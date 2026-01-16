// Package types contains shared types used across the application
package types

// APIResponse is the standard JSON response format
type APIResponse struct {
	OK     bool        `json:"ok"`
	Data   interface{} `json:"data,omitempty"`
	Error  string      `json:"error,omitempty"`
	Detail string      `json:"detail,omitempty"`
}

// LogEntry represents an activity log entry
type LogEntry struct {
	Time    string `json:"time"`
	Action  string `json:"action"`
	Detail  string `json:"detail"`
	Success bool   `json:"success"`
}

// Status represents system and network status
type Status struct {
	Hostname    string   `json:"hostname"`
	Time        string   `json:"time"`
	NMVersion   string   `json:"nm_version"`
	Devices     []Device `json:"devices"`
	WifiDevices []string `json:"wifi_devices"`
	System      SystemInfo `json:"system,omitempty"`
}

// SystemInfo represents system health metrics
type SystemInfo struct {
	UptimeSeconds int64   `json:"uptime_seconds"`
	Load1         float64 `json:"load1"`
	Load5         float64 `json:"load5"`
	Load15        float64 `json:"load15"`
	MemTotal      uint64  `json:"mem_total"`
	MemAvailable  uint64  `json:"mem_available"`
	MemUsed       uint64  `json:"mem_used"`
	DiskTotal     uint64  `json:"disk_total"`
	DiskFree      uint64  `json:"disk_free"`
	DiskUsed      uint64  `json:"disk_used"`
}

// Device represents a network device
type Device struct {
	Device     string `json:"device"`
	Type       string `json:"type"`
	State      string `json:"state"`
	Connection string `json:"connection"`
	IPv4       string `json:"ipv4,omitempty"`
	Gateway    string `json:"gateway,omitempty"`
	DNS        string `json:"dns,omitempty"`
}

// WifiNetwork represents a scanned WiFi network
type WifiNetwork struct {
	InUse     bool   `json:"in_use"`
	SSID      string `json:"ssid"`
	BSSID     string `json:"bssid"`
	Channel   int    `json:"chan"`
	Band      string `json:"band"`
	Rate      string `json:"rate"`
	Signal    int    `json:"signal"`
	Security  string `json:"security"`
	Device    string `json:"device"`
	Mode      string `json:"mode"`
	IsHotspot bool   `json:"is_hotspot"`
}

// SavedWifiConnection represents a saved WiFi connection's metadata
type SavedWifiConnection struct {
	UUID        string `json:"uuid"`
	AutoConnect bool   `json:"ac"`
	Priority    int    `json:"pri"`
}

// WiredConnection represents a wired/USB connection for sharing
type WiredConnection struct {
	Name   string `json:"name"`
	UUID   string `json:"uuid"`
	Method string `json:"method"`
	Device string `json:"device"`
}

// Connection represents a NetworkManager connection profile
type Connection struct {
	Name        string `json:"name"`
	UUID        string `json:"uuid"`
	Type        string `json:"type"`
	Device      string `json:"device"`
	Active      bool   `json:"active"`
	AutoConnect bool   `json:"autoconnect"`
}

// WifiScanResult contains all data from a WiFi scan
type WifiScanResult struct {
	Networks []WifiNetwork              `json:"networks"`
	Saved    map[string]SavedWifiConnection `json:"saved"`
	Wired    []WiredConnection          `json:"wired"`
	Iface    string                     `json:"iface"`
}

// --- Request types ---

// WifiConnectRequest is the request body for WiFi connection
type WifiConnectRequest struct {
	Dev      string `json:"dev"`
	SSID     string `json:"ssid"`
	Password string `json:"password"`
	Hidden   bool   `json:"hidden"`
}

// WifiDisconnectRequest is the request body for WiFi disconnection
type WifiDisconnectRequest struct {
	SSID      string `json:"ssid"`
	IsHotspot bool   `json:"is_hotspot"`
}

// WifiForgetRequest is the request body for forgetting a network
type WifiForgetRequest struct {
	SSID      string `json:"ssid"`
	IsHotspot bool   `json:"is_hotspot"`
}

// WifiPriorityRequest is the request body for setting priority
type WifiPriorityRequest struct {
	UUID     string `json:"uuid"`
	Priority int    `json:"priority"`
}

// HotspotRequest is the request body for hotspot operations
type HotspotRequest struct {
	Mode       string `json:"mode"` // "start" or "stop"
	Dev        string `json:"dev"`
	SSID       string `json:"ssid"`
	Password   string `json:"password"`
	Band       string `json:"band"`
	Channel    int    `json:"channel"`
	ConName    string `json:"con_name"`
	IPRange    string `json:"ip_range"`
	Persistent bool   `json:"persistent"`
}

// ConnectionUUIDRequest is a request with just a UUID
type ConnectionUUIDRequest struct {
	UUID string `json:"uuid"`
}

