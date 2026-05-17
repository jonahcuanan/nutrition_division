// ════════════════════════════════════════════════
//  ACCOUNT SETTINGS – JavaScript
// ════════════════════════════════════════════════

// ── Toast notification system ─────────────────────
function showToast(type, message) {
    const host = document.getElementById('toastContainer');
    if (!host) return;

    // Remove any existing toast to avoid stacking multiple toasts of the same type/message
    const existing = host.querySelector('.toast');
    if (existing) existing.remove();

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    
    const label = document.createElement('div');
    label.className = 'toast-label';
    label.textContent = message;
    
    toast.appendChild(label);
    host.appendChild(toast);

    setTimeout(() => {
        if (toast.parentNode === host) {
            toast.remove();
        }
    }, 4000);
}

function triggerToastsFromHost() {
    const host = document.getElementById('toastContainer');
    if (!host) return;

    const successMsg = host.getAttribute('data-success') || host.dataset.success || '';
    const errorMsg = host.getAttribute('data-error') || host.dataset.error || '';

    if (successMsg) {
        showToast('success', successMsg);
        host.setAttribute('data-success', '');
        host.dataset.success = '';
    }
    if (errorMsg) {
        showToast('error', errorMsg);
        host.setAttribute('data-error', '');
        host.dataset.error = '';
    }
}

// ── Field state helpers ──────────────────────────
function setFieldState(fieldEl, state) {
    if (!fieldEl) return;
    fieldEl.classList.remove('field-error', 'field-valid');
    if (state === 'error') fieldEl.classList.add('field-error');
    if (state === 'valid') fieldEl.classList.add('field-valid');
}

function clearFieldState(fieldEl) {
    if (!fieldEl) return;
    fieldEl.classList.remove('field-error', 'field-valid');
}

function showInlineError(fieldEl, msg) {
    let el = fieldEl.querySelector('.field-inline-msg');
    if (!el) {
        el = document.createElement('span');
        el.className = 'inline-error field-inline-msg';
        fieldEl.appendChild(el);
    }
    el.innerHTML = `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>${msg}`;
    el.classList.remove('hidden');
}

function hideInlineError(fieldEl) {
    const el = fieldEl?.querySelector('.field-inline-msg');
    if (el) el.classList.add('hidden');
}

