package configure

import (
	"bufio"
	"fmt"
	"net"
	"os"
	"os/exec"
	"regexp"
	"strings"
)

// NetworkConfigType represents a type of network configuration
type NetworkConfigType string

const (
	ConfigOpenVPN NetworkConfigType = "openvpn"
	ConfigIodine  NetworkConfigType = "iodine"
	ConfigHans    NetworkConfigType = "hans"
	ConfigWifiAP  NetworkConfigType = "wifi_ap"
)

// NetworkConfig represents a detected network configuration
type NetworkConfig struct {
	Type           NetworkConfigType `json:"type"`
	Name           string            `json:"name"`
	Description    string            `json:"description"`
	Icon           string            `json:"icon"`
	Ready          bool              `json:"ready"`
	Status         string            `json:"status"` // ready, incomplete, missing_file
	FoundParams    []string          `json:"found_params"`
	MissingParams  []string          `json:"missing_params,omitempty"`
	OptionalParams []string          `json:"optional_params,omitempty"`
	FileStatus     string            `json:"file_status,omitempty"` // found, missing
	FileName       string            `json:"file_name,omitempty"`
}

// NetworkManager handles network configuration detection and application
type NetworkManager struct {
	fileManager *FileManager
}

// NewNetworkManager creates a new NetworkManager
func NewNetworkManager(fm *FileManager) *NetworkManager {
	return &NetworkManager{fileManager: fm}
}

// configDefinitions defines what parameters each config type needs
var configDefinitions = map[NetworkConfigType]struct {
	name        string
	description string
	icon        string
	required    []string
	optional    []string
	needsFile   bool
	fileName    string
}{
	ConfigOpenVPN: {
		name:        "OpenVPN",
		description: "Secure VPN tunnel connection",
		icon:        "shield-lock",
		needsFile:   true,
		fileName:    "OpenVPN profile",
	},
	ConfigIodine: {
		name:        "Iodine DNS Tunnel",
		description: "DNS tunneling for restricted networks",
		icon:        "dns",
		required:    []string{"IODINE_TOPDOMAIN", "IODINE_NAMESERVER", "IODINE_PASS"},
		optional:    []string{"IODINE_MTU", "IODINE_LAZY", "IODINE_INTERVAL"},
	},
	ConfigHans: {
		name:        "Hans ICMP VPN",
		description: "ICMP tunnel for covert communication",
		icon:        "router",
		required:    []string{"HANS_SERVER", "HANS_PASSWORD"},
	},
	ConfigWifiAP: {
		name:        "WiFi Access Point",
		description: "Wireless hotspot for device sharing",
		icon:        "wifi",
		required:    []string{"WIFI_SSID", "WIFI_PASSWORD"},
	},
}

// ParseEnvFile parses a KEY=VALUE environment file
func ParseEnvFile(content string) map[string]string {
	env := make(map[string]string)
	scanner := bufio.NewScanner(strings.NewReader(content))
	envLineRegex := regexp.MustCompile(`^[A-Z_]+=.+$`)

	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())

		// Skip empty lines and comments
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}

		// Check if line matches KEY=VALUE format
		if !envLineRegex.MatchString(line) {
			continue
		}

		parts := strings.SplitN(line, "=", 2)
		if len(parts) == 2 {
			key := parts[0]
			value := strings.Trim(parts[1], "\"'")
			env[key] = value
		}
	}

	return env
}

