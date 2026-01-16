package ssh

import (
	"encoding/json"
	"fmt"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"

	"nm-webui/internal/logger"
	"nm-webui/internal/types"
)

// TunnelManager handles SSH tunnel operations
type TunnelManager struct {
	dataDir    string
	keyManager *KeyManager
	logger     *logger.Logger
	mu         sync.RWMutex
	tunnels    map[string]*types.SSHTunnel
}

// NewTunnelManager creates a new tunnel manager
func NewTunnelManager(dataDir string, km *KeyManager, log *logger.Logger) *TunnelManager {
	os.MkdirAll(dataDir, 0750)

	tm := &TunnelManager{
		dataDir:    dataDir,
		keyManager: km,
		logger:     log,
		tunnels:    make(map[string]*types.SSHTunnel),
	}

	// Load existing tunnels from storage
	tm.loadRegistry()

	return tm
}

// registryPath returns the path to the tunnels JSON file
func (tm *TunnelManager) registryPath() string {
	return filepath.Join(tm.dataDir, "tunnels.json")
}

// loadRegistry loads tunnel configurations from disk
func (tm *TunnelManager) loadRegistry() {
	data, err := os.ReadFile(tm.registryPath())
	if err != nil {
		if !os.IsNotExist(err) {
			tm.logger.Error("ssh", "load_registry").
				WithError(err).
				Commit()
		}
		return
	}

	var tunnels map[string]*types.SSHTunnel
	if err := json.Unmarshal(data, &tunnels); err != nil {
		tm.logger.Error("ssh", "load_registry").
			WithError(err).
			Commit()
		return
	}

	tm.tunnels = tunnels
	tm.logger.Info("ssh", "load_registry").
		WithExtra("count", len(tunnels)).
		Commit()
}

// saveRegistry saves tunnel configurations to disk
func (tm *TunnelManager) saveRegistry() error {
	data, err := json.MarshalIndent(tm.tunnels, "", "  ")
	if err != nil {
		return err
	}
	return os.WriteFile(tm.registryPath(), data, 0600)
}

// List returns all tunnels with updated status
func (tm *TunnelManager) List() []types.SSHTunnel {
	tm.mu.Lock()
	defer tm.mu.Unlock()

	tm.logger.Debug("ssh", "list_tunnels").Commit()

	// Update status based on actual process state
	for id, tunnel := range tm.tunnels {
		if tunnel.PID > 0 && tm.isPIDAlive(tunnel.PID) {
			tunnel.Status = "running"
		} else {
			tunnel.Status = "stopped"
			tunnel.PID = 0
		}
		tunnel.ID = id
	}

	// Save updated status
	tm.saveRegistry()

	// Convert map to slice
	result := make([]types.SSHTunnel, 0, len(tm.tunnels))
	for _, tunnel := range tm.tunnels {
		result = append(result, *tunnel)
	}

	tm.logger.Debug("ssh", "list_tunnels").
		WithExtra("count", len(result)).
		Commit()
	return result
}

// Create creates and starts a new tunnel
func (tm *TunnelManager) Create(req types.SSHTunnelCreateRequest) (*types.SSHTunnel, error) {
	// Validate inputs
	if err := tm.validateTunnelRequest(req); err != nil {
		return nil, err
	}

	tm.mu.Lock()
	defer tm.mu.Unlock()

	// Start the SSH process
	pid, err := tm.spawnSSH(req)
	if err != nil {
		return nil, err
	}

	// Generate unique ID and create tunnel record
	tunnelID := generateID()
	tunnel := &types.SSHTunnel{
		ID:       tunnelID,
		Status:   "running",
		PID:      pid,
		Host:     req.Host,
		Port:     req.Port,
		User:     req.User,
		AuthType: req.AuthType,
		KeyFile:  req.KeyFile,
		FwdType:  req.FwdType,
		LPort:    req.LPort,
		RHost:    req.RHost,
		RPort:    req.RPort,
		Since:    time.Now().Unix(),
	}

	tm.tunnels[tunnelID] = tunnel
	if err := tm.saveRegistry(); err != nil {
		tm.logger.Warn("ssh", "save_registry").
			WithError(err).
			Commit()
	}

	tm.logger.Info("ssh", "create_tunnel").
		WithExtra("id", tunnelID).
		WithExtra("pid", pid).
		WithExtra("host", req.User+"@"+req.Host).
		WithExtra("type", req.FwdType).
		Commit()

	return tunnel, nil
}

