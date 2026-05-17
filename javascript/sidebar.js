(function () {
    const sidebar = document.getElementById('sidebar');
    const arrow = document.getElementById('sbArrow');
    const mobileToggle = document.getElementById('sbMobileToggle');
    const mobileClose = document.getElementById('sbMobileClose');
    const mobileOverlay = document.getElementById('sbMobileOverlay');
    const KEY = 'sb_open';
    const media = window.matchMedia('(max-width: 900px)');

    const isMobile = () => media.matches;

    function openMobileMenu() {
        sidebar.classList.add('open');
        mobileOverlay.classList.add('active');
        document.body.classList.add('sb-lock');
    }

    function closeMobileMenu() {
        sidebar.classList.remove('open');
        mobileOverlay.classList.remove('active');
        document.body.classList.remove('sb-lock');
    }

    function syncSidebarState() {
        if (isMobile()) {
            closeMobileMenu();
        } else {
            mobileOverlay.classList.remove('active');
            document.body.classList.remove('sb-lock');

            // Apply state without animation on load
            sidebar.classList.add('sb-no-transition');
            if (localStorage.getItem(KEY) === 'true') {
                sidebar.classList.add('open');
            } else {
                sidebar.classList.remove('open');
            }

            // Force reflow and remove the no-transition class
            void sidebar.offsetWidth;
            setTimeout(() => {
                sidebar.classList.remove('sb-no-transition');
            }, 10);
        }
    }

    syncSidebarState();

    function applyTableLabels() {
        document.querySelectorAll('table.table-stack').forEach(table => {
            const headers = Array.from(table.querySelectorAll('thead th')).map(th => th.textContent.trim());
            if (headers.length === 0) return;
            table.querySelectorAll('tbody tr').forEach(row => {
                let colIndex = 0;
                row.querySelectorAll('td').forEach(td => {
                    const span = parseInt(td.getAttribute('colspan') || '1', 10);
                    if (span > 1) {
                        colIndex += span;
                        return;
                    }
                    if (!td.dataset.label) {
                        td.dataset.label = headers[colIndex] || '';
                    }
                    colIndex += 1;
                });
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', applyTableLabels);
    } else {
        applyTableLabels();
    }

    arrow.addEventListener('click', () => {
        if (isMobile()) return;
        sidebar.classList.toggle('open');
        localStorage.setItem(KEY, sidebar.classList.contains('open'));
    });

    mobileToggle.addEventListener('click', () => {
        if (!isMobile()) return;
        if (sidebar.classList.contains('open')) closeMobileMenu();
        else openMobileMenu();
    });

    mobileOverlay.addEventListener('click', () => {
        if (isMobile()) closeMobileMenu();
    });

    if (mobileClose) {
        mobileClose.addEventListener('click', () => {
            if (isMobile()) closeMobileMenu();
        });
    }

    media.addEventListener('change', syncSidebarState);

    const nav = document.querySelector('aside nav');
    const SCROLL_KEY = 'sb_scroll';

    // Restore scroll position
    const savedScroll = sessionStorage.getItem(SCROLL_KEY);
    if (savedScroll && nav) {
        nav.scrollTop = savedScroll;
    }

    // Save scroll position before navigation
    document.querySelectorAll('aside nav a, .sb-logout a').forEach(a => {
        a.addEventListener('click', function () {
            if (nav) {
                sessionStorage.setItem(SCROLL_KEY, nav.scrollTop);
            }
            if (isMobile()) closeMobileMenu();
        });
    });
})();
