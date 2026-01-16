package handlers

import (
	"encoding/json"
	"io"
	"net/http"
	"strings"

	"nm-webui/internal/httputil"
	"nm-webui/internal/ssh"
	"nm-webui/internal/types"
)

// SSHHandler handles SSH-related API endpoints
type SSHHandler struct {
	keyManager    *ssh.KeyManager
	tunnelManager *ssh.TunnelManager
	logAction     func(action, detail string, success bool)
}

// NewSSHHandler creates a new SSH handler
func NewSSHHandler(km *ssh.KeyManager, tm *ssh.TunnelManager, logAction func(string, string, bool)) *SSHHandler {
	return &SSHHandler{
		keyManager:    km,
		tunnelManager: tm,
		logAction:     logAction,
	}
}

// ========== Key Management ==========

// ListKeys handles GET /api/ssh/keys
func (h *SSHHandler) ListKeys(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequireGET(w, r) {
		return
	}

	keys, err := h.keyManager.List()
	if err != nil {
		httputil.JSONError(w, http.StatusInternalServerError, "Failed to list keys", err.Error())
		return
	}

	httputil.JSONOK(w, types.SSHKeyListResult{Keys: keys})
}

// UploadKey handles POST /api/ssh/keys/upload
func (h *SSHHandler) UploadKey(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequirePOST(w, r) {
		return
	}

	// Parse multipart form (max 1MB)
	if err := r.ParseMultipartForm(1 << 20); err != nil {
		httputil.JSONError(w, http.StatusBadRequest, "Invalid form data", err.Error())
		return
	}

	file, header, err := r.FormFile("keyfile")
	if err != nil {
		httputil.JSONError(w, http.StatusBadRequest, "No file uploaded", err.Error())
		return
	}
	defer file.Close()

	// Read file content
	content, err := io.ReadAll(file)
	if err != nil {
		httputil.JSONError(w, http.StatusInternalServerError, "Failed to read file", err.Error())
		return
	}

	// Upload the key
	if err := h.keyManager.Upload(header.Filename, content); err != nil {
		httputil.JSONError(w, http.StatusInternalServerError, "Failed to save key", err.Error())
		return
	}

	h.logAction("SSH: upload_key", header.Filename, true)
	httputil.JSONOK(w, map[string]string{"file": header.Filename})
}

// DeleteKey handles POST /api/ssh/keys/delete
func (h *SSHHandler) DeleteKey(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequirePOST(w, r) {
		return
	}

	var req types.SSHKeyDeleteRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		httputil.JSONError(w, http.StatusBadRequest, "Invalid request", err.Error())
		return
	}

	if req.Name == "" {
		httputil.JSONError(w, http.StatusBadRequest, "Key name required", "")
		return
	}

	if err := h.keyManager.Delete(req.Name); err != nil {
		httputil.JSONError(w, http.StatusInternalServerError, "Failed to delete key", err.Error())
		return
	}

	h.logAction("SSH: delete_key", req.Name, true)
	httputil.JSONOK(w, map[string]bool{"success": true})
}

// GenerateKey handles POST /api/ssh/keys/generate
func (h *SSHHandler) GenerateKey(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequirePOST(w, r) {
		return
	}

	var req types.SSHKeyGenerateRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		httputil.JSONError(w, http.StatusBadRequest, "Invalid request", err.Error())
		return
	}

	result, err := h.keyManager.Generate(req)
	if err != nil {
		httputil.JSONError(w, http.StatusBadRequest, "Failed to generate key", err.Error())
		return
	}

	h.logAction("SSH: generate_key", req.KeyName+" ("+req.KeyType+")", true)
	httputil.JSONOK(w, result)
}

// GetPublicKey handles GET /api/ssh/keys/public?name=keyname
func (h *SSHHandler) GetPublicKey(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequireGET(w, r) {
		return
	}

	keyName := r.URL.Query().Get("name")
	if keyName == "" {
		httputil.JSONError(w, http.StatusBadRequest, "Key name required", "")
		return
	}

	content, err := h.keyManager.GetPublicKey(keyName)
	if err != nil {
		httputil.JSONError(w, http.StatusNotFound, "Public key not found", err.Error())
		return
	}

	httputil.JSONOK(w, map[string]string{"content": content})
}