// ── Main init (called on load + after AJAX re-render) ────
function initAccountSettings() {
    triggerToastsFromHost();
    const modal          = document.getElementById('userModal');
    const backdrop       = document.getElementById('modalBackdrop');
    const roleSelect     = document.getElementById('roleSelect');
    const barangaySelect = document.getElementById('barangaySelect');
    const barangayField  = document.getElementById('barangayField');
    const bnsReq         = document.getElementById('bnsReq');
    const userFirstName  = document.getElementById('userFirstName');
    const userLastName   = document.getElementById('userLastName');
    const userMiddleName = document.getElementById('userMiddleName');
    const userSuffix     = document.getElementById('userSuffix');
    const userIdInput    = document.getElementById('userIdInput');
    const generateUserIdBtn = document.getElementById('generateUserIdBtn');

    // ── Modal open / close ──────────────────────
    window.openModal = function () {
        if (!modal) return;
        modal.classList.add('active');
        modal.classList.remove('modal-error', 'modal-success');
        document.body.style.overflow = 'hidden';
    };

    window.closeModal = function () {
        if (!modal) return;
        modal.classList.remove('active', 'modal-error', 'modal-success');
        document.body.style.overflow = '';
        
        // Reset form inputs
        const form = modal.querySelector('form');
        if (form) {
            form.reset();
            // Clear custom validation / borders
            form.querySelectorAll('.field').forEach(fieldEl => {
                clearFieldState(fieldEl);
                hideInlineError(fieldEl);
            });
            const indicator = document.getElementById('pwMatchIndicator');
            if (indicator) indicator.classList.add('hidden');
        }
        
        // Remove success/error banners from the modal body
        const banner = modal.querySelector('#modalAlertBanner');
        if (banner) banner.remove();
    };

    if (backdrop) backdrop.addEventListener('click', window.closeModal);
    document.addEventListener('keydown', e => { if (e.key === 'Escape') window.closeModal(); });

    // Removed strength indicator logic per user request

    // ── Real-time password requirements checklist ─
    // Shows BEFORE the user clicks Create User
    (function initPwRequirements() {
        const pwField  = document.getElementById('newPwField');
        const reqLen   = document.getElementById('pwReqLen');
        const reqUpper = document.getElementById('pwReqUpper');
        const reqNum   = document.getElementById('pwReqNum');
        const reqList  = document.getElementById('newPwReqList');
        if (!pwField || !reqList) return;

        function toggleReq(el, met) {
            if (!el) return;
            el.classList.toggle('met', met);
            const dot = el.querySelector('.pw-req-dot');
            if (dot) dot.textContent = met ? '✓' : '✓';
        }

        function evaluateRequirements() {
            const pw      = pwField.value;
            const hasLen  = pw.length >= 6;
            const hasNum  = pw.match(/[0-9]/);
            const allMet  = hasLen && hasNum;

            toggleReq(reqLen,   hasLen);
            toggleReq(reqNum,   hasNum);

            const fieldEl = pwField.closest('.field');
            if (pw.length > 0) {
                // Field-level border feedback
                if (!hasLen) {
                    setFieldState(fieldEl, 'error');
                } else {
                    allMet ? setFieldState(fieldEl, 'valid') : clearFieldState(fieldEl);
                }
            } else {
                clearFieldState(fieldEl);
            }
        }

        pwField.addEventListener('input', evaluateRequirements);
        pwField.addEventListener('blur',  () => {
            const pw      = pwField.value;
            const fieldEl = pwField.closest('.field');
            if (pw === '') {
                setFieldState(fieldEl, 'error');
                showInlineError(fieldEl, 'Password is required.');
            } else if (pw.length < 6) {
                setFieldState(fieldEl, 'error');
                showInlineError(fieldEl, 'Password must be at least 6 characters.');
            } else {
                hideInlineError(fieldEl);
            }
        });
        pwField.addEventListener('input', () => {
            if (pwField.value.length >= 6) hideInlineError(pwField.closest('.field'));
        });
    })();

    // ── Live password-match indicator ───────────
    (function initPasswordMatch() {
        const pwField   = document.getElementById('newPwField');
        const pwConfirm = document.getElementById('newPwConfirm');
        const indicator = document.getElementById('pwMatchIndicator');
        const matchIcon = document.getElementById('pwMatchIcon');
        const matchText = document.getElementById('pwMatchText');
        if (!pwField || !pwConfirm || !indicator) return;

        const CHECK = `<polyline points="20 6 9 17 4 12"/>`;
        const CROSS = `<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>`;

        function evaluate() {
            const pw  = pwField.value;
            const cfg = pwConfirm.value;
            if (!cfg) { indicator.classList.add('hidden'); clearFieldState(pwConfirm.closest('.field')); return; }

            indicator.classList.remove('hidden');
            if (pw === cfg) {
                indicator.classList.add('match');
                indicator.classList.remove('mismatch');
                if (matchIcon) matchIcon.innerHTML = CHECK;
                if (matchText) matchText.textContent = 'Passwords match';
                setFieldState(pwConfirm.closest('.field'), 'valid');
            } else {
                indicator.classList.add('mismatch');
                indicator.classList.remove('match');
                if (matchIcon) matchIcon.innerHTML = CROSS;
                if (matchText) matchText.textContent = 'Passwords do not match';
                setFieldState(pwConfirm.closest('.field'), 'error');
            }
        }

        pwField.addEventListener('input',   evaluate);
        pwConfirm.addEventListener('input', evaluate);
    })();

    // ── Required-field blur validation ──────────
    (function initRequiredValidation() {
        const form = document.getElementById('createUserForm');
        if (!form) return;

        form.querySelectorAll('input[required], select[required]').forEach(input => {
            // Skip password — handled by pw-requirements block above
            if (input.id === 'newPwField' || input.id === 'newPwConfirm') return;

            input.addEventListener('blur', () => {
                const fieldEl = input.closest('.field');
                if (!fieldEl || fieldEl.classList.contains('field-locked')) return;
                if (input.value.trim() === '') {
                    setFieldState(fieldEl, 'error');
                    showInlineError(fieldEl, 'This field is required.');
                } else {
                    clearFieldState(fieldEl);
                    hideInlineError(fieldEl);
                }
            });
            input.addEventListener('input', () => {
                if (input.value.trim() !== '') {
                    clearFieldState(input.closest('.field'));
                    hideInlineError(input.closest('.field'));
                }
            });
        });
    })();

    // ── BNS / Health Worker barangay logic ──────
    // Staff → field is visually LOCKED (striped + badge)
    function handleRoleChange() {
        if (!roleSelect || !barangaySelect || !barangayField) return;

        const isBns         = roleSelect.value === 'Barangay Nutrition Scholars';
        const isHw          = roleSelect.value === 'Health Worker';
        const isStaff       = roleSelect.value === 'Staff';
        const needsBarangay = isBns || isHw;

        barangaySelect.required = needsBarangay;
        barangaySelect.disabled = isStaff;

        // Required asterisk
        if (bnsReq) bnsReq.style.display = needsBarangay ? 'inline' : 'none';

        // ── STAFF: lock the field visually ──
        if (isStaff) {
            barangaySelect.value = '';
            barangayField.classList.add('field-locked');
            clearFieldState(barangayField);
            hideInlineError(barangayField);

            // Insert lock badge if not already there
            if (!barangayField.querySelector('.field-locked-badge')) {
                const badge = document.createElement('span');
                badge.className = 'field-locked-badge';
                badge.innerHTML = `
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                    </svg>
                    Not required for Staff accounts`;
                barangayField.appendChild(badge);
            }
        } else {
            // ── BNS / Health Worker / Admin: unlock ──
            barangayField.classList.remove('field-locked');
            const badge = barangayField.querySelector('.field-locked-badge');
            if (badge) badge.remove();

            if (needsBarangay) {
                // Highlight as required if empty
                if (!barangaySelect.value) {
                    clearFieldState(barangayField); // don't pre-error on switch
                }
            } else {
                clearFieldState(barangayField);
            }
        }
    }

    if (roleSelect) {
        roleSelect.addEventListener('change', handleRoleChange);
        handleRoleChange(); // apply state on modal open
    }

    // ── Duplicate-user check (first + last name) ─
    let userExistsTimer = null;
    let lastUserKey     = '';
    let lastUserResult  = null;

    function setUserExistsState(exists, message) {
        if (!userLastName || !userFirstName) return;
        const msg = exists
            ? (message || 'This user already exists in the system. Please verify the name before proceeding.')
            : '';

        userLastName.setCustomValidity(msg);

        const firstField = userFirstName.closest('.field');
        const lastField  = userLastName.closest('.field');
        if (msg) {
            setFieldState(firstField, 'error');
            setFieldState(lastField,  'error');
        } else {
            clearFieldState(firstField);
            clearFieldState(lastField);
        }

        const firstErr = document.getElementById('userFirstNameError');
        const lastErr  = document.getElementById('userLastNameError');
        if (firstErr) firstErr.classList.toggle('hidden', !msg);
        if (lastErr)  lastErr.classList.toggle('hidden',  !msg);

        const banner    = document.getElementById('userExistsBanner');
        const bannerMsg = document.getElementById('userExistsMsg');
        if (banner)           banner.classList.toggle('hidden', !msg);
        if (bannerMsg && msg) bannerMsg.textContent = msg;
    }

    function checkUserExists(force = false) {
        const payload = {
            first_name: userFirstName ? userFirstName.value.trim() : '',
            last_name:  userLastName  ? userLastName.value.trim()  : ''
        };
        if (!payload.first_name || !payload.last_name) {
            setUserExistsState(false, '');
            lastUserResult = null;
            return Promise.resolve(false);
        }

        const key = `${payload.first_name}|${payload.last_name}`;
        if (!force && lastUserKey === key && lastUserResult !== null) return Promise.resolve(lastUserResult);

        lastUserKey = key;
        const fd = new FormData();
        Object.keys(payload).forEach(k => fd.append(k, payload[k]));

        return fetch('check_user_exists.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(json => {
                if (!json?.success) { setUserExistsState(false, ''); lastUserResult = null; return false; }
                const exists = !!json.exists;
                setUserExistsState(exists, json.message || '');
                lastUserResult = exists;
                return exists;
            })
            .catch(() => { setUserExistsState(false, ''); lastUserResult = null; return false; });
    }

    function scheduleUserCheck() {
        if (userExistsTimer) clearTimeout(userExistsTimer);
        userExistsTimer = setTimeout(() => checkUserExists(), 300);
    }

    [userFirstName, userMiddleName, userLastName, userSuffix].forEach(field => {
        if (!field) return;
        field.addEventListener('input', scheduleUserCheck);
        field.addEventListener('blur',  () => checkUserExists(true));
    });

    // ── Password toggles ────────────────────────
    document.querySelectorAll('.pw-toggle').forEach(btn => {
        btn.addEventListener('click', () => {
            const input = document.getElementById(btn.getAttribute('data-target'));
            if (!input) return;
            const hidden    = input.type === 'password';
            input.type      = hidden ? 'text' : 'password';
            btn.textContent = hidden ? 'Hide' : 'Show';
            btn.setAttribute('aria-label', hidden ? 'Hide password' : 'Show password');
        });
    });

    // ── Duplicate-email check ──────────────────
    let emailCheckTimer = null;
    let lastEmailKey    = '';
    
    async function checkEmailExists(inputEl, excludeId = 0) {
        const email = inputEl.value.trim();
        const fieldEl = inputEl.closest('.field');
        
        if (!email) {
            clearFieldState(fieldEl);
            hideInlineError(fieldEl);
            inputEl.setCustomValidity('');
            return false;
        }

        if (!email.toLowerCase().endsWith('@gmail.com')) {
            setFieldState(fieldEl, 'error');
            showInlineError(fieldEl, 'Email address must end with @gmail.com.');
            inputEl.setCustomValidity('Email address must end with @gmail.com.');
            return true;
        }

        inputEl.setCustomValidity('');

        if (lastEmailKey === email) return false;
        lastEmailKey = email;

        const fd = new FormData();
        fd.append('email', email);
        if (excludeId > 0) fd.append('exclude_user_id', excludeId);

        try {
            const r = await fetch('account_settings.php?action=check_email_exists', { method: 'POST', body: fd });
            const json = await r.json();
            if (json.exists) {
                setFieldState(fieldEl, 'error');
                showInlineError(fieldEl, json.message || 'Email already in use.');
                inputEl.setCustomValidity(json.message || 'Email already in use.');
                return true;
            } else {
                setFieldState(fieldEl, 'valid');
                hideInlineError(fieldEl);
                inputEl.setCustomValidity('');
                return false;
            }
        } catch (e) {
            console.error('Email check error:', e);
            return false;
        }
    }

    const modalEmail = modal ? modal.querySelector('input[name="email"]') : null;
    if (modalEmail) {
        modalEmail.addEventListener('input', () => {
            if (emailCheckTimer) clearTimeout(emailCheckTimer);
            emailCheckTimer = setTimeout(() => checkEmailExists(modalEmail), 500);
        });
        modalEmail.addEventListener('blur', () => checkEmailExists(modalEmail));
    }

    const myAccountEmail = document.querySelector('.card-body input[name="email"]');
    if (myAccountEmail) {
        const excludeIdInput = document.getElementById('myCurrentUserId');
        const excludeId = excludeIdInput ? parseInt(excludeIdInput.value, 10) : 0;
        myAccountEmail.addEventListener('input', () => {
            if (emailCheckTimer) clearTimeout(emailCheckTimer);
            emailCheckTimer = setTimeout(() => checkEmailExists(myAccountEmail, excludeId), 500);
        });
        myAccountEmail.addEventListener('blur', () => checkEmailExists(myAccountEmail, excludeId));
    }

    // ── Edit User Modal (Admin) ────────────────
    window.openEditUserModal = function (data) {
        const editModal = document.getElementById('editUserModal');
        if (!editModal) return;

        document.getElementById('editTargetUserId').value = data.user_id;
        document.getElementById('editUserIdDisplay').value = String(data.user_id).padStart(6, '0');
        document.getElementById('editFirstName').value = data.first_name;
        document.getElementById('editMiddleName').value = data.middle_name || '';
        document.getElementById('editLastName').value = data.last_name;
        document.getElementById('editSuffix').value = data.suffix || '';
        document.getElementById('editContactNumber').value = data.contact_number;
        document.getElementById('editEmail').value = data.email;

        editModal.classList.add('active');
        document.body.style.overflow = 'hidden';
    };

    window.closeEditModal = function () {
        const editModal = document.getElementById('editUserModal');
        if (!editModal) return;
        editModal.classList.remove('active');
        document.body.style.overflow = '';
    };

    // ── Generate user ID button ─────────────────
    if (generateUserIdBtn && userIdInput) {
        generateUserIdBtn.addEventListener('click', () => {
            const randomId = String(Math.floor(100000 + Math.random() * 900000));
            userIdInput.value = randomId;
            clearFieldState(userIdInput.closest('.field'));
            hideInlineError(userIdInput.closest('.field'));
        });
    }
}

