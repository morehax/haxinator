#!/usr/bin/env python3
# NetworkManager HANS VPN Service
# Author: MoreHax (haxinatorgalore@gmail.com)
# A Python-based NetworkManager VPN plugin for HANS, providing reliable VPN connectivity through DBus integration.
# Manages HANS tunnel setup, IP configuration, and clean disconnection with detailed logging to /tmp/hans-plugin.log.
# Features: host-order IP handling, dynamic tun0 configuration, route-metric support, and robust tunnel monitoring via ping.
# Ensures proper process termination and supports address-data for NetworkManager compatibility.

import os
import subprocess
import logging
import time
import json
import dbus
import dbus.service
import dbus.mainloop.glib
from gi.repository import GLib
import socket
import struct

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

def ip4_to_uint32_host(ip_str):
    return struct.unpack('<I', socket.inet_aton(ip_str))[0]

class HansVPNPlugin(dbus.service.Object):
    def __init__(self, bus):
        dbus.service.Object.__init__(self, bus, OBJECT_PATH)
        self.vpn_settings = None
        self.process = None
        self.check_timer = None
        self.loop = GLib.MainLoop()
        logging.info("HANS VPN Plugin initialized")
        logging.debug(f"Environment: {os.environ}")

    @dbus.service.method(IFACE_VPN_PLUGIN, in_signature='a{sa{ss}}', out_signature='s')
    def NeedSecrets(self, settings):
        logging.info("NeedSecrets called with settings: %s", settings)
        return ""

    @dbus.service.method(IFACE_VPN_PLUGIN, in_signature='a{sa{sv}}a{sv}', out_signature='')
    def ConnectInteractive(self, settings, details):
        self.Connect(settings)

    @dbus.service.method(IFACE_VPN_PLUGIN, in_signature='a{sa{ss}}', out_signature='')
    def Connect(self, connection):
        self.vpn_settings = connection
        vpn_data = connection['vpn']['data']
        server = vpn_data.get('server', '')
        password = vpn_data.get('password', '')
        if not server or not password:
            self.state_changed(6, "Missing VPN server or password")
            return

        cmd = ['hans', '-f', '-c', server, '-p', password]
        try:
            self.process = subprocess.Popen(
                cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True
            )
            GLib.io_add_watch(self.process.stdout, GLib.IO_IN, self._log_hans_output)
            GLib.io_add_watch(self.process.stderr, GLib.IO_IN, self._log_hans_output)
            self.process.start_time = time.time()
            self.state_changed(2, "Connecting")

            if self._setup_tun0():
                assigned_ip, assigned_prefix = self._get_tun0_ipv4()
                if assigned_ip:
                    self.state_changed(3, "Connecting")
                    self.send_config(assigned_ip, assigned_prefix)
                    self.state_changed(4, "Connected")
                else:
                    self.check_timer = GLib.timeout_add(1000, self.check_tunnel)
            else:
                self.check_timer = GLib.timeout_add(1000, self.check_tunnel)
        except Exception as e:
            logging.error("Error starting HANS: %s", e)
            self.state_changed(6, f"HANS startup error: {str(e)}")

    def _log_hans_output(self, fd, condition):
        if condition & GLib.IO_IN:
            line = fd.readline().strip()
            if line:
                logging.debug("HANS output: %s", line)
            return True
        return False

    def _setup_tun0(self):
        try:
            subprocess.check_output(['ip', 'link', 'set', 'tun0', 'up'])
            return True
        except Exception as e:
            logging.debug("Failed to set up tun0: %s", e)
            return False

    def _get_tun0_ipv4(self):
        try:
            out = subprocess.check_output(['ip', '-j', 'addr', 'show', 'dev', 'tun0'], text=True)
            data = json.loads(out)
            for addr_info in data[0].get('addr_info', []):
                if addr_info.get('family') == 'inet':
                    ip_str = addr_info.get('local')
                    prefix_int = addr_info.get('prefixlen')
                    return ip_str, prefix_int
            return None, None
        except Exception as e:
            logging.error("Failed to get tun0 IPv4: %s", e)
            return None, None

    def send_config(self, assigned_ip, assigned_prefix):
        vpn_data = self.vpn_settings['vpn']['data']
        dns_str = vpn_data.get('dns', '8.8.8.8')

        ip4config = {
            'address': dbus.UInt32(ip4_to_uint32_host(assigned_ip)),
            'prefix': dbus.UInt32(int(assigned_prefix)),
            'gateway': dbus.UInt32(ip4_to_uint32_host('10.1.2.254')),  # Hardcoded gateway
            'mtu': dbus.UInt32(1467),
            'tundev': 'tun0',
            'address-data': dbus.Array([
                dbus.Dictionary({
                    'address': dbus.String(assigned_ip),
                    'prefix': dbus.UInt32(int(assigned_prefix))
                }, signature='sv')
            ]),
            'dns': dbus.Array([dbus.UInt32(ip4_to_uint32_host(dns_str))], signature='u'),
            'route-metric': dbus.UInt32(5),
            'routes': dbus.Array([
                dbus.Struct([
                    dbus.UInt32(ip4_to_uint32_host("10.1.2.1")),  # Server tunnel IP
                    dbus.UInt32(32),  # /32 for specific host
                    dbus.UInt32(0)  # No gateway, use tun0
                ], signature='(uuu)')
            ], signature='(uuu)')
        }

        try:
            subprocess.call(["ip", "addr", "flush", "dev", "tun0"])
        except Exception as e:
            logging.warning("Failed to flush tun0: %s", e)

        try:
            self.Ip4Config(ip4config)
        except Exception as e:
            logging.error("Failed to emit Ip4Config: %s", e)

    @dbus.service.method(IFACE_VPN_PLUGIN, in_signature='', out_signature='')
    def Disconnect(self):
        if self.process:
            try:
                self.process.terminate()
                self.process.wait(timeout=5)
            except Exception as e:
                logging.error("Error terminating HANS process: %s", e)
        if self.check_timer:
            GLib.source_remove(self.check_timer)
            self.check_timer = None
        self.state_changed(6, "Disconnected")
        GLib.idle_add(self.loop.quit)

    def check_tunnel(self):
        if self.process.poll() is not None:
            self.state_changed(6, "HANS process exited")
            return False
        try:
            vpn_data = self.vpn_settings['vpn']['data']
            gw_str = vpn_data.get('gateway', '10.1.2.1')
            ping_result = subprocess.run(
                ['ping', '-c', '1', '-W', '1', gw_str],
                stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True
            )
            if ping_result.returncode == 0:
                assigned_ip, assigned_prefix = self._get_tun0_ipv4()
                if assigned_ip:
                    self.send_config(assigned_ip, assigned_prefix)
                    self.state_changed(4, "Connected")
                    return False
        except Exception as e:
            logging.error("Tunnel check error: %s", e)
        return True

    def state_changed(self, state, reason):
        try:
            self.StateChanged(dbus.UInt32(state), reason)
        except Exception as e:
            logging.error("Error sending StateChanged: %s", e)

    @dbus.service.signal(IFACE_VPN_PLUGIN, signature='a{sv}')
    def Ip4Config(self, config):
        pass

    @dbus.service.signal(IFACE_VPN_PLUGIN, signature='us')
    def StateChanged(self, state, reason):
        pass

def main():
    dbus.mainloop.glib.DBusGMainLoop(set_as_default=True)
    bus = dbus.SystemBus()
    name = dbus.service.BusName(BUS_NAME, bus)
    plugin = HansVPNPlugin(bus)
    plugin.loop.run()

if __name__ == '__main__':
    main()
