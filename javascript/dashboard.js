document.addEventListener("DOMContentLoaded", function() {
    // Shared options
    const fontOpts = { family: "'Plus Jakarta Sans', sans-serif" };
    const commonColors = ['#2ea86a', '#1a6ed8', '#e5a200', '#2aa7a0', '#6b58d6', '#e05252'];

    // 1. Barangay Chart
    const bLabels = window.barangayLabels || [];
    const bData = window.barangayData || [];
    if (bData.length > 0) {
        new Chart(document.getElementById('barangayChart').getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: bLabels,
                datasets: [{
                    data: bData,
                    backgroundColor: commonColors,
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
