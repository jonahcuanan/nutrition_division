/* ── Modal helpers ── */
const openModal = id => document.getElementById(id).classList.add('active');
const closeModal = id => document.getElementById(id).classList.remove('active');
function showValidationError(message) {
    const el = document.getElementById('validationErrorMessage');
    if (el) el.textContent = message;
    openModal('validationErrorModal');
}
let childPickerMode = 'add';

document.querySelectorAll('.btn-close').forEach(btn =>
    btn.addEventListener('click', () => closeModal(btn.dataset.target))
);
document.querySelectorAll('[data-backdrop]').forEach(bg =>
    bg.addEventListener('click', () => closeModal(bg.dataset.backdrop))
);
window.addEventListener('keydown', e => {
    if (e.key === 'Escape') ['typeModal', 'interventionModal', 'viewModal', 'confirmChildModal', 'pastInterventionModal', 'deleteTypeModal', 'validationErrorModal'].forEach(closeModal);
});

function escHtmlText(value) {
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
    const cls = isSuccess ? 'banner-success' : 'banner-error';
    host.innerHTML = `<div class="${cls}">${escHtmlText(message)}</div>`;
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

function showTypeModalAlert(message) {
    const host = document.getElementById('typeModalAlert');
    if (!host) return;
    if (!message) {
        host.innerHTML = '';
        return;
    }
    host.innerHTML = `<div class="banner-error" style="margin-top:10px;">${escHtmlText(message)}</div>`;
}

function encodeBase64Utf8(value) {
    try {
        return btoa(unescape(encodeURIComponent(value)));
    } catch (e) {
        return btoa(value);
    }
}

function updateChildCountDisplay(el, count) {
    if (!el) return;
    const n = Number(count || 0);
    el.textContent = `${n} child${n === 1 ? '' : 'ren'}`;
}

function updateTypeBadgeCount() {
    const badge = document.getElementById('typeBadgeCount');
    if (!badge) return;
    const count = (window.existingTypes || []).length;
    badge.textContent = `${count} Type${count === 1 ? '' : 's'}`;
}

function buildViewLink(typeId, description, date) {
    const key = `${typeId}::${description || ''}::${date || ''}`;
    return `view_interventions.php?k=${encodeURIComponent(encodeBase64Utf8(key))}`;
}

function addTypeToSelect(typeId, typeName) {
    const select = document.getElementById('type_id_modal');
    if (!select) return;
    const option = document.createElement('option');
    option.value = String(typeId);
    option.textContent = typeName;
    select.appendChild(option);
    updateTypeBadgeCount();
}

function addTypeToList(typeName) {
    let list = document.getElementById('existingTypeList');
    if (!list) {
        const container = document.querySelector('#typeModal .modal-body div[style*="border:1px solid"]');
        if (!container) return;
        list = document.createElement('div');
        list.id = 'existingTypeList';
        list.style.display = 'flex';
        list.style.flexDirection = 'column';
        container.innerHTML = '';
        container.appendChild(list);
    }

    const row = document.createElement('div');
    row.style.display = 'flex';
    row.style.alignItems = 'center';
    row.style.gap = '8px';
    row.style.padding = '9px 12px';
    row.style.borderBottom = '1px solid var(--slate-100)';
    row.style.fontSize = '12px';
    row.style.color = 'var(--slate-700)';
    row.innerHTML = '<span style="color:var(--green);font-weight:600;">✓</span>' +
        `<span>${escHtmlText(typeName)}</span>`;
    list.appendChild(row);
}

function removeTypeFromUI(typeId) {
    const select = document.getElementById('type_id_modal');
    if (select) {
        const opt = select.querySelector(`option[value="${typeId}"]`);
        if (opt) opt.remove();
    }
    const row = document.querySelector(`#tableBody tr[data-type-id="${typeId}"]`);
    const typeName = row ? (row.getAttribute('data-type-name') || '') : '';
    if (row) {
        if (typeName) {
            const idx = existingTypes.indexOf(typeName.toLowerCase());
            if (idx >= 0) existingTypes.splice(idx, 1);
        }
        row.remove();
    }

    const list = document.getElementById('existingTypeList');
    if (list) {
        const rows = Array.from(list.children);
        rows.forEach(item => {
            const text = item.textContent || '';
            if (typeName && text.trim().toLowerCase() === typeName.toLowerCase()) {
                item.remove();
            }
        });
    }
    updateTypeBadgeCount();
}

function upsertInterventionRow(payload) {
    const tableBody = document.getElementById('tableBody');
    if (!tableBody) return;
    const typeId = payload.type_id;
    const typeName = payload.type_name || '—';
    const description = payload.description || '';
    const date = payload.intervention_date || '';
    const count = payload.child_count || 0;

    let row = tableBody.querySelector(`tr[data-type-id="${typeId}"][data-description="${description}"][data-date="${date}"]`);
    if (!row) {
        // Also check for the "empty placeholder" row for this type if it exists
        const placeholder = tableBody.querySelector(`tr[data-type-id="${typeId}"][data-description=""][data-date=""]`);
        if (placeholder && !placeholder.querySelector('[data-child-count]')?.textContent.includes('children')) {
            placeholder.remove();
        }

        row = document.createElement('tr');
        row.className = 'intervention-row';
        row.setAttribute('data-type-id', typeId);
        row.setAttribute('data-type-name', typeName);
        row.setAttribute('data-description', description);
        row.setAttribute('data-date', date);
        row.setAttribute('data-child-ids', JSON.stringify(payload.child_ids || []));
        row.innerHTML = `
            <td class="type-cell">
                <span class="type-badge">${escHtmlText(typeName)}</span>
            </td>
            <td>
                <span class="child-count-badge" data-child-count>
                    ${count} child${count !== 1 ? 'ren' : ''}
                </span>
            </td>
            <td style="text-align:center;">
                <div style="display:flex;align-items:center;justify-content:center;gap:6px;">
                    <a href="${buildViewLink(typeId, description, date)}" class="tbl-btn-view btn-view-page">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                        View
                    </a>
                    <form method="POST" class="js-delete-type-form" style="display:inline-flex;">
                        <input type="hidden" name="action" value="delete_type">
                        <input type="hidden" name="type_id" value="${escHtmlText(typeId)}">
                        <button type="button" class="tbl-btn-delete js-open-delete-type-modal" 
                            data-type-name="${escHtmlText(typeName)}"
                            style="display:inline-flex;align-items:center;gap:5px;padding:6px 10px;border-radius:8px;border:1px solid #fecaca;background:#fff1f2;color:#b91c1c;font-size:11px;font-weight:700;line-height:1;cursor:pointer;">
                            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                            Delete
                        </button>
                    </form>
                </div>
            </td>
        `;
        tableBody.appendChild(row);
    } else {
        const viewLink = row.querySelector('.btn-view-page');
        if (viewLink) viewLink.href = buildViewLink(typeId, description, date);
        const badge = row.querySelector('.type-badge');
        if (badge) badge.textContent = typeName;
        row.setAttribute('data-type-name', typeName);
    }

    const countEl = row.querySelector('[data-child-count]');
    updateChildCountDisplay(countEl, count);
}

/* ── Type Name Duplicate Detection ── */
const existingTypes = window.existingTypes || [];
const typeInput = document.getElementById('type_name_modal');
const duplicateWarning = document.getElementById('duplicateWarning');
const saveTypeBtn = document.getElementById('saveTypeBtn');
const giveOutTypeId = window.giveOutTypeId || 0;

if (typeInput) {
    typeInput.addEventListener('input', function () {
        const value = this.value.trim().toLowerCase();
        const isDuplicate = value && existingTypes.includes(value);

        duplicateWarning.style.display = isDuplicate ? 'block' : 'none';
        saveTypeBtn.disabled = isDuplicate;
        saveTypeBtn.style.opacity = isDuplicate ? '0.6' : '1';
        saveTypeBtn.style.cursor = isDuplicate ? 'not-allowed' : 'pointer';
    });
}

const typeForm = document.getElementById('typeForm');
if (typeForm) {
    typeForm.addEventListener('submit', (event) => {
        event.preventDefault();
        showTypeModalAlert('');

        if (saveTypeBtn) {
            saveTypeBtn.disabled = true;
            saveTypeBtn.style.opacity = '0.7';
        }

        const formData = new FormData(typeForm);
        fetch('interventions.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
            .then(res => {
                if (!res.ok) throw new Error('Server error: ' + res.status);
                return res.json();
            })
            .then(json => {
                if (json && json.success) {
                    const payload = json.payload || {};
                    const name = payload.type_name || typeInput.value.trim();
                    if (name) {
                        existingTypes.push(name.toLowerCase());
                        addTypeToSelect(payload.type_id, name);
                        addTypeToList(name);
                        upsertInterventionRow({
                            type_id: payload.type_id,
                            type_name: name,
                            child_count: 0,
                            description: '',
                            intervention_date: ''
                        });
                    }
                    typeForm.reset();
                    if (duplicateWarning) duplicateWarning.style.display = 'none';
                    showToast('success', json.message || 'Intervention type added successfully.');
                    closeModal('typeModal');
                } else {
                    showTypeModalAlert((json && json.message) ? json.message : 'Unable to save intervention type.');
                }
            })
            .catch(err => {
                console.error('[AddType]', err);
                showTypeModalAlert('A network error occurred. (' + err.message + ')');
            })
            .finally(() => {
                if (saveTypeBtn) {
                    saveTypeBtn.disabled = false;
                    saveTypeBtn.style.opacity = '1';
                }
            });
    });
}

const giveOutWrap = document.getElementById('giveoutWrap');
const giveoutCategorySelect = document.getElementById('giveoutCategorySelect');
const giveOutItemSelect = document.getElementById('giveoutItemSelect');
const giveOutQtyInput = document.getElementById('giveoutQtyInput');
const giveOutAddBtn = document.getElementById('giveoutAddBtn');
const giveOutError = document.getElementById('giveoutError');
const giveOutCartTable = document.getElementById('giveoutCartTable');
const giveOutCartBody = document.getElementById('giveoutCartBody');
const giveOutCartEmpty = document.getElementById('giveoutCartEmpty');
const giveOutCartInputs = document.getElementById('giveoutCartInputs');
const typeSelectModal = document.getElementById('type_id_modal');
const editChildTable = document.getElementById('editChildTable');
const interventionDateInput = document.getElementById('intervention_date_modal');
const confirmChildName = document.getElementById('confirmChildName');
const confirmChildGo = document.getElementById('confirmChildGo');
const pastInterventionModal = document.getElementById('pastInterventionModal');
const pastInterventionList = document.getElementById('pastInterventionList');
const pastInterventionProceed = document.getElementById('pastInterventionProceed');
const interventionForm = document.getElementById('interventionForm');
const interventionModal = document.getElementById('interventionModal');
const confirmOverrideInput = document.getElementById('confirm_override');
const deleteTypeNameText = document.getElementById('deleteTypeNameText');
const confirmDeleteTypeBtn = document.getElementById('confirmDeleteTypeBtn');
let pendingDeleteTypeForm = null;
let pendingChildProfileId = '';
let giveOutValidationDebounceTimer = null;

document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;

    const trigger = target.closest('.js-open-delete-type-modal');
    if (!(trigger instanceof HTMLElement)) return;
    if (trigger.hasAttribute('disabled')) return;

    const form = trigger.closest('form.js-delete-type-form');
    if (!(form instanceof HTMLFormElement)) return;

    pendingDeleteTypeForm = form;
    const typeName = trigger.getAttribute('data-type-name') || 'this intervention type';
    if (deleteTypeNameText) {
        deleteTypeNameText.textContent = `Type: ${typeName}`;
    }
    openModal('deleteTypeModal');
});

