import Chart from 'chart.js/auto';
import { CircleHelp, createElement } from 'lucide';
import { initializeRecordSelects } from './branch-geography';
import './iso-code-select';
import './container-assignment';
import './transport-plate-input';
import './maintenance';
import '../css/maintenance.css';
import '../css/notifications.css';
import '../css/external-audits.css';
import './external-audit-charts';
import './reports';
import './container-history';
import './notifications';
import './unsaved-changes';
import './technical-form-validation';
import './documentary-validation';
import './interaction-gate';
import './parking';
import './operational-catalogs';
import './sidebar-groups';
import './administration';
import './branch-modals';
import './user-modals';
import './account-password';
import './account-recovery-dialog';
import './account-flash';
import './login-background';
import './account-review-modal';
import './unit-types';
import './shipping-lines';
import './container-statuses';
import './yard-layout';
import * as THREE from 'three';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import { addContainerDetails, corrugatedTexture, createContainerDetailsGeometry, createContainerGeometry } from './container-model';

document.addEventListener('click', (event) => {
    if (event.target instanceof Element && event.target.closest('.form-select-menu button, .form-select-trigger') && event.target.closest('label')) {
        event.preventDefault();
    }
}, true);

document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element) || event.target.closest('input, select, textarea, button, a')) {
        return;
    }
    const control = event.target.closest('label')?.control;
    if (!control || ['checkbox', 'radio', 'file'].includes(control.type)) {
        return;
    }
    if (control.matches('select.enhanced-native-select') || matchMedia('(pointer: coarse)').matches) {
        event.preventDefault();
    }
}, true);

document.addEventListener('focusin', (event) => {
    if (event.target instanceof Element && event.target.matches('select.enhanced-native-select')) {
        event.target.blur();
    }
});

const dashboardCharts = new WeakMap();
const yardScenes = new WeakMap();
const ingressLocationScenes = new WeakMap();

document.addEventListener('DOMContentLoaded', () => {
    if (document.querySelector('[data-dispatch-location]')) initializeRecordSelects();
    document.querySelectorAll('.container-form').forEach((form) => {
        [['estado_unidad', 'observaciones_estado'], ['status_box', 'observaciones_box']].forEach(([statusName, notesName]) => {
            const status = form.elements.namedItem(statusName);
            const notes = form.elements.namedItem(notesName);
            if (!status || !notes) return;
            const message = statusName === 'estado_unidad'
                ? 'Describe la falla y su ubicacion en la unidad danada.'
                : 'Describe el dano y su ubicacion en la estructura.';
            const indicator = document.createElement('small');
            indicator.className = 'status-note-requirement';
            notes.closest('label')?.querySelector('span')?.append(indicator);
            const sync = () => {
                notes.required = (status.selectedOptions?.[0] || status).dataset.requiresObservations === 'true';
                indicator.textContent = notes.required ? '(obligatorio)' : '';
                notes.setCustomValidity(notes.required && !notes.value.trim() ? message : '');
            };
            status.addEventListener('change', sync);
            notes.addEventListener('input', sync);
            sync();
        });
    });
    if (document.querySelector('[data-external-audit-form]')) {
        initializeRecordSelects();
        document.querySelectorAll('[data-external-audit-form]').forEach((form) => {
            const notes = form.querySelector('[data-audit-notes]');
            const count = form.querySelector('[data-audit-count]');
            if (notes && count) notes.addEventListener('input', () => { count.textContent = String(Array.from(notes.value).length); });
            const photos = form.querySelector('.external-audit-assessment [data-correction-photo-group]');
            if (notes && photos) {
                const desktop = matchMedia('(min-width: 961px)');
                const alignNotes = () => {
                    const carousel = photos.querySelector('[data-photo-carousel]');
                    if (!desktop.matches || carousel.hidden || !carousel.querySelector('figure')) {
                        notes.style.removeProperty('height');
                        return;
                    }
                    const navigation = carousel.querySelector('[data-photo-navigation]');
                    const navigationHeight = navigation.hidden ? 0 : navigation.getBoundingClientRect().height + parseFloat(getComputedStyle(carousel).rowGap);
                    const height = photos.getBoundingClientRect().bottom - notes.getBoundingClientRect().top - navigationHeight;
                    notes.style.height = `${Math.max(220, Math.round(height))}px`;
                };
                new ResizeObserver(alignNotes).observe(photos);
                desktop.addEventListener('change', alignNotes);
            }
        });
    }
    document.querySelectorAll('[data-field-help]').forEach((button) => {
        button.replaceChildren(createElement(CircleHelp, { 'aria-hidden': 'true' }));
        const tooltip = document.getElementById(button.getAttribute('aria-describedby'));
        if (!tooltip) return;
        const touchHelp = button.hasAttribute('data-help-touch');
        function showHelp(visible) {
            tooltip.hidden = !visible;
            button.setAttribute('aria-expanded', String(visible));
            if (visible && touchHelp) {
                const bounds = button.getBoundingClientRect();
                tooltip.style.position = 'fixed';
                tooltip.style.bottom = 'auto';
                tooltip.style.left = Math.max(12, Math.min(bounds.left - 110, innerWidth - tooltip.offsetWidth - 12)) + 'px';
                tooltip.style.top = (bounds.top - tooltip.offsetHeight - 10 >= 12 ? bounds.top - tooltip.offsetHeight - 10 : bounds.bottom + 10) + 'px';
            }
        }
        button.addEventListener('pointerenter', (event) => { if (!touchHelp || event.pointerType !== 'touch') showHelp(true); });
        button.addEventListener('pointerleave', (event) => { if (!touchHelp || event.pointerType !== 'touch') showHelp(false); });
        button.addEventListener('focus', () => showHelp(button.matches(':focus-visible')));
        button.addEventListener('blur', () => showHelp(false));
        button.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') showHelp(false);
        });
        if (touchHelp) {
            button.addEventListener('click', (event) => {
                if (event.pointerType === 'touch' || matchMedia('(hover: none)').matches) showHelp(tooltip.hidden);
            });
            document.addEventListener('pointerdown', (event) => { if (!button.contains(event.target)) showHelp(false); });
            window.addEventListener('resize', () => showHelp(false));
            document.addEventListener('scroll', () => showHelp(false), true);
        }
    });
});

function parseChartData(canvas, key) {
    try {
        return JSON.parse(canvas.dataset[key] || '[]');
    } catch {
        return [];
    }
}

function parseChartNumber(canvas, key) {
    const value = Number(canvas.dataset[key] || 0);

    return Number.isFinite(value) ? value : 0;
}

function dashboardTheme() {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';

    return {
        barStart: '#34c6bc',
        barEnd: '#00897b',
        grid: isDark ? 'rgba(159, 178, 189, 0.16)' : 'rgba(113, 128, 148, 0.18)',
        label: isDark ? '#edf7f6' : '#223044',
        muted: isDark ? '#9fb2bd' : '#7a899c',
        tooltipBg: isDark ? '#101c22' : '#ffffff',
        tooltipText: isDark ? '#edf7f6' : '#223044',
        surface: isDark ? '#21353d' : '#edf5f8',
    };
}

const centerTextPlugin = {
    id: 'dashboardCenterText',
    afterDraw(chart, args, options) {
        if (!options?.text) {
            return;
        }

        const { ctx, chartArea } = chart;
        const centerX = (chartArea.left + chartArea.right) / 2;
        const centerY = (chartArea.top + chartArea.bottom) / 2;

        ctx.save();
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = options.color;
        ctx.font = `850 ${options.size || 22}px "Instrument Sans", "Segoe UI", Arial, sans-serif`;
        ctx.fillText(options.text, centerX, centerY - (options.subtext ? 8 : 0));

        if (options.subtext) {
            ctx.fillStyle = options.mutedColor;
            ctx.font = `700 ${options.subSize || 11}px "Instrument Sans", "Segoe UI", Arial, sans-serif`;
            ctx.fillText(options.subtext, centerX, centerY + 14);
        }

        ctx.restore();
    },
};

