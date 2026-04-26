import './stimulus_bootstrap.js';
/*
 * NORSU Alumni Tracker — main JS entry point
 * Loaded via importmap (ES module = executes exactly once).
 */
import './styles/app.css';
import './styles/landing-shell.css';

const ADMIN_SIDEBAR_COMPACT_KEY = 'norsu.adminSidebarCompact';

function isDesktopViewport() {
    return window.innerWidth >= 1024;
}

function applyAdminSidebarCompactState() {
    const body = document.body;
    if (!body || !body.classList.contains('admin-material-page')) {
        if (body) body.classList.remove('sidebar-compact');
        return;
    }

    if (!isDesktopViewport()) {
        body.classList.remove('sidebar-compact');
        return;
    }

    const shouldCompact = localStorage.getItem(ADMIN_SIDEBAR_COMPACT_KEY) === '1';
    body.classList.toggle('sidebar-compact', shouldCompact);
}

function syncAdminSidebarCompactToggle() {
    const btn = document.getElementById('adminSidebarCompactToggle');
    if (!btn) return;

    const compact = document.body.classList.contains('sidebar-compact');
    btn.setAttribute('aria-pressed', compact ? 'true' : 'false');
    btn.setAttribute('title', compact ? 'Expand sidebar' : 'Collapse sidebar');

    const icon = btn.querySelector('i');
    if (icon) {
        icon.classList.remove('bi-layout-sidebar', 'bi-layout-sidebar-inset');
        icon.classList.add(compact ? 'bi-layout-sidebar-inset' : 'bi-layout-sidebar');
    }
}

function toggleAdminSidebarCompact() {
    const body = document.body;
    if (!body || !body.classList.contains('admin-material-page') || !isDesktopViewport()) return;

    const nextCompact = !body.classList.contains('sidebar-compact');
    body.classList.toggle('sidebar-compact', nextCompact);
    localStorage.setItem(ADMIN_SIDEBAR_COMPACT_KEY, nextCompact ? '1' : '0');
    syncAdminSidebarCompactToggle();
}

/* ═══════════════════════════════════════════════════════════════
   SIDEBAR HELPERS
   ═══════════════════════════════════════════════════════════════ */
function closeSidebar() {
    const sb = document.getElementById('sidebar');
    const bd = document.getElementById('sidebarBackdrop');
    if (sb) sb.classList.remove('show');
    if (bd) bd.classList.remove('show');
}

function toggleSidebar() {
    const sb = document.getElementById('sidebar');
    const bd = document.getElementById('sidebarBackdrop');
    if (sb) sb.classList.toggle('show');
    if (bd) bd.classList.toggle('show');
}

/* Update which sidebar link gets the .active highlight */
function updateActiveLink() {
    const currentPath = window.location.pathname;
    document.querySelectorAll('#sidebar .sidebar-link').forEach(link => {
        const href = link.getAttribute('href');
        if (!href) return;
        const isActive = href === currentPath
            || (href !== '/' && currentPath.startsWith(href));
        link.classList.toggle('active', isActive);
    });
}

/* Ensure collapsed-sidebar tooltip labels are available from actual link text */
function hydrateSidebarLinkLabels() {
    document.querySelectorAll('#sidebar .sidebar-link').forEach(link => {
        const existing = link.getAttribute('data-sidebar-label');
        const label = (existing || link.textContent || '').replace(/\s+/g, ' ').trim();
        if (!label) return;
        link.setAttribute('data-sidebar-label', label);
        if (!link.getAttribute('aria-label')) {
            link.setAttribute('aria-label', label);
        }
    });
}

function triggerUiOpenAnimation() {
    const root = document.documentElement;
    if (!root) return;

    root.classList.remove('ui-ready');
    root.classList.add('ui-preload');

    // Double rAF ensures preload styles apply before entering the ready state.
    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            root.classList.remove('ui-preload');
            root.classList.add('ui-ready');
        });
    });
}

