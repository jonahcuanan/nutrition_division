(function () {
    // Utility to enforce uppercase input values across pages
    const SELECTOR = [
        'input:not([type])',
        'input[type="text"]',
        'input[type="search"]',
        'input[type="tel"]',
        'input[type="url"]',
        'input[type="number"]',
        'textarea'
    ].join(',');

    const SKIP_TYPES = new Set(['password', 'email', 'file', 'checkbox', 'radio', 'color', 'range', 'date', 'datetime-local', 'month', 'time', 'week']);

    function shouldSkip(el) {
        if (!el || el.dataset.keepCase === 'true') return true;
        const type = (el.getAttribute('type') || '').toLowerCase();
        return SKIP_TYPES.has(type);
    }

    function forceUpper(el) {
        if (shouldSkip(el)) return;
        const val = el.value;
        const upper = val.toUpperCase();
        if (val !== upper) {
            const canTrackCaret = document.activeElement === el && typeof el.selectionStart === 'number' && typeof el.selectionEnd === 'number';
            const start = canTrackCaret ? el.selectionStart : null;
            const end = canTrackCaret ? el.selectionEnd : null;
            el.value = upper;
            if (canTrackCaret && start !== null && end !== null) {
                el.setSelectionRange(start, end);
            }
        }
    }

    function wire(el) {
        if (!el || shouldSkip(el)) return;
        if (!el.classList.contains('force-uppercase')) {
            el.classList.add('force-uppercase');
            forceUpper(el);
            el.addEventListener('input', () => forceUpper(el));
            el.addEventListener('change', () => forceUpper(el));
        }
    }

    function scan(root = document) {
        root.querySelectorAll(SELECTOR).forEach(wire);
    }

    function injectStyle() {
        if (document.getElementById('force-uppercase-style')) return;
        const style = document.createElement('style');
        style.id = 'force-uppercase-style';
        style.textContent = '.force-uppercase { text-transform: uppercase; }';
        document.head.appendChild(style);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            injectStyle();
            scan();
        });
    } else {
        injectStyle();
        scan();
    }

    // Observe dynamically added inputs
    const observer = new MutationObserver((mutations) => {
        mutations.forEach(m => {
            m.addedNodes.forEach(node => {
                if (!(node instanceof HTMLElement)) return;
                if (node.matches && node.matches(SELECTOR)) {
                    wire(node);
                }
                if (node.querySelectorAll) {
                    scan(node);
                }
            });
        });
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
})();
