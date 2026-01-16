// Package ssh provides SSH key and tunnel management
package ssh

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strings"
	"time"

	"nm-webui/internal/logger"
	"nm-webui/internal/types"
)

// KeyManager handles SSH key operations
type KeyManager struct {
	sshDir string
	logger *logger.Logger
}

// NewKeyManager creates a new key manager
func NewKeyManager(sshDir string, log *logger.Logger) *KeyManager {
	// Ensure directory exists with proper permissions
	os.MkdirAll(sshDir, 0700)
	return &KeyManager{sshDir: sshDir, logger: log}
}

// List returns all SSH private keys in the directory
func (km *KeyManager) List() ([]types.SSHKey, error) {
	km.logger.Info("ssh", "list_keys").
		WithExtra("dir", km.sshDir).
		Commit()

	entries, err := os.ReadDir(km.sshDir)
	if err != nil {
		if os.IsNotExist(err) {
			return []types.SSHKey{}, nil
		}
		km.logger.Error("ssh", "list_keys").
			WithError(err).
			Commit()
		return nil, err
	}

	var keys []types.SSHKey
	skipFiles := map[string]bool{
		"known_hosts":     true,
		"config":          true,
		"authorized_keys": true,
	}

	for _, entry := range entries {
		if entry.IsDir() {
			continue
		}

		name := entry.Name()

		// Skip system files and public keys
		if skipFiles[name] || strings.HasSuffix(name, ".pub") {
			continue
		}

		// Check if it's likely a private key
		if !isPrivateKeyFile(name) {
			continue
		}

		info, err := entry.Info()
		if err != nil {
			continue
		}

		// Check if corresponding .pub file exists
		pubPath := filepath.Join(km.sshDir, name+".pub")
		hasPub := false
		if _, err := os.Stat(pubPath); err == nil {
			hasPub = true
		}

		keys = append(keys, types.SSHKey{
			Name:      name,
			Type:      detectKeyType(name),
			HasPubKey: hasPub,
			ModTime:   info.ModTime().Format(time.RFC3339),
		})
	}

	km.logger.Debug("ssh", "list_keys").
		WithExtra("count", len(keys)).
		Commit()
	return keys, nil
}

// Upload saves an uploaded key file
func (km *KeyManager) Upload(filename string, content []byte) error {
	// Sanitize filename
	filename = filepath.Base(filename)
	if filename == "" || filename == "." || filename == ".." {
		return fmt.Errorf("invalid filename")
	}

	destPath := filepath.Join(km.sshDir, filename)

	km.logger.Info("ssh", "upload_key").
		WithExtra("filename", filename).
		Commit()

	// Write file with restricted permissions
	if err := os.WriteFile(destPath, content, 0600); err != nil {
		km.logger.Error("ssh", "upload_key").
			WithExtra("filename", filename).
			WithError(err).
			Commit()
		return err
	}

	km.logger.Info("ssh", "upload_key_success").
		WithExtra("filename", filename).
		Commit()
	return nil
}

// Delete removes a key file (and its .pub if exists)
func (km *KeyManager) Delete(filename string) error {
	filename = filepath.Base(filename)
	if filename == "" || filename == "." || filename == ".." {
		return fmt.Errorf("invalid filename")
	}

	privPath := filepath.Join(km.sshDir, filename)
	pubPath := privPath + ".pub"

	km.logger.Info("ssh", "delete_key").
		WithExtra("filename", filename).
		Commit()

	// Check if file exists
	if _, err := os.Stat(privPath); os.IsNotExist(err) {
		return fmt.Errorf("key file not found")
	}

	// Delete private key
	if err := os.Remove(privPath); err != nil {
		km.logger.Error("ssh", "delete_key").
			WithExtra("filename", filename).
			WithError(err).
			Commit()
		return err
	}

	// Delete public key if exists (ignore errors)
	os.Remove(pubPath)

	km.logger.Info("ssh", "delete_key_success").
		WithExtra("filename", filename).
		Commit()
	return nil
}

