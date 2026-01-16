// Package logger provides a comprehensive logging system for the application
package logger

import (
	"fmt"
	"strings"
	"sync"
	"time"
)

// Level represents log severity levels
type Level int

const (
	DEBUG Level = iota
	INFO
	WARN
	ERROR
)

func (l Level) String() string {
	switch l {
	case DEBUG:
		return "DEBUG"
	case INFO:
		return "INFO"
	case WARN:
		return "WARN"
	case ERROR:
		return "ERROR"
	default:
		return "UNKNOWN"
	}
}

// Entry represents a single log entry
type Entry struct {
	Time      time.Time `json:"time"`
	Level     string    `json:"level"`
	Category  string    `json:"category"`  // e.g., "nmcli", "ssh", "api", "system"
	Action    string    `json:"action"`    // e.g., "execute", "connect", "scan"
	Command   string    `json:"command,omitempty"`   // The actual command executed
	Output    string    `json:"output,omitempty"`    // Command output (truncated if needed)
	Error     string    `json:"error,omitempty"`     // Error message if any
	Duration  int64     `json:"duration_ms,omitempty"` // Duration in milliseconds
	Success   bool      `json:"success"`
	ExitCode  int       `json:"exit_code,omitempty"`
	Extra     map[string]interface{} `json:"extra,omitempty"` // Additional context
}

// Settings holds logger configuration
type Settings struct {
	Enabled      bool `json:"enabled"`
	MaxEntries   int  `json:"max_entries"`
	LogCommands  bool `json:"log_commands"`  // Log full command strings
	LogOutput    bool `json:"log_output"`    // Log command output
	MaxOutputLen int  `json:"max_output_len"` // Max output length to store
	MinLevel     Level `json:"min_level"`
}

// Logger is the main logging service
type Logger struct {
	mu       sync.RWMutex
	entries  []Entry
	settings Settings
	
	// Callbacks for real-time log streaming (future SSE support)
	subscribers []chan Entry
	subMu       sync.RWMutex
}

// DefaultSettings returns sensible default settings
func DefaultSettings() Settings {
	return Settings{
		Enabled:      true,
		MaxEntries:   500,
		LogCommands:  true,
		LogOutput:    true,
		MaxOutputLen: 4096,
		MinLevel:     DEBUG,
	}
}

// New creates a new Logger instance
func New(settings Settings) *Logger {
	return &Logger{
		entries:     make([]Entry, 0, settings.MaxEntries),
		settings:    settings,
		subscribers: make([]chan Entry, 0),
	}
}

// NewDefault creates a logger with default settings
func NewDefault() *Logger {
	return New(DefaultSettings())
}

// Settings returns current logger settings
func (l *Logger) Settings() Settings {
	l.mu.RLock()
	defer l.mu.RUnlock()
	return l.settings
}

// SetEnabled enables or disables logging
func (l *Logger) SetEnabled(enabled bool) {
	l.mu.Lock()
	defer l.mu.Unlock()
	l.settings.Enabled = enabled
}

// IsEnabled returns whether logging is enabled
func (l *Logger) IsEnabled() bool {
	l.mu.RLock()
	defer l.mu.RUnlock()
	return l.settings.Enabled
}

// UpdateSettings updates logger settings
func (l *Logger) UpdateSettings(s Settings) {
	l.mu.Lock()
	defer l.mu.Unlock()
	l.settings = s
}

// Log adds a new log entry
func (l *Logger) Log(level Level, category, action string) *EntryBuilder {
	return &EntryBuilder{
		logger: l,
		entry: Entry{
			Time:     time.Now(),
			Level:    level.String(),
			Category: category,
			Action:   action,
			Success:  true,
		},
	}
}

// Debug logs at DEBUG level
func (l *Logger) Debug(category, action string) *EntryBuilder {
	return l.Log(DEBUG, category, action)
}

// Info logs at INFO level
func (l *Logger) Info(category, action string) *EntryBuilder {
	return l.Log(INFO, category, action)
}

// Warn logs at WARN level
func (l *Logger) Warn(category, action string) *EntryBuilder {
	return l.Log(WARN, category, action)
}

