/**
 * App Module - Tab router and application state
 */
import API from './api.js';
import UI from './ui.js';
import Icons from './icons.js';

// Global state
const state = {
    currentTab: null,
    tabs: {},
    devices: [],
    selectedDevice: 'wlan0',
    hostname: ''
};

// Tab registry
const tabs = {};

/**
 * Register a tab module
 */
export function registerTab(tab) {
    tabs[tab.id] = tab;
}

/**
 * Switch to a tab
 */
export function switchTab(tabId) {
    if (state.currentTab === tabId) return;

    // Deactivate current tab
    if (state.currentTab && tabs[state.currentTab]?.onDeactivate) {
        tabs[state.currentTab].onDeactivate();
    }

    // Update nav state
    document.querySelectorAll('.nav-tab').forEach(t => {
        t.classList.toggle('active', t.dataset.tab === tabId);
    });

    // Update panel state
    document.querySelectorAll('.tab-panel').forEach(p => {
        p.classList.toggle('active', p.id === `tab-${tabId}`);
    });

    state.currentTab = tabId;

    // Activate new tab
    if (tabs[tabId]?.onActivate) {
        tabs[tabId].onActivate();
    }

    // Update URL hash
    window.location.hash = tabId;
}

/**
 * Initialize the application
 */
async function init() {
    // Initialize API (no auth for now)
    API.init();

    // Initialize UI
    UI.initDropdowns();

    // Build navigation and panels
    buildNavigation();
    buildPanels();

    // Load device list on startup (needed by WiFi tab dropdown)
    try {
        const status = await API.getStatus();
        if (status.devices) {
            setDevices(status.devices);
        }
        if (status.hostname) {
            setHostname(status.hostname);
        }
    } catch (err) {
        console.error('Failed to load initial device list:', err);
    }

    // Initialize all tabs
    for (const tab of Object.values(tabs)) {
        if (tab.init) {
            await tab.init();
        }
    }

    // Setup navigation click handlers
    document.querySelectorAll('.nav-tab').forEach(btn => {
        btn.addEventListener('click', () => switchTab(btn.dataset.tab));
    });

    // Handle initial tab from URL hash
    const initialTab = window.location.hash.slice(1) || Object.keys(tabs)[0];
    if (tabs[initialTab]) {
        switchTab(initialTab);
    } else {
        switchTab(Object.keys(tabs)[0]);
    }

    // Listen for hash changes
    window.addEventListener('hashchange', () => {
        const tabId = window.location.hash.slice(1);
        if (tabs[tabId]) {
            switchTab(tabId);
        }
    });

    // Update clock every second
    updateClock();
    setInterval(updateClock, 1000);

    // Setup header buttons (reboot/shutdown)
    setupHeaderButtons();

    console.log('nm-webui initialized');
}

/**
 * Build navigation from registered tabs
 */
function buildNavigation() {
    const nav = document.getElementById('nav-tabs');
    if (!nav) return;

    nav.innerHTML = Object.values(tabs).map(tab => `
        <button class="nav-tab" data-tab="${tab.id}">
            ${tab.iconSvg || Icons.get(tab.iconName) || ''}
            <span class="nav-tab-label">${tab.label}</span>
        </button>
    `).join('');
}

/**
 * Build tab panels from registered tabs
 */
function buildPanels() {
    const main = document.getElementById('main-content');
    if (!main) return;

    main.innerHTML = Object.values(tabs).map(tab => `
        <div class="tab-panel" id="tab-${tab.id}">
            ${tab.render ? tab.render() : ''}
        </div>
    `).join('');
}

/**
 * Update header clock
 */
function updateClock() {
    const el = document.getElementById('clock');
    if (el) {
        const now = new Date();
        el.textContent = now.toLocaleTimeString('en-US', { 
            hour: '2-digit', 
            minute: '2-digit',
            hour12: false 
        });
    }
}

/**
 * Get available WiFi devices
 */
export function getWifiDevices() {
    return state.devices.filter(d => d.type === 'wifi');
}

/**
 * Get the currently selected device
 */
export function getSelectedDevice() {
    return state.selectedDevice;
}

/**
 * Set the currently selected device
 */
export function setSelectedDevice(device) {
    state.selectedDevice = device;
}

/**
 * Set devices from status update
 */
export function setDevices(devices) {
    state.devices = devices;
}

/**
 * Set hostname in header
 */
export function setHostname(hostname) {
    state.hostname = hostname;
    const el = document.getElementById('header-hostname');
    if (el) {
        el.textContent = hostname || '--';
        el.title = hostname || '';
    }
}

/**
 * Setup header action buttons
 */
function setupHeaderButtons() {
    // Shutdown button
    document.getElementById('btn-shutdown')?.addEventListener('click', async () => {
        if (!confirm('Are you sure you want to shutdown the system?\n\nThe device will power off completely.')) {
            return;
        }
        
        try {
            UI.showSpinner('Shutting down...');
            await API.shutdownSystem();
            UI.success('System is shutting down...');
        } catch (err) {
            UI.error('Shutdown failed: ' + err.message);
        } finally {
            UI.hideSpinner();
        }
    });

    // Reboot button
    document.getElementById('btn-reboot')?.addEventListener('click', async () => {
        if (!confirm('Are you sure you want to reboot the system?\n\nThe device will restart.')) {
            return;
        }
        
        try {
            UI.showSpinner('Rebooting...');
            await API.rebootSystem();
            UI.success('System is rebooting...');
        } catch (err) {
            UI.error('Reboot failed: ' + err.message);
        } finally {
            UI.hideSpinner();
        }
    });
}

// Export for modules
export { API, UI, Icons, state, init };