function gradientForChart(ctx, chartArea, theme) {
    const gradient = ctx.createLinearGradient(chartArea.left, 0, chartArea.right, 0);
    gradient.addColorStop(0, theme.barStart);
    gradient.addColorStop(1, theme.barEnd);

    return gradient;
}

function getChartTooltip() {
    let tooltip = document.querySelector('.chart-floating-tooltip');

    if (!tooltip) {
        tooltip = document.createElement('div');
        tooltip.className = 'chart-floating-tooltip';
        tooltip.innerHTML = '<div class="chart-floating-tooltip-title"></div><div class="chart-floating-tooltip-body"></div>';
        document.body.appendChild(tooltip);
    }

    return tooltip;
}

function externalTooltipHandler(context) {
    const { chart, tooltip } = context;
    const tooltipEl = getChartTooltip();

    if (tooltip.opacity === 0) {
        tooltipEl.classList.remove('visible');
        return;
    }

    const titleEl = tooltipEl.querySelector('.chart-floating-tooltip-title');
    const bodyEl = tooltipEl.querySelector('.chart-floating-tooltip-body');
    const title = tooltip.title?.join(' ') || '';
    const body = tooltip.body?.flatMap((item) => item.lines).join(' ') || '';
    const rawColor = tooltip.labelColors?.[0]?.backgroundColor;
    const color = typeof rawColor === 'string' ? rawColor : '#00897b';
    const rect = chart.canvas.getBoundingClientRect();
    const left = rect.left + tooltip.caretX;
    const top = rect.top + tooltip.caretY;

    titleEl.textContent = title;
    bodyEl.replaceChildren();

    const marker = document.createElement('span');
    marker.style.setProperty('--tooltip-dot', color);
    bodyEl.append(marker, document.createTextNode(body));

    tooltipEl.style.left = `${left}px`;
    tooltipEl.style.top = `${top}px`;
    tooltipEl.classList.add('visible');
}

function chartTooltipOptions(theme) {
    return {
        enabled: false,
        external: externalTooltipHandler,
        backgroundColor: theme.tooltipBg,
        bodyColor: theme.tooltipText,
        borderColor: theme.grid,
        borderWidth: 1,
        displayColors: true,
        padding: 10,
        titleColor: theme.tooltipText,
    };
}

function doughnutOptions(theme, centerText, centerSubtext) {
    return {
        animation: {
            duration: 650,
            easing: 'easeOutQuart',
        },
        cutout: '68%',
        maintainAspectRatio: false,
        plugins: {
            dashboardCenterText: {
                color: theme.label,
                mutedColor: theme.muted,
                subtext: centerSubtext,
                text: centerText,
            },
            legend: {
                display: false,
            },
            tooltip: chartTooltipOptions(theme),
        },
        responsive: true,
    };
}

const yardChartNarrowWidth = 520;

function wrapChartLabel(label, maxLength) {
    return label.split(/\s+/).reduce((lines, word) => {
        const last = lines[lines.length - 1];
        if (last && `${last} ${word}`.length <= maxLength) {
            lines[lines.length - 1] = `${last} ${word}`;
        } else {
            lines.push(word);
        }

        return lines;
    }, []);
}

function initYardChart(canvas) {
    const labels = parseChartData(canvas, 'labels');
    const values = parseChartData(canvas, 'values');
    const occupied = parseChartData(canvas, 'occupied');
    const capacity = parseChartData(canvas, 'capacity');

    if (!labels.length) {
        return;
    }

    dashboardCharts.get(canvas)?.destroy();

    const body = canvas.closest('.yard-chart-canvas-body');
    if (body) {
        const rowHeight = 48;
        body.style.height = `${Math.max(260, labels.length * rowHeight)}px`;
    }

    const theme = dashboardTheme();

    const chart = new Chart(canvas, {
        type: 'bar',
        data: {
            labels,
            datasets: [
                {
                    label: 'Ocupación',
                    data: values,
                    borderRadius: 0,
                    barPercentage: 0.68,
                    categoryPercentage: 0.72,
                    backgroundColor(context) {
                        const { chart: activeChart } = context;
                        const { ctx, chartArea } = activeChart;

                        if (!chartArea) {
                            return theme.barEnd;
                        }

                        return gradientForChart(ctx, chartArea, theme);
                    },
                    hoverBackgroundColor: theme.barEnd,
                },
            ],
        },
        options: {
            animation: {
                duration: 650,
                easing: 'easeOutQuart',
            },
            indexAxis: 'y',
            maintainAspectRatio: false,
            responsive: true,
            layout: {
                padding: {
                    bottom: 4,
                    right: 8,
                    top: 4,
                },
            },
            plugins: {
                legend: {
                    display: false,
                },
                tooltip: {
                    ...chartTooltipOptions(theme),
                    callbacks: {
                        label(context) {
                            const index = context.dataIndex;
                            return `${occupied[index] ?? 0}/${capacity[index] ?? 0} espacios (${context.parsed.x}%)`;
                        },
                    },
                    displayColors: false,
                },
            },
            scales: {
                x: {
                    beginAtZero: true,
                    max: 100,
                    border: {
                        display: false,
                    },
                    grid: {
                        color: theme.grid,
                        drawTicks: false,
                    },
                    ticks: {
                        callback: (value) => `${value}%`,
                        maxRotation: 0,
                        color: theme.muted,
                        font: {
                            family: 'Instrument Sans, Segoe UI, Arial, sans-serif',
                            size: 12,
                            weight: 700,
                        },
                        padding: 8,
                        stepSize: 25,
                    },
                },
                y: {
                    border: {
                        display: false,
                    },
                    grid: {
                        display: false,
                    },
                    ticks: {
                        callback(value) {
                            const label = String(this.getLabelForValue(value));

                            return this.chart.width < yardChartNarrowWidth ? wrapChartLabel(label, 15) : label;
                        },
                        color: theme.label,
                        font: (context) => ({
                            family: 'Instrument Sans, Segoe UI, Arial, sans-serif',
                            size: context.chart.width < yardChartNarrowWidth ? 11 : 13,
                            weight: 800,
                        }),
                        padding: 10,
                    },
                },
            },
        },
    });

    dashboardCharts.set(canvas, chart);
}

function initStatusChart(canvas) {
    const labels = parseChartData(canvas, 'labels');
    const rawValues = parseChartData(canvas, 'values').map(Number);
    const rawColors = parseChartData(canvas, 'colors');
    const total = parseChartNumber(canvas, 'total');
    const hasData = rawValues.some((value) => value > 0);
    const values = hasData ? rawValues : [1];
    const colors = hasData ? rawColors : [dashboardTheme().surface];
    const theme = dashboardTheme();

    dashboardCharts.get(canvas)?.destroy();

    const chart = new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels: hasData ? labels : [canvas.dataset.emptyLabel || 'Sin activos'],
            datasets: [
                {
                    data: values,
                    backgroundColor: colors,
                    borderColor: theme.tooltipBg,
                    borderWidth: 3,
                    hoverOffset: 4,
                },
            ],
        },
        options: doughnutOptions(theme, `${total}`, canvas.dataset.centerLabel || 'activos'),
        plugins: [centerTextPlugin],
    });

    dashboardCharts.set(canvas, chart);
}

function initPypChart(canvas) {
    const audited = parseChartNumber(canvas, 'audited');
    const total = parseChartNumber(canvas, 'total');
    const percent = parseChartNumber(canvas, 'percent');
    const pending = Math.max(total - audited, 0);
    const hasData = total > 0;
    const theme = dashboardTheme();

    dashboardCharts.get(canvas)?.destroy();

    const chart = new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels: hasData ? ['Auditados', 'Pendientes'] : ['Sin activos'],
            datasets: [
                {
                    data: hasData ? [audited, pending] : [1],
                    backgroundColor: hasData ? ['#00897b', theme.surface] : [theme.surface],
                    borderColor: theme.tooltipBg,
                    borderWidth: 3,
                    hoverOffset: 4,
                },
            ],
        },
        options: {
            ...doughnutOptions(theme, `${percent}%`, 'cobertura'),
            plugins: {
                ...doughnutOptions(theme, `${percent}%`, 'cobertura').plugins,
                tooltip: {
                    ...doughnutOptions(theme, `${percent}%`, 'cobertura').plugins.tooltip,
                    callbacks: {
                        label(context) {
                            if (!hasData) {
                                return 'Sin contenedores activos';
                            }

                            return `${context.label}: ${context.parsed}`;
                        },
                    },
                },
            },
        },
        plugins: [centerTextPlugin],
    });

    dashboardCharts.set(canvas, chart);
}