// Start starts an existing stopped tunnel
func (tm *TunnelManager) Start(tunnelID string) error {
	tm.mu.Lock()
	defer tm.mu.Unlock()

	tunnel, exists := tm.tunnels[tunnelID]
	if !exists {
		return fmt.Errorf("tunnel not found")
	}

	if tunnel.Status == "running" && tunnel.PID > 0 && tm.isPIDAlive(tunnel.PID) {
		return fmt.Errorf("tunnel already running")
	}

	// Reconstruct request from tunnel config
	req := types.SSHTunnelCreateRequest{
		Host:     tunnel.Host,
		Port:     tunnel.Port,
		User:     tunnel.User,
		AuthType: tunnel.AuthType,
		KeyFile:  tunnel.KeyFile,
		FwdType:  tunnel.FwdType,
		LPort:    tunnel.LPort,
		RHost:    tunnel.RHost,
		RPort:    tunnel.RPort,
	}

	pid, err := tm.spawnSSH(req)
	if err != nil {
		return err
	}

	tunnel.PID = pid
	tunnel.Status = "running"
	tunnel.Since = time.Now().Unix()
	tm.saveRegistry()

	tm.logger.Info("ssh", "start_tunnel").
		WithExtra("id", tunnelID).
		WithExtra("pid", pid).
		Commit()

	return nil
}

// Stop stops a running tunnel
func (tm *TunnelManager) Stop(tunnelID string) error {
	tm.mu.Lock()
	defer tm.mu.Unlock()

	tunnel, exists := tm.tunnels[tunnelID]
	if !exists {
		return fmt.Errorf("tunnel not found")
	}

	if tunnel.PID > 0 {
		tm.logger.Info("ssh", "stop_tunnel").
			WithExtra("id", tunnelID).
			WithExtra("pid", tunnel.PID).
			Commit()

		if err := tm.killPID(tunnel.PID); err != nil {
			tm.logger.Warn("ssh", "stop_tunnel_kill").
				WithExtra("pid", tunnel.PID).
				WithError(err).
				Commit()
		}
	}

	tunnel.Status = "stopped"
	tunnel.PID = 0
	tm.saveRegistry()

	tm.logger.Info("ssh", "stop_tunnel_success").
		WithExtra("id", tunnelID).
		Commit()

	return nil
}

// Delete stops and removes a tunnel configuration
func (tm *TunnelManager) Delete(tunnelID string) error {
	tm.mu.Lock()
	defer tm.mu.Unlock()

	tunnel, exists := tm.tunnels[tunnelID]
	if !exists {
		return fmt.Errorf("tunnel not found")
	}

	// Stop if running
	if tunnel.PID > 0 {
		tm.killPID(tunnel.PID)
	}

	delete(tm.tunnels, tunnelID)
	tm.saveRegistry()

	tm.logger.Info("ssh", "delete_tunnel").
		WithExtra("id", tunnelID).
		Commit()

	return nil
}

// validateTunnelRequest validates the tunnel creation request
func (tm *TunnelManager) validateTunnelRequest(req types.SSHTunnelCreateRequest) error {
	// Validate host
	if req.Host == "" {
		return fmt.Errorf("host is required")
	}
	if !isValidHostname(req.Host) && net.ParseIP(req.Host) == nil {
		return fmt.Errorf("invalid host")
	}

	// Validate user
	if req.User == "" {
		return fmt.Errorf("user is required")
	}
	if !regexp.MustCompile(`^[a-zA-Z0-9._-]+$`).MatchString(req.User) {
		return fmt.Errorf("invalid SSH user")
	}

	// Validate port
	if req.Port <= 0 {
		req.Port = 22
	}
	if req.Port > 65535 {
		return fmt.Errorf("invalid SSH port")
	}

	// Validate forward type
	if req.FwdType != "L" && req.FwdType != "R" && req.FwdType != "D" {
		return fmt.Errorf("invalid forward type: must be L, R, or D")
	}

	// Validate local port
	if req.LPort <= 0 || req.LPort > 65535 {
		return fmt.Errorf("invalid local port")
	}

	// Validate target for L and R forwarding
	if req.FwdType != "D" {
		if req.RPort <= 0 || req.RPort > 65535 {
			return fmt.Errorf("invalid target port")
		}
		if req.RHost == "" {
			req.RHost = "127.0.0.1"
		}
		if !isValidHostname(req.RHost) && net.ParseIP(req.RHost) == nil {
			return fmt.Errorf("invalid target host")
		}
	}

	// Validate auth
	if req.AuthType == "key" {
		if req.KeyFile == "" {
			return fmt.Errorf("key file is required for key auth")
		}
		if !tm.keyManager.KeyExists(req.KeyFile) {
			return fmt.Errorf("key file not found")
		}
	} else if req.AuthType == "password" {
		if req.Password == "" {
			return fmt.Errorf("password is required for password auth")
		}
	} else {
		return fmt.Errorf("invalid auth type: must be key or password")
	}

	return nil
}

