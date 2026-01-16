// Package services provides background services and process management
package services

import (
	"context"
	"io"
	"os/exec"
	"sync"
	"syscall"
)

// Process represents a managed background process
type Process struct {
	Name    string
	PID     int
	Cmd     *exec.Cmd
	cancel  context.CancelFunc
	done    chan struct{}
}

// ProcessManager manages background processes
type ProcessManager struct {
	mu        sync.RWMutex
	processes map[string]*Process
}

// NewProcessManager creates a new process manager
func NewProcessManager() *ProcessManager {
	return &ProcessManager{
		processes: make(map[string]*Process),
	}
}

// Start starts a new background process
func (pm *ProcessManager) Start(name string, cmdName string, args ...string) (*Process, error) {
	pm.mu.Lock()
	defer pm.mu.Unlock()

	// Stop existing process with same name
	if existing, ok := pm.processes[name]; ok {
		existing.Stop()
	}

	ctx, cancel := context.WithCancel(context.Background())
	cmd := exec.CommandContext(ctx, cmdName, args...)
	
	// Set process group so we can kill all children
	cmd.SysProcAttr = &syscall.SysProcAttr{Setpgid: true}

	if err := cmd.Start(); err != nil {
		cancel()
		return nil, err
	}

	proc := &Process{
		Name:   name,
		PID:    cmd.Process.Pid,
		Cmd:    cmd,
		cancel: cancel,
		done:   make(chan struct{}),
	}

	// Wait for process to finish
	go func() {
		cmd.Wait()
		close(proc.done)
	}()

	pm.processes[name] = proc
	return proc, nil
}

// StartWithPipes starts a process and returns stdout/stderr readers
func (pm *ProcessManager) StartWithPipes(name string, cmdName string, args ...string) (*Process, io.ReadCloser, io.ReadCloser, error) {
	pm.mu.Lock()
	defer pm.mu.Unlock()

	if existing, ok := pm.processes[name]; ok {
		existing.Stop()
	}

	ctx, cancel := context.WithCancel(context.Background())
	cmd := exec.CommandContext(ctx, cmdName, args...)
	cmd.SysProcAttr = &syscall.SysProcAttr{Setpgid: true}

	stdout, err := cmd.StdoutPipe()
	if err != nil {
		cancel()
		return nil, nil, nil, err
	}

	stderr, err := cmd.StderrPipe()
	if err != nil {
		cancel()
		return nil, nil, nil, err
	}

	if err := cmd.Start(); err != nil {
		cancel()
		return nil, nil, nil, err
	}

	proc := &Process{
		Name:   name,
		PID:    cmd.Process.Pid,
		Cmd:    cmd,
		cancel: cancel,
		done:   make(chan struct{}),
	}

	go func() {
		cmd.Wait()
		close(proc.done)
	}()

	pm.processes[name] = proc
	return proc, stdout, stderr, nil
}

// Stop stops a process by name
func (pm *ProcessManager) Stop(name string) bool {
	pm.mu.Lock()
	proc, ok := pm.processes[name]
	if ok {
		delete(pm.processes, name)
	}
	pm.mu.Unlock()

	if ok {
		proc.Stop()
		return true
	}
	return false
}

// Get returns a process by name
func (pm *ProcessManager) Get(name string) *Process {
	pm.mu.RLock()
	defer pm.mu.RUnlock()
	return pm.processes[name]
}

// List returns all process names
func (pm *ProcessManager) List() []string {
	pm.mu.RLock()
	defer pm.mu.RUnlock()

	names := make([]string, 0, len(pm.processes))
	for name := range pm.processes {
		names = append(names, name)
	}
	return names
}

// IsRunning checks if a process is running
func (pm *ProcessManager) IsRunning(name string) bool {
	pm.mu.RLock()
	proc, ok := pm.processes[name]
	pm.mu.RUnlock()

	if !ok {
		return false
	}

	select {
	case <-proc.done:
		return false
	default:
		return true
	}
}

// Stop stops the process
func (p *Process) Stop() {
	if p.cancel != nil {
		p.cancel()
	}
	
	// Kill process group
	if p.Cmd != nil && p.Cmd.Process != nil {
		syscall.Kill(-p.Cmd.Process.Pid, syscall.SIGKILL)
	}
}

// Wait waits for the process to finish
func (p *Process) Wait() {
	<-p.done
}

// Done returns a channel that closes when the process finishes
func (p *Process) Done() <-chan struct{} {
	return p.done
}
