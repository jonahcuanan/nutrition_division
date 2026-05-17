/* ── Barangay Page JS ── */

window.openBarangayModal = function () {
    const modal = document.getElementById('barangay-modal');
    const title = document.getElementById('modal-title');
    const subtitle = document.getElementById('modal-subtitle');
    const submit = modal?.querySelector('.btn-submit');
    const idInput = document.getElementById('barangay_id');
    if (!modal) return;

    if (idInput && !idInput.value) {
        if (title) title.textContent = 'Add Barangay';
        if (subtitle) subtitle.textContent = 'Fill in the details below to register a new barangay.';
        if (submit) submit.innerHTML = '<svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.3" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Save Barangay';

        // Ensure fields are editable for new entry
        const form = modal.querySelector('form');
        if (form) {
            form.elements['barangay_name'].readOnly = false;
            form.elements['city'].readOnly = false;
            form.elements['province'].readOnly = false;
            form.elements['psgc'].readOnly = false;
        }
    }

    modal.classList.add('active');
    setTimeout(() => {
        document.querySelector('#barangay-modal input[name="barangay_name"]')?.focus();
    }, 60);
};

window.closeBarangayModal = function () {
    const modal = document.getElementById('barangay-modal');
    const title = document.getElementById('modal-title');
    const subtitle = document.getElementById('modal-subtitle');
    const submit = modal?.querySelector('.btn-submit');
    const idInput = document.getElementById('barangay_id');
    if (!modal) return;

    modal.classList.remove('active');
    if (idInput) idInput.value = '';
    if (title) title.textContent = 'Add Barangay';
    if (subtitle) subtitle.textContent = 'Fill in the details below to register a new barangay.';
    if (submit) submit.innerHTML = '<svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.3" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Save Barangay';

    // Reset readonly status
    const form = modal.querySelector('form');
    if (form) {
        form.elements['barangay_name'].readOnly = false;
        form.elements['city'].readOnly = false;
        form.elements['province'].readOnly = false;
        form.elements['psgc'].readOnly = false;
    }

    const modalAlert = document.getElementById('modalAlert');
    if (modalAlert) {
        modalAlert.classList.remove('active');
        const textEl = document.getElementById('modalAlertText');
        if (textEl) textEl.innerHTML = '';
    }
};

window.closeDeleteModal = function () {
    const modal = document.getElementById('delete-modal');
    const confirmBtn = document.getElementById('confirm-delete-btn');
    if (!modal) return;
    modal.classList.remove('active');
    if (confirmBtn) {
        confirmBtn.removeAttribute('data-href');
        confirmBtn.removeAttribute('data-id');
    }
};

window.startEditBarangay = function (e, btn) {
    e.preventDefault();
    const modal = document.getElementById('barangay-modal');
    const title = document.getElementById('modal-title');
    const subtitle = document.getElementById('modal-subtitle');
    const submit = modal?.querySelector('.btn-submit');
    const idInput = document.getElementById('barangay_id');
    if (!modal || !idInput) return false;

    const form = modal.querySelector('form');
    if (!form) return false;

    form.elements['barangay_name'].value = btn.dataset.name || '';
    form.elements['city'].value = btn.dataset.city || '';
    form.elements['province'].value = btn.dataset.province || '';
    form.elements['total_population'].value = btn.dataset.total || '';
    form.elements['estimated_children_measured'].value = btn.dataset.estimated || '';
    form.elements['psgc'].value = btn.dataset.psgc || '';
    idInput.value = btn.dataset.id || '';

    // Lock core location fields during edit
    form.elements['barangay_name'].readOnly = true;
    form.elements['city'].readOnly = true;
    form.elements['province'].readOnly = true;
    form.elements['psgc'].readOnly = true;

    if (title) title.textContent = 'Edit Barangay';
    if (subtitle) subtitle.textContent = 'Update the details below and save your changes.';
    if (submit) submit.innerHTML = '<svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.3" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Save Changes';

    modal.classList.add('active');
    setTimeout(() => form.elements['total_population'].focus(), 60);
    return false;
};

