

(function () {
    'use strict';

    const sidebar    = document.getElementById('sidebar');
    const menuToggle = document.getElementById('menuToggle');
    const COLLAPSED_KEY = 'cs_sidebar_collapsed';
    const isMobile = () => window.innerWidth <= 768;

    /* ---------- Persist collapse state ---------- */
    function setCollapsed(collapsed) {
        if (isMobile()) return;
        sidebar.classList.toggle('collapsed', collapsed);
        try { localStorage.setItem(COLLAPSED_KEY, collapsed ? '1' : '0'); } catch (_) {}
    }

    function loadState() {
        if (isMobile()) return;
        try {
            const saved = localStorage.getItem(COLLAPSED_KEY);
            if (saved === '1') sidebar.classList.add('collapsed');
        } catch (_) {}
    }

    /* ---------- Toggle ---------- */
    menuToggle.addEventListener('click', function () {
        if (isMobile()) {
            sidebar.classList.toggle('mobile-open');
        } else {
            const isNowCollapsed = !sidebar.classList.contains('collapsed');
            setCollapsed(isNowCollapsed);
        }
    });

    /* ---------- Close mobile sidebar on nav click ---------- */
    sidebar.querySelectorAll('.nav-item').forEach(function (item) {
        item.addEventListener('click', function () {
            if (isMobile()) {
                sidebar.classList.remove('mobile-open');
            }
        });
    });

    /* ---------- Close mobile sidebar on outside click ---------- */
    document.addEventListener('click', function (e) {
        if (isMobile() && sidebar.classList.contains('mobile-open')) {
            if (!sidebar.contains(e.target) && !menuToggle.contains(e.target)) {
                sidebar.classList.remove('mobile-open');
            }
        }
    });

    /* ---------- Handle resize ---------- */
    window.addEventListener('resize', function () {
        if (!isMobile()) {
            sidebar.classList.remove('mobile-open');
            loadState();
        } else {
            sidebar.classList.remove('collapsed');
        }
    });

    /* ---------- Init ---------- */
    loadState();

})();