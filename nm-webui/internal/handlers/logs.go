package handlers

import (
	"encoding/json"
	"net/http"
	"strconv"
	"time"

	"nm-webui/internal/httputil"
	"nm-webui/internal/logger"
)

// LogsHandler handles logging-related API endpoints
type LogsHandler struct {
	log *logger.Logger
}

// NewLogsHandler creates a new logs handler
func NewLogsHandler(log *logger.Logger) *LogsHandler {
	return &LogsHandler{log: log}
}

// GetLogs handles GET /api/logs
func (h *LogsHandler) GetLogs(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequireGET(w, r) {
		return
	}

	// Parse query parameters for filtering
	query := r.URL.Query()
	
	filter := &logger.Filter{}
	
	if cat := query.Get("category"); cat != "" {
		filter.Category = cat
	}
	if lvl := query.Get("level"); lvl != "" {
		filter.Level = lvl
	}
	if limit := query.Get("limit"); limit != "" {
		if n, err := strconv.Atoi(limit); err == nil {
			filter.Limit = n
		}
	}
	if search := query.Get("search"); search != "" {
		filter.Search = search
	}
	if since := query.Get("since"); since != "" {
		if t, err := time.Parse(time.RFC3339, since); err == nil {
			filter.Since = t
		}
	}
	if success := query.Get("success"); success != "" {
		if b, err := strconv.ParseBool(success); err == nil {
			filter.Success = &b
		}
	}

	entries := h.log.GetEntries(filter)
	
	httputil.JSONOK(w, map[string]interface{}{
		"entries": entries,
		"count":   len(entries),
	})
}

// GetSettings handles GET /api/logs/settings
func (h *LogsHandler) GetSettings(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequireGET(w, r) {
		return
	}

	settings := h.log.Settings()
	httputil.JSONOK(w, settings)
}

// UpdateSettings handles POST /api/logs/settings
func (h *LogsHandler) UpdateSettings(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequirePOST(w, r) {
		return
	}

	var settings logger.Settings
	if err := json.NewDecoder(r.Body).Decode(&settings); err != nil {
		httputil.JSONError(w, http.StatusBadRequest, "Invalid request body", err.Error())
		return
	}

	h.log.UpdateSettings(settings)
	httputil.JSONMessage(w, "Settings updated")
}

// Toggle handles POST /api/logs/toggle
func (h *LogsHandler) Toggle(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequirePOST(w, r) {
		return
	}

	var req struct {
		Enabled bool `json:"enabled"`
	}
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		httputil.JSONError(w, http.StatusBadRequest, "Invalid request body", err.Error())
		return
	}

	h.log.SetEnabled(req.Enabled)
	
	status := "disabled"
	if req.Enabled {
		status = "enabled"
	}
	httputil.JSONMessage(w, "Logging "+status)
}

// Clear handles POST /api/logs/clear
func (h *LogsHandler) Clear(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequirePOST(w, r) {
		return
	}

	h.log.Clear()
	httputil.JSONMessage(w, "Logs cleared")
}

// Stats handles GET /api/logs/stats
func (h *LogsHandler) Stats(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequireGET(w, r) {
		return
	}

	stats := h.log.Stats()
	httputil.JSONOK(w, stats)
}
