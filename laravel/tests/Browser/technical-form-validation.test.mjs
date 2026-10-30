import assert from 'node:assert/strict';
import test from 'node:test';
import { technicalTextError, dispatchObservationsError, repairStepErrors, sendTechnicalForm, initializeTechnicalForm } from '../../resources/js/technical-form-validation.js';

test('exit observations remain optional but require 40 characters when provided', () => {
    for (const value of ['', '   ', 'El contenedor se entrega conforme a la revision efectuada.']) {
        assert.equal(dispatchObservationsError(value), '', value);
    }
    assert.match(dispatchObservationsError('Sin novedades.'), /40/);
    const boundary = 'El equipo fue revisado antes de entregar al transportista.'.slice(0, 40);
    assert.equal(dispatchObservationsError(boundary), '');
    assert.match(dispatchObservationsError(boundary.slice(0, 39)), /40/);
    for (const value of ['1234567890', '!!! ---', 'aaaaaaaaaaaa', 'texto de relleno '.repeat(4), 'Codigo 12345678901234567890']) {
        assert.notEqual(dispatchObservationsError(value), '', value);
    }
    const valid = 'El contenedor sale conforme a la revision. Se verificaron las puertas, los sellos y la unidad antes de entregar el equipo al transportista de esta sucursal.'.slice(0, 150);
    assert.equal(valid.length, 150);
    assert.equal(dispatchObservationsError(valid), '');
    assert.match(dispatchObservationsError(valid + 'x'), /150/);
});

test('assessment and result steps need meaningful text and at least one retained or new photo', () => {
    const photo = { type: 'image/jpeg', size: 1024 };
    const assessment = { diagnosis: 'Se encontro una fuga en el sello de la puerta.', photoField: 'photos_before', photos: { saved: 0, files: [photo] } };
    assert.deepEqual(repairStepErrors(0, assessment), {});
    assert.deepEqual(repairStepErrors(0, { ...assessment, photos: { saved: 1, files: [] } }), {});
    const invalid = repairStepErrors(0, { ...assessment, diagnosis: 'a'.repeat(40), photos: { saved: 0, files: [] } });
    assert.match(invalid.diagnosis[0], /Evita repetir/);
    assert.match(invalid.photos_before[0], /al menos una foto/);
    const result = { work_performed: 'Se sustituyo el sello y no presenta fugas.', photoField: 'evidence_after', photos: { saved: 0, files: [photo] } };
    assert.deepEqual(repairStepErrors(2, result), {});
    assert.match(repairStepErrors(2, { ...result, photos: { saved: 0, files: [{ ...photo, size: 3 * 1024 * 1024 }] } }).evidence_after[0], /2 MB/);
    assert.match(repairStepErrors(2, { ...result, photos: { saved: 5, files: [photo] } }).evidence_after[0], /hasta 5 fotos/);
});

test('repair step requires a service and valid nonduplicated material rows, but materials are optional', () => {
    assert.deepEqual(repairStepErrors(1, { serviceId: '1', materials: [] }), {});
    const invalid = repairStepErrors(1, { serviceId: '', materials: [
        { index: 0, partId: '', quantity: '0', max: 99999 },
        { index: 3, partId: '2', quantity: '1', max: 99999 },
        { index: 5, partId: '2', quantity: '1.0001', max: 99999 },
    ] });
    assert.ok(invalid.repair_service_id);
    assert.ok(invalid['materials.0.part_id']);
    assert.ok(invalid['materials.0.quantity']);
    assert.ok(invalid['materials.5.part_id']);
    assert.ok(invalid['materials.5.quantity']);
    assert.equal(invalid['materials.3.quantity'], undefined);
    assert.deepEqual(repairStepErrors(1, { serviceId: '1', materials: [{ index: 2, partId: '3', quantity: '0.001', max: 99999 }] }), {});
    assert.ok(repairStepErrors(1, { serviceId: '1', materials: [{ index: 2, partId: '3', quantity: '100000', max: 99999 }] })['materials.2.quantity']);
});

test('technical text explains filler and length errors before submitting, while drafts stay flexible', () => {
    for (const text of ['123456789012345678901234567890123', 'Se encontro '.repeat(2) + 'q'.repeat(20), 'panel '.repeat(6),
        'La falla corresponde a ' + 'asdf'.repeat(8), 'Falla ' + '!'.repeat(30), 'Falla ' + 'abcdefghijklmnopqrst'.repeat(2)]) {
        assert.notEqual(technicalTextError(text), '', text);
    }
    assert.match(technicalTextError('Corto'), /30 caracteres/);
    assert.match(technicalTextError('a'.repeat(2001), false), /2000 caracteres/);
    assert.equal(technicalTextError('q'.repeat(40), false), '');
    assert.equal(technicalTextError('Reparación de panel completada'), '');
    assert.equal(technicalTextError('Se revisó la válvula #4: 125 PSI, sin fugas. SUDU5353440.'), '');
});