initAccountSettings();

// ── Users Account table search ────────────────────
window.filterUsersTable = function () {
    const input     = document.getElementById('usersSearchInput');
    const table     = document.getElementById('usersAccountTable');
    const noResults = document.getElementById('usersNoResults');
    if (!input || !table) return;

    const filter     = input.value.trim().toLowerCase();
    let   anyVisible = false;

    Array.from(table.tBodies[0]?.rows || []).forEach(row => {
        const match = row.textContent.toLowerCase().includes(filter);
        row.style.display = match ? '' : 'none';
        if (match) anyVisible = true;
    });

    if (noResults) noResults.style.display = anyVisible ? 'none' : 'block';
};

// ── Activity Logs table search ────────────────────
window.filterLogsTable = function () {
    const input     = document.getElementById('logsSearchInput');
    const table     = document.getElementById('logsTable');
    const noResults = document.getElementById('logsNoResults');
    if (!input || !table) return;

    const filter     = input.value.trim().toLowerCase();
    let   anyVisible = false;

    Array.from(table.tBodies[0]?.rows || []).forEach(row => {
        const match = row.textContent.toLowerCase().includes(filter);
        row.style.display = match ? '' : 'none';
        if (match) anyVisible = true;
    });

    if (noResults) noResults.style.display = anyVisible ? 'none' : 'block';
};

