// Network interface management functions for nmcli
package nmcli

import (
	"os/exec"
	"regexp"
	"strconv"
	"strings"

	"nm-webui/internal/types"
)

// GetInterfaces returns detailed information about all network interfaces
func (c *Client) GetInterfaces() ([]types.NetworkInterface, error) {
	// Get list of devices with basic info
	output, err := c.runTerse("DEVICE,TYPE,STATE,CONNECTION", "device", "status")
	if err != nil {
		return nil, err
	}

	var interfaces []types.NetworkInterface
	lines := strings.Split(strings.TrimSpace(output), "\n")

	for _, line := range lines {
		if line == "" {
			continue
		}
		parts := parseEscapedLine(line)
		if len(parts) < 4 {
			continue
		}

		device := parts[0]
		devType := parts[1]
		state := parts[2]
		connection := parts[3]

		// Skip loopback
		if devType == "loopback" {
			continue
		}

		// Get detailed info for this device
		iface := types.NetworkInterface{
			Device:     device,
			Type:       devType,
			State:      state,
			Connection: connection,
		}

		// Get device details
		c.enrichInterfaceDetails(&iface)

		// Check if sharing is enabled
		if connection != "" && connection != "--" {
			iface.Sharing = c.isConnectionSharing(connection)
		}

		interfaces = append(interfaces, iface)
	}

	return interfaces, nil
}

// enrichInterfaceDetails adds detailed info to an interface
func (c *Client) enrichInterfaceDetails(iface *types.NetworkInterface) {
	// Use nmcli device show to get full details
	output, err := c.run("device", "show", iface.Device)
	if err != nil {
		return
	}

	// Parse key-value output
	for _, line := range strings.Split(output, "\n") {
		line = strings.TrimSpace(line)
		if line == "" {
			continue
		}

		// Split on first colon
		idx := strings.Index(line, ":")
		if idx < 0 {
			continue
		}
		key := strings.TrimSpace(line[:idx])
		value := strings.TrimSpace(line[idx+1:])

		switch key {
		case "GENERAL.HWADDR":
			iface.HWAddr = value
		case "GENERAL.MTU":
			if mtu, err := strconv.Atoi(value); err == nil {
				iface.MTU = mtu
			}
		case "GENERAL.DRIVER":
			iface.Driver = value
		case "IP4.ADDRESS[1]":
			iface.IP4Address = value
		case "IP4.GATEWAY":
			iface.IP4Gateway = value
		case "IP4.DNS[1]":
			iface.IP4DNS = value
		case "IP6.ADDRESS[1]":
			iface.IP6Address = value
		case "WIRED-PROPERTIES.CARRIER":
			// Could use this to check if cable is connected
		}
	}

	// Get speed for ethernet interfaces
	if iface.Type == "ethernet" && iface.State == "connected" {
		iface.Speed = c.getInterfaceSpeed(iface.Device)
	}
}

// getInterfaceSpeed returns the link speed for an interface
func (c *Client) getInterfaceSpeed(device string) string {
	// Try ethtool first
	output, err := c.runExec("ethtool", device)
	if err == nil {
		re := regexp.MustCompile(`Speed:\s*(\d+\s*\w+/s)`)
		if match := re.FindStringSubmatch(output); len(match) > 1 {
			return match[1]
		}
	}

	// Try reading from /sys
	output, err = c.runExec("cat", "/sys/class/net/"+device+"/speed")
	if err == nil {
		speed := strings.TrimSpace(output)
		if speed != "" && speed != "-1" {
			return speed + " Mb/s"
		}
	}

	return ""
}

// runExec runs an arbitrary command (not nmcli)
func (c *Client) runExec(name string, args ...string) (string, error) {
	cmd := exec.Command(name, args...)
	output, err := cmd.CombinedOutput()
	return string(output), err
}

// isConnectionSharing checks if a connection has sharing enabled
func (c *Client) isConnectionSharing(connectionName string) bool {
	output, err := c.runTerse("ipv4.method", "connection", "show", connectionName)
	if err != nil {
		return false
	}
	return strings.Contains(strings.TrimSpace(output), "shared")
}

// SetInterfaceSharing enables or disables internet sharing on an interface
func (c *Client) SetInterfaceSharing(device string, enable bool, upstream string) types.ActionResult {
	// First, find the connection profile for this device
	connName, err := c.getConnectionForDevice(device)
	if err != nil || connName == "" {
		return types.ActionResult{
			Success: false,
			Message: "No connection profile found for device " + device,
		}
	}

	var method string
	if enable {
		method = "shared"
	} else {
		method = "auto"
	}

	// Modify the connection
	output, err := c.run("connection", "modify", connName, "ipv4.method", method)
	if err != nil {
		return types.ActionResult{
			Success: false,
			Message: prettyMessage(output),
		}
	}

	// Reactivate the connection to apply changes
	output, err = c.run("connection", "up", connName)
	if err != nil {
		return types.ActionResult{
			Success: false,
			Message: "Sharing configured but failed to reactivate: " + prettyMessage(output),
		}
	}

	action := "enabled"
	if !enable {
		action = "disabled"
	}

	return types.ActionResult{
		Success: true,
		Message: "Internet sharing " + action + " on " + device,
	}
}

// getConnectionForDevice finds the active connection profile for a device
func (c *Client) getConnectionForDevice(device string) (string, error) {
	output, err := c.runTerse("NAME", "connection", "show", "--active")
	if err != nil {
		return "", err
	}

	// Get the device for each active connection
	lines := strings.Split(strings.TrimSpace(output), "\n")
	for _, connName := range lines {
		if connName == "" {
			continue
		}
		// Check if this connection is on our device
		devOutput, err := c.runTerse("connection.interface-name,GENERAL.DEVICES", "connection", "show", connName)
		if err != nil {
			continue
		}
		if strings.Contains(devOutput, device) {
			return connName, nil
		}
	}

	// If no active connection, look for any connection associated with this device
	output, err = c.runTerse("NAME,DEVICE", "connection", "show")
	if err != nil {
		return "", err
	}

	lines = strings.Split(strings.TrimSpace(output), "\n")
	for _, line := range lines {
		parts := parseEscapedLine(line)
		if len(parts) >= 2 && parts[1] == device {
			return parts[0], nil
		}
	}

	return "", nil
}

// GetUpstreamInterface returns the interface that has internet connectivity
func (c *Client) GetUpstreamInterface() string {
	// Find the default route
	output, err := c.runExec("ip", "route", "show", "default")
	if err != nil {
		return ""
	}

	// Parse "default via X.X.X.X dev ethX ..."
	re := regexp.MustCompile(`default via \S+ dev (\S+)`)
	if match := re.FindStringSubmatch(output); len(match) > 1 {
		return match[1]
	}

	return ""
}
