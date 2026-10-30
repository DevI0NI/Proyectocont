import assert from 'node:assert/strict';
import test from 'node:test';
import { accountPasswordChecks, initializeAccountPassword } from '../../resources/js/account-password.js';

test('password checklist follows the server policy and clears requirements when text is removed', () => {
    assert.deepEqual(accountPasswordChecks(''), { length: false, uppercase: false, number: false, symbol: false });
    assert.deepEqual(accountPasswordChecks('Segura12!'), { length: true, uppercase: true, number: true, symbol: true });
    assert.equal(accountPasswordChecks('segura12!').uppercase, false);
    assert.equal(accountPasswordChecks('Seguraaa!').number, false);
    assert.equal(accountPasswordChecks('Segura123').symbol, false);
    assert.equal(accountPasswordChecks('Ab1!').length, false);
    assert.equal(accountPasswordChecks('A1!' + 'x'.repeat(62)).length, false);
    assert.equal(accountPasswordChecks('A1!' + 'x'.repeat(61)).length, true);
    assert.equal(accountPasswordChecks('Segura12€').symbol, true);
});

test('typing and autofill changes update the existing checkboxes without adding layout elements', () => {
    const listeners = {};
    const input = { value: '', dataset: {}, addEventListener: (name, fn) => { listeners[name] = fn; } };
    const rows = ['length', 'uppercase', 'number', 'symbol'].map((rule) => {
        const checkbox = { checked: false };
        return { dataset: { accountPasswordRule: rule }, checkbox,
            classList: { toggle: (_name, value) => { checkbox.highlighted = value; } },
            querySelector: () => checkbox };
    });
    let visible = false;
    const help = { classList: { toggle: (_name, value) => { visible = value; } }, setAttribute() {} };
    const root = { querySelector: (selector) => ({ '#accountPasswordHelp': help, '[data-account-password]': input })[selector], querySelectorAll: () => rows };
    initializeAccountPassword(root);
    assert.equal(visible, false);
    assert.ok(rows.every((row) => !row.checkbox.checked));
    input.value = 'Segura12!';
    listeners.focus();
    listeners.input();
    assert.equal(visible, true);
    assert.ok(rows.every((row) => row.checkbox.checked && row.checkbox.highlighted));
    listeners.blur();
    assert.equal(visible, false);
    listeners.focus();
    assert.equal(visible, true);
    input.value = '';
    listeners.change();
    assert.equal(visible, false);
    assert.ok(rows.every((row) => !row.checkbox.checked && !row.checkbox.highlighted));
    initializeAccountPassword(root);
    assert.equal(Object.keys(listeners).length, 4);
});

test('confirmation validates in real time and rechecks when the original password changes', () => {
    const primaryEvents = {};
    const confirmEvents = {};
    const input = { value: 'Segura12!', dataset: {}, addEventListener: (name, fn) => { primaryEvents[name] = fn; } };
    let error = '';
    const confirmation = { value: '', setCustomValidity: (value) => { error = value; }, setAttribute() {}, addEventListener: (name, fn) => { confirmEvents[name] = fn; } };
    let valid = false;
    const hint = { classList: { toggle: (_name, value) => { valid = value; } } };
    const root = { querySelector: (selector) => ({ '[data-account-password]': input, '[data-password-confirmation]': confirmation, '#passwordMatchHint': hint })[selector], querySelectorAll: () => [] };
    initializeAccountPassword(root);
    assert.equal(hint.hidden, true);
    confirmation.value = 'Distinta12!';
    confirmEvents.input();
    assert.equal(error, 'Las contraseñas no coinciden.');
    assert.equal(valid, false);
    confirmation.value = input.value;
    confirmEvents.input();
    assert.equal(error, '');
    assert.equal(valid, true);
    assert.equal(hint.textContent, 'Las contraseñas coinciden.');
    input.value = 'Nueva12!';
    primaryEvents.input();
    assert.equal(error, 'Las contraseñas no coinciden.');
});

test('the visibility toggle keeps focus in the password field instead of collapsing its checklist', () => {
    const events = {};
    const toggleEvents = {};
    let visible = false;
    const input = { value: 'Segura12!', dataset: {},
        addEventListener: (name, fn) => { events[name] = fn; }, focus: () => events.focus() };
    const toggle = { addEventListener: (name, fn) => { toggleEvents[name] = fn; } };
    const help = { classList: { toggle: (_name, value) => { visible = value; } }, setAttribute() {} };
    const root = { querySelector: (selector) => ({ '[data-account-password]': input, '#togglePassword': toggle, '#accountPasswordHelp': help })[selector], querySelectorAll: () => [] };
    initializeAccountPassword(root);
    events.focus();
    let prevented = false;
    toggleEvents.pointerdown({ preventDefault: () => { prevented = true; } });
    toggleEvents.click();
    assert.equal(prevented, true);
    assert.equal(visible, true);
});