// Error logs at ERROR level
func (l *Logger) Error(category, action string) *EntryBuilder {
	return l.Log(ERROR, category, action)
}

// Command creates a log entry specifically for command execution
func (l *Logger) Command(category, cmd string, args []string) *EntryBuilder {
	fullCmd := cmd
	if len(args) > 0 {
		fullCmd = cmd + " " + strings.Join(args, " ")
	}
	
	return l.Log(DEBUG, category, "execute").WithCommand(fullCmd)
}

// addEntry adds an entry to the log (called by EntryBuilder.Commit)
func (l *Logger) addEntry(entry Entry) {
	l.mu.Lock()
	
	if !l.settings.Enabled {
		l.mu.Unlock()
		return
	}
	
	// Check minimum level
	entryLevel := levelFromString(entry.Level)
	if entryLevel < l.settings.MinLevel {
		l.mu.Unlock()
		return
	}
	
	// Truncate output if needed
	if !l.settings.LogOutput {
		entry.Output = ""
	} else if len(entry.Output) > l.settings.MaxOutputLen {
		entry.Output = entry.Output[:l.settings.MaxOutputLen] + "\n... [truncated]"
	}
	
	// Mask sensitive data in commands
	if l.settings.LogCommands {
		entry.Command = maskSensitive(entry.Command)
	} else {
		entry.Command = "[hidden]"
	}
	
	// Add entry
	l.entries = append(l.entries, entry)
	
	// Trim if over capacity
	if len(l.entries) > l.settings.MaxEntries {
		l.entries = l.entries[len(l.entries)-l.settings.MaxEntries:]
	}
	
	l.mu.Unlock()
	
	// Notify subscribers (non-blocking)
	l.subMu.RLock()
	for _, ch := range l.subscribers {
		select {
		case ch <- entry:
		default:
			// Channel full, skip
		}
	}
	l.subMu.RUnlock()
}

// GetEntries returns log entries with optional filtering
func (l *Logger) GetEntries(filter *Filter) []Entry {
	l.mu.RLock()
	defer l.mu.RUnlock()
	
	if filter == nil {
		// Return all (newest first)
		result := make([]Entry, len(l.entries))
		for i, e := range l.entries {
			result[len(l.entries)-1-i] = e
		}
		return result
	}
	
	var result []Entry
	for i := len(l.entries) - 1; i >= 0; i-- {
		e := l.entries[i]
		
		// Apply filters
		if filter.Category != "" && e.Category != filter.Category {
			continue
		}
		if filter.Level != "" && e.Level != filter.Level {
			continue
		}
		if filter.Success != nil && e.Success != *filter.Success {
			continue
		}
		if !filter.Since.IsZero() && e.Time.Before(filter.Since) {
			continue
		}
		if filter.Search != "" && !containsIgnoreCase(e, filter.Search) {
			continue
		}
		
		result = append(result, e)
		
		if filter.Limit > 0 && len(result) >= filter.Limit {
			break
		}
	}
	
	return result
}

// Clear removes all log entries
func (l *Logger) Clear() {
	l.mu.Lock()
	defer l.mu.Unlock()
	l.entries = make([]Entry, 0, l.settings.MaxEntries)
}

// Subscribe returns a channel for real-time log updates
func (l *Logger) Subscribe() chan Entry {
	ch := make(chan Entry, 100)
	l.subMu.Lock()
	l.subscribers = append(l.subscribers, ch)
	l.subMu.Unlock()
	return ch
}

// Unsubscribe removes a subscription
func (l *Logger) Unsubscribe(ch chan Entry) {
	l.subMu.Lock()
	defer l.subMu.Unlock()
	
	for i, sub := range l.subscribers {
		if sub == ch {
			l.subscribers = append(l.subscribers[:i], l.subscribers[i+1:]...)
			close(ch)
			break
		}
	}
}

// Filter defines filtering options for log retrieval
type Filter struct {
	Category string     `json:"category,omitempty"`
	Level    string     `json:"level,omitempty"`
	Success  *bool      `json:"success,omitempty"`
	Since    time.Time  `json:"since,omitempty"`
	Limit    int        `json:"limit,omitempty"`
	Search   string     `json:"search,omitempty"`
}

