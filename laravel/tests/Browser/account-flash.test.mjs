import assert from 'node:assert/strict';
import test from 'node:test';
import { initializeAccountFlash } from '../../resources/js/account-flash.js';

test('login success and error notices start their collapse after five seconds', () => {
    const timers = [];
    const notices = ['error', 'success'].map(() => ({
        dataset: {}, classes: new Set(), attributes: {},
        classList: { add(name) { this.owner.classes.add(name); } },
        setAttribute(name, value) { this.attributes[name] = value; },
    }));
    notices.forEach((notice) => { notice.classList.owner = notice; });
    const root = { querySelectorAll: () => notices };
    const delay = (callback, milliseconds) => timers.push({ callback, milliseconds });
    initializeAccountFlash(root, delay);
    initializeAccountFlash(root, delay);
    assert.equal(timers.length, 2);
    notices.forEach((notice) => assert.equal(notice.classes.size, 0));
    timers.forEach((timer) => {
        assert.equal(timer.milliseconds, 5000);
        timer.callback();
    });
    notices.forEach((notice) => {
        assert.equal(notice.classes.has('is-dismissed'), true);
        assert.equal(notice.attributes['aria-hidden'], 'true');
        assert.equal(notice.hidden, undefined);
    });
});
