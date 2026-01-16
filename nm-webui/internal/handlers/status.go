package handlers

import (
	"context"
	"io"
	"net/http"
	"os/exec"
	"regexp"
	"strconv"
	"strings"
	"time"

	"nm-webui/internal/httputil"
	"nm-webui/internal/nmcli"
	"nm-webui/internal/types"
)

// GetLogsFunc is a function signature for retrieving logs
type GetLogsFunc func() []types.LogEntry

// StatusHandler handles status and log API endpoints
type StatusHandler struct {
	nmcli   *nmcli.Client
	getLogs GetLogsFunc
}

// NewStatusHandler creates a new status handler
func NewStatusHandler(client *nmcli.Client, logsFn GetLogsFunc) *StatusHandler {
	return &StatusHandler{nmcli: client, getLogs: logsFn}
}

// GetStatus handles GET /api/status
func (h *StatusHandler) GetStatus(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequireGET(w, r) {
		return
	}

	status, err := h.nmcli.GetStatus()
	if err != nil {
		httputil.JSONError(w, http.StatusInternalServerError, "Failed to get status", err.Error())
		return
	}

	httputil.JSONOK(w, status)
}

// GetLogs handles GET /api/log
func (h *StatusHandler) GetLogs(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequireGET(w, r) {
		return
	}

	logs := h.getLogs()
	httputil.JSONOK(w, logs)
}

// GetExternalIP handles GET /api/status/external-ip
func (h *StatusHandler) GetExternalIP(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequireGET(w, r) {
		return
	}

	client := &http.Client{Timeout: 3 * time.Second}
	resp, err := client.Get("https://ifconfig.me/ip")
	if err != nil {
		httputil.JSONError(w, http.StatusBadGateway, "Failed to fetch external IP", err.Error())
		return
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		httputil.JSONError(w, http.StatusBadGateway, "Failed to fetch external IP", resp.Status)
		return
	}

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		httputil.JSONError(w, http.StatusBadGateway, "Failed to read external IP", err.Error())
		return
	}

	ip := strings.TrimSpace(string(body))
	if ip == "" {
		httputil.JSONError(w, http.StatusBadGateway, "External IP response was empty", "")
		return
	}

	httputil.JSONOK(w, ip)
}

// GetDNSLookup handles GET /api/status/dns
func (h *StatusHandler) GetDNSLookup(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequireGET(w, r) {
		return
	}

	ctx, cancel := context.WithTimeout(r.Context(), 3*time.Second)
	defer cancel()

	cmd := exec.CommandContext(ctx, "getent", "hosts", "google.com")
	output, err := cmd.Output()
	if ctx.Err() != nil {
		httputil.JSONError(w, http.StatusGatewayTimeout, "DNS lookup timed out", "")
		return
	}
	if err != nil {
		httputil.JSONError(w, http.StatusBadGateway, "DNS lookup failed", err.Error())
		return
	}

	addresses := make([]string, 0)
	seen := make(map[string]bool)
	for _, line := range strings.Split(strings.TrimSpace(string(output)), "\n") {
		fields := strings.Fields(line)
		if len(fields) == 0 {
			continue
		}
		addr := fields[0]
		if addr != "" && !seen[addr] {
			seen[addr] = true
			addresses = append(addresses, addr)
		}
	}

	if len(addresses) == 0 {
		httputil.JSONError(w, http.StatusBadGateway, "DNS lookup returned no records", "")
		return
	}

	result := map[string]interface{}{
		"host":      "google.com",
		"addresses": addresses,
	}
	httputil.JSONOK(w, result)
}

// GetPing handles GET /api/status/ping
func (h *StatusHandler) GetPing(w http.ResponseWriter, r *http.Request) {
	if !httputil.RequireGET(w, r) {
		return
	}

	ctx, cancel := context.WithTimeout(r.Context(), 3*time.Second)
	defer cancel()

	cmd := exec.CommandContext(ctx, "ping", "-c", "3", "-W", "1", "8.8.8.8")
	output, err := cmd.CombinedOutput()
	if ctx.Err() != nil {
		httputil.JSONError(w, http.StatusGatewayTimeout, "Ping timed out", "")
		return
	}

	outStr := string(output)
	packetLossRe := regexp.MustCompile(`(\d+)%\s*packet loss`)
	transmitRe := regexp.MustCompile(`(\d+)\s+packets transmitted,\s+(\d+)\s+received`)
	avgRe := regexp.MustCompile(`=\s*[\d.]+/([\d.]+)/[\d.]+/[\d.]+\s*ms`)

	result := map[string]interface{}{
		"target": "8.8.8.8",
	}

	if match := packetLossRe.FindStringSubmatch(outStr); len(match) > 1 {
		if loss, convErr := strconv.Atoi(match[1]); convErr == nil {
			result["loss_percent"] = loss
		}
	}
	if match := transmitRe.FindStringSubmatch(outStr); len(match) > 2 {
		if tx, convErr := strconv.Atoi(match[1]); convErr == nil {
			result["transmitted"] = tx
		}
		if rx, convErr := strconv.Atoi(match[2]); convErr == nil {
			result["received"] = rx
		}
	}
	if match := avgRe.FindStringSubmatch(outStr); len(match) > 1 {
		if avg, convErr := strconv.ParseFloat(match[1], 64); convErr == nil {
			result["avg_ms"] = avg
		}
	}

	if err != nil && len(result) == 1 {
		httputil.JSONError(w, http.StatusBadGateway, "Ping failed", err.Error())
		return
	}

	httputil.JSONOK(w, result)
}
