document.addEventListener('DOMContentLoaded', function () {
    const showChartToast = (message) => {
        let toast = document.getElementById('chart-click-toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'chart-click-toast';
            toast.style.position = 'fixed';
            toast.style.bottom = '24px';
            toast.style.right = '24px';
            toast.style.backgroundColor = '#0f172a';
            toast.style.color = '#f8fafc';
            toast.style.padding = '12px 20px';
            toast.style.borderRadius = '8px';
            toast.style.boxShadow = '0 10px 15px -3px rgba(0, 0, 0, 0.2), 0 4px 6px -2px rgba(0, 0, 0, 0.1)';
            toast.style.fontFamily = 'Arial, sans-serif';
            toast.style.fontSize = '14px';
            toast.style.fontWeight = '500';
            toast.style.zIndex = '99999';
            toast.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(10px)';
            document.body.appendChild(toast);
        }
        
        toast.textContent = message;
        toast.style.opacity = '1';
        toast.style.transform = 'translateY(0)';
        
        if (window.toastTimeout) {
            clearTimeout(window.toastTimeout);
        }
        window.toastTimeout = setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(10px)';
        }, 4000);
    };

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

    const chartGrid = {
        display: true,
        drawOnChartArea: true,
        color: 'rgba(148, 163, 184, 0.45)',
        lineWidth: 0.85,
        drawBorder: true,
        borderColor: '#1f2937',
        tickLength: 6,
        tickColor: '#1f2937'
    };

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
                grid: { ...chartGrid },
                border: { color: '#1f2937', width: 1.5 },
                ticks: { color: '#111827', font: { size: chartTickFontSize, family: 'Arial' }, autoSkip: false }
            },
            y: {
                grid: { ...chartGrid },
                border: { color: '#1f2937', width: 1.5 },
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
        xGridStep = null,
        xLabelStep = null,
        yMin = null,
        yMax = null,
        yStep = null,
        yTickCallback = null,
        yAutoSkip = null,
        yGridStep = null,
        yLabelStep = null
    ) => {
        const xGridTickStep = xGridStep !== null ? xGridStep : xStep;
        const xLabelTickStep = xLabelStep !== null ? xLabelStep : xStep;
        const xLabelCallback = (xLabelStep !== null && xTickCallback === null)
            ? (value) => {
                const v = Number(value);
                if (!Number.isFinite(v)) return '';
                const step = xLabelTickStep;
                if (step === null || step <= 1) return String(v);
                if (xMin !== null && v === xMin) return String(v);
                if (xMax !== null && v === xMax) return String(v);
                return Math.round(v % step) === 0 ? String(v) : '';
            }
            : xTickCallback;
        const yGridTickStep = yGridStep !== null ? yGridStep : yStep;
        const yLabelTickStep = yLabelStep !== null ? yLabelStep : yStep;
        const yLabelCallback = (yLabelStep !== null && yTickCallback === null)
            ? (value) => {
                const v = Number(value);
                if (!Number.isFinite(v)) return '';
                const step = yLabelTickStep;
                if (step === null || step <= 1) return String(v);
                if (yMin !== null && v === yMin) return String(v);
                if (yMax !== null && v === yMax) return String(v);
                return Math.round(v % step) === 0 ? String(v) : '';
            }
            : yTickCallback;
        const ctx = document.getElementById(id);
        if (!ctx) return;

        const processedDatasets = datasets.map(ds => {
            if (ds.label !== 'Child') {
                return {
                    ...ds,
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    pointHitRadius: 12
                };
            }
            return {
                ...ds,
                pointHitRadius: 12
            };
        });

        new Chart(ctx, {
            type: 'line',
            data: {
                datasets: processedDatasets
            },
            options: {
                ...baseOpts,
                onClick: (event, elements, chart) => {
                    if (elements && elements.length > 0) {
                        const firstEl = elements[0];
                        const datasetIndex = firstEl.datasetIndex;
                        const dataIndex = firstEl.index;
                        const dataset = chart.data.datasets[datasetIndex];
                        const point = dataset.data[dataIndex];
                        
                        const label = dataset.label;
                        const x = point.x;
                        const y = typeof point.y === 'number' ? point.y.toFixed(1) : point.y;
                        
                        const xUnit = xTitle.includes('Age') ? ' months' : ' cm';
                        const yUnit = yTitle.includes('(kg)') ? ' kg' : (yTitle.includes('(cm)') ? ' cm' : '');
                        
                        let message = '';
                        if (label === 'Child') {
                            message = `Child record at ${x}${xUnit}: ${y}${yUnit} (${point.status || 'No status'})`;
                        } else {
                            message = `${label} threshold at ${x}${xUnit}: ${y}${yUnit}`;
                        }
                        
                        showChartToast(message);
                    }
                },
                onHover: (event, elements) => {
                    event.native.target.style.cursor = elements && elements.length > 0 ? 'pointer' : 'default';
                },
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
                                const unit = yTitle.includes('(kg)') ? ' kg' : (yTitle.includes('(cm)') ? ' cm' : '');
                                return `${ctx.dataset.label}: ${val}${unit}`;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        type: 'linear',
                        grid: { ...chartGrid },
                        border: { color: '#1f2937', width: 1.5 },
                        min: xMin === null ? undefined : xMin,
                        max: xMax === null ? undefined : xMax,
                        ticks: {
                            color: '#111827',
                            font: { size: chartTickFontSize, family: 'Arial' },
                            stepSize: xGridTickStep === null ? undefined : xGridTickStep,
                            autoSkip: false,
                            callback: xLabelCallback === null ? undefined : xLabelCallback,
                            maxRotation: 0,
                            minRotation: 0
                        },
                        title: { display: true, text: xTitle, color: '#111827', font: { size: chartTitleFontSize, family: 'Arial', weight: '600' } }
                    },
                    y: {
                        grid: { ...chartGrid },
                        border: { color: '#1f2937', width: 1.5 },
                        min: yMin === null ? undefined : yMin,
                        max: yMax === null ? undefined : yMax,
                        ticks: {
                            color: '#111827',
                            font: { size: chartTickFontSize, family: 'Arial' },
                            stepSize: yGridTickStep === null ? undefined : yGridTickStep,
                            callback: yLabelCallback === null ? undefined : yLabelCallback,
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

    const childAxisValues = (points, key) =>
        points.map(p => Number(p[key])).filter(v => Number.isFinite(v));

    /** Keep standard ECCD chart design; only widen an axis when a child point goes past it. */
    const fitAxisToChild = (defaultMin, defaultMax, childValues) => {
        const vals = childValues.filter(v => Number.isFinite(v));
        if (!vals.length) {
            return { min: defaultMin, max: defaultMax, expanded: false };
        }
        const childMin = Math.min(...vals);
        const childMax = Math.max(...vals);
        const below = childMin < defaultMin;
        const above = childMax > defaultMax;
        if (!below && !above) {
            return { min: defaultMin, max: defaultMax, expanded: false };
        }
        const pad = Math.max((defaultMax - defaultMin) * 0.05, 0.5);
        return {
            min: below ? Math.floor(childMin - pad) : defaultMin,
            max: above ? Math.ceil(childMax + pad) : defaultMax,
            expanded: true
        };
    };

    const expandedAxisStep = (min, max, defaultStep) => {
        const range = max - min;
        const rough = range / (isCompactViewport ? 8 : 12);
        return Math.max(defaultStep, Math.ceil(rough));
    };

    if (hasRecords && wfaRef.length) {
        const wfaXDef = { min: 0, max: 59, step: isCompactViewport ? 6 : 1 };
        const wfaYDef = { min: 1, max: 25, step: isCompactViewport ? 2 : 1 };
        const wfaX = fitAxisToChild(wfaXDef.min, wfaXDef.max, childAxisValues(wfaChild, 'x'));
        const wfaY = fitAxisToChild(wfaYDef.min, wfaYDef.max, childAxisValues(wfaChild, 'y'));
        const wfaYLabelStep = wfaY.expanded
            ? expandedAxisStep(wfaY.min, wfaY.max, wfaYDef.step)
            : wfaYDef.step;

        makeEccdLineChart('wfaEccdChart', [
            { label: 'Severely Underweight', data: toPoints(wfaRef, 'age_month', 'severely_underweight_max'), borderColor: '#1e3a8a', backgroundColor: 'transparent', borderWidth: 1.6, pointRadius: 0, tension: 0.25 },
            { label: 'Underweight', data: toPoints(wfaRef, 'age_month', 'underweight_max'), borderColor: '#2563eb', backgroundColor: 'transparent', borderWidth: 1.6, pointRadius: 0, tension: 0.25 },
            { label: 'Normal (Upper)', data: toPoints(wfaRef, 'age_month', 'normal_max'), borderColor: '#38bdf8', backgroundColor: 'transparent', borderWidth: 1.6, pointRadius: 0, tension: 0.25 },
            { label: 'Overweight', data: toPoints(wfaRef, 'age_month', 'overweight', true), borderColor: '#0ea5e9', backgroundColor: 'transparent', borderWidth: 1.6, pointRadius: 0, tension: 0.25 },
            { label: 'Child', data: wfaChild, borderColor: childLineColor, backgroundColor: childLineColor, borderWidth: 2.4, pointRadius: 3, pointHoverRadius: 6, tension: 0.15 }
        ], 'Age (months)', 'Weight (kg)',
            wfaX.min, wfaX.max, wfaX.expanded ? expandedAxisStep(wfaX.min, wfaX.max, wfaXDef.step) : wfaXDef.step,
            null, null, null,
            wfaY.min, wfaY.max, null, null, null, 1, wfaYLabelStep);
    }

    if (hasRecords && hfaRef.length) {
        const hfaXDef = { min: 0, max: 59, step: isCompactViewport ? 6 : 1 };
        const hfaYDef = { min: 43, max: 119, step: isCompactViewport ? 4 : 1 };
        const hfaX = fitAxisToChild(hfaXDef.min, hfaXDef.max, childAxisValues(hfaChild, 'x'));
        const hfaY = fitAxisToChild(hfaYDef.min, hfaYDef.max, childAxisValues(hfaChild, 'y'));
        const hfaYGridStep = 1;
        const hfaHeightLabelStep = (() => {
            const range = hfaY.max - hfaY.min;
            if (range > 100) return 10;
            if (range > 60 || isCompactViewport) return 5;
            return 2;
        })();

        const hfaYLabelCallback = (value) => {
            const v = Number(value);
            if (!Number.isFinite(v)) return '';
            if (v === hfaY.min || v === hfaY.max) return String(v);
            const start = Math.ceil(hfaY.min / hfaHeightLabelStep) * hfaHeightLabelStep;
            if (v < start || v > hfaY.max) return '';
            return v % hfaHeightLabelStep === 0 ? String(v) : '';
        };

        makeEccdLineChart('hfaEccdChart', [
            { label: 'Severely Stunted', data: toPoints(hfaRef, 'age_month', 'severely_stunted'), borderColor: '#1e3a8a', backgroundColor: 'transparent', borderWidth: 1.6, pointRadius: 0, tension: 0.25 },
            { label: 'Stunted', data: toPoints(hfaRef, 'age_month', 'stunted_to'), borderColor: '#2563eb', backgroundColor: 'transparent', borderWidth: 1.6, pointRadius: 0, tension: 0.25 },
            { label: 'Normal (Upper)', data: toPoints(hfaRef, 'age_month', 'normal_to'), borderColor: '#38bdf8', backgroundColor: 'transparent', borderWidth: 1.6, pointRadius: 0, tension: 0.25 },
            { label: 'Tall', data: toPoints(hfaRef, 'age_month', 'tall'), borderColor: '#0ea5e9', backgroundColor: 'transparent', borderWidth: 1.6, pointRadius: 0, tension: 0.25 },
            { label: 'Child', data: hfaChild, borderColor: childLineColor, backgroundColor: childLineColor, borderWidth: 2.4, pointRadius: 3, pointHoverRadius: 6, tension: 0.15 }
        ], 'Age (months)', 'Height (cm)',
            hfaX.min, hfaX.max, hfaX.expanded ? expandedAxisStep(hfaX.min, hfaX.max, hfaXDef.step) : hfaXDef.step,
            null, null, null,
            hfaY.min, hfaY.max, null, hfaYLabelCallback, false, hfaYGridStep, null);
    }

    if (hasRecords && wflRef.length) {
        const wflXDef = { min: 65, max: 120, step: isCompactViewport ? 5 : 1 };
        const wflYDef = { min: 1, max: 34, step: isCompactViewport ? 2 : 1 };
        const wflX = fitAxisToChild(wflXDef.min, wflXDef.max, childAxisValues(wflChild, 'x'));
        const wflY = fitAxisToChild(wflYDef.min, wflYDef.max, childAxisValues(wflChild, 'y'));

        const wflHeightLabelStep = 5;
        const wflXGridStep = 1;
        const wflYGridStep = 1;

        const wflTickLabel = (value) => {
            const v = Number(value);
            if (!wflY.expanded) {
                if (v === 1 || v >= 4) return String(v);
                return '';
            }
            if (v === wflY.min || v === wflY.max) return String(v);
            if (v === 1) return '1';
            const step = wflY.expanded ? expandedAxisStep(wflY.min, wflY.max, wflYDef.step) : wflYDef.step;
            return v % Math.max(step, 2) === 0 ? String(v) : '';
        };

        const wflXLabel = (value) => {
            const v = Number(value);
            if (!Number.isFinite(v)) return '';
            const start = Math.ceil(wflX.min / wflHeightLabelStep) * wflHeightLabelStep;
            if (v < start || v > wflX.max) return '';
            return v % wflHeightLabelStep === 0 ? String(v) : '';
        };

        makeEccdLineChart('wflEccdChart', [
            { label: 'Severely Wasted', data: toPoints(wflRef, 'length_cm', 'severely_wasted'), borderColor: '#7f1d1d', backgroundColor: 'transparent', borderWidth: 1.8, pointRadius: 0, tension: 0.25 },
            { label: 'Wasted', data: toPoints(wflRef, 'length_cm', 'wasted_to'), borderColor: '#b91c1c', backgroundColor: 'transparent', borderWidth: 1.8, pointRadius: 0, tension: 0.25 },
            { label: 'Normal (Upper)', data: toPoints(wflRef, 'length_cm', 'normal_to'), borderColor: '#dc2626', backgroundColor: 'transparent', borderWidth: 1.8, pointRadius: 0, tension: 0.25 },
            { label: 'Overweight', data: toPoints(wflRef, 'length_cm', 'overweight_to'), borderColor: '#ef4444', backgroundColor: 'transparent', borderWidth: 1.8, pointRadius: 0, tension: 0.25 },
            { label: 'Obese', data: toPoints(wflRef, 'length_cm', 'obese'), borderColor: '#f87171', backgroundColor: 'transparent', borderWidth: 1.8, pointRadius: 0, tension: 0.25 },
            { label: 'Child', data: wflChild, borderColor: childLineColor, backgroundColor: childLineColor, borderWidth: 2.4, pointRadius: 3, pointHoverRadius: 6, tension: 0.15 }
        ], 'Length/Height (cm)', 'Weight (kg)',
            wflX.min, wflX.max, null, wflXLabel, wflXGridStep, wflHeightLabelStep,
            wflY.min, wflY.max, null, wflTickLabel, false, wflYGridStep, null);
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
