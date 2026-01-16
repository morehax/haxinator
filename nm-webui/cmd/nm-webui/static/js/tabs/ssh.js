/**
 * SSH Tab Module - Manages SSH keys and tunnels
 */
import { API, UI, Icons, registerTab } from '../app.js';

const SSHTab = {
    id: 'ssh',
    label: 'SSH',
    iconName: 'key',
    keys: [],
    tunnels: [],
    keysLoaded: false,
    tunnelsLoaded: false,
    autoRefreshInterval: null,
    eventsBound: false,

    init() {
        // Event binding will happen after render
    },

    render() {
        return `
            <div class="toolbar">
                <h2>SSH Management</h2>
            </div>
            <div id="ssh-tab-content">
                ${this.renderBase()}
            </div>
        `;
    },

    onActivate() {
        this.bindEvents();
        if (!this.keysLoaded || !this.tunnelsLoaded) {
            this.loadKeys();
            this.loadTunnels();
        }
        this.startAutoRefresh();
    },

    onDeactivate() {
        this.stopAutoRefresh();
    },

    bindEvents() {
        if (this.eventsBound) {
            return;
        }
        this.eventsBound = true;

        document.addEventListener('click', (e) => {
            const subTab = e.target.closest('[data-ssh-tab]');
            if (subTab) {
                this.switchSubTab(subTab.dataset.sshTab);
                return;
            }

            const keyAction = e.target.closest('[data-key-action]');
            if (keyAction) {
                const action = keyAction.dataset.keyAction;
                const name = keyAction.dataset.name;
                this.handleKeyAction(action, name);
                return;
            }

            const tunnelAction = e.target.closest('[data-tunnel-action]');
            if (tunnelAction) {
                const action = tunnelAction.dataset.tunnelAction;
                const id = tunnelAction.dataset.id;
                this.handleTunnelAction(action, id);
                return;
            }
        });

        document.addEventListener('change', (e) => {
            if (e.target.id === 'ssh-key-file') {
                this.uploadKey(e.target.files[0]);
            }
        });
    },

    renderBase() {
        return `
            <div class="card">
                <div class="card-header">
                    <div class="ssh-tabs">
                        <button class="ssh-tab-btn active" data-ssh-tab="tunnels">${Icons.link} Tunnels</button>
                        <button class="ssh-tab-btn" data-ssh-tab="keys">${Icons.key} Keys</button>
                    </div>
                    <div class="card-actions" id="ssh-tunnels-actions">
                        <button class="btn btn-sm" data-tunnel-action="refresh">${Icons.refresh} Refresh</button>
                        <button class="btn btn-sm btn-primary" data-tunnel-action="new">${Icons.plus} New Tunnel</button>
                    </div>
                    <div class="card-actions" id="ssh-keys-actions" style="display: none;">
                        <button class="btn btn-sm" data-key-action="refresh">${Icons.refresh} Refresh</button>
                        <label class="btn btn-sm btn-secondary">
                            ${Icons.upload} Upload
                            <input type="file" id="ssh-key-file" hidden accept=".pem,.key,.ppk,*">
                        </label>
                        <button class="btn btn-sm btn-primary" data-key-action="generate">${Icons.plus} Generate</button>
                    </div>
                </div>
                <div class="card-body">
                    <div id="ssh-tunnels-panel">
                        <div id="tunnel-list" class="list-group">
                            ${UI.loading('Loading tunnels...')}
                        </div>
                    </div>
                    <div id="ssh-keys-panel" style="display: none;">
                        <div id="key-list" class="list-group">
                            ${UI.loading('Loading keys...')}
                        </div>
                    </div>
                </div>
            </div>
        `;
    },

    switchSubTab(tab) {
        document.querySelectorAll('.ssh-tab-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.sshTab === tab);
        });

        document.getElementById('ssh-tunnels-panel').style.display = tab === 'tunnels' ? '' : 'none';
        document.getElementById('ssh-keys-panel').style.display = tab === 'keys' ? '' : 'none';
        document.getElementById('ssh-tunnels-actions').style.display = tab === 'tunnels' ? '' : 'none';
        document.getElementById('ssh-keys-actions').style.display = tab === 'keys' ? '' : 'none';
    },

    // ========== Keys ==========

    async loadKeys(force = false) {
        const container = document.getElementById('key-list');
        if (!container) return;

        if (force) {
            container.innerHTML = UI.loading('Refreshing...');
        }

        try {
            const data = await API.getSSHKeys();
            this.keys = data.keys || [];
            this.keysLoaded = true;
            container.innerHTML = this.renderKeys();
        } catch (err) {
            container.innerHTML = `<div class="state-message state-error">Error: ${UI.escape(err.message)}</div>`;
            UI.error('Failed to load keys: ' + err.message);
        }
    },

    renderKeys() {
        if (this.keys.length === 0) {
            return '<div class="state-message">No SSH keys found. Upload or generate a key to get started.</div>';
        }

        return this.keys.map(key => `
            <div class="list-item">
                <div class="list-item-content">
                    <div class="list-item-title">
                        <span class="key-icon">${Icons.key}</span>
                        ${UI.escape(key.name)}
                    </div>
                    <div class="list-item-meta">
                        <span class="badge">${UI.escape(key.type || 'unknown')}</span>
                        ${key.has_pub_key ? '<span class="badge badge-success">Has .pub</span>' : ''}
                    </div>
                </div>
                <div class="list-item-actions">
                    ${key.has_pub_key ? `
                        <button class="btn btn-sm" data-key-action="download" data-name="${UI.escape(key.name)}" title="Download public key">
                            ${Icons.download}
                        </button>
                        <button class="btn btn-sm" data-key-action="copy" data-name="${UI.escape(key.name)}" title="Copy public key">
                            ${Icons.copy}
                        </button>
                    ` : ''}
                    <button class="btn btn-sm btn-danger" data-key-action="delete" data-name="${UI.escape(key.name)}" title="Delete key">
                        ${Icons.trash}
                    </button>
                </div>
            </div>
        `).join('');
    },

    async handleKeyAction(action, name) {
        switch (action) {
            case 'refresh':
                await this.loadKeys(true);
                break;
            case 'generate':
                this.showGenerateKeyModal();
                break;
            case 'delete':
                await this.deleteKey(name);
                break;
            case 'download':
                this.downloadPublicKey(name);
                break;
            case 'copy':
                await this.copyPublicKey(name);
                break;
        }
    },

    async uploadKey(file) {
        if (!file) return;

        UI.showSpinner('Uploading key...');
        try {
            await API.uploadSSHKey(file);
            UI.success('Key uploaded: ' + file.name);
            await this.loadKeys(true);
        } catch (err) {
            UI.error('Upload failed: ' + err.message);
        } finally {
            UI.hideSpinner();
            const input = document.getElementById('ssh-key-file');
            if (input) input.value = '';
        }
    },

    async deleteKey(name) {
        if (!confirm(`Delete key "${name}"? This cannot be undone.`)) return;

        UI.showSpinner('Deleting...');
        try {
            await API.deleteSSHKey(name);
            UI.success('Key deleted');
            await this.loadKeys(true);
            this.populateKeySelect();
        } catch (err) {
            UI.error('Delete failed: ' + err.message);
        } finally {
            UI.hideSpinner();
        }
    },

    downloadPublicKey(name) {
        const url = API.getSSHPublicKeyDownloadUrl(name);
        const a = document.createElement('a');
        a.href = url;
        a.download = name + '.pub';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    },

    async copyPublicKey(name) {
        try {
            const data = await API.getSSHPublicKey(name);
            await navigator.clipboard.writeText(data.content);
            UI.success('Public key copied to clipboard');
        } catch (err) {
            UI.error('Failed to copy: ' + err.message);
        }
    },

    showGenerateKeyModal() {
        const modal = UI.modal({
            title: 'Generate SSH Key Pair',
            content: `
                <form id="generate-key-form" class="form-stack">
                    <div class="form-group">
                        <label for="gen-key-name">Key Name</label>
                        <input type="text" id="gen-key-name" class="input" placeholder="my-key" 
                               pattern="[a-zA-Z0-9_-]+" required>
                        <small class="form-hint">Letters, numbers, underscore, dash only</small>
                    </div>
                    <div class="form-group">
                        <label for="gen-key-type">Key Type</label>
                        <select id="gen-key-type" class="select">
                            <option value="ed25519">Ed25519 (recommended)</option>
                            <option value="rsa">RSA</option>
                            <option value="ecdsa">ECDSA</option>
                        </select>
                    </div>
                    <div class="form-group" id="rsa-bits-group" style="display: none;">
                        <label for="gen-key-bits">Key Size</label>
                        <select id="gen-key-bits" class="select">
                            <option value="2048">2048 bits</option>
                            <option value="3072">3072 bits</option>
                            <option value="4096" selected>4096 bits</option>
                        </select>
                    </div>
                </form>
            `,
            buttons: [
                { text: 'Cancel', className: 'btn' },
                { text: 'Generate', className: 'btn btn-primary', action: () => this.submitGenerateKey() }
            ]
        });

        document.getElementById('gen-key-type').addEventListener('change', (e) => {
            document.getElementById('rsa-bits-group').style.display = 
                e.target.value === 'rsa' ? '' : 'none';
        });
    },

    async submitGenerateKey() {
        const name = document.getElementById('gen-key-name').value.trim();
        const type = document.getElementById('gen-key-type').value;
        const bits = parseInt(document.getElementById('gen-key-bits').value);

        if (!name || !/^[a-zA-Z0-9_-]+$/.test(name)) {
            UI.error('Invalid key name');
            return;
        }

        UI.closeModal();
        UI.showSpinner('Generating key...');

        try {
            const result = await API.generateSSHKey(name, type, bits);
            this.showPublicKeyModal(result);
            await this.loadKeys(true);
        } catch (err) {
            UI.error('Generation failed: ' + err.message);
        } finally {
            UI.hideSpinner();
        }
    },

    showPublicKeyModal(result) {
        UI.modal({
            title: 'Key Generated Successfully',
            content: `
                <div class="alert alert-success">${Icons.checkCircle} SSH key pair created!</div>
                <p><strong>Private Key:</strong> <code>${UI.escape(result.private_key)}</code></p>
                <p><strong>Public Key:</strong> <code>${UI.escape(result.public_key)}</code></p>
                <div class="form-group">
                    <label>Public Key (copy this to remote servers):</label>
                    <textarea id="pub-key-content" class="input" rows="3" readonly>${UI.escape(result.public_key_content)}</textarea>
                    <button class="btn btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('pub-key-content').value).then(() => { this.textContent = 'Copied!'; setTimeout(() => this.textContent = 'Copy', 2000); })">
                        ${Icons.copy} Copy
                    </button>
                </div>
            `,
            buttons: [
                { text: 'Done', className: 'btn btn-primary' }
            ]
        });
    },

    // ========== Tunnels ==========

    async loadTunnels(force = false) {
        const container = document.getElementById('tunnel-list');
        if (!container) return;

        if (force && !this.tunnelsLoaded) {
            container.innerHTML = UI.loading('Loading tunnels...');
        }

        try {
            const data = await API.getSSHTunnels();
            this.tunnels = data.tunnels || [];
            this.tunnelsLoaded = true;
            container.innerHTML = this.renderTunnels();
        } catch (err) {
            container.innerHTML = `<div class="state-message state-error">Error: ${UI.escape(err.message)}</div>`;
        }
    },

    renderTunnels() {
        if (this.tunnels.length === 0) {
            return '<div class="state-message">No tunnels configured. Click "New Tunnel" to create one.</div>';
        }

        return this.tunnels.map(tunnel => {
            const isRunning = tunnel.status === 'running';
            const statusIcon = isRunning ? Icons.checkCircle : Icons.circle;
            const statusClass = isRunning ? 'text-success' : 'text-muted';

            let mapping = '';
            switch (tunnel.fwd) {
                case 'L':
                    mapping = `localhost:${tunnel.lport} → ${tunnel.rhost}:${tunnel.rport}`;
                    break;
                case 'R':
                    mapping = `remote:${tunnel.rport} → localhost:${tunnel.lport}`;
                    break;
                case 'D':
                    mapping = `SOCKS proxy on :${tunnel.lport}`;
                    break;
            }

            const fwdTypeLabel = { L: 'Local', R: 'Remote', D: 'SOCKS' }[tunnel.fwd] || tunnel.fwd;

            return `
                <div class="list-item ${isRunning ? '' : 'list-item-muted'}">
                    <div class="list-item-content">
                        <div class="list-item-title">
                            <span class="status-indicator ${statusClass}">${statusIcon}</span>
                            ${UI.escape(tunnel.user)}@${UI.escape(tunnel.host)}
                        </div>
                        <div class="list-item-meta">
                            <span class="badge">${fwdTypeLabel}</span>
                            <span class="tunnel-mapping">${mapping}</span>
                            ${tunnel.pid ? `<span class="badge badge-muted">PID: ${tunnel.pid}</span>` : ''}
                        </div>
                    </div>
                    <div class="list-item-actions">
                        ${isRunning ? `
                            <button class="btn btn-sm btn-warning" data-tunnel-action="stop" data-id="${tunnel.id}">
                                ${Icons.stop} Stop
                            </button>
                        ` : `
                            <button class="btn btn-sm btn-success" data-tunnel-action="start" data-id="${tunnel.id}">
                                ${Icons.play} Start
                            </button>
                        `}
                        <button class="btn btn-sm btn-danger" data-tunnel-action="delete" data-id="${tunnel.id}">
                            ${Icons.trash}
                        </button>
                    </div>
                </div>
            `;
        }).join('');
    },

    async handleTunnelAction(action, id) {
        switch (action) {
            case 'refresh':
                await this.loadTunnels(true);
                break;
            case 'new':
                this.showNewTunnelModal();
                break;
            case 'start':
                await this.startTunnel(id);
                break;
            case 'stop':
                await this.stopTunnel(id);
                break;
            case 'delete':
                await this.deleteTunnel(id);
                break;
        }
    },

    async startTunnel(id) {
        UI.showSpinner('Starting tunnel...');
        try {
            await API.startSSHTunnel(id);
            UI.success('Tunnel started');
            await this.loadTunnels(true);
        } catch (err) {
            UI.error('Failed to start: ' + err.message);
        } finally {
            UI.hideSpinner();
        }
    },

    async stopTunnel(id) {
        UI.showSpinner('Stopping tunnel...');
        try {
            await API.stopSSHTunnel(id);
            UI.success('Tunnel stopped');
            await this.loadTunnels(true);
        } catch (err) {
            UI.error('Failed to stop: ' + err.message);
        } finally {
            UI.hideSpinner();
        }
    },

    async deleteTunnel(id) {
        if (!confirm('Delete this tunnel configuration?')) return;

        UI.showSpinner('Deleting...');
        try {
            await API.deleteSSHTunnel(id);
            UI.success('Tunnel deleted');
            await this.loadTunnels(true);
        } catch (err) {
            UI.error('Delete failed: ' + err.message);
        } finally {
            UI.hideSpinner();
        }
    },

    showNewTunnelModal() {
        UI.modal({
            title: 'Create SSH Tunnel',
            content: `
                <form id="new-tunnel-form" class="form-stack">
                    <div class="form-row">
                        <div class="form-group flex-2">
                            <label for="tun-host">Remote Host</label>
                            <input type="text" id="tun-host" class="input" placeholder="example.com" required>
                        </div>
                        <div class="form-group flex-1">
                            <label for="tun-port">SSH Port</label>
                            <input type="number" id="tun-port" class="input" value="22" min="1" max="65535">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="tun-user">SSH User</label>
                        <input type="text" id="tun-user" class="input" value="root" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="tun-auth">Authentication</label>
                        <select id="tun-auth" class="select">
                            <option value="key">SSH Key</option>
                            <option value="password">Password</option>
                        </select>
                    </div>
                    
                    <div class="form-group" id="tun-key-group">
                        <label for="tun-key">Key File</label>
                        <select id="tun-key" class="select">
                            <option value="">Loading keys...</option>
                        </select>
                    </div>
                    
                    <div class="form-group" id="tun-password-group" style="display: none;">
                        <label for="tun-password">Password</label>
                        <input type="password" id="tun-password" class="input">
                    </div>
                    
                    <hr class="form-divider">
                    
                    <div class="form-group">
                        <label for="tun-fwd">Forward Type</label>
                        <select id="tun-fwd" class="select">
                            <option value="L">Local (-L) - Forward local port to remote</option>
                            <option value="R">Remote (-R) - Forward remote port to local</option>
                            <option value="D">Dynamic (-D) - SOCKS proxy</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="tun-lport">Local Port</label>
                        <input type="number" id="tun-lport" class="input" min="1" max="65535" required>
                    </div>
                    
                    <div id="target-fields">
                        <div class="form-row">
                            <div class="form-group flex-2">
                                <label for="tun-rhost">Target Host</label>
                                <input type="text" id="tun-rhost" class="input" value="127.0.0.1">
                            </div>
                            <div class="form-group flex-1">
                                <label for="tun-rport">Target Port</label>
                                <input type="number" id="tun-rport" class="input" min="1" max="65535">
                            </div>
                        </div>
                    </div>
                </form>
            `,
            buttons: [
                { text: 'Cancel', className: 'btn' },
                { text: 'Create', className: 'btn btn-primary', action: () => this.submitNewTunnel() }
            ]
        });

        this.populateKeySelect();

        document.getElementById('tun-auth').addEventListener('change', (e) => {
            document.getElementById('tun-key-group').style.display = 
                e.target.value === 'key' ? '' : 'none';
            document.getElementById('tun-password-group').style.display = 
                e.target.value === 'password' ? '' : 'none';
        });

        document.getElementById('tun-fwd').addEventListener('change', (e) => {
            document.getElementById('target-fields').style.display = 
                e.target.value === 'D' ? 'none' : '';
        });
    },

    async populateKeySelect() {
        const select = document.getElementById('tun-key');
        if (!select) return;

        try {
            const data = await API.getSSHKeys();
            const keys = data.keys || [];
            
            if (keys.length === 0) {
                select.innerHTML = '<option value="">No keys available - upload or generate one</option>';
            } else {
                select.innerHTML = keys.map(k => 
                    `<option value="${UI.escape(k.name)}">${UI.escape(k.name)}</option>`
                ).join('');
            }
        } catch (err) {
            select.innerHTML = '<option value="">Error loading keys</option>';
        }
    },

    async submitNewTunnel() {
        const host = document.getElementById('tun-host').value.trim();
        const port = parseInt(document.getElementById('tun-port').value) || 22;
        const user = document.getElementById('tun-user').value.trim();
        const auth = document.getElementById('tun-auth').value;
        const key = document.getElementById('tun-key').value;
        const password = document.getElementById('tun-password').value;
        const fwd = document.getElementById('tun-fwd').value;
        const lport = parseInt(document.getElementById('tun-lport').value);
        const rhost = document.getElementById('tun-rhost').value.trim() || '127.0.0.1';
        const rport = parseInt(document.getElementById('tun-rport').value) || 0;

        if (!host) { UI.error('Host is required'); return; }
        if (!user) { UI.error('User is required'); return; }
        if (!lport) { UI.error('Local port is required'); return; }
        if (auth === 'key' && !key) { UI.error('Please select a key file'); return; }
        if (auth === 'password' && !password) { UI.error('Password is required'); return; }
        if (fwd !== 'D' && !rport) { UI.error('Target port is required for Local/Remote forwarding'); return; }

        const config = { host, port, user, auth, fwd, lport, rhost, rport };
        if (auth === 'key') { config.key = key; } else { config.password = password; }

        UI.closeModal();
        UI.showSpinner('Creating tunnel...');

        try {
            await API.createSSHTunnel(config);
            UI.success('Tunnel created and started');
            await this.loadTunnels(true);
        } catch (err) {
            UI.error('Failed to create tunnel: ' + err.message);
        } finally {
            UI.hideSpinner();
        }
    },

    startAutoRefresh() {
        this.stopAutoRefresh();
        this.autoRefreshInterval = setInterval(() => {
            const sshTab = document.getElementById('ssh-tab');
            if (sshTab && !sshTab.hidden) {
                this.loadTunnels(false);
            }
        }, 10000);
    },

    stopAutoRefresh() {
        if (this.autoRefreshInterval) {
            clearInterval(this.autoRefreshInterval);
            this.autoRefreshInterval = null;
        }
    }
};

registerTab(SSHTab);

export default SSHTab;
