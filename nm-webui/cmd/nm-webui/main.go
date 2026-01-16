// nm-webui - Minimal NetworkManager Web Interface for Raspberry Pi
// A lightweight, self-contained binary for managing WiFi and network connections
package main

import (
	"context"
	"crypto/rand"
	"embed"
	"encoding/base64"
	"flag"
	"fmt"
	"log"
	"net/http"
	"os"
	"os/signal"
	"path/filepath"
	"strings"
	"syscall"
	"time"

	"nm-webui/internal/server"
)

//go:embed all:static
var staticFS embed.FS

func main() {
	// Parse command line flags
	listen := flag.String("listen", "127.0.0.1:8080", "Address to listen on")
	authFile := flag.String("auth-file", "", "Path to auth credentials file (user:pass)")
	noAuth := flag.Bool("no-auth", false, "Disable authentication (for testing)")
	flag.Parse()

	// Load or generate auth credentials
	cfg := &server.Config{Listen: *listen}

	if !*noAuth {
		if err := loadOrGenerateAuth(cfg, *authFile); err != nil {
			log.Fatalf("Failed to setup authentication: %v", err)
		}
	} else {
		log.Println("WARNING: Authentication disabled!")
	}

	// Create server
	srv, err := server.New(cfg, staticFS)
	if err != nil {
		log.Fatalf("Failed to create server: %v", err)
	}

	// Create HTTP server
	httpServer := &http.Server{
		Addr:         cfg.Listen,
		Handler:      srv.Handler(),
		ReadTimeout:  30 * time.Second,
		WriteTimeout: 30 * time.Second,
		IdleTimeout:  60 * time.Second,
	}

	// Start server in goroutine
	go func() {
		log.Printf("Starting nm-webui on http://%s", cfg.Listen)
		log.Printf("Username: %s", cfg.Username)
		if err := httpServer.ListenAndServe(); err != http.ErrServerClosed {
			log.Fatalf("HTTP server error: %v", err)
		}
	}()

	// Graceful shutdown
	stop := make(chan os.Signal, 1)
	signal.Notify(stop, syscall.SIGINT, syscall.SIGTERM)
	<-stop

	log.Println("Shutting down server...")
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()

	if err := httpServer.Shutdown(ctx); err != nil {
		log.Printf("Server shutdown error: %v", err)
	}
	log.Println("Server stopped")
}

// loadOrGenerateAuth loads credentials from file/env or generates random ones
func loadOrGenerateAuth(cfg *server.Config, authFile string) error {
	// Try environment variables first
	if user := os.Getenv("NM_WEBUI_USER"); user != "" {
		cfg.Username = user
		cfg.Password = os.Getenv("NM_WEBUI_PASS")
		if cfg.Password != "" {
			return nil
		}
	}

	// Try auth file
	if authFile != "" {
		data, err := os.ReadFile(authFile)
		if err == nil {
			parts := strings.SplitN(strings.TrimSpace(string(data)), ":", 2)
			if len(parts) == 2 {
				cfg.Username = parts[0]
				cfg.Password = parts[1]
				return nil
			}
		} else if !os.IsNotExist(err) {
			return fmt.Errorf("failed to read auth file: %w", err)
		}
	}

	// Generate random credentials
	cfg.Username = "admin"
	passBytes := make([]byte, 16)
	if _, err := rand.Read(passBytes); err != nil {
		return fmt.Errorf("failed to generate password: %w", err)
	}
	cfg.Password = base64.URLEncoding.EncodeToString(passBytes)[:16]

	log.Printf("Generated random password: %s", cfg.Password)

	// Save to auth file if specified
	if authFile != "" {
		dir := filepath.Dir(authFile)
		if err := os.MkdirAll(dir, 0700); err != nil {
			return fmt.Errorf("failed to create auth directory: %w", err)
		}
		authData := fmt.Sprintf("%s:%s\n", cfg.Username, cfg.Password)
		if err := os.WriteFile(authFile, []byte(authData), 0600); err != nil {
			return fmt.Errorf("failed to write auth file: %w", err)
		}
		log.Printf("Saved credentials to %s", authFile)
	}

	return nil
}
