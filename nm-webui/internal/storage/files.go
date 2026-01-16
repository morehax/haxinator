// Package storage handles file storage and persistence
package storage

import (
	"encoding/json"
	"os"
	"path/filepath"
)

// FileStore manages file storage for the application
type FileStore struct {
	baseDir string
}

// NewFileStore creates a new file store
func NewFileStore(baseDir string) (*FileStore, error) {
	// Ensure directory exists
	if err := os.MkdirAll(baseDir, 0750); err != nil {
		return nil, err
	}
	return &FileStore{baseDir: baseDir}, nil
}

// Path returns the full path for a filename
func (fs *FileStore) Path(filename string) string {
	return filepath.Join(fs.baseDir, filename)
}

// Exists checks if a file exists
func (fs *FileStore) Exists(filename string) bool {
	_, err := os.Stat(fs.Path(filename))
	return err == nil
}

// Read reads a file's contents
func (fs *FileStore) Read(filename string) ([]byte, error) {
	return os.ReadFile(fs.Path(filename))
}

// Write writes data to a file
func (fs *FileStore) Write(filename string, data []byte, perm os.FileMode) error {
	return os.WriteFile(fs.Path(filename), data, perm)
}

// Delete deletes a file
func (fs *FileStore) Delete(filename string) error {
	return os.Remove(fs.Path(filename))
}

// ReadJSON reads and unmarshals JSON from a file
func (fs *FileStore) ReadJSON(filename string, v interface{}) error {
	data, err := fs.Read(filename)
	if err != nil {
		return err
	}
	return json.Unmarshal(data, v)
}

// WriteJSON marshals and writes JSON to a file
func (fs *FileStore) WriteJSON(filename string, v interface{}, perm os.FileMode) error {
	data, err := json.MarshalIndent(v, "", "  ")
	if err != nil {
		return err
	}
	return fs.Write(filename, data, perm)
}

// List lists files in the storage directory
func (fs *FileStore) List() ([]string, error) {
	entries, err := os.ReadDir(fs.baseDir)
	if err != nil {
		return nil, err
	}

	var files []string
	for _, entry := range entries {
		if !entry.IsDir() {
			files = append(files, entry.Name())
		}
	}
	return files, nil
}

// SubDir returns a FileStore for a subdirectory
func (fs *FileStore) SubDir(name string) (*FileStore, error) {
	return NewFileStore(filepath.Join(fs.baseDir, name))
}
