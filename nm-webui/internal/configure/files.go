package configure

import (
	"fmt"
	"io"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"
)

// FileType represents the type of configuration file
type FileType string

const (
	FileTypeEnvSecrets     FileType = "env-secrets"
	FileTypeVPN            FileType = "vpn"
	FileTypeAuthorizedKeys FileType = "authorized-keys"
)

const (
	vpnDirName         = "openvpn"
	vpnFileExt         = ".ovpn"
	vpnMaxName         = 64
	authorizedKeysPath = "/root/.ssh/authorized_keys"
)

// FileStatus represents the status of a configuration file
type FileStatus struct {
	Exists   bool   `json:"exists"`
	Size     int64  `json:"size"`
	Modified string `json:"modified,omitempty"`
}

// FileInfo contains metadata about all configuration files
type FileInfo struct {
	EnvSecrets     FileStatus      `json:"env-secrets"`
	VPN            FileStatus      `json:"vpn"`
	AuthorizedKeys FileStatus      `json:"authorized-keys"`
	VPNProfiles    []VPNFileStatus `json:"vpn_profiles"`
}

// VPNFileStatus represents the status of a stored OpenVPN profile file
type VPNFileStatus struct {
	Name     string `json:"name"`
	FileName string `json:"file_name"`
	Size     int64  `json:"size"`
	Modified string `json:"modified,omitempty"`
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
	FileTypeEnvSecrets:     "env-secrets",
	FileTypeVPN:            "VPN.ovpn",
	FileTypeAuthorizedKeys: authorizedKeysPath,
}

// maxSizeMap defines maximum file sizes for each type
var maxSizeMap = map[FileType]int64{
	FileTypeEnvSecrets:     1 * 1024 * 1024, // 1MB
	FileTypeVPN:            1 * 1024 * 1024, // 1MB
	FileTypeAuthorizedKeys: 16 * 1024,       // 16KB
}

// GetFilePath returns the full path for a file type
func (fm *FileManager) GetFilePath(fileType FileType) string {
	if fileType == FileTypeAuthorizedKeys {
		return authorizedKeysPath
	}
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
	vpnProfiles, _ := fm.ListVPNProfiles()
	vpnStatus := FileStatus{Exists: len(vpnProfiles) > 0}

	return FileInfo{
		EnvSecrets:     fm.GetFileStatus(FileTypeEnvSecrets),
		VPN:            vpnStatus,
		AuthorizedKeys: fm.GetFileStatus(FileTypeAuthorizedKeys),
		VPNProfiles:    vpnProfiles,
	}
}

// GetVPNDirPath returns the directory for VPN profiles
func (fm *FileManager) GetVPNDirPath() string {
	return filepath.Join(fm.basePath, vpnDirName)
}

// NormalizeVPNProfileName validates and normalizes a profile name
func NormalizeVPNProfileName(name string) (string, error) {
	name = strings.TrimSpace(name)
	if name == "" || len(name) > vpnMaxName {
		return "", fmt.Errorf("invalid profile name")
	}
	for _, ch := range name {
		if (ch >= 'a' && ch <= 'z') || (ch >= 'A' && ch <= 'Z') || (ch >= '0' && ch <= '9') || ch == '-' || ch == '_' {
			continue
		}
		return "", fmt.Errorf("profile name must be alphanumeric, '-' or '_'")
	}
	return name, nil
}

// GetVPNProfilePath returns the full path for a VPN profile
func (fm *FileManager) GetVPNProfilePath(profile string) (string, error) {
	profileName, err := NormalizeVPNProfileName(profile)
	if err != nil {
		return "", err
	}
	return filepath.Join(fm.GetVPNDirPath(), profileName+vpnFileExt), nil
}

// ListVPNProfiles returns all stored VPN profile files
func (fm *FileManager) ListVPNProfiles() ([]VPNFileStatus, error) {
	dir := fm.GetVPNDirPath()
	entries, err := os.ReadDir(dir)
	if err != nil {
		if os.IsNotExist(err) {
			return []VPNFileStatus{}, nil
		}
		return nil, fmt.Errorf("failed to read vpn directory: %w", err)
	}

	var profiles []VPNFileStatus
	for _, entry := range entries {
		if entry.IsDir() {
			continue
		}
		name := entry.Name()
		if !strings.HasSuffix(strings.ToLower(name), vpnFileExt) {
			continue
		}
		info, err := entry.Info()
		if err != nil {
			continue
		}
		base := strings.TrimSuffix(name, vpnFileExt)
		profiles = append(profiles, VPNFileStatus{
			Name:     base,
			FileName: name,
			Size:     info.Size(),
			Modified: info.ModTime().Format("2006-01-02 15:04"),
		})
	}

	sort.Slice(profiles, func(i, j int) bool {
		return profiles[i].Name < profiles[j].Name
	})

	return profiles, nil
}

