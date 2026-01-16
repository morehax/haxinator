#!/usr/bin/env python3
# NetworkManager Iodine VPN Service
# Author: MoreHax (haxinatorgalore@gmail.com)
# A robust Python-based NetworkManager VPN plugin for iodine, replacing the bug-prone network-manager-iodine.
# Integrates with DBus to manage iodine DNS tunneling, handling connection setup, IP configuration, and clean disconnection.
# Key improvements: corrects gateway byte order (host order for NM), supports address-data, adds route-metric for explicit routes,
# and ensures proper process termination for reliable restarts. Logs detailed diagnostics to /tmp/nm-iodine-debug.log.

import dbus
import dbus.service
import subprocess
import threading
import socket
import struct
from gi.repository import GLib
from dbus.mainloop.glib import DBusGMainLoop

NM_VPN_PLUGIN_FAILURE_LOGIN_FAILED = 0
NM_VPN_PLUGIN_FAILURE_CONNECT_FAILED = 1
NM_VPN_PLUGIN_FAILURE_BAD_IP_CONFIG = 2

def ip4_to_uint32_network(ip_str):
    return struct.unpack('!I', socket.inet_aton(ip_str))[0]

def ip4_to_uint32_host(ip_str):
    return struct.unpack('<I', socket.inet_aton(ip_str))[0]