// DetectConfigurations detects available network configurations from env-secrets
func (nm *NetworkManager) DetectConfigurations() ([]NetworkConfig, error) {
	// Read env-secrets file
	content, err := nm.fileManager.ViewFile(FileTypeEnvSecrets)
	if err != nil {
		return nil, err
	}

	env := ParseEnvFile(content)
	if len(env) == 0 {
		return nil, fmt.Errorf("no valid configuration found in env-secrets file")
	}

	var configs []NetworkConfig

	// Check each configuration type
	for configType, def := range configDefinitions {
		// Find which required params exist
		var foundParams []string
		var missingParams []string
		for _, param := range def.required {
			if _, exists := env[param]; exists {
				foundParams = append(foundParams, param)
			} else {
				missingParams = append(missingParams, param)
			}
		}

		// Skip if required params exist but none found
		if len(def.required) > 0 && len(foundParams) == 0 {
			continue
		}

		// Find optional params
		var optionalParams []string
		for _, param := range def.optional {
			if _, exists := env[param]; exists {
				optionalParams = append(optionalParams, param)
			}
		}

		// Check file requirement
		fileStatus := ""
		paramsComplete := len(missingParams) == 0
		fileReady := true

		if def.needsFile {
			vpnProfiles, err := nm.fileManager.ListVPNProfiles()
			if err == nil && len(vpnProfiles) > 0 {
				fileStatus = "found"
			} else {
				fileStatus = "missing"
				fileReady = false
			}
		}

		// Determine status
		status := "incomplete"
		ready := false
		if paramsComplete && fileReady {
			status = "ready"
			ready = true
		} else if paramsComplete && !fileReady {
			status = "missing_file"
		}

		config := NetworkConfig{
			Type:           configType,
			Name:           def.name,
			Description:    def.description,
			Icon:           def.icon,
			Ready:          ready,
			Status:         status,
			FoundParams:    foundParams,
			MissingParams:  missingParams,
			OptionalParams: optionalParams,
		}

		if def.needsFile {
			config.FileStatus = fileStatus
			config.FileName = def.fileName
		}

		configs = append(configs, config)
	}

	return configs, nil
}

// ApplyConfiguration applies a specific network configuration
type ApplyOptions struct {
	VPNProfile string
}

func (nm *NetworkManager) ApplyConfiguration(configType NetworkConfigType, opts ApplyOptions) error {
	// Read env-secrets
	content, err := nm.fileManager.ViewFile(FileTypeEnvSecrets)
	if err != nil {
		return fmt.Errorf("failed to read env-secrets: %w", err)
	}

	env := ParseEnvFile(content)

	switch configType {
	case ConfigOpenVPN:
		return nm.applyOpenVPN(env, opts.VPNProfile)
	case ConfigIodine:
		return nm.applyIodine(env)
	case ConfigHans:
		return nm.applyHans(env)
	case ConfigWifiAP:
		return nm.applyWifiAP(env)
	default:
		return fmt.Errorf("unknown configuration type: %s", configType)
	}
}

// applyOpenVPN configures OpenVPN connection
func (nm *NetworkManager) applyOpenVPN(env map[string]string, profile string) error {
	if profile == "" {
		return fmt.Errorf("vpn profile is required")
	}
	vpnFile, err := nm.fileManager.GetVPNProfilePath(profile)
	if err != nil {
		return err
	}

	// Read .ovpn file to detect auth type
	ovpnContent, err := os.ReadFile(vpnFile)
	if err != nil {
		return fmt.Errorf("failed to read VPN config file: %w", err)
	}

	// Check if the config requires username/password authentication
	needsCredentials := strings.Contains(string(ovpnContent), "auth-user-pass")

	profileKey := strings.ToUpper(strings.ReplaceAll(profile, "-", "_"))
	profileKey = strings.ToUpper(strings.ReplaceAll(profileKey, " ", "_"))
	user := env["VPN_"+profileKey+"_USER"]
	pass := env["VPN_"+profileKey+"_PASS"]

	if needsCredentials {
		if user == "" || pass == "" {
			return fmt.Errorf("VPN_%s_USER and VPN_%s_PASS are required (config contains auth-user-pass)", profileKey, profileKey)
		}
		if len(pass) < 6 {
			return fmt.Errorf("VPN_%s_PASS must be at least 6 characters", profileKey)
		}
	}

	connectionID := "openvpn-" + profile
	// Delete existing connection for this profile
	exec.Command("nmcli", "connection", "delete", connectionID).Run()

	// Import OpenVPN config
	out, err := exec.Command("nmcli", "connection", "import", "type", "openvpn", "file", vpnFile).CombinedOutput()
	if err != nil {
		return fmt.Errorf("failed to import OpenVPN configuration: %s", string(out))
	}

	importedName := parseImportedConnectionName(string(out))
	if importedName == "" {
		importedName = "VPN"
	}

	// Rename connection
	if out, err := exec.Command("nmcli", "connection", "modify", importedName, "connection.id", connectionID).CombinedOutput(); err != nil {
		return fmt.Errorf("failed to rename OpenVPN connection: %s", string(out))
	}

	// Only set credentials if the config requires them
	if needsCredentials {
		// Set username
		if out, err := exec.Command("nmcli", "connection", "modify", connectionID,
			"+vpn.data", fmt.Sprintf("username=%s", user),
			"+vpn.data", "password-flags=0").CombinedOutput(); err != nil {
			return fmt.Errorf("failed to set OpenVPN username: %s", string(out))
		}

		// Set password
		if out, err := exec.Command("nmcli", "connection", "modify", connectionID,
			"vpn.secrets", fmt.Sprintf("password=%s", pass)).CombinedOutput(); err != nil {
			return fmt.Errorf("failed to set OpenVPN password: %s", string(out))
		}
	}

	return nil
}