// EntryBuilder provides a fluent interface for building log entries
type EntryBuilder struct {
	logger *Logger
	entry  Entry
	start  time.Time
}

// WithCommand sets the command string
func (b *EntryBuilder) WithCommand(cmd string) *EntryBuilder {
	b.entry.Command = cmd
	b.start = time.Now()
	return b
}

// WithOutput sets the command output
func (b *EntryBuilder) WithOutput(output string) *EntryBuilder {
	b.entry.Output = output
	return b
}

// WithError sets an error
func (b *EntryBuilder) WithError(err error) *EntryBuilder {
	if err != nil {
		b.entry.Error = err.Error()
		b.entry.Success = false
	}
	return b
}

// WithErrorStr sets an error string
func (b *EntryBuilder) WithErrorStr(errStr string) *EntryBuilder {
	if errStr != "" {
		b.entry.Error = errStr
		b.entry.Success = false
	}
	return b
}

// WithSuccess sets the success status
func (b *EntryBuilder) WithSuccess(success bool) *EntryBuilder {
	b.entry.Success = success
	return b
}

// WithExitCode sets the exit code
func (b *EntryBuilder) WithExitCode(code int) *EntryBuilder {
	b.entry.ExitCode = code
	return b
}

// WithExtra adds extra context
func (b *EntryBuilder) WithExtra(key string, value interface{}) *EntryBuilder {
	if b.entry.Extra == nil {
		b.entry.Extra = make(map[string]interface{})
	}
	b.entry.Extra[key] = value
	return b
}

// WithDuration sets the duration
func (b *EntryBuilder) WithDuration(d time.Duration) *EntryBuilder {
	b.entry.Duration = d.Milliseconds()
	return b
}

// Commit finalizes and stores the log entry
func (b *EntryBuilder) Commit() {
	// Calculate duration if start was set
	if !b.start.IsZero() {
		b.entry.Duration = time.Since(b.start).Milliseconds()
	}
	b.logger.addEntry(b.entry)
}

// --- Helper functions ---

func levelFromString(s string) Level {
	switch s {
	case "DEBUG":
		return DEBUG
	case "INFO":
		return INFO
	case "WARN":
		return WARN
	case "ERROR":
		return ERROR
	default:
		return DEBUG
	}
}

// maskSensitive masks passwords and other sensitive data in commands
func maskSensitive(cmd string) string {
	// Mask password arguments
	sensitivePatterns := []string{
		"password",
		"secret",
		"key",
		"token",
		"pass",
	}
	
	words := strings.Fields(cmd)
	for i, word := range words {
		lower := strings.ToLower(word)
		for _, pattern := range sensitivePatterns {
			if strings.Contains(lower, pattern) && i+1 < len(words) {
				// Mask the next word (the actual value)
				words[i+1] = "****"
			}
		}
	}
	
	return strings.Join(words, " ")
}

func containsIgnoreCase(e Entry, search string) bool {
	search = strings.ToLower(search)
	return strings.Contains(strings.ToLower(e.Category), search) ||
		strings.Contains(strings.ToLower(e.Action), search) ||
		strings.Contains(strings.ToLower(e.Command), search) ||
		strings.Contains(strings.ToLower(e.Output), search) ||
		strings.Contains(strings.ToLower(e.Error), search)
}

// Stats returns logging statistics
func (l *Logger) Stats() map[string]interface{} {
	l.mu.RLock()
	defer l.mu.RUnlock()
	
	stats := map[string]interface{}{
		"total_entries": len(l.entries),
		"max_entries":   l.settings.MaxEntries,
		"enabled":       l.settings.Enabled,
	}
	
	// Count by category
	categories := make(map[string]int)
	errors := 0
	for _, e := range l.entries {
		categories[e.Category]++
		if !e.Success {
			errors++
		}
	}
	stats["by_category"] = categories
	stats["errors"] = errors
	
	return stats
}

// Msg is a simple helper for quick logging
func (l *Logger) Msg(level Level, category, format string, args ...interface{}) {
	l.Log(level, category, fmt.Sprintf(format, args...)).Commit()
}