function initDashboardCharts() {
    document.querySelectorAll('.yard-chart-canvas').forEach(initYardChart);
    document.querySelectorAll('.status-chart-canvas').forEach(initStatusChart);
    document.querySelectorAll('.pyp-chart-canvas').forEach(initPypChart);
}

document.addEventListener('DOMContentLoaded', initDashboardCharts);
document.addEventListener('livewire:navigated', initDashboardCharts);

document.addEventListener('livewire:init', () => {
    window.Livewire?.hook?.('morph.updated', () => {
        requestAnimationFrame(initDashboardCharts);
    });
});

new MutationObserver(() => initDashboardCharts())
    .observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

function parseYardScene(canvas) {
    try {
        return JSON.parse(canvas.dataset.yardScene || '{}');
    } catch {
        return {};
    }
}

function containerColor(container) {
    if (container.anonymous) {
        return 0x68777d;
    }

    const status = String(container.estatus_ipsa || '').toUpperCase();

    if (status.includes('DISP')) {
        return 0x2fd09f;
    }

    if (status.includes('PEND')) {
        return 0xf7b844;
    }

    if (status.includes('OOS') || status.includes('REPAR')) {
        return 0xf45f5b;
    }

    return 0x8aa0b6;
}

function dimmedContainerColor(isDark) {
    return isDark ? 0x20343b : 0xb5c5c9;
}

function statusTone(value) {
    const status = String(value || '').toUpperCase();

    if (status.includes('DISP') || status === 'OK') {
        return 'success';
    }

    if (status.includes('PEND') || status.includes('DMG')) {
        return 'warning';
    }

    if (status.includes('OOS') || status.includes('REPAR')) {
        return 'danger';
    }

    return 'neutral';
}

function disposeYardScene(canvas) {
    const previous = yardScenes.get(canvas);

    if (!previous) {
        return;
    }

    previous.cleanup?.();
    previous.resizeObserver?.disconnect();
    previous.controls?.dispose();
    previous.renderer?.dispose();
    previous.scene?.traverse((object) => {
        object.geometry?.dispose?.();
        if (Array.isArray(object.material)) {
            object.material.forEach((material) => material.dispose?.());
        } else {
            object.material?.dispose?.();
        }
    });
    cancelAnimationFrame(previous.frame);
    yardScenes.delete(canvas);
}