// applyIodine configures Iodine DNS tunnel
func (nm *NetworkManager) applyIodine(env map[string]string) error {
	topdomain := env["IODINE_TOPDOMAIN"]
	nameserver := env["IODINE_NAMESERVER"]
	password := env["IODINE_PASS"]

	if topdomain == "" || nameserver == "" || password == "" {
		return fmt.Errorf("IODINE_TOPDOMAIN, IODINE_NAMESERVER, and IODINE_PASS are required")
	}
	if len(password) < 4 {
		return fmt.Errorf("IODINE_PASS must be at least 4 characters")
	}

	// Validate nameserver (IP or domain)
	if net.ParseIP(nameserver) == nil && !isValidDomain(nameserver) {
		return fmt.Errorf("IODINE_NAMESERVER must be a valid IP or domain")
	}

	// Optional parameters with defaults
	mtu := env["IODINE_MTU"]
	if mtu == "" {
		mtu = "1400"
	}
	lazy := env["IODINE_LAZY"]
	if lazy == "" {
		lazy = "true"
	}
	interval := env["IODINE_INTERVAL"]
	if interval == "" {
		interval = "4"
	}

	// Delete existing connection
	exec.Command("nmcli", "connection", "delete", "iodine-vpn").Run()

	// Create iodine VPN connection
	out, err := exec.Command("nmcli", "connection", "add",
		"type", "vpn",
		"ifname", "iodine0",
		"con-name", "iodine-vpn",
		"vpn-type", "iodine").CombinedOutput()
	if err != nil {
		return fmt.Errorf("failed to create Iodine connection: %s", string(out))
	}

	// Configure VPN data
	vpnData := fmt.Sprintf("topdomain = %s, nameserver = %s, password = %s, mtu = %s, lazy-mode = %s, interval = %s",
		topdomain, nameserver, password, mtu, lazy, interval)

	if out, err := exec.Command("nmcli", "connection", "modify", "iodine-vpn",
		"vpn.data", vpnData).CombinedOutput(); err != nil {
		return fmt.Errorf("failed to configure Iodine connection: %s", string(out))
	}

	// Set password in secrets
	if out, err := exec.Command("nmcli", "connection", "modify", "iodine-vpn",
		"vpn.secrets", fmt.Sprintf("password=%s", password)).CombinedOutput(); err != nil {
		return fmt.Errorf("failed to set Iodine password: %s", string(out))
	}

	return nil
}

