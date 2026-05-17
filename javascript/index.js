document.addEventListener('DOMContentLoaded', () => {
    // Handle timeout toast if present
    const toast = document.getElementById('timeoutToast');
    if (toast) {
        setTimeout(() => {
            toast.classList.add('fade-out');
            setTimeout(() => {
                toast.parentElement.remove();
                // Clean up URL parameters
                const url = new URL(window.location);
                url.searchParams.delete('timeout');
                window.history.replaceState({}, '', url);
            }, 400);
        }, 5000);
    }

    // Force all text input fields to uppercase on input (global for this file)
    document.querySelectorAll('input[type="text"]').forEach(input => {
        input.addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });
    });

    const passwordInput = document.getElementById('password');
    if (passwordInput) {
        passwordInput.setAttribute('autocapitalize', 'characters');
        passwordInput.addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });
    }
});

// Animated count-up from 0 to actual values
const stats = {
    total: parseInt(document.body.dataset.total || '0', 10),
    mal: parseInt(document.body.dataset.mal || '0', 10),
    risk: parseInt(document.body.dataset.risk || '0', 10),
    norm: parseInt(document.body.dataset.norm || '0', 10),
};

const pct = {
    mal: parseInt(document.body.dataset.malPct || '0', 10),
    risk: parseInt(document.body.dataset.riskPct || '0', 10),
    norm: parseInt(document.body.dataset.normPct || '0', 10),
};

const targets = {
    'count-total': stats.total,
    'count-mal':   stats.mal,
    'count-risk':  stats.risk,
    'count-norm':  stats.norm,
};

function animateCount(id, end, duration = 1400) {
    const el = document.getElementById(id);
    if (!el || end === 0) return;
    let start = 0;
    const step = Math.ceil(end / (duration / 16));
    const timer = setInterval(() => {
        start = Math.min(start + step, end);
        el.textContent = start.toLocaleString();
        if (start >= end) clearInterval(timer);
    }, 16);
}

// Animate bars
function animateBars() {
    setTimeout(() => {
        const malBar = document.getElementById('bar-mal');
        const riskBar = document.getElementById('bar-risk');
        const normBar = document.getElementById('bar-norm');
        if (malBar) malBar.style.width  = pct.mal + '%';
        if (riskBar) riskBar.style.width = pct.risk + '%';
        if (normBar) normBar.style.width = pct.norm + '%';
    }, 300);
}

window.addEventListener('load', () => {
    Object.entries(targets).forEach(([id, val]) => animateCount(id, val));
    animateBars();
});

window.togglePw = function() {
    const pw  = document.getElementById('password');
    const btn = document.getElementById('eye-btn');
    if (!pw || !btn) return;
    if (pw.type === 'password') {
        pw.type = 'text';
        btn.textContent = '🙈';
    } else {
        pw.type = 'password';
        btn.textContent = '👁️';
    }
};
