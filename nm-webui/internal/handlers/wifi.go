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

// LogFunc is a function signature for logging actions
type LogFunc func(action, detail string, success bool)

// WifiHandler handles WiFi-related API endpoints
type WifiHandler struct {
	nmcli  *nmcli.Client
	addLog LogFunc
}

// NewWifiHandler creates a new WiFi handler
func NewWifiHandler(client *nmcli.Client, logFn LogFunc) *WifiHandler {
	return &WifiHandler{nmcli: client, addLog: logFn}
}

// Scan handles GET /api/wifi/scan
func (h *WifiHandler) Scan(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequireGET(w, r) {
		return
	}

	dev := r.URL.Query().Get("dev")
	rescan := r.URL.Query().Get("rescan") != "no"

	result, err := h.nmcli.WifiScan(dev, rescan)
	if err != nil {
		httputil.JSONError(w, http.StatusInternalServerError, "Failed to scan WiFi", err.Error())
		return
	}

	httputil.JSONOK(w, result)
}

// Connect handles POST /api/wifi/connect
func (h *WifiHandler) Connect(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequirePOST(w, r) {
		return
	}

	var req types.WifiConnectRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		httputil.JSONError(w, http.StatusBadRequest, "Invalid request body", err.Error())
		return
	}

	// Validate
	if req.SSID == "" {
		httputil.JSONError(w, http.StatusBadRequest, "SSID is required", "")
		return
	}
	if len(req.SSID) > 32 {
		httputil.JSONError(w, http.StatusBadRequest, "SSID too long", "Maximum 32 characters")
		return
	}
	if len(req.Password) > 63 {
		httputil.JSONError(w, http.StatusBadRequest, "Password too long", "Maximum 63 characters")
		return
	}

	result := h.nmcli.WifiConnect(req.Dev, req.SSID, req.Password, req.Hidden)
	h.addLog("wifi_connect", fmt.Sprintf("SSID: %s, Device: %s", req.SSID, req.Dev), result.Success)

	httputil.JSONOK(w, result)
}

// Disconnect handles POST /api/wifi/disconnect
func (h *WifiHandler) Disconnect(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequirePOST(w, r) {
		return
	}

	var req types.WifiDisconnectRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		httputil.JSONError(w, http.StatusBadRequest, "Invalid request body", err.Error())
		return
	}

	result := h.nmcli.WifiDisconnect(req.SSID, req.IsHotspot)
	h.addLog("wifi_disconnect", fmt.Sprintf("SSID: %s", req.SSID), result.Success)

	httputil.JSONOK(w, result)
}

// Forget handles POST /api/wifi/forget
func (h *WifiHandler) Forget(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequirePOST(w, r) {
		return
	}

	var req types.WifiForgetRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		httputil.JSONError(w, http.StatusBadRequest, "Invalid request body", err.Error())
		return
	}

	result := h.nmcli.WifiForget(req.SSID, req.IsHotspot)
	h.addLog("wifi_forget", fmt.Sprintf("SSID: %s", req.SSID), result.Success)

	httputil.JSONOK(w, result)
}

// SetPriority handles POST /api/wifi/priority
func (h *WifiHandler) SetPriority(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequirePOST(w, r) {
		return
	}

	var req types.WifiPriorityRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		httputil.JSONError(w, http.StatusBadRequest, "Invalid request body", err.Error())
		return
	}

	if req.Priority < -100 || req.Priority > 100 {
		httputil.JSONError(w, http.StatusBadRequest, "Priority out of range", "Must be between -100 and 100")
		return
	}

	result := h.nmcli.SetPriority(req.UUID, req.Priority)
	h.addLog("set_priority", fmt.Sprintf("UUID: %s, Priority: %d", req.UUID, req.Priority), result.Success)

	httputil.JSONOK(w, result)
}

// Hotspot handles POST /api/wifi/hotspot
func (h *WifiHandler) Hotspot(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequirePOST(w, r) {
		return
	}

	var req types.HotspotRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		httputil.JSONError(w, http.StatusBadRequest, "Invalid request body", err.Error())
		return
	}

	var result types.ActionResult

	if req.Mode == "stop" {
		result = h.nmcli.HotspotStop(req.Dev)
		h.addLog("hotspot_stop", fmt.Sprintf("Device: %s", req.Dev), result.Success)
	} else {
		result = h.nmcli.HotspotStart(req.Dev, req.SSID, req.Password, req.Band, req.Channel, req.ConName, req.IPRange, req.Persistent)
		h.addLog("hotspot_start", fmt.Sprintf("SSID: %s, Device: %s", req.SSID, req.Dev), result.Success)
	}

	httputil.JSONOK(w, result)
}