test('next, direct stage clicks and keyboard shortcuts cannot skip invalid steps, but going back is allowed', () => {
    const previousDocument = globalThis.document;
    const hints = [];
    class Control extends EventTarget {
        dataset = {};
        attrs = new Map();
        setAttribute(key, value) { this.attrs.set(key, value); }
        getAttribute(key) { return this.attrs.get(key); }
        removeAttribute(key) { this.attrs.delete(key); }
        focus() {}
        setCustomValidity() {}
        insertAdjacentElement(_position, hint) { hints.push(hint); }
    }
    globalThis.document = { createElement: () => new Control(), querySelectorAll: () => hints };
    try {
        const form = new Control();
        const active = { value: '0' };
        const diagnosis = Object.assign(new Control(), { name: 'diagnosis', value: 'corto' });
        const work = Object.assign(new Control(), { name: 'work_performed', value: 'Se reparo el panel y se verifico sin fugas.' });
        const before = Object.assign(new Control(), { type: 'file', name: 'evidence_before[]', files: [] });
        const after = Object.assign(new Control(), { type: 'file', name: 'evidence_after[]', files: [] });
        const service = Object.assign(new Control(), { name: 'repair_service_id', value: '', tagName: 'SELECT', parentElement: { querySelector: () => null } });
        const fields = [[diagnosis, before], [service], [work, after]];
        const panels = fields.map((controls, index) => ({
            dataset: { maintenancePanel: String(index) }, contains: (field) => controls.includes(field),
            querySelector: (selector) => selector === 'input[type="file"]' ? controls.find((field) => field.type === 'file')
                : controls.find((field) => selector === `[name="${field.name}"]`),
            querySelectorAll: () => [],
        }));
        fields.forEach((controls, index) => controls.forEach((field) => {
            field.closest = (selector) => selector === '[data-maintenance-panel]' ? panels[index] : null;
        }));
        const navigate = (target, key) => {
            const event = new Event(key ? 'keydown' : 'click', { cancelable: true });
            Object.defineProperty(event, 'target', { value: target });
            if (key) Object.defineProperty(event, 'key', { value: key });
            form.dispatchEvent(event);
            if (!event.defaultPrevented && !key) active.value = target.dataset.maintenanceTab ?? String(Number(active.value) + 1);
            return event;
        };
        const tabs = [0, 1, 2].map((index) => {
            const tab = { dataset: { maintenanceTab: String(index) }, matches: () => true,
                closest: (selector) => selector === '[data-maintenance-tab]' ? tab : null, click: () => navigate(tab) };
            return tab;
        });
        form.elements = [...fields.flat()];
        form.elements.namedItem = () => active;
        form.prepend = () => {};
        form.querySelectorAll = (selector) => selector === '[data-maintenance-panel]' ? panels : [diagnosis, work];
        form.querySelector = (selector) => {
            if (selector.includes('submit')) return {};
            const index = Number(selector.match(/"(\d)"/)[1]);
            return selector.includes('tab=') ? tabs[index] : panels[index];
        };
        initializeTechnicalForm(form);
        for (const selector of ['[data-maintenance-next]', '[data-correction-next]']) {
            const next = { dataset: {}, closest: (requested) => requested.includes('next') ? next : null };
            assert.equal(navigate(next).defaultPrevented, true);
            assert.equal(active.value, '0');
        }
        diagnosis.value = 'Se encontro una fuga en el sello de la puerta.';
        assert.equal(navigate(tabs[2]).defaultPrevented, true);
        before.files = [{ type: 'image/jpeg', size: 1024 }];
        assert.equal(navigate(tabs[1]).defaultPrevented, false);
        assert.equal(active.value, '1');
        assert.equal(navigate(tabs[2], 'End').defaultPrevented, true);
        service.value = '1';
        assert.equal(navigate(tabs[2]).defaultPrevented, false);
        assert.equal(active.value, '2');
        diagnosis.value = 'corto';
        assert.equal(navigate(tabs[0]).defaultPrevented, false);
        assert.equal(active.value, '0');
        assert.equal(navigate(tabs[0], 'End').defaultPrevented, true);
        assert.equal(active.value, '0');
    } finally { globalThis.document = previousDocument; }
});

test('a server validation error returns field messages without consuming or changing selected photos', async () => {
    const body = new FormData();
    const photo = new File(['contenido foto'], 'evidencia.jpg', { type: 'image/jpeg', lastModified: 1 });
    body.append('photos[]', photo);
    body.set('_method', 'PATCH');
    body.set('action', 'submit');
    body.set('work_performed', 'Texto con errores');
    const result = await sendTechnicalForm('/reparaciones/1/correcciones', body, async (url, options) => {
        assert.equal(url, '/reparaciones/1/correcciones');
        assert.equal(options.body, body);
        assert.equal(options.headers.Accept, 'application/json');
        assert.equal(options.headers['Content-Type'], undefined);
        return { status: 422, json: async () => ({ errors: { work_performed: ['Revisa el texto.'] } }) };
    });
    assert.deepEqual(result, { errors: { work_performed: ['Revisa el texto.'] } });
    assert.equal(body.get('photos[]'), photo);
    assert.equal(body.get('work_performed'), 'Texto con errores');
    assert.equal(result.redirect, undefined);
});