// ── AJAX form submission ──────────────────────────
document.addEventListener('submit', async function (e) {
    const form = e.target;
    if (!form.closest('.modal-box') && !form.closest('.card-body')) return;

    // ── Edit User Form: dedicated JSON AJAX path ──
    if (form.id === 'editUserForm') {
        e.preventDefault();

        const errors = [];
        const emailInput = form.querySelector('input[name="email"]');
        if (emailInput && emailInput.validationMessage) errors.push(emailInput.validationMessage);

        const getModalBanner = () => {
            const modalBody = form.closest('.modal-body');
            let banner = modalBody.querySelector('#editModalAlertBanner');
            if (!banner) {
                banner = document.createElement('div');
                banner.id = 'editModalAlertBanner';
                modalBody.insertBefore(banner, modalBody.firstChild);
            }
            return banner;
        };

        if (errors.length > 0) {
            const banner = getModalBanner();
            banner.className = 'modal-alert modal-alert-error';
            banner.innerHTML = `<div class="modal-alert-icon">⚠️</div>
                <div class="modal-alert-body">
                    <div class="modal-alert-title">Unable to Save Changes</div>
                    <ul class="modal-alert-list">${errors.map(e => `<li>${e}</li>`).join('')}</ul>
                </div>`;
            banner.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            return;
        }

        const btn = form.querySelector('button[type="submit"]');
        const origHtml = btn ? btn.innerHTML : '';
        if (btn) { btn.innerHTML = '<span class="btn-spinner"></span> Saving…'; btn.classList.add('btn-loading'); btn.disabled = true; }

        try {
            const fd = new FormData(form);
            fd.append('_ajax', '1');
            const res = await fetch(window.location.href, {
                method: 'POST',
                body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const json = await res.json();

            if (json.success) {
                // Close modal
                window.closeEditModal();

                // Refresh the users table by reloading the main content silently
                const refreshRes = await fetch(window.location.href);
                const refreshText = await refreshRes.text();
                const doc = new DOMParser().parseFromString(refreshText, 'text/html');
                const currentMain = document.querySelector('.main-content');
                const newMain = doc.querySelector('.main-content');
                if (currentMain && newMain) currentMain.innerHTML = newMain.innerHTML;

                // Show success toast/alert at top of page using standard showToast
                showToast('success', json.message);

                initAccountSettings();
            } else {
                // Show error in modal
                const banner = getModalBanner();
                banner.className = 'modal-alert modal-alert-error';
                const parts = json.message.split(/\.\s+/).filter(Boolean);
                banner.innerHTML = `<div class="modal-alert-icon">⚠️</div>
                    <div class="modal-alert-body">
                        <div class="modal-alert-title">Unable to Save Changes</div>
                        <ul class="modal-alert-list">${parts.map(p => `<li>${p}</li>`).join('')}</ul>
                    </div>`;
                banner.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        } catch (err) {
            console.error('Edit user error:', err);
            const banner = getModalBanner();
            banner.className = 'modal-alert modal-alert-error';
            banner.innerHTML = `<div class="modal-alert-icon">⚠️</div>
                <div class="modal-alert-body">
                    <div class="modal-alert-title">Connection Error</div>
                    <div class="modal-alert-text">Something went wrong. Please try again.</div>
                </div>`;
        } finally {
            if (btn) { btn.innerHTML = origHtml; btn.classList.remove('btn-loading'); btn.disabled = false; }
        }
        return;
    }

    // Final check before submission (all other forms)
    const errors = [];

    // Validation for User Creation
    if (form.id === 'createUserForm') {
        const pw = form.querySelector('#newPwField')?.value || '';
        const cfg = form.querySelector('#newPwConfirm')?.value || '';

        if (pw.length < 6) errors.push('Password must be at least 6 characters long.');
        if (!/[0-9]/.test(pw)) errors.push('Password must contain at least one number.');
        if (pw !== cfg) errors.push('Password and Confirm Password do not match.');

        const emailInput = form.querySelector('input[name="email"]');
        if (emailInput && emailInput.validationMessage) errors.push(emailInput.validationMessage);

        const lastNameInput = form.querySelector('#userLastName');
        if (lastNameInput && lastNameInput.validationMessage) errors.push(lastNameInput.validationMessage);
    }

    // Validation for My Account Updates
    if (form.querySelector('input[name="update_account"]')) {
        const pw = form.querySelector('#myPassword')?.value || '';
        const cfg = form.querySelector('#myPasswordConfirm')?.value || '';

        if (pw !== '') {
            if (pw.length < 6) errors.push('New password must be at least 6 characters long.');
            if (!/[0-9]/.test(pw)) errors.push('New password must contain at least one number.');
            if (pw !== cfg) errors.push('New passwords do not match.');
        }

        const emailInput = form.querySelector('input[name="email"]');
        if (emailInput && emailInput.validationMessage) errors.push(emailInput.validationMessage);
    }

    if (errors.length > 0) {
        e.preventDefault();

        const modalBox = form.closest('.modal-box');
        const cardBody = form.closest('.card-body');

        if (modalBox) {
            const modalBody = form.closest('.modal-body');
            let banner = modalBody.querySelector('#modalAlertBanner');
            if (!banner) {
                banner = document.createElement('div');
                banner.id = 'modalAlertBanner';
                modalBody.insertBefore(banner, modalBody.firstChild);
            }
            banner.className = 'modal-alert modal-alert-error active';
            banner.innerHTML = `
                <div class="modal-alert-icon">⚠️</div>
                <div class="modal-alert-body">
                    <div class="modal-alert-title">Unable to Save Changes</div>
                    <ul class="modal-alert-list">
                        ${errors.map(err => `<li>${err}</li>`).join('')}
                    </ul>
                </div>`;
            banner.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        } else if (cardBody) {
            const card = cardBody.closest('.card');
            let banner = card ? card.querySelector('.account-inline-alert') : null;
            if (!banner) {
                banner = document.createElement('div');
                banner.className = 'alert error account-inline-alert';
                banner.style.cssText = 'margin: 0 0 16px 0; animation: slideDown .25s ease;';
                cardBody.insertBefore(banner, cardBody.firstChild);
            }
            banner.innerHTML = `<span class="alert-icon">⚠️</span>
                <ul style="margin:0;padding-left:16px;line-height:1.8;">${errors.map(err => `<li>${err}</li>`).join('')}</ul>`;
            banner.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        return;
    }

    e.preventDefault();

    const isModal  = !!form.closest('.modal-box');
    const btn      = form.querySelector('button[type="submit"]');
    const origHtml = btn ? btn.innerHTML : '';

    if (btn) {
        btn.innerHTML = '<span class="btn-spinner"></span> Saving…';
        btn.classList.add('btn-loading');
        btn.disabled  = true;
    }

    try {
        const formData = new FormData(form);
        const response = await fetch(window.location.href, { method: 'POST', body: formData });
        const text     = await response.text();
        const doc      = new DOMParser().parseFromString(text, 'text/html');

        const currentMain = document.querySelector('.main-content');
        const newMain     = doc.querySelector('.main-content');
        if (currentMain && newMain) currentMain.innerHTML = newMain.innerHTML;

        const currentModal = document.getElementById('userModal');
        const newModal     = doc.getElementById('userModal');
        if (currentModal && newModal) {
            currentModal.innerHTML = newModal.innerHTML;
            
            const isSuccess = newModal.classList.contains('modal-success');
            if (isSuccess && isModal) {
                if (typeof window.closeModal === 'function') {
                    window.closeModal();
                } else {
                    currentModal.classList.remove('active', 'modal-error', 'modal-success');
                    document.body.style.overflow = '';
                }
            } else {
                ['active', 'modal-error', 'modal-success'].forEach(cls =>
                    currentModal.classList.toggle(cls, newModal.classList.contains(cls))
                );

                if (isModal) {
                    const banner = currentModal.querySelector('#modalAlertBanner');
                    if (banner) banner.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            }
        }

        initAccountSettings();
        if (typeof updateSidebarCounters === 'function') updateSidebarCounters();



    } catch (err) {
        console.error('Form submission error:', err);

        if (isModal) {
            const modal = document.getElementById('userModal');
            if (modal) {
                modal.classList.add('active', 'modal-error');
                const body = modal.querySelector('.modal-body');
                if (body) {
                    const existing = body.querySelector('#modalAlertBanner');
                    if (existing) existing.remove();
                    const banner = document.createElement('div');
                    banner.id        = 'modalAlertBanner';
                    banner.className = 'modal-alert modal-alert-error';
                    banner.innerHTML = `
                        <div class="modal-alert-icon">⚠️</div>
                        <div class="modal-alert-body">
                            <div class="modal-alert-title">Connection Error</div>
                            <div class="modal-alert-text">Something went wrong. Please check your connection and try again.</div>
                        </div>`;
                    body.insertBefore(banner, body.firstChild);
                }
            }
        }
    } finally {
        if (btn && document.contains(btn)) {
            btn.innerHTML = origHtml;
            btn.classList.remove('btn-loading');
            btn.disabled  = false;
        }
    }
});