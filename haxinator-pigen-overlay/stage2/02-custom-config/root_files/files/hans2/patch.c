/* NetworkManager iodine VPN connections - improved shutdown support */

#include <config.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <fcntl.h>
#include <signal.h>
#include <sys/stat.h>
#include <sys/wait.h>
#include <errno.h>
#include <glib.h>
#include <NetworkManager.h>
#include <nm-vpn-service-plugin.h>

#include "nm-iodine-service.h"

typedef struct {
    GPid pid;
    GVariantBuilder ip4config;
    gint failure;
} NMIodinePluginPrivate;

G_DEFINE_TYPE_WITH_PRIVATE(NMIodinePlugin, nm_iodine_plugin, NM_TYPE_VPN_SERVICE_PLUGIN)

static void quit_mainloop(NMIodinePlugin *plugin, gpointer user_data) {
    g_main_loop_quit((GMainLoop *) user_data);
}

static gboolean real_disconnect(NMVpnServicePlugin *plugin, GError **err) {
    NMIodinePluginPrivate *priv = nm_iodine_plugin_get_instance_private((NMIodinePlugin *)plugin);

    if (priv->pid) {
        if (kill(priv->pid, SIGTERM) == 0)
            g_timeout_add(2000, (GSourceFunc) (void *) kill, GINT_TO_POINTER(priv->pid));
        else
            kill(priv->pid, SIGKILL);

        g_message("Terminated iodine daemon with PID %d.", priv->pid);
        priv->pid = 0;
    }

    // Signal plugin to quit mainloop
    g_signal_emit_by_name(plugin, "quit");

    return TRUE;
}

static gboolean real_connect(NMVpnServicePlugin *plugin, NMConnection *connection, GError **error) {
    NMSettingVpn *s_vpn = nm_connection_get_setting_vpn(connection);
    NMIodinePluginPrivate *priv = nm_iodine_plugin_get_instance_private((NMIodinePlugin *) plugin);

    g_variant_builder_init(&priv->ip4config, G_VARIANT_TYPE_VARDICT);

    if (!s_vpn) {
        g_set_error(error, NM_VPN_PLUGIN_ERROR, NM_VPN_PLUGIN_ERROR_INVALID_CONNECTION, "Invalid VPN setting");
        return FALSE;
    }

    const char *topdomain = nm_setting_vpn_get_data_item(s_vpn, "topdomain");
    const char *nameserver = nm_setting_vpn_get_data_item(s_vpn, "nameserver");
    const char *fragsize = nm_setting_vpn_get_data_item(s_vpn, "fragsize");
    const char *password = nm_setting_vpn_get_secret(s_vpn, "password");

    if (!topdomain || !password) {
        g_set_error(error, NM_VPN_PLUGIN_ERROR, NM_VPN_PLUGIN_ERROR_BAD_ARGUMENTS,
                   "Missing required VPN data or secret");
        return FALSE;
    }

    const char *iodine_path = "/usr/sbin/iodine"; // or search multiple paths
    if (!g_file_test(iodine_path, G_FILE_TEST_EXISTS)) {
        g_set_error(error, NM_VPN_PLUGIN_ERROR, NM_VPN_PLUGIN_ERROR_LAUNCH_FAILED, "iodine binary not found");
        return FALSE;
    }

    GPtrArray *argv = g_ptr_array_new();
    g_ptr_array_add(argv, (gpointer) iodine_path);
    g_ptr_array_add(argv, (gpointer) "-f");
    g_ptr_array_add(argv, (gpointer) "-P");
    g_ptr_array_add(argv, (gpointer) password);
    if (fragsize) {
        g_ptr_array_add(argv, (gpointer) "-m");
        g_ptr_array_add(argv, (gpointer) fragsize);
    }
    if (nameserver) g_ptr_array_add(argv, (gpointer) nameserver);
    g_ptr_array_add(argv, (gpointer) topdomain);
    g_ptr_array_add(argv, NULL);

    GPid pid;
    gint stdin_fd, stderr_fd;
    if (!g_spawn_async_with_pipes(NULL, (char **) argv->pdata, NULL,
                                  G_SPAWN_DO_NOT_REAP_CHILD, NULL, NULL,
                                  &pid, &stdin_fd, NULL, &stderr_fd, error)) {
        g_ptr_array_free(argv, TRUE);
        return FALSE;
    }
    g_ptr_array_free(argv, TRUE);
    priv->pid = pid;

    write(stdin_fd, password, strlen(password));
    write(stdin_fd, "\n", 1);
    close(stdin_fd);

    GIOChannel *stderr_channel = g_io_channel_unix_new(stderr_fd);
    g_io_add_watch(stderr_channel, G_IO_IN | G_IO_HUP, (GIOFunc) g_io_channel_unref, stderr_channel);

    g_message("iodine started with pid %d", pid);
    return TRUE;
}

static gboolean real_need_secrets(NMVpnServicePlugin *plugin, NMConnection *connection,
                                  const char **setting_name, GError **error) {
    NMSettingVpn *s_vpn = nm_connection_get_setting_vpn(connection);
    if (!s_vpn || !nm_setting_vpn_get_secret(s_vpn, "password")) {
        *setting_name = NM_SETTING_VPN_SETTING_NAME;
        return TRUE;
    }
    return FALSE;
}

static void nm_iodine_plugin_init(NMIodinePlugin *plugin) {
    NMIodinePluginPrivate *priv = nm_iodine_plugin_get_instance_private(plugin);
    priv->pid = 0;
    priv->failure = -1;
}

static void nm_iodine_plugin_class_init(NMIodinePluginClass *klass) {
    NMVpnServicePluginClass *parent = NM_VPN_SERVICE_PLUGIN_CLASS(klass);
    parent->connect = real_connect;
    parent->disconnect = real_disconnect;
    parent->need_secrets = real_need_secrets;
}

int main(int argc, char *argv[]) {
    g_type_init();
    GMainLoop *loop = g_main_loop_new(NULL, FALSE);
    NMIodinePlugin *plugin = g_object_new(nm_iodine_plugin_get_type(), NULL);
    g_signal_connect(plugin, "quit", G_CALLBACK(quit_mainloop), loop);
    g_main_loop_run(loop);
    g_main_loop_unref(loop);
    g_object_unref(plugin);
    return 0;
}

