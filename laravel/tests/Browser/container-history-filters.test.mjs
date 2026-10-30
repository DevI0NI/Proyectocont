import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

function historyContext() {
    const handlers = new Map();
    let initialized = 0;
    const storage = new Map();
    const scrolls = [];
    const help = { replaceChildren() {} };
    const document = {
        addEventListener(name, handler) { handlers.set(name, handler); },
        querySelector() { return {}; },
        querySelectorAll() { return [help]; },
    };
    const source = readFileSync(new URL('../../resources/js/container-history.js', import.meta.url), 'utf8').replace(/^import .*;\r?\n/gm, '');
    const runtime = {
        document, initializeRecordSelects() { initialized++; },
        location: { pathname: '/historial-contenedores', search: '?state=all&page=2' },
        window: { scrollY: 580, scrollTo(value) { scrolls.push(value.top); } },
        sessionStorage: { getItem(key) { return storage.get(key) ?? null; }, setItem(key, value) { storage.set(key, value); }, removeItem(key) { storage.delete(key); } },
        requestAnimationFrame(callback) { callback(); },
    };
    vm.runInNewContext(source, runtime);
    return { handlers, storage, scrolls, selectEvent: runtime.selectHistoryEvent, initialized: () => initialized };
}

test('history selects are initialized again after live table replacement', () => {
    const context = historyContext();
    context.handlers.get('DOMContentLoaded')();
    context.handlers.get('administration:updated')();
    assert.equal(context.initialized(), 2);
});

test('opening history stores the exact list position and restores it only on returning to that list', () => {
    const context = historyContext();
    context.handlers.get('click')({ target: { closest(selector) { return selector === '[data-history-view]' ? {} : null; } } });
    const saved = JSON.parse(context.storage.get('proportsa-history-return'));
    assert.equal(saved.url, '/historial-contenedores?state=all&page=2');
    assert.equal(saved.scrollY, 580);
    context.handlers.get('DOMContentLoaded')();
    assert.deepEqual(context.scrolls, [580]);
    assert.equal(context.storage.has('proportsa-history-return'), false);
});

test('history filters submit automatically even when their column menu is portaled outside the table', () => {
    const context = historyContext();
    let submissions = 0;
    const input = {
        closest(selector) { return selector === '[data-history-query]' ? {} : null; },
        matches() { return true; },
        form: { requestSubmit() { submissions++; } },
    };
    context.handlers.get('change')({ target: input });
    assert.equal(submissions, 1);
    context.handlers.get('change')({ target: { ...input, closest() { return null; } } });
    assert.equal(submissions, 1);
});

test('horizontal timeline selects one detail panel and updates its accessible navigation state', () => {
    const context = historyContext();
    const panels = [{}, {}, {}];
    const previous = {};
    const next = {};
    const tabs = Array.from({ length: 3 }, (_, index) => ({
        dataset: { historyEvent: String(index) },
        selected: index === 0 ? 'true' : 'false',
        setAttribute(name, value) { if (name === 'aria-selected') this.selected = value; },
        focus() { this.focused = true; },
        scrollIntoView() { this.scrolled = true; },
    }));
    const root = {
        querySelectorAll(selector) { return selector === '[data-history-event]' ? tabs : panels; },
        querySelector(selector) { return selector === '[data-history-previous]' ? previous : next; },
    };
    context.selectEvent(root, 1, true);
    assert.deepEqual(panels.map((panel) => panel.hidden), [true, false, true]);
    assert.deepEqual(tabs.map((tab) => tab.selected), ['false', 'true', 'false']);
    assert.deepEqual(tabs.map((tab) => tab.tabIndex), [-1, 0, -1]);
    assert.equal(tabs[1].focused, true);
    assert.equal(tabs[1].scrolled, true);
    assert.equal(previous.disabled, false);
    assert.equal(next.disabled, false);
    context.selectEvent(root, 100);
    assert.equal(next.disabled, true);
    assert.equal(panels[2].hidden, false);
    context.selectEvent(root, -100);
    assert.equal(previous.disabled, true);
    assert.equal(panels[0].hidden, false);
});

test('selecting an ingress submits its own form once without using table filters', () => {
    const context = historyContext();
    let submissions = 0;
    const input = {
        matches(selector) { return selector === 'select[name=cycle]'; },
        closest(selector) { return selector === '[data-history-cycle-form]' ? {} : null; },
        form: { requestSubmit() { submissions++; } },
    };
    context.handlers.get('change')({ target: input });
    assert.equal(submissions, 1);
});
