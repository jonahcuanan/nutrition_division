document.addEventListener('DOMContentLoaded', function () {
    const editModal = document.getElementById('editModal');
    const openEditBtn = document.getElementById('btnOpenEditModal');
    const closeEditModalBtn = document.getElementById('btnCloseEditModal');
    const closeEditModalTopBtn = document.getElementById('btnCloseEditModalTop');
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
    const filterMonth = document.getElementById('filterMonth');
    const filterSex = document.getElementById('filterSex');
    const filterAgeMin = document.getElementById('filterAgeMin');
    const filterAgeMax = document.getElementById('filterAgeMax');

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
    if (closeEditModalTopBtn) {
        closeEditModalTopBtn.addEventListener('click', closeEditModal);
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
        const monthValue = filterMonth ? filterMonth.value : '';
        const sexValue = filterSex ? filterSex.value : '';
        const ageMinValue = filterAgeMin && filterAgeMin.value !== '' ? parseInt(filterAgeMin.value, 10) : null;
        const ageMaxValue = filterAgeMax && filterAgeMax.value !== '' ? parseInt(filterAgeMax.value, 10) : null;

        document.querySelectorAll('tbody tr.child-profile-row').forEach(row => {
            const dataCell = row.querySelector('td[data-month]');
            const rowMonth = dataCell ? dataCell.dataset.month || '' : '';
            const rowSex = dataCell ? dataCell.dataset.sex || '' : '';
            const rowAge = dataCell && dataCell.dataset.age !== '' ? parseInt(dataCell.dataset.age, 10) : null;

            let visible = true;
            if (monthValue && rowMonth !== monthValue) visible = false;
            if (visible && sexValue && rowSex !== sexValue) visible = false;
            if (visible && ageMinValue !== null) {
                if (rowAge === null || rowAge < ageMinValue) visible = false;
            }
            if (visible && ageMaxValue !== null) {
                if (rowAge === null || rowAge > ageMaxValue) visible = false;
            }

            row.style.display = visible ? '' : 'none';
        });
    }

    if (filterMonth) filterMonth.addEventListener('change', applyFilters);
    if (filterSex) filterSex.addEventListener('change', applyFilters);
    if (filterAgeMin) filterAgeMin.addEventListener('input', applyFilters);
    if (filterAgeMax) filterAgeMax.addEventListener('input', applyFilters);

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
});
