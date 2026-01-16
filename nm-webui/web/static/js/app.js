// nm-webui - Network Manager Web Interface
// Vanilla JS SPA with polling and minimal footprint

(function() {
    'use strict';

    // State
    const state = {
        currentTab: 'wifi',
        wifiIface: '',
        wifiDevices: [],
        savedConnections: {},
        authHeader: null,
        wifiLoaded: false,
        connectionsLoaded: false,
        statusLoaded: false,
    };

    // API Helper
    async function api(endpoint, options = {}) {
        const url = '/api' + endpoint;
        
        const headers = {
            'Content-Type': 'application/json',
        };
        
        // Add auth header if we have one
        if (state.authHeader) {
            headers['Authorization'] = state.authHeader;
        }
        
        const config = {
            headers,
            ...options,
        };

        try {
            const response = await fetch(url, config);
            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.error || `HTTP ${response.status}`);
            }

            return data;
        } catch (err) {
            console.error('API Error:', err);
            throw err;
        }
    }

    // Toast notifications
    function toast(message, type = 'success') {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = `
            <span class="toast-icon">${type === 'success' ? '✓' : '✕'}</span>
            <span class="toast-message">${escapeHtml(message)}</span>
            <button class="toast-close">&times;</button>
        `;

        toast.querySelector('.toast-close').onclick = () => toast.remove();
        container.appendChild(toast);

        setTimeout(() => toast.remove(), 4000);
    }

    // Escape HTML for XSS prevention
    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // Tab switching
    function initTabs() {
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', () => {
                const tabId = tab.dataset.tab;

                // Update active states
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

                tab.classList.add('active');
                document.getElementById(`${tabId}-tab`).classList.add('active');

                state.currentTab = tabId;

                // Load tab data only if not already loaded
                if (tabId === 'wifi' && !state.wifiLoaded) loadWifiNetworks(true);
                else if (tabId === 'connections' && !state.connectionsLoaded) loadConnections();
                else if (tabId === 'status' && !state.statusLoaded) loadStatus();
                else if (tabId === 'logs') loadLogs();
            });
        });
    }

    // Modal handling
    function initModals() {
        const overlay = document.getElementById('modal-overlay');

        // Close buttons
        document.querySelectorAll('[data-close]').forEach(btn => {
            btn.addEventListener('click', closeModal);
        });

        // Click outside modal
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) closeModal();
        });

        // ESC key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeModal();
        });
    }

    function openModal(modalId) {
        const overlay = document.getElementById('modal-overlay');
        const modal = document.getElementById(modalId);

        overlay.classList.remove('hidden');
        modal.classList.remove('hidden');

        // Focus first input
        const input = modal.querySelector('input:not([type="hidden"]):not([readonly])');
        if (input) setTimeout(() => input.focus(), 100);
    }

    function closeModal() {
        const overlay = document.getElementById('modal-overlay');
        overlay.classList.add('hidden');
        document.querySelectorAll('.modal').forEach(m => m.classList.add('hidden'));
    }

    // Clock
    function updateClock() {
        const el = document.getElementById('clock');
        const now = new Date();
        el.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
    }

    // Signal bars
    function signalBars(signal) {
        const strength = signal >= 70 ? 'strong' : signal >= 40 ? 'medium' : 'weak';
        let bars = '';
        for (let i = 1; i <= 4; i++) {
            const active = signal >= (i * 20) ? 'active' : '';
            bars += `<span class="signal-bar ${active}" style="height: ${6 + i * 3}px"></span>`;
        }
        return `<span class="signal-${strength}">${bars}</span> ${signal}%`;
    }

    // Security icon
    function securityIcon(security) {
        if (security === 'OPEN' || !security) return '🔓';
        if (security.includes('WPA3')) return '🔐';
        if (security.includes('WPA')) return '🔒';
        return '🔒';
    }

    // ==================== WiFi Tab ====================

    async function loadWifiNetworks(rescan = false) {
        const list = document.getElementById('wifi-list');
        const wiredList = document.getElementById('wired-list');
        const countEl = document.getElementById('network-count');
        const ifaceSelect = document.getElementById('wifi-iface');

        // Only show loading indicator when actually rescanning
        if (rescan) {
            list.innerHTML = '<div class="loading">Scanning...</div>';
        }

        try {
            const res = await api(`/wifi/scan?dev=${state.wifiIface}&rescan=${rescan ? 'yes' : 'no'}`);
            const { networks, saved, wired, iface } = res.data;

            state.savedConnections = saved || {};
            state.wifiIface = iface;

            // Update interface dropdown if we have new devices
            if (networks && networks.length > 0) {
                const devices = [...new Set(networks.map(n => n.device))];
                if (devices.length > 0 && ifaceSelect.options.length === 0) {
                    devices.forEach(dev => {
                        const opt = document.createElement('option');
                        opt.value = dev;
                        opt.textContent = dev;
                        if (dev === iface) opt.selected = true;
                        ifaceSelect.appendChild(opt);
                    });
                }
            }

            // Render networks
            if (!networks || networks.length === 0) {
                list.innerHTML = '<div class="empty">No networks found</div>';
                countEl.textContent = '0';
            } else {
                countEl.textContent = networks.length;
                list.innerHTML = networks.map(net => renderNetworkItem(net)).join('');
            }

            // Render wired connections
            if (!wired || wired.length === 0) {
                wiredList.innerHTML = '<div class="empty">No wired/USB connections</div>';
            } else {
                wiredList.innerHTML = wired.map(w => renderWiredItem(w)).join('');
            }

            attachNetworkEvents();
            attachWiredEvents();
            state.wifiLoaded = true;

        } catch (err) {
            list.innerHTML = `<div class="empty text-danger">Error: ${escapeHtml(err.message)}</div>`;
            toast('Failed to scan WiFi: ' + err.message, 'error');
        }
    }

    function renderNetworkItem(net) {
        const saved = state.savedConnections[net.ssid];
        const isConnected = net.in_use;
        const isHotspot = net.is_hotspot;
        const classes = [isConnected ? 'connected' : '', isHotspot ? 'hotspot' : ''].filter(Boolean).join(' ');

        let icon = '📶';
        if (isHotspot) icon = '🌐';
        else if (isConnected) icon = '✓';

        return `
            <div class="network-item ${classes}" data-ssid="${escapeHtml(net.ssid)}" data-bssid="${net.bssid}" data-hotspot="${isHotspot}">
                <span class="network-icon">${icon}</span>
                <div class="network-info">
                    <div class="network-name">${escapeHtml(net.ssid || '(Hidden Network)')}</div>
                    <div class="network-meta">
                        <span class="network-signal">${signalBars(net.signal)}</span>
                        <span>${securityIcon(net.security)} ${net.security}</span>
                        <span>${net.band} CH${net.chan}</span>
                        ${saved ? `<span title="Auto-connect priority">⭐ ${saved.pri}</span>` : ''}
                    </div>
                </div>
                <div class="network-actions">
                    ${isConnected ? `
                        <button class="btn btn-sm btn-danger disconnect-btn">Disconnect</button>
                        ${saved ? `<button class="btn btn-sm forget-btn">Forget</button>` : ''}
                    ` : `
                        <button class="btn btn-sm btn-primary connect-btn">Connect</button>
                        ${saved ? `
                            <input type="number" class="input priority-input" value="${saved.pri}" 
                                   min="-100" max="100" title="Auto-connect priority" data-uuid="${saved.uuid}">
                            <button class="btn btn-sm forget-btn">Forget</button>
                        ` : ''}
                    `}
                </div>
            </div>
        `;
    }

    function renderWiredItem(conn) {
        const isShared = conn.method === 'shared';
        return `
            <div class="network-item" data-uuid="${conn.uuid}">
                <span class="network-icon">${conn.device ? '🔌' : '📱'}</span>
                <div class="network-info">
                    <div class="network-name">${escapeHtml(conn.name)}</div>
                    <div class="network-meta">
                        <span>${conn.device || 'Not connected'}</span>
                        <span>${isShared ? '🌐 Sharing enabled' : 'Not sharing'}</span>
                    </div>
                </div>
                <div class="network-actions share-toggle">
                    <span class="text-muted">${isShared ? 'On' : 'Off'}</span>
                    <label class="toggle">
                        <input type="checkbox" class="share-checkbox" ${isShared ? 'checked' : ''}>
                        <span class="toggle-slider"></span>
                    </label>
                </div>
            </div>
        `;
    }

    function attachNetworkEvents() {
        // Connect buttons
        document.querySelectorAll('.connect-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const item = e.target.closest('.network-item');
                const ssid = item.dataset.ssid;

                document.getElementById('connect-ssid').value = ssid;
                document.getElementById('connect-ssid-hidden').value = ssid;
                document.getElementById('connect-password').value = '';
                document.getElementById('connect-hidden').checked = false;

                openModal('connect-modal');
            });
        });

        // Disconnect buttons
        document.querySelectorAll('.disconnect-btn').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                const item = e.target.closest('.network-item');
                const ssid = item.dataset.ssid;
                const isHotspot = item.dataset.hotspot === 'true';

                btn.disabled = true;
                try {
                    const res = await api('/wifi/disconnect', {
                        method: 'POST',
                        body: JSON.stringify({ ssid, is_hotspot: isHotspot }),
                    });
                    toast(res.data.message || 'Disconnected');
                    setTimeout(() => loadWifiNetworks(), 500);
                } catch (err) {
                    toast('Disconnect failed: ' + err.message, 'error');
                }
                btn.disabled = false;
            });
        });

        // Forget buttons
        document.querySelectorAll('.forget-btn').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                const item = e.target.closest('.network-item');
                const ssid = item.dataset.ssid;
                const isHotspot = item.dataset.hotspot === 'true';

                if (!confirm(`Forget network "${ssid}"?`)) return;

                btn.disabled = true;
                try {
                    const res = await api('/wifi/forget', {
                        method: 'POST',
                        body: JSON.stringify({ ssid, is_hotspot: isHotspot }),
                    });
                    toast(res.data.message || 'Network forgotten');
                    setTimeout(() => loadWifiNetworks(), 500);
                } catch (err) {
                    toast('Failed to forget: ' + err.message, 'error');
                }
                btn.disabled = false;
            });
        });

        // Priority inputs
        document.querySelectorAll('.priority-input').forEach(input => {
            let timeout;
            input.addEventListener('change', async (e) => {
                clearTimeout(timeout);
                const uuid = e.target.dataset.uuid;
                const priority = parseInt(e.target.value, 10);

                timeout = setTimeout(async () => {
                    try {
                        await api('/wifi/priority', {
                            method: 'POST',
                            body: JSON.stringify({ uuid, priority }),
                        });
                        toast('Priority saved');
                    } catch (err) {
                        toast('Failed to set priority: ' + err.message, 'error');
                    }
                }, 500);
            });
        });
    }

    function attachWiredEvents() {
        document.querySelectorAll('.share-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', async (e) => {
                const item = e.target.closest('.network-item');
                const uuid = item.dataset.uuid;
                const enable = e.target.checked;

                try {
                    await api('/connections/share', {
                        method: 'POST',
                        body: JSON.stringify({ uuid, enable }),
                    });
                    toast(enable ? 'Sharing enabled' : 'Sharing disabled');
                    setTimeout(() => loadWifiNetworks(false), 500);
                } catch (err) {
                    toast('Failed to toggle sharing: ' + err.message, 'error');
                    e.target.checked = !enable;
                }
            });
        });
    }

    // Connect form submission
    document.getElementById('connect-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = e.target;
        const submitBtn = form.closest('.modal').querySelector('[type="submit"]');

        const ssid = form.ssid.value;
        const password = form.password.value;
        const hidden = form.hidden.checked;

        submitBtn.disabled = true;
        submitBtn.textContent = 'Connecting...';

        try {
            const res = await api('/wifi/connect', {
                method: 'POST',
                body: JSON.stringify({
                    dev: state.wifiIface,
                    ssid,
                    password,
                    hidden,
                }),
            });

            if (res.data.success) {
                toast(res.data.message || 'Connected!');
                closeModal();
                setTimeout(() => loadWifiNetworks(), 1000);
            } else {
                toast(res.data.message || 'Connection failed', 'error');
            }
        } catch (err) {
            toast('Connection failed: ' + err.message, 'error');
        }

        submitBtn.disabled = false;
        submitBtn.textContent = 'Connect';
    });

    // Hotspot form submission
    document.getElementById('hotspot-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = e.target;
        const submitBtn = form.closest('.modal').querySelector('[type="submit"]');

        submitBtn.disabled = true;
        submitBtn.textContent = 'Starting...';

        try {
            const res = await api('/wifi/hotspot', {
                method: 'POST',
                body: JSON.stringify({
                    mode: 'start',
                    dev: state.wifiIface,
                    ssid: form.ssid.value,
                    password: form.password.value,
                    band: form.band.value,
                    channel: parseInt(form.channel.value, 10) || 0,
                    con_name: form.con_name.value,
                    ip_range: form.ip_range.value,
                    persistent: form.persistent.checked,
                }),
            });

            if (res.data.success) {
                toast(res.data.message || 'Hotspot started!');
                closeModal();
                setTimeout(() => loadWifiNetworks(), 1000);
            } else {
                toast(res.data.message || 'Failed to start hotspot', 'error');
            }
        } catch (err) {
            toast('Hotspot failed: ' + err.message, 'error');
        }

        submitBtn.disabled = false;
        submitBtn.textContent = 'Start Hotspot';
    });

    // ==================== Connections Tab ====================

    async function loadConnections() {
        const list = document.getElementById('conn-list');
        const countEl = document.getElementById('conn-count');

        list.innerHTML = '<div class="loading">Loading...</div>';

        try {
            const res = await api('/connections');
            const conns = res.data;

            if (!conns || conns.length === 0) {
                list.innerHTML = '<div class="empty">No saved connections</div>';
                countEl.textContent = '0';
            } else {
                countEl.textContent = conns.length;
                list.innerHTML = conns.map(renderConnectionItem).join('');
                attachConnectionEvents();
            }
            state.connectionsLoaded = true;
        } catch (err) {
            list.innerHTML = `<div class="empty text-danger">Error: ${escapeHtml(err.message)}</div>`;
        }
    }

    function renderConnectionItem(conn) {
        const icon = getConnectionIcon(conn.type);
        const isActive = conn.active;

        return `
            <div class="connection-item ${isActive ? 'connected' : ''}" data-uuid="${conn.uuid}">
                <span class="connection-icon">${icon}</span>
                <div class="connection-info">
                    <div class="connection-name">${escapeHtml(conn.name)}</div>
                    <div class="connection-meta">
                        <span>${conn.type}</span>
                        ${conn.device ? `<span>📍 ${conn.device}</span>` : ''}
                        ${conn.autoconnect ? '<span>🔄 Auto</span>' : ''}
                        ${isActive ? '<span class="text-success">● Active</span>' : ''}
                    </div>
                </div>
                <div class="connection-actions">
                    ${isActive ? `
                        <button class="btn btn-sm btn-warning deactivate-btn">Deactivate</button>
                    ` : `
                        <button class="btn btn-sm btn-primary activate-btn">Activate</button>
                    `}
                    <button class="btn btn-sm btn-danger delete-btn">Delete</button>
                </div>
            </div>
        `;
    }

    function getConnectionIcon(type) {
        if (type.includes('wireless')) return '📶';
        if (type.includes('ethernet')) return '🔌';
        if (type.includes('vpn')) return '🔐';
        if (type.includes('bluetooth')) return '📱';
        return '🌐';
    }

    function attachConnectionEvents() {
        // Activate
        document.querySelectorAll('.activate-btn').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                const item = e.target.closest('.connection-item');
                const uuid = item.dataset.uuid;

                btn.disabled = true;
                try {
                    const res = await api('/connections/activate', {
                        method: 'POST',
                        body: JSON.stringify({ uuid }),
                    });
                    toast(res.data.message || 'Activated');
                    setTimeout(loadConnections, 500);
                } catch (err) {
                    toast('Activation failed: ' + err.message, 'error');
                }
                btn.disabled = false;
            });
        });

        // Deactivate
        document.querySelectorAll('.deactivate-btn').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                const item = e.target.closest('.connection-item');
                const uuid = item.dataset.uuid;

                btn.disabled = true;
                try {
                    const res = await api('/connections/deactivate', {
                        method: 'POST',
                        body: JSON.stringify({ uuid }),
                    });
                    toast(res.data.message || 'Deactivated');
                    setTimeout(loadConnections, 500);
                } catch (err) {
                    toast('Deactivation failed: ' + err.message, 'error');
                }
                btn.disabled = false;
            });
        });

        // Delete
        document.querySelectorAll('.delete-btn').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                const item = e.target.closest('.connection-item');
                const uuid = item.dataset.uuid;
                const name = item.querySelector('.connection-name').textContent;

                if (!confirm(`Delete connection "${name}"?`)) return;

                btn.disabled = true;
                try {
                    const res = await api(`/connections/delete/${uuid}`, {
                        method: 'DELETE',
                    });
                    toast(res.data.message || 'Deleted');
                    setTimeout(loadConnections, 500);
                } catch (err) {
                    toast('Delete failed: ' + err.message, 'error');
                }
                btn.disabled = false;
            });
        });
    }

    // ==================== Status Tab ====================

    async function loadStatus() {
        const statusEl = document.getElementById('status-info');
        const deviceEl = document.getElementById('device-list');

        try {
            const res = await api('/status');
            const status = res.data;

            // Update header
            document.getElementById('hostname').textContent = status.hostname;

            // Render status info
            statusEl.innerHTML = `
                <div class="status-card">
                    <div class="status-label">Hostname</div>
                    <div class="status-value">${escapeHtml(status.hostname)}</div>
                </div>
                <div class="status-card">
                    <div class="status-label">Time</div>
                    <div class="status-value">${new Date(status.time).toLocaleString()}</div>
                </div>
                <div class="status-card">
                    <div class="status-label">NetworkManager</div>
                    <div class="status-value">${escapeHtml(status.nm_version)}</div>
                </div>
            `;

            // Render devices
            if (!status.devices || status.devices.length === 0) {
                deviceEl.innerHTML = '<div class="empty">No network devices</div>';
            } else {
                deviceEl.innerHTML = status.devices.map(dev => `
                    <div class="device-item">
                        <span class="device-icon">${getDeviceIcon(dev.type)}</span>
                        <div class="device-info">
                            <div class="device-name">${escapeHtml(dev.name)}</div>
                            <div class="device-meta">
                                <span>${dev.type}</span>
                                <span class="${dev.state === 'connected' ? 'text-success' : ''}">${dev.state}</span>
                                ${dev.connection ? `<span>🔗 ${escapeHtml(dev.connection)}</span>` : ''}
                                ${dev.ipv4 ? `<span>📍 ${dev.ipv4}</span>` : ''}
                            </div>
                        </div>
                    </div>
                `).join('');
            }

            // Update WiFi interface selector
            if (status.wifi_devices && status.wifi_devices.length > 0) {
                const select = document.getElementById('wifi-iface');
                if (select.options.length === 0) {
                    status.wifi_devices.forEach(dev => {
                        const opt = document.createElement('option');
                        opt.value = dev;
                        opt.textContent = dev;
                        select.appendChild(opt);
                    });
                    state.wifiIface = status.wifi_devices[0];
                }
            }
            state.statusLoaded = true;
        } catch (err) {
            statusEl.innerHTML = `<div class="empty text-danger">Error: ${escapeHtml(err.message)}</div>`;
        }
    }

    function getDeviceIcon(type) {
        if (type.includes('wifi')) return '📶';
        if (type.includes('ethernet')) return '🔌';
        if (type.includes('loopback')) return '🔄';
        if (type.includes('bridge')) return '🌉';
        return '🌐';
    }

    // ==================== Logs Tab ====================

    async function loadLogs() {
        const list = document.getElementById('log-list');

        try {
            const res = await api('/log');
            const logs = res.data;

            if (!logs || logs.length === 0) {
                list.innerHTML = '<div class="empty">No log entries</div>';
            } else {
                list.innerHTML = logs.map(log => `
                    <div class="log-item">
                        <div class="log-header">
                            <span class="log-time">${new Date(log.time).toLocaleString()}</span>
                            <span class="log-action ${log.success ? 'success' : 'error'}">${escapeHtml(log.action)}</span>
                        </div>
                        <div class="log-detail">${escapeHtml(log.detail)}</div>
                    </div>
                `).join('');
            }
        } catch (err) {
            list.innerHTML = `<div class="empty text-danger">Error: ${escapeHtml(err.message)}</div>`;
        }
    }

    // ==================== Init ====================

    async function init() {
        console.log('nm-webui initializing...');

        // Init UI components
        initTabs();
        initModals();

        // Clock
        updateClock();
        setInterval(updateClock, 1000);

        // Button handlers
        document.getElementById('scan-btn').addEventListener('click', () => {
            loadWifiNetworks(true);
        });

        document.getElementById('hotspot-btn').addEventListener('click', () => {
            openModal('hotspot-modal');
        });

        document.getElementById('refresh-conn-btn').addEventListener('click', loadConnections);

        document.getElementById('clear-logs-btn').addEventListener('click', () => {
            document.getElementById('log-list').innerHTML = '<div class="empty">No log entries</div>';
        });

        document.getElementById('wifi-iface').addEventListener('change', (e) => {
            state.wifiIface = e.target.value;
            loadWifiNetworks(true);
        });

        // Initial load
        try {
            await loadStatus();
            await loadWifiNetworks(true);
        } catch (err) {
            console.error('Initial load failed:', err);
        }

        console.log('nm-webui ready');
    }

    // Start when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
