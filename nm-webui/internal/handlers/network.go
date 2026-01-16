// Package handlers contains HTTP request handlers
package handlers

import (
	"encoding/json"
	"fmt"
	"net/http"

	"nm-webui/internal/httputil"
	"nm-webui/internal/nmcli"
	"nm-webui/internal/types"
)

// NetworkHandler handles network interface API endpoints
type NetworkHandler struct {
	nmcli  *nmcli.Client
	addLog LogFunc
}

// NewNetworkHandler creates a new network handler
func NewNetworkHandler(client *nmcli.Client, logFn LogFunc) *NetworkHandler {
	return &NetworkHandler{nmcli: client, addLog: logFn}
}

// ListInterfaces handles GET /api/network/interfaces
func (h *NetworkHandler) ListInterfaces(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequireGET(w, r) {
		return
	}

	interfaces, err := h.nmcli.GetInterfaces()
	if err != nil {
		httputil.JSONError(w, http.StatusInternalServerError, "Failed to get interfaces", err.Error())
		return
	}

	// Also get the upstream interface (the one with internet)
	upstream := h.nmcli.GetUpstreamInterface()

	result := map[string]interface{}{
		"interfaces": interfaces,
		"upstream":   upstream,
	}

	httputil.JSONOK(w, result)
}

// ToggleSharing handles POST /api/network/share
func (h *NetworkHandler) ToggleSharing(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequirePOST(w, r) {
		return
	}

	var req types.NetworkShareRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		httputil.JSONError(w, http.StatusBadRequest, "Invalid request body", err.Error())
		return
	}

	if req.Device == "" {
		httputil.JSONError(w, http.StatusBadRequest, "Device is required", "")
		return
	}

	// If no upstream specified, auto-detect
	upstream := req.Upstream
	if upstream == "" && req.Enable {
		upstream = h.nmcli.GetUpstreamInterface()
	}

	result := h.nmcli.SetInterfaceSharing(req.Device, req.Enable, upstream)
	
	action := "disabled"
	if req.Enable {
		action = "enabled"
	}
	h.addLog("network_sharing", fmt.Sprintf("Device: %s, Action: %s", req.Device, action), result.Success)

	httputil.JSONOK(w, result)
}
