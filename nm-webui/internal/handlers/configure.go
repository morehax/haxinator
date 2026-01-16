package handlers

import (
	"encoding/json"
	"net/http"

	"nm-webui/internal/configure"
	"nm-webui/internal/httputil"
)

// ConfigureHandler handles configuration-related API requests
type ConfigureHandler struct {
	fileManager    *configure.FileManager
	networkManager *configure.NetworkManager
	logAction      func(category, action, detail string, success bool)
}

// NewConfigureHandler creates a new ConfigureHandler
func NewConfigureHandler(basePath string, logAction func(category, action, detail string, success bool)) *ConfigureHandler {
	fm := configure.NewFileManager(basePath)
	nm := configure.NewNetworkManager(fm)
	return &ConfigureHandler{
		fileManager:    fm,
		networkManager: nm,
		logAction:      logAction,
	}
}

// GetFileStatus returns the status of all configuration files
func (h *ConfigureHandler) GetFileStatus(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequireGET(w, r) {
		return
	}

	status := h.fileManager.GetAllFileStatus()
	httputil.JSONOK(w, status)
}

// ViewFile returns the contents of a configuration file
func (h *ConfigureHandler) ViewFile(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequireGET(w, r) {
		return
	}

	fileType := r.URL.Query().Get("type")
	ft, valid := configure.ValidateFileType(fileType)
	if !valid {
		httputil.JSONError(w, http.StatusBadRequest, "Invalid file type", "Provide a valid type parameter")
		return
	}

	content, err := h.fileManager.ViewFile(ft)
	if err != nil {
		httputil.JSONError(w, http.StatusNotFound, "File not found", err.Error())
		return
	}

	status := h.fileManager.GetFileStatus(ft)
	httputil.JSONOK(w, map[string]interface{}{
		"success": true,
		"content": content,
		"size":    status.Size,
	})
}

// UploadFile handles file uploads
func (h *ConfigureHandler) UploadFile(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequirePOST(w, r) {
		return
	}

	// Parse multipart form (max 2MB)
	if err := r.ParseMultipartForm(2 << 20); err != nil {
		httputil.JSONError(w, http.StatusBadRequest, "Failed to parse form", err.Error())
		return
	}

	fileType := r.FormValue("type")
	ft, valid := configure.ValidateFileType(fileType)
	if !valid {
		httputil.JSONError(w, http.StatusBadRequest, "Invalid file type", "Provide a valid type parameter")
		return
	}

	file, header, err := r.FormFile("file")
	if err != nil {
		httputil.JSONError(w, http.StatusBadRequest, "No file uploaded", err.Error())
		return
	}
	defer file.Close()

	if err := h.fileManager.SaveFile(ft, file, header.Size); err != nil {
		h.logAction("configure", "upload", fileType+": "+err.Error(), false)
		httputil.JSONError(w, http.StatusBadRequest, "Upload failed", err.Error())
		return
	}

	h.logAction("configure", "upload", fileType+" uploaded successfully", true)
	httputil.JSONMessage(w, "File uploaded successfully")
}

// DeleteFile handles file deletion
func (h *ConfigureHandler) DeleteFile(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequirePOST(w, r) {
		return
	}

	var req struct {
		Type string `json:"type"`
	}
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		httputil.JSONError(w, http.StatusBadRequest, "Invalid request", err.Error())
		return
	}

	ft, valid := configure.ValidateFileType(req.Type)
	if !valid {
		httputil.JSONError(w, http.StatusBadRequest, "Invalid file type", "Provide a valid type")
		return
	}

	if err := h.fileManager.DeleteFile(ft); err != nil {
		h.logAction("configure", "delete", req.Type+": "+err.Error(), false)
		httputil.JSONError(w, http.StatusInternalServerError, "Delete failed", err.Error())
		return
	}

	h.logAction("configure", "delete", req.Type+" deleted", true)
	httputil.JSONMessage(w, "File deleted successfully")
}

// GetNetworkConfigs returns detected network configurations
func (h *ConfigureHandler) GetNetworkConfigs(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequireGET(w, r) {
		return
	}

	configs, err := h.networkManager.DetectConfigurations()
	if err != nil {
		// Return empty array if env-secrets doesn't exist
		httputil.JSONOK(w, map[string]interface{}{
			"success": true,
			"configs": []configure.NetworkConfig{},
		})
		return
	}

	httputil.JSONOK(w, map[string]interface{}{
		"success": true,
		"configs": configs,
	})
}

// ApplyNetworkConfig applies selected network configurations
func (h *ConfigureHandler) ApplyNetworkConfig(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequirePOST(w, r) {
		return
	}

	var req struct {
		Configs []string `json:"configs"` // Array of config types to apply
	}
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		httputil.JSONError(w, http.StatusBadRequest, "Invalid request", err.Error())
		return
	}

	if len(req.Configs) == 0 {
		httputil.JSONError(w, http.StatusBadRequest, "No configurations selected", "Select at least one configuration")
		return
	}

	var results []string
	var errors []string

	for _, configType := range req.Configs {
		ct, valid := configure.ValidateConfigType(configType)
		if !valid {
			errors = append(errors, "Invalid config type: "+configType)
			continue
		}

		if err := h.networkManager.ApplyConfiguration(ct); err != nil {
			h.logAction("configure", "apply", configType+": "+err.Error(), false)
			errors = append(errors, configType+": "+err.Error())
		} else {
			h.logAction("configure", "apply", configType+" configured successfully", true)
			results = append(results, configType+" configured successfully")
		}
	}

	if len(errors) > 0 {
		httputil.JSONOK(w, map[string]interface{}{
			"success": false,
			"results": results,
			"errors":  errors,
		})
		return
	}

	httputil.JSONOK(w, map[string]interface{}{
		"success": true,
		"results": results,
	})
}
