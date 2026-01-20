package nmcli

import (
	"strings"

	"nm-webui/internal/types"
)

// ConnectionsList returns all saved connections
func (c *Client) ConnectionsList() ([]types.Connection, error) {
	out, err := c.runTerse("NAME,UUID,TYPE,DEVICE,AUTOCONNECT", "connection", "show")
	if err != nil {
		return nil, err
	}

	var conns []types.Connection
	for _, line := range strings.Split(strings.TrimSpace(out), "\n") {
		if line == "" {
			continue
		}
		parts := parseEscapedLine(line)
		if len(parts) < 5 {
			continue
		}

		connType := parts[2]

		// Skip tun connections - they are managed by parent VPN connections
		if connType == "tun" {
			continue
		}

		// Hide loopback connection from UI
		if connType == "loopback" || parts[0] == "lo" || parts[3] == "lo" {
			continue
		}

		conns = append(conns, types.Connection{
			Name:        parts[0],
			UUID:        parts[1],
			Type:        connType,
			Device:      parts[3],
			Active:      parts[3] != "" && parts[3] != "--",
			AutoConnect: parts[4] == "yes",
		})
	}

	return conns, nil
}

// ConnectionActivate activates a connection by UUID
func (c *Client) ConnectionActivate(uuid string) types.ActionResult {
	if !isValidUUID(uuid) {
		return types.ActionResult{Success: false, Message: "Invalid UUID"}
	}

	out, err := c.run("connection", "up", "uuid", uuid)
	out = strings.TrimSpace(out)

	success := err == nil && strings.Contains(out, "successfully")
	return types.ActionResult{Success: success, Message: prettyMessage(out)}
}

// ConnectionDeactivate deactivates a connection by UUID
func (c *Client) ConnectionDeactivate(uuid string) types.ActionResult {
	if !isValidUUID(uuid) {
		return types.ActionResult{Success: false, Message: "Invalid UUID"}
	}

	out, err := c.run("connection", "down", "uuid", uuid)
	out = strings.TrimSpace(out)

	success := err == nil && (strings.Contains(out, "successfully") || strings.Contains(out, "deactivated"))
	return types.ActionResult{Success: success, Message: prettyMessage(out)}
}

// ConnectionDelete deletes a connection by UUID
func (c *Client) ConnectionDelete(uuid string) types.ActionResult {
	if !isValidUUID(uuid) {
		return types.ActionResult{Success: false, Message: "Invalid UUID"}
	}

	out, err := c.run("connection", "delete", "uuid", uuid)
	out = strings.TrimSpace(out)

	success := err == nil && strings.Contains(out, "deleted")
	return types.ActionResult{Success: success, Message: prettyMessage(out)}
}

// ConnectionShare toggles connection sharing (ipv4.method=shared)
func (c *Client) ConnectionShare(uuid string, enable bool) types.ActionResult {
	if !isValidUUID(uuid) {
		return types.ActionResult{Success: false, Message: "Invalid UUID"}
	}

	method := "auto"
	if enable {
		method = "shared"
	}

	out, err := c.run("connection", "modify", uuid, "ipv4.method", method, "ipv6.method", "ignore")
	success := err == nil && strings.TrimSpace(out) == ""
	msg := "Settings saved"
	if !success {
		msg = strings.TrimSpace(out)
	}
	return types.ActionResult{Success: success, Message: msg}
}