// Generate creates a new SSH key pair
func (km *KeyManager) Generate(req types.SSHKeyGenerateRequest) (*types.SSHKeyGenerateResult, error) {
	// Validate key name
	if !regexp.MustCompile(`^[a-zA-Z0-9_-]+$`).MatchString(req.KeyName) {
		return nil, fmt.Errorf("invalid key name: use only letters, numbers, underscore, and dash")
	}

	// Validate key type
	validTypes := map[string]bool{"rsa": true, "ed25519": true, "ecdsa": true}
	if !validTypes[req.KeyType] {
		return nil, fmt.Errorf("invalid key type: must be rsa, ed25519, or ecdsa")
	}

	// Validate bits for RSA
	if req.KeyType == "rsa" {
		if req.KeyBits < 2048 || req.KeyBits > 4096 {
			return nil, fmt.Errorf("RSA key bits must be between 2048 and 4096")
		}
	}

	privPath := filepath.Join(km.sshDir, req.KeyName)
	pubPath := privPath + ".pub"

	// Check if key already exists
	if _, err := os.Stat(privPath); err == nil {
		return nil, fmt.Errorf("key with this name already exists")
	}
	if _, err := os.Stat(pubPath); err == nil {
		return nil, fmt.Errorf("key with this name already exists")
	}

	// Build ssh-keygen command
	hostname, _ := os.Hostname()
	comment := fmt.Sprintf("nm-webui@%s %s", hostname, time.Now().Format("2006-01-02"))

	args := []string{"-t", req.KeyType}
	if req.KeyType == "rsa" {
		args = append(args, "-b", fmt.Sprintf("%d", req.KeyBits))
	}
	args = append(args, "-f", privPath, "-N", "", "-C", comment)

	km.logger.Info("ssh", "generate_key").
		WithExtra("name", req.KeyName).
		WithExtra("type", req.KeyType).
		Commit()

	start := time.Now()
	cmd := exec.Command("ssh-keygen", args...)
	output, err := cmd.CombinedOutput()
	duration := time.Since(start)

	// Log the command execution
	km.logger.Command("ssh", "ssh-keygen", args).
		WithOutput(string(output)).
		WithDuration(duration).
		WithSuccess(err == nil).
		Commit()

	if err != nil {
		// Clean up partial files
		os.Remove(privPath)
		os.Remove(pubPath)
		return nil, fmt.Errorf("failed to generate key: %v", err)
	}

	// Set permissions
	os.Chmod(privPath, 0600)
	os.Chmod(pubPath, 0644)

	// Read public key content
	pubContent, err := os.ReadFile(pubPath)
	if err != nil {
		return nil, fmt.Errorf("key generated but failed to read public key: %v", err)
	}

	km.logger.Info("ssh", "generate_key_success").
		WithExtra("name", req.KeyName).
		WithExtra("type", req.KeyType).
		Commit()

	return &types.SSHKeyGenerateResult{
		PrivateKey:       req.KeyName,
		PublicKey:        req.KeyName + ".pub",
		PublicKeyContent: strings.TrimSpace(string(pubContent)),
	}, nil
}

// GetPublicKey returns the content of a public key file
func (km *KeyManager) GetPublicKey(keyName string) (string, error) {
	keyName = filepath.Base(keyName)
	pubPath := filepath.Join(km.sshDir, keyName+".pub")

	content, err := os.ReadFile(pubPath)
	if err != nil {
		if os.IsNotExist(err) {
			return "", fmt.Errorf("public key not found")
		}
		return "", err
	}

	return strings.TrimSpace(string(content)), nil
}

// GetKeyPath returns the full path to a key file
func (km *KeyManager) GetKeyPath(keyName string) string {
	return filepath.Join(km.sshDir, filepath.Base(keyName))
}

// KeyExists checks if a key file exists
func (km *KeyManager) KeyExists(keyName string) bool {
	keyPath := km.GetKeyPath(keyName)
	_, err := os.Stat(keyPath)
	return err == nil
}

// isPrivateKeyFile checks if a filename looks like a private key
func isPrivateKeyFile(name string) bool {
	// Common private key extensions
	if strings.HasSuffix(name, ".pem") ||
		strings.HasSuffix(name, ".key") ||
		strings.HasSuffix(name, ".ppk") {
		return true
	}

	// Files without extension but not system files
	if !strings.Contains(name, ".") {
		return true
	}

	// Check for id_* pattern
	if strings.HasPrefix(name, "id_") && !strings.HasSuffix(name, ".pub") {
		return true
	}

	return false
}

// detectKeyType tries to determine the key type from filename
func detectKeyType(name string) string {
	nameLower := strings.ToLower(name)
	if strings.Contains(nameLower, "ed25519") {
		return "ed25519"
	}
	if strings.Contains(nameLower, "ecdsa") {
		return "ecdsa"
	}
	if strings.Contains(nameLower, "rsa") {
		return "rsa"
	}
	if strings.Contains(nameLower, "dsa") {
		return "dsa"
	}
	return "unknown"
}