// ConnectionShareRequest is the request for toggling sharing
type ConnectionShareRequest struct {
	UUID   string `json:"uuid"`
	Enable bool   `json:"enable"`
}

// --- Network Interface types ---

// NetworkInterface represents a network interface with full details
type NetworkInterface struct {
	Device      string `json:"device"`
	Type        string `json:"type"`        // ethernet, wifi, loopback, bridge
	State       string `json:"state"`       // connected, disconnected, unavailable
	Connection  string `json:"connection"`  // active connection profile name
	HWAddr      string `json:"hwaddr"`      // MAC address
	MTU         int    `json:"mtu"`
	IP4Address  string `json:"ip4_address"` // e.g. "192.168.1.100/24"
	IP4Gateway  string `json:"ip4_gateway"`
	IP4DNS      string `json:"ip4_dns"`     // comma-separated
	IP6Address  string `json:"ip6_address,omitempty"`
	Speed       string `json:"speed,omitempty"` // e.g. "1000 Mb/s"
	Driver      string `json:"driver,omitempty"`
	Sharing     bool   `json:"sharing"`     // is internet sharing enabled
	SharingTo   string `json:"sharing_to,omitempty"` // device sharing to
}

// NetworkInterfacesResult contains the list of interfaces
type NetworkInterfacesResult struct {
	Interfaces []NetworkInterface `json:"interfaces"`
}

// NetworkShareRequest is the request for toggling interface sharing
type NetworkShareRequest struct {
	Device   string `json:"device"`
	Enable   bool   `json:"enable"`
	Upstream string `json:"upstream,omitempty"` // upstream interface for internet
}

// --- Result types ---

// ActionResult represents the result of an action
type ActionResult struct {
	Success bool   `json:"success"`
	Message string `json:"message"`
}

// --- SSH Types ---

// SSHKey represents an SSH private key file
type SSHKey struct {
	Name       string `json:"name"`        // filename
	Type       string `json:"type"`        // rsa, ed25519, ecdsa, unknown
	HasPubKey  bool   `json:"has_pub_key"` // whether .pub file exists
	ModTime    string `json:"mod_time"`    // last modified time
}

// SSHTunnel represents an SSH tunnel configuration and status
type SSHTunnel struct {
	ID       string `json:"id"`
	Status   string `json:"status"` // running, stopped
	PID      int    `json:"pid,omitempty"`
	Host     string `json:"host"`
	Port     int    `json:"port"`     // SSH port (default 22)
	User     string `json:"user"`
	AuthType string `json:"auth"`     // key, password
	KeyFile  string `json:"key,omitempty"`
	FwdType  string `json:"fwd"`      // L, R, D
	LPort    int    `json:"lport"`    // local port
	RHost    string `json:"rhost"`    // remote/target host
	RPort    int    `json:"rport"`    // remote/target port
	Since    int64  `json:"since,omitempty"` // unix timestamp when started
}

// SSHKeyListResult is the API response for listing keys
type SSHKeyListResult struct {
	Keys []SSHKey `json:"keys"`
}

// SSHTunnelListResult is the API response for listing tunnels
type SSHTunnelListResult struct {
	Tunnels []SSHTunnel `json:"tunnels"`
}

// --- SSH Request types ---

// SSHKeyGenerateRequest is the request for generating a new key pair
type SSHKeyGenerateRequest struct {
	KeyName string `json:"key_name"`
	KeyType string `json:"key_type"` // rsa, ed25519, ecdsa
	KeyBits int    `json:"key_bits"` // for RSA: 2048, 3072, 4096
}

// SSHKeyGenerateResult is the result of key generation
type SSHKeyGenerateResult struct {
	PrivateKey       string `json:"private_key"`
	PublicKey        string `json:"public_key"`
	PublicKeyContent string `json:"public_key_content"`
}

// SSHTunnelCreateRequest is the request for creating a new tunnel
type SSHTunnelCreateRequest struct {
	Host     string `json:"host"`
	Port     int    `json:"port"`     // SSH port (default 22)
	User     string `json:"user"`
	AuthType string `json:"auth"`     // key, password
	KeyFile  string `json:"key,omitempty"`
	Password string `json:"password,omitempty"`
	FwdType  string `json:"fwd"`      // L, R, D
	LPort    int    `json:"lport"`    // local port
	RHost    string `json:"rhost"`    // target host (for L/R)
	RPort    int    `json:"rport"`    // target port (for L/R)
}

// SSHTunnelIDRequest is a request with just a tunnel ID
type SSHTunnelIDRequest struct {
	ID string `json:"id"`
}

// SSHKeyDeleteRequest is a request to delete a key
type SSHKeyDeleteRequest struct {
	Name string `json:"name"`
}
