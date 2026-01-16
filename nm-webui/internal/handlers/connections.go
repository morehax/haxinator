package handlers

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"

	"nm-webui/internal/httputil"
	"nm-webui/internal/nmcli"
	"nm-webui/internal/types"
)

// ConnectionsHandler handles connection-related API endpoints
type ConnectionsHandler struct {
	nmcli  *nmcli.Client
	addLog LogFunc
}

// NewConnectionsHandler creates a new connections handler
func NewConnectionsHandler(client *nmcli.Client, logFn LogFunc) *ConnectionsHandler {
	return &ConnectionsHandler{nmcli: client, addLog: logFn}
}

// List handles GET /api/connections
func (h *ConnectionsHandler) List(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequireGET(w, r) {
		return
	}

	conns, err := h.nmcli.ConnectionsList()
	if err != nil {
		httputil.JSONError(w, http.StatusInternalServerError, "Failed to list connections", err.Error())
		return
	}

	httputil.JSONOK(w, conns)
}

// Activate handles POST /api/connections/activate
func (h *ConnectionsHandler) Activate(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequirePOST(w, r) {
		return
	}

	var req types.ConnectionUUIDRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		httputil.JSONError(w, http.StatusBadRequest, "Invalid request body", err.Error())
		return
	}

	result := h.nmcli.ConnectionActivate(req.UUID)
	h.addLog("connection_activate", fmt.Sprintf("UUID: %s", req.UUID), result.Success)

	httputil.JSONOK(w, result)
}

// Deactivate handles POST /api/connections/deactivate
func (h *ConnectionsHandler) Deactivate(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequirePOST(w, r) {
		return
	}

	var req types.ConnectionUUIDRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		httputil.JSONError(w, http.StatusBadRequest, "Invalid request body", err.Error())
		return
	}

	result := h.nmcli.ConnectionDeactivate(req.UUID)
	h.addLog("connection_deactivate", fmt.Sprintf("UUID: %s", req.UUID), result.Success)

	httputil.JSONOK(w, result)
}

// Delete handles DELETE /api/connections/delete/{uuid}
func (h *ConnectionsHandler) Delete(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodDelete && r.Method != http.MethodPost {
		httputil.JSONError(w, http.StatusMethodNotAllowed, "Method not allowed", "Use DELETE or POST")
		return
	}

	// Extract UUID from path
	path := strings.TrimPrefix(r.URL.Path, "/api/connections/delete/")
	uuid := strings.TrimSpace(path)

	if uuid == "" {
		httputil.JSONError(w, http.StatusBadRequest, "UUID is required", "")
		return
	}

	result := h.nmcli.ConnectionDelete(uuid)
	h.addLog("connection_delete", fmt.Sprintf("UUID: %s", uuid), result.Success)

	httputil.JSONOK(w, result)
}

// Share handles POST /api/connections/share
func (h *ConnectionsHandler) Share(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequirePOST(w, r) {
		return
	}

	var req types.ConnectionShareRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		httputil.JSONError(w, http.StatusBadRequest, "Invalid request body", err.Error())
		return
	}

	result := h.nmcli.ConnectionShare(req.UUID, req.Enable)
	h.addLog("connection_share", fmt.Sprintf("UUID: %s, Enable: %v", req.UUID, req.Enable), result.Success)

	httputil.JSONOK(w, result)
}
