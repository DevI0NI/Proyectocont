import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

function context(values = ['1', '2']) {
    const documentHandlers = new Map();
    const handlers = new Map();
    const runtime = vm.createContext({ Event, document: { addEventListener(name, handler) { documentHandlers.set(name, handler); } } });
    const source = readFileSync(new URL('../../resources/js/reports.js', import.meta.url), 'utf8').replace(/^import .*;\r?\n/gm, '');
    vm.runInContext(source, runtime);
    const option = (value, name, patio = '') => ({
        value, checked: false, disabled: false, dataset: { patio },
        label: { hidden: false, querySelector() { return { textContent: name }; } },
        closest() { return this.label; },
    });
    const yards = values.map((value) => ({ value, options: [{ value: '' }, { value: '1' }, { value: '2' }, { value: '3' }], dispatchEvent() {} }));
    const blocks = values.map(() => [option('11', 'Bloque 1', '1'), option('21', 'Bloque 1', '2'), option('31', 'Bloque 1', '3')]);
    function menu(kind, inputs) {
        const classes = new Set();
        const handlers = new Map();
        const value = {};
        const all = { checked: true, hasAttribute(name) { return name === 'data-report-location-all'; } };
        const trigger = { disabled: false, expanded: 'false', setAttribute(name, content) { this.expanded = content; }, addEventListener(name, handler) { handlers.set('trigger:' + name, handler); }, focus() { this.focused = true; } };
        return { dataset: { reportLocation: kind }, handlers, all, trigger, value,
            classList: { contains(name) { return classes.has(name); }, remove(name) { classes.delete(name); }, add(name) { classes.add(name); } },
            addEventListener(name, handler) { handlers.set(name, handler); },
            querySelectorAll() { return inputs; },
            querySelector(selector) { return selector === '[data-report-location-value]' ? value : selector === '[data-report-location-all]' ? all : trigger; },
        };
    }
    const menus = blocks.map((inputs) => menu('blocks', inputs));
    const rows = values.map((value, index) => ({ querySelector(selector) { return selector === '[data-report-yard]' ? yards[index] : menus[index]; }, querySelectorAll() { return blocks[index]; } }));
    const add = {};
    const root = {
        querySelectorAll(selector) { return selector === '[data-report-location-row]' ? rows : menus; },
        querySelector(selector) { return selector === '[data-report-add-location]' ? add : null; },
        addEventListener(name, handler) { handlers.set(name, handler); },
    };
    return { runtime, root, yards, blocks, menus, documentHandlers, handlers, add };
}

test('patio rows filter their own blocks independently and clear selections when changing patio', () => {
    const { runtime, root, yards, blocks, menus } = context();
    blocks[0][0].checked = true;
    blocks[1][1].checked = true;
    runtime.updateReportLocations(root);
    assert.equal(blocks[0][1].disabled, true);
    assert.equal(blocks[1][0].disabled, true);
    assert.equal(blocks[0][0].checked, true);
    assert.equal(blocks[1][1].checked, true);
    assert.equal(yards[0].options[2].disabled, true);
    yards[0].value = '3';
    runtime.updateReportLocations(root);
    assert.equal(blocks[0][0].checked, false);
    assert.equal(blocks[0][0].label.hidden, true);
    assert.equal(blocks[0][2].disabled, false);
    assert.equal(blocks[1][1].checked, true);
    assert.equal(menus[0].value.textContent, 'Todos');
});

test('Todos clears only its own row and no patio disables the block menu and adding another row', () => {
    const { runtime, root, yards, blocks, menus, handlers, add } = context(['1']);
    runtime.initializeReportLocations(root);
    blocks[0][0].checked = true;
    handlers.get('change')({ target: { matches() { return false; }, closest() { return menus[0]; }, hasAttribute() { return true; } } });
    assert.equal(menus[0].value.textContent, 'Todos');
    assert.equal(menus[0].all.checked, true);
    assert.equal(blocks[0][0].checked, false);
    assert.equal(blocks[0][1].disabled, true);
    yards[0].value = '';
    runtime.updateReportLocations(root);
    assert.equal(menus[0].trigger.disabled, true);
    assert.equal(add.disabled, true);
    assert.equal(yards[0].required, false);
});

test('location menus open exclusively and close with Escape or an outside click', () => {
    const { runtime, root, menus, documentHandlers, handlers } = context();
    runtime.initializeReportLocations(root);
    const click = (menu) => handlers.get('click')({ target: { closest(selector) { return selector === '[data-report-location-trigger]' ? menu.trigger : null; } } });
    menus.forEach((menu) => { menu.trigger.closest = () => menu; });
    click(menus[0]);
    assert.equal(menus[0].trigger.expanded, 'true');
    click(menus[1]);
    assert.equal(menus[0].trigger.expanded, 'false');
    assert.equal(menus[1].trigger.expanded, 'true');
    handlers.get('keydown')({ target: { closest() { return menus[1]; } }, key: 'Escape', preventDefault() {} });
    assert.equal(menus[1].trigger.expanded, 'false');
    assert.equal(menus[1].trigger.focused, true);
    click(menus[0]);
    documentHandlers.get('click')({ target: { closest() { return null; } } });
    assert.equal(menus[0].trigger.expanded, 'false');
});

test('changing patio renders only the blocks of that patio with the correct row field name', () => {
    const { runtime, root, yards, blocks, handlers } = context(['1']);
    const row = root.querySelectorAll('[data-report-location-row]')[0];
    const originalQuery = root.querySelector.bind(root);
    root.querySelector = (selector) => selector === '[data-report-location-data]'
        ? { textContent: JSON.stringify([{ id: 3, blocks: [{ id: 31, number: 7 }] }]) } : originalQuery(selector);
    runtime.document.createElement = (tag) => ({
        tag, dataset: {}, children: [], closest() { return this.parent; },
        append(...children) { this.children.push(...children); children.forEach((child) => { child.parent = this; }); },
        querySelector() { return this.children.find((child) => child.tag === 'span'); },
    });
    const originalRowQuery = row.querySelector.bind(row);
    row.querySelector = (selector) => selector === '[data-report-block-options]'
        ? { replaceChildren(...labels) { blocks[0] = labels.map((label) => label.children[0]); } } : originalRowQuery(selector);
    runtime.initializeReportLocations(root);
    const yard = yards[0];
    yard.value = '3'; yard.name = 'locations[4][patio_id]';
    yard.matches = () => true;
    yard.closest = (selector) => selector === '[data-report-location-row]' ? row : null;
    handlers.get('change')({ target: yard });
    assert.equal(blocks[0].length, 1);
    assert.equal(blocks[0][0].value, '31');
    assert.equal(blocks[0][0].name, 'locations[4][bloque_ids][]');
    assert.equal(blocks[0][0].disabled, false);
    assert.equal(blocks[0][0].parent.querySelector('span').textContent, 'Bloque 7');
});
