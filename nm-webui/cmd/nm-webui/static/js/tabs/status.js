/**
 * Status Tab Module
 */
import { API, UI, Icons, registerTab, setDevices, setHostname } from '../app.js';

const StatusTab = {
    id: 'status',
    label: 'Status',
    iconName: 'activity',
    loaded: false,
    eventsBound: false,
    diagnostics: {
        externalIP: null,
        dns: null,
        ping: null
    },

    init() {
        // Nothing to initialize
    },

    render() {
        return `
            <div class="toolbar">
                <h2>System Status</h2>
                <div class="toolbar-spacer"></div>
                <button class="btn" id="status-refresh">
                    ${Icons.refresh} Refresh
                </button>
            </div>
            <div id="status-content">
                ${UI.loading('Loading status...')}
            </div>
        `;
    },

    onActivate() {
        // Setup event handlers
        if (!this.eventsBound) {
            document.getElementById('status-refresh')?.addEventListener('click', () => this.load(true));
            this.eventsBound = true;
        }
        
        if (!this.loaded) {
            this.load();
        }
    },

    onDeactivate() {
        // Nothing to cleanup
    },

    async load(force = false) {
        const container = document.getElementById('status-content');
        if (!container) return;

        if (force) {
            container.innerHTML = UI.loading('Refreshing...');
        }

        try {
            const data = await API.getStatus();
            this.loaded = true;
            
            // Update global device list
            if (data.devices) {
                setDevices(data.devices);
            }
            if (data.hostname) {
                setHostname(data.hostname);
            }

            container.innerHTML = this.renderStatus(data);
            this.bindStatusActions();
        } catch (err) {
            container.innerHTML = `<div class="state-message">Error: ${UI.escape(err.message)}</div>`;
            UI.error('Failed to load status: ' + err.message);
        }
    },

    renderStatus(data) {
        const system = data.system || {};
        return `
            <div class="card">
                <div class="card-header">
                    <span class="card-title">${Icons.grid} System Info</span>
                </div>
                <div class="status-grid">
                    <div class="status-card">
                        <div class="status-label">Disk (/)</div>
                        <div class="status-value">${UI.escape(this.formatUsage(system.disk_used, system.disk_total))}</div>
                    </div>
                    <div class="status-card">
                        <div class="status-label">Memory</div>
                        <div class="status-value">${UI.escape(this.formatUsage(system.mem_used, system.mem_total))}</div>
                    </div>
                    <div class="status-card">
                        <div class="status-label">Uptime</div>
                        <div class="status-value">${UI.escape(this.formatUptime(system.uptime_seconds))}</div>
                    </div>
                    <div class="status-card">
                        <div class="status-label">Load (1/5/15m)</div>
                        <div class="status-value">${UI.escape(this.formatLoad(system))}</div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <span class="card-title">${Icons.activity} Diagnostics</span>
                </div>
                <div class="status-grid">
                    <div class="status-card">
                        <div class="status-label">External IP</div>
                        <div class="status-value" id="diag-external-ip">${UI.escape(this.diagnostics.externalIP || '—')}</div>
                        <button class="btn btn-sm" id="diag-external-ip-btn">Check External IP</button>
                    </div>
                    <div class="status-card">
                        <div class="status-label">DNS Lookup</div>
                        <div class="status-value">google.com</div>
                        <div class="item-meta">
                            <span class="item-meta-item" id="diag-dns-result">${UI.escape(this.diagnostics.dns || '—')}</span>
                        </div>
                        <button class="btn btn-sm" id="diag-dns-btn">Run Lookup</button>
                    </div>
                    <div class="status-card">
                        <div class="status-label">Ping</div>
                        <div class="status-value">8.8.8.8</div>
                        <div class="item-meta">
                            <span class="item-meta-item" id="diag-ping-result">${UI.escape(this.diagnostics.ping || '—')}</span>
                        </div>
                        <button class="btn btn-sm" id="diag-ping-btn">Run Ping</button>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <span class="card-title">${Icons.server} Network Devices</span>
                    <span class="badge">${data.devices?.length || 0}</span>
                </div>
                <div class="card-body">
                    ${this.renderDevices(data.devices || [])}
                </div>
            </div>
        `;
    },

    renderDevices(devices) {
        if (!devices.length) {
            return UI.empty('No network devices found');
        }

        return devices.map(dev => {
            const isConnected = dev.state === 'connected';
            const icon = dev.type === 'wifi' ? Icons.wifi : dev.type === 'ethernet' ? Icons.ethernet : Icons.radio;
            
            return `
                <div class="list-item ${isConnected ? 'active' : ''}">
                    <div class="item-icon">${icon}</div>
                    <div class="item-content">
                        <div class="item-title">${UI.escape(dev.device)}</div>
                        <div class="item-meta">
                            <span class="item-meta-item">
                                <strong>Type:</strong> ${UI.escape(dev.type)}
                            </span>
                            <span class="item-meta-item">
                                <strong>State:</strong> 
                                <span class="${isConnected ? 'text-success' : 'text-muted'}">${UI.escape(dev.state)}</span>
                            </span>
                            ${dev.connection ? `
                                <span class="item-meta-item">
                                    <strong>Connection:</strong> ${UI.escape(dev.connection)}
                                </span>
                            ` : ''}
                            ${dev.ipv4 ? `
                                <span class="item-meta-item">
                                    <strong>IP:</strong> ${UI.escape(dev.ipv4)}
                                </span>
                            ` : ''}
                            ${dev.gateway ? `
                                <span class="item-meta-item">
                                    <strong>Gateway:</strong> ${UI.escape(dev.gateway)}
                                </span>
                            ` : ''}
                            ${dev.dns ? `
                                <span class="item-meta-item">
                                    <strong>DNS:</strong> ${UI.escape(dev.dns)}
                                </span>
                            ` : ''}
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    },

    bindStatusActions() {
        const externalBtn = document.getElementById('diag-external-ip-btn');
        if (externalBtn) {
            externalBtn.onclick = () => this.loadExternalIP();
        }

        const dnsBtn = document.getElementById('diag-dns-btn');
        if (dnsBtn) {
            dnsBtn.onclick = () => this.loadDNSLookup();
        }

        const pingBtn = document.getElementById('diag-ping-btn');
        if (pingBtn) {
            pingBtn.onclick = () => this.loadPing();
        }
    },

    async loadExternalIP() {
        const valueEl = document.getElementById('diag-external-ip');
        const button = document.getElementById('diag-external-ip-btn');
        if (!valueEl || !button) return;

        button.disabled = true;
        valueEl.textContent = 'Checking...';

        try {
            const ip = await API.getExternalIP();
            this.diagnostics.externalIP = ip;
            valueEl.textContent = ip || '—';
        } catch (err) {
            valueEl.textContent = this.diagnostics.externalIP || '—';
            UI.error('Failed to fetch external IP: ' + err.message);
        } finally {
            button.disabled = false;
        }
    },

    async loadDNSLookup() {
        const valueEl = document.getElementById('diag-dns-result');
        const button = document.getElementById('diag-dns-btn');
        if (!valueEl || !button) return;

        button.disabled = true;
        valueEl.textContent = 'Looking up...';

        try {
            const result = await API.getDNSLookup();
            const addresses = result.addresses || [];
            const display = addresses.length ? addresses.join(', ') : 'No records';
            this.diagnostics.dns = display;
            valueEl.textContent = display;
        } catch (err) {
            valueEl.textContent = this.diagnostics.dns || '—';
            UI.error('DNS lookup failed: ' + err.message);
        } finally {
            button.disabled = false;
        }
    },

    async loadPing() {
        const valueEl = document.getElementById('diag-ping-result');
        const button = document.getElementById('diag-ping-btn');
        if (!valueEl || !button) return;

        button.disabled = true;
        valueEl.textContent = 'Pinging...';

        try {
            const result = await API.getPingCheck();
            const received = result.received ?? '—';
            const transmitted = result.transmitted ?? '—';
            const avg = result.avg_ms ? `${result.avg_ms} ms` : '—';
            const loss = result.loss_percent !== undefined ? `${result.loss_percent}% loss` : '';
            const display = `${received}/${transmitted} received, avg ${avg}${loss ? `, ${loss}` : ''}`;
            this.diagnostics.ping = display;
            valueEl.textContent = display;
        } catch (err) {
            valueEl.textContent = this.diagnostics.ping || '—';
            UI.error('Ping failed: ' + err.message);
        } finally {
            button.disabled = false;
        }
    },

    formatUsage(used, total) {
        if (!total) return '—';
        return `${this.formatBytes(used)} / ${this.formatBytes(total)}`;
    },

    formatBytes(value) {
        if (!value) return '—';
        return UI.formatBytes(value);
    },

    formatUptime(seconds) {
        if (!seconds) return '—';
        const days = Math.floor(seconds / 86400);
        const hours = Math.floor((seconds % 86400) / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);

        if (days > 0) {
            return `${days}d ${hours}h`;
        }
        if (hours > 0) {
            return `${hours}h ${minutes}m`;
        }
        return `${minutes}m`;
    },

    formatLoad(system) {
        const { load1, load5, load15 } = system;
        if (!Number.isFinite(load1) || !Number.isFinite(load5) || !Number.isFinite(load15)) {
            return '—';
        }
        return `${load1.toFixed(2)} / ${load5.toFixed(2)} / ${load15.toFixed(2)}`;
    }
};

// Register the tab
registerTab(StatusTab);

export default StatusTab;
