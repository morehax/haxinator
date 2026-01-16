package configure

import (
	"fmt"
	"io"
	"os"
	"path/filepath"
	"time"
)

// FileType represents the type of configuration file
type FileType string

const (
	FileTypeEnvSecrets FileType = "env-secrets"
	FileTypeVPN        FileType = "vpn"
)

// FileStatus represents the status of a configuration file
type FileStatus struct {
	Exists   bool   `json:"exists"`
	Size     int64  `json:"size"`
	Modified string `json:"modified,omitempty"`
}

// FileInfo contains metadata about all configuration files
type FileInfo struct {
	EnvSecrets FileStatus `json:"env-secrets"`
	VPN        FileStatus `json:"vpn"`
}

// FileManager handles configuration file operations
type FileManager struct {
	basePath string
}

// NewFileManager creates a new FileManager
func NewFileManager(basePath string) *FileManager {
	return &FileManager{basePath: basePath}
}

// filePathMap maps file types to their actual filenames
var filePathMap = map[FileType]string{
	FileTypeEnvSecrets: "env-secrets",
	FileTypeVPN:        "VPN.ovpn",
}

// maxSizeMap defines maximum file sizes for each type
var maxSizeMap = map[FileType]int64{
	FileTypeEnvSecrets: 1 * 1024 * 1024, // 1MB
	FileTypeVPN:        1 * 1024 * 1024, // 1MB
}

// GetFilePath returns the full path for a file type
func (fm *FileManager) GetFilePath(fileType FileType) string {
	filename, ok := filePathMap[fileType]
	if !ok {
		return ""
	}
	return filepath.Join(fm.basePath, filename)
}

// GetFileStatus returns the status of a specific file
func (fm *FileManager) GetFileStatus(fileType FileType) FileStatus {
	path := fm.GetFilePath(fileType)
	if path == "" {
		return FileStatus{Exists: false}
	}

	info, err := os.Stat(path)
	if err != nil {
		return FileStatus{Exists: false}
	}

	return FileStatus{
		Exists:   true,
		Size:     info.Size(),
		Modified: info.ModTime().Format("2006-01-02 15:04"),
	}
}

// GetAllFileStatus returns the status of all configuration files
func (fm *FileManager) GetAllFileStatus() FileInfo {
	return FileInfo{
		EnvSecrets: fm.GetFileStatus(FileTypeEnvSecrets),
		VPN:        fm.GetFileStatus(FileTypeVPN),
	}
}

// ViewFile returns the contents of a configuration file
func (fm *FileManager) ViewFile(fileType FileType) (string, error) {
	path := fm.GetFilePath(fileType)
	if path == "" {
		return "", fmt.Errorf("invalid file type")
	}

	if _, err := os.Stat(path); os.IsNotExist(err) {
		return "", fmt.Errorf("file does not exist")
	}

	content, err := os.ReadFile(path)
	if err != nil {
		return "", fmt.Errorf("failed to read file: %w", err)
	}

	return string(content), nil
}

// SaveFile saves uploaded content to a configuration file
func (fm *FileManager) SaveFile(fileType FileType, reader io.Reader, size int64) error {
	// Validate file type
	path := fm.GetFilePath(fileType)
	if path == "" {
		return fmt.Errorf("invalid file type")
	}

	// Check size limit
	maxSize, ok := maxSizeMap[fileType]
	if !ok {
		return fmt.Errorf("unknown file type")
	}
	if size > maxSize {
		return fmt.Errorf("file too large (max %d bytes)", maxSize)
	}

	// Ensure directory exists
	if err := os.MkdirAll(fm.basePath, 0755); err != nil {
		return fmt.Errorf("failed to create directory: %w", err)
	}

	// Create temporary file first
	tmpPath := path + ".tmp." + fmt.Sprintf("%d", time.Now().UnixNano())
	tmpFile, err := os.Create(tmpPath)
	if err != nil {
		return fmt.Errorf("failed to create temp file: %w", err)
	}
	defer os.Remove(tmpPath) // Clean up temp file on error

	// Copy content to temp file
	written, err := io.Copy(tmpFile, reader)
	if err != nil {
		tmpFile.Close()
		return fmt.Errorf("failed to write file: %w", err)
	}
	tmpFile.Close()

	if written > maxSize {
		return fmt.Errorf("file too large (max %d bytes)", maxSize)
	}

	// Set permissions
	if err := os.Chmod(tmpPath, 0644); err != nil {
		return fmt.Errorf("failed to set permissions: %w", err)
	}

	// Atomic rename
	if err := os.Rename(tmpPath, path); err != nil {
		return fmt.Errorf("failed to save file: %w", err)
	}

	return nil
}

// DeleteFile removes a configuration file
func (fm *FileManager) DeleteFile(fileType FileType) error {
	path := fm.GetFilePath(fileType)
	if path == "" {
		return fmt.Errorf("invalid file type")
	}

	if err := os.Remove(path); err != nil && !os.IsNotExist(err) {
		return fmt.Errorf("failed to delete file: %w", err)
	}

	return nil
}

// ValidateFileType checks if a file type string is valid
func ValidateFileType(t string) (FileType, bool) {
	switch t {
	case "env-secrets":
		return FileTypeEnvSecrets, true
	case "vpn":
		return FileTypeVPN, true
	default:
		return "", false
	}
}