function initYard3d(canvas) {
    const signature = JSON.stringify([canvas.dataset.yardScene, document.documentElement.getAttribute('data-theme')]);
    if (yardScenes.get(canvas)?.signature === signature) return;
    const data = parseYardScene(canvas);
    const blocks = Array.isArray(data.blocks) ? data.blocks : [];
    const hasVisualFilters = Boolean(data.has_visual_filters);
    const hasBlockFocus = Array.isArray(data.blocks) && data.blocks.length > 1 && data.blocks.some((block) => block.selected);
    const stage = canvas.closest('.containers-yard3d-stage');
    const tooltip = stage?.querySelector('.containers-yard3d-tooltip');
    const actionPanel = stage?.querySelector('.containers-yard3d-actions');
    const actionClose = actionPanel?.querySelector('.containers-yard3d-actions-close');
    const actionName = actionPanel?.querySelector('[data-yard-action-name]');
    const actionMeta = actionPanel?.querySelector('[data-yard-action-meta]');
    const actionInfo = actionPanel?.querySelector('[data-yard-action-info]');
    const actionTicket = actionPanel?.querySelector('[data-yard-action-ticket]');
    const actionTransfer = actionPanel?.querySelector('[data-yard-action-transfer]');
    const actionDispatch = actionPanel?.querySelector('[data-yard-action-dispatch]');
    const actionMaintenance = actionPanel?.querySelector('[data-yard-action-maintenance]');
    const actionHistory = actionPanel?.querySelector('[data-yard-action-history]');
    const actionAuditForm = actionPanel?.querySelector('[data-yard-audit-start]');

    if (!stage || !blocks.length) {
        disposeYardScene(canvas);
        return;
    }

    disposeYardScene(canvas);

    const scene = new THREE.Scene();
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    scene.background = new THREE.Color(isDark ? 0x0b171c : 0xf3f8fa);

    const renderer = new THREE.WebGLRenderer({
        antialias: window.devicePixelRatio <= 1.5,
        canvas,
        alpha: false,
        powerPreference: 'high-performance',
    });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.5));
    renderer.shadowMap.enabled = false;

    const camera = new THREE.PerspectiveCamera(42, 1, 0.1, 1000);
    const controls = new OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;
    controls.dampingFactor = 0.08;
    controls.maxPolarAngle = Math.PI * 0.48;
    controls.minDistance = 10;
    controls.maxDistance = 90;
    controls.touches.ONE = THREE.TOUCH.ROTATE;
    controls.touches.TWO = THREE.TOUCH.DOLLY_PAN;
    renderer.domElement.style.touchAction = 'none';

    scene.add(new THREE.HemisphereLight(0xffffff, isDark ? 0x102027 : 0xd7efed, 2.3));

    const keyLight = new THREE.DirectionalLight(0xffffff, 2.2);
    keyLight.position.set(12, 20, 10);
    scene.add(keyLight);

    const floorMaterial = new THREE.MeshStandardMaterial({
        color: isDark ? 0x263238 : 0x9aa6ac,
        roughness: 0.95,
        metalness: 0.02,
    });
    const blockMaterial = new THREE.MeshStandardMaterial({
        color: isDark ? 0x3b4c52 : 0xd3dbde,
        roughness: 0.9,
        metalness: 0.02,
    });
    const selectedBlockMaterial = new THREE.MeshStandardMaterial({
        color: isDark ? 0x2b5f57 : 0xbfe9e3,
        roughness: 0.82,
        metalness: 0.03,
    });
    const edgeMaterial = new THREE.LineBasicMaterial({ color: isDark ? 0x8fd8cf : 0x087866, transparent: true, opacity: 0.55 });
    const fadedEdgeMaterial = new THREE.LineBasicMaterial({ color: isDark ? 0x8fd8cf : 0x087866, transparent: true, opacity: 0.12 });
    const fadedBlockMaterial = new THREE.MeshStandardMaterial({ color: isDark ? 0x3b4c52 : 0xd3dbde, roughness: 0.9, transparent: true, opacity: 0.35 });
    const slotLineMaterial = new THREE.MeshBasicMaterial({ color: isDark ? 0xe8eef0 : 0x5f7177, transparent: true, opacity: isDark ? 0.55 : 0.75 });
    const fadedSlotLineMaterial = new THREE.MeshBasicMaterial({ color: isDark ? 0xe8eef0 : 0x5f7177, transparent: true, opacity: 0.15 });
    const laneMaterial = new THREE.MeshBasicMaterial({ color: 0xf2c94c });
    const blockGap = 4.9;
    const slotWidth = 1.34;
    const rowDepth = 2.2;
    const boxHeight = 0.78;
    const containerGeometry = createContainerGeometry(0.98, boxHeight, 1.96);
    const containerDetails = createContainerDetailsGeometry(0.98, boxHeight, 1.96);
    const containerLineMaterial = new THREE.LineBasicMaterial({ color: 0x0a1a1e, transparent: true, opacity: 0.62 });
    const dimmedLineMaterial = new THREE.LineBasicMaterial({ color: 0x0a1a1e, transparent: true, opacity: 0.12 });
    const containerMaterials = new Map();
    const materialForContainer = (container, isDimmed) => {
        const color = isDimmed ? dimmedContainerColor(isDark) : containerColor(container);
        const key = `${color}-${isDimmed ? 'dim' : 'solid'}`;

        if (!containerMaterials.has(key)) {
            containerMaterials.set(key, new THREE.MeshStandardMaterial({
                color,
                map: corrugatedTexture(),
                opacity: isDimmed ? 0.28 : 1,
                roughness: 0.58,
                metalness: 0.08,
                transparent: isDimmed,
            }));
        }

        return containerMaterials.get(key);
    };

    const selectedContainerMaterial = new THREE.MeshStandardMaterial({
        color: 0x2f6df6,
        map: corrugatedTexture(),
        emissive: 0x2f6df6,
        emissiveIntensity: 0.28,
        roughness: 0.5,
        metalness: 0.08,
    });

    const maxColumns = Math.max(...blocks.map((block) => Math.ceil((Number(block.capacidad) || 1) / 2)), 1);
    const blockSpan = maxColumns * slotWidth + 4.4;
    let groupColumns = 1;
    for (let candidate = 2; candidate <= Math.min(4, blocks.length); candidate++) {
        const aspect = (columns) => (columns * blockSpan) / (Math.ceil(blocks.length / columns) * blockGap);
        if (Math.abs(Math.log(aspect(candidate) / 1.7)) < Math.abs(Math.log(aspect(groupColumns) / 1.7))) groupColumns = candidate;
    }
    const rowCount = Math.ceil(blocks.length / groupColumns);
    const blockX = (blockIndex) => (Math.floor(blockIndex / rowCount) - (groupColumns - 1) / 2) * blockSpan;
    const floorWidth = Math.max(14, groupColumns * blockSpan + 3.6);
    const floorDepth = Math.max(8, rowCount * blockGap + 4.6);

    const floor = new THREE.Mesh(new THREE.BoxGeometry(floorWidth, 0.16, floorDepth), floorMaterial);
    floor.position.y = -0.1;
    scene.add(floor);
    const yardBox = (w, h, d, x, y, z, material) => {
        const mesh = new THREE.Mesh(new THREE.BoxGeometry(w, h, d), material);
        mesh.position.set(x, y, z);
        scene.add(mesh);
        return mesh;
    };
    const dashGeometry = new THREE.BoxGeometry(0.7, 0.01, 0.07);
    for (let lane = 0; lane <= rowCount; lane++) {
        const laneZ = (lane - rowCount / 2) * blockGap;
        for (let x = -floorWidth / 2 + 0.8; x < floorWidth / 2 - 0.8; x += 1.4) {
            const dash = new THREE.Mesh(dashGeometry, laneMaterial);
            dash.position.set(x + 0.35, -0.015, laneZ);
            scene.add(dash);
        }
    }
    for (let lane = 1; lane < groupColumns; lane++) {
        const laneX = (lane - groupColumns / 2) * blockSpan;
        for (let z = -floorDepth / 2 + 0.8; z < floorDepth / 2 - 0.8; z += 1.4) {
            const dash = new THREE.Mesh(dashGeometry, laneMaterial);
            dash.rotation.y = Math.PI / 2;
            dash.position.set(laneX, -0.015, z + 0.35);
            scene.add(dash);
        }
    }
    const frontLaneZ = (rowCount / 2) * blockGap;
    const gateX = floorWidth / 2 - 1.4;
    const boothZ = frontLaneZ + 1.45;
    const boothWall = new THREE.MeshStandardMaterial({ color: 0xf1f4f5, roughness: 0.65 });
    const boothGlass = new THREE.MeshStandardMaterial({ color: 0x4f86a0, roughness: 0.15, metalness: 0.35 });
    const boothTrim = new THREE.MeshStandardMaterial({ color: 0x0f7f72, roughness: 0.55 });
    yardBox(1.75, 0.08, 1.55, gateX, 0.0, boothZ, new THREE.MeshStandardMaterial({ color: 0xbfc8cc, roughness: 0.9 }));
    yardBox(1.5, 1.15, 1.3, gateX, 0.62, boothZ, boothWall);
    yardBox(1.5, 0.12, 1.3, gateX, 0.1, boothZ, boothTrim);
    yardBox(1.22, 0.42, 0.02, gateX, 0.82, boothZ - 0.66, boothGlass);
    yardBox(0.02, 0.42, 0.9, gateX - 0.76, 0.82, boothZ, boothGlass);
    yardBox(0.42, 0.8, 0.02, gateX + 0.35, 0.47, boothZ + 0.66, new THREE.MeshStandardMaterial({ color: 0x5b6b73, roughness: 0.6 }));
    yardBox(1.95, 0.14, 1.75, gateX, 1.27, boothZ, boothTrim);
    const signCanvas = document.createElement('canvas');
    signCanvas.width = 256;
    signCanvas.height = 64;
    const signContext = signCanvas.getContext('2d');
    signContext.fillStyle = '#0f7f72';
    signContext.fillRect(0, 0, 256, 64);
    signContext.fillStyle = '#ffffff';
    signContext.font = '800 34px Instrument Sans, Segoe UI, Arial';
    signContext.textAlign = 'center';
    signContext.textBaseline = 'middle';
    signContext.fillText('CONTROL', 128, 34);
    const signTexture = new THREE.CanvasTexture(signCanvas);
    signTexture.colorSpace = THREE.SRGBColorSpace;
    const sign = new THREE.Mesh(new THREE.PlaneGeometry(1.2, 0.3), new THREE.MeshBasicMaterial({ map: signTexture }));
    sign.position.set(gateX, 1.5, boothZ + 0.88);
    scene.add(sign);
    const frontSign = sign.clone();
    frontSign.rotation.y = Math.PI;
    frontSign.position.z = boothZ - 0.88;
    scene.add(frontSign);
    const barrierX = gateX - 1.15;
    yardBox(0.26, 0.55, 0.26, barrierX, 0.27, frontLaneZ + 0.95, new THREE.MeshStandardMaterial({ color: 0xf2c94c, roughness: 0.6 }));
    for (let i = 0; i < 6; i++) {
        yardBox(0.07, 0.07, 0.48, barrierX, 0.5, frontLaneZ + 0.68 - i * 0.48, new THREE.MeshBasicMaterial({ color: i % 2 ? 0xffffff : 0xd94b3d }));
    }
    const poleMaterial = new THREE.MeshStandardMaterial({ color: isDark ? 0x8a9ba1 : 0x6d7c82, roughness: 0.6, metalness: 0.4 });
    const lampMaterial = new THREE.MeshBasicMaterial({ color: 0xfff3c4 });
    [-1, 1].forEach((sideX) => [-1, 1].forEach((sideZ) => {
        const poleX = sideX * (floorWidth / 2 - 0.35);
        const poleZ = sideZ * (floorDepth / 2 - 0.35);
        yardBox(0.08, 3.2, 0.08, poleX, 1.52, poleZ, poleMaterial);
        yardBox(0.42, 0.1, 0.24, poleX - sideX * 0.12, 3.14, poleZ, lampMaterial);
    }));

    const pickables = [];
    let selectedBlockZ = 0;
    let selectedBlockX = 0;
    blocks.forEach((block, blockIndex) => {
        const z = ((blockIndex % rowCount) - (rowCount - 1) / 2) * blockGap;
        const offsetX = blockX(blockIndex);
        if (block.selected) {
            selectedBlockZ = z;
            selectedBlockX = offsetX;
        }
        const capacity = Math.max(Number(block.capacidad) || 1, 1);
        const columnCount = Math.ceil(capacity / 2);
        const baseWidth = columnCount * slotWidth;
        const base = new THREE.Mesh(
            new THREE.BoxGeometry(baseWidth, 0.18, rowDepth),
            block.selected ? selectedBlockMaterial : (hasBlockFocus ? fadedBlockMaterial : blockMaterial),
        );
        base.position.set(offsetX, 0.04, z);
        scene.add(base);

        const faded = hasBlockFocus && !block.selected;
        const blockLines = faded ? fadedSlotLineMaterial : slotLineMaterial;
        const edges = new THREE.LineSegments(new THREE.EdgesGeometry(base.geometry), faded ? fadedEdgeMaterial : edgeMaterial);
        edges.position.copy(base.position);
        scene.add(edges);
        for (let column = 0; column <= columnCount; column++) {
            yardBox(0.045, 0.012, rowDepth - 0.18, offsetX - baseWidth / 2 + column * slotWidth, 0.137, z, blockLines);
        }
        yardBox(baseWidth, 0.012, 0.045, offsetX, 0.137, z - rowDepth / 2 + 0.09, blockLines);
        yardBox(baseWidth, 0.012, 0.045, offsetX, 0.137, z + rowDepth / 2 - 0.09, blockLines);

        const labelCanvas = document.createElement('canvas');
        const labelContext = labelCanvas.getContext('2d');
        labelCanvas.width = 256;
        labelCanvas.height = 64;
        labelContext.clearRect(0, 0, 256, 64);
        labelContext.fillStyle = isDark ? '#edf7f6' : '#203044';
        labelContext.font = '700 28px Instrument Sans, Segoe UI, Arial';
        labelContext.textAlign = 'center';
        labelContext.fillText(`Bloque ${block.numero}`, 128, 42);
        const texture = new THREE.CanvasTexture(labelCanvas);
        const label = new THREE.Sprite(new THREE.SpriteMaterial({ map: texture, transparent: true, opacity: faded ? 0.35 : 1 }));
        label.position.set(offsetX - (baseWidth / 2) - 1.35, 1.0, z);
        label.scale.set(2.6, 0.65, 1);
        scene.add(label);

        const containers = Array.isArray(block.containers) ? block.containers : [];
        containers.forEach((container, index) => {
            const position = Math.max(Number(container.posicion) || index + 1, 1);
            const stackOffset = position <= capacity
                ? capacity - position
                : index;
            const column = Math.floor(stackOffset / 2);
            const level = stackOffset % 2;
            const x = offsetX + ((column + 0.5) * slotWidth) - (baseWidth / 2);
            const isDimmed = (hasVisualFilters && !container.matches) || faded;
            const mesh = new THREE.Mesh(containerGeometry, materialForContainer(container, isDimmed));
            mesh.position.set(x, 0.56 + (level * (boxHeight + 0.08)), z);
            mesh.userData = { container };
            addContainerDetails(mesh, containerDetails, isDimmed ? dimmedLineMaterial : containerLineMaterial);
            scene.add(mesh);
            if (!faded) pickables.push(mesh);
        });
    });

    const viewDirection = new THREE.Vector3(0.05, 0.62, 0.78).normalize();
    function frameYard(aspect) {
        const focusWidth = hasBlockFocus ? blockSpan + 1 : floorWidth;
        const focusDepth = hasBlockFocus ? blockGap * 2.2 : floorDepth;
        const halfFov = THREE.MathUtils.degToRad(camera.fov / 2);
        const horizontalFit = focusWidth / (2 * Math.tan(halfFov) * aspect);
        const verticalFit = (focusDepth * 0.8) / (2 * Math.tan(halfFov));
        const distance = Math.max(horizontalFit, verticalFit, 6) * (hasBlockFocus ? 1 : 1.3);
        controls.target.set(hasBlockFocus ? selectedBlockX : 0, 0.52, hasBlockFocus ? selectedBlockZ : 0);
        camera.position.copy(controls.target).addScaledVector(viewDirection, distance);
        controls.maxDistance = Math.max(90, distance * 3);
        camera.far = Math.max(1000, distance * 4);
        controls.update();
    }
    camera.position.set(selectedBlockX, Math.max(5.4, floorDepth * 0.95), selectedBlockZ + (floorDepth * 1.35));
    controls.target.set(selectedBlockX, 0.52, selectedBlockZ);
    controls.update();

    const raycaster = new THREE.Raycaster();
    const pointer = new THREE.Vector2();
    let hovered = null;
    let selectedMesh = null;
    let selectedMeshMaterial = null;
    let pointerStart = null;
    let hoverFrame = 0;
    let lastHoverEvent = null;
    const originalScale = new THREE.Vector3(1, 1, 1);
    let previousViewport = '';

    function resize() {
        const rect = stage.getBoundingClientRect();
        const width = Math.max(rect.width, 320);
        const height = Math.max(rect.height, 280);
        renderer.setSize(width, height, false);
        camera.aspect = width / height;
        const viewport = `${width}:${height}`;
        if (!data.fit_to_viewport && viewport !== previousViewport) {
            frameYard(width / height);
        } else if (data.fit_to_viewport && viewport !== previousViewport) {
            const halfFov = THREE.MathUtils.degToRad(camera.fov / 2);
            const horizontalFit = (floorWidth + 5) / (2 * Math.tan(halfFov) * camera.aspect);
            const verticalFit = (floorDepth + 4) / (2 * Math.tan(halfFov));
            const distance = Math.max(horizontalFit, verticalFit) * 1.1;
            const direction = camera.position.clone().sub(controls.target).normalize();
            controls.target.set(0, 0.52, 0);
            camera.position.copy(controls.target).addScaledVector(direction, distance);
            controls.maxDistance = Math.max(90, distance * 2);
            camera.far = Math.max(1000, distance * 4);
            controls.update();
        }
        previousViewport = viewport;
        camera.updateProjectionMatrix();
    }

    function hideActions() {
        if (actionPanel) {
            actionPanel.hidden = true;
        }
    }

    function setSelectedContainer(mesh) {
        if (selectedMesh) {
            selectedMesh.material = selectedMeshMaterial;
        }

        selectedMesh = mesh;
        selectedMeshMaterial = mesh?.material ?? null;

        if (selectedMesh) {
            selectedMesh.material = selectedContainerMaterial;
        }
    }

    function closeActions() {
        hideActions();
        setSelectedContainer(null);
    }

    function pickContainer(event) {
        const rect = canvas.getBoundingClientRect();
        pointer.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
        pointer.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;
        raycaster.setFromCamera(pointer, camera);

        return raycaster.intersectObjects(pickables, false)[0]?.object || null;
    }

    function placeActions(event) {
        if (!actionPanel) {
            return;
        }

        const rect = stage.getBoundingClientRect();
        const panelWidth = actionPanel.offsetWidth || 210;
        const panelHeight = actionPanel.offsetHeight || 150;
        const left = Math.min(Math.max(event.clientX - rect.left + 14, 12), rect.width - panelWidth - 12);
        const top = Math.min(Math.max(event.clientY - rect.top + 14, 12), rect.height - panelHeight - 12);
        actionPanel.style.left = `${left}px`;
        actionPanel.style.top = `${top}px`;
    }

    function showActions(item, event) {
        hideActions();
        if (item.anonymous) {
            return;
        }

        if (!actionPanel) {
            return;
        }

        const actions = item.actions || {};
        if (!actions.info && !actions.ticket && !actions.transfer && !actions.dispatch) {
            return;
        }

        if (actionName) actionName.textContent = item.nombre || 'Sin nombre';
        if (actionMeta) actionMeta.textContent = `Posicion ${item.posicion}`;
        if (actionMaintenance) actionMaintenance.textContent = actions.maintenance_label || 'Iniciar trabajo';
        if (actionAuditForm && actions.maintenance) {
            actionAuditForm.action = actions.maintenance;
            actionAuditForm.elements.namedItem('audit_version').value = String(actions.audit_version);
        }

        [
            [actionInfo, actions.info],
            [actionTicket, actions.ticket],
            [actionTransfer, actions.transfer],
            [actionDispatch, actions.dispatch],
            [actionMaintenance, actions.maintenance],
            [actionHistory, actions.history],
        ].forEach(([link, url]) => {
            if (!link) return;
            if (url) {
                link.href = url;
                link.hidden = false;
            } else {
                link.hidden = true;
            }
        });

        actionPanel.hidden = false;
        placeActions(event);
    }

    function showStatusTooltip(item, event) {
        if (!tooltip) {
            return;
        }

        const rect = canvas.getBoundingClientRect();
        tooltip.replaceChildren();
        const title = document.createElement('strong');
        title.textContent = item.anonymous ? 'Ocupado' : (item.nombre || 'Sin nombre');
        const position = document.createElement('span');
        position.textContent = `Posicion ${item.posicion}`;
        const statusGroup = document.createElement('div');
        statusGroup.className = 'yard-tooltip-statuses';
        (item.anonymous ? [] : [
            ['Estado IPSA', item.estatus_ipsa],
            ['Unidad', item.estatus_unidad],
            ['Estructura', item.estatus_box],
        ]).forEach(([labelText, value]) => {
            const row = document.createElement('span');
            const label = document.createElement('small');
            label.textContent = labelText;
            const badge = document.createElement('b');
            badge.textContent = value || 'N/D';
            badge.dataset.tone = statusTone(value);
            row.append(label, badge);
            statusGroup.append(row);
        });
        tooltip.append(title, position, statusGroup);
        tooltip.hidden = false;

        const tooltipWidth = tooltip.offsetWidth || 240;
        const tooltipHeight = tooltip.offsetHeight || 118;
        const left = Math.min(Math.max(event.clientX - rect.left + 14, 12), rect.width - tooltipWidth - 12);
        const belowTop = Math.min(Math.max(event.clientY - rect.top + 14, 12), rect.height - tooltipHeight - 12);
        const aboveTop = Math.max(event.clientY - rect.top - tooltipHeight - 12, 12);
        tooltip.style.left = `${left}px`;
        tooltip.style.top = `${event.pointerType === 'touch' ? aboveTop : belowTop}px`;
    }

    function updateHoverNow(event) {
        if (event.pointerType === 'touch') {
            return;
        }

        const rect = canvas.getBoundingClientRect();
        const hit = pickContainer(event);

        if (hovered && hovered !== hit) {
            hovered.scale.copy(originalScale);
        }

        hovered = hit;
        canvas.style.cursor = hovered && !hovered.userData.container.anonymous ? 'pointer' : 'grab';

        if (!hovered) {
            if (tooltip) tooltip.hidden = true;
            return;
        }

        hovered.scale.set(1.08, 1.08, 1.08);

        const item = hovered.userData.container;
        showStatusTooltip(item, event);
    }

    function updateHover(event) {
        lastHoverEvent = event;
        if (hoverFrame) {
            return;
        }

        hoverFrame = requestAnimationFrame(() => {
            hoverFrame = 0;
            if (lastHoverEvent) {
                updateHoverNow(lastHoverEvent);
            }
        });
    }

    const handlePointerLeave = () => {
        if (hovered) hovered.scale.copy(originalScale);
        hovered = null;
        canvas.style.cursor = 'grab';
        if (tooltip) tooltip.hidden = true;
    };
    const handlePointerDown = (event) => {
        pointerStart = {
            id: event.pointerId,
            x: event.clientX,
            y: event.clientY,
            type: event.pointerType,
        };
    };
    const handlePointerUp = (event) => {
        const moved = pointerStart
            ? Math.hypot(event.clientX - pointerStart.x, event.clientY - pointerStart.y)
            : 0;
        pointerStart = null;

        if (moved > 8) {
            return;
        }

        const hit = pickContainer(event);
        const item = hit?.userData?.container;
        if (hovered && hovered !== hit) hovered.scale.copy(originalScale);
        hovered = hit;
        if (item) {
            hovered.scale.set(1.08, 1.08, 1.08);
            if (event.pointerType === 'touch') {
                showStatusTooltip(item, event);
            } else if (tooltip) {
                tooltip.hidden = true;
            }
            setSelectedContainer(item.anonymous ? null : hit);
            showActions(item, event);
        } else {
            if (tooltip) tooltip.hidden = true;
            closeActions();
        }
    };

    canvas.addEventListener('pointerdown', handlePointerDown);
    canvas.addEventListener('pointermove', updateHover);
    canvas.addEventListener('pointerleave', handlePointerLeave);
    canvas.addEventListener('pointerup', handlePointerUp);
    actionClose?.addEventListener('click', closeActions);
    const beginAudit = (event) => {
        if (!actionAuditForm) return;
        event.preventDefault();
        actionAuditForm.requestSubmit();
    };
    actionMaintenance?.addEventListener('click', beginAudit);

    resize();
    const resizeObserver = new ResizeObserver(resize);
    resizeObserver.observe(stage);

    function animate() {
        if (!canvas.isConnected) {
            disposeYardScene(canvas);
            return;
        }
        controls.update();
        renderer.render(scene, camera);
        const state = yardScenes.get(canvas);
        if (state) {
            state.frame = requestAnimationFrame(animate);
        }
    }

    const cleanup = () => {
        canvas.removeEventListener('pointerdown', handlePointerDown);
        canvas.removeEventListener('pointermove', updateHover);
        canvas.removeEventListener('pointerleave', handlePointerLeave);
        canvas.removeEventListener('pointerup', handlePointerUp);
        actionClose?.removeEventListener('click', closeActions);
        actionMaintenance?.removeEventListener('click', beginAudit);
        cancelAnimationFrame(hoverFrame);
        setSelectedContainer(null);
        selectedContainerMaterial.dispose();
    };

    yardScenes.set(canvas, { signature, scene, renderer, camera, controls, resizeObserver, cleanup, frame: 0 });
    animate();
}

