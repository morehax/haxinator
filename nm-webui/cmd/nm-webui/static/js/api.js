/**
 * API Module - Handles all backend communication
 */
const API = {
    baseUrl: '',
    authHeader: null,

    /**
     * Initialize API with optional auth
     */
    init(baseUrl = '', username = '', password = '') {
        this.baseUrl = baseUrl;
        if (username && password) {
            this.authHeader = 'Basic ' + btoa(username + ':' + password);
        }
    },

    /**
     * Make an API request
     */
    async request(method, endpoint, data = null) {
        const url = this.baseUrl + endpoint;
        const options = {
            method,
            headers: {
                'Content-Type': 'application/json',
            }
        };

        if (this.authHeader) {
            options.headers['Authorization'] = this.authHeader;
        }

        if (data) {
            options.body = JSON.stringify(data);
        }

        try {
            const response = await fetch(url, options);
            const json = await response.json();
            
            if (!response.ok || json.ok === false) {
                throw new Error(json.error || json.detail || 'Request failed');
            }
            
            // Return the data payload directly if present
            return json.data !== undefined ? json.data : json;
        } catch (err) {
            if (err.name === 'SyntaxError') {
                throw new Error('Invalid response from server');
            }
            throw err;
        }
    },

    // GET helper
    get(endpoint) {
        return this.request('GET', endpoint);
    },

    // POST helper
    post(endpoint, data) {
        return this.request('POST', endpoint, data);
    },

    // DELETE helper
    delete(endpoint, data = null) {
        return this.request('DELETE', endpoint, data);
    },

    // ========== Status ==========
    async getStatus() {
        return this.get('/api/status');
    },

    async getLogs() {
        return this.get('/api/log');
    },

    async getExternalIP() {
        return this.get('/api/status/external-ip');
    },

    async getDNSLookup() {
        return this.get('/api/status/dns');
    },

    async getPingCheck() {
        return this.get('/api/status/ping');
    },

    // ========== WiFi ==========
    async scanWifi(device = 'wlan0') {
        return this.get(`/api/wifi/scan?dev=${encodeURIComponent(device)}`);
    },

    async connectWifi(device, ssid, password, hidden = false) {
        return this.post('/api/wifi/connect', { dev: device, ssid, password, hidden });
    },

    async disconnectWifi(ssid) {
        return this.post('/api/wifi/disconnect', { ssid });
    },

    async forgetNetwork(ssid) {
        return this.post('/api/wifi/forget', { ssid });
    },

    async setNetworkPriority(uuid, priority) {
        return this.post('/api/wifi/priority', { uuid, priority: parseInt(priority) });
    },

    async createHotspot(config) {
        return this.post('/api/wifi/hotspot', config);
    },

    // ========== Connections ==========
    async getConnections() {
        return this.get('/api/connections');
    },

    async activateConnection(uuid) {
        return this.post('/api/connections/activate', { uuid });
    },

    async deactivateConnection(uuid) {
        return this.post('/api/connections/deactivate', { uuid });
    },

    async deleteConnection(uuid) {
        return this.delete(`/api/connections/delete/${encodeURIComponent(uuid)}`);
    },

    async toggleSharing(device, enable) {
        return this.post('/api/connections/share', { dev: device, enable });
    },

    // ========== Network ==========
    async getNetworkInterfaces() {
        return this.get('/api/network/interfaces');
    },

    async setInterfaceSharing(device, enable, upstream = '') {
        return this.post('/api/network/share', { device, enable, upstream });
    },

    // ========== Logs ==========
    async getSystemLogs(options = {}) {
        const params = new URLSearchParams();
        if (options.category) params.set('category', options.category);
        if (options.level) params.set('level', options.level);
        if (options.limit) params.set('limit', options.limit.toString());
        if (options.search) params.set('search', options.search);
        if (options.success !== undefined) params.set('success', options.success.toString());
        
        const queryString = params.toString();
        return this.get('/api/logs' + (queryString ? '?' + queryString : ''));
    },

    async getLogSettings() {
        return this.get('/api/logs/settings');
    },

    async updateLogSettings(settings) {
        return this.post('/api/logs/settings', settings);
    },

    async toggleLogging(enabled) {
        return this.post('/api/logs/toggle', { enabled });
    },

    async clearLogs() {
        return this.post('/api/logs/clear', {});
    },

    async getLogStats() {
        return this.get('/api/logs/stats');
    },

    // ========== SSH Keys ==========
    async getSSHKeys() {
        return this.get('/api/ssh/keys');
    },

    async uploadSSHKey(file) {
        // Special handling for file upload - can't use standard request method
        const formData = new FormData();
        formData.append('keyfile', file);

        const options = {
            method: 'POST',
            body: formData
        };

        if (this.authHeader) {
            options.headers = { 'Authorization': this.authHeader };
        }

        const response = await fetch(this.baseUrl + '/api/ssh/keys/upload', options);
        const json = await response.json();
        
        if (!response.ok || json.ok === false) {
            throw new Error(json.error || json.detail || 'Upload failed');
        }
        
        return json.data !== undefined ? json.data : json;
    },

    async deleteSSHKey(name) {
        return this.post('/api/ssh/keys/delete', { name });
    },

    async generateSSHKey(keyName, keyType, keyBits = 2048) {
        return this.post('/api/ssh/keys/generate', {
            key_name: keyName,
            key_type: keyType,
            key_bits: keyBits
        });
    },

    async getSSHPublicKey(name) {
        return this.get(`/api/ssh/keys/public?name=${encodeURIComponent(name)}`);
    },

    getSSHPublicKeyDownloadUrl(name) {
        return this.baseUrl + `/api/ssh/keys/download?name=${encodeURIComponent(name)}`;
    },

    // ========== SSH Tunnels ==========
    async getSSHTunnels() {
        return this.get('/api/ssh/tunnels');
    },

    async createSSHTunnel(config) {
        return this.post('/api/ssh/tunnels/create', config);
    },

    async startSSHTunnel(id) {
        return this.post('/api/ssh/tunnels/start', { id });
    },

    async stopSSHTunnel(id) {
        return this.post('/api/ssh/tunnels/stop', { id });
    },

    async deleteSSHTunnel(id) {
        return this.post('/api/ssh/tunnels/delete', { id });
    },

    // ========== System ==========
    async shutdownSystem() {
        return this.post('/api/system/shutdown', {});
    },

    async rebootSystem() {
        return this.post('/api/system/reboot', {});
    }
};

export default API;
