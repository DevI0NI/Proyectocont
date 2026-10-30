import assert from 'node:assert/strict';
import test from 'node:test';
import { auditChartConfig, auditTooltip, initializeAuditCharts } from '../../resources/js/external-audit-charts.js';

const data = () => ({
    totals: { total: 6, ready: 2, in_review: 1, approved: 1, rejected: 0, waiting: 2 },
    states: [{ key: 'ready', label: 'Pendientes', color: '#5498d5' }, { key: 'in_review', label: 'En revision', color: '#e8b349' },
        { key: 'approved', label: 'Aprobados', color: '#25af91' }, { key: 'rejected', label: 'Rechazados', color: '#e36e78' }, { key: 'waiting', label: 'Esperando', color: '#94a6b1' }],
    branches: [{ name: 'Puerto Barrios', total: 5, ready: 2, in_review: 1, approved: 1, rejected: 0, waiting: 1 },
        { name: 'Entre Rios', total: 1, ready: 0, in_review: 0, approved: 0, rejected: 0, waiting: 1 }],
});

test('audit charts use five exclusive states, not a duplicate total series, and integer stacked bars', () => {
    const config = auditChartConfig(data(), 'states');
    assert.deepEqual(config.data.datasets[0].data, [2, 1, 1, 0, 2]);
    assert.equal(config.data.datasets[0].data.reduce((a, b) => a + b, 0), 6);
    const bars = auditChartConfig(data(), 'branches');
    assert.equal(bars.options.indexAxis, 'y');
    assert.equal(bars.options.scales.x.stacked, true);
    assert.equal(bars.options.scales.x.ticks.precision, 0);
    assert.deepEqual(bars.data.labels, ['Puerto Barrios', 'Entre Rios']);
    assert.equal(bars.data.datasets.length, 5);
    assert.deepEqual(bars.data.datasets[4].data, [1, 1]);
});

test('zero data and long branch labels remain intact, and dark mode changes readable axis colors', () => {
    const empty = data();
    empty.totals = { total: 0, ready: 0, in_review: 0, approved: 0, rejected: 0, waiting: 0 };
    empty.branches = [];
    assert.deepEqual(auditChartConfig(empty, 'states').data.datasets[0].data, [0, 0, 0, 0, 0]);
    assert.deepEqual(auditChartConfig(empty, 'branches').data.labels, []);
    const sample = data();
    sample.branches[0].name = 'Sucursal con nombre largo y espacios';
    assert.equal(auditChartConfig(sample, 'branches').data.labels[0], sample.branches[0].name);
    assert.notEqual(auditChartConfig(sample, 'branches', true).options.scales.x.ticks.color,
        auditChartConfig(sample, 'branches', false).options.scales.x.ticks.color);
});

test('tooltips describe the hovered state or branch and include the container count', () => {
    const states = auditChartConfig(data(), 'states').options.plugins.tooltip;
    const bars = auditChartConfig(data(), 'branches');

    assert.equal(states.enabled, false);
    assert.equal(states.external, auditTooltip);
    assert.equal(states.callbacks.title([{ label: 'Pendientes de inspeccion' }]), 'Pendientes de inspeccion');
    assert.equal(states.callbacks.label({ parsed: 2 }), '2 contenedores');
    assert.equal(bars.options.plugins.tooltip.callbacks.title([{ chart: { data: bars.data }, dataIndex: 0 }]), 'Puerto Barrios');
    assert.equal(bars.options.plugins.tooltip.callbacks.label({ parsed: { x: 1 }, dataset: { label: 'Aprobados' } }), 'Aprobados: 1 contenedor');
});

test('floating tooltips keep full text outside the canvas, fit the viewport and are removed with the chart', () => {
    const createNode = () => ({
        children: [], offsetWidth: 260, offsetHeight: 85,
        style: { setProperty() {} }, classes: new Set(),
        get classList() { return { add: (name) => this.classes.add(name), remove: (name) => this.classes.delete(name) }; },
        setAttribute() {}, append(...nodes) { this.children.push(...nodes); },
        appendChild(node) { this.children.push(node); return node; },
        replaceChildren(...nodes) { this.children = nodes; }, remove() { this.removed = true; },
    });
    const body = createNode();
    const documentRef = { body, createElement: createNode, createTextNode: (text) => ({ textContent: text }), defaultView: { innerWidth: 360, innerHeight: 640 } };
    const chart = { canvas: { ownerDocument: documentRef, getBoundingClientRect: () => ({ left: 300, top: 10 }) } };
    const tooltip = { opacity: 1, title: ['Sucursal con nombre completo muy largo'], body: [{ lines: ['Pendientes de inspeccion: 2 contenedores'] }], caretX: 30, caretY: 10 };

    auditTooltip({ chart, tooltip });

    const element = body.children[0];
    assert.equal(element.children[0].textContent, tooltip.title[0]);
    assert.equal(element.children[1].children[1].textContent, tooltip.body[0].lines[0]);
    assert.equal(element.style.left, '88px');
    assert.equal(element.style.top, '34px');
    assert.equal(element.classes.has('visible'), true);
    auditTooltip({ chart, tooltip: { opacity: 0 } });
    assert.equal(element.classes.has('visible'), false);
    auditTooltip({ chart, tooltip });
    assert.equal(body.children.length, 1);
    auditChartConfig(data(), 'states').plugins.find((plugin) => plugin.id === 'externalAuditTooltip').afterDestroy(chart);
    assert.equal(element.removed, true);
});

test('polling reuses charts, updates changed data, and disposes charts after leaving the dashboard', () => {
    let payload = data();
    let dark = false;
    let created = 0;
    let updated = 0;
    let destroyed = 0;
    const canvases = ['states', 'branches'].map((kind) => ({ isConnected: true, dataset: { auditChart: kind }, parentElement: { style: {} } }));
    const root = { querySelector: () => ({ textContent: JSON.stringify(payload) }), querySelectorAll: () => canvases };
    const documentRef = { documentElement: { getAttribute: () => dark ? 'dark' : 'light' }, querySelectorAll: () => [root] };
    class FakeChart {
        constructor() { created++; }
        update(mode) { assert.equal(mode, 'none'); updated++; }
        destroy() { destroyed++; }
    }
    const registry = new Map();

    initializeAuditCharts(documentRef, FakeChart, registry);
    initializeAuditCharts(documentRef, FakeChart, registry);
    assert.equal(created, 2);
    assert.equal(updated, 0);
    payload.totals.ready = 3;
    initializeAuditCharts(documentRef, FakeChart, registry);
    assert.equal(created, 2);
    assert.equal(updated, 2);
    dark = true;
    initializeAuditCharts(documentRef, FakeChart, registry);
    assert.equal(updated, 4);
    canvases.forEach((canvas) => { canvas.isConnected = false; });
    documentRef.querySelectorAll = () => [];
    initializeAuditCharts(documentRef, FakeChart, registry);
    assert.equal(destroyed, 2);
    assert.equal(registry.size, 0);
});
