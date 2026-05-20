document.addEventListener('DOMContentLoaded', () => {
    // Force all text input fields to uppercase on input (for both child and guardian fields)
    document.querySelectorAll('#childForm input[type="text"]').forEach(input => {
        input.addEventListener('input', function () {
            this.value = this.value.toUpperCase();
        });
    });

    // ── IP toggle ──
    const addressField = document.getElementById('addressField');
    const isIpField = document.getElementById('isIpField');
    const addressPrefix = 'PUROK ';
    const purokOnlyPattern = /^PUROK\s*$/;
    const addressWarning = document.getElementById('addressWarning');

    function enforceAddressPrefix(restoreCaret = true) {
        if (!addressField) return;
        const prevStart = addressField.selectionStart;
        const prevEnd = addressField.selectionEnd;
        const raw = addressField.value || '';
        const upper = raw.toUpperCase();
        let next = upper;

        if (!upper.startsWith(addressPrefix)) {
            const stripped = upper.replace(/^PUROK\s*/i, '').trimStart();
            next = addressPrefix + stripped;
        }

        if (next !== addressField.value) {
            addressField.value = next;
        }

        if (restoreCaret) {
            const minPos = addressPrefix.length;
            const safeStart = Math.max(prevStart || minPos, minPos);
            const safeEnd = Math.max(prevEnd || minPos, minPos);
            addressField.setSelectionRange(safeStart, safeEnd);
        }
    }

    function hasAddressDetails() {
        if (!addressField) return false;
        const val = addressField.value.trim();
        return val !== '' && !purokOnlyPattern.test(val);
    }

    function validateAddressDetail(showBubble = false) {
        const val = addressField.value.trim();
        let message = '';
        if (!val) {
            message = 'Complete address is required.';
        } else if (purokOnlyPattern.test(val)) {
            message = 'Add details after Purok (e.g., Purok 3).';
        }

        addressField.setCustomValidity(message);
        if (addressWarning) {
            addressWarning.textContent = message;
            addressWarning.classList.toggle('hidden', message === '');
        }
        if (showBubble && message) {
            addressField.reportValidity();
        }
    }

    function toggleIp() { isIpField.disabled = !hasAddressDetails(); }

    addressField.addEventListener('keydown', (event) => {
        const start = addressField.selectionStart || 0;
        const end = addressField.selectionEnd || 0;
        if ((event.key === 'Backspace' && start <= addressPrefix.length) ||
            (event.key === 'Delete' && start < addressPrefix.length && end <= addressPrefix.length)) {
            event.preventDefault();
            enforceAddressPrefix();
        }
    });

    addressField.addEventListener('input', () => {
        enforceAddressPrefix();
        toggleIp();
        validateAddressDetail();
    });

    addressField.addEventListener('focus', () => {
        enforceAddressPrefix();
    });

    addressField.addEventListener('blur', () => validateAddressDetail(true));
    enforceAddressPrefix(false);
    validateAddressDetail();
    toggleIp();

    // Nudge user if they skip address and jump to guardian section
    const guardianFields = [
        'guardianFirstName',
        'guardianMiddleName',
        'guardianLastName',
        'guardianSuffix',
        'guardianRelationship',
        'contactNumber'
    ]
        .map(id => document.getElementById(id))
        .filter(Boolean);

    guardianFields.forEach(field => {
        field.addEventListener('focus', () => {
            validateAddressDetail(true);
            // Pull focus back to address when it is incomplete so the browser bubble appears immediately
            if (addressField.validationMessage) {
                addressField.focus();
            }
        });
    });

    // ── Enforce contact number to be digits only, max 11 digits ──
    const contactNumberFld = document.getElementById('contactNumber');
    if (contactNumberFld) {
        contactNumberFld.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '');
        });
    }

    const birthdateField = document.getElementById('birthdateField');
    const measurementDateField = document.getElementById('measurementDateField');

    // ── Duplicate child check ──
    const childFirstName = document.getElementById('childFirstName');
    const childMiddleName = document.getElementById('childMiddleName');
    const childLastName = document.getElementById('childLastName');
    const childSuffix = document.getElementById('childSuffix');
    const childExistsError = document.getElementById('childExistsError');
    const barangayField = document.getElementById('barangayField');

    let childExistsTimer = null;
    let lastCheckKey = '';
    let lastCheckResult = null;

    function setChildExistsState(exists, message) {
        if (!childLastName) return;
        const msg = exists ? (message || 'This child already exists in the system. Please verify before proceeding.') : '';

        // Block native form submission via constraint validation
        childLastName.setCustomValidity(msg);

        // Red border + background on both First Name and Last Name
        [childFirstName, childLastName].forEach(field => {
            if (!field) return;
            field.classList.toggle('border-rose-400', !!msg);
            field.classList.toggle('ring-1', !!msg);
            field.classList.toggle('ring-rose-200', !!msg);
            field.classList.toggle('bg-rose-50', !!msg);
        });

        // Per-field error hints (one under First Name, one under Last Name)
        const firstNameError = document.getElementById('childFirstNameError');
        const lastNameError = document.getElementById('childExistsError');
        if (firstNameError) {
            firstNameError.classList.toggle('hidden', !msg);
            if (msg) firstNameError.textContent = '⚠ Duplicate name detected.';
        }
        if (lastNameError) {
            lastNameError.classList.toggle('hidden', !msg);
            if (msg) lastNameError.textContent = '⚠ Duplicate name detected.';
        }

        // Prominent inline banner below the name row
        const banner = document.getElementById('childExistsBanner');
        const bannerMsg = document.getElementById('childExistsMsg');
        if (banner) banner.classList.toggle('hidden', !msg);
        if (bannerMsg && msg) bannerMsg.textContent = msg;
    }

    function buildCheckKey(data) {
        return [data.first_name, data.last_name].join('|');
    }

    function getCheckPayload() {
        return {
            first_name: childFirstName ? childFirstName.value.trim() : '',
            last_name: childLastName ? childLastName.value.trim() : ''
        };
    }

    function shouldCheck(payload) {
        return payload.first_name && payload.last_name;
    }

    function checkChildExists(force = false) {
        const payload = getCheckPayload();
        if (!shouldCheck(payload)) {
            setChildExistsState(false, '');
            lastCheckResult = null;
            return Promise.resolve(false);
        }

        const key = buildCheckKey(payload);
        if (!force && lastCheckKey === key && lastCheckResult !== null) {
            return Promise.resolve(lastCheckResult);
        }

        lastCheckKey = key;
        const formData = new FormData();
        Object.keys(payload).forEach(k => formData.append(k, payload[k]));

        return fetch('check_child_exists.php', {
            method: 'POST',
            body: formData
        })
            .then(res => res.json())
            .then(json => {
                if (!json || !json.success) {
                    setChildExistsState(false, '');
                    lastCheckResult = null;
                    return false;
                }
                const exists = !!json.exists;
                setChildExistsState(exists, json.message || '');
                lastCheckResult = exists;
                return exists;
            })
            .catch(() => {
                setChildExistsState(false, '');
                lastCheckResult = null;
                return false;
            });
    }

    // ── Visual checking indicator ──
    function setCheckingState(isChecking) {
        [childFirstName, childLastName].forEach(field => {
            if (!field) return;
            if (isChecking) {
                field.classList.add('opacity-70');
                field.style.backgroundImage = 'none';
            } else {
                field.classList.remove('opacity-70');
            }
        });
        // Show/hide a tiny spinner next to the Last Name label if present
        const spinner = document.getElementById('childCheckSpinner');
        if (spinner) spinner.classList.toggle('hidden', !isChecking);
    }

    // Wrap checkChildExists to add loading state
    const _originalCheckChildExists = checkChildExists;
    function checkChildExistsWithState(force = false) {
        const payload = getCheckPayload();
        if (!shouldCheck(payload)) {
            setChildExistsState(false, '');
            lastCheckResult = null;
            return Promise.resolve(false);
        }
        const key = buildCheckKey(payload);
        if (!force && lastCheckKey === key && lastCheckResult !== null) {
            return Promise.resolve(lastCheckResult);
        }
        setCheckingState(true);
        return checkChildExists(force).finally(() => setCheckingState(false));
    }

    function scheduleChildCheck() {
        if (childExistsTimer) clearTimeout(childExistsTimer);
        // Short 300ms debounce while typing so every keystroke doesn't fire a request
        childExistsTimer = setTimeout(() => checkChildExistsWithState(), 300);
    }

    // On input in name fields: debounced check
    [childFirstName, childMiddleName, childLastName, childSuffix].forEach(field => {
        if (!field) return;
        field.addEventListener('input', scheduleChildCheck);
    });

    // On blur of FIRST or LAST name: fire immediately (no debounce)
    [childFirstName, childLastName].forEach(field => {
        if (!field) return;
        field.addEventListener('blur', () => {
            if (childExistsTimer) { clearTimeout(childExistsTimer); childExistsTimer = null; }
            checkChildExistsWithState();
        });
    });
    // Middle name and suffix blur: regular debounced check
    [childMiddleName, childSuffix].forEach(field => {
        if (!field) return;
        field.addEventListener('blur', () => checkChildExistsWithState());
    });

    // Catch-all: when ANY field BELOW the name row gets focus,
    // immediately re-check so the user sees the warning no matter where they navigate next
    const downstreamIds = [
        'childSuffix', 'childSex', 'birthdateField', 'measurementDateField',
        'heightField', 'weightField', 'addressField', 'barangayField', 'isIpField',
        'guardianFirstName', 'guardianLastName', 'guardianMiddleName',
        'guardianSuffix', 'guardianRelationship', 'contactNumber'
    ];
    downstreamIds.forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('focus', () => {
            // Only run if not already flagged to avoid flicker
            if (lastCheckResult === null || lastCheckResult === false) {
                checkChildExistsWithState();
            } else if (lastCheckResult === true) {
                // Re-apply styling in case it was cleared
                setChildExistsState(true, 'This child is already existing on the system. Please verify the name before proceeding.');
            }
        });
    });

    if (barangayField) barangayField.addEventListener('change', scheduleChildCheck);

    // ── Guardian duplicate check ──
    const guardianFirstName = document.getElementById('guardianFirstName');
    const guardianMiddleName = document.getElementById('guardianMiddleName');
    const guardianLastName = document.getElementById('guardianLastName');
    const guardianSuffixFld = document.getElementById('guardianSuffix');
    const guardianExistsBanner = document.getElementById('guardianExistsBanner');
    const guardianExistsMsg = document.getElementById('guardianExistsMsg');

    let guardianExistsTimer = null;
    let lastGuardianKey = '';
    let lastGuardianResult = null;

    function setGuardianExistsState(exists, message) {
        const msg = exists ? (message || 'This guardian already exists in the system.') : '';
        [guardianFirstName, guardianLastName].forEach(field => {
            if (!field) return;
            field.classList.toggle('border-amber-400', !!msg);
            field.classList.toggle('ring-1', !!msg);
            field.classList.toggle('ring-amber-200', !!msg);
            field.classList.toggle('bg-amber-50', !!msg);
        });
        if (guardianExistsBanner) {
            guardianExistsBanner.classList.toggle('hidden', !msg);
        }
        if (guardianExistsMsg && msg) {
            guardianExistsMsg.textContent = msg + ' A new record will still be created unless you use the existing one.';
        }
    }

    function buildGuardianKey(d) {
        return [d.first_name, d.middle_name, d.last_name, d.suffix].join('|');
    }

    function getGuardianPayload() {
        return {
            first_name: guardianFirstName ? guardianFirstName.value.trim() : '',
            middle_name: guardianMiddleName ? guardianMiddleName.value.trim() : '',
            last_name: guardianLastName ? guardianLastName.value.trim() : '',
            suffix: guardianSuffixFld ? guardianSuffixFld.value.trim() : ''
        };
    }

    function checkGuardianExists(force = false) {
        const payload = getGuardianPayload();
        if (!payload.first_name || !payload.last_name) {
            setGuardianExistsState(false, '');
            lastGuardianResult = null;
            return Promise.resolve(false);
        }
        const key = buildGuardianKey(payload);
        if (!force && lastGuardianKey === key && lastGuardianResult !== null) {
            return Promise.resolve(lastGuardianResult);
        }
        lastGuardianKey = key;
        const formData = new FormData();
        Object.keys(payload).forEach(k => formData.append(k, payload[k]));
        return fetch('check_guardian_exists.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(json => {
                if (!json || !json.success) { setGuardianExistsState(false, ''); lastGuardianResult = null; return false; }
                const exists = !!json.exists;
                setGuardianExistsState(exists, json.message || '');
                lastGuardianResult = exists;
                return exists;
            })
            .catch(() => { setGuardianExistsState(false, ''); lastGuardianResult = null; return false; });
    }

    function scheduleGuardianCheck() {
        if (guardianExistsTimer) clearTimeout(guardianExistsTimer);
        guardianExistsTimer = setTimeout(() => checkGuardianExists(), 250);
    }

    [guardianFirstName, guardianMiddleName, guardianLastName, guardianSuffixFld].forEach(field => {
        if (!field) return;
        field.addEventListener('input', scheduleGuardianCheck);
        // Fire immediately on blur — warns before moving to next field
        field.addEventListener('blur', () => checkGuardianExists());
    });


    function calendarYMDLocal(d = new Date()) {
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${day}`;
    }

    // ── Birthdate + measurement date limits ──
    function setDateLimits() {
        const t = new Date();
        birthdateField.max = calendarYMDLocal(t);
        const min = new Date(t.getFullYear() - 5, t.getMonth(), t.getDate() + 1);
        birthdateField.min = calendarYMDLocal(min);
    }
    setDateLimits();

    function setMeasurementDateMin() {
        if (!measurementDateField) return;
        if (birthdateField.value) {
            measurementDateField.min = birthdateField.value;
        }
    }

    function setMeasurementDateToToday() {
        if (!measurementDateField) return;

        fetch('server_date.php', { cache: 'no-store' })
            .then(res => res.json())
            .then(json => {
                const today = (json && json.success && json.today) ? json.today : calendarYMDLocal();
                measurementDateField.max = today;
                if (!measurementDateField.value) {
                    measurementDateField.value = today;
                }
                setMeasurementDateMin();
                updateAge();
            })
            .catch(() => {
                const today = calendarYMDLocal();
                measurementDateField.max = today;
                if (!measurementDateField.value) {
                    measurementDateField.value = today;
                }
                setMeasurementDateMin();
                updateAge();
            });
    }
    setMeasurementDateToToday();

    // ── Age calculation ──
    const ageField = document.getElementById('ageField');
    const ageBadge = document.getElementById('ageBadge');

    function calcAge(birthStr, measureStr) {
        if (!birthStr || !measureStr) return '';
        const b = new Date(birthStr);
        const m = new Date(measureStr);
        if (isNaN(b.getTime()) || isNaN(m.getTime()) || m < b) return '';
        let months = (m.getFullYear() - b.getFullYear()) * 12 + (m.getMonth() - b.getMonth());
        if (m.getDate() < b.getDate()) months--;
        return months >= 0 ? months : '';
    }

    function ageText(m) {
        if (m === '') return '';
        if (m === 0) return 'Newborn';
        if (m < 12) return `${m} mo`;
        const y = Math.floor(m / 12), r = m % 12;
        return r ? `${y}y ${r}m` : `${y} yr`;
    }

    function updateAge() {
        const measureStr = measurementDateField ? measurementDateField.value : '';
        const m = calcAge(birthdateField.value, measureStr);
        ageField.value = m;
        const txt = ageText(m);
        ageBadge.textContent = txt;
        ageBadge.style.display = txt ? 'inline-block' : 'none';
        updateMuacVisibility();
        requestStatusUpdate();
    }

    birthdateField.addEventListener('change', () => {
        setMeasurementDateMin();
        updateAge();
    });
    birthdateField.addEventListener('input', () => {
        setMeasurementDateMin();
        updateAge();
    });
    if (measurementDateField) {
        measurementDateField.addEventListener('change', updateAge);
        measurementDateField.addEventListener('input', updateAge);
    }

    const GROWTH_STATUS_STYLE_CLASSES = ['status-na', 'status-oor', 'status-severe', 'status-moderate', 'status-over', 'status-normal'];
    const SUM_STATUS_BASE = 'text-[0.84rem] font-semibold inline-block rounded px-1.5 py-0.5';

    function growthStatusTierClass(statusText) {
        const value = String(statusText || '').toLowerCase().trim();
        if (!value || value === 'n/a') return 'status-na';
        if (value.includes('severely stunted') || value.includes('severely underweight') || value.includes('severely wasted')) {
            return 'status-severe';
        }
        if (value.includes('overweight') || value.includes('obese')) return 'status-over';
        if (value.includes('underweight') || value.includes('stunted') || value.includes('wasted') || value.includes('moderately wasted')) {
            return 'status-moderate';
        }
        if (value === 'normal' || value === 'tall') return 'status-normal';
        return 'status-na';
    }

    function applyGrowthStatusFieldStyle(el, statusText) {
        if (!el) return;
        el.classList.remove(...GROWTH_STATUS_STYLE_CLASSES);
        el.classList.add(growthStatusTierClass(statusText));
    }

    function applyGrowthStatusSummary(el, statusText) {
        if (!el) return;
        el.classList.remove(...GROWTH_STATUS_STYLE_CLASSES);
        const t = String(statusText || '').trim();
        if (!t || t === '—') {
            el.className = 'text-[0.84rem] font-semibold text-slate-900';
            return;
        }
        el.className = `${SUM_STATUS_BASE} ${growthStatusTierClass(statusText)}`;
    }

    // ── Growth status auto-calc ──
    const childSexField = document.getElementById('childSex');
    const heightField = document.getElementById('heightField');
    const weightField = document.getElementById('weightField');
    const hfaStatusField = document.getElementById('hfaStatusField');
    const wfaStatusField = document.getElementById('wfaStatusField');
    const wflStatusField = document.getElementById('wflStatusField');
    const muacField = document.getElementById('muacField');
    const muacStatusField = document.getElementById('muacStatusField');
    const statusMessage = document.getElementById('statusMessage');
    let statusTimer = null;

    function clearStatusFields() {
        if (hfaStatusField) {
            hfaStatusField.value = '';
            applyGrowthStatusFieldStyle(hfaStatusField, '');
        }
        if (wfaStatusField) {
            wfaStatusField.value = '';
            applyGrowthStatusFieldStyle(wfaStatusField, '');
        }
        if (wflStatusField) {
            wflStatusField.value = '';
            applyGrowthStatusFieldStyle(wflStatusField, '');
        }
        if (muacStatusField) {
            muacStatusField.value = '';
            applyGrowthStatusFieldStyle(muacStatusField, '');
        }
        if (statusMessage) statusMessage.textContent = '';
    }

    const muacContainer = document.getElementById('muacContainer');

    function updateMuacVisibility() {
        if (!muacField || !muacContainer || !ageField) return;
        const ageRaw = ageField.value;
        const age = ageRaw === '' || ageRaw === null ? null : parseInt(ageRaw, 10);
        // Show MUAC only for children between 6 and 59 months inclusive
        if (Number.isInteger(age) && age >= 6 && age <= 59) {
            muacContainer.style.display = '';
            muacField.disabled = false;
        } else {
            // Hide and disable for ages <6 months or >=60 months or unknown
            muacContainer.style.display = 'none';
            muacField.disabled = true;
            try { muacField.value = ''; } catch (e) { }
            if (muacStatusField) { muacStatusField.value = ''; applyGrowthStatusFieldStyle(muacStatusField, ''); }
        }
    }

    function requestStatusUpdate() {
        if (!childSexField || !heightField || !weightField || !ageField) return;

        const sex = childSexField.value;
        const age = ageField.value;
        const height = heightField.value;
        const weight = weightField.value;
        const muac = muacField ? muacField.value : '';

        if (!sex || age === '' || (!height && !weight && !muac)) {
            clearStatusFields();
            return;
        }

        if (statusTimer) {
            clearTimeout(statusTimer);
        }

        statusTimer = setTimeout(() => {
            const formData = new FormData();
            formData.append('sex', sex);
            formData.append('age_in_months', age);
            formData.append('height', height || '0');
            formData.append('weight', weight || '0');
            if (muac !== '') formData.append('muac', muac);

            fetch('compute_growth_status.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(json => {
                    if (!json || !json.success || !json.data) {
                        clearStatusFields();
                        if (statusMessage) {
                            statusMessage.textContent = (json && json.message) ? json.message : 'Unable to determine growth status.';
                        }
                        return;
                    }
                    hfaStatusField.value = json.data.height_for_age_status || 'N/A';
                    wfaStatusField.value = json.data.weight_for_age_status || 'N/A';
                    wflStatusField.value = json.data.weight_for_ltht_status || 'N/A';
                    if (muacStatusField) muacStatusField.value = json.data.muac_status || 'N/A';

                    applyGrowthStatusFieldStyle(hfaStatusField, hfaStatusField.value);
                    applyGrowthStatusFieldStyle(wfaStatusField, wfaStatusField.value);
                    applyGrowthStatusFieldStyle(wflStatusField, wflStatusField.value);
                    if (muacStatusField) applyGrowthStatusFieldStyle(muacStatusField, muacStatusField.value);

                    if (statusMessage) statusMessage.textContent = '';
                })
                .catch(() => {
                    clearStatusFields();
                    if (statusMessage) statusMessage.textContent = 'Unable to determine growth status.';
                });
        }, 150);
    }

    if (childSexField) childSexField.addEventListener('change', requestStatusUpdate);
    if (heightField) heightField.addEventListener('input', requestStatusUpdate);
    if (weightField) weightField.addEventListener('input', requestStatusUpdate);
    if (muacField) muacField.addEventListener('input', requestStatusUpdate);
    updateAge();
    if (hfaStatusField) applyGrowthStatusFieldStyle(hfaStatusField, hfaStatusField.value);
    if (wfaStatusField) applyGrowthStatusFieldStyle(wfaStatusField, wfaStatusField.value);
    if (wflStatusField) applyGrowthStatusFieldStyle(wflStatusField, wflStatusField.value);
    if (muacStatusField) applyGrowthStatusFieldStyle(muacStatusField, muacStatusField.value);

    // ── Modal references ──
    const form = document.getElementById('childForm');
    const modal = document.getElementById('confirmModal');
    const modalBox = document.getElementById('confirmModalBox');
    const backdrop = document.getElementById('modalBackdrop');
    const openBtn = document.getElementById('openModalBtn');
    const cancelBtn = document.getElementById('cancelBtn');
    const confirmBtn = document.getElementById('confirmBtn');
    const confirmLabel = confirmBtn ? confirmBtn.querySelector('.btn-label') : null;
    const confirmSpinner = confirmBtn ? confirmBtn.querySelector('.spinner') : null;
    const alertHost = document.getElementById('formAlert');

    // ── User-selection modal references ──
    const userSelectModal = document.getElementById('userSelectModal');
    const userSelectBox = document.getElementById('userSelectBox');
    const userSelectBackBtn = document.getElementById('userSelectBackBtn');
    const userSelectConfirmBtn = document.getElementById('userSelectConfirmBtn');
    const userSelectLoading = document.getElementById('userSelectLoading');
    const userSelectEmpty = document.getElementById('userSelectEmpty');
    const userSelectList = document.getElementById('userSelectList');
    const userSelectBarangayName = document.getElementById('userSelectBarangayName');
    const designatedUserIdInput = document.getElementById('designatedUserId');
    const currentUserRole = document.getElementById('currentUserRole') ? document.getElementById('currentUserRole').value : '';

    let selectedUserId = null;
    let selectedUserName = '';

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function showAlert(type, message) {
        if (!alertHost) return;
        if (!message) {
            alertHost.innerHTML = '';
            return;
        }
        const isSuccess = type === 'success';
        const classes = isSuccess
            ? 'mb-5 flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-[0.85rem] font-medium text-emerald-800'
            : 'mb-5 flex items-center gap-2 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-[0.85rem] font-medium text-rose-800';
        const icon = isSuccess ? '✅' : '⚠️';
        alertHost.innerHTML = `<div class="${classes}">${icon} ${escapeHtml(message)}</div>`;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function showToast(type, message) {
        const host = document.getElementById('toastContainer');
        if (!host || !message) return;

        const toast = document.createElement('div');
        const isSuccess = type === 'success';
        toast.className = `toast ${isSuccess ? 'toast-success' : 'toast-error'}`;

        const label = document.createElement('span');
        label.className = 'toast-label';
        label.textContent = message;
        toast.appendChild(label);

        host.prepend(toast);

        setTimeout(() => {
            toast.style.transition = 'opacity .25s, transform .25s';
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(-6px)';
            setTimeout(() => toast.remove(), 260);
        }, 3800);
    }

    function setConfirmLoading(isLoading) {
        if (!confirmBtn) return;
        if (isLoading) {
            if (confirmLabel) confirmLabel.classList.add('hidden');
            if (confirmSpinner) confirmSpinner.classList.remove('hidden');
            confirmBtn.classList.add('opacity-80', 'pointer-events-none');
        } else {
            if (confirmLabel) confirmLabel.classList.remove('hidden');
            if (confirmSpinner) confirmSpinner.classList.add('hidden');
            confirmBtn.classList.remove('opacity-80', 'pointer-events-none');
        }
    }

    async function openModal() {
        validateAddressDetail(true);
        if (!form.checkValidity()) { form.reportValidity(); return; }

        const exists = await checkChildExists(true);

        if (exists) {
            if (childLastName) childLastName.reportValidity();
            return;
        }

        // Populate child summary
        const fn = document.getElementById('childFirstName').value.trim();
        const mn = document.getElementById('childMiddleName').value.trim();
        const ln = document.getElementById('childLastName').value.trim();
        const suf = document.getElementById('childSuffix').value.trim();
        const sex = document.getElementById('childSex').value;
        const birthdate = birthdateField.value;
        const months = ageField.value;
        const addr = addressField.value.trim();

        const brSel = document.getElementById('barangayField');
        const brText = brSel.value ? brSel.options[brSel.selectedIndex].text : '—';

        const ipEl = document.getElementById('isIpField');
        const ipVal = ipEl.disabled ? 'Not specified' : ipEl.value;

        // Populate measurement summary
        const measurementDate = measurementDateField ? measurementDateField.value : '';
        const height = heightField ? heightField.value : '';
        const weight = weightField ? weightField.value : '';
        const muac = muacField ? muacField.value : '';
        const hfaStatus = hfaStatusField ? hfaStatusField.value : '';
        const wfaStatus = wfaStatusField ? wfaStatusField.value : '';
        const wflStatus = wflStatusField ? wflStatusField.value : '';
        const muacStatus = muacStatusField ? muacStatusField.value : '';

        // Populate guardian summary
        const gfn = document.getElementById('guardianFirstName').value.trim();
        const gmn = document.getElementById('guardianMiddleName').value.trim();
        const gln = document.getElementById('guardianLastName').value.trim();
        const gsuf = document.getElementById('guardianSuffix').value.trim();
        const rel = document.getElementById('guardianRelationship').value;
        const contact = document.getElementById('contactNumber').value.trim();

        document.getElementById('sumChildFirstName').textContent = fn || '—';
        document.getElementById('sumChildMiddleName').textContent = mn || '—';
        document.getElementById('sumChildLastName').textContent = ln || '—';
        document.getElementById('sumChildSuffix').textContent = suf || '—';
        document.getElementById('sumBirthdate').textContent = birthdate || '—';
        document.getElementById('sumSex').textContent = sex || '—';
        document.getElementById('sumAge').textContent = months !== '' ? `${months} month${months == 1 ? '' : 's'}` : '—';
        document.getElementById('sumAddress').textContent = addr || '—';
        document.getElementById('sumBarangay').textContent = brText;

        const ipEl2 = document.getElementById('sumIp');
        if (ipEl2) {
            ipEl2.textContent = ipVal;
            ipEl2.className = `text-[0.82rem] font-semibold ${ipVal === 'Yes' ? 'text-emerald-600' : ipVal === 'No' ? 'text-slate-500' : 'text-slate-400'}`;
        }

        document.getElementById('sumMeasurementDate').textContent = measurementDate || '—';
        document.getElementById('sumHeight').textContent = height || '—';
        document.getElementById('sumWeight').textContent = weight || '—';
        document.getElementById('sumMuac').textContent = muac || '—';
        const sumHfa = document.getElementById('sumHfaStatus');
        const sumWfa = document.getElementById('sumWfaStatus');
        const sumWfl = document.getElementById('sumWflStatus');
        const sumMuac = document.getElementById('sumMuacStatus');
        if (sumHfa) {
            sumHfa.textContent = hfaStatus || '—';
            applyGrowthStatusSummary(sumHfa, hfaStatus);
        }
        if (sumWfa) {
            sumWfa.textContent = wfaStatus || '—';
            applyGrowthStatusSummary(sumWfa, wfaStatus);
        }
        if (sumWfl) {
            sumWfl.textContent = wflStatus || '—';
            applyGrowthStatusSummary(sumWfl, wflStatus);
        }
        if (sumMuac) {
            sumMuac.textContent = muacStatus || '—';
            applyGrowthStatusSummary(sumMuac, muacStatus);
        }

        document.getElementById('sumGuardianFirstName').textContent = gfn || '—';
        document.getElementById('sumGuardianMiddleName').textContent = gmn || '—';
        document.getElementById('sumGuardianLastName').textContent = gln || '—';
        document.getElementById('sumGuardianSuffix').textContent = gsuf || '—';
        document.getElementById('sumRelationship').textContent = rel || '—';
        document.getElementById('sumContact').textContent = contact || '—';

        setConfirmLoading(false);
        if (confirmBtn) confirmBtn.disabled = false;
        if (cancelBtn) cancelBtn.disabled = false;

        modal.classList.remove('opacity-0', 'invisible', 'pointer-events-none');
        modal.classList.add('opacity-100', 'visible', 'pointer-events-auto');
        if (modalBox) {
            modalBox.classList.remove('translate-y-4', 'scale-95');
            modalBox.classList.add('translate-y-0', 'scale-100');
        }
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('overflow-hidden', 'modal-open');
    }

    function closeModal() {
        modal.classList.add('opacity-0', 'invisible', 'pointer-events-none');
        modal.classList.remove('opacity-100', 'visible', 'pointer-events-auto');
        if (modalBox) {
            modalBox.classList.add('translate-y-4', 'scale-95');
            modalBox.classList.remove('translate-y-0', 'scale-100');
        }
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('overflow-hidden', 'modal-open');
    }

    async function submitForm() {
        setConfirmLoading(true);
        if (confirmBtn) confirmBtn.disabled = true;
        if (cancelBtn) cancelBtn.disabled = true;

        try {
            const formData = new FormData(form);
            formData.append('ajax', '1');
            const response = await fetch(form.action || window.location.href, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            });
            const raw = await response.text();
            let json = null;
            try {
                json = raw ? JSON.parse(raw) : null;
            } catch (err) {
                console.error('Failed to parse JSON response from server:', err, raw);
                showToast('error', 'Server error: ' + (raw || (response.status + ' ' + response.statusText)));
                closeModal();
                return;
            }

            if (json && json.success) {
                showToast('success', json.message || 'Child profile saved successfully.');
                closeModal();
                form.reset();
                enforceAddressPrefix(false);
                validateAddressDetail();
                toggleIp();
                setMeasurementDateToToday();
                updateAge();
                clearStatusFields();
                setChildExistsState(false, '');
                setGuardianExistsState(false, '');
                lastGuardianKey = '';
                lastGuardianResult = null;
                if (childFirstName) childFirstName.focus();
            } else {
                showToast('error', (json && json.message) ? json.message : 'Failed to save child profile.');
                console.error('Save failed, server response:', json, raw);
                closeModal();
            }
        } catch (err) {
            console.error('Submit failed:', err);
            showToast('error', 'Unable to save profile: ' + (err && err.message ? err.message : 'network error'));
            closeModal();
        } finally {
            setConfirmLoading(false);
            if (confirmBtn) confirmBtn.disabled = false;
            if (cancelBtn) cancelBtn.disabled = false;
        }
    }

    // ─────────────────────────────────────────────────────────────
    // User-selection modal helpers
    // ─────────────────────────────────────────────────────────────

    function showUserSelectState(state) {
        // state: 'loading' | 'empty' | 'list'
        [userSelectLoading, userSelectEmpty, userSelectList].forEach(el => {
            if (el) el.classList.add('hidden');
        });
        if (state === 'loading' && userSelectLoading) userSelectLoading.classList.remove('hidden');
        if (state === 'empty' && userSelectEmpty) { userSelectEmpty.classList.remove('hidden'); userSelectEmpty.style.display = 'flex'; }
        if (state === 'list' && userSelectList) userSelectList.classList.remove('hidden');
    }

    function setUserSelectConfirmLoading(isLoading) {
        if (!userSelectConfirmBtn) return;
        const label = userSelectConfirmBtn.querySelector('.us-btn-label');
        const spinner = userSelectConfirmBtn.querySelector('.us-spinner');
        if (isLoading) {
            if (label) label.classList.add('hidden');
            if (spinner) spinner.classList.remove('hidden');
            userSelectConfirmBtn.classList.add('opacity-70', 'pointer-events-none');
        } else {
            if (label) label.classList.remove('hidden');
            if (spinner) spinner.classList.add('hidden');
            userSelectConfirmBtn.classList.remove('opacity-70', 'pointer-events-none');
        }
    }

    function selectUser(userId, userName) {
        selectedUserId = userId;
        selectedUserName = userName;

        // Highlight selected card, un-highlight others
        if (userSelectList) {
            userSelectList.querySelectorAll('.us-user-card').forEach(card => {
                const isSelected = parseInt(card.dataset.userId, 10) === userId;
                card.classList.toggle('ring-2', isSelected);
                card.classList.toggle('ring-blue-400', isSelected);
                card.classList.toggle('bg-blue-50/50', isSelected);
                card.classList.toggle('border-blue-300', isSelected);
                card.classList.toggle('bg-white', !isSelected);
                card.classList.toggle('border-slate-200', !isSelected);
                // checkmark icon
                const check = card.querySelector('.us-check');
                if (check) check.classList.toggle('hidden', !isSelected);
            });
        }

        if (userSelectConfirmBtn) userSelectConfirmBtn.disabled = false;
    }

    function buildUserCard(user) {
        const roleColor = 'bg-blue-50 text-blue-600 border border-blue-100/50';
        const roleShort = (user.role || 'BNS').toUpperCase();

        const card = document.createElement('button');
        card.type = 'button';
        card.dataset.userId = user.user_id;
        card.className = [
            'us-user-card w-full flex items-center gap-3 rounded-xl border border-slate-200',
            'bg-white px-4 py-3 text-left transition-all duration-200 ease-out',
            'hover:border-blue-300 hover:bg-blue-50/30 focus:outline-none focus:ring-2 focus:ring-blue-200 shadow-sm'
        ].join(' ');

        card.innerHTML = `
            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-blue-50 to-blue-100 text-blue-600 font-bold text-[0.82rem] shadow-inner ring-1 ring-blue-200/50">
                ${escapeHtml(user.full_name.split(' ').map(n => n[0]).slice(0, 2).join(''))}
            </div>
            <div class="flex-1 min-w-0">
                <div class="text-[0.84rem] font-bold text-slate-800 truncate">${escapeHtml(user.full_name)}</div>
                <div class="flex items-center gap-1.5 mt-0.5">
                    <span class="inline-block rounded-md px-1.5 py-0.5 text-[0.62rem] font-bold tracking-wider ${roleColor}">${escapeHtml(roleShort)}</span>
                </div>
            </div>
            <div class="us-check hidden shrink-0 h-5 w-5 flex items-center justify-center rounded-full bg-blue-600 text-white shadow-md">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
        `;

        card.addEventListener('click', () => selectUser(user.user_id, user.full_name));
        return card;
    }

    async function openUserSelectModal() {
        // Reset selection state
        selectedUserId = null;
        selectedUserName = '';
        if (userSelectConfirmBtn) userSelectConfirmBtn.disabled = true;
        if (userSelectList) userSelectList.innerHTML = '';

        // Show barangay name
        const brSel = document.getElementById('barangayField');
        const brText = brSel && brSel.value ? brSel.options[brSel.selectedIndex].text : '—';
        const brId = brSel ? parseInt(brSel.value, 10) : 0;
        if (userSelectBarangayName) userSelectBarangayName.textContent = brText;

        // Animate modal in
        userSelectModal.classList.remove('opacity-0', 'invisible', 'pointer-events-none');
        userSelectModal.classList.add('opacity-100', 'visible', 'pointer-events-auto');
        if (userSelectBox) {
            userSelectBox.classList.remove('translate-y-4', 'scale-95');
            userSelectBox.classList.add('translate-y-0', 'scale-100');
        }
        userSelectModal.setAttribute('aria-hidden', 'false');

        showUserSelectState('loading');

        try {
            const fd = new FormData();
            fd.append('action', 'get_barangay_users');
            fd.append('barangay_id', brId);
            const res = await fetch('add_profile.php', { method: 'POST', body: fd });
            const json = await res.json();

            if (!json || !json.success || !Array.isArray(json.users) || json.users.length === 0) {
                showUserSelectState('empty');
                return;
            }

            // Render user cards
            json.users.forEach(user => {
                if (userSelectList) userSelectList.appendChild(buildUserCard(user));
            });
            showUserSelectState('list');
        } catch (err) {
            console.error('Failed to fetch barangay users:', err);
            showUserSelectState('empty');
        }
    }

    function closeUserSelectModal() {
        userSelectModal.classList.add('opacity-0', 'invisible', 'pointer-events-none');
        userSelectModal.classList.remove('opacity-100', 'visible', 'pointer-events-auto');
        if (userSelectBox) {
            userSelectBox.classList.add('translate-y-4', 'scale-95');
            userSelectBox.classList.remove('translate-y-0', 'scale-100');
        }
        userSelectModal.setAttribute('aria-hidden', 'true');
    }

    // ─────────────────────────────────────────────────────────────
    // Event wiring
    // ─────────────────────────────────────────────────────────────

    if (openBtn) openBtn.addEventListener('click', openModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
    if (backdrop) backdrop.addEventListener('click', closeModal);

    // confirmBtn: BNS users submit directly; others open the user-selection modal
    if (confirmBtn) {
        confirmBtn.addEventListener('click', () => {
            if (currentUserRole === 'Barangay Nutrition Scholars') {
                // BNS should add child directly without choosing another assigned user
                submitForm();
            } else {
                openUserSelectModal();
            }
        });
    }

    // Back button: close user-select modal (review modal stays visible behind it)
    if (userSelectBackBtn) userSelectBackBtn.addEventListener('click', closeUserSelectModal);

    // Confirm & Save: inject selected user, submit
    if (userSelectConfirmBtn) {
        userSelectConfirmBtn.addEventListener('click', async () => {
            if (!selectedUserId) return;
            if (designatedUserIdInput) designatedUserIdInput.value = selectedUserId;
            closeUserSelectModal();
            await submitForm();
        });
    }

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            // Close whichever modal is on top
            if (!userSelectModal.classList.contains('invisible')) {
                closeUserSelectModal();
            } else {
                closeModal();
            }
        }
    });

    const toastHost = document.getElementById('toastContainer');
    if (toastHost) {
        const successMsg = toastHost.dataset.success || '';
        const errorMsg = toastHost.dataset.error || '';
        if (successMsg) showToast('success', successMsg);
        if (errorMsg) showToast('error', errorMsg);
        if ((successMsg || errorMsg) && alertHost) {
            alertHost.innerHTML = '';
        }
    }
});
