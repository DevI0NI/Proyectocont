import assert from 'node:assert/strict';
import test from 'node:test';
import { initializeRecoveryDialog, recoveryReasonError } from '../../resources/js/account-recovery-dialog.js';

test('recovery reason requires 60 to 150 characters and rejects repetitive filler', () => {
    assert.match(recoveryReasonError('Olvidé mi contraseña.'), /60/);
    assert.match(recoveryReasonError('a'.repeat(60)), /repetir/);
    assert.notEqual(recoveryReasonError('1234567890'.repeat(6)), '');
    assert.match(recoveryReasonError('abc'.repeat(20)), /repetir/);
    assert.match(recoveryReasonError('clave '.repeat(12)), /repetir/);
    assert.match(recoveryReasonError('a'.repeat(151)), /150/);
    assert.equal(recoveryReasonError('No recuerdo mi contraseña y necesito recuperar el acceso para trabajar.'), '');
});

test('recovery dialog paints before animating, prevents duplicate opens, and animates cancel and Escape', () => {
    const frames = [];
    const timers = [];
    const events = {};
    const classes = new Set();
    let opens = 0;
    const dialog = { dataset: {}, open: false,
        classList: { add: (name) => classes.add(name), remove: (name) => classes.delete(name) },
        showModal() { this.open = true; opens++; }, close() { this.open = false; },
        addEventListener: (name, fn) => { events[name] = fn; } };
    const trigger = { addEventListener: (_name, fn) => { events.open = fn; } };
    const cancel = { addEventListener: (_name, fn) => { events.close = fn; } };
    const root = { querySelector: (selector) => ({ '#accountRecoveryDialog': dialog, '[data-recovery-open]': trigger, '[data-recovery-close]': cancel })[selector] };
    initializeRecoveryDialog(root, (fn) => frames.push(fn), (fn) => timers.push(fn));
    events.open();
    events.open();
    assert.equal(opens, 1);
    assert.equal(classes.has('is-visible'), false);
    frames.shift()();
    assert.equal(classes.has('is-visible'), false);
    frames.shift()();
    assert.equal(classes.has('is-visible'), true);
    events.close();
    assert.equal(dialog.open, true);
    assert.equal(classes.has('is-visible'), false);
    timers.shift()();
    assert.equal(dialog.open, false);
    events.open();
    frames.shift()();
    frames.shift()();
    let prevented = false;
    events.cancel({ preventDefault: () => { prevented = true; } });
    assert.equal(prevented, true);
    timers.shift()();
    assert.equal(dialog.open, false);
    assert.equal(opens, 2);
});
