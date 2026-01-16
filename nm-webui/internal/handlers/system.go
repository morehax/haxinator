// Package handlers contains HTTP request handlers
package handlers

import (
	"fmt"
	"net/http"
	"os/exec"

	"nm-webui/internal/httputil"
	"nm-webui/internal/logger"
)

// SystemHandler handles system-level operations
type SystemHandler struct {
	logger *logger.Logger
}

// NewSystemHandler creates a new system handler
func NewSystemHandler(log *logger.Logger) *SystemHandler {
	return &SystemHandler{
		logger: log,
	}
}

// Shutdown initiates system shutdown
func (h *SystemHandler) Shutdown(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		httputil.JSONError(w, http.StatusMethodNotAllowed, "Method not allowed", "POST required")
		return
	}

	h.logger.Info("system", "Shutdown requested").Commit()

	// Execute shutdown command
	cmd := exec.Command("sudo", "/sbin/poweroff")
	err := cmd.Start()
	if err != nil {
		h.logger.Error("system", fmt.Sprintf("Shutdown failed: %v", err)).Commit()
		httputil.JSONError(w, http.StatusInternalServerError, "Shutdown failed", err.Error())
		return
	}

	httputil.JSONMessage(w, "System is shutting down...")
}

// Reboot initiates system reboot
func (h *SystemHandler) Reboot(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		httputil.JSONError(w, http.StatusMethodNotAllowed, "Method not allowed", "POST required")
		return
	}

	h.logger.Info("system", "Reboot requested").Commit()

	// Execute reboot command
	cmd := exec.Command("sudo", "/sbin/reboot")
	err := cmd.Start()
	if err != nil {
		h.logger.Error("system", fmt.Sprintf("Reboot failed: %v", err)).Commit()
		httputil.JSONError(w, http.StatusInternalServerError, "Reboot failed", err.Error())
		return
	}

	httputil.JSONMessage(w, "System is rebooting...")
}