window.confirmDelete = function (e, link) {
    e.preventDefault();
    if (link.classList.contains('disabled')) return false;

    const row = link.closest('tr');
    const name = row?.querySelector('.badge')?.textContent.trim() || 'this barangay';
    const modal = document.getElementById('delete-modal');
    const nameEl = document.getElementById('delete-name');
    const confirmBtn = document.getElementById('confirm-delete-btn');

    if (!modal || !nameEl || !confirmBtn) {
        if (confirm(`Delete "${name}"?\n\nThis action cannot be undone.`)) {
            window.location.href = link.href;
        }
        return false;
    }

    nameEl.textContent = name;
    confirmBtn.dataset.href = link.href;
    const rowId = row?.getAttribute('data-barangay-id') || '';
    if (rowId) confirmBtn.dataset.id = rowId;
    modal.classList.add('active');
    setTimeout(() => confirmBtn.focus(), 30);
    return false;
};

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function showPageAlert(type, message) {
    const host = document.getElementById('pageAlert');
    if (!host) return;
    if (!message) {
        host.innerHTML = '';
        return;
    }
    const isSuccess = type === 'success';
    const cls = isSuccess ? 'alert alert-success' : 'alert alert-error';
    const icon = isSuccess ? '✅' : '⚠️';
    host.innerHTML = `<div class="${cls}"><span class="alert-icon">${icon}</span>${escapeHtml(message)}</div>`;
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

function showModalAlert(message) {
    const banner = document.getElementById('modalAlert');
    const textEl = document.getElementById('modalAlertText');
    if (!banner || !textEl) return;
    if (!message) {
        banner.classList.remove('active');
        textEl.innerHTML = '';
        return;
    }

    // Handle multi-line or list-based messages
    if (message.includes('<li>') || message.includes('. ')) {
        const list = message.split('. ').filter(Boolean);
        textEl.innerHTML = `<ul class="modal-alert-list">${list.map(li => `<li>${li}</li>`).join('')}</ul>`;
    } else {
        textEl.textContent = message;
    }
    banner.classList.add('active');
    banner.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function formatNumber(value) {
    if (value === null || value === undefined || value === '') return '—';
    const num = Number(value);
    if (!isFinite(num)) return '—';
    return Math.trunc(num).toLocaleString('en-US');
}

function updateTotalCount(delta) {
    const countEl = document.getElementById('barangayTotalCount');
    if (!countEl) return;
    const current = parseInt(countEl.textContent || '0', 10) || 0;
    countEl.textContent = String(Math.max(0, current + delta));
}

function reindexRows() {
    const rows = document.querySelectorAll('#barangayTable tbody tr[data-barangay-id]');
    rows.forEach((row, idx) => {
        const num = row.querySelector('.row-num');
        if (num) num.textContent = String(idx + 1);
    });
}

function updateRowFromPayload(payload) {
    const row = document.querySelector(`#barangayTable tbody tr[data-barangay-id="${payload.barangay_id}"]`);
    if (!row) return false;

    const nameCell = row.querySelector('[data-col="name"] .badge');
    if (nameCell) nameCell.textContent = payload.barangay_name;
    const cityCell = row.querySelector('[data-col="city"]');
    if (cityCell) cityCell.textContent = payload.city;
    const provinceCell = row.querySelector('[data-col="province"]');
    if (provinceCell) provinceCell.textContent = payload.province;
    const popCell = row.querySelector('[data-col="population"] .num-cell');
    if (popCell) popCell.textContent = formatNumber(payload.total_population);
    const estCell = row.querySelector('[data-col="estimated"] .num-cell');
    if (estCell) estCell.textContent = formatNumber(payload.estimated_children_measured);
    const psgcCell = row.querySelector('[data-col="psgc"] .psgc-cell');
    if (psgcCell) psgcCell.textContent = payload.psgc ? payload.psgc : '—';

    const editBtn = row.querySelector('.btn-edit');
    if (editBtn) {
        editBtn.dataset.name = payload.barangay_name || '';
        editBtn.dataset.city = payload.city || '';
        editBtn.dataset.province = payload.province || '';
        editBtn.dataset.total = payload.total_population || '';
        editBtn.dataset.estimated = payload.estimated_children_measured || '';
        editBtn.dataset.psgc = payload.psgc || '';
    }
    return true;
}

function addRowFromPayload(payload) {
    const tbody = document.querySelector('#barangayTable tbody');
    if (!tbody) return;

    const emptyState = tbody.querySelector('.empty-state');
    if (emptyState) {
        const row = emptyState.closest('tr');
        if (row) row.remove();
    }

    const rowCount = tbody.querySelectorAll('tr[data-barangay-id]').length + 1;
    const safeName = escapeHtml(payload.barangay_name || '');
    const safeCity = escapeHtml(payload.city || '');
    const safeProvince = escapeHtml(payload.province || '');
    const safePsgc = escapeHtml(payload.psgc || '—');
    const safeId = escapeHtml(payload.barangay_id);
    const safeTotal = escapeHtml(String(payload.total_population ?? ''));
    const safeEstimated = escapeHtml(String(payload.estimated_children_measured ?? ''));

    const row = document.createElement('tr');
    row.setAttribute('data-barangay-id', payload.barangay_id);
    row.innerHTML = `
        <td><span class="row-num">${rowCount}</span></td>
        <td style="font-weight:600;" data-col="name"><span class="badge">${safeName}</span></td>
        <td data-col="city">${safeCity}</td>
        <td style="color:var(--text-muted);" data-col="province">${safeProvince}</td>
        <td data-col="population"><span class="num-cell">${formatNumber(payload.total_population)}</span></td>
        <td data-col="estimated"><span class="num-cell">${formatNumber(payload.estimated_children_measured)}</span></td>
        <td data-col="psgc"><span class="psgc-cell">${safePsgc}</span></td>
        <td>
            <div class="action-buttons">
                <a href="#" class="btn-edit" onclick="return startEditBarangay(event, this)"
                    data-id="${safeId}"
                    data-name="${safeName}"
                    data-city="${safeCity}"
                    data-province="${safeProvince}"
                    data-total="${safeTotal}"
                    data-estimated="${safeEstimated}"
                    data-psgc="${safePsgc}">
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.3" viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25z"/><path d="M14.06 6.19l1.77-1.77a1.5 1.5 0 1 1 2.12 2.12l-1.77 1.77"/></svg>
                    Edit
                </a>
                <a href="?delete_id=${safeId}" class="btn-delete" onclick="return confirmDelete(event, this)">
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.3" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                    Delete
                </a>
            </div>
        </td>
    `;

    tbody.appendChild(row);
}

window.filterTable = function () {
    const query = (document.getElementById('searchInput')?.value || '').toLowerCase().trim();
    const rows = document.querySelectorAll('#barangayTable tbody tr');
    let visibleCount = 0;

    rows.forEach(row => {
        if (row.querySelector('.empty-state')) { row.style.display = ''; return; }
        const match = row.textContent.toLowerCase().includes(query);
        row.style.display = match ? '' : 'none';
        if (match) visibleCount++;
    });

    const noResults = document.getElementById('noResults');
    if (noResults) {
        noResults.style.display = (query && visibleCount === 0) ? 'block' : 'none';
    }
};

document.addEventListener('DOMContentLoaded', () => {

    /* Auto-uppercase text inputs */
    document.querySelectorAll('.form-group input[type="text"]').forEach(input => {
        input.addEventListener('input', function () {
            const pos = this.selectionStart;
            this.value = this.value.toUpperCase();
            this.setSelectionRange(pos, pos);
        });
    });

    /* Close modals on backdrop click */
    document.getElementById('barangay-modal')?.addEventListener('click', function (e) {
        if (e.target === this) window.closeBarangayModal();
    });
    document.getElementById('delete-modal')?.addEventListener('click', function (e) {
        if (e.target === this) window.closeDeleteModal();
    });

    /* Confirm delete */
    document.getElementById('confirm-delete-btn')?.addEventListener('click', function () {
        const href = this.dataset.href;
        if (!href) return;

        this.disabled = true;
        this.textContent = 'Deleting...';

        fetch(href, {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
            .then(res => res.json())
            .then(json => {
                if (json && json.success) {
                    const payload = json.payload || {};
                    const rowId = payload.barangay_id || this.dataset.id;
                    if (rowId) {
                        const row = document.querySelector(`#barangayTable tbody tr[data-barangay-id="${rowId}"]`);
                        if (row) row.remove();
                        reindexRows();
                        updateTotalCount(-1);
                    }
                    showToast('success', json.message || 'Barangay deleted successfully.');
                    window.closeDeleteModal();
                } else {
                    showToast('error', (json && json.message) ? json.message : 'Failed to delete barangay.');
                }
            })
            .catch(() => {
                showToast('error', 'A network error occurred.');
            })
            .finally(() => {
                this.disabled = false;
                this.innerHTML = '<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.3" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg> Yes, Delete';
            });
    });

    /* If modal is open from server-side error and it's an edit (has id) */
    const modal = document.getElementById('barangay-modal');
    const idInput = document.getElementById('barangay_id');
    if (modal?.classList.contains('active') && idInput?.value) {
        const title = document.getElementById('modal-title');
        const subtitle = document.getElementById('modal-subtitle');
        const submit = modal.querySelector('.btn-submit');
        if (title) title.textContent = 'Edit Barangay';
        if (subtitle) subtitle.textContent = 'Update the details below and save your changes.';
        if (submit) submit.textContent = 'Save Changes';

        const form = modal.querySelector('form');
        if (form) {
            form.elements['barangay_name'].readOnly = true;
            form.elements['city'].readOnly = true;
            form.elements['province'].readOnly = true;
            form.elements['psgc'].readOnly = true;
        }
    }

    /* Escape key closes any open modal */
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            window.closeBarangayModal();
            window.closeDeleteModal();
        }
    });

    /* Auto-dismiss transient feedback only (not permanent instruction banners). */
    setTimeout(() => {
        document.querySelectorAll('.alert-success, .alert-error').forEach(el => {
            el.style.transition = 'opacity .4s, transform .4s';
            el.style.opacity = '0';
            el.style.transform = 'translateY(-4px)';
            setTimeout(() => el.remove(), 400);
        });
    }, 5000);

    const barangayForm = document.querySelector('#barangay-modal form');
    if (barangayForm) {
        function setFieldState(fieldName, isValid, message) {
            const group = document.getElementById(`group-${fieldName}`);
            const errorEl = document.getElementById(`error-${fieldName}`);
            if (!group || !errorEl) return;

            if (isValid) {
                group.classList.remove('has-error');
                errorEl.classList.remove('active');
                errorEl.textContent = '';
            } else {
                group.classList.add('has-error');
                errorEl.textContent = message;
                errorEl.classList.add('active');
            }
        }

        function clearAllFieldErrors() {
            const fields = ['barangay_name', 'city', 'province', 'total_population', 'estimated_children_measured', 'psgc'];
            fields.forEach(f => setFieldState(f, true, ''));
        }

        const nameInput = barangayForm.querySelector('input[name="barangay_name"]');
        const psgcInput = barangayForm.querySelector('input[name="psgc"]');
        const totalPopInput = barangayForm.querySelector('input[name="total_population"]');
        const estMeasInput = barangayForm.querySelector('input[name="estimated_children_measured"]');

        let debounceTimer = null;

        function checkBarangayDuplicate(field) {
            if (!barangayForm) return;
            const input = barangayForm.querySelector(`input[name="${field}"]`);
            if (!input) return;

            const value = input.value.trim();
            const idValue = idInput ? idInput.value : '';

            if (field === 'barangay_name' && idValue) return;

            if (value === '') {
                input.setCustomValidity('');
                setFieldState(field, true, '');
                return;
            }

            const payload = new FormData();
            payload.append(field, value);
            if (idValue) payload.append('exclude_id', idValue);

            fetch('barangays.php?action=check_exists', {
                method: 'POST',
                body: payload,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
                .then(res => res.json())
                .then(json => {
                    if (json && json.success) {
                        if (json.exists) {
                            input.setCustomValidity(json.message);
                            setFieldState(field, false, json.message);
                        } else {
                            input.setCustomValidity('');
                            setFieldState(field, true, '');
                        }
                    }
                })
                .catch(() => { });
        }

        if (nameInput) {
            nameInput.addEventListener('input', () => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => checkBarangayDuplicate('barangay_name'), 500);
            });
            nameInput.addEventListener('blur', () => checkBarangayDuplicate('barangay_name'));
        }

        if (psgcInput) {
            psgcInput.addEventListener('input', () => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => checkBarangayDuplicate('psgc'), 500);
            });
            psgcInput.addEventListener('blur', () => checkBarangayDuplicate('psgc'));
        }

        const checkDemographics = () => {
            const total = parseInt(totalPopInput.value) || 0;
            const est = parseInt(estMeasInput.value) || 0;
            if (est > total && total > 0) {
                estMeasInput.setCustomValidity('Estimated measured children cannot exceed total population.');
                setFieldState('estimated_children_measured', false, 'Estimated measured children cannot exceed total population.');
            } else {
                estMeasInput.setCustomValidity('');
                setFieldState('estimated_children_measured', true, '');
            }
        };

        if (totalPopInput && estMeasInput) {
            totalPopInput.addEventListener('input', checkDemographics);
            estMeasInput.addEventListener('input', checkDemographics);
        }

        barangayForm.addEventListener('submit', (e) => {
            e.preventDefault();
            clearAllFieldErrors();

            let hasLocalErrors = false;
            const total = parseInt(totalPopInput.value) || 0;
            const est = parseInt(estMeasInput.value) || 0;

            if (est > total) {
                setFieldState('estimated_children_measured', false, 'Cannot exceed total population.');
                hasLocalErrors = true;
            }
            if (psgcInput.value.trim().length < 1) {
                setFieldState('psgc', false, 'PSGC code is required.');
                hasLocalErrors = true;
            }

            if (hasLocalErrors) return;

            const submitBtn = barangayForm.querySelector('.btn-submit');
            const origHtml = submitBtn ? submitBtn.innerHTML : '';
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="btn-spinner"></span> Saving…';
            }

            const formData = new FormData(barangayForm);
            fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
                .then(res => res.json())
                .then(json => {
                    if (json && json.success) {
                        const payload = json.payload || {};
                        if (payload.action === 'update') {
                            updateRowFromPayload(payload);
                        } else if (payload.action === 'add') {
                            addRowFromPayload(payload);
                            updateTotalCount(1);
                        }
                        showToast('success', json.message || 'Saved successfully.');
                        barangayForm.reset();
                        window.closeBarangayModal();
                    } else {
                        if (json.message && json.message.toLowerCase().includes('psgc')) {
                            setFieldState('psgc', false, json.message);
                        } else if (json.message && json.message.toLowerCase().includes('name')) {
                            setFieldState('barangay_name', false, json.message);
                        } else {
                            showToast('error', json.message || 'Unable to save barangay.');
                        }
                    }
                })
                .catch(() => {
                    showToast('error', 'A network error occurred.');
                })
                .finally(() => {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = origHtml;
                    }
                });
        });
    }

        const toastHost = document.getElementById('toastContainer');
        if (toastHost) {
            const successMsg = toastHost.dataset.success || '';
            const errorMsg = toastHost.dataset.error || '';
            if (successMsg) showToast('success', successMsg);
            if (errorMsg) showToast('error', errorMsg);
        }
});