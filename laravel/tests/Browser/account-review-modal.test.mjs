import assert from 'node:assert/strict';
import test from 'node:test';
import { accountReviewModal } from '../../resources/js/account-review-modal.js';

function setup(reduced = false) {
    const animations = [];
    let completeRequest;
    let calls = 0;
    let closes = 0;
    const wire = {
        recoveryModalOpen: false,
        openRecovery() { calls++; return new Promise((resolve) => { completeRequest = resolve; }); },
        closeRecovery() { closes++; this.recoveryModalOpen = false; return Promise.resolve(); },
    };
    const dialog = {
        open: false,
        showModal() { this.open = true; },
        close() { this.open = false; },
        getBoundingClientRect: () => ({ left: 100, right: 600, top: 100, bottom: 500 }),
        animate(frames, options) {
            let finish;
            const animation = { frames, options, finished: new Promise((resolve) => { finish = resolve; }), cancel() {}, finish: () => finish() };
            animations.push(animation);
            return animation;
        },
    };
    const state = accountReviewModal(wire, () => reduced);
    state.$el = dialog;
    state.init();
    return { state, wire, dialog, animations, complete: () => { wire.recoveryModalOpen = true; completeRequest(); }, calls: () => calls, closes: () => closes };
}

test('review modal opens immediately while authorization loads and avoids repeated requests', async () => {
    const context = setup();
    const pending = context.state.open(12);
    assert.equal(context.dialog.open, true);
    assert.equal(context.state.loading, true);
    await context.state.open(12);
    assert.equal(context.calls(), 1);
    context.complete();
    await pending;
    assert.equal(context.state.loading, false);
    context.state.$el = { tagName: 'BUTTON' };
    const closing = context.state.close();
    assert.equal(context.dialog.open, true);
    assert.equal(context.closes(), 0);
    context.animations.at(-1).finish();
    await closing;
    assert.equal(context.dialog.open, false);
    assert.equal(context.closes(), 1);
});

test('outside clicks exclude dialog padding and inside controls, and late responses do not reopen a dismissed dialog', async () => {
    const context = setup();
    const pending = context.state.open(12);
    assert.equal(context.state.isOutside({ target: context.dialog, clientX: 50, clientY: 150 }), true);
    assert.equal(context.state.isOutside({ target: context.dialog, clientX: 110, clientY: 150 }), false);
    assert.equal(context.state.isOutside({ target: {}, clientX: 50, clientY: 150 }), false);
    const closing = context.state.close();
    context.animations.at(-1).finish();
    await closing;
    context.complete();
    await pending;
    context.state.sync(true);
    assert.equal(context.dialog.open, false);
    assert.equal(context.state.requested, false);
});

test('review completion animates the close and reduced motion uses only opacity', async () => {
    const context = setup(true);
    context.wire.recoveryModalOpen = true;
    context.state.requested = true;
    context.state.sync(true);
    assert.equal(context.animations[0].frames[0].transform, 'none');
    context.state.sync(false);
    assert.equal(context.dialog.open, true);
    assert.equal(context.animations.at(-1).frames[1].transform, 'none');
    context.animations.at(-1).finish();
    await Promise.resolve();
    assert.equal(context.dialog.open, false);
    assert.equal(context.closes(), 0);
});
