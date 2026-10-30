import assert from 'node:assert/strict';
import test from 'node:test';
import { documentaryTextError, initializeDocumentaryFields, observationLengthError } from '../../resources/js/documentary-validation.js';

test('unit and structure observations enforce 40 trimmed characters when not blank', () => {
    assert.equal(observationLengthError(''), '');
    assert.equal(observationLengthError('   '), '');
    assert.notEqual(observationLengthError('x'.repeat(39)), '');
    assert.notEqual(observationLengthError(' ' + 'x'.repeat(39) + ' '), '');
    assert.equal(observationLengthError('x'.repeat(40)), '');
});

test('documentary fields permit names and business punctuation but reject numeric and repetitive filler', () => {
    for (const [kind, value] of [['brand', '3M'], ['brand', 'CIMC 2024'], ['brand', 'Maersk'], ['driver', "María José O'Neill"],
        ['driver', 'Juan Pérez-Gómez'], ['company', 'Transportes del Caribe, S. A.'], ['company', 'Logistica & Servicios (GT) 2026']]) {
        assert.equal(documentaryTextError(value, kind, true), '', value);
    }
    for (const kind of ['brand', 'driver', 'company']) {
        for (const value of ['35426556565y', '3 5 4 2 6 5 5 6 5 6 5 y', '123456789', 'aaaaaaaaaaaa', 'qwertyuiop', 'asdfasdfasdfasdfasdfasdf', 'texto de relleno '.repeat(3)]) {
            assert.notEqual(documentaryTextError(value, kind, true), '', `${kind}: ${value}`);
        }
    }
    assert.notEqual(documentaryTextError('Piloto 123', 'driver'), '');
    assert.notEqual(documentaryTextError('Empresa 123456789012', 'company'), '');
    assert.notEqual(documentaryTextError('Marca !!!', 'brand'), '');
    assert.equal(documentaryTextError('', 'driver'), '');
    assert.notEqual(documentaryTextError('', 'brand', true), '');
});

test('valid feedback fades while keeping the green field, but new errors remain visible', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const previous = globalThis.document;
    let hint;
    globalThis.document = { createElement: () => {
        const visible = new Set();
        return { dataset: {}, setAttribute() {}, classList: { toggle: (name, enabled) => enabled ? visible.add(name) : visible.delete(name), contains: (name) => visible.has(name) } };
    } };
    try {
        const field = new EventTarget();
        Object.assign(field, { name: 'marca', value: '', required: true, dataset: {},
            setCustomValidity(message) { this.error = message; }, getAttribute() { return ''; }, setAttribute() {},
            insertAdjacentElement(position, element) { hint = element; } });
        initializeDocumentaryFields({ id: 'containerIngressForm', querySelectorAll: () => [field] });
        assert.equal(field.dataset.state, undefined);

        field.value = 'aaaaaaaaaaaa';
        field.dispatchEvent(new Event('input'));
        assert.equal(field.dataset.state, 'invalid');
        assert.equal(hint.dataset.state, 'invalid');
        assert.ok(field.error);
        assert.equal(hint.classList.contains('is-visible'), true);
        field.value = 'CIMC';
        field.dispatchEvent(new Event('input'));
        assert.equal(field.dataset.state, 'valid');
        assert.equal(hint.dataset.state, 'valid');
        assert.equal(field.error, '');
        t.mock.timers.tick(1600);
        assert.equal(hint.classList.contains('is-visible'), false);
        assert.equal(field.dataset.state, 'valid');
        t.mock.timers.tick(240);
        assert.equal(hint.textContent, '');
        field.dispatchEvent(new Event('blur'));
        assert.equal(hint.classList.contains('is-visible'), true);
        field.value = 'aaaaaaaaaaaa';
        field.dispatchEvent(new Event('input'));
        t.mock.timers.tick(2000);
        assert.equal(hint.classList.contains('is-visible'), true);
        assert.equal(hint.dataset.state, 'invalid');
        assert.ok(hint.textContent);
    } finally { globalThis.document = previous; }
});
