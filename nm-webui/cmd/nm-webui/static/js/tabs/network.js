/**
 * Network Tab Module - Interface management and sharing
 */
import { API, UI, Icons, registerTab } from '../app.js';

const NetworkTab = {
    id: 'network',
    label: 'Network',
    iconName: 'server',
    loaded: false,
    eventsBound: false,
    interfaces: [],
    upstream: '',

    init() {
        // Nothing async to initialize
    },

    render() {
        return `
            <div class="toolbar">
                <h2>Network Interfaces</h2>
                <div class="toolbar-spacer"></div>
                <button class="btn" id="network-refresh">
                    ${Icons.refresh} Refresh
                </button>
            </div>
            <div id="network-content">
                ${UI.loading('Loading interfaces...')}
            </div>
        `;
    },

    onActivate() {
        this.bindEvents();
        
        if (!this.loaded) {
            this.load();
        }
    },

    onDeactivate() {
        // Nothing to cleanup
    },

    bindEvents() {
        if (this.eventsBound) {
            return;
        }
        this.eventsBound = true;

        document.getElementById('network-refresh')?.addEventListener('click', () => this.load(true));

        // Delegate toggle events
        document.getElementById('network-content')?.addEventListener('change', (e) => {
            if (e.target.classList.contains('sharing-toggle')) {
                const device = e.target.dataset.device;
                const enable = e.target.checked;
                this.toggleSharing(device, enable);
            }
        });
    },

    async load(force = false) {
        const container = document.getElementById('network-content');
        if (!container) return;

        if (force) {
            container.innerHTML = UI.loading('Refreshing...');
        }

        try {
            const data = await API.getNetworkInterfaces();
            this.interfaces = data.interfaces || [];
            this.upstream = data.upstream || '';
            this.loaded = true;

            container.innerHTML = this.renderInterfaces();
        } catch (err) {
            container.innerHTML = `<div class="state-message">Error: ${UI.escape(err.message)}</div>`;
            UI.error('Failed to load interfaces: ' + err.message);
        }
    },

    renderInterfaces() {
        if (!this.interfaces.length) {
            return UI.empty('No network interfaces found');
        }

        // Group interfaces by type
        const ethernet = this.interfaces.filter(i => i.type === 'ethernet');
        const wifi = this.interfaces.filter(i => i.type === 'wifi');
        const other = this.interfaces.filter(i => i.type !== 'ethernet' && i.type !== 'wifi');

        let html = '';

        if (ethernet.length > 0) {
            html += this.renderInterfaceGroup('Ethernet / Wired', Icons.ethernet, ethernet);
        }

        if (wifi.length > 0) {
            html += this.renderInterfaceGroup('Wireless', Icons.wifi, wifi);
        }

        if (other.length > 0) {
            html += this.renderInterfaceGroup('Other', Icons.radio, other);
        }

        return html;
    },

    renderInterfaceGroup(title, icon, interfaces) {
        return `
            <div class="card">
                <div class="card-header">
                    <span class="card-title">${icon} ${title}</span>
                    <span class="badge">${interfaces.length}</span>
                </div>
                <div class="card-body">
                    ${interfaces.map(iface => this.renderInterface(iface)).join('')}
                </div>
            </div>
        `;
    },

    renderInterface(iface) {
        const isConnected = iface.state === 'connected';
        const isUpstream = iface.device === this.upstream;
        const canShare = iface.type === 'ethernet' && !isUpstream;
        
        const icon = this.getInterfaceIcon(iface.type, isConnected);
        const stateClass = isConnected ? 'text-success' : 'text-muted';
        
        return `
            <div class="list-item ${isConnected ? 'active' : ''}" data-device="${UI.escape(iface.device)}">
                <div class="item-icon">${icon}</div>
                <div class="item-content">
                    <div class="item-title">
                        ${UI.escape(iface.device)}
                        ${isUpstream ? '<span class="badge badge-success" style="margin-left: 8px; font-size: 0.7em;">Internet</span>' : ''}
                        ${iface.sharing ? '<span class="badge badge-warning" style="margin-left: 8px; font-size: 0.7em;">Sharing</span>' : ''}
                    </div>
                    <div class="item-meta">
                        <span class="item-meta-item">
                            <span class="${stateClass}">● ${UI.escape(iface.state)}</span>
                        </span>
                        ${iface.connection ? `
                            <span class="item-meta-item">
                                <strong>Profile:</strong> ${UI.escape(iface.connection)}
                            </span>
                        ` : ''}
                        ${iface.hwaddr ? `
                            <span class="item-meta-item">
                                <strong>MAC:</strong> ${UI.escape(iface.hwaddr)}
                            </span>
                        ` : ''}
                    </div>
                    ${isConnected ? this.renderIPInfo(iface) : ''}
                </div>
                <div class="item-actions">
                    ${canShare ? `
                        <label class="toggle" title="${iface.sharing ? 'Disable' : 'Enable'} internet sharing">
                            <input type="checkbox" 
                                class="toggle-input sharing-toggle" 
                                data-device="${UI.escape(iface.device)}"
                                ${iface.sharing ? 'checked' : ''}>
                            <span class="toggle-track"><span class="toggle-thumb"></span></span>
                            <span class="toggle-label">Share</span>
                        </label>
                    ` : ''}
                    <button class="btn btn-sm btn-ghost" onclick="NetworkTab.showDetails('${UI.escape(iface.device)}')">
                        Details
                    </button>
                </div>
            </div>
        `;
    },

    renderIPInfo(iface) {
        if (!iface.ip4_address) return '';
        
        return `
            <div class="item-meta" style="margin-top: 4px;">
                <span class="item-meta-item">
                    <strong>IP:</strong> ${UI.escape(iface.ip4_address)}
                </span>
                ${iface.ip4_gateway ? `
                    <span class="item-meta-item">
                        <strong>Gateway:</strong> ${UI.escape(iface.ip4_gateway)}
                    </span>
                ` : ''}
                ${iface.ip4_dns ? `
                    <span class="item-meta-item">
                        <strong>DNS:</strong> ${UI.escape(iface.ip4_dns)}
                    </span>
                ` : ''}
                ${iface.speed ? `
                    <span class="item-meta-item">
                        <strong>Speed:</strong> ${UI.escape(iface.speed)}
                    </span>
                ` : ''}
            </div>
        `;
    },

    getInterfaceIcon(type, connected) {
        if (!connected) return Icons.circle;
        switch (type) {
            case 'ethernet': return Icons.ethernet;
            case 'wifi': return Icons.wifi;
            case 'bridge': return Icons.link;
            default: return Icons.radio;
        }
    },

    async toggleSharing(device, enable) {
        try {
            const result = await API.setInterfaceSharing(device, enable, this.upstream);
            if (result.success) {
                UI.success(result.message || `Sharing ${enable ? 'enabled' : 'disabled'} on ${device}`);
                this.loaded = false;
                this.load();
            } else {
                UI.error(result.message || 'Failed to toggle sharing');
                this.load();
            }
        } catch (err) {
            UI.error('Failed to toggle sharing: ' + err.message);
            this.load();
        }
    },

    showDetails(deviceName) {
        const iface = this.interfaces.find(i => i.device === deviceName);
        if (!iface) return;

        const content = `
            <div class="status-grid">
                <div class="status-card">
                    <div class="status-label">Device</div>
                    <div class="status-value">${UI.escape(iface.device)}</div>
                </div>
                <div class="status-card">
                    <div class="status-label">Type</div>
                    <div class="status-value">${UI.escape(iface.type)}</div>
                </div>
                <div class="status-card">
                    <div class="status-label">State</div>
                    <div class="status-value">${UI.escape(iface.state)}</div>
                </div>
                <div class="status-card">
                    <div class="status-label">Connection</div>
                    <div class="status-value">${UI.escape(iface.connection) || '—'}</div>
                </div>
                <div class="status-card">
                    <div class="status-label">MAC Address</div>
                    <div class="status-value">${UI.escape(iface.hwaddr) || '—'}</div>
                </div>
                <div class="status-card">
                    <div class="status-label">MTU</div>
                    <div class="status-value">${iface.mtu || '—'}</div>
                </div>
                <div class="status-card">
                    <div class="status-label">Driver</div>
                    <div class="status-value">${UI.escape(iface.driver) || '—'}</div>
                </div>
                <div class="status-card">
                    <div class="status-label">Speed</div>
                    <div class="status-value">${UI.escape(iface.speed) || '—'}</div>
                </div>
            </div>
            ${iface.ip4_address ? `
                <h4 style="margin: 16px 0 8px; font-size: 14px; color: var(--color-text-secondary);">IPv4</h4>
                <div class="status-grid">
                    <div class="status-card">
                        <div class="status-label">Address</div>
                        <div class="status-value">${UI.escape(iface.ip4_address)}</div>
                    </div>
                    <div class="status-card">
                        <div class="status-label">Gateway</div>
                        <div class="status-value">${UI.escape(iface.ip4_gateway) || '—'}</div>
                    </div>
                    <div class="status-card">
                        <div class="status-label">DNS</div>
                        <div class="status-value">${UI.escape(iface.ip4_dns) || '—'}</div>
                    </div>
                </div>
            ` : ''}
            ${iface.ip6_address ? `
                <h4 style="margin: 16px 0 8px; font-size: 14px; color: var(--color-text-secondary);">IPv6</h4>
                <div class="status-grid">
                    <div class="status-card">
                        <div class="status-label">Address</div>
                        <div class="status-value" style="font-size: 12px;">${UI.escape(iface.ip6_address)}</div>
                    </div>
                </div>
            ` : ''}
        `;

        UI.modal(`Interface: ${iface.device}`, content, { width: '500px' });
    }
};

// Make showDetails accessible globally for onclick
window.NetworkTab = NetworkTab;

// Register the tab
registerTab(NetworkTab);

export default NetworkTab;
