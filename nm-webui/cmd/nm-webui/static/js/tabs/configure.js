/**
 * Configure Tab Module
 * File upload and network configuration management
 */
import { API, UI, Icons, registerTab } from '../app.js';

const ConfigureTab = {
    id: 'configure',
    label: 'Configure',
    iconName: 'settings',
    loaded: false,
    eventsBound: false,
    fileStatus: {},
    networkConfigs: [],
    selectedVPNProfile: '',
    selectedVPNProfiles: [],

    init() {
        // Nothing async to initialize
    },

    render() {
        return `
            <div class="card">
                <div class="card-header">
                    <span class="card-title">${Icons.key} Environment Secrets</span>
                    <div class="card-actions">
                        <span class="badge" id="env-status">No File</span>
                        <button class="btn btn-sm" id="env-view" style="display:none">${Icons.eye} View</button>
                    </div>
                </div>
                <div class="card-body">
                    <p class="form-hint" style="margin-bottom: 1rem;">
                        Configuration file for VPN, DNS tunnel, and WiFi credentials (KEY=VALUE format)
                    </p>
                    <div class="upload-zone" data-type="env-secrets" id="env-upload">
                        <div class="upload-content">
                            <div class="upload-icon">${Icons.key}</div>
                            <div class="upload-text">Drop your env-secrets file here or click to browse</div>
                            <div class="upload-info">Max 1MB</div>
                        </div>
                        <div class="upload-progress" style="display: none;">
                            <div class="progress-bar"></div>
                            <div class="upload-status"></div>
                        </div>
                        <input type="file" class="file-input" style="display: none;">
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <span class="card-title">${Icons.shield} VPN Configuration</span>
                    <div class="card-actions">
                        <span class="badge" id="vpn-status">No File</span>
                        <button class="btn btn-sm" id="vpn-view" style="display:none">${Icons.eye} View</button>
                    </div>
                </div>
                <div class="card-body">
                    <p class="form-hint" style="margin-bottom: 1rem;">
                        Upload OpenVPN configuration file for secure connections. Profile name is derived from the filename.
                    </p>
                    <div class="upload-zone" data-type="vpn" id="vpn-upload">
                        <div class="upload-content">
                            <div class="upload-icon">${Icons.shield}</div>
                            <div class="upload-text">Drop your .ovpn file here or click to browse</div>
                            <div class="upload-info">Supported: .ovpn, .conf (Max 1MB)</div>
                        </div>
                        <div class="upload-progress" style="display: none;">
                            <div class="progress-bar"></div>
                            <div class="upload-status"></div>
                        </div>
                        <input type="file" class="file-input" accept=".ovpn,.conf" style="display: none;">
                    </div>
                </div>
            </div>

            <div class="card" id="vpn-profiles-card" style="display: none;">
                <div class="card-header">
                    <span class="card-title">${Icons.fileText} VPN Profiles</span>
                    <div class="card-actions">
                        <span class="badge" id="vpn-profiles-count">0</span>
                    </div>
                </div>
                <div class="card-body padded" id="vpn-profiles-list">
                    ${UI.loading('Loading profiles...')}
                </div>
            </div>

            <div class="card" id="network-configs-card" style="display: none;">
                <div class="card-header">
                    <span class="card-title">${Icons.globe} Network Configurations</span>
                    <div class="card-actions">
                        <span class="badge" id="configs-count">0 Ready</span>
                        <button class="btn btn-sm btn-success" id="apply-configs">${Icons.check} Apply Selected</button>
                    </div>
                </div>
                <div class="card-body" id="network-configs-list">
                    ${UI.loading('Loading configurations...')}
                </div>
            </div>
        `;
    },

    onActivate() {
        this.bindEvents();
        this.loadFileStatus();
        this.loadNetworkConfigs();
    },

    onDeactivate() {
        // Nothing to cleanup
    },

    bindEvents() {
        if (this.eventsBound) {
            return;
        }
        this.eventsBound = true;

        document.querySelectorAll('.upload-zone').forEach(zone => {
            this.initUploadZone(zone);
        });

        document.getElementById('env-view')?.addEventListener('click', () => this.viewFile('env-secrets'));
        document.getElementById('vpn-view')?.addEventListener('click', () => this.viewFile('vpn', this.selectedVPNProfile));
        document.getElementById('apply-configs')?.addEventListener('click', () => this.applyConfigs());

        document.getElementById('vpn-profiles-list')?.addEventListener('click', (e) => {
            const action = e.target.closest('button')?.dataset.action;
            const profile = e.target.closest('button')?.dataset.profile;
            if (!action || !profile) return;
            if (action === 'vpn-view') {
                this.viewFile('vpn', profile);
            } else if (action === 'vpn-delete') {
                this.deleteVPNProfile(profile);
            }
        });

        document.getElementById('network-configs-list')?.addEventListener('change', (e) => {
            if (e.target.classList.contains('vpn-profile-select')) {
                this.selectedVPNProfile = e.target.value;
            }
            if (e.target.classList.contains('vpn-profile-checkbox')) {
                this.selectedVPNProfiles = this.getSelectedVPNProfiles();
            }
        });
    },

    initUploadZone(zone) {
        const input = zone.querySelector('.file-input');
        const type = zone.dataset.type;

        zone.addEventListener('click', (e) => {
            if (e.target !== input && !e.target.closest('.upload-progress')) {
                input.click();
            }
        });

        zone.addEventListener('dragover', (e) => {
            e.preventDefault();
            zone.classList.add('drag-over');
        });

        zone.addEventListener('dragleave', (e) => {
            e.preventDefault();
            zone.classList.remove('drag-over');
        });

        zone.addEventListener('drop', (e) => {
            e.preventDefault();
            zone.classList.remove('drag-over');
            if (e.dataTransfer.files.length > 0) {
                this.uploadFile(type, e.dataTransfer.files[0], zone);
            }
        });

        input.addEventListener('change', () => {
            if (input.files.length > 0) {
                this.uploadFile(type, input.files[0], zone);
                input.value = '';
            }
        });
    },

    async uploadFile(type, file, zone) {
        const content = zone.querySelector('.upload-content');
        const progress = zone.querySelector('.upload-progress');
        const progressBar = zone.querySelector('.progress-bar');
        const status = zone.querySelector('.upload-status');

        content.style.display = 'none';
        progress.style.display = 'block';
        progressBar.style.width = '0%';
        status.textContent = 'Uploading...';
        status.className = 'upload-status';

        try {
            const formData = new FormData();
            formData.append('type', type);
            formData.append('file', file);
            if (type === 'vpn') {
                const profile = this.profileFromFilename(file.name);
                if (!profile) {
                    throw new Error('Profile name must be alphanumeric, "-" or "_"');
                }
                formData.append('profile', profile);
            }

            const response = await fetch('/api/configure/upload', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (!response.ok || data.error) {
                throw new Error(data.error || 'Upload failed');
            }

            progressBar.style.width = '100%';
            status.textContent = 'Upload successful!';
            status.classList.add('success');
            UI.success(data.message || 'File uploaded successfully');

            setTimeout(() => {
                content.style.display = 'block';
                progress.style.display = 'none';
                this.loadFileStatus();
                this.loadNetworkConfigs();
            }, 1500);

        } catch (err) {
            progressBar.style.width = '100%';
            progressBar.classList.add('error');
            status.textContent = 'Error: ' + err.message;
            status.classList.add('error');
            UI.error('Upload failed: ' + err.message);

            setTimeout(() => {
                content.style.display = 'block';
                progress.style.display = 'none';
                progressBar.classList.remove('error');
            }, 3000);
        }
    },

    async loadFileStatus() {
        try {
            const response = await fetch('/api/configure/files');
            const result = await response.json();
            this.fileStatus = result.data || result;
            this.setDefaultVPNProfile();
            this.updateFileStatusUI();
        } catch (err) {
            console.error('Failed to load file status:', err);
        }
    },

    updateFileStatusUI() {
        const envStatus = document.getElementById('env-status');
        const envView = document.getElementById('env-view');
        if (this.fileStatus['env-secrets']?.exists) {
            envStatus.textContent = 'File Present';
            envStatus.className = 'badge badge-success';
            envView.style.display = 'inline-flex';
        } else {
            envStatus.textContent = 'No File';
            envStatus.className = 'badge';
            envView.style.display = 'none';
        }

        const vpnStatus = document.getElementById('vpn-status');
        const vpnView = document.getElementById('vpn-view');
        const profiles = this.getVPNProfiles();
        if (profiles.length) {
            vpnStatus.textContent = `${profiles.length} Profile${profiles.length > 1 ? 's' : ''}`;
            vpnStatus.className = 'badge badge-success';
            vpnView.style.display = 'inline-flex';
        } else {
            vpnStatus.textContent = 'No Profiles';
            vpnStatus.className = 'badge';
            vpnView.style.display = 'none';
        }

        this.updateVPNProfilesUI();
    },

    async viewFile(type, profile = '') {
        try {
            const params = new URLSearchParams({ type });
            if (type === 'vpn' && profile) {
                params.set('profile', profile);
            }
            const response = await fetch(`/api/configure/view?${params.toString()}`);
            const result = await response.json();

            if (!response.ok || !result.ok) {
                throw new Error(result.error || 'Failed to load file');
            }

            const data = result.data || result;
            const content = `
                <pre class="file-content">${UI.escape(data.content)}</pre>
                <div class="form-hint" style="margin-top: 1rem;">
                    Size: ${this.formatSize(data.size)}
                </div>
            `;

            const title = type === 'vpn' ? `${profile} Profile` : `${type} File Contents`;
            UI.modal(title, content, { width: '600px' });

        } catch (err) {
            UI.error('Failed to view file: ' + err.message);
        }
    },

    async loadNetworkConfigs() {
        const card = document.getElementById('network-configs-card');
        const container = document.getElementById('network-configs-list');
        const countBadge = document.getElementById('configs-count');

        try {
            const response = await fetch('/api/configure/networks');
            const result = await response.json();
            const data = result.data || result;
            this.networkConfigs = data.configs || [];
            this.setDefaultVPNProfile();

            if (this.networkConfigs.length === 0) {
                card.style.display = 'none';
                return;
            }

            card.style.display = 'block';
            const readyCount = this.networkConfigs.filter(c => c.ready).length;
            countBadge.textContent = `${readyCount}/${this.networkConfigs.length} Ready`;

            container.innerHTML = this.renderNetworkConfigs();
            this.loaded = true;

        } catch (err) {
            card.style.display = 'none';
            console.error('Failed to load network configs:', err);
        }
    },

    renderNetworkConfigs() {
        if (!this.networkConfigs.length) {
            return UI.empty('No network configurations detected');
        }

        return `
            <div class="config-grid">
                ${this.networkConfigs.map(config => this.renderConfigCard(config)).join('')}
            </div>
        `;
    },

    renderConfigCard(config) {
        const statusClass = config.status === 'ready' ? 'success' : 
                           config.status === 'incomplete' ? 'warning' : 'secondary';
        const iconMap = {
            'shield-lock': Icons.shield,
            'dns': Icons.globe,
            'router': Icons.radio,
            'wifi': Icons.wifi
        };
        const icon = iconMap[config.icon] || Icons.settings;
        const profileSelect = config.type === 'openvpn' ? this.renderVPNProfileSelector() : '';

        return `
            <div class="config-card ${statusClass}">
                <div class="config-header">
                    <span class="config-icon">${icon}</span>
                    <div class="config-title">
                        <strong>${UI.escape(config.name)}</strong>
                        <small>${UI.escape(config.description)}</small>
                    </div>
                    <span class="badge badge-${statusClass}">${config.status}</span>
                </div>
                <div class="config-body">
                    ${profileSelect}
                    ${config.found_params?.length ? `
                        <div class="config-params">
                            <small>Found:</small>
                            ${config.found_params.map(p => `<code>${UI.escape(p)}</code>`).join(' ')}
                        </div>
                    ` : ''}
                    ${config.missing_params?.length ? `
                        <div class="config-params missing">
                            <small>Missing:</small>
                            ${config.missing_params.map(p => `<code>${UI.escape(p)}</code>`).join(' ')}
                        </div>
                    ` : ''}
                    ${config.file_status ? `
                        <div class="config-file">
                            ${config.file_status === 'found' ? Icons.checkCircle : Icons.alertCircle} 
                            ${UI.escape(config.file_name)}
                        </div>
                    ` : ''}
                </div>
                <div class="config-footer">
                    ${config.ready ? `
                        <label class="checkbox-label">
                            <input type="checkbox" class="config-checkbox" data-type="${config.type}" checked>
                            <span>Apply this configuration</span>
                        </label>
                    ` : `
                        <span class="config-disabled">
                            ${config.status === 'missing_file' ? 'Missing configuration file' : 'Missing required parameters'}
                        </span>
                    `}
                </div>
            </div>
        `;
    },

    async applyConfigs() {
        const checkboxes = document.querySelectorAll('.config-checkbox:checked');
        const configs = Array.from(checkboxes).flatMap(cb => {
            const type = cb.dataset.type;
            if (type === 'openvpn') {
                const profiles = this.getSelectedVPNProfiles();
                return profiles.map(profile => ({ type, profile }));
            }
            return [{ type }];
        });

        if (configs.length === 0) {
            UI.warning('No configurations selected');
            return;
        }
        const missingProfile = configs.some(c => c.type === 'openvpn' && !c.profile);
        if (missingProfile) {
            UI.warning('Select at least one VPN profile');
            return;
        }

        const applyBtn = document.getElementById('apply-configs');
        const originalText = applyBtn.innerHTML;

        try {
            applyBtn.disabled = true;
            applyBtn.innerHTML = `${Icons.loader} Applying...`;

            const response = await fetch('/api/configure/apply', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ configs })
            });

            const data = await response.json();
            if (!response.ok || data.ok === false) {
                throw new Error(data.error || data.detail || 'Failed to apply configurations');
            }
            const payload = data.data !== undefined ? data.data : data;

            if (payload.errors?.length) {
                payload.errors.forEach(err => UI.error(err));
            }

            if (payload.results?.length) {
                payload.results.forEach(result => UI.success(result));
            }

            if (payload.success) {
                UI.success('All configurations applied successfully');
            }

        } catch (err) {
            UI.error('Failed to apply configurations: ' + err.message);
        } finally {
            applyBtn.disabled = false;
            applyBtn.innerHTML = originalText;
        }
    },

    formatSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    },

    getVPNProfiles() {
        return this.fileStatus?.vpn_profiles || [];
    },

    setDefaultVPNProfile() {
        const profiles = this.getVPNProfiles();
        if (!profiles.length) {
            this.selectedVPNProfile = '';
            this.selectedVPNProfiles = [];
            return;
        }
        const exists = profiles.some(p => p.name === this.selectedVPNProfile);
        if (!exists) {
            this.selectedVPNProfile = profiles[0].name;
        }
        if (!this.selectedVPNProfiles.length) {
            this.selectedVPNProfiles = profiles.map(p => p.name);
        }
    },

    renderVPNProfilesList() {
        const profiles = this.getVPNProfiles();
        if (!profiles.length) {
            return UI.empty('No VPN profiles uploaded');
        }
        return `
            <div class="config-grid">
                ${profiles.map(profile => `
                    <div class="config-card">
                        <div class="config-header">
                            <span class="config-icon">${Icons.fileText}</span>
                            <div class="config-title">
                                <strong>${UI.escape(profile.name)}</strong>
                                <small>${UI.escape(profile.file_name)} • ${this.formatSize(profile.size)} • ${UI.escape(profile.modified)}</small>
                            </div>
                            <div class="card-actions">
                                <button class="btn btn-sm" data-action="vpn-view" data-profile="${UI.escape(profile.name)}">${Icons.eye} View</button>
                                <button class="btn btn-sm btn-danger" data-action="vpn-delete" data-profile="${UI.escape(profile.name)}">${Icons.trash} Delete</button>
                            </div>
                        </div>
                    </div>
                `).join('')}
            </div>
        `;
    },

    updateVPNProfilesUI() {
        const card = document.getElementById('vpn-profiles-card');
        const count = document.getElementById('vpn-profiles-count');
        const list = document.getElementById('vpn-profiles-list');
        const profiles = this.getVPNProfiles();
        if (!card || !count || !list) return;

        if (!profiles.length) {
            card.style.display = 'none';
            return;
        }

        card.style.display = 'block';
        count.textContent = String(profiles.length);
        list.innerHTML = this.renderVPNProfilesList();
    },

    renderVPNProfileSelector() {
        const profiles = this.getVPNProfiles();
        if (!profiles.length) {
            return `
                <div class="config-params missing">
                    <small>Profiles:</small>
                    <code>No profiles uploaded</code>
                </div>
            `;
        }
        const items = profiles.map(profile => {
            const checked = this.selectedVPNProfiles.includes(profile.name) ? 'checked' : '';
            return `
                <label class="checkbox-label">
                    <input type="checkbox" class="vpn-profile-checkbox" value="${UI.escape(profile.name)}" ${checked}>
                    <span>${UI.escape(profile.name)}</span>
                </label>
            `;
        }).join('');
        return `
            <div class="config-params">
                <small>Profiles:</small>
                <div class="config-profile-list">
                    ${items}
                </div>
            </div>
        `;
    },

    getSelectedVPNProfiles() {
        const checked = document.querySelectorAll('.vpn-profile-checkbox:checked');
        const values = Array.from(checked).map(input => input.value);
        this.selectedVPNProfiles = values;
        if (values.length) {
            this.selectedVPNProfile = values[0];
        }
        return values;
    },

    profileFromFilename(filename) {
        const base = filename.replace(/\.[^/.]+$/, '');
        if (!base || base.length > 64) {
            return '';
        }
        if (!/^[A-Za-z0-9_-]+$/.test(base)) {
            return '';
        }
        return base;
    },

    async deleteVPNProfile(profile) {
        const confirmed = await UI.confirm(`Delete VPN profile "${profile}"?`, 'Delete Profile');
        if (!confirmed) {
            return;
        }
        try {
            const response = await fetch('/api/configure/delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ type: 'vpn', profile })
            });
            const data = await response.json();
            if (!response.ok || data.error) {
                throw new Error(data.error || 'Delete failed');
            }
            UI.success('VPN profile deleted');
            this.loadFileStatus();
            this.loadNetworkConfigs();
        } catch (err) {
            UI.error('Failed to delete VPN profile: ' + err.message);
        }
    }
};

registerTab(ConfigureTab);

export default ConfigureTab;
