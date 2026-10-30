import Chart from 'chart.js/auto';

const instances = new Map();
const tooltipElements = new WeakMap();

export function auditTooltip({ chart, tooltip }) {
    let element = tooltipElements.get(chart);
    if (!tooltip.opacity) {
        element?.classList.remove('visible');
        return;
    }
    const documentRef = chart.canvas.ownerDocument;
    if (!element) {
        element = documentRef.createElement('div');
        element.className = 'chart-floating-tooltip external-audit-tooltip';
        element.setAttribute('role', 'tooltip');
        documentRef.body.appendChild(element);
        tooltipElements.set(chart, element);
    }
    const title = documentRef.createElement('div');
    title.className = 'chart-floating-tooltip-title';
    title.textContent = (tooltip.title || []).join(' ');
    const lines = (tooltip.body || []).map((body, index) => {
        const row = documentRef.createElement('div');
        row.className = 'chart-floating-tooltip-body';
        const marker = documentRef.createElement('span');
        const color = tooltip.labelColors?.[index]?.backgroundColor;
        marker.style.setProperty('--tooltip-dot', typeof color === 'string' ? color : '#25af91');
        row.append(marker, documentRef.createTextNode(body.lines.join(' ')));
        return row;
    });
    element.replaceChildren(title, ...lines);
    const rect = chart.canvas.getBoundingClientRect();
    const view = documentRef.defaultView;
    const left = rect.left + tooltip.caretX - element.offsetWidth / 2;
    const above = rect.top + tooltip.caretY - element.offsetHeight - 14;
    const top = above >= 12 ? above : rect.top + tooltip.caretY + 14;
    element.style.left = `${Math.max(12, Math.min(left, view.innerWidth - element.offsetWidth - 12))}px`;
    element.style.top = `${Math.max(12, Math.min(top, view.innerHeight - element.offsetHeight - 12))}px`;
    element.classList.add('visible');
}

const tooltipLifecycle = {
    id: 'externalAuditTooltip',
    afterDestroy(chart) {
        tooltipElements.get(chart)?.remove();
        tooltipElements.delete(chart);
    },
};
const centerTotal = {
    id: 'externalAuditTotal',
    afterDatasetsDraw(chart) {
        if (chart.config.type !== 'doughnut' || !chart.chartArea) return;
        const { ctx, chartArea } = chart;
        const total = chart.data.datasets[0].data.reduce((sum, value) => sum + value, 0);
        const x = (chartArea.left + chartArea.right) / 2;
        const y = (chartArea.top + chartArea.bottom) / 2;
        ctx.save();
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = chart.options.plugins.externalAuditTotal.color;
        ctx.font = '600 22px "Instrument Sans", "Segoe UI", sans-serif';
        ctx.fillText(total.toLocaleString('es-GT'), x, y - 8);
        ctx.font = '400 10px "Instrument Sans", "Segoe UI", sans-serif';
        ctx.fillText('asignados', x, y + 14);
        ctx.restore();
    },
};

export function auditChartConfig(data, kind, dark = false) {
    const ink = dark ? '#e6f2f3' : '#243841';
    const muted = dark ? '#a1b8c1' : '#657a85';
    const grid = dark ? '#294149' : '#e3ecef';
    const states = data.states;
    const shared = {
        responsive: true, maintainAspectRatio: false, animation: false,
        font: { family: '"Instrument Sans", "Segoe UI", sans-serif' },
        plugins: {
            legend: { display: false },
            tooltip: {
                enabled: false, external: auditTooltip, mode: 'nearest', intersect: true,
                callbacks: {
                    title: (items) => kind === 'states' ? items[0]?.label || '' : items[0]?.chart.data.labels[items[0].dataIndex] || '',
                    label: (item) => {
                        const count = Number(kind === 'states' ? item.parsed : item.parsed.x);
                        const quantity = `${count.toLocaleString('es-GT')} ${count === 1 ? 'contenedor' : 'contenedores'}`;
                        return kind === 'states' ? quantity : `${item.dataset.label}: ${quantity}`;
                    },
                },
            },
            externalAuditTotal: { color: ink },
        },
    };
    if (kind === 'states') return {
        type: 'doughnut', plugins: [centerTotal, tooltipLifecycle],
        data: { labels: states.map((state) => state.label), datasets: [{ data: states.map((state) => data.totals[state.key]), backgroundColor: states.map((state) => state.color), borderWidth: 0, hoverOffset: 5, spacing: 3 }] },
        options: { ...shared, cutout: '76%', layout: { padding: 8 } },
    };
    return {
        type: 'bar', plugins: [tooltipLifecycle],
        data: {
            labels: data.branches.map((branch) => branch.name),
            datasets: states.map((state) => ({ label: state.label, data: data.branches.map((branch) => branch[state.key]), backgroundColor: state.color, borderWidth: 0, maxBarThickness: 26 })),
        },
        options: { ...shared, indexAxis: 'y',
            scales: {
                x: { stacked: true, beginAtZero: true, min: 0, suggestedMax: 1, border: { display: false }, grid: { color: grid }, ticks: { color: muted, precision: 0 }, title: { display: true, text: 'Contenedores', color: muted } },
                y: { stacked: true, border: { display: false }, grid: { display: false }, ticks: { color: ink, autoSkip: false,
                    callback(value) { const name = this.getLabelForValue(value); return name.length > 26 ? name.slice(0, 23) + '...' : name; },
                } },
            },
        },
    };
}

export function initializeAuditCharts(documentRef = document, ChartClass = Chart, registry = instances) {
    for (const [canvas, state] of registry) {
        if (!canvas.isConnected) { state.chart.destroy(); registry.delete(canvas); }
    }
    const dark = documentRef.documentElement.getAttribute('data-theme') === 'dark';
    documentRef.querySelectorAll('[data-audit-overview]').forEach((root) => {
        const serialized = root.querySelector('[data-audit-chart-data]')?.textContent;
        if (!serialized) return;
        let data;
        try { data = JSON.parse(serialized); } catch { return; }
        if (!Array.isArray(data.states) || !Array.isArray(data.branches) || !data.totals) return;
        root.querySelectorAll('[data-audit-chart]').forEach((canvas) => {
            const signature = `${dark}:${canvas.dataset.auditChart}:${serialized}`;
            const previous = registry.get(canvas);
            if (previous?.signature === signature) return;
            if (canvas.dataset.auditChart === 'branches') canvas.parentElement.style.height = `${Math.max(260, data.branches.length * 44)}px`;
            const config = auditChartConfig(data, canvas.dataset.auditChart, dark);
            if (previous) {
                previous.chart.data = config.data;
                previous.chart.options = config.options;
                previous.chart.update('none');
                previous.signature = signature;
            } else registry.set(canvas, { signature, chart: new ChartClass(canvas, config) });
        });
    });
}

if (typeof document !== 'undefined') {
    let scheduled = false;
    const schedule = () => {
        if (scheduled) return;
        scheduled = true;
        requestAnimationFrame(() => { scheduled = false; initializeAuditCharts(); });
    };
    document.addEventListener('DOMContentLoaded', schedule);
    document.addEventListener('livewire:navigated', schedule);
    document.addEventListener('livewire:init', () => window.Livewire?.hook?.('morph.updated', schedule));
    new MutationObserver(schedule).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
}
