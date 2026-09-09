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