// ViewVPNProfile returns the contents of a VPN profile
func (fm *FileManager) ViewVPNProfile(profile string) (string, error) {
	path, err := fm.GetVPNProfilePath(profile)
	if err != nil {
		return "", err
	}
	content, err := os.ReadFile(path)
	if err != nil {
		return "", fmt.Errorf("failed to read file: %w", err)
	}
	return string(content), nil
}

// SaveVPNProfile saves uploaded content to a VPN profile file
func (fm *FileManager) SaveVPNProfile(profile string, reader io.Reader, size int64) error {
	path, err := fm.GetVPNProfilePath(profile)
	if err != nil {
		return err
	}
	if size > maxSizeMap[FileTypeVPN] {
		return fmt.Errorf("file too large (max %d bytes)", maxSizeMap[FileTypeVPN])
	}
	if err := os.MkdirAll(fm.GetVPNDirPath(), 0755); err != nil {
		return fmt.Errorf("failed to create directory: %w", err)
	}

	tmpPath := path + ".tmp." + fmt.Sprintf("%d", time.Now().UnixNano())
	tmpFile, err := os.Create(tmpPath)
	if err != nil {
		return fmt.Errorf("failed to create temp file: %w", err)
	}
	defer os.Remove(tmpPath)

	written, err := io.Copy(tmpFile, reader)
	if err != nil {
		tmpFile.Close()
		return fmt.Errorf("failed to write file: %w", err)
	}
	tmpFile.Close()

	if written > maxSizeMap[FileTypeVPN] {
		return fmt.Errorf("file too large (max %d bytes)", maxSizeMap[FileTypeVPN])
	}

	if err := os.Chmod(tmpPath, 0644); err != nil {
		return fmt.Errorf("failed to set permissions: %w", err)
	}

	if err := os.Rename(tmpPath, path); err != nil {
		return fmt.Errorf("failed to save file: %w", err)
	}

	return nil
}

// DeleteVPNProfile removes a VPN profile file
func (fm *FileManager) DeleteVPNProfile(profile string) error {
	path, err := fm.GetVPNProfilePath(profile)
	if err != nil {
		return err
	}
	if err := os.Remove(path); err != nil && !os.IsNotExist(err) {
		return fmt.Errorf("failed to delete file: %w", err)
	}
	return nil
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

// AppendAuthorizedKey appends a single-line public key to authorized_keys with deduplication
func (fm *FileManager) AppendAuthorizedKey(reader io.Reader, size int64) (bool, error) {
	maxSize, ok := maxSizeMap[FileTypeAuthorizedKeys]
	if !ok {
		return false, fmt.Errorf("unknown file type")
	}
	if size > maxSize {
		return false, fmt.Errorf("file too large (max %d bytes)", maxSize)
	}

	limited := io.LimitReader(reader, maxSize+1)
	content, err := io.ReadAll(limited)
	if err != nil {
		return false, fmt.Errorf("failed to read file: %w", err)
	}
	if int64(len(content)) > maxSize {
		return false, fmt.Errorf("file too large (max %d bytes)", maxSize)
	}

	line := strings.TrimSpace(string(content))
	if line == "" {
		return false, fmt.Errorf("empty key")
	}
	if strings.ContainsAny(line, "\r\n") {
		return false, fmt.Errorf("only single-line keys are supported")
	}
	if len(strings.Fields(line)) < 2 {
		return false, fmt.Errorf("invalid public key format")
	}

	path := fm.GetFilePath(FileTypeAuthorizedKeys)
	dir := filepath.Dir(path)
	if err := os.MkdirAll(dir, 0700); err != nil {
		return false, fmt.Errorf("failed to create ssh directory: %w", err)
	}

	existing := map[string]bool{}
	if data, err := os.ReadFile(path); err == nil {
		for _, entry := range strings.Split(string(data), "\n") {
			entry = strings.TrimSpace(entry)
			if entry == "" {
				continue
			}
			existing[entry] = true
		}
	} else if !os.IsNotExist(err) {
		return false, fmt.Errorf("failed to read existing keys: %w", err)
	}

	if existing[line] {
		return false, nil
	}

	file, err := os.OpenFile(path, os.O_APPEND|os.O_WRONLY|os.O_CREATE, 0600)
	if err != nil {
		return false, fmt.Errorf("failed to open authorized_keys: %w", err)
	}
	defer file.Close()

	if _, err := file.WriteString(line + "\n"); err != nil {
		return false, fmt.Errorf("failed to append key: %w", err)
	}

	if err := os.Chmod(path, 0600); err != nil {
		return false, fmt.Errorf("failed to set permissions: %w", err)
	}

	return true, nil
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
	case "authorized-keys":
		return FileTypeAuthorizedKeys, true
	default:
		return "", false
	}
}
