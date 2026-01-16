package nmcli

import (
	"fmt"
	"strconv"
	"strings"

	"nm-webui/internal/types"
)

// WifiScan scans for WiFi networks
func (c *Client) WifiScan(dev string, rescan bool) (*types.WifiScanResult, error) {
	// If no device specified, find first WiFi device
	if dev == "" {
		devOut, _ := c.runTerse("DEVICE,TYPE", "device")
		for _, line := range strings.Split(strings.TrimSpace(devOut), "\n") {
			parts := strings.Split(line, ":")
			if len(parts) >= 2 && strings.HasSuffix(parts[1], "wifi") {
				dev = parts[0]
				break
			}
		}
		if dev == "" {
			return nil, fmt.Errorf("no WiFi devices found")
		}
	}

	// Get local MAC addresses for hotspot detection
	localMACs := c.getLocalWifiMACs()

	// Perform WiFi scan
	rescanArg := "no"
	if rescan {
		rescanArg = "yes"
	}

	fields := "IN-USE,SSID,BSSID,CHAN,FREQ,RATE,SIGNAL,SECURITY,DEVICE,MODE"
	scanOut, err := c.run("-t", "--escape", "yes", "-f", fields, "dev", "wifi", "list", "ifname", dev, "--rescan", rescanArg)
	if err != nil {
		return nil, fmt.Errorf("WiFi scan failed: %w", err)
	}

	var networks []types.WifiNetwork
	for _, line := range strings.Split(strings.TrimSpace(scanOut), "\n") {
		if line == "" {
			continue
		}

		parts := parseEscapedLine(line)
		if len(parts) < 10 {
			continue
		}

		inUse := parts[0] == "*"
		ssid := parts[1]
		bssid := parts[2]
		channel, _ := strconv.Atoi(parts[3])
		freq, _ := strconv.Atoi(extractNumber(parts[4]))
		rate := parts[5]
		signal, _ := strconv.Atoi(parts[6])
		security := parts[7]
		device := parts[8]
		mode := parts[9]

		// Determine band from frequency
		band := "2.4 GHz"
		if freq >= 5950 {
			band = "6 GHz"
		} else if freq > 4900 {
			band = "5 GHz"
		}

		// Check if this is our hotspot
		isHotspot := inUse && contains(localMACs, strings.ToUpper(bssid))

		if security == "" {
			security = "OPEN"
		}

		networks = append(networks, types.WifiNetwork{
			InUse:     inUse,
			SSID:      ssid,
			BSSID:     bssid,
			Channel:   channel,
			Band:      band,
			Rate:      rate,
			Signal:    signal,
			Security:  security,
			Device:    device,
			Mode:      mode,
			IsHotspot: isHotspot,
		})
	}

	// Get saved WiFi connections
	saved := make(map[string]types.SavedWifiConnection)
	savedOut, _ := c.runTerse("NAME,UUID,TYPE,AUTOCONNECT,AUTOCONNECT-PRIORITY", "connection", "show")
	for _, line := range strings.Split(strings.TrimSpace(savedOut), "\n") {
		parts := strings.Split(line, ":")
		if len(parts) < 5 {
			continue
		}
		if parts[2] == "802-11-wireless" {
			pri, _ := strconv.Atoi(parts[4])
			saved[parts[0]] = types.SavedWifiConnection{
				UUID:        parts[1],
				AutoConnect: parts[3] == "yes",
				Priority:    pri,
			}
		}
	}

	// Get wired/USB connections for sharing
	var wired []types.WiredConnection
	wiredOut, _ := c.runTerse("NAME,UUID,TYPE,DEVICE", "connection", "show")
	for _, line := range strings.Split(strings.TrimSpace(wiredOut), "\n") {
		parts := strings.Split(line, ":")
		if len(parts) < 4 {
			continue
		}
		connType := parts[2]
		if strings.Contains(connType, "ethernet") || connType == "gsm" || connType == "bluetooth" {
			methodOut, _ := c.runTerse("ipv4.method", "connection", "show", parts[1])
			method := strings.TrimPrefix(strings.TrimSpace(methodOut), "ipv4.method:")

			wired = append(wired, types.WiredConnection{
				Name:   parts[0],
				UUID:   parts[1],
				Method: method,
				Device: parts[3],
			})
		}
	}

	return &types.WifiScanResult{
		Networks: networks,
		Saved:    saved,
		Wired:    wired,
		Iface:    dev,
	}, nil
}

// WifiConnect connects to a WiFi network
func (c *Client) WifiConnect(dev, ssid, password string, hidden bool) types.ActionResult {
	args := []string{"dev", "wifi", "connect", ssid}

	if password != "" {
		args = append(args, "password", password)
	}
	if hidden {
		args = append(args, "hidden", "yes")
	}
	if dev != "" {
		args = append(args, "ifname", dev)
	}

	out, err := c.run(args...)
	out = strings.TrimSpace(out)

	success := err == nil && strings.Contains(out, "successfully")
	return types.ActionResult{Success: success, Message: prettyMessage(out)}
}

