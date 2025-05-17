#!/usr/bin/env python3

import os
import sys
import subprocess
import logging
import time
import json
import dbus
import dbus.service
import dbus.mainloop.glib
from gi.repository import GLib
import ipaddress
import traceback
import datetime
import struct
import socket

# Setup logging with high-precision timestamps
logging.basicConfig(
    filename='/tmp/hans-plugin.log',
    level=logging.DEBUG,
    format='%(asctime)s.%(msecs)03d [%(levelname)s] %(message)s',
    datefmt='%Y-%m-%d %H:%M:%S'
)

# D-Bus service details
BUS_NAME = 'org.freedesktop.NetworkManager.hans'
OBJECT_PATH = '/org/freedesktop/NetworkManager/VPN/Plugin'
IFACE_VPN_PLUGIN = 'org.freedesktop.NetworkManager.VPN.Plugin'

class HansVPNPlugin(dbus.service.Object):
    def __init__(self, bus):
        dbus.service.Object.__init__(self, bus, OBJECT_PATH)
        self.vpn_settings = None
        self.process = None
        self.check_timer = None
        logging.info("HANS VPN Plugin initialized")
        logging.debug(f"Environment: {os.environ}")

    @dbus.service.method(IFACE_VPN_PLUGIN,
                         in_signature='a{sa{ss}}', out_signature='s')
    def NeedSecrets(self, settings):
        logging.info("NeedSecrets called with settings: %s", settings)
        logging.debug("NeedSecrets settings structure: %s", json.dumps(str(settings), indent=2))
        return ""

    @dbus.service.method(IFACE_VPN_PLUGIN,
                         in_signature='a{sa{sv}}a{sv}', out_signature='')
    def ConnectInteractive(self, settings, details):
        logging.info("ConnectInteractive called with settings: %s, details: %s", settings, details)
        logging.debug("ConnectInteractive settings: %s, details: %s", json.dumps(str(settings), indent=2), json.dumps(str(details), indent=2))
        self.Connect(settings)

    @dbus.service.method(IFACE_VPN_PLUGIN,
                         in_signature='a{sa{ss}}', out_signature='')
    def Connect(self, connection):
        logging.info("Connect called with connection: %s", connection)
        self.vpn_settings = connection
        logging.debug("Connection settings: %s", json.dumps(str(connection), indent=2))
        # Log gateway specifically
        try:
            ipv4_settings = connection.get('ipv4', {})
            gateway = ipv4_settings.get('gateway', 'Not set')
            logging.debug(f"Connection ipv4.gateway: {gateway}")
        except Exception as e:
            logging.error(f"Error logging gateway: {e}")

        try:
            vpn_data = connection['vpn']['data']
            server = vpn_data.get('server', '')
            password = vpn_data.get('password', '')
            logging.debug("Parsed VPN data: server=%s, password=%s", server, "****" if password else "")
            if not server or not password:
                raise ValueError("Missing server or password")
        except Exception as e:
            logging.error("Failed to parse settings: %s, traceback: %s", e, traceback.format_exc())
            self.state_changed(6, f"Invalid configuration: {str(e)}")
            return

        # Launch HANS without specifying client IP, letting hans assign it
        cmd = ['hans', '-f', '-c', server, '-p', password]
        logging.info("Starting HANS with command: %s", ' '.join(cmd))
        try:
            self.process = subprocess.Popen(
                cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True
            )
            GLib.io_add_watch(self.process.stdout, GLib.IO_IN, self._log_hans_output)
            GLib.io_add_watch(self.process.stderr, GLib.IO_IN, self._log_hans_output)
            self.process.start_time = time.time()
            logging.debug("HANS process started, PID: %s", self.process.pid)
            self.state_changed(2, "Connecting")

            # Early tun0 setup and IP config
            if self._setup_tun0():
                assigned_ip, assigned_prefix = self._get_tun0_ipv4()
                if assigned_ip:
                    self.state_changed(3, "Connecting")  # Mimic OpenVPN's early StateChanged(3)
                    self.send_config(assigned_ip, assigned_prefix)
                    self.state_changed(4, "Connected")  # Mimic OpenVPN's final StateChanged(4)
                else:
                    self.check_timer = GLib.timeout_add(1000, self.check_tunnel)
                    logging.debug("No IP assigned, starting tunnel check timer")
            else:
                self.check_timer = GLib.timeout_add(1000, self.check_tunnel)
                logging.debug("tun0 setup failed, starting tunnel check timer")
        except Exception as e:
            logging.error("Error starting HANS: %s, traceback: %s", e, traceback.format_exc())
            self.state_changed(6, f"HANS startup error: {str(e)}")

    def _log_hans_output(self, fd, condition):
        if condition & GLib.IO_IN:
            line = fd.readline().strip()
            if line:
                logging.debug("HANS output: %s", line)
            return True
        logging.debug("HANS output condition not met: %s", condition)
        return False

    def _setup_tun0(self):
        try:
            # Check if tun0 exists
            tun_output = subprocess.check_output(['ip', 'link', 'show', 'tun0'], text=True)
            logging.debug("tun0 status: %s", tun_output.strip())
            if "UP" not in tun_output:
                subprocess.check_call(['ip', 'link', 'set', 'tun0', 'up'])
                logging.info("tun0 set up successfully")
                # Log post-setup state
                post_setup_output = subprocess.check_output(['ip', 'link', 'show', 'tun0'], text=True)
                logging.debug("tun0 status after setup: %s", post_setup_output.strip())
            return True
        except subprocess.CalledProcessError as e:
            logging.debug("tun0 not found or error: %s, stderr: %s", e, e.stderr)
            return False
        except Exception as e:
            logging.error("Error setting up tun0: %s, traceback: %s", e, traceback.format_exc())
            return False

    def check_tunnel(self):
        try:
            if self.process.poll() is not None:
                stdout, stderr = self.process.communicate()
                logging.error("HANS process terminated: stdout=%s, stderr=%s", stdout, stderr)
                self.state_changed(6, "HANS process failed")
                return False

            # Log current tun0 state
            try:
                tun_state = subprocess.check_output(['ip', 'addr', 'show', 'tun0'], text=True)
                logging.debug("Current tun0 state: %s", tun_state.strip())
            except subprocess.CalledProcessError as e:
                logging.debug("Failed to check tun0 state: %s", e)

            # Try pinging the tunnel endpoint
            ping_result = subprocess.run(
                ['ping', '-c', '1', '-W', '1', '10.1.2.1'],
                stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True
            )
            logging.debug("Ping result: returncode=%s, stdout=%s, stderr=%s",
                          ping_result.returncode, ping_result.stdout.strip(), ping_result.stderr.strip())
            if ping_result.returncode == 0:
                logging.info("Tunnel is up (ping successful)")
                assigned_ip, assigned_prefix = self._get_tun0_ipv4()
                if assigned_ip:
                    self.state_changed(3, "Connecting")  # Mimic OpenVPN
                    self.send_config(assigned_ip, assigned_prefix)
                    self.state_changed(4, "Connected")  # Mimic OpenVPN
                    return False
                else:
                    logging.error("Could not parse assigned IP from tun0")
                    self.state_changed(6, "IP parse error")
                    return False
            else:
                logging.debug("Ping failed, tunnel not ready yet")

            if time.time() - self.process.start_time > 60:
                logging.error("Tunnel failed to establish within 60 seconds")
                self.state_changed(6, "Tunnel timeout")
                return False
            return True
        except Exception as e:
            logging.error("Error checking tunnel: %s, traceback: %s", e, traceback.format_exc())
            return True

    def _get_tun0_ipv4(self):
        try:
            out = subprocess.check_output(['ip', '-j', 'addr', 'show', 'dev', 'tun0'], text=True)
            logging.debug("ip addr show tun0 output: %s", out.strip())
            data = json.loads(out)
            for addr_info in data[0].get('addr_info', []):
                if addr_info.get('family') == 'inet':
                    ip_str = addr_info.get('local')
                    prefix_int = addr_info.get('prefixlen')
                    if ip_str and prefix_int is not None:
                        logging.debug("Found IPv4 for tun0: %s/%s", ip_str, prefix_int)
                        return ip_str, prefix_int
            logging.debug("No IPv4 address found for tun0")
            return None, None
        except Exception as e:
            logging.error("Failed to parse IPv4 from tun0: %s, traceback: %s", e, traceback.format_exc())
            return None, None

    def send_config(self, assigned_ip, assigned_prefix):
        logging.debug("Entering send_config with assigned_ip=%s, assigned_prefix=%s", assigned_ip, assigned_prefix)
        ip_str = str(assigned_ip)
        prefix_str = str(assigned_prefix)
        gw_str = '10.1.2.1'
        dns_str = '8.8.8.8'  # Use Google DNS as per hack
        internal_gw_str = '10.1.2.1'  # Assuming tunnel endpoint for Hans
        
        # Log IP conversions for debugging
        logging.debug(f"Converting IP {ip_str} to uint32")
        ip_uint32 = self.ip_to_uint32(ip_str)
        logging.debug(f"IP {ip_str} converted to uint32: {ip_uint32}")
        logging.debug(f"Converting gateway {gw_str} to uint32")
        gw_uint32 = self.ip_to_uint32(gw_str)
        logging.debug(f"Gateway {gw_str} converted to uint32: {gw_uint32}")
        logging.debug(f"Converting DNS {dns_str} to uint32")
        dns_uint32 = self.ip_to_uint32(dns_str)
        logging.debug(f"DNS {dns_str} converted to uint32: {dns_uint32}")

        config = {
            'gateway': dbus.UInt32(gw_uint32),
            'tundev': 'tun0',
            'mtu': dbus.UInt32(1467),  # Match tun0 MTU
            'can-persist': dbus.Boolean(True),
            'has-ip4': dbus.Boolean(True)
        }
        ip4_config = {
            'internal-gateway': dbus.UInt32(gw_uint32),
            'address': dbus.UInt32(ip_uint32),
            'prefix': dbus.UInt32(prefix_str),
            'dns': dbus.Array([dbus.UInt32(dns_uint32)], signature='u'),  # DNS set to 8.8.8.8
            'tundev': 'tun0',
            'gateway': dbus.UInt32(gw_uint32),
            'mtu': dbus.UInt32(1467)
        }
        logging.debug("Config dictionary: %s", json.dumps(str(config), indent=2))
        logging.debug("Ip4Config dictionary: %s", json.dumps(str(ip4_config), indent=2))
        try:
            logging.debug("Sending Config signal")
            self.Config(config)
            logging.debug("Sending Ip4Config signal")
            self.Ip4Config(ip4_config)
            logging.debug("Config and Ip4Config signals sent successfully")
            # Log current network state
            try:
                ip_addr = subprocess.check_output(['ip', 'addr', 'show', 'tun0'], text=True)
                ip_route = subprocess.check_output(['ip', 'route', 'show'], text=True)
                logging.debug("Post-config network state: ip addr=%s, ip route=%s", ip_addr.strip(), ip_route.strip())
                # Log contents of dispatcher log file
                try:
                    if os.path.exists('/tmp/hans-plugin-dispatcher.log'):
                        with open('/tmp/hans-plugin-dispatcher.log', 'r') as f:
                            dispatcher_log = f.read()
                        logging.debug("Dispatcher log contents: %s", dispatcher_log)
                    else:
                        logging.debug("Dispatcher log file not found at /tmp/hans-plugin-dispatcher.log")
                except Exception as e:
                    logging.error("Error reading dispatcher log: %s, traceback: %s", e, traceback.format_exc())
            except subprocess.CalledProcessError as e:
                logging.debug("Failed to log network state: %s", e)
        except dbus.DBusException as e:
            logging.error("DBus error in send_config: %s, traceback: %s", e, traceback.format_exc())
            raise
        except Exception as e:
            logging.error("Error in send_config: %s, traceback: %s", e, traceback.format_exc())
            raise

    @dbus.service.method(IFACE_VPN_PLUGIN, in_signature='a{sv}', out_signature='')
    def SetConfig(self, config):
        logging.info("SetConfig called with config: %s", json.dumps(str(config), indent=2))
        # Acknowledge config, no action needed as we set it in send_config
        pass

    @dbus.service.method(IFACE_VPN_PLUGIN, in_signature='a{sv}', out_signature='')
    def SetIp4Config(self, config):
        logging.info("SetIp4Config called with config: %s", json.dumps(str(config), indent=2))
        # Acknowledge config, no action needed as we set it in send_config
        pass

    def ip_to_uint32(self, ip):
        try:
            uint32_ip = struct.unpack("!I", socket.inet_aton(ip))[0]
            logging.debug(f"Converted IP {ip} to uint32: {uint32_ip}")
            return uint32_ip
        except Exception as e:
            logging.error(f"Error converting IP {ip} to uint32: {e}, traceback: {traceback.format_exc()}")
            raise

    @dbus.service.method(IFACE_VPN_PLUGIN, in_signature='', out_signature='')
    def Disconnect(self):
        logging.info("Disconnect called")
        logging.debug("Disconnect triggered, stack trace: %s", ''.join(traceback.format_stack()))
        if self.process:
            try:
                self.process.terminate()
                self.process.wait(timeout=5)
                logging.debug("HANS process terminated, PID: %s", self.process.pid)
                self.process = None
            except Exception as e:
                logging.error("Error terminating HANS process: %s, traceback: %s", e, traceback.format_exc())
        if self.check_timer:
            GLib.source_remove(self.check_timer)
            self.check_timer = None
            logging.debug("Check timer removed")
        self.state_changed(6, "Disconnected")

    def state_changed(self, state, reason):
        state_map = {
            2: "Connecting",
            3: "Activating",
            4: "Connected",
            6: "Disconnected/Failed"
        }
        logging.info("State changed to %s: %s", state_map.get(state, state), reason)
        try:
            self.StateChanged(dbus.UInt32(state), reason)
            logging.debug("Successfully sent StateChanged signal: state=%s, reason=%s", state, reason)
        except dbus.DBusException as e:
            logging.error("DBus error in state_changed: %s, traceback: %s", e, traceback.format_exc())
            raise
        except Exception as e:
            logging.error("Error in state_changed: %s, traceback: %s", e, traceback.format_exc())
            raise

    @dbus.service.signal(IFACE_VPN_PLUGIN, signature='a{sv}')
    def Config(self, config):
        pass

    @dbus.service.signal(IFACE_VPN_PLUGIN, signature='a{sv}')
    def Ip4Config(self, config):
        pass

    @dbus.service.signal(IFACE_VPN_PLUGIN, signature='us')
    def StateChanged(self, state, reason):
        pass

def main():
    try:
        dbus.mainloop.glib.DBusGMainLoop(set_as_default=True)
        bus = dbus.SystemBus()
        name = dbus.service.BusName(BUS_NAME, bus)
        plugin = HansVPNPlugin(bus)
        logging.info("HANS VPN Plugin started")
        loop = GLib.MainLoop()
        loop.run()
    except Exception as e:
        logging.error("Error in main: %s, traceback: %s", e, traceback.format_exc())
        raise

if __name__ == '__main__':
    main()