// spawnSSH spawns an SSH process and returns its PID
func (tm *TunnelManager) spawnSSH(req types.SSHTunnelCreateRequest) (int, error) {
	// Build port forwarding flags
	var fwdFlag string
	switch req.FwdType {
	case "L":
		fwdFlag = fmt.Sprintf("-L 0.0.0.0:%d:%s:%d", req.LPort, req.RHost, req.RPort)
	case "R":
		fwdFlag = fmt.Sprintf("-R 0.0.0.0:%d:%s:%d", req.RPort, req.RHost, req.LPort)
	case "D":
		fwdFlag = fmt.Sprintf("-D 0.0.0.0:%d", req.LPort)
	}

	// Build SSH command
	var cmd *exec.Cmd
	var cmdStr string

	if req.AuthType == "key" {
		keyPath := tm.keyManager.GetKeyPath(req.KeyFile)
		args := []string{
			"-N", "-T",
			"-o", "ExitOnForwardFailure=yes",
			"-o", "StrictHostKeyChecking=no",
			"-o", "ServerAliveInterval=60",
			"-o", "ServerAliveCountMax=3",
			"-i", keyPath,
			"-p", strconv.Itoa(req.Port),
		}
		// Add forwarding flag (split by space since it contains multiple parts)
		args = append(args, strings.Fields(fwdFlag)...)
		args = append(args, fmt.Sprintf("%s@%s", req.User, req.Host))

		cmd = exec.Command("ssh", args...)
		cmdStr = fmt.Sprintf("ssh %s", strings.Join(args, " "))
	} else {
		// Use sshpass for password auth
		args := []string{
			"-p", req.Password,
			"ssh",
			"-o", "StrictHostKeyChecking=no",
			"-N", "-T",
			"-o", "ExitOnForwardFailure=yes",
			"-o", "ServerAliveInterval=60",
			"-o", "ServerAliveCountMax=3",
			"-p", strconv.Itoa(req.Port),
		}
		args = append(args, strings.Fields(fwdFlag)...)
		args = append(args, fmt.Sprintf("%s@%s", req.User, req.Host))

		cmd = exec.Command("sshpass", args...)
		cmdStr = fmt.Sprintf("sshpass -p <masked> ssh %s", strings.Join(args[3:], " "))
	}

	// Start process in background
	cmd.SysProcAttr = &syscall.SysProcAttr{
		Setpgid: true,
	}
	cmd.Stdout = nil
	cmd.Stderr = nil

	start := time.Now()
	err := cmd.Start()
	duration := time.Since(start)

	if err != nil {
		tm.logger.Error("ssh", "spawn_ssh").
			WithCommand(cmdStr).
			WithError(err).
			WithDuration(duration).
			Commit()
		return 0, fmt.Errorf("failed to spawn SSH: %v", err)
	}

	pid := cmd.Process.Pid
	tm.logger.Debug("ssh", "spawn_ssh").
		WithCommand(cmdStr).
		WithExtra("pid", pid).
		WithDuration(duration).
		Commit()

	// Wait a moment to check if it failed immediately
	time.Sleep(500 * time.Millisecond)
	if !tm.isPIDAlive(pid) {
		return 0, fmt.Errorf("SSH process exited immediately - check credentials and connectivity")
	}

	// Detach from the process so it continues running after we return
	go cmd.Wait()

	return pid, nil
}

// isPIDAlive checks if a process is still running
func (tm *TunnelManager) isPIDAlive(pid int) bool {
	process, err := os.FindProcess(pid)
	if err != nil {
		return false
	}
	// Signal 0 doesn't send a signal but checks if process exists
	err = process.Signal(syscall.Signal(0))
	return err == nil
}

// killPID terminates a process
func (tm *TunnelManager) killPID(pid int) error {
	process, err := os.FindProcess(pid)
	if err != nil {
		return err
	}
	return process.Signal(syscall.SIGTERM)
}

// isValidHostname validates a hostname string
func isValidHostname(h string) bool {
	if len(h) > 253 {
		return false
	}
	// Simple hostname validation
	return regexp.MustCompile(`^[a-zA-Z0-9]([a-zA-Z0-9\-\.]*[a-zA-Z0-9])?$`).MatchString(h)
}

// generateID generates a unique tunnel ID
func generateID() string {
	return fmt.Sprintf("%d", time.Now().UnixNano())
}