// WifiDisconnect disconnects from a WiFi network
func (c *Client) WifiDisconnect(ssid string, isHotspot bool) types.ActionResult {
	connectionName := ssid

	if isHotspot {
		connectionName = c.findConnectionBySSID(ssid)
		if connectionName == "" {
			connectionName = ssid
		}
	}

	out, err := c.run("connection", "down", "id", connectionName)
	out = strings.TrimSpace(out)

	success := err == nil && (strings.Contains(out, "successfully") || strings.Contains(out, "deactivated"))
	return types.ActionResult{Success: success, Message: prettyMessage(out)}
}

// WifiForget removes a saved WiFi connection
func (c *Client) WifiForget(ssid string, isHotspot bool) types.ActionResult {
	connectionName := ssid

	if isHotspot {
		connectionName = c.findConnectionBySSID(ssid)
		if connectionName == "" {
			connectionName = ssid
		}
	}

	out, err := c.run("connection", "delete", "id", connectionName)
	out = strings.TrimSpace(out)

	success := err == nil && strings.Contains(out, "deleted")
	return types.ActionResult{Success: success, Message: prettyMessage(out)}
}

// SetPriority sets the auto-connect priority for a connection
func (c *Client) SetPriority(uuid string, priority int) types.ActionResult {
	if !isValidUUID(uuid) {
		return types.ActionResult{Success: false, Message: "Invalid UUID"}
	}

	out, err := c.run("connection", "modify", uuid, "connection.autoconnect-priority", strconv.Itoa(priority))
	success := err == nil && strings.TrimSpace(out) == ""
	msg := "Priority saved"
	if !success {
		msg = strings.TrimSpace(out)
	}
	return types.ActionResult{Success: success, Message: msg}
}

// HotspotStart creates and starts a WiFi hotspot
func (c *Client) HotspotStart(dev, ssid, password, band string, channel int, conName, ipRange string, persistent bool) types.ActionResult {
	if dev == "" {
		return types.ActionResult{Success: false, Message: "Device is required"}
	}
	if ssid == "" {
		ssid = "MyHotspot"
	}

	args := []string{"device", "wifi", "hotspot", "ifname", dev, "ssid", ssid}

	if len(password) >= 8 {
		args = append(args, "password", password)
	}

	if band == "5" {
		args = append(args, "band", "a")
	} else if band == "2.4" {
		args = append(args, "band", "bg")
	}

	if conName != "" {
		args = append(args, "con-name", conName)
	}

	if channel > 0 && channel <= 165 {
		args = append(args, "channel", strconv.Itoa(channel))
	}

	out, err := c.run(args...)
	success := err == nil && !strings.Contains(out, "Error")

	if success {
		actualConName := conName
		if actualConName == "" {
			actualConName = fmt.Sprintf("Hotspot %s", dev)
		}

		if ipRange != "" && isValidCIDR(ipRange) {
			c.run("connection", "modify", actualConName, "ipv4.addresses", ipRange)
		}

		autoConnect := "no"
		if persistent {
			autoConnect = "yes"
		}
		c.run("connection", "modify", actualConName, "connection.autoconnect", autoConnect)
	}

	return types.ActionResult{Success: success, Message: prettyMessage(out)}
}

// HotspotStop stops a hotspot
func (c *Client) HotspotStop(dev string) types.ActionResult {
	conName := fmt.Sprintf("Hotspot %s", dev)
	out, err := c.run("connection", "down", conName)
	out = strings.TrimSpace(out)

	success := err == nil && (strings.Contains(out, "deactivated") || strings.Contains(out, "Unknown"))
	msg := "Hotspot stopped"
	if !success {
		msg = prettyMessage(out)
	}
	return types.ActionResult{Success: success, Message: msg}
}

// Helper functions

func (c *Client) getLocalWifiMACs() []string {
	var macs []string

	devOut, _ := c.runTerse("DEVICE,TYPE", "device", "status")
	for _, line := range strings.Split(strings.TrimSpace(devOut), "\n") {
		parts := strings.Split(line, ":")
		if len(parts) >= 2 && strings.HasSuffix(parts[1], "wifi") {
			macOut, _ := c.runTerse("GENERAL.HWADDR", "device", "show", parts[0])
			for _, macLine := range strings.Split(macOut, "\n") {
				if strings.HasPrefix(macLine, "GENERAL.HWADDR:") {
					mac := strings.TrimPrefix(macLine, "GENERAL.HWADDR:")
					mac = strings.ToUpper(strings.ReplaceAll(strings.TrimSpace(mac), "-", ":"))
					macs = append(macs, mac)
				}
			}
		}
	}
	return macs
}

func (c *Client) findConnectionBySSID(ssid string) string {
	conOut, _ := c.runTerse("NAME,TYPE", "connection", "show")
	for _, line := range strings.Split(strings.TrimSpace(conOut), "\n") {
		parts := strings.Split(line, ":")
		if len(parts) < 2 {
			continue
		}
		if parts[1] == "802-11-wireless" {
			conSSID, _ := c.runTerse("802-11-wireless.ssid", "connection", "show", parts[0])
			conSSID = strings.TrimPrefix(strings.TrimSpace(conSSID), "802-11-wireless.ssid:")
			if conSSID == ssid {
				return parts[0]
			}
		}
	}
	return ""
}