// DownloadPublicKey handles GET /api/ssh/keys/download?name=keyname
func (h *SSHHandler) DownloadPublicKey(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequireGET(w, r) {
		return
	}

	keyName := r.URL.Query().Get("name")
	if keyName == "" {
		httputil.JSONError(w, http.StatusBadRequest, "Key name required", "")
		return
	}

	content, err := h.keyManager.GetPublicKey(keyName)
	if err != nil {
		httputil.JSONError(w, http.StatusNotFound, "Public key not found", err.Error())
		return
	}

	// Set headers for file download
	w.Header().Set("Content-Type", "application/octet-stream")
	w.Header().Set("Content-Disposition", "attachment; filename=\""+keyName+".pub\"")
	w.Write([]byte(content))
}

// ========== Tunnel Management ==========

// ListTunnels handles GET /api/ssh/tunnels
func (h *SSHHandler) ListTunnels(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequireGET(w, r) {
		return
	}

	tunnels := h.tunnelManager.List()
	httputil.JSONOK(w, types.SSHTunnelListResult{Tunnels: tunnels})
}

// CreateTunnel handles POST /api/ssh/tunnels/create
func (h *SSHHandler) CreateTunnel(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequirePOST(w, r) {
		return
	}

	var req types.SSHTunnelCreateRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		httputil.JSONError(w, http.StatusBadRequest, "Invalid request", err.Error())
		return
	}

	// Set defaults
	if req.Port == 0 {
		req.Port = 22
	}
	if req.RHost == "" {
		req.RHost = "127.0.0.1"
	}

	tunnel, err := h.tunnelManager.Create(req)
	if err != nil {
		httputil.JSONError(w, http.StatusBadRequest, "Failed to create tunnel", err.Error())
		return
	}

	h.logAction("SSH: create_tunnel", req.User+"@"+req.Host+" ("+req.FwdType+")", true)
	httputil.JSONOK(w, tunnel)
}

// StartTunnel handles POST /api/ssh/tunnels/start
func (h *SSHHandler) StartTunnel(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequirePOST(w, r) {
		return
	}

	var req types.SSHTunnelIDRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		httputil.JSONError(w, http.StatusBadRequest, "Invalid request", err.Error())
		return
	}

	if req.ID == "" {
		httputil.JSONError(w, http.StatusBadRequest, "Tunnel ID required", "")
		return
	}

	if err := h.tunnelManager.Start(req.ID); err != nil {
		httputil.JSONError(w, http.StatusBadRequest, "Failed to start tunnel", err.Error())
		return
	}

	h.logAction("SSH: start_tunnel", req.ID, true)
	httputil.JSONOK(w, map[string]bool{"success": true})
}

// StopTunnel handles POST /api/ssh/tunnels/stop
func (h *SSHHandler) StopTunnel(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequirePOST(w, r) {
		return
	}

	var req types.SSHTunnelIDRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		httputil.JSONError(w, http.StatusBadRequest, "Invalid request", err.Error())
		return
	}

	if req.ID == "" {
		httputil.JSONError(w, http.StatusBadRequest, "Tunnel ID required", "")
		return
	}

	if err := h.tunnelManager.Stop(req.ID); err != nil {
		httputil.JSONError(w, http.StatusBadRequest, "Failed to stop tunnel", err.Error())
		return
	}

	h.logAction("SSH: stop_tunnel", req.ID, true)
	httputil.JSONOK(w, map[string]bool{"success": true})
}

// DeleteTunnel handles POST /api/ssh/tunnels/delete
func (h *SSHHandler) DeleteTunnel(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequirePOST(w, r) {
		return
	}

	var req types.SSHTunnelIDRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		httputil.JSONError(w, http.StatusBadRequest, "Invalid request", err.Error())
		return
	}

	if req.ID == "" {
		httputil.JSONError(w, http.StatusBadRequest, "Tunnel ID required", "")
		return
	}

	if err := h.tunnelManager.Delete(req.ID); err != nil {
		httputil.JSONError(w, http.StatusBadRequest, "Failed to delete tunnel", err.Error())
		return
	}

	h.logAction("SSH: delete_tunnel", req.ID, true)
	httputil.JSONOK(w, map[string]bool{"success": true})
}

// GetTunnel handles GET /api/ssh/tunnels/{id} - get single tunnel details
func (h *SSHHandler) GetTunnel(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequireGET(w, r) {
		return
	}

	// Extract ID from path
	path := strings.TrimPrefix(r.URL.Path, "/api/ssh/tunnels/")
	if path == "" || path == r.URL.Path {
		httputil.JSONError(w, http.StatusBadRequest, "Tunnel ID required", "")
		return
	}

	tunnels := h.tunnelManager.List()
	for _, t := range tunnels {
		if t.ID == path {
			httputil.JSONOK(w, t)
			return
		}
	}

	httputil.JSONError(w, http.StatusNotFound, "Tunnel not found", "")
}