function initManagedAcademicFields() {
    document.querySelectorAll('[data-academic-college="true"]').forEach((collegeSelect) => {
        const form = collegeSelect.form;
        if (!form || form.dataset.academicManagedInit === '1') {
            return;
        }

        const degreeProgramSelect = form.querySelector('[data-academic-degree-program="true"]');
        const courseInput = form.querySelector('[data-academic-course="true"]');

        if (!degreeProgramSelect || !courseInput) {
            return;
        }

        form.dataset.academicManagedInit = '1';

        const originalOptions = Array.from(degreeProgramSelect.options)
            .filter((option) => option.value !== '')
            .map((option) => ({
                value: option.value,
                label: option.textContent || option.value,
                collegeName: option.dataset.collegeName || '',
                courseCode: option.dataset.courseCode || '',
            }));

        function syncCourseFromSelection() {
            const selected = originalOptions.find((option) => option.value === degreeProgramSelect.value);
            courseInput.value = selected ? selected.courseCode : '';

            if (selected && collegeSelect.value === '') {
                collegeSelect.value = selected.collegeName;
            }
        }

        function renderDegreePrograms() {
            const selectedCollege = collegeSelect.value;
            const selectedDegreeProgram = degreeProgramSelect.dataset.selectedValue || degreeProgramSelect.value;

            const matchingOptions = originalOptions.filter((option) => {
                return selectedCollege === '' || option.collegeName === selectedCollege;
            });

            degreeProgramSelect.innerHTML = '';

            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = selectedCollege === '' ? '— Select Degree Program —' : '— Select Degree Program —';
            degreeProgramSelect.appendChild(placeholder);

            matchingOptions.forEach((option) => {
                const element = document.createElement('option');
                element.value = option.value;
                element.textContent = option.label;
                element.dataset.collegeName = option.collegeName;
                element.dataset.courseCode = option.courseCode;

                if (option.value === selectedDegreeProgram) {
                    element.selected = true;
                }

                degreeProgramSelect.appendChild(element);
            });

            if (!matchingOptions.some((option) => option.value === selectedDegreeProgram)) {
                degreeProgramSelect.value = '';
            }

            syncCourseFromSelection();
        }

        collegeSelect.addEventListener('change', () => {
            degreeProgramSelect.dataset.selectedValue = degreeProgramSelect.value;
            renderDegreePrograms();
        });

        degreeProgramSelect.addEventListener('change', () => {
            degreeProgramSelect.dataset.selectedValue = degreeProgramSelect.value;
            syncCourseFromSelection();
        });

        degreeProgramSelect.dataset.selectedValue = degreeProgramSelect.value;
        renderDegreePrograms();
    });
}

/* ═══════════════════════════════════════════════════════════════
   EVENT DELEGATION (click) — survives every Turbo body swap
   ═══════════════════════════════════════════════════════════════ */
