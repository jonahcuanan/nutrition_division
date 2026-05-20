document.addEventListener("DOMContentLoaded", function () {
    const welcomeNameEl = document.getElementById('welcomeName');
    function updateWelcomeName(name) {
        if (!welcomeNameEl) return;
        const cleaned = (name || '').trim();
        if (cleaned !== '') welcomeNameEl.textContent = cleaned;
    }

    const storedName = localStorage.getItem('display_name');
    if (storedName) updateWelcomeName(storedName);

    window.addEventListener('storage', (event) => {
        if (event.key === 'display_name') {
            updateWelcomeName(event.newValue || '');
        }
    });

    // Shared options
    const fontOpts = { family: "'Plus Jakarta Sans', sans-serif" };
    const commonColors = ['#2ea86a', '#1a6ed8', '#e5a200', '#2aa7a0', '#6b58d6', '#e05252'];
    const barangayColors = [
        '#1f77b4', '#ff7f0e', '#2ca02c', '#d62728',
        '#9467bd', '#8c564b', '#e377c2', '#7f7f7f',
        '#bcbd22', '#17becf', '#4e79a7', '#f28e2b',
        '#59a14f', '#e15759', '#9c755f', '#bab0ab',
        '#edc948', '#b07aa1', '#76b7b2', '#ff9da7',
        '#9c9ede', '#98df8a', '#c5b0d5', '#c49c94'
    ];

    // 1. Barangay Chart
    const bLabels = window.barangayLabels || [];
    const bData = window.barangayData || [];
    if (bData.length > 0) {
        const bColors = bLabels.map((_, idx) => barangayColors[idx % barangayColors.length]);
        new Chart(document.getElementById('barangayChart').getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: bLabels,
                datasets: [{
                    data: bData,
                    backgroundColor: bColors,
                    borderWidth: 0, hoverOffset: 4
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { font: fontOpts, usePointStyle: true } } },
                cutout: '65%'
            }
        });
    }

    // 2. Demographics per Barangay Chart
    const gbLabels = window.genderBarangayLabels || [];
    const gbMale = window.genderBarangayMale || [];
    const gbFemale = window.genderBarangayFemale || [];

    if (gbLabels.length > 0) {
        new Chart(document.getElementById('genderBarangayChart').getContext('2d'), {
            type: 'bar',
            data: {
                labels: gbLabels,
                datasets: [
                    {
                        label: 'Male',
                        data: gbMale,
                        backgroundColor: '#1a6ed8',
                        borderRadius: 4
                    },
                    {
                        label: 'Female',
                        data: gbFemale,
                        backgroundColor: '#e84393',
                        borderRadius: 4
                    }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top', labels: { font: fontOpts, usePointStyle: true } }
                },
                scales: {
                    x: { stacked: true, ticks: { font: fontOpts } },
                    y: { stacked: true, beginAtZero: true, ticks: { font: fontOpts, stepSize: 1 } }
                }
            }
        });
    }

    // 3. WFA Chart per Barangay
    const wfaData = window.wfaByBarangay || {};
    const wfaLabels = Object.keys(wfaData);
    const wfaEl = document.getElementById('wfaChart');
    if (wfaEl && wfaLabels.length > 0) {
        new Chart(wfaEl.getContext('2d'), {
            type: 'bar',
            data: {
                labels: wfaLabels,
                datasets: [
                    {
                        label: 'Normal',
                        data: wfaLabels.map(b => wfaData[b]['Normal'] || 0),
                        backgroundColor: '#00ff00',
                        borderRadius: 4
                    },
                    {
                        label: 'Overweight',
                        data: wfaLabels.map(b => wfaData[b]['Overweight'] || 0),
                        backgroundColor: '#ffc000',
                        borderRadius: 4
                    },
                    {
                        label: 'Underweight',
                        data: wfaLabels.map(b => wfaData[b]['Underweight'] || 0),
                        backgroundColor: '#ffff00',
                        borderRadius: 4
                    },
                    {
                        label: 'Severely Underweight',
                        data: wfaLabels.map(b => wfaData[b]['Severely Underweight'] || 0),
                        backgroundColor: '#ff0000',
                        borderRadius: 4
                    }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { 
                    legend: { position: 'bottom', labels: { font: fontOpts, usePointStyle: true } },
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    }
                },
                scales: {
                    x: { stacked: true, ticks: { font: fontOpts } },
                    y: { stacked: true, beginAtZero: true, ticks: { font: fontOpts, stepSize: 1 } }
                }
            }
        });
    }

    // 4. HFA Chart per Barangay
    const hfaData = window.hfaByBarangay || {};
    const hfaLabels = Object.keys(hfaData);
    const hfaEl = document.getElementById('hfaChart');
    if (hfaEl && hfaLabels.length > 0) {
        new Chart(hfaEl.getContext('2d'), {
            type: 'bar',
            data: {
                labels: hfaLabels,
                datasets: [
                    {
                        label: 'Normal',
                        data: hfaLabels.map(b => hfaData[b]['Normal'] || 0),
                        backgroundColor: '#00ff00',
                        borderRadius: 4
                    },
                    {
                        label: 'Tall',
                        data: hfaLabels.map(b => hfaData[b]['Tall'] || 0),
                        backgroundColor: '#00ff00',
                        borderRadius: 4
                    },
                    {
                        label: 'Stunted',
                        data: hfaLabels.map(b => hfaData[b]['Stunted'] || 0),
                        backgroundColor: '#ffff00',
                        borderRadius: 4
                    },
                    {
                        label: 'Severely Stunted',
                        data: hfaLabels.map(b => hfaData[b]['Severely Stunted'] || 0),
                        backgroundColor: '#ff0000',
                        borderRadius: 4
                    }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { 
                    legend: { position: 'bottom', labels: { font: fontOpts, usePointStyle: true } },
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    }
                },
                scales: {
                    x: { stacked: true, ticks: { font: fontOpts } },
                    y: { stacked: true, beginAtZero: true, ticks: { font: fontOpts, stepSize: 1 } }
                }
            }
        });
    }

    // 5. WFL/H Chart per Barangay
    const wflhData = window.wflhByBarangay || {};
    const wflhLabels = Object.keys(wflhData);
    const wflhEl = document.getElementById('wflhChart');
    if (wflhEl && wflhLabels.length > 0) {
        new Chart(wflhEl.getContext('2d'), {
            type: 'bar',
            data: {
                labels: wflhLabels,
                datasets: [
                    {
                        label: 'Normal',
                        data: wflhLabels.map(b => wflhData[b]['Normal'] || 0),
                        backgroundColor: '#00ff00',
                        borderRadius: 4
                    },
                    {
                        label: 'Overweight',
                        data: wflhLabels.map(b => wflhData[b]['Overweight'] || 0),
                        backgroundColor: '#ffc000',
                        borderRadius: 4
                    },
                    {
                        label: 'Obese',
                        data: wflhLabels.map(b => wflhData[b]['Obese'] || 0),
                        backgroundColor: '#ffc000',
                        borderRadius: 4
                    },
                    {
                        label: 'Wasted',
                        data: wflhLabels.map(b => wflhData[b]['Wasted'] || 0),
                        backgroundColor: '#ffff00',
                        borderRadius: 4
                    },
                    {
                        label: 'Severely Wasted',
                        data: wflhLabels.map(b => wflhData[b]['Severely Wasted'] || 0),
                        backgroundColor: '#ff0000',
                        borderRadius: 4
                    }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { 
                    legend: { position: 'bottom', labels: { font: fontOpts, usePointStyle: true } },
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    }
                },
                scales: {
                    x: { stacked: true, ticks: { font: fontOpts } },
                    y: { stacked: true, beginAtZero: true, ticks: { font: fontOpts, stepSize: 1 } }
                }
            }
        });
    }

    // 6. MUAC Chart per Barangay
    const muacData = window.muacByBarangay || {};
    const muacLabels = Object.keys(muacData);
    const muacEl = document.getElementById('muacChart');
    if (muacEl && muacLabels.length > 0) {
        new Chart(muacEl.getContext('2d'), {
            type: 'bar',
            data: {
                labels: muacLabels,
                datasets: [
                    {
                        label: 'Normal',
                        data: muacLabels.map(b => muacData[b]['Normal'] || 0),
                        backgroundColor: '#00ff00',
                        borderRadius: 4
                    },
                    {
                        label: 'Moderately Wasted (MAM)',
                        data: muacLabels.map(b => muacData[b]['Moderately Wasted'] || 0),
                        backgroundColor: '#ffff00',
                        borderRadius: 4
                    },
                    {
                        label: 'Severely Wasted (SAM)',
                        data: muacLabels.map(b => muacData[b]['Severely Wasted'] || 0),
                        backgroundColor: '#ff0000',
                        borderRadius: 4
                    }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { 
                    legend: { position: 'bottom', labels: { font: fontOpts, usePointStyle: true } },
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    }
                },
                scales: {
                    x: { stacked: true, ticks: { font: fontOpts } },
                    y: { stacked: true, beginAtZero: true, ticks: { font: fontOpts, stepSize: 1 } }
                }
            }
        });
    }

    // Live Philippine Clock
    function updateClock() {
        const clockEl = document.getElementById('philippineClock');
        if (clockEl) {
            const options = { timeZone: 'Asia/Manila', hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true };
            clockEl.textContent = new Intl.DateTimeFormat('en-US', options).format(new Date());
        }
    }
    setInterval(updateClock, 1000);
    updateClock();
});
