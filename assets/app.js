/* ============================================================
   Mailroom System - Shared JavaScript
   Modern enterprise interactions, drawers, dropdowns
   ============================================================ */

/* ── Modal Manager ───────────────────────────────────────── */
const MailroomModal = {
    open(id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        if (modal.dataset.lockedBefore) return;
        modal.classList.add('active');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        const firstInput = modal.querySelector('input:not([type=hidden]), textarea, select');
        if (firstInput) setTimeout(() => firstInput.focus(), 150);
    },
    close(id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.remove('active');
        modal.style.display = 'none';
        document.body.style.overflow = '';
    },
    closeAll() {
        document.querySelectorAll('.modal-backdrop').forEach(m => {
            m.classList.remove('active');
            m.style.display = 'none';
        });
        document.body.style.overflow = '';
    }
};

// Backdrop click + Escape for modals
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal-backdrop') && !e.target.classList.contains('.modal-dialog')) {
        const content = e.target.querySelector('.modal-dialog');
        if (content && !content.contains(e.target)) {
            // close it
            e.target.classList.remove('active');
            e.target.style.display = 'none';
            document.body.style.overflow = '';
        }
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        // Close topmost drawer first
        const openDrawer = document.querySelector('.drawer.open');
        if (openDrawer) { MailroomDrawer.close(openDrawer.id); return; }

        const openModals = document.querySelectorAll('.modal-backdrop.active');
        if (openModals.length > 0) {
            MailroomModal.close(openModals[openModals.length - 1].id);
        }
    }
});

// Legacy compatibility
function openModal(id) { MailroomModal.open(id); }
function closeModal(id) { MailroomModal.close(id); }

/* ── Drawer Manager (side panels) ────────────────────────── */
const MailroomDrawer = {
    open(id) {
        const drawer = document.getElementById(id);
        if (!drawer) return;
        const backdrop = document.querySelector('.drawer-backdrop');
        if (backdrop) backdrop.classList.add('active');
        drawer.classList.add('open');
        document.body.style.overflow = 'hidden';
    },
    close(id) {
        const drawer = document.getElementById(id);
        if (!drawer) return;
        const backdrop = document.querySelector('.drawer-backdrop');
        if (backdrop) backdrop.classList.remove('active');
        drawer.classList.remove('open');
        document.body.style.overflow = '';
    },
    closeAll() {
        document.querySelectorAll('.drawer').forEach(d => {
            d.classList.remove('open');
        });
        const backdrop = document.querySelector('.drawer-backdrop');
        if (backdrop) backdrop.classList.remove('active');
        document.body.style.overflow = '';
    }
};

// Drawer backdrop click
document.querySelectorAll('.drawer-backdrop').forEach(b => {
    b.addEventListener('click', function() {
        MailroomDrawer.closeAll();
    });
});

/* ── Dropdown Manager ────────────────────────────────────── */
const MailroomDropdown = {
    open(elOrId) {
        const trigger = typeof elOrId === 'string' ? document.getElementById(elOrId) : elOrId;
        if (!trigger) return;
        const menu = trigger.nextElementSibling;
        if (!menu || !menu.classList.contains('dropdown-menu')) return;

        // Close any other open dropdowns
        document.querySelectorAll('.dropdown-menu.show').forEach(m => {
            if (m !== menu) m.classList.remove('show');
        });

        menu.classList.add('show');
    },
    close(el) {
        const trigger = typeof el === 'string' ? document.getElementById(el) : el;
        if (!trigger) return;
        const menu = trigger.nextElementSibling;
        if (menu && menu.classList.contains('dropdown-menu')) menu.classList.remove('show');
    },
    toggle(el) {
        const trigger = typeof el === 'string' ? document.getElementById(el) : el;
        if (!trigger) return;
        const menu = trigger.nextElementSibling;
        if (!menu || !menu.classList.contains('dropdown-menu')) return;
        menu.classList.toggle('show');
    },
    closeAll() {
        document.querySelectorAll('.dropdown-menu.show').forEach(m => m.classList.remove('show'));
    }
};

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.dropdown')) {
        MailroomDropdown.closeAll();
    }
});

// Utility function for non-click dropdowns
function toggleDropdown(el) {
    MailroomDropdown.toggle(el);
}

