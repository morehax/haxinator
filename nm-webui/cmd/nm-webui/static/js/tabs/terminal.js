/**
 * Terminal Tab Module
 * Embeds Shell In A Box (shellinabox) in an iframe
 */
import { UI, Icons, registerTab } from '../app.js';

const TerminalTab = {
    id: 'terminal',
    label: 'Terminal',
    iconName: 'terminal',
    terminalUrl: null,
    eventsBound: false,

    init() {
        const host = window.location.hostname;
        this.terminalUrl = `https://${host}:4200`;
    },

    render() {
        return `
            <div class="card">
                <div class="card-header">
                    <span class="card-title">${Icons.terminal} Web Terminal</span>
                    <div class="card-actions">
                        <button class="btn btn-sm" id="terminal-refresh" title="Refresh Terminal">
                            ${Icons.refresh} Refresh
                        </button>
                        <button class="btn btn-sm" id="terminal-popout" title="Open in New Window">
                            ${Icons.externalLink} Pop Out
                        </button>
                    </div>
                </div>
                <div class="card-body terminal-container">
                    <iframe 
                        id="terminal-iframe" 
                        src="" 
                        loading="lazy"
                        allow="clipboard-read; clipboard-write"
                    ></iframe>
                    <div class="terminal-overlay" id="terminal-overlay">
                        <div class="terminal-message">
                            <div class="terminal-icon">${Icons.terminal}</div>
                            <h3>Web Terminal</h3>
                            <p>Shell In A Box uses HTTPS with a self-signed certificate.</p>
                            <p class="terminal-hint" style="margin-bottom: var(--space-md);">
                                First, click below to accept the certificate, then return here.
                            </p>
                            <div style="display: flex; gap: var(--space-sm); justify-content: center; flex-wrap: wrap;">
                                <a class="btn btn-primary" id="terminal-accept" href="" target="_blank">
                                    ${Icons.lock} Accept Certificate
                                </a>
                                <button class="btn btn-success" id="terminal-connect">
                                    ${Icons.play} Connect
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;
    },

    onActivate() {
        this.bindEvents();
    },

    onDeactivate() {
        const iframe = document.getElementById('terminal-iframe');
        if (iframe) {
            iframe.src = '';
        }
    },

    bindEvents() {
        if (this.eventsBound) {
            return;
        }
        this.eventsBound = true;

        const acceptLink = document.getElementById('terminal-accept');
        if (acceptLink) {
            acceptLink.href = this.terminalUrl;
        }

        document.getElementById('terminal-connect')?.addEventListener('click', () => this.connect());
        document.getElementById('terminal-refresh')?.addEventListener('click', () => this.refresh());
        document.getElementById('terminal-popout')?.addEventListener('click', () => this.popout());
    },

    connect() {
        const iframe = document.getElementById('terminal-iframe');
        const overlay = document.getElementById('terminal-overlay');
        
        if (iframe && this.terminalUrl) {
            iframe.src = this.terminalUrl;
            
            setTimeout(() => {
                if (overlay) {
                    overlay.style.display = 'none';
                }
            }, 1000);

            iframe.onerror = () => {
                if (overlay) {
                    overlay.style.display = 'flex';
                }
                UI.error('Failed to connect to terminal service');
            };
        }
    },

    refresh() {
        const iframe = document.getElementById('terminal-iframe');
        if (iframe && iframe.src) {
            iframe.src = iframe.src;
            UI.success('Terminal refreshed');
        } else {
            this.connect();
        }
    },

    popout() {
        if (this.terminalUrl) {
            window.open(this.terminalUrl, '_blank', 'width=800,height=600');
        }
    }
};

registerTab(TerminalTab);

export default TerminalTab;
