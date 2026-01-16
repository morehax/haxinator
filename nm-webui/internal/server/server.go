package server

import (
	"embed"
	"io/fs"
	"log"
	"net/http"
	"sync"
	"time"

	"nm-webui/internal/handlers"
	"nm-webui/internal/logger"
	"nm-webui/internal/nmcli"
	"nm-webui/internal/ssh"
	"nm-webui/internal/types"
)

// Config holds server configuration
type Config struct {
	Listen   string
	Username string
	Password string
}

// Server is the main HTTP server
type Server struct {
	config     *Config
	mux        *http.ServeMux
	middleware *Middleware
	nmcli      *nmcli.Client
	logger     *logger.Logger
	
	// SSH managers
	sshKeyMgr    *ssh.KeyManager
	sshTunnelMgr *ssh.TunnelManager
	
	// Activity log (legacy - kept for backwards compatibility with status handler)
	logMu   sync.RWMutex
	logs    []types.LogEntry
	maxLogs int
}

// Data paths
const (
	sshKeyDir     = "/var/lib/nm-webui/ssh"
	sshDataDir    = "/var/lib/nm-webui/data"
	configDataDir = "/etc/haxinator"
)

// New creates a new server instance
func New(cfg *Config, staticFS embed.FS) (*Server, error) {
	// Create the central logger
	appLogger := logger.NewDefault()
	
	// Create nmcli client with logger
	nmcliClient := nmcli.NewWithLogger(appLogger)

	// Create SSH managers
	sshKeyMgr := ssh.NewKeyManager(sshKeyDir, appLogger)
	sshTunnelMgr := ssh.NewTunnelManager(sshDataDir, sshKeyMgr, appLogger)

	// Create middleware
	mw := NewMiddleware(cfg.Username, cfg.Password)

	s := &Server{
		config:       cfg,
		mux:          http.NewServeMux(),
		middleware:   mw,
		nmcli:        nmcliClient,
		logger:       appLogger,
		sshKeyMgr:    sshKeyMgr,
		sshTunnelMgr: sshTunnelMgr,
		logs:         make([]types.LogEntry, 0, 100),
		maxLogs:      100,
	}

	// Setup routes
	s.setupRoutes(staticFS)
	
	// Log startup
	appLogger.Info("system", "startup").
		WithExtra("listen", cfg.Listen).
		Commit()

	return s, nil
}

// Logger returns the server's logger instance (for external use)
func (s *Server) Logger() *logger.Logger {
	return s.logger
}

