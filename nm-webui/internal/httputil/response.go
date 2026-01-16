// Package httputil provides HTTP utility functions
package httputil

import (
	"encoding/json"
	"net/http"

	"nm-webui/internal/types"
)

// JSONOK sends a successful JSON response with data
func JSONOK(w http.ResponseWriter, data interface{}) {
	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(types.APIResponse{OK: true, Data: data})
}

// JSONMessage sends a successful JSON response with just a message (no data)
func JSONMessage(w http.ResponseWriter, message string) {
	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(map[string]interface{}{"ok": true, "message": message})
}

// JSONError sends an error JSON response
func JSONError(w http.ResponseWriter, status int, err, detail string) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	json.NewEncoder(w).Encode(types.APIResponse{OK: false, Error: err, Detail: detail})
}

// RequireMethod checks HTTP method and returns error if wrong
func RequireMethod(w http.ResponseWriter, r *http.Request, method string) bool {
	if r.Method != method {
		JSONError(w, http.StatusMethodNotAllowed, "Method not allowed", "Use "+method)
		return false
	}
	return true
}

// RequireGET checks for GET method
func RequireGET(w http.ResponseWriter, r *http.Request) bool {
	return RequireMethod(w, r, http.MethodGet)
}

// RequirePOST checks for POST method
func RequirePOST(w http.ResponseWriter, r *http.Request) bool {
	return RequireMethod(w, r, http.MethodPost)
}
