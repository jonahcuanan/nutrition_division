(function () {
    const MODAL_ID = 'logout-confirm-modal';
    const STYLE_ID = 'logout-confirm-style';

    let pendingUrl = null;
    let lastFocused = null;

    function getLogoutLink(target) {
        if (!(target instanceof Element)) return null;
        return target.closest('a[href="logout.php"], a[href$="/logout.php"]');
    }

    function ensureStyle() {
        if (document.getElementById(STYLE_ID)) return;
        const style = document.createElement('style');
        style.id = STYLE_ID;
        style.textContent = [
            '.logout-modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.45);display:none;align-items:center;justify-content:center;z-index:9999;padding:16px}',
            '.logout-modal-backdrop.open{display:flex}',
            '.logout-modal{width:min(420px,100%);background:#fff;border-radius:14px;box-shadow:0 20px 60px rgba(2,6,23,.25);overflow:hidden;border:1px solid #e2e8f0;font-family:inherit}',
            '.logout-modal-head{padding:16px 18px;border-bottom:1px solid #e2e8f0;font-weight:700;color:#0f172a;font-size:1rem}',
            '.logout-modal-body{padding:14px 18px;color:#334155;font-size:.95rem;line-height:1.5}',
            '.logout-modal-actions{display:flex;gap:10px;justify-content:flex-end;padding:14px 18px;border-top:1px solid #e2e8f0;background:#f8fafc}',
            '.logout-btn{border:1px solid transparent;border-radius:10px;padding:8px 14px;font-weight:600;cursor:pointer;font-size:.9rem}',
            '.logout-btn-cancel{background:#e2e8f0;color:#0f172a}',
            '.logout-btn-cancel:hover{background:#cbd5e1}',
            '.logout-btn-confirm{background:#dc2626;color:#fff}',
            '.logout-btn-confirm:hover{background:#b91c1c}'
        ].join('');
        document.head.appendChild(style);
    }

    function ensureModal() {
        let modal = document.getElementById(MODAL_ID);
        if (modal) return modal;

        modal = document.createElement('div');
        modal.id = MODAL_ID;
        modal.className = 'logout-modal-backdrop';
        modal.setAttribute('aria-hidden', 'true');
        modal.innerHTML = [
            '<div class="logout-modal" role="dialog" aria-modal="true" aria-labelledby="logoutModalTitle" aria-describedby="logoutModalDesc">',
            '<div class="logout-modal-head" id="logoutModalTitle">Confirm Logout</div>',
            '<div class="logout-modal-body" id="logoutModalDesc">Are you sure you want to log out?</div>',
            '<div class="logout-modal-actions">',
            '<button type="button" class="logout-btn logout-btn-cancel" data-action="cancel">Cancel</button>',
            '<button type="button" class="logout-btn logout-btn-confirm" data-action="confirm">Log Out</button>',
            '</div>',
            '</div>'
        ].join('');

        document.body.appendChild(modal);

        modal.addEventListener('click', function (event) {
            const target = event.target;
            if (!(target instanceof Element)) return;
            const action = target.getAttribute('data-action');

            if (target === modal || action === 'cancel') {
                closeModal();
                return;
            }

            if (action === 'confirm') {
                const url = pendingUrl;
                closeModal();
                if (url) window.location.href = url;
            }
        });

        return modal;
    }

    function openModal(url, triggerEl) {
        pendingUrl = url;
        lastFocused = triggerEl || document.activeElement;
        const modal = ensureModal();
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        const cancelBtn = modal.querySelector('[data-action="cancel"]');
        if (cancelBtn instanceof HTMLElement) cancelBtn.focus();
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        const modal = ensureModal();
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        pendingUrl = null;
        document.body.style.overflow = '';
        if (lastFocused instanceof HTMLElement) {
            lastFocused.focus();
        }
    }

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        const modal = document.getElementById(MODAL_ID);
        if (!modal || !modal.classList.contains('open')) return;
        closeModal();
    });

    document.addEventListener('click', function (event) {
        const link = getLogoutLink(event.target);
        if (!link) return;
        event.preventDefault();
        ensureStyle();
        openModal(link.href, link);
    });
})();
