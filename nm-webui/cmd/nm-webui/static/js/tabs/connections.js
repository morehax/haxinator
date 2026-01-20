/**
 * Connections Tab Module
 */
import { API, UI, Icons, registerTab } from '../app.js';

const ConnectionsTab = {
    id: 'connections',
    label: 'Connections',
    iconName: 'link',
    loaded: false,
    eventsBound: false,
    connections: [],

    init() {
        // Nothing to initialize
    },

    render() {
        return `
            <div class="toolbar">
                <h2>Saved Connections</h2>
                <div class="toolbar-spacer"></div>
                <button class="btn" id="conn-refresh">
                    ${Icons.refresh} Refresh
                </button>
            </div>
            <div class="card">
                <div class="card-header">
                    <span class="card-title">${Icons.link} Connection Profiles</span>
                    <span class="badge" id="conn-count">0</span>
                </div>
                <div class="card-body" id="conn-list">
                    ${UI.loading('Loading connections...')}
                </div>
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

        document.getElementById('conn-refresh')?.addEventListener('click', () => this.load(true));

        // Delegate click events
        document.getElementById('conn-list')?.addEventListener('click', (e) => {
            const action = e.target.closest('[data-action]')?.dataset.action;
            const uuid = e.target.closest('[data-uuid]')?.dataset.uuid;
            
            if (action && uuid) {
                this.handleAction(action, uuid);
            }
        });

        // Handle priority changes
        document.getElementById('conn-list')?.addEventListener('change', (e) => {
            if (e.target.classList.contains('priority-input')) {
                const uuid = e.target.closest('[data-uuid]')?.dataset.uuid;
                const priority = e.target.value;
                if (uuid) {
                    this.setPriority(uuid, priority);
                }
            }
        });
    },

    async load(force = false) {
        const container = document.getElementById('conn-list');
        const countBadge = document.getElementById('conn-count');
        if (!container) return;

        if (force) {
            container.innerHTML = UI.loading('Refreshing...');
        }

        try {
            const data = await API.getConnections();
            this.connections = Array.isArray(data) ? data : (data.connections || []);
            this.loaded = true;

            if (countBadge) {
                countBadge.textContent = this.connections.length;
            }

            container.innerHTML = this.renderConnections();
        } catch (err) {
            container.innerHTML = `<div class="state-message">Error: ${UI.escape(err.message)}</div>`;
            UI.error('Failed to load connections: ' + err.message);
        }
    },

    renderConnections() {
        if (!this.connections.length) {
            return UI.empty('No saved connections');
        }

        // Group by type
        const groups = {};
        this.connections.forEach(conn => {
            const type = conn.type || 'other';
            if (!groups[type]) groups[type] = [];
            groups[type].push(conn);
        });

        // Sort each group by priority (higher first)
        Object.values(groups).forEach(arr => {
            arr.sort((a, b) => (b.priority || 0) - (a.priority || 0));
        });

        // Render grouped
        const typeLabels = {
            '802-11-wireless': { label: 'Wi-Fi', icon: Icons.wifi },
            '802-3-ethernet': { label: 'Ethernet', icon: Icons.ethernet },
            'vpn': { label: 'VPN', icon: Icons.shield },
            'bridge': { label: 'Bridge', icon: Icons.link },
            'other': { label: 'Other', icon: Icons.radio }
        };

        let html = '';
        for (const [type, conns] of Object.entries(groups)) {
            const typeInfo = typeLabels[type] || typeLabels.other;
            html += `
                <div class="list-item" style="background: var(--color-bg-elevated); padding: var(--space-sm) var(--space-lg);">
                    <strong class="text-sm text-secondary" style="display: flex; align-items: center; gap: var(--space-sm);">
                        ${typeInfo.icon} <span class="nowrap">${typeInfo.label}</span>
                    </strong>
                </div>
            `;
            html += conns.map(conn => this.renderConnection(conn)).join('');
        }

        return html;
    },

    renderConnection(conn) {
        const isActive = conn.active;
        const icon = this.getConnectionIcon(conn.type);
        
        return `
            <div class="list-item ${isActive ? 'active' : ''}" data-uuid="${UI.escape(conn.uuid)}">
                <div class="item-icon">${icon}</div>
                <div class="item-content">
                    <div class="item-title">${UI.escape(conn.name)}</div>
                    <div class="item-meta">
                        ${conn.device ? `
                            <span class="item-meta-item">
                                <strong>Device:</strong> ${UI.escape(conn.device)}
                            </span>
                        ` : ''}
                        ${isActive ? `
                            <span class="item-meta-item text-success">● Active</span>
                        ` : ''}
                        <span class="item-meta-item">
                            <strong>UUID:</strong> <code class="text-xs">${UI.escape(conn.uuid?.slice(0, 8))}...</code>
                        </span>
                    </div>
                </div>
                <div class="item-actions">
                    ${conn.type === '802-11-wireless' ? `
                        <div class="toolbar-group" style="margin-right: var(--space-sm);">
                            <label class="text-xs text-muted">Priority:</label>
                            <input type="number" 
                                class="input input-sm priority-input" 
                                value="${conn.priority || 0}" 
                                min="-999" 
                                max="999"
                                title="Higher priority networks are preferred">
                        </div>
                    ` : ''}
                    ${isActive ? `
                        <button class="btn btn-sm" data-action="deactivate">Deactivate</button>
                    ` : `
                        <button class="btn btn-sm btn-primary" data-action="activate">Activate</button>
                    `}
                    ${UI.dropdown([
                        { label: 'View Details', action: 'details', iconName: 'info' },
                        { divider: true },
                        { label: 'Delete', action: 'delete', iconName: 'trash', danger: true }
                    ])}
                </div>
            </div>
        `;
    },

    getConnectionIcon(type) {
        switch (type) {
            case '802-11-wireless': return Icons.wifi;
            case '802-3-ethernet': return Icons.ethernet;
            case 'vpn': return Icons.shield;
            case 'bridge': return Icons.link;
            default: return Icons.radio;
        }
    },

    async handleAction(action, uuid) {
        const conn = this.connections.find(c => c.uuid === uuid);
        
        switch (action) {
            case 'activate':
                await this.activate(uuid);
                break;
            case 'deactivate':
                await this.deactivate(uuid);
                break;
            case 'delete':
                if (await UI.confirm(`Delete connection "${conn?.name}"?`, 'Delete Connection')) {
                    await this.delete(uuid);
                }
                break;
            case 'details':
                this.showDetails(conn);
                break;
        }
    },

    async activate(uuid) {
        try {
            const result = await API.activateConnection(uuid);
            if (result && result.success === false) {
                throw new Error(result.message || 'Activation failed');
            }
            UI.success('Connection activated');
            this.load(true);
        } catch (err) {
            UI.error('Failed to activate: ' + err.message);
        }
    },

    async deactivate(uuid) {
        try {
            const result = await API.deactivateConnection(uuid);
            if (result && result.success === false) {
                throw new Error(result.message || 'Deactivation failed');
            }
            UI.success('Connection deactivated');
            this.load(true);
        } catch (err) {
            UI.error('Failed to deactivate: ' + err.message);
        }
    },

    async delete(uuid) {
        try {
            const result = await API.deleteConnection(uuid);
            if (result && result.success === false) {
                throw new Error(result.message || 'Delete failed');
            }
            UI.success('Connection deleted');
            this.load(true);
        } catch (err) {
            UI.error('Failed to delete: ' + err.message);
        }
    },

    async setPriority(uuid, priority) {
        try {
            await API.setNetworkPriority(uuid, priority);
            UI.success('Priority updated');
        } catch (err) {
            UI.error('Failed to update priority: ' + err.message);
        }
    },

    showDetails(conn) {
        if (!conn) return;

        const content = `
            <div class="status-grid">
                <div class="status-card">
                    <div class="status-label">Name</div>
                    <div class="status-value">${UI.escape(conn.name)}</div>
                </div>
                <div class="status-card">
                    <div class="status-label">Type</div>
                    <div class="status-value">${UI.escape(conn.type)}</div>
                </div>
                <div class="status-card">
                    <div class="status-label">Device</div>
                    <div class="status-value">${UI.escape(conn.device) || '—'}</div>
                </div>
                <div class="status-card">
                    <div class="status-label">Status</div>
                    <div class="status-value ${conn.active ? 'text-success' : ''}">${conn.active ? 'Active' : 'Inactive'}</div>
                </div>
            </div>
            <div class="form-group" style="margin-top: var(--space-lg);">
                <label class="form-label">UUID</label>
                <input type="text" class="input" value="${UI.escape(conn.uuid)}" readonly style="font-family: var(--font-mono); font-size: var(--text-xs);">
            </div>
        `;

        UI.modal(`Connection: ${conn.name}`, content, { width: '400px' });
    }
};

// Register the tab
registerTab(ConnectionsTab);

export default ConnectionsTab;