if (confirmDeleteTypeBtn) {
    const deleteTypeBtnLabel = confirmDeleteTypeBtn.querySelector('.js-delete-type-btn-label');
    confirmDeleteTypeBtn.addEventListener('click', () => {
        if (!pendingDeleteTypeForm) return;
        const formToSubmit = pendingDeleteTypeForm;
        pendingDeleteTypeForm = null;

        confirmDeleteTypeBtn.disabled = true;
        if (deleteTypeBtnLabel) deleteTypeBtnLabel.textContent = 'Deleting…';

        const formData = new FormData(formToSubmit);
        fetch('interventions.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
            .then(res => {
                if (!res.ok) throw new Error('Server error: ' + res.status);
                return res.json();
            })
            .then(json => {
                if (json && json.success) {
                    const payload = json.payload || {};
                    if (payload.type_id) {
                        removeTypeFromUI(payload.type_id);
                    }
                    showToast('success', json.message || 'Intervention type deleted successfully.');
                    closeModal('deleteTypeModal');
                } else {
                    showToast('error', (json && json.message) ? json.message : 'Unable to delete intervention type.');
                }
            })
            .catch(err => {
                console.error('[DeleteType]', err);
                showToast('error', 'A network error occurred. (' + err.message + ')');
            })
            .finally(() => {
                confirmDeleteTypeBtn.disabled = false;
                if (deleteTypeBtnLabel) deleteTypeBtnLabel.textContent = 'Delete';
            });
    });
}

function openConfirmChildModal(childId, childName) {
    pendingChildProfileId = childId || '';
    if (confirmChildName) {
        confirmChildName.textContent = childName ? `Name: ${childName}` : '';
    }
    openModal('confirmChildModal');
}

if (confirmChildGo) {
    confirmChildGo.addEventListener('click', () => {
        if (!pendingChildProfileId) return;
        window.location.href = `view_child_profile.php?child_id=${encodeURIComponent(pendingChildProfileId)}`;
    });
}

const giveOutCart = {};

function getSelectedChildrenCount() {
    return document.querySelectorAll("input[name='child_ids[]']:checked").length;
}

/**
 * Same rules as server-side give-out validation (non-empty cart assumed by caller).
 * @returns {string} Error message, or '' if give-out lines look valid.
 */
function getGiveOutValidationMessage() {
    const selectedType = parseInt(typeSelectModal?.value || '0', 10);
    if (selectedType !== giveOutTypeId) return '';
    const cartItems = Object.keys(giveOutCart);
    if (cartItems.length === 0) return '';
    const n = getSelectedChildrenCount();
    for (const id of cartItems) {
        const item = giveOutCart[id];
        if (item.qty !== n) {
            return `For "${item.name}", quantity (${item.qty} pcs) must match the number of selected children (${n}). Use one piece per child.`;
        }
        if (item.qty > item.maxQty) {
            return `Not enough "${item.name}" in stock. You need ${item.qty} pcs but only ${item.maxQty} available.`;
        }
    }
    return '';
}

function scheduleGiveOutValidationModal() {
    if (!interventionModal?.classList.contains('active')) return;
    if (document.getElementById('form_action')?.value !== 'add_intervention') return;
    if (parseInt(typeSelectModal?.value || '0', 10) !== giveOutTypeId) return;
    clearTimeout(giveOutValidationDebounceTimer);
    giveOutValidationDebounceTimer = setTimeout(() => {
        giveOutValidationDebounceTimer = null;
        if (Object.keys(giveOutCart).length === 0) return;
        const msg = getGiveOutValidationMessage();
        if (msg) showValidationError(msg);
    }, 320);
}

/** Run give-out checks immediately (e.g. Save click) and show the validation modal if needed. */
function runGiveOutValidationModalIfInvalid() {
    if (parseInt(typeSelectModal?.value || '0', 10) !== giveOutTypeId) return false;
    if (Object.keys(giveOutCart).length === 0) return false;
    const msg = getGiveOutValidationMessage();
    if (!msg) return false;
    showValidationError(msg);
    return true;
}

/** Give-out qty is total pcs for the batch; must equal number of selected children (1 per child). */
function syncGiveOutQuantitiesToChildCount() {
    if (!giveOutWrap || !giveOutWrap.classList.contains('active')) return;
    const n = getSelectedChildrenCount();
    const ids = Object.keys(giveOutCart);
    if (ids.length === 0) return;
    hideGiveOutError();
    if (n === 0) return;
    for (const id of ids) {
        const item = giveOutCart[id];
        if (n > item.maxQty) {
            showGiveOutError(`Not enough stock for "${item.name}". You selected ${n} children but only ${item.maxQty} available.`);
            scheduleGiveOutValidationModal();
            return;
        }
        item.qty = n;
    }
    renderGiveOutCart();
}

function resetGiveOutCart() {
    Object.keys(giveOutCart).forEach(key => delete giveOutCart[key]);
    renderGiveOutCart();
    if (giveoutCategorySelect) giveoutCategorySelect.value = '';
    if (giveOutItemSelect) {
        giveOutItemSelect.value = '';
        // Show all items initially on reset
        Array.from(giveOutItemSelect.options).forEach(opt => {
            if (opt.value !== "") opt.style.display = '';
        });
    }
    if (giveOutQtyInput) giveOutQtyInput.value = '1';
    hideGiveOutError();
    syncGiveOutQtyInputDefault();
}

function hideGiveOutError() {
    if (!giveOutError) return;
    giveOutError.style.display = 'none';
    giveOutError.textContent = '';
}

function showGiveOutError(message) {
    if (!giveOutError) return;
    giveOutError.textContent = message;
    giveOutError.style.display = 'block';
}

function addGiveOutItem(inventoryId, name, unit, maxQty, totalQtyPcs) {
    giveOutCart[inventoryId] = {
        name,
        unit,
        maxQty,
        qty: totalQtyPcs,
    };
    renderGiveOutCart();
}

function syncGiveOutQtyInputDefault() {
    if (!giveOutQtyInput || !giveOutWrap || !giveOutWrap.classList.contains('active')) return;
    const n = getSelectedChildrenCount();
    if (n > 0) {
        giveOutQtyInput.value = String(n);
    }
}

function removeGiveOutItem(inventoryId) {
    delete giveOutCart[inventoryId];
    renderGiveOutCart();
}

function renderGiveOutCart() {
    if (!giveOutCartBody || !giveOutCartTable || !giveOutCartEmpty || !giveOutCartInputs) return;
    const ids = Object.keys(giveOutCart);
    giveOutCartBody.innerHTML = '';
    giveOutCartInputs.innerHTML = '';

    if (ids.length === 0) {
        giveOutCartTable.style.display = 'none';
        giveOutCartEmpty.style.display = 'block';
        return;
    }

    giveOutCartTable.style.display = '';
    giveOutCartEmpty.style.display = 'none';

    ids.forEach(id => {
        const item = giveOutCart[id];
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${escHtml(item.name)}</td>
            <td style="text-align:center;">${item.maxQty} ${escHtml(item.unit)}</td>
            <td style="text-align:center;">
                <input type="number" min="1" max="${item.maxQty}" value="${item.qty}" data-giveout-qty="${id}" title="Must equal number of selected children" class="field-input" style="max-width:90px;margin:0 auto;padding:5px 8px;font-size:12px;">
            </td>
            <td style="text-align:center;">
                <button type="button" class="giveout-remove-btn" data-giveout-remove="${id}">Remove</button>
            </td>
        `;
        giveOutCartBody.appendChild(row);

        const idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'giveout_inventory_ids[]';
        idInput.value = id;
        giveOutCartInputs.appendChild(idInput);

        const qtyInput = document.createElement('input');
        qtyInput.type = 'hidden';
        qtyInput.name = 'giveout_qtys[]';
        qtyInput.value = item.qty;
        qtyInput.dataset.giveoutHiddenQty = id;
        giveOutCartInputs.appendChild(qtyInput);
    });
    scheduleGiveOutValidationModal();
}

function toggleGiveOutSection() {
    if (!giveOutWrap || !typeSelectModal) return;
    const selectedTypeId = parseInt(typeSelectModal.value || '0', 10);
    const show = selectedTypeId === giveOutTypeId;
    giveOutWrap.classList.toggle('active', show && childPickerMode !== 'edit');
    if (!show || childPickerMode === 'edit') {
        resetGiveOutCart();
    } else {
        syncGiveOutQtyInputDefault();
        syncGiveOutQuantitiesToChildCount();
        scheduleGiveOutValidationModal();
    }
}

function applyEditModalAppearance() {
    if (editChildTable) {
        editChildTable.classList.toggle('edit-like-view', childPickerMode === 'edit');
    }
    if (interventionDateInput) {
        interventionDateInput.disabled = false;
        interventionDateInput.readOnly = false;
    }
}

/* ── Open buttons ── */
const btnOpenTypeModal = document.getElementById('btnOpenTypeModal');
if (btnOpenTypeModal) {
    btnOpenTypeModal.addEventListener('click', () => openModal('typeModal'));
}

const btnOpenInterventionModal = document.getElementById('btnOpenInterventionModal');
if (btnOpenInterventionModal) {
    btnOpenInterventionModal.addEventListener('click', () => {
        childPickerMode = 'add';
        document.getElementById('interventionModalTitle').textContent = 'Add Intervention';
        document.getElementById('type_id_modal').value = '';
        document.getElementById('intervention_date_modal').value = window.currentDate || '';
        document.getElementById('form_action').value = 'add_intervention';
        if (confirmOverrideInput) confirmOverrideInput.value = '0';
        document.getElementById('original_type_id').value = '';
        document.getElementById('original_description').value = '';
        document.getElementById('description_modal').value = '';
        const originalDateInput = document.getElementById('original_date');
        if (originalDateInput) originalDateInput.value = '';

        setEditAllowedChildren([]);
        document.querySelectorAll("input[name='child_ids[]']").forEach(cb => cb.checked = false);
        document.getElementById('childSearch').value = '';
        resetGiveOutCart();
        toggleGiveOutSection();
        applyEditModalAppearance();
        applyChildPickerMode();
        updateCheckedCount();
        syncGiveOutQtyInputDefault();
        openModal('interventionModal');
    });
}

/* ── View buttons ── */
document.querySelectorAll('.btn-view').forEach(btn => {
    btn.addEventListener('click', () => {
        const type = btn.dataset.type || '—';
        const desc = btn.dataset.description || '';
        const children = JSON.parse(btn.dataset.childrenDetails || '[]');
        const date = btn.dataset.date || '';

        document.getElementById('viewModalType').textContent = type;
        document.getElementById('viewModalTypeName').textContent = type;
        document.getElementById('viewModalDescription').textContent = desc || 'No description provided.';
        document.getElementById('viewModalDate').textContent = date || '—';
        document.getElementById('viewChildCount').textContent = children.length;

        const list = document.getElementById('viewModalChildren');
        list.innerHTML = '';
        if (children.length === 0) {
            list.innerHTML = '<p style="color:var(--slate-400);font-size:13px;padding:12px 0;text-align:center;">No children recorded.</p>';
        } else {
            const rows = children.map(child => `
                <tr class="${Number(child.child_id || 0) > 0 ? 'child-profile-row' : ''}" data-child-id="${Number(child.child_id || 0) > 0 ? Number(child.child_id) : ''}">
                    <td class="view-child-location">${escHtml(child.address_location || 'N/A')}</td>
                    <td class="view-child-name">${escHtml(child.name || '—')}</td>
                    <td class="view-child-sex">${escHtml(child.sex || 'N/A')}</td>
                    <td>${escHtml(child.age_in_months || 'N/A')}</td>
                    <td><span class="status-pill ${statusPillClass(child.height_for_age_status)}">${escHtml(child.height_for_age_status || 'N/A')}</span></td>
                    <td><span class="status-pill ${statusPillClass(child.weight_for_age_status)}">${escHtml(child.weight_for_age_status || 'N/A')}</span></td>
                    <td><span class="status-pill ${statusPillClass(child.weight_for_ltht_status)}">${escHtml(child.weight_for_ltht_status || 'N/A')}</span></td>
                    <td>${escHtml(date || '—')}</td>
                </tr>`).join('');

            list.innerHTML = `
                <table class="view-children-table">
                    <thead>
                        <tr>
                            <th>Address / Location</th>
                            <th>Full Name</th>
                            <th>Sex</th>
                            <th>Age (months)</th>
                            <th>Height for Age Status</th>
                            <th>Weight for Age Status</th>
                            <th>Weight for L/HT Status</th>
                            <th>Intervention Date</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>`;
        }
        openModal('viewModal');
    });
});

const childrenList = document.getElementById('childrenList');
if (childrenList) {
    childrenList.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) return;
        if (target.closest('input,button,a,label,select,textarea')) return;

        const row = target.closest('.child-check-row.child-profile-row');
        if (!(row instanceof HTMLElement)) return;

        const childId = row.getAttribute('data-child-id');
        if (!childId) return;
        const nameCell = row.querySelector('.child-name-cell');
        const childName = nameCell ? nameCell.textContent.trim() : '';
        openConfirmChildModal(childId, childName);
    });

    childrenList.addEventListener('change', () => {
        syncSelectedRowState();
        updateCheckedCount();
        syncGiveOutQtyInputDefault();
        syncGiveOutQuantitiesToChildCount();
        scheduleGiveOutValidationModal();
    });
}

const viewModalChildren = document.getElementById('viewModalChildren');
if (viewModalChildren) {
    viewModalChildren.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) return;
        if (target.closest('input,button,a,label,select,textarea')) return;

        const row = target.closest('.child-profile-row');
        if (!(row instanceof HTMLElement)) return;

        const childId = row.getAttribute('data-child-id');
        if (!childId) return;
        const nameCell = row.querySelector('.view-child-name');
        const childName = nameCell ? nameCell.textContent.trim() : '';
        openConfirmChildModal(childId, childName);
    });
}

if (pastInterventionProceed) {
    pastInterventionProceed.addEventListener('click', () => {
        if (confirmOverrideInput) confirmOverrideInput.value = '1';
        closeModal('pastInterventionModal');
        submitInterventionAjax();
    });
}

function renderPastInterventionList(items) {
    if (!pastInterventionList) return;
    pastInterventionList.innerHTML = '';
    items.forEach(item => {
        const card = document.createElement('div');
        card.style.border = '1px solid var(--slate-200)';
        card.style.borderRadius = '10px';
        card.style.padding = '10px 12px';
        card.style.background = '#fff';

        const name = escHtml(item.name || '—');
        const date = escHtml(item.intervention_date || '—');
        const desc = escHtml(item.description || 'No description');
        const items = escHtml(item.items_summary || '');

        card.innerHTML = `
            <div style="font-weight:700;color:var(--slate-900);font-size:14px;border-bottom:1px solid var(--slate-100);padding-bottom:6px;margin-bottom:8px;">${name}</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div style="font-size:12px;color:var(--slate-600);"><span style="font-weight:600;color:var(--slate-800);">Date:</span> ${date}</div>
                ${items ? `<div style="font-size:12px;color:var(--slate-600);"><span style="font-weight:600;color:var(--slate-800);">Items:</span> ${items}</div>` : ''}
            </div>
            <div style="font-size:12px;color:var(--slate-600);margin-top:6px;"><span style="font-weight:600;color:var(--slate-800);">Notes:</span> ${desc}</div>
        `;
        pastInterventionList.appendChild(card);
    });
}

function resetInterventionFormState() {
    childPickerMode = 'add';
    const formAction = document.getElementById('form_action');
    if (formAction) formAction.value = 'add_intervention';
    if (confirmOverrideInput) confirmOverrideInput.value = '0';

    const typeSelect = document.getElementById('type_id_modal');
    if (typeSelect) typeSelect.value = '';
    const dateInput = document.getElementById('intervention_date_modal');
    if (dateInput) dateInput.value = window.currentDate || '';
    const descInput = document.getElementById('description_modal');
    if (descInput) descInput.value = '';
    const originalType = document.getElementById('original_type_id');
    if (originalType) originalType.value = '';
    const originalDesc = document.getElementById('original_description');
    if (originalDesc) originalDesc.value = '';
    const originalDate = document.getElementById('original_date');
    if (originalDate) originalDate.value = '';

    setEditAllowedChildren([]);
    document.querySelectorAll("input[name='child_ids[]']").forEach(cb => cb.checked = false);
    const search = document.getElementById('childSearch');
    if (search) search.value = '';
    resetGiveOutCart();
    toggleGiveOutSection();
    applyEditModalAppearance();
    applyChildPickerMode();
    updateCheckedCount();
}

function submitInterventionAjax() {
    if (!interventionForm) return;
    if (interventionSaveBtn) {
        interventionSaveBtn.disabled = true;
        interventionSaveBtn.style.opacity = '0.7';
    }

    const formData = new FormData(interventionForm);
    fetch('interventions.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        }
    })
        .then(res => {
            if (!res.ok) throw new Error('Server error: ' + res.status);
            return res.json();
        })
        .then(json => {
            if (json && json.success) {
                const payload = json.payload || {};
                if (payload.type_id) {
                    upsertInterventionRow(payload);
                }
                showToast('success', json.message || 'Intervention saved successfully.');
                closeModal('interventionModal');
                resetInterventionFormState();
            } else {
                showValidationError((json && json.message) ? json.message : 'Unable to save intervention.');
            }
        })
        .catch(err => {
            console.error('[SaveIntervention]', err);
            showValidationError('A network error occurred. (' + err.message + ')');
        })
        .finally(() => {
            if (interventionSaveBtn) {
                interventionSaveBtn.disabled = false;
                interventionSaveBtn.style.opacity = '1';
            }
        });
}

const toastHost = document.getElementById('toastContainer');
const pageAlert = document.getElementById('pageAlert');
if (toastHost) {
    const successMsg = toastHost.dataset.success || '';
    const errorMsg = toastHost.dataset.error || '';
    if (successMsg) showToast('success', successMsg);
    if (errorMsg) showToast('error', errorMsg);
    if ((successMsg || errorMsg) && pageAlert) {
        pageAlert.innerHTML = '';
    }
}

/** Save button: validate give-out before submit so the error modal appears immediately. */
const interventionSaveBtn = interventionForm?.querySelector('.modal-footer button[type="submit"]');
if (interventionSaveBtn) {
    interventionSaveBtn.addEventListener(
        'click',
        (event) => {
            if (document.getElementById('form_action')?.value !== 'add_intervention') return;
            const selectedType = parseInt(typeSelectModal?.value || '0', 10);
            if (selectedType !== giveOutTypeId) return;
            if (Object.keys(giveOutCart).length === 0) {
                event.preventDefault();
                event.stopImmediatePropagation();
                showValidationError('You must select at least one item from the inventory to give out.');
                return;
            }
            if (runGiveOutValidationModalIfInvalid()) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        },
        true
    );
}

if (interventionForm) {
    interventionForm.addEventListener('submit', (event) => {
        const action = document.getElementById('form_action')?.value || '';
        if (action !== 'add_intervention') return;

        const selectedType = parseInt(typeSelectModal?.value || '0', 10);
        const selectedChildren = Array.from(document.querySelectorAll("input[name='child_ids[]']:checked"))
            .map(cb => cb.value)
            .filter(Boolean);

        if (!selectedType || selectedChildren.length === 0) return;

        // Give-out rules must run even when confirm_override bypasses the duplicate-intervention check
        if (selectedType === giveOutTypeId) {
            const cartItems = Object.keys(giveOutCart);
            if (cartItems.length === 0) {
                event.preventDefault();
                showValidationError('You must select at least one item from the inventory to give out.');
                return;
            }
            const validationMsg = getGiveOutValidationMessage();
            if (validationMsg) {
                event.preventDefault();
                showValidationError(validationMsg);
                return;
            }
        }

        if (confirmOverrideInput && confirmOverrideInput.value === '1') {
            event.preventDefault();
            submitInterventionAjax();
            return;
        }

        event.preventDefault();

        fetch('interventions.php?action=latest_intervention', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ type_id: selectedType, child_ids: selectedChildren })
        })
            .then(res => res.json())
            .then(json => {
                if (!json || !json.success || !Array.isArray(json.data) || json.data.length === 0) {
                    if (confirmOverrideInput) confirmOverrideInput.value = '1';
                    submitInterventionAjax();
                    return;
                }

                const items = json.data.map(row => {
                    const childId = String(row.child_id || '');
                    const rowEl = document.querySelector(`.child-check-row[data-child-id="${childId}"]`);
                    const nameCell = rowEl ? rowEl.querySelector('.child-name-cell') : null;
                    return {
                        name: nameCell ? nameCell.textContent.trim() : '—',
                        intervention_date: row.intervention_date || '—',
                        description: row.description || 'No description',
                        items_summary: row.items_summary || '',
                    };
                });

                renderPastInterventionList(items);
                openModal('pastInterventionModal');
            })
            .catch(() => {
                if (confirmOverrideInput) confirmOverrideInput.value = '1';
                submitInterventionAjax();
            });
    });
}

if (interventionForm) {
    interventionForm.addEventListener('submit', (event) => {
        const action = document.getElementById('form_action')?.value || '';
        if (action !== 'edit_intervention') return;
        event.preventDefault();
        submitInterventionAjax();
    });
}

/* ── Edit buttons ── */
function openEditIntervention(prefill) {
    childPickerMode = 'edit';
    document.getElementById('interventionModalTitle').textContent = 'Edit Intervention';
    document.getElementById('type_id_modal').value = String(prefill.typeId || prefill.type_id || '');
    document.getElementById('intervention_date_modal').value = prefill.date || window.currentDate || '';
    document.getElementById('form_action').value = 'edit_intervention';
    if (confirmOverrideInput) confirmOverrideInput.value = '0';
    document.getElementById('original_type_id').value = String(prefill.typeId || prefill.type_id || '');
    document.getElementById('original_description').value = prefill.description || '';
    document.getElementById('description_modal').value = prefill.description || '';
    const originalDateInput = document.getElementById('original_date');
    if (originalDateInput) originalDateInput.value = prefill.date || '';

    const selectedIds = (prefill.childIds || prefill.child_ids || []).map(id => String(id));
    setEditAllowedChildren(selectedIds);
    document.querySelectorAll("input[name='child_ids[]']").forEach(cb => {
        cb.checked = selectedIds.includes(cb.value);
    });
    document.getElementById('childSearch').value = '';
    resetGiveOutCart();
    toggleGiveOutSection();
    applyEditModalAppearance();
    applyChildPickerMode();
    updateCheckedCount();
    openModal('interventionModal');
}

document.addEventListener('click', (event) => {
    const btn = event.target.closest('.js-open-edit-intervention');
    if (!btn) return;

    const row = btn.closest('.intervention-row');
    if (!row) return;

    const prefill = {
        type_id: row.dataset.typeId,
        type_name: row.dataset.typeName,
        description: row.dataset.description,
        date: row.dataset.date,
        child_ids: JSON.parse(row.dataset.childIds || '[]')
    };
    openEditIntervention(prefill);
});

if (typeSelectModal) {
    typeSelectModal.addEventListener('change', toggleGiveOutSection);
}

if (giveoutCategorySelect) {
    giveoutCategorySelect.addEventListener('change', function () {
        const catId = this.value;
        if (!giveOutItemSelect) return;

        giveOutItemSelect.value = '';
        Array.from(giveOutItemSelect.options).forEach(opt => {
            if (opt.value === "") return;
            const itemCatId = opt.dataset.category;
            if (!catId || itemCatId === catId) {
                opt.style.display = '';
            } else {
                opt.style.display = 'none';
            }
        });
    });
}

if (giveOutAddBtn) {
    giveOutAddBtn.addEventListener('click', () => {
        hideGiveOutError();
        if (!giveOutItemSelect || !giveOutQtyInput) return;
        if (!giveOutItemSelect.value) {
            showGiveOutError('Please select an inventory item first.');
            return;
        }

        const n = getSelectedChildrenCount();
        if (n === 0) {
            showGiveOutError('Select the children in the table first. Quantity (pcs) must match how many children you select.');
            return;
        }

        const selectedOption = giveOutItemSelect.options[giveOutItemSelect.selectedIndex];
        const inventoryId = giveOutItemSelect.value;
        const maxQty = parseInt(selectedOption.dataset.max || '0', 10);
        const qty = parseInt(giveOutQtyInput.value || '0', 10);

        if (!Number.isInteger(qty) || qty <= 0) {
            showGiveOutError('Enter a valid quantity (pcs).');
            return;
        }
        if (qty !== n) {
            showGiveOutError(`Quantity must be ${n} pcs — the same as the ${n} selected child(ren) (one piece per child).`);
            return;
        }
        if (qty > maxQty) {
            showGiveOutError('Quantity exceeds available stock for selected item.');
            return;
        }

        addGiveOutItem(
            inventoryId,
            selectedOption.dataset.name || selectedOption.textContent,
            selectedOption.dataset.unit || 'unit',
            maxQty,
            qty
        );

        // Reset item selection but keep category? 
        // Usually better to keep category if adding multiple items from same category.
        giveOutItemSelect.value = '';
        syncGiveOutQtyInputDefault();
    });
}

if (giveOutCartBody) {
    giveOutCartBody.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) return;
        const removeId = target.getAttribute('data-giveout-remove');
        if (removeId) {
            removeGiveOutItem(removeId);
        }
    });

    giveOutCartBody.addEventListener('input', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLInputElement)) return;
        const qtyId = target.getAttribute('data-giveout-qty');
        if (!qtyId || !giveOutCart[qtyId]) return;

        const n = getSelectedChildrenCount();
        const nextQty = parseInt(target.value || '0', 10);
        if (!Number.isInteger(nextQty) || nextQty < 1 || nextQty > giveOutCart[qtyId].maxQty) {
            return;
        }
        if (n > 0 && nextQty !== n) {
            hideGiveOutError();
            showGiveOutError(`Quantity must stay ${n} pcs to match the ${n} selected child(ren).`);
            scheduleGiveOutValidationModal();
        } else {
            hideGiveOutError();
        }
        giveOutCart[qtyId].qty = nextQty;
        const hidden = giveOutCartInputs.querySelector(`input[data-giveout-hidden-qty="${qtyId}"]`);
        if (hidden instanceof HTMLInputElement) {
            hidden.value = String(nextQty);
        }
    });
}

/* ── Child filter ── */
const childSearchInput = document.getElementById('childSearch');
if (childSearchInput) {
    childSearchInput.addEventListener('input', function () {
        applyChildPickerMode();
    });
}

/* ── Select / Clear all ── */
const selectAllBtn = document.getElementById('selectAllBtn');
if (selectAllBtn) {
    selectAllBtn.addEventListener('click', () => {
        document.querySelectorAll("input[name='child_ids[]']").forEach(cb => {
            const row = cb.closest('.child-check-row');
            if (row && row.style.display !== 'none') cb.checked = true;
        });
        syncSelectedRowState();
        updateCheckedCount();
        syncGiveOutQtyInputDefault();
        syncGiveOutQuantitiesToChildCount();
        scheduleGiveOutValidationModal();
    });
}

const clearAllBtn = document.getElementById('clearAllBtn');
if (clearAllBtn) {
    clearAllBtn.addEventListener('click', () => {
        document.querySelectorAll("input[name='child_ids[]']").forEach(cb => cb.checked = false);
        syncSelectedRowState();
        updateCheckedCount();
        syncGiveOutQtyInputDefault();
        syncGiveOutQuantitiesToChildCount();
        scheduleGiveOutValidationModal();
    });
}

function updateCheckedCount() {
    const checkedCountEl = document.getElementById('checkedCount');
    if (!checkedCountEl) return;
    const n = document.querySelectorAll("input[name='child_ids[]']:checked").length;
    checkedCountEl.textContent = n;
}

function syncSelectedRowState() {
    document.querySelectorAll('#childrenList .child-check-row').forEach(row => {
        const checkbox = row.querySelector("input[name='child_ids[]']");
        row.classList.toggle('is-selected', !!(checkbox && checkbox.checked));
    });
}

function setEditAllowedChildren(selectedIds) {
    const selectedSet = new Set((selectedIds || []).map(String));
    document.querySelectorAll('#childrenList .child-check-row').forEach(row => {
        const checkbox = row.querySelector("input[name='child_ids[]']");
        row.dataset.editAllowed = (checkbox && selectedSet.has(String(checkbox.value))) ? '1' : '0';
    });
}

function applyChildPickerMode() {
    applyEditModalAppearance();
    const childSearchInput = document.getElementById('childSearch');
    const q = childSearchInput ? childSearchInput.value.toLowerCase().trim() : '';
    document.querySelectorAll('#childrenList .child-check-row').forEach(row => {
        const eligible = row.dataset.eligible === '1';
        const editAllowed = row.dataset.editAllowed === '1';
        const searchMatch = !q || (row.dataset.search || '').includes(q);
        const modeMatch = childPickerMode === 'edit' ? editAllowed : eligible;
        row.classList.toggle('is-hidden', !modeMatch);
        row.style.display = (searchMatch && modeMatch) ? '' : 'none';
    });
    syncSelectedRowState();
}

/* ── Table search ── */
const tableSearchInput = document.getElementById('tableSearch');
if (tableSearchInput) {
    tableSearchInput.addEventListener('input', function () {
        const q = this.value.toLowerCase();
        document.querySelectorAll('#tableBody .intervention-row').forEach(row => {
            row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
    });
}

/* ── Utility ── */
function escHtml(str) {
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function statusPillClass(status) {
    const normalized = String(status || '').trim().toLowerCase();
    if (['normal', 'tall'].includes(normalized)) return 'is-good';
    if (['severely underweight', 'severely stunted', 'severely wasted'].includes(normalized)) return 'is-bad';
    if (['underweight', 'stunted', 'wasted'].includes(normalized)) return 'is-warn';
    if (['overweight', 'obese'].includes(normalized)) return 'is-alert';
    return 'is-muted';
}