// setupRoutes configures all HTTP routes
func (s *Server) setupRoutes(staticFS embed.FS) {
	// Create handlers
	wifiHandler := handlers.NewWifiHandler(s.nmcli, s.AddLog)
	connHandler := handlers.NewConnectionsHandler(s.nmcli, s.AddLog)
	statusHandler := handlers.NewStatusHandler(s.nmcli, s.GetLogs)
	networkHandler := handlers.NewNetworkHandler(s.nmcli, s.AddLog)
	logsHandler := handlers.NewLogsHandler(s.logger)
	sshHandler := handlers.NewSSHHandler(s.sshKeyMgr, s.sshTunnelMgr, s.AddLog)
	systemHandler := handlers.NewSystemHandler(s.logger)

	// API routes - Status
	s.mux.HandleFunc("/api/status", s.middleware.Auth(statusHandler.GetStatus))
	s.mux.HandleFunc("/api/log", s.middleware.Auth(statusHandler.GetLogs))
	s.mux.HandleFunc("/api/status/external-ip", s.middleware.Auth(statusHandler.GetExternalIP))
	s.mux.HandleFunc("/api/status/dns", s.middleware.Auth(statusHandler.GetDNSLookup))
	s.mux.HandleFunc("/api/status/ping", s.middleware.Auth(statusHandler.GetPing))

	// API routes - System
	s.mux.HandleFunc("/api/system/shutdown", s.middleware.Auth(systemHandler.Shutdown))
	s.mux.HandleFunc("/api/system/reboot", s.middleware.Auth(systemHandler.Reboot))

	// API routes - WiFi
	s.mux.HandleFunc("/api/wifi/scan", s.middleware.Auth(wifiHandler.Scan))
	s.mux.HandleFunc("/api/wifi/connect", s.middleware.Auth(wifiHandler.Connect))
	s.mux.HandleFunc("/api/wifi/disconnect", s.middleware.Auth(wifiHandler.Disconnect))
	s.mux.HandleFunc("/api/wifi/forget", s.middleware.Auth(wifiHandler.Forget))
	s.mux.HandleFunc("/api/wifi/priority", s.middleware.Auth(wifiHandler.SetPriority))
	s.mux.HandleFunc("/api/wifi/hotspot", s.middleware.Auth(wifiHandler.Hotspot))

	// API routes - Connections
	s.mux.HandleFunc("/api/connections", s.middleware.Auth(connHandler.List))
	s.mux.HandleFunc("/api/connections/activate", s.middleware.Auth(connHandler.Activate))
	s.mux.HandleFunc("/api/connections/deactivate", s.middleware.Auth(connHandler.Deactivate))
	s.mux.HandleFunc("/api/connections/delete/", s.middleware.Auth(connHandler.Delete))
	s.mux.HandleFunc("/api/connections/share", s.middleware.Auth(connHandler.Share))

	// API routes - Network
	s.mux.HandleFunc("/api/network/interfaces", s.middleware.Auth(networkHandler.ListInterfaces))
	s.mux.HandleFunc("/api/network/share", s.middleware.Auth(networkHandler.ToggleSharing))

	// API routes - SSH Keys
	s.mux.HandleFunc("/api/ssh/keys", s.middleware.Auth(sshHandler.ListKeys))
	s.mux.HandleFunc("/api/ssh/keys/upload", s.middleware.Auth(sshHandler.UploadKey))
	s.mux.HandleFunc("/api/ssh/keys/delete", s.middleware.Auth(sshHandler.DeleteKey))
	s.mux.HandleFunc("/api/ssh/keys/generate", s.middleware.Auth(sshHandler.GenerateKey))
	s.mux.HandleFunc("/api/ssh/keys/public", s.middleware.Auth(sshHandler.GetPublicKey))
	s.mux.HandleFunc("/api/ssh/keys/download", s.middleware.Auth(sshHandler.DownloadPublicKey))

	// API routes - SSH Tunnels
	s.mux.HandleFunc("/api/ssh/tunnels", s.middleware.Auth(sshHandler.ListTunnels))
	s.mux.HandleFunc("/api/ssh/tunnels/create", s.middleware.Auth(sshHandler.CreateTunnel))
	s.mux.HandleFunc("/api/ssh/tunnels/start", s.middleware.Auth(sshHandler.StartTunnel))
	s.mux.HandleFunc("/api/ssh/tunnels/stop", s.middleware.Auth(sshHandler.StopTunnel))
	s.mux.HandleFunc("/api/ssh/tunnels/delete", s.middleware.Auth(sshHandler.DeleteTunnel))

	// API routes - Logs (new comprehensive logging)
	s.mux.HandleFunc("/api/logs", s.middleware.Auth(logsHandler.GetLogs))
	s.mux.HandleFunc("/api/logs/settings", s.middleware.Auth(func(w http.ResponseWriter, r *http.Request) {
		if r.Method == http.MethodGet {
			logsHandler.GetSettings(w, r)
		} else {
			logsHandler.UpdateSettings(w, r)
		}
	}))
	s.mux.HandleFunc("/api/logs/toggle", s.middleware.Auth(logsHandler.Toggle))
	s.mux.HandleFunc("/api/logs/clear", s.middleware.Auth(logsHandler.Clear))
	s.mux.HandleFunc("/api/logs/stats", s.middleware.Auth(logsHandler.Stats))

	// API routes - Configure
	configHandler := handlers.NewConfigureHandler(configDataDir, s.AddLogWithCategory)
	s.mux.HandleFunc("/api/configure/files", s.middleware.Auth(configHandler.GetFileStatus))
	s.mux.HandleFunc("/api/configure/view", s.middleware.Auth(configHandler.ViewFile))
	s.mux.HandleFunc("/api/configure/upload", s.middleware.Auth(configHandler.UploadFile))
	s.mux.HandleFunc("/api/configure/delete", s.middleware.Auth(configHandler.DeleteFile))
	s.mux.HandleFunc("/api/configure/networks", s.middleware.Auth(configHandler.GetNetworkConfigs))
	s.mux.HandleFunc("/api/configure/apply", s.middleware.Auth(configHandler.ApplyNetworkConfig))

	// Static files
	staticSubFS, err := fs.Sub(staticFS, "static")
	if err != nil {
		log.Fatalf("Failed to setup static files: %v", err)
	}
	s.mux.Handle("/", http.FileServer(http.FS(staticSubFS)))
}

// Handler returns the HTTP handler
func (s *Server) Handler() http.Handler {
	return s.mux
}

// AddLog adds an entry to the activity log (legacy method)
func (s *Server) AddLog(action, detail string, success bool) {
	s.logMu.Lock()
	defer s.logMu.Unlock()

	entry := types.LogEntry{
		Time:    time.Now().Format(time.RFC3339),
		Action:  action,
		Detail:  detail,
		Success: success,
	}

	s.logs = append(s.logs, entry)
	if len(s.logs) > s.maxLogs {
		s.logs = s.logs[1:]
	}

	// Also log to the new logger
	lvl := logger.INFO
	if !success {
		lvl = logger.ERROR
	}
	s.logger.Log(lvl, "action", action).
		WithExtra("detail", detail).
		WithSuccess(success).
		Commit()

	log.Printf("[%s] %s: %s (success=%v)", entry.Time, action, detail, success)
}

// GetLogs returns a copy of the activity log (newest first) - legacy method
func (s *Server) GetLogs() []types.LogEntry {
	s.logMu.RLock()
	defer s.logMu.RUnlock()

	logs := make([]types.LogEntry, len(s.logs))
	copy(logs, s.logs)

	// Reverse for newest first
	for i, j := 0, len(logs)-1; i < j; i, j = i+1, j-1 {
		logs[i], logs[j] = logs[j], logs[i]
	}

	return logs
}

// AddLogWithCategory adds an entry to the activity log with a category
func (s *Server) AddLogWithCategory(category, action, detail string, success bool) {
	s.logMu.Lock()
	defer s.logMu.Unlock()

	entry := types.LogEntry{
		Time:    time.Now().Format(time.RFC3339),
		Action:  category + ":" + action,
		Detail:  detail,
		Success: success,
	}

	s.logs = append(s.logs, entry)
	if len(s.logs) > s.maxLogs {
		s.logs = s.logs[1:]
	}

	// Also log to the new logger
	lvl := logger.INFO
	if !success {
		lvl = logger.ERROR
	}
	s.logger.Log(lvl, category, action).
		WithExtra("detail", detail).
		WithSuccess(success).
		Commit()

	log.Printf("[%s] %s:%s: %s (success=%v)", entry.Time, category, action, detail, success)
}
