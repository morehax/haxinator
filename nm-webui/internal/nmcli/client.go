// Package nmcli provides a wrapper around the nmcli command-line tool
package nmcli

import (
	"os/exec"
	"regexp"
	"strings"
	"time"

	"nm-webui/internal/logger"
)

// Client wraps nmcli operations
type Client struct {
	nmcliBin string
	log      *logger.Logger
}

// New creates a new nmcli client
func New() *Client {
	return &Client{nmcliBin: "nmcli", log: nil}
}

// NewWithLogger creates a new nmcli client with logging
func NewWithLogger(log *logger.Logger) *Client {
	return &Client{nmcliBin: "nmcli", log: log}
}

// SetLogger sets the logger for the client
func (c *Client) SetLogger(log *logger.Logger) {
	c.log = log
}

// run executes nmcli with the given arguments and returns output
func (c *Client) run(args ...string) (string, error) {
	start := time.Now()
	cmd := exec.Command(c.nmcliBin, args...)
	output, err := cmd.CombinedOutput()
	duration := time.Since(start)
	
	// Log the command execution
	if c.log != nil {
		exitCode := 0
		if err != nil {
			if exitErr, ok := err.(*exec.ExitError); ok {
				exitCode = exitErr.ExitCode()
			} else {
				exitCode = -1
			}
		}
		
		c.log.Command("nmcli", c.nmcliBin, args).
			WithOutput(string(output)).
			WithError(err).
			WithExitCode(exitCode).
			WithDuration(duration).
			WithSuccess(err == nil).
			Commit()
	}
	
	return string(output), err
}

// runTerse executes nmcli in terse mode
func (c *Client) runTerse(fields string, args ...string) (string, error) {
	fullArgs := []string{"-t"}
	if fields != "" {
		fullArgs = append(fullArgs, "-f", fields)
	}
	fullArgs = append(fullArgs, args...)
	return c.run(fullArgs...)
}

// --- Utility functions ---

// parseEscapedLine parses a line with escaped colons (nmcli --escape output)
func parseEscapedLine(line string) []string {
	var parts []string
	var current strings.Builder
	escaped := false

	for _, r := range line {
		if escaped {
			current.WriteRune(r)
			escaped = false
		} else if r == '\\' {
			escaped = true
		} else if r == ':' {
			parts = append(parts, current.String())
			current.Reset()
		} else {
			current.WriteRune(r)
		}
	}
	parts = append(parts, current.String())
	return parts
}

// extractNumber extracts the first number from a string
func extractNumber(s string) string {
	re := regexp.MustCompile(`\d+`)
	return re.FindString(s)
}

// contains checks if a slice contains a string
func contains(slice []string, item string) bool {
	for _, s := range slice {
		if s == item {
			return true
		}
	}
	return false
}

// isValidUUID validates a UUID format
func isValidUUID(uuid string) bool {
	matched, _ := regexp.MatchString(`^[0-9a-fA-F\-]{36}$`, uuid)
	return matched
}

// isValidCIDR validates a CIDR notation
func isValidCIDR(cidr string) bool {
	matched, _ := regexp.MatchString(`^(\d{1,3}\.){3}\d{1,3}/\d{1,2}$`, cidr)
	return matched
}

// prettyMessage converts nmcli output to user-friendly messages
func prettyMessage(msg string) string {
	switch {
	case strings.Contains(msg, "No network with SSID"):
		return "Network not found"
	case strings.Contains(msg, "Secrets were required"):
		return "Wrong / missing password"
	case strings.Contains(msg, "Connection activation failed"):
		return "Could not obtain IP"
	default:
		return msg
	}
}