function parseIngressLocationScene(canvas) {
    try {
        return JSON.parse(canvas.dataset.scene || '{}');
    } catch {
        return {};
    }
}

function disposeIngressLocationScene(canvas) {
    const previous = ingressLocationScenes.get(canvas);

    if (!previous) {
        return;
    }

    previous.resizeObserver?.disconnect();
    previous.controls?.dispose?.();
    previous.cleanup?.();
    if (previous.frame) {
        cancelAnimationFrame(previous.frame);
    }
    previous.renderer?.dispose();
    previous.scene?.traverse((object) => {
        object.geometry?.dispose?.();
        if (Array.isArray(object.material)) {
            object.material.forEach((material) => {
                material.map?.dispose?.();
                material.dispose?.();
            });
        } else {
            object.material?.map?.dispose?.();
            object.material?.dispose?.();
        }
    });
    ingressLocationScenes.delete(canvas);
}

function initIngressLocation3d(canvas) {
    const signature = JSON.stringify([canvas.dataset.scene, document.documentElement.getAttribute('data-theme')]);
    if (ingressLocationScenes.get(canvas)?.signature === signature) return;
    const data = parseIngressLocationScene(canvas);
    const block = data.block || null;
    const containers = Array.isArray(data.containers) ? data.containers : [];
    const previewPosition = Number(data.preview_position || 0);
    const selectedPosition = Number(data.selected_position || 0);
    const blockerPositions = new Set((data.blocker_positions || []).map(Number));
    const stage = canvas.closest('.ingress-location3d-stage');
    const tooltip = stage?.querySelector('.ingress-location3d-tooltip');

    disposeIngressLocationScene(canvas);

    if (!stage || !block || (!previewPosition && !selectedPosition && !containers.length)) {
        canvas.width = Math.max(canvas.clientWidth, 1);
        canvas.height = Math.max(canvas.clientHeight, 1);
        return;
    }

    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const scene = new THREE.Scene();
    scene.background = new THREE.Color(isDark ? 0x071419 : 0xf3f8fa);

    const renderer = new THREE.WebGLRenderer({ antialias: true, canvas, alpha: false });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    renderer.shadowMap.enabled = true;

    const capacity = Math.max(Number(block.capacidad_maxima || 25), 1);
    const slotWidth = 1.28;
    const boxHeight = 0.74;
    const columnCount = Math.ceil(capacity / 2);
    const baseWidth = Math.max(9, columnCount * slotWidth);
    const baseDepth = 2.6;
    const floorDepth = 8;

    const camera = new THREE.PerspectiveCamera(39, 1, 0.1, 120);
    camera.position.set(baseWidth * 0.02, Math.max(4.9, floorDepth * 0.4), floorDepth * 1.28);
    const controls = new OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;
    controls.dampingFactor = 0.08;
    controls.maxPolarAngle = Math.PI * 0.49;
    controls.minDistance = 5.8;
    controls.maxDistance = 28;
    controls.target.set(0, 0.52, -(floorDepth * 0.04));
    controls.touches.ONE = THREE.TOUCH.ROTATE;
    controls.touches.TWO = THREE.TOUCH.DOLLY_PAN;
    controls.update();
    renderer.domElement.style.touchAction = 'none';

    scene.add(new THREE.HemisphereLight(0xffffff, isDark ? 0x0f252d : 0xd7efed, 2.25));
    const keyLight = new THREE.DirectionalLight(0xffffff, 2.35);
    keyLight.position.set(8, 14, 8);
    keyLight.castShadow = true;
    scene.add(keyLight);

    const floor = new THREE.Mesh(
        new THREE.BoxGeometry(baseWidth + 3.5, 0.14, 5.2),
        new THREE.MeshStandardMaterial({ color: isDark ? 0x10252c : 0xe7f4f2, roughness: 0.86 }),
    );
    floor.position.y = -0.18;
    floor.receiveShadow = true;
    scene.add(floor);

    const base = new THREE.Mesh(
        new THREE.BoxGeometry(baseWidth, 0.18, baseDepth),
        new THREE.MeshStandardMaterial({ color: isDark ? 0x20564d : 0xbfe9e3, roughness: 0.74, metalness: 0.03 }),
    );
    base.position.y = 0;
    base.receiveShadow = true;
    scene.add(base);
    const edges = new THREE.LineSegments(
        new THREE.EdgesGeometry(base.geometry),
        new THREE.LineBasicMaterial({ color: isDark ? 0x78d6cc : 0x087866, transparent: true, opacity: 0.42 }),
    );
    edges.position.copy(base.position);
    scene.add(edges);

    function slotForPosition(position) {
        const normalized = position <= capacity ? capacity - position : Math.max(position - 1, 0);
        const column = Math.floor(normalized / 2);
        const level = normalized % 2;

        return {
            x: ((column + 0.5) * slotWidth) - ((columnCount * slotWidth) / 2),
            y: 0.52 + (level * (boxHeight + 0.08)),
        };
    }

    function createPositionLabel(text, highlighted = false) {
        const labelCanvas = document.createElement('canvas');
        const context = labelCanvas.getContext('2d');
        labelCanvas.width = highlighted ? 384 : 320;
        labelCanvas.height = highlighted ? 192 : 160;

        const bg = highlighted ? '#ffc247' : (isDark ? '#e9fff9' : '#ffffff');
        const border = highlighted ? '#fff6c7' : '#2ee6c3';
        const textColor = highlighted ? '#2b2107' : '#0d3f3b';
        const shadow = highlighted ? 'rgba(92, 55, 0, 0.35)' : 'rgba(5, 30, 34, 0.22)';

        context.clearRect(0, 0, labelCanvas.width, labelCanvas.height);
        context.shadowColor = shadow;
        context.shadowBlur = 14;
        context.shadowOffsetY = 6;
        context.fillStyle = bg;
        context.strokeStyle = border;
        context.lineWidth = highlighted ? 12 : 7;

        const centerX = labelCanvas.width / 2;
        const badgeWidth = highlighted ? 268 : 190;
        const badgeHeight = highlighted ? 112 : 72;
        const x = (labelCanvas.width - badgeWidth) / 2;
        const y = highlighted ? 24 : 28;
        const radius = highlighted ? 44 : 30;

        context.beginPath();
        context.roundRect(x, y, badgeWidth, badgeHeight, radius);
        context.fill();
        context.shadowColor = 'transparent';
        context.stroke();

        context.fillStyle = textColor;
        context.font = highlighted ? '950 72px Instrument Sans, Segoe UI, Arial' : '950 46px Instrument Sans, Segoe UI, Arial';
        context.textAlign = 'center';
        context.textBaseline = 'middle';
        context.fillText(String(text), centerX, y + (badgeHeight / 2) + 1);

        const texture = new THREE.CanvasTexture(labelCanvas);
        texture.colorSpace = THREE.SRGBColorSpace;
        const label = new THREE.Sprite(new THREE.SpriteMaterial({
            map: texture,
            transparent: true,
            depthTest: false,
        }));
        label.renderOrder = highlighted ? 12 : 10;
        label.scale.set(highlighted ? 2.85 : 1.72, highlighted ? 1.43 : 0.86, 1);

        return label;
    }

    function labelOffset() {
        return { x: 0, y: 1.18, z: 0 };
    }

    function stackedLabelSide(position) {
        const slot = slotForPosition(position);
        const occupied = containers.map((container) => slotForPosition(Number(container.posicion || 0)));
        if (!occupied.some((other) => other.x === slot.x && other.y > slot.y)) return 0;

        const rightOccupied = occupied.some((other) => Math.abs(other.x - slot.x - slotWidth) < 0.01);
        const leftOccupied = occupied.some((other) => Math.abs(other.x - slot.x + slotWidth) < 0.01);
        return rightOccupied && !leftOccupied ? -1 : 1;
    }

    const selectedLabelSide = selectedPosition ? stackedLabelSide(selectedPosition) : 0;

    const pickables = [];
    const ingressContainerGeometry = createContainerGeometry(0.98, boxHeight, 2.2);
    const ingressContainerDetails = createContainerDetailsGeometry(0.98, boxHeight, 2.2);
    const ingressLineMaterial = new THREE.LineBasicMaterial({ color: 0x0a1a1e, transparent: true, opacity: 0.55 });
    containers.forEach((container) => {
        const position = Number(container.posicion || 0);
        const selected = selectedPosition === position && String(data.selected_id) === String(container.id);
        const blocking = blockerPositions.has(position);
        const slot = slotForPosition(position);
        const mesh = new THREE.Mesh(
            ingressContainerGeometry,
            new THREE.MeshStandardMaterial({
                color: selected ? 0xf7b844 : blocking ? 0xd15c55 : (isDark ? 0x31b996 : 0x149976),
                map: corrugatedTexture(),
                roughness: 0.58,
                metalness: 0.08,
            }),
        );
        mesh.castShadow = true;
        mesh.position.set(slot.x, slot.y, 0);
        mesh.userData = {
            container: {
                nombre: container.nombre || 'Contenedor',
                posicion: position,
            },
        };
        addContainerDetails(mesh, ingressContainerDetails, ingressLineMaterial);
        scene.add(mesh);
        pickables.push(mesh);
        if (!selected && !blocking) {
            const outline = new THREE.LineSegments(
                new THREE.EdgesGeometry(mesh.geometry),
                new THREE.LineBasicMaterial({ color: isDark ? 0x77f0cf : 0x087866, transparent: true, opacity: 0.84 }),
            );
            outline.position.copy(mesh.position);
            scene.add(outline);
        }
        if (selected) {
            const label = createPositionLabel(position, true);
            const offset = labelOffset();
            label.position.set(slot.x + offset.x + (selectedLabelSide * 2), slot.y + (boxHeight / 2) + offset.y, offset.z);
            scene.add(label);
            const start = selectedLabelSide
                ? new THREE.Vector3(slot.x + (selectedLabelSide * 0.52), slot.y + 0.12, 0.72)
                : new THREE.Vector3(slot.x, slot.y + (boxHeight / 2) + 0.04, 0);
            const end = new THREE.Vector3(label.position.x, label.position.y - 0.62, label.position.z);
            const points = selectedLabelSide ? [
                start,
                new THREE.Vector3(slot.x + (selectedLabelSide * 0.85), start.y, 0.72),
                new THREE.Vector3(slot.x + (selectedLabelSide * 0.85), end.y, 0.72),
                new THREE.Vector3(end.x, end.y, 0.72),
                end,
            ] : [start, end];
            const connector = new THREE.Line(
                new THREE.BufferGeometry().setFromPoints(points),
                new THREE.LineBasicMaterial({ color: 0xffd66b, depthTest: false }),
            );
            connector.renderOrder = 11;
            scene.add(connector);
            if (selectedLabelSide) {
                const anchorDot = new THREE.Mesh(
                    new THREE.SphereGeometry(0.065, 12, 12),
                    new THREE.MeshBasicMaterial({ color: 0xffd66b, depthTest: false }),
                );
                anchorDot.position.copy(start);
                anchorDot.renderOrder = 12;
                scene.add(anchorDot);
            }
        }
    });

    if (previewPosition) {
    const previewSlot = slotForPosition(previewPosition);
    const previewMaterial = new THREE.MeshStandardMaterial({
        color: 0xf7b844,
        emissive: 0x5c3700,
        emissiveIntensity: 0.32,
        roughness: 0.48,
        metalness: 0.08,
    });
    previewMaterial.map = corrugatedTexture();
    const previewMesh = new THREE.Mesh(createContainerGeometry(1.0, boxHeight, 2.24), previewMaterial);
    addContainerDetails(previewMesh, createContainerDetailsGeometry(1.0, boxHeight, 2.24), new THREE.LineBasicMaterial({ color: 0x5c3700, transparent: true, opacity: 0.6 }));
    previewMesh.castShadow = true;
    previewMesh.position.set(previewSlot.x, previewSlot.y, 0);
    scene.add(previewMesh);

    const outline = new THREE.LineSegments(
        new THREE.EdgesGeometry(previewMesh.geometry),
        new THREE.LineBasicMaterial({ color: 0xfff0b8, transparent: true, opacity: 0.92 }),
    );
    outline.position.copy(previewMesh.position);
    scene.add(outline);

    const previewLabel = createPositionLabel(previewPosition, true);
    const previewOffset = labelOffset();
    previewLabel.position.set(previewSlot.x + previewOffset.x, previewSlot.y + (boxHeight / 2) + previewOffset.y, previewOffset.z);
    scene.add(previewLabel);

    const anchorStart = new THREE.Vector3(previewSlot.x, previewSlot.y + (boxHeight / 2) + 0.04, 0);
    const anchorEnd = new THREE.Vector3(previewLabel.position.x, previewLabel.position.y - 0.62, previewLabel.position.z);
    const anchorLine = new THREE.Line(
        new THREE.BufferGeometry().setFromPoints([anchorStart, anchorEnd]),
        new THREE.LineBasicMaterial({
            color: 0xffd66b,
            transparent: true,
            opacity: 0.96,
            depthTest: false,
        }),
    );
    anchorLine.renderOrder = 11;
    scene.add(anchorLine);

    const anchorDot = new THREE.Mesh(
        new THREE.SphereGeometry(0.09, 18, 18),
        new THREE.MeshBasicMaterial({ color: 0xffd66b, depthTest: false }),
    );
    anchorDot.position.copy(anchorStart);
    anchorDot.renderOrder = 11;
    scene.add(anchorDot);
    }

    const raycaster = new THREE.Raycaster();
    const pointer = new THREE.Vector2();
    let hovered = null;

    function hideTooltip() {
        if (tooltip) {
            tooltip.hidden = true;
        }
        canvas.style.cursor = 'grab';
        if (hovered) {
            hovered.scale.set(1, 1, 1);
            hovered = null;
        }
    }

    function pickContainer(event) {
        const rect = canvas.getBoundingClientRect();
        pointer.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
        pointer.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;
        raycaster.setFromCamera(pointer, camera);

        return raycaster.intersectObjects(pickables, false)[0]?.object || null;
    }

    function showTooltip(event, mesh) {
        if (!tooltip || !mesh?.userData?.container) {
            return;
        }

        const container = mesh.userData.container;
        const stageRect = stage.getBoundingClientRect();
        const tooltipPosition = tooltip.querySelector('[data-ingress-tooltip-position]');
        const tooltipName = tooltip.querySelector('[data-ingress-tooltip-name]');

        if (tooltipPosition) {
            tooltipPosition.textContent = `Posicion ${container.posicion}`;
        }

        if (tooltipName) {
            tooltipName.textContent = container.nombre;
        }

        tooltip.hidden = false;
        const x = Math.min(event.clientX - stageRect.left, stageRect.width - 180);
        const y = Math.min(event.clientY - stageRect.top, stageRect.height - 70);
        tooltip.style.left = `${Math.max(8, x)}px`;
        tooltip.style.top = `${Math.max(8, y)}px`;
    }

    function updateHover(event) {
        const next = pickContainer(event);

        if (hovered && hovered !== next) {
            hovered.scale.set(1, 1, 1);
        }

        hovered = next || null;

        if (!hovered) {
            hideTooltip();
            return;
        }

        hovered.scale.set(1.04, 1.04, 1.04);
        canvas.style.cursor = 'pointer';
        showTooltip(event, hovered);
    }

    function resize() {
        const rect = stage.getBoundingClientRect();
        const width = Math.max(rect.width, 320);
        const height = Math.max(rect.height, 230);
        renderer.setSize(width, height, false);
        camera.aspect = width / height;
        const focusedPositions = data.transfer_focus
            ? selectedPosition
                ? [selectedPosition, ...blockerPositions, ...containers
                    .map((container) => Number(container.posicion))
                    .filter((position) => position > selectedPosition && position <= selectedPosition + 6)]
                : previewPosition ? [previewPosition] : []
            : [previewPosition, ...containers.map((container) => Number(container.posicion))].filter((position) => position > 0);
        const focusedXs = focusedPositions.map((position) => slotForPosition(position).x);
        if (data.transfer_focus && selectedLabelSide) {
            focusedXs.push(slotForPosition(selectedPosition).x + (selectedLabelSide * 3.5));
        }
        const focusX = focusedXs.length ? (Math.min(...focusedXs) + Math.max(...focusedXs)) / 2 : 0;
        const visibleWidth = focusedXs.length
            ? Math.max(data.transfer_focus ? 9 : 6, Math.max(...focusedXs) - Math.min(...focusedXs) + 5)
            : baseWidth + 3.5;
        const halfFov = THREE.MathUtils.degToRad(camera.fov / 2);
        const fitDistance = visibleWidth / (2 * Math.tan(halfFov) * camera.aspect);
        const distance = Math.max(data.transfer_focus ? floorDepth * 1.28 : 8.5, fitDistance * 1.12);
        camera.position.x = focusX;
        camera.position.z = distance;
        camera.position.y = Math.max(data.transfer_focus ? 4.9 : 4.1, distance * 0.38);
        controls.target.x = focusX;
        controls.target.y = data.transfer_focus ? 0.52 : 1.05;
        controls.maxDistance = Math.max(28, distance * 1.4);
        camera.updateProjectionMatrix();
        controls.update();
    }

    resize();
    const resizeObserver = new ResizeObserver(resize);
    resizeObserver.observe(stage);
    canvas.addEventListener('pointermove', updateHover);
    canvas.addEventListener('pointerdown', updateHover);
    canvas.addEventListener('pointerleave', hideTooltip);
    const cleanup = () => {
        canvas.removeEventListener('pointermove', updateHover);
        canvas.removeEventListener('pointerdown', updateHover);
        canvas.removeEventListener('pointerleave', hideTooltip);
    };
    let frame = 0;
    function animate() {
        if (!canvas.isConnected) {
            disposeIngressLocationScene(canvas);
            return;
        }
        frame = requestAnimationFrame(animate);
        controls.update();
        renderer.render(scene, camera);
    }

    ingressLocationScenes.set(canvas, {
        signature,
        scene,
        renderer,
        camera,
        controls,
        resizeObserver,
        cleanup,
        get frame() {
            return frame;
        },
    });
    animate();
}

function initIngressLocation3dScenes() {
    document.querySelectorAll('.ingress-location3d').forEach(initIngressLocation3d);
}

function initYard3dScenes() {
    document.querySelectorAll('.containers-yard3d').forEach(initYard3d);
}

document.addEventListener('DOMContentLoaded', initYard3dScenes);
document.addEventListener('DOMContentLoaded', initIngressLocation3dScenes);
document.addEventListener('livewire:navigated', initYard3dScenes);
document.addEventListener('livewire:navigated', initIngressLocation3dScenes);
window.addEventListener('ingress-location3d:update', initIngressLocation3dScenes);

document.addEventListener('livewire:init', () => {
    let pendingFrame = false;
    window.Livewire?.hook?.('morph.updated', () => {
        if (pendingFrame) return;
        pendingFrame = true;
        requestAnimationFrame(() => {
            pendingFrame = false;
            initYard3dScenes();
            initIngressLocation3dScenes();
        });
    });
});

new MutationObserver(() => initYard3dScenes())
    .observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

new MutationObserver(() => initIngressLocation3dScenes())
    .observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
