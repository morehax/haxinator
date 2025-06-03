/**
 * Control Panel – Shared JavaScript Components
 * ──────────────────────────────────────────────────────────────
 * 2025-05-29  (refactor-1  “quick-wins”)
 *  • Adds a universal toast() helper & global CSRF accessor.
 *  • Leaves every existing CP method intact.
 *  • Existing per-module toast() functions can be removed later;
 *    they silently defer to the global now.
 * ──────────────────────────────────────────────────────────────
 */

/*────────────────────────────────────────────────────────┐
  GLOBAL HELPERS (toast + csrf)                          │
 └────────────────────────────────────────────────────────*/
(function (win) {
  'use strict';

  /* Cache CSRF token for anyone that needs it */
  const csrfMeta = document.querySelector('meta[name="csrf-token"]');
  win.__CP_CSRF = csrfMeta ? csrfMeta.content : '';

  /* Create (or reuse) the toast container */
  function getToastContainer() {
    return (
      document.querySelector('#toastArea') ||                 // module-specific id
      document.querySelector('.cp-toast-container') ||
      (() => {
        const div = document.createElement('div');
        div.className = 'cp-toast-container';
        document.body.appendChild(div);
        return div;
      })()
    );
  }

  /* Universal toast(message, success = true) */
  function toast(message, success = true) {
    const wrap = getToastContainer();
    const el   = document.createElement('div');
    el.className =
      `toast align-items-center text-bg-${success ? 'success' : 'danger'}`;
    el.innerHTML =
      '<div class="d-flex">' +
        `<div class="toast-body">${message}</div>` +
        '<button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>' +
      '</div>';
    wrap.appendChild(el);

    // Bootstrap auto-dismiss
    if (win.bootstrap?.Toast) {
      new bootstrap.Toast(el, {delay: 4500}).show();
    }
  }

  // Expose globally
  win.toast      = win.toast || toast;   // window.toast()
  win.getCPToast = getToastContainer;    // window.getCPToast()

})(window);

/*────────────────────────────────────────────────────────┐
  “CP” NAMESPACE  (mostly original code)                 │
 └────────────────────────────────────────────────────────*/