document.addEventListener('click', (e) => {
    /* Admin: compact sidebar toggle */
    if (e.target.closest('#adminSidebarCompactToggle')) {
        e.preventDefault();
        toggleAdminSidebarCompact();
        return;
    }

    /* Sidebar toggle button */
    if (e.target.closest('#sidebarToggle')) {
        toggleSidebar();
        return;
    }
    /* Backdrop click → close */
    if (e.target.id === 'sidebarBackdrop') {
        closeSidebar();
        return;
    }
    /* Sidebar nav-link → close sidebar on mobile */
    if (e.target.closest('#sidebar .sidebar-link')) {
        closeSidebar();
    }

    /* ── User dropdown toggle ── */
    const ddBtn = e.target.closest('#userDropdownBtn');
    if (ddBtn) {
        const menu = document.getElementById('userDropdownMenu');
        if (menu) menu.classList.toggle('hidden');
        return;
    }
    /* Close dropdown when clicking outside */
    if (!e.target.closest('#userDropdownWrap')) {
        const menu = document.getElementById('userDropdownMenu');
        if (menu) menu.classList.add('hidden');
    }

    /* Guest navbar toggle */
    if (e.target.closest('#guestNavToggle')) {
        const mobileNav = document.getElementById('guestNavMobile');
        if (mobileNav) mobileNav.classList.toggle('show');
    }
    /* Close guest nav when link clicked */
    if (e.target.closest('#guestNavMobile a')) {
        const mobileNav = document.getElementById('guestNavMobile');
        if (mobileNav) mobileNav.classList.remove('show');
    }

    /* Role selector buttons on registration page */
    const roleBtn = e.target.closest('.role-select-btn');
    if (roleBtn) {
        const radio = roleBtn.querySelector('input[type="radio"]');
        if (radio) {
            radio.checked = true;
            const container = roleBtn.closest('.flex') || roleBtn.parentElement;
            container.querySelectorAll('.role-select-btn').forEach(btn => btn.classList.remove('active'));
            roleBtn.classList.add('active');
        }
    }

    /* ── Tab switching (custom data-tab-target) ── */
    const tabBtn = e.target.closest('[data-tab-target]');
    if (tabBtn) {
        e.preventDefault();
        const target = tabBtn.getAttribute('data-tab-target');
        /* Deactivate all tab buttons in same group */
        const tabGroup = tabBtn.closest('[data-tab-group]') || tabBtn.parentElement;
        tabGroup.querySelectorAll('[data-tab-target]').forEach(t => {
            t.classList.remove('border-norsu', 'text-norsu', 'border-blue-700', 'text-blue-700');
            t.classList.add('border-transparent', 'text-gray-500');
        });
        /* Activate clicked tab */
        tabBtn.classList.add('border-norsu', 'text-norsu');
        tabBtn.classList.remove('border-transparent', 'text-gray-500');
        /* Toggle panes */
        document.querySelectorAll('.tab-pane').forEach(p => p.classList.add('hidden'));
        const panel = document.querySelector(target);
        if (panel) panel.classList.remove('hidden');
    }

    /* ── Modal open ── */
    const modalOpen = e.target.closest('[data-modal-target]');
    if (modalOpen) {
        e.preventDefault();
        const target = modalOpen.getAttribute('data-modal-target');
        const modal = document.getElementById(target);
        if (modal) {
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }
    }
    /* ── Modal close ── */
    if (e.target.closest('[data-modal-close]')) {
        const modal = e.target.closest('.modal-container');
        if (modal) {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
    }
    /* ── Modal backdrop click ── */
    if (e.target.classList.contains('modal-backdrop-layer')) {
        const modal = e.target.closest('.modal-container');
        if (modal) {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
    }
});

/* ═══════════════════════════════════════════════════════════════
   TURBO LIFECYCLE HOOKS
   ═══════════════════════════════════════════════════════════════ */
document.addEventListener('turbo:before-cache', () => {
    closeSidebar();
    const root = document.documentElement;
    if (root) {
        root.classList.remove('ui-preload');
        root.classList.remove('ui-ready');
    }
    const mobileNav = document.getElementById('guestNavMobile');
    if (mobileNav) mobileNav.classList.remove('show');
    /* Remove leftover modal containers so cached snapshot is clean */
    document.querySelectorAll('.modal-container:not(.hidden)').forEach(el => {
        el.classList.add('hidden');
        el.classList.remove('flex');
    });
});

document.addEventListener('turbo:before-visit', () => {
    closeSidebar();
});

document.addEventListener('turbo:load', () => {
    triggerUiOpenAnimation();
    applyAdminSidebarCompactState();
    syncAdminSidebarCompactToggle();
    hydrateSidebarLinkLabels();
    initManagedAcademicFields();
    updateActiveLink();
    closeSidebar();
});

window.addEventListener('resize', () => {
    applyAdminSidebarCompactState();
    syncAdminSidebarCompactToggle();
});