class IodineVPNService(dbus.service.Object):
    def __init__(self, bus, mainloop):
        self.bus_name = dbus.service.BusName("org.freedesktop.NetworkManager.iodine", bus=bus)
        super().__init__(self.bus_name, "/org/freedesktop/NetworkManager/VPN/Plugin")
        self.mainloop = mainloop
        self.process = None
        self.thread = None
        self.pending_connection = None
        self._config_sent = False
        self.client_ip_str = None
        self.server_ip_str = None
        self.external_ip_str = None
        self.discovered_mtu = None
        self.tundev_name = None
        try:
            self.logf = open("/tmp/nm-iodine-debug.log", "a")
        except Exception as e:
            import sys
            self.logf = sys.stderr
            self._log(f"Warning: couldn't open debug log file: {e}")
        self._log("===== nm-iodine-service started =====")

    def _log(self, msg):
        try:
            self.logf.write(msg + "\n")
            self.logf.flush()
        except Exception:
            pass

    def _emit_state(self, state):
        try:
            self.StateChanged(state)
            self._log(f"StateChanged emitted: {state}")
        except Exception as e:
            self._log(f"Error in StateChanged({state}): {e}")

    def _emit_failure(self, reason_code):
        try:
            self.Failure(reason_code)
            self._log(f"Failure emitted: {reason_code}")
        except Exception as e:
            self._log(f"Error in Failure({reason_code}): {e}")

    def _emit_ip4_config(self):
        mtu_val = self.discovered_mtu if self.discovered_mtu else 1130
        tundev_str = self.tundev_name if self.tundev_name else "dns0"
        prefix_val = 27
        client_ip_u32 = dbus.UInt32(ip4_to_uint32_host(self.client_ip_str))  # Fix: Use host byte order
        server_ip_u32 = dbus.UInt32(ip4_to_uint32_network(self.server_ip_str))  # Keep network order for ptp if required
        prefix_u32 = dbus.UInt32(prefix_val)
        mtu_u32 = dbus.UInt32(mtu_val)
        gateway_ip_str = self.external_ip_str or self.server_ip_str
        gateway_u32_host = dbus.UInt32(ip4_to_uint32_host(gateway_ip_str))  # Host order for gateway
        gateway_u32_net = dbus.UInt32(ip4_to_uint32_network(gateway_ip_str))  # Network order for routes if required

        route = dbus.Struct([
            dbus.UInt32(ip4_to_uint32_network("10.0.0.0")),  # Network order for route destination
            dbus.UInt32(27),
            gateway_u32_net  # Network order for route gateway
        ])

        ip4config = {
            "gateway": gateway_u32_host,  # Host order
            "address": client_ip_u32,  # Host order
            "prefix": prefix_u32,
            "ptp": self.server_ip_str,  # String format, no byte order issue
            "mtu": mtu_u32,
            "tundev": tundev_str,
#            "routes": dbus.Array([route], signature="(uuu)"),
            "address-data": dbus.Array([
                dbus.Dictionary({
                    "address": dbus.String(self.client_ip_str),  # String format, correct
                    "prefix": dbus.UInt32(prefix_val)
                }, signature="sv")
            ]),
#            "addresses": dbus.Array([
#                dbus.Struct([
#                    client_ip_u32,  # Fix: Host order
#                    prefix_u32,
##                    gateway_u32_net  # Network order if required
#                ], signature=None)
#            ], signature="(uuu)"),
#            "route-metric": dbus.UInt32(5)
        }

        self._log(f"Emitting Ip4Config: client={self.client_ip_str}, server={self.server_ip_str}, "
                  f"gateway={gateway_ip_str}, prefix={prefix_val}, mtu={mtu_val}")

        try:
            self.Ip4Config(ip4config)
        except Exception as e:
            self._log(f"Error emitting Ip4Config: {e}")
        else:
            self._config_sent = True
            self._emit_state(4)

    def _parse_and_handle_output(self, line):
        self._log(f"[iodine] {line}")
        if "Opened dns" in line:
            self.tundev_name = line.strip().split()[-1]
        elif "Setting IP of" in line:
            self.client_ip_str = line.split("to")[-1].strip()
        elif "Server tunnel IP is" in line:
            self.server_ip_str = line.split("is")[-1].strip()
        elif "Sending DNS queries for" in line or "Sending raw traffic directly to" in line:
            self.external_ip_str = line.strip().split()[-1]
        elif "Setting MTU of" in line:
            try:
                self.discovered_mtu = int(line.strip().split()[-1])
            except: pass
        if self.client_ip_str and self.server_ip_str and not self._config_sent:
            self._emit_ip4_config()

    def _reader_thread(self, proc):
        self._log("Reader thread started for iodine process")
        try:
            for raw_line in iter(proc.stdout.readline, ''):
                if raw_line == '': break
                self._parse_and_handle_output(raw_line.rstrip("\n"))
            ret = proc.wait()
            self._log(f"iodine process exited with code {ret}")
            if not self._config_sent:
                self._emit_failure(NM_VPN_PLUGIN_FAILURE_BAD_IP_CONFIG if ret == 0 else NM_VPN_PLUGIN_FAILURE_LOGIN_FAILED)
        except Exception as e:
            self._log(f"Exception in reader thread: {e}")
            if not self._config_sent:
                self._emit_failure(NM_VPN_PLUGIN_FAILURE_CONNECT_FAILED)
        finally:
            self._emit_state(6)
            self._log("Reader thread terminating")

    def _start_iodine(self, connection):
        vpn = connection.get("vpn", {})
        data = vpn.get("data", {})
        secrets = vpn.get("secrets", {})
        topdomain = data.get("topdomain")
        nameserver = data.get("nameserver")
        fragsize = data.get("fragsize")
        password = secrets.get("password")
        if not topdomain or not password:
            self._log("Missing 'topdomain' or 'password'. Cannot proceed.")
            self._emit_failure(NM_VPN_PLUGIN_FAILURE_CONNECT_FAILED)
            return
        cmd = ["iodine", "-f", "-P", password]
        if fragsize:
            cmd += ["-m", str(fragsize)]
        if nameserver:
            cmd.append(nameserver)
        cmd.append(topdomain)
        safe_cmd = cmd.copy()
        safe_cmd[3] = "*****"
        self._log("Launching iodine: " + " ".join(safe_cmd))
        try:
            self.process = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
        except Exception as e:
            self._log(f"Failed to start iodine: {e}")
            self._emit_failure(NM_VPN_PLUGIN_FAILURE_CONNECT_FAILED)
            return
        self._emit_state(3)
        self.thread = threading.Thread(target=self._reader_thread, args=(self.process,), daemon=True)
        self.thread.start()

    @dbus.service.method("org.freedesktop.NetworkManager.VPN.Plugin", in_signature="a{sa{sv}}", out_signature="")
    def Connect(self, connection):
        self._start_iodine(connection)

    @dbus.service.method("org.freedesktop.NetworkManager.VPN.Plugin", in_signature="a{sa{sv}}a{sv}", out_signature="")
    def ConnectInteractive(self, connection, details):
        vpn = connection.get("vpn", {})
        password = vpn.get("secrets", {}).get("password", "")
        if not password:
            self.pending_connection = connection
            self.SecretsRequired("VPN connection requires a password", ["password"])
        else:
            self._start_iodine(connection)

    @dbus.service.method("org.freedesktop.NetworkManager.VPN.Plugin", in_signature="a{sa{sv}}", out_signature="s")
    def NeedSecrets(self, settings):
        vpn = settings.get("vpn", {})
        password = vpn.get("secrets", {}).get("password", "")
        return "vpn" if not password else ""

    @dbus.service.method("org.freedesktop.NetworkManager.VPN.Plugin", in_signature="a{sa{sv}}", out_signature="")
    def NewSecrets(self, connection):
        if self.pending_connection:
            vpn_pending = self.pending_connection.get("vpn", {})
            vpn_new = connection.get("vpn", {})
            vpn_pending.setdefault("secrets", {}).update(vpn_new.get("secrets", {}))
            for k, v in vpn_new.items():
                if k != "secrets":
                    vpn_pending[k] = v
            self._start_iodine(self.pending_connection)
            self.pending_connection = None

    @dbus.service.method("org.freedesktop.NetworkManager.VPN.Plugin", in_signature="", out_signature="")
    def Disconnect(self):
        if self.process:
            try:
                self.process.terminate()
                self._log("Sent SIGTERM to iodine process")
                self.process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                self.process.kill()
            except Exception as e:
                self._log(f"Error stopping iodine: {e}")
        self._emit_state(5)
        self._emit_state(6)
        self._log("Shutting down mainloop")
        self.mainloop.quit()

    @dbus.service.signal("org.freedesktop.NetworkManager.VPN.Plugin", signature="u")
    def StateChanged(self, state): pass

    @dbus.service.signal("org.freedesktop.NetworkManager.VPN.Plugin", signature="u")
    def Failure(self, reason): pass

    @dbus.service.signal("org.freedesktop.NetworkManager.VPN.Plugin", signature="a{sv}")
    def Ip4Config(self, config): pass

    @dbus.service.signal("org.freedesktop.NetworkManager.VPN.Plugin", signature="sas")
    def SecretsRequired(self, message, secrets): pass

if __name__ == "__main__":
    DBusGMainLoop(set_as_default=True)
    bus = dbus.SystemBus()
    loop = GLib.MainLoop()
    service = IodineVPNService(bus, loop)
    loop.run()