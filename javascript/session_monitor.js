/**
 * session_monitor.js
 * ─────────────────────────────────────────────────────────────────────────────
 * • Inactivity timeout: 30 minutes of no mouse / keyboard / touch / scroll
 *   → Warning modal shown at 1 minute remaining
 *   → Auto-logout (POST to auto_logout.php?reason=timeout) when time runs out
 *
 * • Note: Browser/tab close detection has been DISABLED to prevent false
 *   positives during navigation and refreshes.
 * ─────────────────────────────────────────────────────────────────────────────
 */
(function () {
    'use strict';

    /* ── Configuration ───────────────────────────────────────────── */
    const TIMEOUT_MS = 3 * 60 * 1000;    // 3 minutes
    const WARN_BEFORE_MS = 15 * 1000;       // show warning 15 seconds before logout
    const ENDPOINT = 'auto_logout.php';

    /* ── State ────────────────────────────────────────────────────── */
    let inactivityTimer = null;
    let countdownInterval = null;
    let warningVisible = false;
    let secondsLeft = 0;
    let lastResetTime = 0;

    /* ── Helper: POST logout ────── */
    function sendLogout(reason) {
        const body = JSON.stringify({ reason: reason });
        const blob = new Blob([body], { type: 'application/json' });
        navigator.sendBeacon(ENDPOINT, blob);
    }

    /* ── Hard redirect to login after logging out ─────────────────── */
    function performLogout(reason) {
        clearTimers();
        sendLogout(reason);
        // Give the beacon a small head-start, then redirect
        setTimeout(function () {
            window.location.href = 'index.php?timeout=1';
        }, 200);
    }

    /* ── Timer management ─────────────────────────────────────────── */
    function clearTimers() {
        if (inactivityTimer) clearTimeout(inactivityTimer);
        if (countdownInterval) clearInterval(countdownInterval);
        inactivityTimer = null;
        countdownInterval = null;
    }

    function resetInactivityTimer(force = false) {
        if (warningVisible) return;   // don't reset while warning is shown
        const now = Date.now();
        if (!force && now - lastResetTime < 5000) return; // Only reset at most once every 5 seconds
        lastResetTime = now;

        clearTimers();
        inactivityTimer = setTimeout(showWarning, TIMEOUT_MS - WARN_BEFORE_MS);
    }

    /* ── Warning modal ────────────────────────────────────────────── */
    function buildModal() {
        if (document.getElementById('sm-timeout-modal')) return;

        const style = document.createElement('style');
        style.textContent = [
            '#sm-timeout-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.55);',
            'display:flex;align-items:center;justify-content:center;z-index:99999;',
            'padding:16px;animation:smFadeIn .2s ease}',
            '@keyframes smFadeIn{from{opacity:0}to{opacity:1}}',
            '#sm-timeout-modal{width:min(420px,100%);background:#fff;border-radius:16px;',
            'box-shadow:0 24px 64px rgba(2,6,23,.3);overflow:hidden;',
            'border:1px solid #e2e8f0;font-family:inherit;',
            'animation:smSlideUp .25s cubic-bezier(.16,1,.3,1)}',
            '@keyframes smSlideUp{from{transform:translateY(24px);opacity:0}to{transform:none;opacity:1}}',
            '.sm-head{padding:18px 20px;background:linear-gradient(135deg,#f59e0b,#d97706);',
            'display:flex;align-items:center;gap:10px}',
            '.sm-head-icon{font-size:1.5rem;line-height:1}',
            '.sm-head-title{font-weight:700;color:#fff;font-size:1rem;letter-spacing:.01em}',
            '.sm-body{padding:18px 20px;color:#334155;font-size:.95rem;line-height:1.55}',
            '.sm-countdown{display:inline-block;font-weight:800;color:#b45309;font-size:1.05rem}',
            '.sm-actions{display:flex;gap:10px;justify-content:flex-end;',
            'padding:14px 20px;border-top:1px solid #e2e8f0;background:#f8fafc}',
            '.sm-btn{border:1px solid transparent;border-radius:10px;padding:9px 18px;',
            'font-weight:600;cursor:pointer;font-size:.9rem;transition:background .15s,transform .1s}',
            '.sm-btn:active{transform:scale(.97)}',
            '.sm-btn-stay{background:#e2e8f0;color:#0f172a}',
            '.sm-btn-stay:hover{background:#cbd5e1}',
            '.sm-btn-logout{background:#dc2626;color:#fff}',
            '.sm-btn-logout:hover{background:#b91c1c}',
        ].join('');
        document.head.appendChild(style);

        const backdrop = document.createElement('div');
        backdrop.id = 'sm-timeout-backdrop';
        backdrop.innerHTML = [
            '<div id="sm-timeout-modal" role="alertdialog" aria-modal="true"',
            '     aria-labelledby="smModalTitle" aria-describedby="smModalDesc">',
            '  <div class="sm-head">',
            '    <span class="sm-head-icon">⏰</span>',
            '    <span class="sm-head-title" id="smModalTitle">Inactivity Warning</span>',
            '  </div>',
            '  <div class="sm-body" id="smModalDesc">',
            '    You have been inactive for a while. You will be automatically logged out in',
            '    <span id="smCountdown" class="sm-countdown"></span>.',
            '    <br><br>Click <strong>Stay Logged In</strong> to continue your session.',
            '  </div>',
            '  <div class="sm-actions">',
            '    <button type="button" class="sm-btn sm-btn-logout" id="smBtnLogout">Log Out Now</button>',
            '    <button type="button" class="sm-btn sm-btn-stay"   id="smBtnStay">Stay Logged In</button>',
            '  </div>',
            '</div>',
        ].join('');
        document.body.appendChild(backdrop);

        document.getElementById('smBtnStay').addEventListener('click', dismissWarning);
        document.getElementById('smBtnLogout').addEventListener('click', function () {
            performLogout('timeout');
        });
    }

    function formatSeconds(s) {
        const m = Math.floor(s / 60);
        const sec = s % 60;
        if (m > 0) return m + ' min ' + (sec > 0 ? sec + ' sec' : '');
        return sec + ' sec';
    }

    function showWarning() {
        warningVisible = true;
        buildModal();

        secondsLeft = Math.round(WARN_BEFORE_MS / 1000);
        const countdownEl = document.getElementById('smCountdown');
        if (countdownEl) countdownEl.textContent = formatSeconds(secondsLeft);

        const backdrop = document.getElementById('sm-timeout-backdrop');
        if (backdrop) backdrop.style.display = 'flex';

        document.body.style.overflow = 'hidden';

        countdownInterval = setInterval(function () {
            secondsLeft -= 1;
            if (countdownEl) countdownEl.textContent = formatSeconds(secondsLeft);
            if (secondsLeft <= 0) {
                clearInterval(countdownInterval);
                performLogout('timeout');
            }
        }, 1000);

        // Focus the "Stay Logged In" button for accessibility
        const stayBtn = document.getElementById('smBtnStay');
        if (stayBtn) stayBtn.focus();
    }

    function dismissWarning() {
        warningVisible = false;
        const backdrop = document.getElementById('sm-timeout-backdrop');
        if (backdrop) backdrop.style.display = 'none';
        document.body.style.overflow = '';
        if (countdownInterval) clearInterval(countdownInterval);
        countdownInterval = null;
        resetInactivityTimer(true);
    }

    /* ── Activity event listeners ─────────────────────────────────── */
    const ACTIVITY_EVENTS = ['mousemove', 'keydown', 'mousedown', 'touchstart', 'scroll', 'click'];
    ACTIVITY_EVENTS.forEach(function (evt) {
        document.addEventListener(evt, resetInactivityTimer, { passive: true });
    });

    /* ── Kick off the timer ───────────────────────────────────────── */
    resetInactivityTimer();

})();