test('only a successful redirected save navigates; expired sessions and failed requests keep the form', async () => {
    const body = new FormData();
    const success = await sendTechnicalForm('/save', body, async () => ({ ok: true, status: 200, redirected: true, url: 'http://localhost/saved' }));
    assert.deepEqual(success, { redirect: 'http://localhost/saved' });
    const jsonSuccess = await sendTechnicalForm('/save', body, async () => ({ ok: true, status: 200,
        headers: { get: () => 'application/json' }, json: async () => ({ redirect: '/saved' }) }));
    assert.deepEqual(jsonSuccess, { redirect: '/saved' });
    for (const response of [{ ok: false, status: 419 }, { ok: false, status: 500 },
        { ok: true, status: 200, redirected: true, url: 'http://localhost/login' }, { ok: true, status: 200, redirected: false }]) {
        const failure = await sendTechnicalForm('/save', body, async () => response);
        assert.ok(failure.errors.form.length > 0);
        assert.equal(failure.redirect, undefined);
    }
    await assert.rejects(sendTechnicalForm('/save', body, async () => { throw new Error('offline'); }), /offline/);
});

test('invalid text stays in the form and a button named action cannot replace the submission URL', async () => {
    const previousDocument = globalThis.document;
    const previousFetch = globalThis.fetch;
    const PreviousFormData = globalThis.FormData;
    const hints = [];
    class Control extends EventTarget {
        dataset = {};
        attrs = new Map();
        hidden = true;
        setAttribute(name, value) { this.attrs.set(name, value); }
        getAttribute(name) { return this.attrs.get(name); }
        removeAttribute(name) { this.attrs.delete(name); }
    }
    globalThis.document = { createElement: () => new Control(), querySelectorAll: () => hints };
    try {
        const form = new Control();
        form.setAttribute('action', '/reparaciones/1/correcciones');
        form.action = { toString: () => '[object RadioNodeList]' };
        const text = new Control();
        const photo = new File(['abc'], 'conservar.jpg');
        form.files = [photo];
        text.value = 'Texto con ' + 'q'.repeat(35);
        text.name = 'diagnosis';
        form.elements = [text];
        text.disabled = false;
        text.setCustomValidity = () => {};
        text.insertAdjacentElement = (position, hint) => { assert.equal(position, 'beforebegin'); hints.push(hint); };
        text.closest = () => ({ dataset: { maintenancePanel: '2' } });
        let focused = false;
        let step;
        text.focus = () => { focused = true; };
        form.querySelectorAll = () => [text];
        form.prepend = () => {};
        form.querySelector = () => ({ click: () => { step = 2; } });
        initializeTechnicalForm(form);
        let anotherSubmitHandlerRan = false;
        form.addEventListener('submit', () => { anotherSubmitHandlerRan = true; });
        const event = new Event('submit', { cancelable: true });
        Object.defineProperty(event, 'submitter', { value: { value: 'submit' } });
        form.dispatchEvent(event);
        assert.equal(event.defaultPrevented, true);
        assert.equal(anotherSubmitHandlerRan, false);
        assert.equal(step, 2);
        assert.equal(focused, true);
        assert.match(hints[0].textContent, /Evita repetir/);
        assert.equal(hints[0].hidden, false);
        assert.equal(hints[0].getAttribute('role'), 'alert');
        assert.equal(text.getAttribute('aria-invalid'), 'true');
        assert.equal(form.files[0], photo);
        assert.equal(text.value, 'Texto con ' + 'q'.repeat(35));
        text.value = 'Se cambio el sello y se verifico sin fugas.';
        text.dispatchEvent(new Event('input'));
        assert.equal(hints[0].hidden, true);
        assert.equal(text.getAttribute('aria-invalid'), undefined);
        globalThis.FormData = class extends PreviousFormData { constructor() { super(); this.append('photos[]', photo); } };
        let submittedUrl;
        globalThis.fetch = async (url, options) => {
            submittedUrl = url;
            assert.equal(options.body.get('action'), 'submit');
            assert.equal(options.body.get('photos[]'), photo);
            return { status: 422, json: async () => ({ errors: { diagnosis: ['Revisa el diagnóstico indicado.'] } }) };
        };
        const submit = new Event('submit', { cancelable: true });
        Object.defineProperty(submit, 'submitter', { value: { name: 'action', value: 'submit' } });
        form.dispatchEvent(submit);
        await new Promise(setImmediate);
        assert.equal(submittedUrl, '/reparaciones/1/correcciones');
        assert.equal(form.files[0], photo);
        assert.equal(form.inert, false);
        assert.equal(hints[0].textContent, 'Revisa el diagnóstico indicado.');
    } finally {
        globalThis.document = previousDocument;
        globalThis.fetch = previousFetch;
        globalThis.FormData = PreviousFormData;
    }
});
