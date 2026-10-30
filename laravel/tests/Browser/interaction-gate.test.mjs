import assert from 'node:assert/strict';
import test from 'node:test';
import { createInteractionGate } from '../../resources/js/interaction-gate.js';

test('rapid alternate selections are ignored throughout loading and cooldown', async () => {
    const states = [];
    let release;
    let unlock;
    let calls = 0;
    const gate = createInteractionGate((busy) => states.push(busy), {
        schedule: (callback, delay) => { assert.equal(delay, 800); unlock = callback; },
    });

    const first = gate.run(() => { calls++; return new Promise((resolve) => { release = resolve; }); });
    assert.equal(await gate.run(() => calls++), false);
    release();
    assert.equal(await first, true);
    assert.equal(await gate.run(() => calls++), false);
    assert.equal(calls, 1);
    assert.deepEqual(states, [true]);
    unlock();
    assert.equal(gate.busy, false);
    assert.equal(await gate.run(() => calls++), true);
    assert.equal(calls, 2);
    assert.deepEqual(states, [true, false, true]);
});

test('a failed preview releases its lock so another selection can retry', async () => {
    let unlock;
    const gate = createInteractionGate(() => {}, { schedule: (callback) => { unlock = callback; } });

    await assert.rejects(gate.run(() => { throw new Error('Preview failed'); }), /Preview failed/);
    assert.equal(gate.busy, true);
    unlock();
    assert.equal(await gate.run(() => {}), true);
});
