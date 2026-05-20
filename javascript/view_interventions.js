document.addEventListener('DOMContentLoaded', function () {
    const editModal = document.getElementById('editModal');
    const openEditBtn = document.getElementById('btnOpenEditModal');
    const closeEditModalBtn = document.getElementById('btnCloseEditModal');
    const confirmModal = document.getElementById('confirmChildModal');
    const confirmName = document.getElementById('confirmChildName');
    const closeConfirmBtn = document.getElementById('btnCloseConfirm');
    const closeConfirmTopBtn = document.getElementById('btnCloseConfirmTop');
    const confirmGoBtn = document.getElementById('btnConfirmGo');
    const historyModal = document.getElementById('historyModal');
    const historyList = document.getElementById('historyList');
    const historyChildName = document.getElementById('historyChildName');
    const closeHistoryBtn = document.getElementById('btnCloseHistory');
    const closeHistoryTopBtn = document.getElementById('btnCloseHistoryTop');
    const filterYear = document.getElementById('filterYear');
    const filterMonth = document.getElementById('filterMonth');
    const filterBarangay = document.getElementById('filterBarangay');
    const filterSex = document.getElementById('filterSex');
    const filterAgeMin = document.getElementById('filterAgeMin');
    const filterAgeMax = document.getElementById('filterAgeMax');
    const filterSearch = document.getElementById('filterSearch');
    const btnResetFilters = document.getElementById('btnResetFilters');

    const config = window.interventionConfig || { historyTypeId: 0, isGiveOut: false };
    const historyTypeId = config.historyTypeId;
    const isGiveOutModal = config.isGiveOut;
    let pendingChildProfileId = '';

    function openEditModal() {
        if (!editModal) return;
        editModal.classList.add('is-open');
        editModal.setAttribute('aria-hidden', 'false');
    }

    function closeEditModal() {
        if (!editModal) return;
        editModal.classList.remove('is-open');
        editModal.setAttribute('aria-hidden', 'true');
    }

    function openConfirmChildModal(childId, childName) {
        if (!confirmModal) return;
        pendingChildProfileId = childId || '';
        if (confirmName) {
            confirmName.textContent = childName ? `Name: ${childName}` : '';
        }
        confirmModal.classList.add('is-open');
        confirmModal.setAttribute('aria-hidden', 'false');
    }

    function closeConfirmChildModal() {
        if (!confirmModal) return;
        confirmModal.classList.remove('is-open');
        confirmModal.setAttribute('aria-hidden', 'true');
    }

    function openHistoryModal(childName) {
        if (!historyModal) return;
        historyModal.classList.add('is-open');
        historyModal.setAttribute('aria-hidden', 'false');
        if (historyChildName) {
            historyChildName.textContent = childName ? `Child: ${childName}` : 'Child';
        }
    }

    function closeHistoryModal() {
        if (!historyModal) return;
        historyModal.classList.remove('is-open');
        historyModal.setAttribute('aria-hidden', 'true');
    }

    if (openEditBtn) {
        openEditBtn.addEventListener('click', (event) => {
            event.preventDefault();
            openEditModal();
        });
    }

    if (closeEditModalBtn) {
        closeEditModalBtn.addEventListener('click', closeEditModal);
    }
    if (editModal) {
        editModal.addEventListener('click', (event) => {
            if (event.target === editModal) closeEditModal();
        });
    }

    if (closeConfirmBtn) {
        closeConfirmBtn.addEventListener('click', closeConfirmChildModal);
    }
    if (closeConfirmTopBtn) {
        closeConfirmTopBtn.addEventListener('click', closeConfirmChildModal);
    }
    if (confirmModal) {
        confirmModal.addEventListener('click', (event) => {
            if (event.target === confirmModal) closeConfirmChildModal();
        });
    }
    if (confirmGoBtn) {
        confirmGoBtn.addEventListener('click', () => {
            if (!pendingChildProfileId) return;
            window.location.href = `view_child_profile.php?child_id=${encodeURIComponent(pendingChildProfileId)}`;
        });
    }

    if (closeHistoryBtn) {
        closeHistoryBtn.addEventListener('click', closeHistoryModal);
    }
    if (closeHistoryTopBtn) {
        closeHistoryTopBtn.addEventListener('click', closeHistoryModal);
    }
    if (historyModal) {
        historyModal.addEventListener('click', (event) => {
            if (event.target === historyModal) closeHistoryModal();
        });
    }

    document.querySelectorAll('.btn-view-history').forEach((btn) => {
        btn.addEventListener('click', (event) => {
            event.stopPropagation();
            const childId = btn.getAttribute('data-child-id');
            const childName = btn.getAttribute('data-child-name') || '';
            if (!childId) return;

            if (historyList) {
                historyList.innerHTML = `<tr><td colspan="${isGiveOutModal ? 4 : 2}"><div class="empty-state">Loading history…</div></td></tr>`;
            }
            openHistoryModal(childName);

            fetch(`view_interventions.php?action=child_history&type_id=${encodeURIComponent(historyTypeId)}&child_id=${encodeURIComponent(childId)}`)
                .then(res => res.json())
                .then(json => {
                    if (!historyList) return;
                    if (!json || !json.success || !Array.isArray(json.data) || json.data.length === 0) {
                        historyList.innerHTML = `<tr><td colspan="${isGiveOutModal ? 4 : 2}"><div class="empty-state">No history found.</div></td></tr>`;
                        return;
                    }

                    historyList.innerHTML = '';
                    json.data.forEach(item => {
                        const date = item.intervention_date || '—';
                        const desc = item.description || 'No description';
                        
                        const itemsRaw = item.given_items || '';
                        const qtysRaw = item.given_qtys || '';
                        let itemsHtml = '—';
                        let qtysHtml = '—';
                        
                        if (itemsRaw !== '') {
                            const itemsArr = itemsRaw.split('||');
                            const qtysArr = qtysRaw.split('||');
                            
                            itemsHtml = itemsArr.map(i => {
                                const safeStr = i.replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                                return `<div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="${safeStr}">${safeStr}</div>`;
                            }).join('');
                            
                            qtysHtml = qtysArr.map(q => {
                                const safeStr = q.replace(/</g, '&lt;').replace(/>/g, '&gt;');
                                return `<div>${safeStr}</div>`;
                            }).join('');
                        }
                        
                        const row = document.createElement('tr');
                        
                        if (isGiveOutModal) {
                            row.innerHTML = `
                                <td class="cell-center">${date}</td>
                                <td class="cell-left">${itemsHtml}</td>
                                <td class="cell-center">${qtysHtml}</td>
                                <td class="cell-left">${desc}</td>
                            `;
                        } else {
                            row.innerHTML = `
                                <td class="cell-center">${date}</td>
                                <td class="cell-left">${desc}</td>
                            `;
                        }
                        historyList.appendChild(row);
                    });
                })
                .catch(() => {
                    if (historyList) {
                        historyList.innerHTML = `<tr><td colspan="${isGiveOutModal ? 4 : 2}"><div class="empty-state">Failed to load history.</div></td></tr>`;
                    }
                });
        });
    });

    function applyFilters() {
        const yearValue    = filterYear    ? filterYear.value.trim()    : '';
        const monthValue   = filterMonth   ? filterMonth.value.trim()   : '';
        const barangayValue= filterBarangay? filterBarangay.value.trim(): '';
        const sexValue     = filterSex     ? filterSex.value.trim()     : '';
        const searchValue  = filterSearch  ? filterSearch.value.trim().toLowerCase() : '';
        const ageMinValue  = filterAgeMin && filterAgeMin.value !== '' ? parseInt(filterAgeMin.value, 10) : null;
        const ageMaxValue  = filterAgeMax && filterAgeMax.value !== '' ? parseInt(filterAgeMax.value, 10) : null;

        document.querySelectorAll('tbody tr.child-profile-row').forEach(row => {
            const dataCell   = row.querySelector('td[data-month]');
            const rowYear    = dataCell ? dataCell.dataset.year     || '' : '';
            const rowMonth   = dataCell ? dataCell.dataset.month    || '' : '';
            const rowSex     = dataCell ? dataCell.dataset.sex      || '' : '';
            const rowBarangay= dataCell ? dataCell.dataset.barangay || '' : '';
            const rowName    = dataCell ? dataCell.dataset.name     || '' : '';
            const rowAddress = dataCell ? dataCell.dataset.address  || '' : '';
            const rowAge     = dataCell && dataCell.dataset.age !== '' ? parseInt(dataCell.dataset.age, 10) : null;

            let visible = true;
            if (yearValue     && rowYear     !== yearValue)     visible = false;
            if (visible && monthValue    && rowMonth    !== monthValue)    visible = false;
            if (visible && barangayValue && rowBarangay !== barangayValue) visible = false;
            if (visible && sexValue      && rowSex      !== sexValue)      visible = false;
            if (visible && ageMinValue !== null) {
                if (rowAge === null || rowAge < ageMinValue) visible = false;
            }
            if (visible && ageMaxValue !== null) {
                if (rowAge === null || rowAge > ageMaxValue) visible = false;
            }
            if (visible && searchValue) {
                if (!rowName.includes(searchValue) && !rowAddress.includes(searchValue)) visible = false;
            }

            row.style.display = visible ? '' : 'none';
        });
    }

    if (filterYear)     filterYear.addEventListener('change', applyFilters);
    if (filterMonth)    filterMonth.addEventListener('change', applyFilters);
    if (filterBarangay) filterBarangay.addEventListener('change', applyFilters);
    if (filterSex)      filterSex.addEventListener('change', applyFilters);
    if (filterAgeMin)   filterAgeMin.addEventListener('input', applyFilters);
    if (filterAgeMax)   filterAgeMax.addEventListener('input', applyFilters);
    if (filterSearch)   filterSearch.addEventListener('input', applyFilters);

    if (btnResetFilters) {
        btnResetFilters.addEventListener('click', () => {
            if (filterYear)      filterYear.value = '';
            if (filterMonth)     filterMonth.value = '';
            if (filterBarangay)  filterBarangay.value = '';
            if (filterSex)       filterSex.value = '';
            if (filterAgeMin)    filterAgeMin.value = '';
            if (filterAgeMax)    filterAgeMax.value = '';
            if (filterSearch)    filterSearch.value = '';
            applyFilters();
        });
    }

    document.querySelectorAll('.child-profile-row').forEach((row) => {
        row.addEventListener('click', (event) => {
            const target = event.target;
            if (target instanceof HTMLElement && target.closest('a,button,input,select,textarea,label')) return;
            const childId = row.getAttribute('data-child-id');
            if (!childId) return;
            const nameCell = row.querySelector('.name-cell');
            const childName = nameCell ? nameCell.textContent.trim() : '';
            openConfirmChildModal(childId, childName);
        });
    });

    document.addEventListener('click', function(event) {
        const btn = event.target;
        if (btn && btn.classList.contains('btn-see-more')) {
            event.stopPropagation();
            const parent = btn.parentNode;
            const shortSpan = parent.querySelector('.note-short');
            const fullSpan = parent.querySelector('.note-full');
            if (shortSpan && fullSpan) {
                if (fullSpan.style.display === 'none') {
                    fullSpan.style.display = 'inline';
                    shortSpan.style.display = 'none';
                    btn.textContent = 'See less';
                } else {
                    fullSpan.style.display = 'none';
                    shortSpan.style.display = 'inline';
                    btn.textContent = 'See more';
                }
            }
        }
    });
});