/* ── Toast Notifications ─────────────────────────────────── */
const MailroomToast = {
    show(message, type = 'success', duration = 4500) {
        let container = document.getElementById('toastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toastContainer';
            container.className = 'toast-container';
            document.body.appendChild(container);
        }

        const icons = {
            success: 'fa-circle-check',
            error: 'fa-circle-xmark',
            warning: 'fa-triangle-exclamation',
            info: 'fa-circle-info'
        };

        const toast = document.createElement('div');
        toast.className = 'toast ' + type;
        toast.innerHTML = `
            <i class="fa-solid ${icons[type] || icons.success} toast-icon"></i>
            <div class="toast-content">${message}</div>
            <button class="toast-close">&times;</button>
        `;

        container.appendChild(toast);

        const remove = () => {
            toast.classList.add('leaving');
            setTimeout(() => toast.remove(), 200);
        };

        toast.querySelector('.toast-close').addEventListener('click', remove);
        setTimeout(remove, duration);
    },
    success(msg) { this.show(msg, 'success'); },
    error(msg) { this.show(msg, 'error'); },
    warning(msg) { this.show(msg, 'warning'); },
    info(msg) { this.show(msg, 'info'); }
};

/* ── Sidebar toggle ──────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', function() {
    // Restore collapsed state
    if (localStorage.getItem('mr_sidebar') === 'collapsed' && window.innerWidth >= 1024) {
        document.documentElement.setAttribute('data-sidebar-collapsed', 'true');
    }

    const toggleBtn = document.querySelector('[data-sidebar-toggle]');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            MailroomSidebar.toggle();
        });
    }

    // When collapsed the toggle is hidden, so let the logo/brand expand it
    document.querySelectorAll('.sidebar-logo, .sidebar-brand').forEach(el => {
        el.addEventListener('click', function() {
            if (document.documentElement.getAttribute('data-sidebar-collapsed') === 'true') {
                MailroomSidebar.toggle();
            }
        });
    });
});

const MailroomSidebar = {
    toggle() {
        if (window.innerWidth < 1024) {
            const sidebar = document.getElementById('appSidebar');
            const overlay = document.getElementById('sidebarOverlay');
            if (!sidebar) return;
            if (sidebar.classList.contains('open')) {
                sidebar.classList.remove('open');
                if (overlay) overlay.classList.remove('show');
            } else {
                sidebar.classList.add('open');
                if (overlay) overlay.classList.add('show');
            }
        } else {
            const collapsed = document.documentElement.getAttribute('data-sidebar-collapsed') === 'true';
            if (collapsed) {
                document.documentElement.removeAttribute('data-sidebar-collapsed');
                localStorage.setItem('mr_sidebar', 'expanded');
            } else {
                document.documentElement.setAttribute('data-sidebar-collapsed', 'true');
                localStorage.setItem('mr_sidebar', 'collapsed');
            }
        }
    },
    close() {
        const sidebar = document.getElementById('appSidebar');
        const overlay = document.getElementById('sidebarOverlay');
        if (sidebar) sidebar.classList.remove('open');
        if (overlay) overlay.classList.remove('show');
    }
};

document.addEventListener('DOMContentLoaded', function() {
    const overlay = document.getElementById('sidebarOverlay');
    if (overlay) {
        overlay.addEventListener('click', () => MailroomSidebar.close());
    }
    window.addEventListener('resize', function() {
        if (window.innerWidth >= 1024) {
            const sidebar = document.getElementById('appSidebar');
            const overlay = document.getElementById('sidebarOverlay');
            if (sidebar) sidebar.classList.remove('open');
            if (overlay) overlay.classList.remove('show');
        }
    });
});

/* ── POST Form Helper (for GET→POST conversions) ─────────── */
function submitPostForm(action, data = {}) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = action;

    const csrfToken = document.querySelector('meta[name="csrf-token"]');
    if (csrfToken) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'csrf_token';
        input.value = csrfToken.content;
        form.appendChild(input);
    }

    for (const [key, value] of Object.entries(data)) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = value;
        form.appendChild(input);
    }

    document.body.appendChild(form);
    form.submit();
}