// applyHans configures Hans ICMP VPN
func (nm *NetworkManager) applyHans(env map[string]string) error {
	server := env["HANS_SERVER"]
	password := env["HANS_PASSWORD"]

	if server == "" || password == "" {
		return fmt.Errorf("HANS_SERVER and HANS_PASSWORD are required")
	}
	if len(password) < 4 {
		return fmt.Errorf("HANS_PASSWORD must be at least 4 characters")
	}

	// Validate server (IP or domain)
	if net.ParseIP(server) == nil && !isValidDomain(server) {
		return fmt.Errorf("HANS_SERVER must be a valid IP or domain")
	}

	// Delete existing connection
	exec.Command("nmcli", "connection", "delete", "hans-icmp-vpn").Run()

	// Create Hans VPN connection
	out, err := exec.Command("nmcli", "connection", "add",
		"type", "vpn",
		"con-name", "hans-icmp-vpn",
		"ifname", "tun0",
		"vpn-type", "org.freedesktop.NetworkManager.hans").CombinedOutput()
	if err != nil {
		return fmt.Errorf("failed to create Hans connection: %s", string(out))
	}

	// Configure VPN data
	vpnData := fmt.Sprintf("server=%s, password=%s, password-flags=1", server, password)
	if out, err := exec.Command("nmcli", "connection", "modify", "hans-icmp-vpn",
		"vpn.data", vpnData).CombinedOutput(); err != nil {
		return fmt.Errorf("failed to configure Hans connection: %s", string(out))
	}

	// Set never-default
	if out, err := exec.Command("nmcli", "connection", "modify", "hans-icmp-vpn",
		"ipv4.never-default", "true").CombinedOutput(); err != nil {
		return fmt.Errorf("failed to set Hans never-default setting: %s", string(out))
	}

	return nil
}

// applyWifiAP configures WiFi Access Point
func (nm *NetworkManager) applyWifiAP(env map[string]string) error {
	ssid := env["WIFI_SSID"]
	password := env["WIFI_PASSWORD"]

	if ssid == "" || password == "" {
		return fmt.Errorf("WIFI_SSID and WIFI_PASSWORD are required")
	}
	if len(ssid) < 1 || len(ssid) > 32 {
		return fmt.Errorf("WIFI_SSID must be 1-32 characters")
	}
	if len(password) < 8 || len(password) > 63 {
		return fmt.Errorf("WIFI_PASSWORD must be 8-63 characters")
	}

	// Validate SSID characters
	ssidRegex := regexp.MustCompile(`^[a-zA-Z0-9_\-\s]+$`)
	if !ssidRegex.MatchString(ssid) {
		return fmt.Errorf("WIFI_SSID contains invalid characters")
	}

	// Delete existing connection
	exec.Command("nmcli", "connection", "delete", "pi_hotspot").Run()

	// Create WiFi AP connection
	out, err := exec.Command("nmcli", "con", "add",
		"type", "wifi",
		"ifname", "wlan0",
		"con-name", "pi_hotspot",
		"autoconnect", "yes",
		"ssid", ssid).CombinedOutput()
	if err != nil {
		return fmt.Errorf("failed to create WiFi AP connection: %s", string(out))
	}

	// Configure AP settings
	if out, err := exec.Command("nmcli", "con", "mod", "pi_hotspot",
		"802-11-wireless.mode", "ap",
		"802-11-wireless.band", "bg",
		"wifi-sec.key-mgmt", "wpa-psk",
		"wifi-sec.psk", password,
		"ipv4.addresses", "192.168.4.1/24",
		"ipv4.method", "shared",
		"ipv4.never-default", "yes",
		"ipv6.method", "ignore").CombinedOutput(); err != nil {
		return fmt.Errorf("failed to configure WiFi AP settings: %s", string(out))
	}

	return nil
}

// isValidDomain checks if a string is a valid domain name
func isValidDomain(domain string) bool {
	if len(domain) == 0 || len(domain) > 253 {
		return false
	}
	domainRegex := regexp.MustCompile(`^([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$`)
	return domainRegex.MatchString(domain)
}

func parseImportedConnectionName(output string) string {
	re := regexp.MustCompile(`Connection '([^']+)'`)
	matches := re.FindStringSubmatch(output)
	if len(matches) > 1 {
		return matches[1]
	}
	return ""
}

// ValidateConfigType validates a configuration type string
func ValidateConfigType(t string) (NetworkConfigType, bool) {
	switch t {
	case "openvpn":
		return ConfigOpenVPN, true
	case "iodine":
		return ConfigIodine, true
	case "hans":
		return ConfigHans, true
	case "wifi_ap":
		return ConfigWifiAP, true
	default:
		return "", false
	}
}
