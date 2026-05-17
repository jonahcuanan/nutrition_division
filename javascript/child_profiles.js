document.addEventListener('DOMContentLoaded', () => {
    // ── Global References ──
    const updateModal = document.getElementById('updateModal');
    const updateModalBox = document.getElementById('updateModalBox');
    const updateModalBackdrop = document.getElementById('updateModalBackdrop');
    const updateForm = document.getElementById('updateProfileForm');
    const btnUpdateSave = document.getElementById('btnUpdateSave');
    const btnUpdateCancel = document.getElementById('btnUpdateCancel');
    const updateModalMessage = document.getElementById('updateModalMessage');

    const profileSection = document.getElementById('profileEditSection');
    const measurementSection = document.getElementById('measurementSection');
    const muacUpdateContainer = document.getElementById('muacUpdateContainer');
    const previewSection = document.getElementById('previewSection');

    // Inputs
    const inputRecordId = document.getElementById('record_id');
    const inputChildId = document.getElementById('child_id');
    const inputUpdateMode = document.getElementById('update_mode');
    const inputWeightId = document.getElementById('weight_id');
    const inputHeightId = document.getElementById('height_id');
    const inputWflId = document.getElementById('wfl_id');
    const inputMuacId = document.getElementById('muac_id');
    const inputMeasurementDate = document.getElementById('measurement_date');
    const inputAgeMonths = document.getElementById('age_in_months');
    const inputHeight = document.getElementById('height');
    const inputWeight = document.getElementById('weight');
    const inputMuac = document.getElementById('muac');

    // Preview Labels
    const infoHfa = document.getElementById('info_hfa_status');
    const infoWfa = document.getElementById('info_wfa_status');
    const infoWfl = document.getElementById('info_wfl_status');
    const infoMuac = document.getElementById('info_muac_status');
    const statusPreviewDot = document.getElementById('statusPreviewDot');
    const statusPreviewHint = document.getElementById('statusPreviewHint');

    // Filter Elements
    const btnToggleFilters = document.getElementById('btnToggleFilters');
    const advancedFiltersPanel = document.getElementById('advancedFiltersPanel');
    const iconToggleFilters = document.getElementById('iconToggleFilters');
    const btnResetFilters = document.getElementById('btnResetFilters');
    const btnGenerateReport = document.getElementById('btnGenerateReport');

    const searchInput = document.getElementById('searchInput');
    const sexFilter = document.getElementById('sexFilter');
    const ipFilter = document.getElementById('ipFilter');
    const barangayFilter = document.getElementById('barangayFilter');
    const ageMinFilter = document.getElementById('ageMinFilter');
    const ageMaxFilter = document.getElementById('ageMaxFilter');
    const hfaFilter = document.getElementById('hfaFilter');
    const wfaFilter = document.getElementById('wfaFilter');
    const wflhFilter = document.getElementById('wflhFilter');

    const tableBody = document.getElementById('tableBody');
    const printTableBody = document.getElementById('printTableBody');

    let currentBirthdate = '';
    let currentSex = '';
    let statusDebounceTimer = null;

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

    // ── Modal Logic ──
    function openUpdateModal() {
        updateModal.classList.remove('invisible', 'pointer-events-none');
        updateModal.classList.add('opacity-100');
        updateModalBox.classList.remove('translate-y-4', 'scale-95');
        updateModalBox.classList.add('translate-y-0', 'scale-100');
        document.body.classList.add('update-modal-open');
    }

    function closeUpdateModal() {
        updateModal.classList.add('opacity-0');
        updateModalBox.classList.add('translate-y-4', 'scale-95');
        updateModalBox.classList.remove('translate-y-0', 'scale-100');
        setTimeout(() => {
            updateModal.classList.add('invisible', 'pointer-events-none');
            updateModal.classList.remove('opacity-100');
            document.body.classList.remove('update-modal-open');
            updateForm.reset();
            updateModalMessage.innerHTML = '';
            resetPreviews();
        }, 200);
    }

    function resetPreviews() {
        [infoHfa, infoWfa, infoWfl, infoMuac].forEach(el => {
            if (el) {
                el.textContent = '—';
                el.className = 'text-[0.76rem] font-bold text-slate-300';
            }
        });
        if (statusPreviewDot) statusPreviewDot.classList.remove('bg-emerald-400', 'animate-pulse');
        if (statusPreviewDot) statusPreviewDot.classList.add('bg-slate-300');
        if (statusPreviewHint) statusPreviewHint.textContent = '(enter height & weight)';
    }

    // ── Action Handlers ──
    document.querySelectorAll('.btn-open-update').forEach(btn => {
        btn.addEventListener('click', () => {
            const childId = btn.getAttribute('data-child-id');
            const mode = btn.getAttribute('data-mode') || 'measurement';

            inputChildId.value = childId;
            inputUpdateMode.value = mode;

            // Reset UI States
            profileSection.classList.add('hidden');
            measurementSection.classList.add('hidden');
            muacUpdateContainer.classList.add('hidden');
            previewSection.classList.add('hidden');

            // Re-show Height/Weight containers in case they were hidden by MUAC mode
            inputHeight.parentElement.classList.remove('hidden');
            inputWeight.parentElement.classList.remove('hidden');

            const modalTitle = document.getElementById('updateModalTitle');
            const modalInstr = document.getElementById('updateModalInstruction');

            if (mode === 'measurement') {
                measurementSection.classList.remove('hidden');
                previewSection.classList.remove('hidden');
                inputMeasurementDate.parentElement.classList.remove('hidden');
                inputMeasurementDate.required = true;
                inputHeight.required = true;
                inputWeight.required = true;
                modalTitle.textContent = 'Update Growth Measurement';
                modalInstr.textContent = 'Enter the latest height and weight for this child';
                // MUAC remains hidden in measurement mode
            } else if (mode === 'muac') {
                measurementSection.classList.remove('hidden');
                muacUpdateContainer.classList.remove('hidden');
                previewSection.classList.remove('hidden');

                // Hide Measurement Date for MUAC mode as requested
                inputMeasurementDate.parentElement.classList.add('hidden');
                inputMeasurementDate.required = false;

                // Hide Height/Weight for pure MUAC update as they are inherited
                inputHeight.parentElement.classList.add('hidden');
                inputWeight.parentElement.classList.add('hidden');
                inputHeight.required = false;
                inputWeight.required = false;

                modalTitle.textContent = 'Update MUAC Measurement';
                modalInstr.textContent = 'Enter the latest MUAC for this child (Height/Weight will be preserved)';
            } else if (mode === 'profile') {
                profileSection.classList.remove('hidden');
                profileSection.open = true;
                modalTitle.textContent = 'Edit Child Profile';
                modalInstr.textContent = 'Update basic information and identity details';
            }

            // Fetch Child Data
            fetch(`child_profiles.php?action=get_child_profile&child_id=${childId}`)
                .then(res => res.json())
                .then(json => {
                    if (json.success && json.data) {
                        const d = json.data;
                        currentBirthdate = d.birthdate;
                        currentSex = d.sex;

                        // Populate Profile Fields
                        document.getElementById('edit_first_name').value = d.first_name || '';
                        document.getElementById('edit_last_name').value = d.last_name || '';
                        document.getElementById('edit_birthdate').value = d.birthdate || '';
                        document.getElementById('edit_sex').value = d.sex || 'Male';
                        document.getElementById('edit_address').value = d.address || '';
                        document.getElementById('edit_g_first').value = d.guardian_first || '';
                        document.getElementById('edit_g_last').value = d.guardian_last || '';
                        document.getElementById('edit_ip').value = d.is_ip || 'No';

                        // Check if middle name and suffix are available
                        if (document.getElementById('edit_middle_name')) {
                            document.getElementById('edit_middle_name').value = d.middle_name || '';
                        }
                        if (document.getElementById('edit_suffix')) {
                            document.getElementById('edit_suffix').value = d.suffix || '';
                        }

                        // Populate Hidden IDs
                        if (inputRecordId) inputRecordId.value = d.record_id || '';
                        if (inputChildId) inputChildId.value = d.child_id || '';
                        if (inputWeightId) inputWeightId.value = d.weight_id || '';
                        if (inputHeightId) inputHeightId.value = d.height_id || '';
                        if (inputWflId) inputWflId.value = d.wfl_id || '';
                        if (inputMuacId) inputMuacId.value = d.muac_id || '';

                        // Measurement defaults
                        inputMeasurementDate.value = document.body.getAttribute('data-server-today');

                        if (mode === 'muac' || mode === 'measurement' || mode === 'both') {
                            // Pre-fill last height/weight for calculation reference
                            inputHeight.value = d.height > 0 ? d.height : '';
                            inputWeight.value = d.weight > 0 ? d.weight : '';
                            inputMuac.value = d.muac_measurement > 0 ? d.muac_measurement : '';

                            // Trigger status preview to show current nutritional status immediately
                            updateStatusPreview();
                        }

                        calculateAge();
                        openUpdateModal();
                    } else {
                        showToast('error', 'Error fetching child profile: ' + (json.message || 'Unknown error'));
                    }
                })
                .catch(err => {
                    console.error(err);
                    showToast('error', 'Network error while fetching child profile.');
                });
        });
    });

    // ── Logic Helpers ──
    function calculateAge() {
        const bStr = (inputUpdateMode.value === 'profile') ? document.getElementById('edit_birthdate').value : currentBirthdate;
        const mStr = inputMeasurementDate.value;

        if (!bStr || !mStr) {
            inputAgeMonths.value = '';
            return;
        }

        const b = new Date(bStr);
        const m = new Date(mStr);

        if (m < b) {
            inputAgeMonths.value = '0';
            return;
        }

        let months = (m.getFullYear() - b.getFullYear()) * 12 + (m.getMonth() - b.getMonth());
        if (m.getDate() < b.getDate()) months--;

        inputAgeMonths.value = months >= 0 ? months : 0;

        updateStatusPreview();
    }

    function updateStatusPreview() {
        const mode = inputUpdateMode.value;
        if (mode === 'profile') return;

        const age = inputAgeMonths.value;
        const height = inputHeight.value;
        const weight = inputWeight.value;
        const muac = inputMuac.value;
        const sex = currentSex;

        if (!age || !height || !weight || height <= 0 || weight <= 0) {
            resetPreviews();
            return;
        }

        if (statusDebounceTimer) clearTimeout(statusDebounceTimer);

        statusDebounceTimer = setTimeout(() => {
            if (statusPreviewDot) {
                statusPreviewDot.classList.remove('bg-slate-300');
                statusPreviewDot.classList.add('bg-emerald-400', 'animate-pulse');
            }
            if (statusPreviewHint) statusPreviewHint.textContent = 'Calculating...';

            fetch(`child_profiles.php?action=compute_status&age_in_months=${age}&sex=${sex}&height=${height}&weight=${weight}&muac=${muac}`)
                .then(res => res.json())
                .then(json => {
                    if (json.success) {
                        applyStatusStyle(infoHfa, json.height_for_age_status);
                        applyStatusStyle(infoWfa, json.weight_for_age_status);
                        applyStatusStyle(infoWfl, json.weight_for_ltht_status);
                        applyStatusStyle(infoMuac, json.muac_status);
                        if (statusPreviewHint) statusPreviewHint.textContent = 'Live preview';
                    }
                })
                .catch(() => {
                    if (statusPreviewHint) statusPreviewHint.textContent = 'Error computing status';
                });
        }, 400);
    }

    function applyStatusStyle(el, status) {
        if (!el) return;
        el.textContent = status || 'N/A';
        const s = (status || '').toLowerCase();

        el.className = 'text-[0.76rem] font-bold';
        if (s.includes('severely')) el.classList.add('text-rose-600');
        else if (s.includes('underweight') || s.includes('stunted') || s.includes('wasted')) el.classList.add('text-amber-600');
        else if (s.includes('overweight') || s.includes('obese')) el.classList.add('text-orange-600');
        else if (s === 'normal' || s === 'tall' || s === 'n') el.classList.add('text-emerald-600');
        else el.classList.add('text-slate-400');
    }

    // ── Event Listeners ──
    inputMeasurementDate.addEventListener('change', calculateAge);
    inputHeight.addEventListener('input', updateStatusPreview);
    inputWeight.addEventListener('input', updateStatusPreview);
    inputMuac.addEventListener('input', updateStatusPreview);

    document.getElementById('edit_birthdate').addEventListener('change', () => {
        if (inputUpdateMode.value === 'profile') calculateAge();
    });

    btnUpdateCancel.addEventListener('click', closeUpdateModal);
    updateModalBackdrop.addEventListener('click', closeUpdateModal);

    // ── Form Submission ──
    updateForm.addEventListener('submit', function (e) {
        e.preventDefault();

        // Final check: set required attributes based on mode just before validation check
        const mode = inputUpdateMode.value;
        if (mode === 'profile') {
            document.getElementById('edit_first_name').required = true;
            document.getElementById('edit_last_name').required = true;
            document.getElementById('edit_birthdate').required = true;
            inputMeasurementDate.required = false;
            inputHeight.required = false;
            inputWeight.required = false;
            inputMuac.required = false;
        } else if (mode === 'measurement') {
            document.getElementById('edit_first_name').required = false;
            document.getElementById('edit_last_name').required = false;
            document.getElementById('edit_birthdate').required = false;
            inputMeasurementDate.required = true;
            inputHeight.required = true;
            inputWeight.required = true;
            inputMuac.required = false;
        } else if (mode === 'muac') {
            document.getElementById('edit_first_name').required = false;
            document.getElementById('edit_last_name').required = false;
            document.getElementById('edit_birthdate').required = false;
            inputMeasurementDate.required = false; // Optional in MUAC mode as backend defaults to today
            inputHeight.required = false;
            inputWeight.required = false;
            inputMuac.required = true;
        }

        if (!updateForm.checkValidity()) {
            updateForm.reportValidity();
            return;
        }

        btnUpdateSave.disabled = true;
        btnUpdateSave.classList.add('opacity-70');
        updateModalMessage.innerHTML = '';

        const formData = new FormData(updateForm);

        fetch('update_profile.php', {
            method: 'POST',
            body: formData
        })
            .then(res => res.json())
            .then(json => {
                if (json.success) {
                    updateModalMessage.innerHTML = '';
                    showToast('success', json.message || 'Changes saved successfully.');
                    closeUpdateModal();
                    btnUpdateSave.disabled = false;
                    btnUpdateSave.classList.remove('opacity-70');
                } else {
                    updateModalMessage.innerHTML = '';
                    showToast('error', json.message || 'Unable to save changes.');
                    btnUpdateSave.disabled = false;
                    btnUpdateSave.classList.remove('opacity-70');
                }
            })
            .catch(err => {
                console.error(err);
                updateModalMessage.innerHTML = '';
                showToast('error', 'Network error occurred.');
                btnUpdateSave.disabled = false;
                btnUpdateSave.classList.remove('opacity-70');
            });
    });

    function filterRows() {
        const query = (searchInput?.value || '').toLowerCase().trim();
        const sex = (sexFilter?.value || '').toLowerCase();
        const ip = (ipFilter?.value || '').toLowerCase();
        const barangay = (barangayFilter?.value || '').toLowerCase();
        const ageMin = (ageMinFilter?.value) ? parseInt(ageMinFilter.value) : null;
        const ageMax = (ageMaxFilter?.value) ? parseInt(ageMaxFilter.value) : null;
        const hfa = (hfaFilter?.value || '').toLowerCase();
        const wfa = (wfaFilter?.value || '').toLowerCase();
        const wflh = (wflhFilter?.value || '').toLowerCase();

        // Filter Screen Table
        const screenRows = tableBody ? tableBody.querySelectorAll('tr[data-child-id]') : [];
        let screenCount = 0;

        screenRows.forEach(row => {
            if (row.id === 'screenNoDataRow') return;
            const matches = checkRowMatches(row, query, sex, ip, barangay, ageMin, ageMax, hfa, wfa, wflh);
            if (matches) {
                row.style.display = '';
                screenCount++;
            } else {
                row.style.display = 'none';
            }
        });

        // Filter Print Table
        const printRows = printTableBody ? printTableBody.querySelectorAll('tr[data-name]') : [];
        let printCount = 0;
        let seq = 1;

        printRows.forEach(row => {
            if (row.id === 'printNoDataRow') return;
            const matches = checkRowMatches(row, query, sex, ip, barangay, ageMin, ageMax, hfa, wfa, wflh);
            if (matches) {
                row.style.display = '';
                const seqCell = row.querySelector('.print-center');
                if (seqCell) seqCell.textContent = seq++;
                printCount++;
            } else {
                row.style.display = 'none';
            }
        });

        // Update UI
        const rowCount = document.getElementById('rowCount');
        if (rowCount) rowCount.textContent = `${screenCount} records`;

        const screenNoDataRow = document.getElementById('screenNoDataRow');
        if (screenNoDataRow) {
            if (screenCount === 0 && screenRows.length > 0) {
                screenNoDataRow.style.display = '';
            } else {
                screenNoDataRow.style.display = 'none';
            }
        }

        const printNoDataRow = document.getElementById('printNoDataRow');
        if (printNoDataRow) {
            if (printCount === 0 && (screenRows.length > 0 || printRows.length > 0)) {
                printNoDataRow.style.display = '';
            } else {
                printNoDataRow.style.display = 'none';
            }
        }
    }

    function checkRowMatches(row, query, sex, ip, barangay, ageMin, ageMax, hfa, wfa, wflh) {
        const rowName = (row.getAttribute('data-name') || '').toLowerCase();
        const rowSex = (row.getAttribute('data-sex') || '').toLowerCase();
        const rowIp = (row.getAttribute('data-ip') || '').toLowerCase();
        const rowBarangay = (row.getAttribute('data-barangay') || '').toLowerCase();
        const rowAge = row.getAttribute('data-age') ? parseInt(row.getAttribute('data-age')) : null;
        const rowHfa = (row.getAttribute('data-hfa') || '').toLowerCase();
        const rowWfa = (row.getAttribute('data-wfa') || '').toLowerCase();
        const rowWflh = (row.getAttribute('data-wflh') || '').toLowerCase();
        const rowText = row.textContent.toLowerCase();

        const matchesQuery = !query || rowName.includes(query) || rowText.includes(query);
        const matchesSex = !sex || rowSex === sex || (sex === 'm' && rowSex === 'male') || (sex === 'f' && rowSex === 'female');
        const matchesIp = !ip || rowIp === ip;
        const matchesBarangay = !barangay || rowBarangay === barangay;
        const matchesAgeMin = ageMin === null || (rowAge !== null && rowAge >= ageMin);
        const matchesAgeMax = ageMax === null || (rowAge !== null && rowAge <= ageMax);
        const matchesHfa = !hfa || rowHfa === hfa;
        const matchesWfa = !wfa || rowWfa === wfa;
        const matchesWflh = !wflh || rowWflh === wflh;

        return matchesQuery && matchesSex && matchesIp && matchesBarangay && matchesAgeMin && matchesAgeMax && matchesHfa && matchesWfa && matchesWflh;
    }

    // Filter Event Listeners
    [searchInput, sexFilter, ipFilter, barangayFilter, ageMinFilter, ageMaxFilter, hfaFilter, wfaFilter, wflhFilter].forEach(el => {
        if (el) el.addEventListener('input', filterRows);
        if (el && (el.tagName === 'SELECT')) el.addEventListener('change', filterRows);
    });

    // Toggle Advanced Filters
    if (btnToggleFilters && advancedFiltersPanel) {
        btnToggleFilters.addEventListener('click', () => {
            const isHidden = advancedFiltersPanel.classList.contains('hidden');
            if (isHidden) {
                advancedFiltersPanel.classList.remove('hidden');
                advancedFiltersPanel.classList.add('flex');
                if (iconToggleFilters) iconToggleFilters.style.transform = 'rotate(180deg)';
            } else {
                advancedFiltersPanel.classList.add('hidden');
                advancedFiltersPanel.classList.remove('flex');
                if (iconToggleFilters) iconToggleFilters.style.transform = 'rotate(0deg)';
            }
        });
    }

    // Reset Filters
    if (btnResetFilters) {
        btnResetFilters.addEventListener('click', () => {
            [searchInput, sexFilter, ipFilter, barangayFilter, ageMinFilter, ageMaxFilter, hfaFilter, wfaFilter, wflhFilter].forEach(el => {
                if (el) el.value = '';
            });
            filterRows();
        });
    }

    // Print Functionality
    if (btnGenerateReport) {
        btnGenerateReport.addEventListener('click', () => {
            window.print();
        });
    }

    // Pre-populate filters from URL query parameters on load
    function initFiltersFromUrl() {
        const urlParams = new URLSearchParams(window.location.search);
        
        const paramSearch = urlParams.get('search') || urlParams.get('query');
        const paramSex = urlParams.get('sex');
        const paramIp = urlParams.get('ip');
        const paramBarangayId = urlParams.get('barangay_id');
        const paramBarangayName = urlParams.get('barangay_name') || urlParams.get('barangay');
        const paramAgeMin = urlParams.get('age_min') || urlParams.get('age_min_months');
        const paramAgeMax = urlParams.get('age_max') || urlParams.get('age_max_months');
        const paramHfa = urlParams.get('hfa');
        const paramWfa = urlParams.get('wfa');
        const paramWflh = urlParams.get('wflh');

        let hasActiveFilters = false;

        if (paramSearch && searchInput) { searchInput.value = paramSearch; hasActiveFilters = true; }
        if (paramSex && sexFilter) { sexFilter.value = paramSex.toLowerCase(); hasActiveFilters = true; }
        if (paramIp && ipFilter) { ipFilter.value = paramIp.toLowerCase(); hasActiveFilters = true; }
        
        if (barangayFilter) {
            if (paramBarangayId) {
                const opt = Array.from(barangayFilter.options).find(o => o.value.includes(paramBarangayId.toLowerCase()));
                if (opt) {
                    barangayFilter.value = opt.value;
                    hasActiveFilters = true;
                }
            } else if (paramBarangayName) {
                const opt = Array.from(barangayFilter.options).find(o => o.value.toLowerCase() === paramBarangayName.toLowerCase());
                if (opt) {
                    barangayFilter.value = opt.value;
                    hasActiveFilters = true;
                }
            }
        }

        if (paramAgeMin && ageMinFilter) { ageMinFilter.value = paramAgeMin; hasActiveFilters = true; }
        if (paramAgeMax && ageMaxFilter) { ageMaxFilter.value = paramAgeMax; hasActiveFilters = true; }
        if (paramHfa && hfaFilter) { hfaFilter.value = paramHfa.toLowerCase(); hasActiveFilters = true; }
        if (paramWfa && wfaFilter) { wfaFilter.value = paramWfa.toLowerCase(); hasActiveFilters = true; }
        if (paramWflh && wflhFilter) { wflhFilter.value = paramWflh.toLowerCase(); hasActiveFilters = true; }

        if (hasActiveFilters) {
            // Expand the advanced filters panel if any advanced filters are active
            if (advancedFiltersPanel && (paramAgeMin || paramAgeMax || paramHfa || paramWfa || paramWflh || (barangayFilter && barangayFilter.value))) {
                advancedFiltersPanel.classList.remove('hidden');
                advancedFiltersPanel.classList.add('flex');
                if (iconToggleFilters) iconToggleFilters.style.transform = 'rotate(180deg)';
            }
            filterRows();
        }
    }

    initFiltersFromUrl();
    filterRows();

    // ── New Measurement Period (Clear) Logic ──
    const btnClearAll = document.getElementById('btnGeneralClearMeasurements');
    const clearConfirmModal = document.getElementById('clearConfirmModal');
    const clearConfirmBox = document.getElementById('clearConfirmBox');
    const clearConfirmBackdrop = document.getElementById('clearConfirmBackdrop');
    const btnCancelClear = document.getElementById('btnCancelClear');
    const btnConfirmClear = document.getElementById('btnConfirmClear');

    const clearSuccessModal = document.getElementById('clearSuccessModal');
    const clearSuccessBox = document.getElementById('clearSuccessBox');
    const clearSuccessBackdrop = document.getElementById('clearSuccessBackdrop');
    const btnClearSuccessOk = document.getElementById('btnClearSuccessOk');

    function openClearModal() {
        clearConfirmModal.classList.remove('invisible', 'pointer-events-none');
        clearConfirmModal.classList.add('opacity-100');
        clearConfirmBox.classList.remove('translate-y-4', 'scale-95');
        clearConfirmBox.classList.add('translate-y-0', 'scale-100');
    }

    function closeClearModal() {
        clearConfirmModal.classList.add('opacity-0');
        clearConfirmBox.classList.add('translate-y-4', 'scale-95');
        clearConfirmBox.classList.remove('translate-y-0', 'scale-100');
        setTimeout(() => {
            clearConfirmModal.classList.add('invisible', 'pointer-events-none');
            clearConfirmModal.classList.remove('opacity-100');
        }, 200);
    }

    function openClearSuccessModal(msg) {
        document.getElementById('clearSuccessMessage').textContent = msg;
        clearSuccessModal.classList.remove('invisible', 'pointer-events-none');
        clearSuccessModal.classList.add('opacity-100');
        clearSuccessBox.classList.remove('translate-y-4', 'scale-95');
        clearSuccessBox.classList.add('translate-y-0', 'scale-100');
    }

    if (btnClearAll) {
        btnClearAll.addEventListener('click', openClearModal);
    }

    if (btnCancelClear) btnCancelClear.addEventListener('click', closeClearModal);
    if (clearConfirmBackdrop) clearConfirmBackdrop.addEventListener('click', closeClearModal);

    if (btnConfirmClear) {
        btnConfirmClear.addEventListener('click', () => {
            btnConfirmClear.disabled = true;
            btnConfirmClear.textContent = 'Processing...';

            fetch('clear_all_measurements.php', { method: 'POST' })
                .then(res => res.json())
                .then(json => {
                    if (json.success) {
                        closeClearModal();
                        openClearSuccessModal(json.message);
                        showToast('success', json.message || 'Measurement period started.');
                    } else {
                        showToast('error', json.message || 'Error clearing measurements.');
                        btnConfirmClear.disabled = false;
                        btnConfirmClear.textContent = 'Yes, Clear Details';
                    }
                })
                .catch(err => {
                    console.error(err);
                    showToast('error', 'Network error occurred.');
                    btnConfirmClear.disabled = false;
                    btnConfirmClear.textContent = 'Yes, Clear Details';
                });
        });
    }

    if (btnClearSuccessOk) {
        btnClearSuccessOk.addEventListener('click', () => {
            window.location.reload();
        });
    }

    // ── Archive Logic ──
    const archiveModal = document.getElementById('archiveModal');
    const archiveBox = document.getElementById('archiveBox');
    const archiveBackdrop = document.getElementById('archiveBackdrop');
    const btnArchiveConfirm = document.getElementById('btnArchiveConfirm');
    const btnArchiveCancel = document.getElementById('btnArchiveCancel');
    const archiveReason = document.getElementById('archiveReason');
    const archiveDateInput = document.getElementById('archiveDate');
    const archiveError = document.getElementById('archiveError');
    let archiveChildId = null;

    function openArchiveModal(childId) {
        archiveChildId = childId;
        archiveModal.classList.remove('invisible', 'pointer-events-none');
        archiveModal.classList.add('opacity-100');
        archiveBox.classList.remove('translate-y-4', 'scale-95');
        archiveBox.classList.add('translate-y-0', 'scale-100');
        archiveReason.value = '';
        archiveError.classList.add('hidden');
    }

    function closeArchiveModal() {
        archiveModal.classList.add('opacity-0');
        archiveBox.classList.add('translate-y-4', 'scale-95');
        archiveBox.classList.remove('translate-y-0', 'scale-100');
        setTimeout(() => {
            archiveModal.classList.add('invisible', 'pointer-events-none');
            archiveModal.classList.remove('opacity-100');
            archiveChildId = null;
        }, 200);
    }

    document.querySelectorAll('.btn-open-archive').forEach(btn => {
        btn.addEventListener('click', () => {
            const childId = btn.getAttribute('data-child-id');
            openArchiveModal(childId);
        });
    });

    btnArchiveCancel.addEventListener('click', closeArchiveModal);
    archiveBackdrop.addEventListener('click', closeArchiveModal);

    btnArchiveConfirm.addEventListener('click', () => {
        const reason = archiveReason.value;
        const date = archiveDateInput.value;

        if (!reason) {
            archiveError.classList.remove('hidden');
            return;
        }

        btnArchiveConfirm.disabled = true;
        btnArchiveConfirm.textContent = 'Archiving...';

        const formData = new FormData();
        formData.append('child_id', archiveChildId);
        formData.append('status', reason);
        formData.append('status_date', date);

        fetch('archive_child.php', {
            method: 'POST',
            body: formData
        })
            .then(res => res.json())
            .then(json => {
                if (json.success) {
                    showToast('success', json.message || 'Child profile archived.');
                    closeArchiveModal();
                    const row = document.querySelector(`tr[data-child-id="${archiveChildId}"]`);
                    if (row) row.remove();
                    const rowCount = document.getElementById('rowCount');
                    if (rowCount) {
                        const current = parseInt(rowCount.textContent || '0', 10) || 0;
                        rowCount.textContent = `${Math.max(0, current - 1)} records`;
                    }
                } else {
                    showToast('error', json.message || 'Error archiving child.');
                    btnArchiveConfirm.disabled = false;
                    btnArchiveConfirm.textContent = 'Archive';
                }
            })
            .catch(err => {
                console.error(err);
                showToast('error', 'Network error occurred.');
                btnArchiveConfirm.disabled = false;
                btnArchiveConfirm.textContent = 'Archive';
            });
    });

    // ── Action Menu Toggle ──
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.action-menu-btn');
        if (btn) {
            const menu = btn.nextElementSibling;
            const isOpen = !menu.classList.contains('hidden');

            // Close all others
            document.querySelectorAll('.action-menu').forEach(m => m.classList.add('hidden'));
            document.querySelectorAll('.action-menu-btn').forEach(b => b.setAttribute('aria-expanded', 'false'));

            if (!isOpen) {
                menu.classList.remove('hidden');
                btn.setAttribute('aria-expanded', 'true');
            }
            e.stopPropagation();
        } else {
            document.querySelectorAll('.action-menu').forEach(m => m.classList.add('hidden'));
            document.querySelectorAll('.action-menu-btn').forEach(b => b.setAttribute('aria-expanded', 'false'));
        }
    });

    // Close on ESC
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeUpdateModal();
            closeArchiveModal();
        }
    });
});