/* ── CSV Export Utility ──────────────────────────────────── */
function exportToCSV(data, filename) {
    if (!data || data.length === 0) return;

    const headers = Object.keys(data[0]);
    const csvContent = [
        headers.join(','),
        ...data.map(row => headers.map(h => {
            let val = (row[h] ?? '').toString();
            val = val.replace(/"/g, '""');
            return val.includes(',') || val.includes('"') || val.includes('\n') ? `"${val}"` : val;
        }).join(','))
    ].join('\n');

    const blob = new Blob(['\ufeff' + csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = filename;
    link.click();
    URL.revokeObjectURL(link.href);
}

/* ── Confirmation Modal ──────────────────────────────────── */
function confirmAction(title, message, onConfirm, confirmText = 'Confirm', confirmClass = 'btn-danger') {
    const id = 'confirm_' + Date.now();
    const html = `
    <div id="${id}" class="modal-backdrop" style="display:flex">
        <div class="modal-dialog sm">
            <div class="modal-header">
                <h3 class="modal-title">${title}</h3>
                <button class="modal-close" onclick="document.getElementById('${id}').remove()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body">
                <p style="font-size:13px;color:var(--text-secondary);line-height:1.5">${message}</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-soft" onclick="document.getElementById('${id}').remove()">Cancel</button>
                <button class="btn ${confirmClass}" id="${id}_confirm">${confirmText}</button>
            </div>
        </div>
    </div>`;
    document.body.insertAdjacentHTML('beforeend', html);
    const wrapper = document.getElementById(id);
    requestAnimationFrame(() => wrapper.classList.add('active'));
    document.getElementById(id + '_confirm').addEventListener('click', function() {
        wrapper.remove();
        onConfirm();
    });
}

/* ── Utility: Show/Hide Element ──────────────────────────── */
function toggleElement(id, show) {
    const el = document.getElementById(id);
    if (!el) return;
    if (show) {
        el.style.display = '';
        el.classList.remove('hidden');
    } else {
        el.style.display = 'none';
        el.classList.add('hidden');
    }
}

/* ── Escape HTML ─────────────────────────────────────────── */
function esc(text) {
    const div = document.createElement('div');
    div.textContent = text == null ? '' : String(text);
    return div.innerHTML;
}

/* ── Format helpers ──────────────────────────────────────── */
function formatDate(value) {
    if (!value) return 'N/A';
    const d = new Date(value);
    if (isNaN(d.getTime())) return value;
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function formatDateTime(value) {
    if (!value) return 'N/A';
    const d = new Date(value);
    if (isNaN(d.getTime())) return value;
    return d.toLocaleString('en-US', {
        month: 'short', day: 'numeric', year: 'numeric',
        hour: 'numeric', minute: '2-digit', hour12: true
    });
}

function formatTimeAgo(value) {
    if (!value) return '';
    const d = new Date(value);
    if (isNaN(d.getTime())) return '';
    const diff = Date.now() - d.getTime();
    const mins = Math.floor(diff / 60000);
    if (mins < 1) return 'just now';
    if (mins < 60) return `${mins}m ago`;
    const hours = Math.floor(mins / 60);
    if (hours < 24) return `${hours}h ago`;
    const days = Math.floor(hours / 24);
    if (days < 7) return `${days}d ago`;
    return formatDate(value);
}

/* ── PWA support (manifest injection + service worker) ────── */
const MailroomPWA = {
    deferredPrompt: null,

    injectMeta() {
        if (document.querySelector('link[rel="manifest"]')) return;
        const head = document.head;

        const manifest = document.createElement('link');
        manifest.rel = 'manifest';
        manifest.href = './manifest.json';
        head.appendChild(manifest);

        const theme = document.createElement('meta');
        theme.name = 'theme-color';
        theme.content = '#8b2635';
        head.appendChild(theme);

        const capable = document.createElement('meta');
        capable.name = 'apple-mobile-web-app-capable';
        capable.content = 'yes';
        head.appendChild(capable);

        const status = document.createElement('meta');
        status.name = 'apple-mobile-web-app-status-bar-style';
        status.content = 'default';
        head.appendChild(status);

        const title = document.createElement('meta');
        title.name = 'apple-mobile-web-app-title';
        title.content = 'Mailroom';
        head.appendChild(title);

        const icon = document.createElement('link');
        icon.rel = 'apple-touch-icon';
        icon.href = './images/icons/apple-touch-icon.png';
        head.appendChild(icon);

        if (!document.querySelector('link[href*="fonts.googleapis"]')) {
            const inter = document.createElement('link');
            inter.rel = 'stylesheet';
            inter.href = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap';
            head.appendChild(inter);
        }
    },

    register() {
        if (!('serviceWorker' in navigator)) return;
        if (location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') return;
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('./sw.js').catch(() => {});
        });
    },

    init() {
        if (sessionStorage.getItem('mr_sw_captured')) return;
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            this.deferredPrompt = e;
            sessionStorage.setItem('mr_sw_captured', '1');
            window.dispatchEvent(new CustomEvent('mr:installable'));
        });
        window.addEventListener('appinstalled', () => {
            this.deferredPrompt = null;
            window.dispatchEvent(new CustomEvent('mr:installed'));
        });
    }
};
window.MailroomPWA = MailroomPWA;

/* ── Display preferences (font family + text size) ────────── */
const MailroomPrefs = {
    keys: { font: 'mr_font_family', scale: 'mr_text_scale' },
    defaults: { font: 'system', scale: 'md' },

    get(key) {
        const stored = localStorage.getItem(this.keys[key]);
        return stored || this.defaults[key];
    },

    set(key, value) {
        localStorage.setItem(this.keys[key], value);
        this.apply();
        window.dispatchEvent(new CustomEvent('mr:prefs'));
    },

    apply() {
        document.documentElement.setAttribute('data-font', this.get('font'));
        const scale = this.get('scale');
        if (scale === 'md') {
            document.documentElement.removeAttribute('data-text-scale');
        } else {
            document.documentElement.setAttribute('data-text-scale', scale);
        }
    },

    reset() {
        Object.keys(this.keys).forEach((k) => localStorage.removeItem(this.keys[k]));
        this.apply();
        window.dispatchEvent(new CustomEvent('mr:prefs'));
    }
};

/* ── Display options floating widget ──────────────────────── */
const FontControl = {
    SECLES: ['sm', 'md', 'lg', 'xl', 'xxl'],
    FAMILIES: [
        { value: 'system', label: 'Default', cls: '' },
        { value: 'serif', label: 'Serif', cls: 'serif' },
        { value: 'modern', label: 'Modern', cls: 'modern' },
        { value: 'mono', label: 'Mono', cls: 'mono' }
    ],

    build() {
        if (document.querySelector('.mr-widget')) return;

        const widget = document.createElement('div');
        widget.className = 'mr-widget';

        const fab = document.createElement('button');
        fab.className = 'mr-widget-fab';
        fab.type = 'button';
        fab.title = 'Display options';
        fab.setAttribute('aria-label', 'Display options');
        fab.textContent = 'Aa';

        const panel = document.createElement('div');
        panel.className = 'mr-widget-panel';
        panel.innerHTML = `
            <h3>Display</h3>

            <div class="mr-section">
                <div class="mr-label">Text size</div>
                <div class="mr-size-row"></div>
            </div>

            <div class="mr-section">
                <div class="mr-label">Font</div>
                <div class="mr-font-row"></div>
            </div>

            <div style="margin-bottom:10px;">
                <button type="button" class="mr-install hidden"><i class="fa-solid fa-download"></i>&nbsp;Install app</button>
            </div>

            <button type="button" class="mr-reset">Reset display settings</button>
        `;

        widget.appendChild(fab);
        widget.appendChild(panel);
        document.body.appendChild(widget);

        const sizeRow = panel.querySelector('.mr-size-row');
        this.SECLES.forEach((s, i) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'mr-size-btn';
            btn.dataset.scale = s;
            btn.textContent = ['A-', 'A', 'A+', 'A++', 'A+++'][i];
            const sizeCls = i === 0 ? 'mr-size-minus' : (i >= 2 ? 'mr-size-plus' : '');
            if (sizeCls) btn.classList.add(sizeCls);
            btn.addEventListener('click', () => MailroomPrefs.set('scale', s));
            sizeRow.appendChild(btn);
        });

        const fontRow = panel.querySelector('.mr-font-row');
        this.FAMILIES.forEach((f) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'mr-font-btn ' + f.cls;
            btn.dataset.font = f.value;
            btn.textContent = f.label;
            btn.addEventListener('click', () => MailroomPrefs.set('font', f.value));
            fontRow.appendChild(btn);
        });

        fab.addEventListener('click', () => widget.classList.toggle('open'));

        document.addEventListener('click', (e) => {
            if (widget.classList.contains('open') && !widget.contains(e.target)) {
                widget.classList.remove('open');
            }
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && widget.classList.contains('open')) widget.classList.remove('open');
        });

        const installBtn = panel.querySelector('.mr-install');
        const updateInstall = () => {
            if (window.MailroomPWA && window.MailroomPWA.deferredPrompt) {
                installBtn.classList.remove('hidden');
            } else {
                installBtn.classList.add('hidden');
            }
        };
        installBtn.addEventListener('click', async () => {
            if (!window.MailroomPWA || !window.MailroomPWA.deferredPrompt) return;
            window.MailroomPWA.deferredPrompt.prompt();
            await window.MailroomPWA.deferredPrompt.userChoice;
            window.MailroomPWA.deferredPrompt = null;
            updateInstall();
        });
        window.addEventListener('mr:installable', updateInstall);
        window.addEventListener('mr:installed', updateInstall);

        panel.querySelector('.mr-reset').addEventListener('click', () => {
            MailroomPrefs.reset();
            this.syncUI(panel);
        });

        this.syncUI(panel);
    },

    syncUI(panel) {
        const font = MailroomPrefs.get('font');
        const scale = MailroomPrefs.get('scale');
        panel.querySelectorAll('.mr-font-btn').forEach((b) => {
            b.classList.toggle('active', b.dataset.font === font);
        });
        panel.querySelectorAll('.mr-size-btn').forEach((b) => {
            b.classList.toggle('active', b.dataset.scale === scale);
        });
    }
};

window.addEventListener('mr:prefs', () => {
    const panel = document.querySelector('.mr-widget-panel');
    if (panel) FontControl.syncUI(panel);
});

/* ── Init PWA + font prefs on every page ──────────────────── */
(function initAppShell() {
    MailroomPWA.injectMeta();
    MailroomPrefs.apply();
    MailroomPWA.register();
    MailroomPWA.init();

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => FontControl.build());
    } else {
        FontControl.build();
    }
})();