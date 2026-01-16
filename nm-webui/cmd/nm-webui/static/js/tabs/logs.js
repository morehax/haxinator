/**
 * Logs Tab Module - System logging and debugging
 */
import { API, UI, Icons, registerTab } from '../app.js';

const LogsTab = {
    id: 'logs',
    label: 'Logs',
    iconName: 'fileText',
    loaded: false,
    eventsBound: false,
    entries: [],
    settings: null,
    autoRefresh: false,
    autoRefreshInterval: null,
    filter: {
        category: '',
        level: '',
        search: '',
        limit: 100
    },

    init() {
        // Nothing async to initialize
    },

    render() {
        return `
            <div class="toolbar">
                <h2>System Logs</h2>
                <div class="toolbar-spacer"></div>
                <div class="toolbar-group">
                    <label class="toggle" title="Auto-refresh logs">
                        <input type="checkbox" class="toggle-input" id="logs-auto-refresh">
                        <span class="toggle-track"><span class="toggle-thumb"></span></span>
                        <span class="toggle-label">Auto</span>
                    </label>
                    <button class="btn btn-sm" id="logs-refresh">
                        ${Icons.refresh} Refresh
                    </button>
                    <button class="btn btn-sm" id="logs-clear">
                        ${Icons.trash} Clear
                    </button>
                    <button class="btn btn-sm" id="logs-settings">
                        ${Icons.settings} Settings
                    </button>
                </div>
            </div>
            
            <div class="card" style="margin-bottom: var(--space-md);">
                <div class="card-body" style="padding: var(--space-sm);">
                    <div class="logs-filters">
                        <div class="form-group" style="margin: 0; flex: 1;">
                            <input type="text" id="logs-search" class="form-control" 
                                placeholder="Search logs..." style="height: 36px;">
                        </div>
                        <select id="logs-category" class="form-control" style="width: auto; height: 36px;">
                            <option value="">All Categories</option>
                            <option value="nmcli">nmcli</option>
                            <option value="system">system</option>
                            <option value="action">action</option>
                            <option value="api">api</option>
                            <option value="ssh">ssh</option>
                        </select>
                        <select id="logs-level" class="form-control" style="width: auto; height: 36px;">
                            <option value="">All Levels</option>
                            <option value="DEBUG">DEBUG</option>
                            <option value="INFO">INFO</option>
                            <option value="WARN">WARN</option>
                            <option value="ERROR">ERROR</option>
                        </select>
                        <select id="logs-limit" class="form-control" style="width: auto; height: 36px;">
                            <option value="50">Last 50</option>
                            <option value="100" selected>Last 100</option>
                            <option value="200">Last 200</option>
                            <option value="500">Last 500</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <div id="logs-stats" class="logs-stats"></div>
            
            <div class="card">
                <div class="card-body" style="padding: 0;">
                    <div id="logs-content" class="logs-container">
                        ${UI.loading('Loading logs...')}
                    </div>
                </div>
            </div>
        `;
    },

    onActivate() {
        this.bindEvents();
        
        if (!this.loaded) {
            this.load();
            this.loadStats();
        }
    },

    onDeactivate() {
        this.stopAutoRefresh();
    },

    bindEvents() {
        if (this.eventsBound) {
            return;
        }
        this.eventsBound = true;

        document.getElementById('logs-refresh')?.addEventListener('click', () => this.load(true));
        document.getElementById('logs-clear')?.addEventListener('click', () => this.clearLogs());
        document.getElementById('logs-settings')?.addEventListener('click', () => this.showSettings());
        
        document.getElementById('logs-auto-refresh')?.addEventListener('change', (e) => {
            if (e.target.checked) {
                this.startAutoRefresh();
            } else {
                this.stopAutoRefresh();
            }
        });
        
        document.getElementById('logs-search')?.addEventListener('input', (e) => {
            this.filter.search = e.target.value;
            this.debounceLoad();
        });
        
        document.getElementById('logs-category')?.addEventListener('change', (e) => {
            this.filter.category = e.target.value;
            this.load();
        });
        
        document.getElementById('logs-level')?.addEventListener('change', (e) => {
            this.filter.level = e.target.value;
            this.load();
        });
        
        document.getElementById('logs-limit')?.addEventListener('change', (e) => {
            this.filter.limit = parseInt(e.target.value);
            this.load();
        });
        
        document.getElementById('logs-content')?.addEventListener('click', (e) => {
            const entry = e.target.closest('.log-entry');
            if (entry && !e.target.closest('button')) {
                entry.classList.toggle('expanded');
            }
        });
    },

    debounceTimeout: null,
    debounceLoad() {
        clearTimeout(this.debounceTimeout);
        this.debounceTimeout = setTimeout(() => this.load(), 300);
    },

    startAutoRefresh() {
        this.autoRefresh = true;
        this.autoRefreshInterval = setInterval(() => this.load(), 2000);
    },

    stopAutoRefresh() {
        this.autoRefresh = false;
        if (this.autoRefreshInterval) {
            clearInterval(this.autoRefreshInterval);
            this.autoRefreshInterval = null;
        }
    },

    async load(showLoading = false) {
        const container = document.getElementById('logs-content');
        if (!container) return;

        if (showLoading && !this.autoRefresh) {
            container.innerHTML = UI.loading('Loading logs...');
        }

        try {
            const data = await API.getSystemLogs(this.filter);
            this.entries = data.entries || [];
            this.loaded = true;

            container.innerHTML = this.renderLogs();
        } catch (err) {
            container.innerHTML = `<div class="state-message">Error: ${UI.escape(err.message)}</div>`;
            if (!this.autoRefresh) {
                UI.error('Failed to load logs: ' + err.message);
            }
        }
    },

    async loadStats() {
        try {
            const stats = await API.getLogStats();
            const statsEl = document.getElementById('logs-stats');
            if (statsEl) {
                statsEl.innerHTML = this.renderStats(stats);
            }
        } catch (err) {
            console.error('Failed to load log stats:', err);
        }
    },

    renderStats(stats) {
        const categories = stats.by_category || {};
        const categoryBadges = Object.entries(categories)
            .map(([cat, count]) => `<span class="badge badge-outline">${cat}: ${count}</span>`)
            .join(' ');

        return `
            <div style="display: flex; gap: var(--space-md); align-items: center; margin-bottom: var(--space-md); flex-wrap: wrap;">
                <span class="text-sm text-secondary">
                    Total: <strong>${stats.total_entries || 0}</strong> / ${stats.max_entries || 0}
                </span>
                <span class="text-sm ${stats.errors > 0 ? 'text-danger' : 'text-secondary'}">
                    Errors: <strong>${stats.errors || 0}</strong>
                </span>
                <span class="text-sm text-secondary">
                    Logging: <strong class="${stats.enabled ? 'text-success' : 'text-muted'}">${stats.enabled ? 'ON' : 'OFF'}</strong>
                </span>
                ${categoryBadges}
            </div>
        `;
    },

    renderLogs() {
        if (!this.entries.length) {
            return `<div class="state-message">No log entries found</div>`;
        }

        return `
            <div class="logs-list">
                ${this.entries.map(entry => this.renderEntry(entry)).join('')}
            </div>
        `;
    },

    renderEntry(entry) {
        const levelClass = this.getLevelClass(entry.level);
        const time = new Date(entry.time).toLocaleTimeString();
        const date = new Date(entry.time).toLocaleDateString();
        const hasDetails = entry.command || entry.output || entry.error || entry.extra;
        
        return `
            <div class="log-entry ${levelClass} ${entry.success ? '' : 'error'} ${hasDetails ? 'has-details' : ''}">
                <div class="log-entry-header">
                    <span class="log-level">${entry.level}</span>
                    <span class="log-category">${UI.escape(entry.category)}</span>
                    <span class="log-action">${UI.escape(entry.action)}</span>
                    ${entry.duration_ms ? `<span class="log-duration">${entry.duration_ms}ms</span>` : ''}
                    <span class="log-status">${entry.success ? Icons.check : Icons.x}</span>
                    <span class="log-time" title="${date}">${time}</span>
                    ${hasDetails ? `<span class="log-expand">${Icons.chevronDown}</span>` : ''}
                </div>
                ${hasDetails ? `
                    <div class="log-entry-details">
                        ${entry.command ? `
                            <div class="log-detail">
                                <span class="log-detail-label">Command:</span>
                                <code class="log-detail-value">${UI.escape(entry.command)}</code>
                            </div>
                        ` : ''}
                        ${entry.output ? `
                            <div class="log-detail">
                                <span class="log-detail-label">Output:</span>
                                <pre class="log-detail-output">${UI.escape(entry.output)}</pre>
                            </div>
                        ` : ''}
                        ${entry.error ? `
                            <div class="log-detail error">
                                <span class="log-detail-label">Error:</span>
                                <code class="log-detail-value">${UI.escape(entry.error)}</code>
                            </div>
                        ` : ''}
                        ${entry.exit_code !== undefined && entry.exit_code !== 0 ? `
                            <div class="log-detail">
                                <span class="log-detail-label">Exit Code:</span>
                                <code class="log-detail-value">${entry.exit_code}</code>
                            </div>
                        ` : ''}
                        ${entry.extra ? `
                            <div class="log-detail">
                                <span class="log-detail-label">Extra:</span>
                                <pre class="log-detail-output">${JSON.stringify(entry.extra, null, 2)}</pre>
                            </div>
                        ` : ''}
                    </div>
                ` : ''}
            </div>
        `;
    },

    getLevelClass(level) {
        switch (level) {
            case 'ERROR': return 'level-error';
            case 'WARN': return 'level-warn';
            case 'INFO': return 'level-info';
            case 'DEBUG': return 'level-debug';
            default: return '';
        }
    },

    async clearLogs() {
        if (!await UI.confirm('Clear all logs?', 'Clear Logs')) {
            return;
        }

        try {
            await API.clearLogs();
            UI.success('Logs cleared');
            this.load();
            this.loadStats();
        } catch (err) {
            UI.error('Failed to clear logs: ' + err.message);
        }
    },

    async showSettings() {
        try {
            const settings = await API.getLogSettings();
            
            const content = `
                <form id="log-settings-form">
                    <div class="form-group">
                        <label class="toggle">
                            <input type="checkbox" class="toggle-input" name="enabled" ${settings.enabled ? 'checked' : ''}>
                            <span class="toggle-track"><span class="toggle-thumb"></span></span>
                            <span class="toggle-label">Logging Enabled</span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label class="toggle">
                            <input type="checkbox" class="toggle-input" name="log_commands" ${settings.log_commands ? 'checked' : ''}>
                            <span class="toggle-track"><span class="toggle-thumb"></span></span>
                            <span class="toggle-label">Log Command Strings</span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label class="toggle">
                            <input type="checkbox" class="toggle-input" name="log_output" ${settings.log_output ? 'checked' : ''}>
                            <span class="toggle-track"><span class="toggle-thumb"></span></span>
                            <span class="toggle-label">Log Command Output</span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Max Entries</label>
                        <input type="number" class="form-control" name="max_entries" 
                            value="${settings.max_entries}" min="50" max="5000" step="50">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Max Output Length</label>
                        <input type="number" class="form-control" name="max_output_len" 
                            value="${settings.max_output_len}" min="256" max="65536" step="256">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Minimum Log Level</label>
                        <select class="form-control" name="min_level">
                            <option value="0" ${settings.min_level === 0 ? 'selected' : ''}>DEBUG</option>
                            <option value="1" ${settings.min_level === 1 ? 'selected' : ''}>INFO</option>
                            <option value="2" ${settings.min_level === 2 ? 'selected' : ''}>WARN</option>
                            <option value="3" ${settings.min_level === 3 ? 'selected' : ''}>ERROR</option>
                        </select>
                    </div>
                </form>
            `;

            UI.modal({
                title: 'Log Settings',
                content,
                width: '400px',
                buttons: [
                    { text: 'Cancel', className: 'btn' },
                    { text: 'Save', className: 'btn btn-primary', action: async () => {
                        const form = document.getElementById('log-settings-form');
                        const newSettings = {
                            enabled: form.querySelector('[name="enabled"]').checked,
                            log_commands: form.querySelector('[name="log_commands"]').checked,
                            log_output: form.querySelector('[name="log_output"]').checked,
                            max_entries: parseInt(form.querySelector('[name="max_entries"]').value),
                            max_output_len: parseInt(form.querySelector('[name="max_output_len"]').value),
                            min_level: parseInt(form.querySelector('[name="min_level"]').value)
                        };

                        try {
                            await API.updateLogSettings(newSettings);
                            UI.success('Settings saved');
                            UI.closeModal();
                            this.loadStats();
                        } catch (err) {
                            UI.error('Failed to save settings: ' + err.message);
                        }
                    } }
                ]
            });
        } catch (err) {
            UI.error('Failed to load settings: ' + err.message);
        }
    }
};

registerTab(LogsTab);

export default LogsTab;
