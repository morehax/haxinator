/**
 * UI Module - Common UI components (toast, modal, dropdown, etc.)
 */
import Icons from './icons.js';

const UI = {
    /**
     * Show a toast notification
     */
    toast(message, type = 'info', duration = 4000) {
        let container = document.getElementById('toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toast-container';
            container.className = 'toast-container';
            document.body.appendChild(container);
        }

        const iconMap = {
            success: Icons.checkCircle,
            error: Icons.alertCircle,
            warning: Icons.alertTriangle,
            info: Icons.info
        };

        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = `
            <span class="toast-icon">${iconMap[type] || iconMap.info}</span>
            <span class="toast-content">${this.escape(message)}</span>
            <button class="toast-close" onclick="this.parentElement.remove()">&times;</button>
        `;

        container.appendChild(toast);

        if (duration > 0) {
            setTimeout(() => toast.remove(), duration);
        }

        return toast;
    },

    /**
     * Show success toast
     */
    success(message) {
        return this.toast(message, 'success');
    },

    /**
     * Show error toast
     */
    error(message) {
        return this.toast(message, 'error', 6000);
    },

    /**
     * Show warning toast
     */
    warning(message) {
        return this.toast(message, 'warning');
    },

    /**
     * Show a confirmation dialog
     */
    async confirm(message, title = 'Confirm') {
        return new Promise((resolve) => {
            const overlay = document.createElement('div');
            overlay.className = 'modal-overlay open';
            overlay.innerHTML = `
                <div class="modal">
                    <div class="modal-header">
                        <h3 class="modal-title">${this.escape(title)}</h3>
                        <button class="modal-close" data-action="cancel">&times;</button>
                    </div>
                    <div class="modal-body">
                        <p>${this.escape(message)}</p>
                    </div>
                    <div class="modal-footer">
                        <button class="btn" data-action="cancel">Cancel</button>
                        <button class="btn btn-danger" data-action="confirm">Confirm</button>
                    </div>
                </div>
            `;

            const close = (result) => {
                overlay.classList.remove('open');
                setTimeout(() => overlay.remove(), 200);
                resolve(result);
            };

            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) close(false);
                if (e.target.dataset.action === 'cancel') close(false);
                if (e.target.dataset.action === 'confirm') close(true);
            });

            document.body.appendChild(overlay);
            overlay.querySelector('[data-action="confirm"]').focus();
        });
    },

    /**
     * Show a modal with custom content
     * Can be called as modal(title, content, options) or modal({ title, content, buttons, ... })
     */
    modal(titleOrConfig, content, options = {}) {
        let title, buttons, footer, onClose, width;
        
        // Support both old format (title, content, options) and new format ({ title, content, buttons })
        if (typeof titleOrConfig === 'object') {
            ({ title, content, buttons = [], footer = '', onClose = null, width = '480px' } = titleOrConfig);
        } else {
            title = titleOrConfig;
            ({ footer = '', onClose = null, width = '480px' } = options);
            buttons = [];
        }
        
        // Build footer from buttons if provided
        let footerHtml = footer;
        if (buttons && buttons.length > 0) {
            footerHtml = buttons.map((btn, idx) => 
                `<button class="${btn.className || 'btn'}" data-btn-idx="${idx}">${this.escape(btn.text)}</button>`
            ).join('');
        }
        
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay open';
        overlay.innerHTML = `
            <div class="modal" style="max-width: ${width}">
                <div class="modal-header">
                    <h3 class="modal-title">${this.escape(title)}</h3>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    ${content}
                </div>
                ${footerHtml ? `<div class="modal-footer">${footerHtml}</div>` : ''}
            </div>
        `;

        const close = () => {
            overlay.classList.remove('open');
            setTimeout(() => {
                overlay.remove();
                if (onClose) onClose();
            }, 200);
        };

        // Store close function globally for easy access
        this._currentModal = { overlay, close };

        overlay.querySelector('.modal-close').onclick = close;
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) close();
            
            // Handle button clicks
            const btnIdx = e.target.dataset.btnIdx;
            if (btnIdx !== undefined && buttons[btnIdx]) {
                const btn = buttons[btnIdx];
                if (btn.action) {
                    btn.action();
                } else {
                    close(); // Default: close modal on button click
                }
            }
        });

        document.body.appendChild(overlay);
        return { overlay, close };
    },

    /**
     * Close the current modal
     */
    closeModal() {
        if (this._currentModal) {
            this._currentModal.close();
            this._currentModal = null;
        }
    },

    /**
     * Setup dropdown behavior
     */
    initDropdowns() {
        document.addEventListener('click', (e) => {
            // Close all open dropdowns
            document.querySelectorAll('.dropdown.open').forEach(d => {
                if (!d.contains(e.target)) {
                    d.classList.remove('open');
                }
            });

            // Toggle clicked dropdown
            const trigger = e.target.closest('.dropdown-trigger');
            if (trigger) {
                e.preventDefault();
                const dropdown = trigger.closest('.dropdown');
                dropdown.classList.toggle('open');
            }
        });
    },

    /**
     * Create a dropdown menu
     */
    dropdown(items) {
        const menuItems = items.map(item => {
            if (item.divider) {
                return '<div class="dropdown-divider"></div>';
            }
            const classes = ['dropdown-item', item.danger ? 'danger' : ''].filter(Boolean).join(' ');
            const icon = item.iconName ? Icons.get(item.iconName) : (item.icon ? `<span class="icon">${item.icon}</span>` : '');
            return `
                <button class="${classes}" data-action="${item.action || ''}">
                    ${icon}
                    ${this.escape(item.label)}
                </button>
            `;
        }).join('');

        return `
            <div class="dropdown">
                <button class="dropdown-trigger" title="More actions">${Icons.moreVertical}</button>
                <div class="dropdown-menu">${menuItems}</div>
            </div>
        `;
    },

    /**
     * Create signal strength bars
     */
    signalBars(strength) {
        const level = strength > 80 ? 4 : strength > 60 ? 3 : strength > 40 ? 2 : 1;
        const className = level <= 1 ? 'weak' : level <= 2 ? 'medium' : '';
        
        let bars = '';
        for (let i = 1; i <= 4; i++) {
            bars += `<div class="signal-bar ${i <= level ? 'active' : ''}"></div>`;
        }
        return `<div class="signal ${className}" title="${strength}%">${bars}</div>`;
    },

    /**
     * Create a loading state
     */
    loading(message = 'Loading...') {
        return `<div class="state-message loading">${this.escape(message)}</div>`;
    },

    /**
     * Create an empty state
     */
    empty(message = 'No items found') {
        return `<div class="state-message">${this.escape(message)}</div>`;
    },

    /**
     * Escape HTML
     */
    escape(str) {
        if (str === null || str === undefined) return '';
        const div = document.createElement('div');
        div.textContent = String(str);
        return div.innerHTML;
    },

    /**
     * Format bytes to human readable
     */
    formatBytes(bytes) {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    },

    /**
     * Show a spinner overlay
     */
    showSpinner(message = 'Loading...') {
        this.hideSpinner(); // Remove existing spinner
        
        const spinner = document.createElement('div');
        spinner.id = 'ui-spinner';
        spinner.className = 'spinner-overlay';
        spinner.innerHTML = `
            <div class="spinner-content">
                <div class="spinner-icon">${Icons.loader}</div>
                <div class="spinner-message">${this.escape(message)}</div>
            </div>
        `;
        document.body.appendChild(spinner);
    },

    /**
     * Hide the spinner overlay
     */
    hideSpinner() {
        const spinner = document.getElementById('ui-spinner');
        if (spinner) {
            spinner.remove();
        }
    },

    /**
     * Get an icon by name
     */
    icon(name, className = '') {
        return Icons.get(name, className);
    }
};

export default UI;