const CP = {
  /* — selectors & breakpoints (unchanged) — */
  selectors: {
    expandBtn: '.cp-expand-btn',
    mobileDetails: '.cp-mobile-details',
    tableRow: '.cp-table-row',
    btnGroupMobile: '.cp-btn-group-mobile',
    btnGroupDesktop: '.cp-btn-group-desktop'
  },
  breakpoints: { sm: 576, md: 768, lg: 992, xl: 1200 },

  /* Initialize once DOM ready */
  init() {
    this.initResponsiveTables();
    this.initMobileNavigation();
    this.initTooltips();
  },

  /*──────────────────────────────────────────────────────*/
  /* Responsive table row-expander                        */
  /*──────────────────────────────────────────────────────*/
  initResponsiveTables() {
    document.addEventListener('click', (e) => {
      const btn = e.target.closest(this.selectors.expandBtn);
      if (!btn) return;

      const rowIdx     = btn.dataset.row;
      const detailsRow = document.querySelector(`#details-${rowIdx}`);
      const icon       = btn.querySelector('i');
      if (!detailsRow || !icon) return;

      const open = detailsRow.style.display !== 'none';
      detailsRow.style.display = open ? 'none' : 'table-row';
      icon.className           = `bi bi-chevron-${open ? 'down' : 'up'}`;
      btn.setAttribute('aria-expanded', !open);
    });
  },

  /*──────────────────────────────────────────────────────*/
  /* Mobile nav / orientation helper (unchanged logic)    */
  /*──────────────────────────────────────────────────────*/
  initMobileNavigation() {
    window.addEventListener('orientationchange', () => {
      setTimeout(() => this.handleOrientationChange(), 120);
    });
  },
  handleOrientationChange() {
    document
      .querySelectorAll('.cp-table-responsive')
      .forEach(table => {
        table.style.display = 'none';
        table.offsetHeight;           // force reflow
        table.style.display = '';
      });
  },

  /*──────────────────────────────────────────────────────*/
  /* Bootstrap tool-tips                                  */
  /*──────────────────────────────────────────────────────*/
  initTooltips() {
    if (!window.bootstrap?.Tooltip) return;
    const triggers = [].slice.call(
      document.querySelectorAll('[data-bs-toggle="tooltip"]')
    );
    triggers.map(el => new bootstrap.Tooltip(el));
  },

  /*──────────────────────────────────────────────────────*/
  /* Utilities                                            */
  /*──────────────────────────────────────────────────────*/
  getCurrentBreakpoint() {
    const w = window.innerWidth;
    if (w < this.breakpoints.sm) return 'xs';
    if (w < this.breakpoints.md) return 'sm';
    if (w < this.breakpoints.lg) return 'md';
    if (w < this.breakpoints.xl) return 'lg';
    return 'xl';
  },
  isMobile() { return window.innerWidth < this.breakpoints.md; },

  /*──────────────────────────────────────────────────────*/
  /* Shared toast shim – routes to global toast()         */
  /*──────────────────────────────────────────────────────*/
  showToast(message, type = 'success') {
    window.toast(message, type === 'success');
  },

  /*──────────────────────────────────────────────────────*/
  /* (all other original helper-builders unchanged)       */
  /*──────────────────────────────────────────────────────*/
  createResponsiveTable(config) {
    /* … original table-builder code unchanged … */
    const { containerId, columns, data, actions = [], expandable = true } = config;
    const container = document.getElementById(containerId);
    if (!container) return;

    container.innerHTML = `
      <div class="cp-table-responsive">
        <table class="table table-sm table-hover align-middle">
          <thead class="table-light">
            <tr>
              ${columns.map(col => `<th class="${col.responsive||''}">${col.title}</th>`).join('')}
              <th>Actions</th>
              ${expandable?'<th class="d-table-cell d-sm-none"><i class="bi bi-info-circle"></i></th>':''}
            </tr>
          </thead>
          <tbody>
            ${this.generateTableRows(data, columns, actions, expandable)}
          </tbody>
        </table>
      </div>`;
  },

  generateTableRows(data, columns, actions, expandable) {
    return data.map((row, i) => {
      const actionDesktop =
        `<div class="cp-btn-group-desktop d-none d-sm-flex">` +
          actions.map(a => this.generateActionButton(a, row, '')).join('') +
        `</div>`;
      const actionMobile =
        `<div class="cp-btn-group-mobile d-flex d-sm-none">` +
          actions.map(a => this.generateActionButton(a, row, 'w-100')).join('') +
        `</div>`;

      const main = `
        <tr class="cp-table-row" data-row="${i}">
          ${columns.map(col => `<td class="${col.responsive||''}">${this.formatCellValue(row[col.key], col)}</td>`).join('')}
          <td>${actionDesktop}${actionMobile}</td>
          ${expandable?`<td class="d-table-cell d-sm-none">
              <button class="cp-expand-btn" data-row="${i}" aria-expanded="false">
                <i class="bi bi-chevron-down"></i>
              </button></td>`:''}
        </tr>`;
      const details = !expandable ? '' :
        `<tr class="cp-mobile-details d-sm-none" id="details-${i}" style="display:none">
           <td colspan="${columns.length+2}" class="p-3">
             <div class="cp-details-grid">
               ${columns.filter(col=>col.showInDetails!==false).map(col=>`
                 <div class="cp-detail-item ${col.fullWidth?'cp-detail-full':''}">
                   <div class="cp-detail-label">${col.title}:</div>
                   <div>${this.formatCellValue(row[col.key], col)}</div>
                 </div>`).join('')}
             </div>
           </td>
         </tr>`;

      return main + details;
    }).join('');
  },

  formatCellValue(v, col) {
    if (v === null || v === undefined) return '';
    switch (col.type) {
      case 'badge'  : return `<span class="badge ${col.badgeClass||'bg-secondary'}">${v}</span>`;
      case 'signal' : {
        const lvl = parseInt(v, 10);
        const cls = lvl>75?'bg-success':lvl>50?'bg-warning':'bg-secondary';
        return `<span class="badge ${cls} cp-signal-badge">${v}%</span>`;
      }
      case 'code'   : return `<code class="cp-code-text">${v}</code>`;
      case 'boolean': return v ? '✅' : '';
      default       : return v;
    }
  },

  generateActionButton(a,row,extra='') {
    if (a.condition && !a.condition(row)) return '';
    const ic  = a.icon ? `<i class="bi bi-${a.icon} me-1"></i>` : '';
    const cls = `btn btn-${a.variant||'primary'} btn-${a.size||'sm'} ${extra}`.trim();
    const data = JSON.stringify(row).replace(/"/g,'&quot;');
    return `<button class="${cls}" onclick="${a.onClick}('${data}')">${ic}${a.label}</button>`;
  }
};

/* DOM-ready bootstrap */
document.addEventListener('DOMContentLoaded', () => CP.init());

/* Node / bundler export */
if (typeof module !== 'undefined' && module.exports)
  module.exports = CP;
