// Package server provides HTTP server setup and middleware
package server

import (
	"crypto/subtle"
	"net/http"

	"nm-webui/internal/httputil"
)

// Middleware wraps handlers with common functionality
type Middleware struct {
	Username string
	Password string
}

// NewMiddleware creates a new middleware instance
func NewMiddleware(username, password string) *Middleware {
	return &Middleware{
		Username: username,
		Password: password,
	}
}

// Auth wraps a handler with HTTP Basic Auth (if credentials configured)
func (m *Middleware) Auth(next http.HandlerFunc) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		// Skip auth if no credentials configured
		if m.Username == "" && m.Password == "" {
			next(w, r)
			return
		}

		user, pass, ok := r.BasicAuth()
		if !ok {
			w.Header().Set("WWW-Authenticate", `Basic realm="nm-webui"`)
			httputil.JSONError(w, http.StatusUnauthorized, "Authentication required", "")
			return
		}

		// Constant-time comparison to prevent timing attacks
		userMatch := subtle.ConstantTimeCompare([]byte(user), []byte(m.Username)) == 1
		passMatch := subtle.ConstantTimeCompare([]byte(pass), []byte(m.Password)) == 1

		if !userMatch || !passMatch {
			w.Header().Set("WWW-Authenticate", `Basic realm="nm-webui"`)
			httputil.JSONError(w, http.StatusUnauthorized, "Invalid credentials", "")
			return
		}

		next(w, r)
	}
}
