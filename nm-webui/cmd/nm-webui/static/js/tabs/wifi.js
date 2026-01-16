/**
 * WiFi Tab Module
 */
import { API, UI, Icons, registerTab, getWifiDevices, getSelectedDevice, setSelectedDevice } from '../app.js';

const WifiTab = {
    id: 'wifi',
    label: 'Wi-Fi',
    iconName: 'wifi',
    loaded: false,
    eventsBound: false,
    networks: [],

    init() {
        // Nothing async to initialize
    },

    render() {
        return `
            <div class="toolbar">
                <div class="toolbar-group">
                    <label for="wifi-device">Interface:</label>
                    <select class="select" id="wifi-device" style="width: auto; min-width: 120px;">
                        <option value="wlan0">wlan0</option>
                    </select>
                </div>
                <div class="toolbar-spacer"></div>
                <button class="btn" id="wifi-scan">
                    ${Icons.refresh} Scan
                </button>
                <button class="btn btn-primary" id="wifi-connect-hidden">
                    ${Icons.plus} Hidden Network
                </button>
                <button class="btn btn-success" id="wifi-hotspot">
                    ${Icons.radio} Hotspot
                </button>
            </div>
            <div class="card">
                <div class="card-header">
                    <span class="card-title">${Icons.wifi} Available Networks</span>
                    <span class="badge" id="wifi-count">0</span>
                </div>
                <div class="card-body" id="wifi-list">
                    ${UI.loading('Select a device and scan...')}
                </div>
            </div>
        `;
    },

    onActivate() {
        this.bindEvents();
        this.updateDeviceSelector();
        
        if (!this.loaded) {
            this.scan();
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

        document.getElementById('wifi-scan')?.addEventListener('click', () => this.scan());
        document.getElementById('wifi-connect-hidden')?.addEventListener('click', () => this.showHiddenModal());
        document.getElementById('wifi-hotspot')?.addEventListener('click', () => this.showHotspotModal());
        document.getElementById('wifi-device')?.addEventListener('change', (e) => {
            setSelectedDevice(e.target.value);
            this.loaded = false;
            this.scan();
        });

        // Delegate click events for network list
        document.getElementById('wifi-list')?.addEventListener('click', (e) => {
            const action = e.target.closest('[data-action]')?.dataset.action;
            const ssid = e.target.closest('[data-ssid]')?.dataset.ssid;
            
            if (action && ssid) {
                this.handleAction(action, ssid);
            }
        });
    },

    updateDeviceSelector() {
        const select = document.getElementById('wifi-device');
        if (!select) return;

        const devices = getWifiDevices();
        const current = getSelectedDevice();

        if (devices.length > 0) {
            select.innerHTML = devices.map(d => 
                `<option value="${UI.escape(d.device)}" ${d.device === current ? 'selected' : ''}>
                    ${UI.escape(d.device)}
                </option>`
            ).join('');
        }
    },

    async scan() {
        const container = document.getElementById('wifi-list');
        const countBadge = document.getElementById('wifi-count');
        if (!container) return;

        container.innerHTML = UI.loading('Scanning...');

        try {
            const device = getSelectedDevice();
            const data = await API.scanWifi(device);
            this.networks = data.networks || [];
            this.loaded = true;

            if (countBadge) {
                countBadge.textContent = this.networks.length;
            }

            container.innerHTML = this.renderNetworks();
        } catch (err) {
            container.innerHTML = `<div class="state-message">Error: ${UI.escape(err.message)}</div>`;
            UI.error('Scan failed: ' + err.message);
        }
    },

    renderNetworks() {
        if (!this.networks.length) {
            return UI.empty('No Wi-Fi networks found');
        }

        return this.networks.map(net => {
            const isConnected = net.in_use === true;
            const securityIcon = net.security && net.security !== '--' && net.security !== 'Open' ? Icons.lock : Icons.unlock;
            const channel = net.chan || net.channel;
            
            return `
                <div class="list-item ${isConnected ? 'active' : ''}" data-ssid="${UI.escape(net.ssid)}">
                    <div class="item-icon">${isConnected ? Icons.checkCircle : Icons.wifi}</div>
                    <div class="item-content">
                        <div class="item-title">${UI.escape(net.ssid) || '<Hidden>'}</div>
                        <div class="item-meta">
                            <span class="item-meta-item">
                                ${UI.signalBars(net.signal)}
                                ${net.signal}%
                            </span>
                            <span class="item-meta-item">
                                ${securityIcon} ${UI.escape(net.security) || 'Open'}
                            </span>
                            ${channel ? `
                                <span class="item-meta-item">Ch ${channel}</span>
                            ` : ''}
                            ${net.band ? `
                                <span class="item-meta-item">${UI.escape(net.band)}</span>
                            ` : ''}
                        </div>
                    </div>
                    <div class="item-actions">
                        ${isConnected ? `
                            <button class="btn btn-sm" data-action="disconnect">Disconnect</button>
                        ` : `
                            <button class="btn btn-sm btn-primary" data-action="connect">Connect</button>
                        `}
                        ${UI.dropdown([
                            { label: 'View Details', action: 'details', iconName: 'info' },
                            { divider: true },
                            { label: 'Forget Network', action: 'forget', iconName: 'trash', danger: true }
                        ])}
                    </div>
                </div>
            `;
        }).join('');
    },

    async handleAction(action, ssid) {
        const network = this.networks.find(n => n.ssid === ssid);
        
        switch (action) {
            case 'connect':
                this.showConnectModal(ssid, network?.security);
                break;
            case 'disconnect':
                await this.disconnect(ssid);
                break;
            case 'forget':
                if (await UI.confirm(`Forget network "${ssid}"?`, 'Forget Network')) {
                    await this.forget(ssid);
                }
                break;
            case 'details':
                this.showDetailsModal(network);
                break;
        }
    },

    showConnectModal(ssid, security) {
        const needsPassword = security && security !== '--' && security !== 'Open';
        
        const content = `
            <form id="connect-form">
                <div class="form-group">
                    <label class="form-label">Network Name (SSID)</label>
                    <input type="text" class="input" name="ssid" value="${UI.escape(ssid)}" readonly>
                </div>
                ${needsPassword ? `
                    <div class="form-group">
                        <label class="form-label">Password</label>
                        <input type="password" class="input" name="password" placeholder="Enter password" required autofocus>
                    </div>
                ` : ''}
            </form>
        `;

        const footer = `
            <button class="btn" data-action="cancel">Cancel</button>
            <button class="btn btn-primary" data-action="submit">Connect</button>
        `;

        const { overlay, close } = UI.modal(`Connect to ${ssid}`, content, { footer });

        overlay.querySelector('[data-action="cancel"]').onclick = close;
        overlay.querySelector('[data-action="submit"]').onclick = async () => {
            const form = overlay.querySelector('#connect-form');
            const password = form.password?.value || '';
            const submitBtn = overlay.querySelector('[data-action="submit"]');
            
            try {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Connecting...';
                
                const result = await API.connectWifi(getSelectedDevice(), ssid, password);
                
                if (result && result.success === false) {
                    throw new Error(result.message || 'Connection failed');
                }
                
                UI.success(`Connected to ${ssid}`);
                close();
                this.loaded = false;
                this.scan();
            } catch (err) {
                UI.error('Failed to connect: ' + err.message);
                submitBtn.disabled = false;
                submitBtn.textContent = 'Connect';
            }
        };
    },

    showHiddenModal() {
        const content = `
            <form id="hidden-form">
                <div class="form-group">
                    <label class="form-label">Network Name (SSID)</label>
                    <input type="text" class="input" name="ssid" placeholder="Enter network name" required autofocus>
                </div>
                <div class="form-group">
                    <label class="form-label">Password</label>
                    <input type="password" class="input" name="password" placeholder="Enter password (leave empty if open)">
                </div>
            </form>
        `;

        const footer = `
            <button class="btn" data-action="cancel">Cancel</button>
            <button class="btn btn-primary" data-action="submit">Connect</button>
        `;

        const { overlay, close } = UI.modal('Connect to Hidden Network', content, { footer });

        overlay.querySelector('[data-action="cancel"]').onclick = close;
        overlay.querySelector('[data-action="submit"]').onclick = async () => {
            const form = overlay.querySelector('#hidden-form');
            const ssid = form.ssid.value.trim();
            const password = form.password.value;
            const submitBtn = overlay.querySelector('[data-action="submit"]');
            
            if (!ssid) {
                UI.warning('Please enter a network name');
                return;
            }

            try {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Connecting...';
                
                const result = await API.connectWifi(getSelectedDevice(), ssid, password, true);
                
                if (result && result.success === false) {
                    throw new Error(result.message || 'Connection failed');
                }
                
                UI.success(`Connected to ${ssid}`);
                close();
                this.loaded = false;
                this.scan();
            } catch (err) {
                UI.error('Failed to connect: ' + err.message);
                submitBtn.disabled = false;
                submitBtn.textContent = 'Connect';
            }
        };
    },

    showHotspotModal() {
        const content = `
            <form id="hotspot-form">
                <div class="form-group">
                    <label class="form-label">Hotspot Name (SSID)</label>
                    <input type="text" class="input" name="ssid" placeholder="MyHotspot" value="RaspberryPi-AP">
                </div>
                <div class="form-group">
                    <label class="form-label">Password</label>
                    <input type="password" class="input" name="password" placeholder="Min 8 characters" minlength="8">
                    <p class="form-hint">Leave empty for open network (not recommended)</p>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Band</label>
                        <select class="select" name="band">
                            <option value="bg">2.4 GHz (bg)</option>
                            <option value="a">5 GHz (a)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Channel</label>
                        <select class="select" name="channel">
                            <option value="0">Auto</option>
                            <option value="1">1</option>
                            <option value="6">6</option>
                            <option value="11">11</option>
                        </select>
                    </div>
                </div>
            </form>
        `;

        const footer = `
            <button class="btn" data-action="cancel">Cancel</button>
            <button class="btn btn-success" data-action="submit">Create Hotspot</button>
        `;

        const { overlay, close } = UI.modal('Create Wi-Fi Hotspot', content, { footer });

        overlay.querySelector('[data-action="cancel"]').onclick = close;
        overlay.querySelector('[data-action="submit"]').onclick = async () => {
            const form = overlay.querySelector('#hotspot-form');
            const config = {
                dev: getSelectedDevice(),
                ssid: form.ssid.value.trim() || 'RaspberryPi-AP',
                password: form.password.value,
                band: form.band.value,
                channel: parseInt(form.channel.value) || 0
            };

            if (config.password && config.password.length < 8) {
                UI.warning('Password must be at least 8 characters');
                return;
            }

            try {
                overlay.querySelector('[data-action="submit"]').disabled = true;
                overlay.querySelector('[data-action="submit"]').textContent = 'Creating...';
                await API.createHotspot(config);
                UI.success('Hotspot created successfully');
            } catch (err) {
                UI.error('Failed to create hotspot: ' + err.message);
                overlay.querySelector('[data-action="submit"]').disabled = false;
                overlay.querySelector('[data-action="submit"]').textContent = 'Create Hotspot';
                return;
            }
            
            close();
            this.loaded = false;
            this.scan();
        };
    },

    showDetailsModal(network) {
        if (!network) return;

        const channel = network.chan || network.channel;
        const content = `
            <div class="status-grid">
                <div class="status-card">
                    <div class="status-label">SSID</div>
                    <div class="status-value">${UI.escape(network.ssid) || '<Hidden>'}</div>
                </div>
                <div class="status-card">
                    <div class="status-label">Signal</div>
                    <div class="status-value">${network.signal}%</div>
                </div>
                <div class="status-card">
                    <div class="status-label">Security</div>
                    <div class="status-value">${UI.escape(network.security) || 'Open'}</div>
                </div>
                <div class="status-card">
                    <div class="status-label">Channel</div>
                    <div class="status-value">${channel || '—'}</div>
                </div>
                <div class="status-card">
                    <div class="status-label">Band</div>
                    <div class="status-value">${UI.escape(network.band) || '—'}</div>
                </div>
                <div class="status-card">
                    <div class="status-label">BSSID</div>
                    <div class="status-value">${UI.escape(network.bssid) || '—'}</div>
                </div>
                <div class="status-card">
                    <div class="status-label">Mode</div>
                    <div class="status-value">${UI.escape(network.mode) || '—'}</div>
                </div>
                <div class="status-card">
                    <div class="status-label">Rate</div>
                    <div class="status-value">${UI.escape(network.rate) || '—'}</div>
                </div>
            </div>
        `;

        UI.modal(`Network: ${network.ssid}`, content, { width: '400px' });
    },

    async disconnect(ssid) {
        try {
            await API.disconnectWifi(ssid);
            UI.success('Disconnected from ' + ssid);
            this.loaded = false;
            this.scan();
        } catch (err) {
            UI.error('Failed to disconnect: ' + err.message);
        }
    },

    async forget(ssid) {
        try {
            await API.forgetNetwork(ssid);
            UI.success(`Forgot network: ${ssid}`);
            this.scan();
        } catch (err) {
            UI.error('Failed to forget network: ' + err.message);
        }
    }
};

// Register the tab
registerTab(WifiTab);

export default WifiTab;
