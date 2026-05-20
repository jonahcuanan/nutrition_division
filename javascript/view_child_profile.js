document.addEventListener('DOMContentLoaded', function () {
    const hasRecords = Array.isArray(window.childRecords) && window.childRecords.length > 0;
    const isCompactViewport = window.matchMedia('(max-width: 768px)').matches;
    const chartTickFontSize = isCompactViewport ? 9 : 11;
    const chartTitleFontSize = isCompactViewport ? 10 : 11;

    const records = hasRecords ? window.childRecords.slice().reverse() : [];
    const eccdRefs = window.eccdRefs || {};

    const childSex = String(window.childSex || '').toLowerCase();
    const childLineColor = childSex === 'female' ? '#ec4899' : '#2563eb';

    const wfaChild = records
        .filter(r => r.age_in_months !== null && r.weight !== null)
        .map(r => ({ x: Number(r.age_in_months), y: Number(r.weight), status: r.weight_for_age_status }));

    const hfaChild = records
        .filter(r => r.age_in_months !== null && r.height !== null)
        .map(r => ({ x: Number(r.age_in_months), y: Number(r.height), status: r.height_for_age_status }));

    const wflChild = records
        .filter(r => r.height !== null && r.weight !== null)
        .map(r => ({ x: Number(r.height), y: Number(r.weight), status: r.weight_for_ltht_status }));

    const baseOpts = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: '#0f172a',
                titleColor: '#94a3b8',
                bodyColor: '#f8fafc',
                padding: 10,
                cornerRadius: 8,
                titleFont: { family: 'Arial', size: chartTickFontSize },
                bodyFont: { family: 'Arial', size: chartTickFontSize + 2, weight: '600' }
            }
        },
        scales: {
            x: {
                grid: { color: '#d1d5db', drawBorder: true, lineWidth: 0.6, drawTicks: true },
                border: { color: '#1f2937' },
                ticks: { color: '#111827', font: { size: chartTickFontSize, family: 'Arial' }, autoSkip: false }
            },
            y: {
                grid: { color: '#d1d5db', drawBorder: true },
                border: { color: '#1f2937' },
                ticks: { color: '#111827', font: { size: chartTickFontSize, family: 'Arial' } },
                beginAtZero: false
            }
        }
    };

    const makeEccdLineChart = (
        id,
        datasets,
        xTitle,
        yTitle,
        xMin = null,
        xMax = null,
        xStep = null,
        xTickCallback = null,
        yMin = null,
        yMax = null,
        yStep = null,
        yTickCallback = null,
        yAutoSkip = null
    ) => {
        const ctx = document.getElementById(id);
        if (!ctx) return;
        new Chart(ctx, {
            type: 'line',
            data: {
                datasets
            },
            options: {
                ...baseOpts,
                plugins: {
                    ...baseOpts.plugins,
                    tooltip: {
                        ...baseOpts.plugins.tooltip,
                        callbacks: {
                            label: (ctx) => {
                                const val = typeof ctx.parsed.y === 'number' ? ctx.parsed.y.toFixed(1) : ctx.parsed.y;
                                if (ctx.dataset.label === 'Child' && ctx.raw && ctx.raw.status) {
                                    const unit = yTitle.includes('(kg)') ? ' kg' : (yTitle.includes('(cm)') ? ' cm' : '');
                                    return `${val}${unit} (${ctx.raw.status})`;
                                }
                                return `${ctx.dataset.label}: ${val}`;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        type: 'linear',
                        grid: { color: '#d1d5db', drawBorder: true, lineWidth: 0.6, drawTicks: true },
                        border: { color: '#1f2937' },
                        min: xMin === null ? undefined : xMin,
                        max: xMax === null ? undefined : xMax,
                        ticks: {
                            color: '#111827',
                            font: { size: chartTickFontSize, family: 'Arial' },
                            stepSize: xStep === null ? undefined : xStep,
                            autoSkip: false,
                            callback: xTickCallback === null ? undefined : xTickCallback,
                            maxRotation: 0,
                            minRotation: 0
                        },
                        title: { display: true, text: xTitle, color: '#111827', font: { size: chartTitleFontSize, family: 'Arial', weight: '600' } }
                    },
                    y: {
                        grid: { color: '#d1d5db', drawBorder: true },
                        border: { color: '#1f2937' },
                        min: yMin === null ? undefined : yMin,
                        max: yMax === null ? undefined : yMax,
                        ticks: {
                            color: '#111827',
                            font: { size: chartTickFontSize, family: 'Arial' },
                            stepSize: yStep === null ? undefined : yStep,
                            callback: yTickCallback === null ? undefined : yTickCallback,
                            autoSkip: yAutoSkip === null ? undefined : yAutoSkip
                        },
                        title: { display: true, text: yTitle, color: '#111827', font: { size: chartTitleFontSize, family: 'Arial', weight: '600' } }
                    }
                }
            }
        });
    };

    const wfaRef = Array.isArray(eccdRefs.wfa) ? eccdRefs.wfa : [];
    const hfaRef = Array.isArray(eccdRefs.hfa) ? eccdRefs.hfa : [];
    const wflRef = Array.isArray(eccdRefs.wfl) ? eccdRefs.wfl : [];

    const toPoints = (rows, xKey, yKey, zeroAsNull = false) => rows.map(r => {
        const yVal = Number(r[yKey]);
        return { x: Number(r[xKey]), y: (zeroAsNull && (!yVal || yVal <= 0)) ? null : yVal };
    });

    if (hasRecords && wfaRef.length) {
        makeEccdLineChart('wfaEccdChart', [
            { label: 'Severely Underweight', data: toPoints(wfaRef, 'age_month', 'severely_underweight_max'), borderColor: '#1e3a8a', backgroundColor: 'transparent', borderWidth: 1.6, pointRadius: 0, tension: 0.25 },
            { label: 'Underweight', data: toPoints(wfaRef, 'age_month', 'underweight_max'), borderColor: '#2563eb', backgroundColor: 'transparent', borderWidth: 1.6, pointRadius: 0, tension: 0.25 },
            { label: 'Normal (Upper)', data: toPoints(wfaRef, 'age_month', 'normal_max'), borderColor: '#38bdf8', backgroundColor: 'transparent', borderWidth: 1.6, pointRadius: 0, tension: 0.25 },
            { label: 'Overweight', data: toPoints(wfaRef, 'age_month', 'overweight', true), borderColor: '#0ea5e9', backgroundColor: 'transparent', borderWidth: 1.6, pointRadius: 0, tension: 0.25 },
            { label: 'Child', data: wfaChild, borderColor: childLineColor, backgroundColor: childLineColor, borderWidth: 2.4, pointRadius: 3, pointHoverRadius: 6, tension: 0.15 }
        ], 'Age (months)', 'Weight (kg)', 0, 59, isCompactViewport ? 6 : 1, null, 1, 25, isCompactViewport ? 2 : 1);
    }

    if (hasRecords && hfaRef.length) {
        makeEccdLineChart('hfaEccdChart', [
            { label: 'Severely Stunted', data: toPoints(hfaRef, 'age_month', 'severely_stunted'), borderColor: '#1e3a8a', backgroundColor: 'transparent', borderWidth: 1.6, pointRadius: 0, tension: 0.25 },
            { label: 'Stunted', data: toPoints(hfaRef, 'age_month', 'stunted_to'), borderColor: '#2563eb', backgroundColor: 'transparent', borderWidth: 1.6, pointRadius: 0, tension: 0.25 },
            { label: 'Normal (Upper)', data: toPoints(hfaRef, 'age_month', 'normal_to'), borderColor: '#38bdf8', backgroundColor: 'transparent', borderWidth: 1.6, pointRadius: 0, tension: 0.25 },
            { label: 'Tall', data: toPoints(hfaRef, 'age_month', 'tall'), borderColor: '#0ea5e9', backgroundColor: 'transparent', borderWidth: 1.6, pointRadius: 0, tension: 0.25 },
            { label: 'Child', data: hfaChild, borderColor: childLineColor, backgroundColor: childLineColor, borderWidth: 2.4, pointRadius: 3, pointHoverRadius: 6, tension: 0.15 }
        ], 'Age (months)', 'Height (cm)', 0, 59, isCompactViewport ? 6 : 1, null, 43, 119, isCompactViewport ? 4 : 1);
    }

    if (hasRecords && wflRef.length) {
        const wflTickLabel = (value) => {
            const v = Number(value);
            if (v === 1 || v >= 4) {
                return String(v);
            }
            return '';
        };

        const wflXLabel = (value) => {
            const v = Number(value);
            return v % 5 === 0 ? String(v) : '';
        };

        makeEccdLineChart('wflEccdChart', [
            { label: 'Severely Wasted', data: toPoints(wflRef, 'length_cm', 'severely_wasted'), borderColor: '#7f1d1d', backgroundColor: 'transparent', borderWidth: 1.8, pointRadius: 0, tension: 0.25 },
            { label: 'Wasted', data: toPoints(wflRef, 'length_cm', 'wasted_to'), borderColor: '#b91c1c', backgroundColor: 'transparent', borderWidth: 1.8, pointRadius: 0, tension: 0.25 },
            { label: 'Normal (Upper)', data: toPoints(wflRef, 'length_cm', 'normal_to'), borderColor: '#dc2626', backgroundColor: 'transparent', borderWidth: 1.8, pointRadius: 0, tension: 0.25 },
            { label: 'Overweight', data: toPoints(wflRef, 'length_cm', 'overweight_to'), borderColor: '#ef4444', backgroundColor: 'transparent', borderWidth: 1.8, pointRadius: 0, tension: 0.25 },
            { label: 'Obese', data: toPoints(wflRef, 'length_cm', 'obese'), borderColor: '#f87171', backgroundColor: 'transparent', borderWidth: 1.8, pointRadius: 0, tension: 0.25 },
            { label: 'Child', data: wflChild, borderColor: childLineColor, backgroundColor: childLineColor, borderWidth: 2.4, pointRadius: 3, pointHoverRadius: 6, tension: 0.15 }
        ], 'Length/Height (cm)', 'Weight (kg)', 65, 120, isCompactViewport ? 5 : 1, wflXLabel, 1, 34, isCompactViewport ? 2 : 1, wflTickLabel, false);
    }

    const bindModal = (openId, modalId, closeId, backdropId) => {
        const openBtn = document.getElementById(openId);
        const modal = document.getElementById(modalId);
        const closeBtn = document.getElementById(closeId);
        const backdrop = document.getElementById(backdropId);

        const openModal = () => {
            if (!modal) return;
            modal.classList.add('is-open');
            document.body.classList.add('eccd-modal-open');
            modal.setAttribute('aria-hidden', 'false');
        };

        const closeModal = () => {
            if (!modal) return;
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
            if (!document.querySelector('.eccd-modal.is-open')) {
                document.body.classList.remove('eccd-modal-open');
            }
        };

        if (openBtn) openBtn.addEventListener('click', openModal);
        if (closeBtn) closeBtn.addEventListener('click', closeModal);
        if (backdrop) backdrop.addEventListener('click', closeModal);
    };

    bindModal('openEccdWfa', 'eccdModalWfa', 'closeEccdWfa', 'eccdBackdropWfa');
    bindModal('openEccdHfa', 'eccdModalHfa', 'closeEccdHfa', 'eccdBackdropHfa');
    bindModal('openEccdWfl', 'eccdModalWfl', 'closeEccdWfl', 'eccdBackdropWfl');

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.eccd-modal.is-open').forEach((modal) => {
                modal.classList.remove('is-open');
                modal.setAttribute('aria-hidden', 'true');
            });
            document.body.classList.remove('eccd-modal-open');
        }
    });
});
