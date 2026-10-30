import assert from 'node:assert/strict';
import test from 'node:test';
import { formFingerprint, initializeUnsavedChanges, shouldGuardLink } from '../../resources/js/unsaved-changes.js';

function emit(target, type, values = {}) {
    const event = new Event(type, { cancelable: true });
    Object.entries(values).forEach(([key, value]) => Object.defineProperty(event, key, { value }));
    target.dispatchEvent(event);
    return event;
}

class Control extends EventTarget {
    dataset = {};
    focusCount = 0;
    focus() { this.focusCount++; }
}

function setup(restored = false) {
    const form = new Control();
    form.value = '';
    form.dataset.restoredChanges = String(restored);
    const dialog = new Control();
    const stay = new Control();
    const leave = new Control();
    dialog.open = false;
    dialog.querySelector = (selector) => selector === '[data-unsaved-stay]' ? stay : leave;
    dialog.showModal = () => { dialog.open = true; };
    dialog.close = () => { dialog.open = false; };
    const documentRef = new Control();
    const windowRef = new Control();
    const destinations = [];
    windowRef.location = { href: 'http://localhost/form', assign: (url) => destinations.push(url) };
    let observer;
    globalThis.MutationObserver = class {
        constructor(callback) { observer = callback; }
        observe() {}
    };
    initializeUnsavedChanges(form, dialog, { documentRef, windowRef, fingerprint: (value) => value.value });
    const link = { href: 'http://localhost/list', target: '', hasAttribute: () => false, closest() { return this; }, focus() {} };
    const navigate = () => emit(documentRef, 'click', { target: link, button: 0 });
    return { form, dialog, stay, leave, documentRef, windowRef, destinations, navigate, mutate: () => observer() };
}

test('unchanged and reverted forms leave freely; changed forms can continue or confirm leaving', () => {
    const state = setup();
    assert.equal(state.navigate().defaultPrevented, false);
    state.form.value = 'Observaciones nuevas';
    emit(state.form, 'input');
    assert.equal(state.navigate().defaultPrevented, true);
    assert.equal(state.dialog.open, true);
    emit(state.stay, 'click');
    assert.equal(state.dialog.open, false);
    assert.deepEqual(state.destinations, []);
    state.navigate();
    emit(state.leave, 'click');
    assert.deepEqual(state.destinations, ['http://localhost/list']);
    assert.equal(emit(state.windowRef, 'beforeunload').defaultPrevented, false);
    const reverted = setup();
    reverted.form.value = 'Temporal';
    emit(reverted.form, 'input');
    reverted.form.value = '';
    emit(reverted.form, 'input');
    assert.equal(reverted.navigate().defaultPrevented, false);
});

test('reload warns for changes, but valid saves bypass it and prevented submits do not', () => {
    const state = setup();
    state.form.value = 'Nuevo dato';
    emit(state.form, 'change');
    assert.equal(emit(state.windowRef, 'beforeunload').defaultPrevented, true);
    const invalid = new Event('submit', { cancelable: true });
    Object.defineProperty(invalid, 'target', { value: state.form });
    invalid.preventDefault();
    state.documentRef.dispatchEvent(invalid);
    assert.equal(emit(state.windowRef, 'beforeunload').defaultPrevented, true);
    emit(state.documentRef, 'submit', { target: state.form });
    assert.equal(emit(state.windowRef, 'beforeunload').defaultPrevented, false);
    emit(state.windowRef, 'pageshow', { persisted: true });
    assert.equal(emit(state.windowRef, 'beforeunload').defaultPrevented, true);
    emit(state.form, 'unsaved:submit');
    assert.equal(emit(state.windowRef, 'beforeunload').defaultPrevented, false);
});

test('restored drafts, material removal, Escape and branch or logout submits are protected', () => {
    const state = setup(true);
    assert.equal(state.navigate().defaultPrevented, true);
    emit(state.dialog, 'cancel');
    assert.equal(state.dialog.open, false);
    let submissions = 0;
    const otherForm = { requestSubmit: () => submissions++ };
    assert.equal(emit(state.documentRef, 'submit', { target: otherForm }).defaultPrevented, true);
    emit(state.leave, 'click');
    assert.equal(submissions, 1);
    const materials = setup();
    materials.form.value = 'Valores iniciales cargados';
    materials.mutate();
    assert.equal(materials.navigate().defaultPrevented, false);
    emit(materials.form, 'click');
    materials.form.value = 'Material eliminado';
    materials.mutate();
    assert.equal(materials.navigate().defaultPrevented, true);
});

test('photo previews, downloads, anchors and new tabs do not open the exit modal', () => {
    const link = { href: 'http://localhost/list', target: '', hasAttribute: () => false };
    const event = { button: 0 };
    assert.equal(shouldGuardLink(event, link, 'http://localhost/form'), true);
    for (const change of [{ target: '_blank' }, { href: 'blob:photo' }, { href: 'http://localhost/form#photos' }, { hasAttribute: () => true }]) {
        assert.equal(shouldGuardLink(event, { ...link, ...change }, 'http://localhost/form'), false);
    }
    assert.equal(shouldGuardLink({ button: 0, defaultPrevented: true }, link, 'http://localhost/form'), false);
    assert.equal(shouldGuardLink({ button: 0, ctrlKey: true }, link, 'http://localhost/form'), false);
});

test('changing the Livewire branch waits for confirmation before replaying the click', () => {
    const state = setup();
    state.form.value = 'Cambios pendientes';
    emit(state.form, 'input');
    let branchChanges = 0;
    const branch = { closest: () => branch, matches: () => true, getAttribute: () => 'false', focus() {}, click: () => branchChanges++ };
    assert.equal(emit(state.documentRef, 'click', { target: branch, button: 0 }).defaultPrevented, true);
    assert.equal(branchChanges, 0);
    emit(state.leave, 'click');
    assert.equal(branchChanges, 1);
});

test('the fingerprint includes photos and removals but excludes step and version controls', () => {
    const OriginalFormData = globalThis.FormData;
    globalThis.FormData = class { constructor(form) { return form.entries; } };
    try {
        const entries = [['notes', 'Texto'], ['active_step', '1'], ['version', '2'], ['photos[]', new File(['abc'], 'foto.jpg', { lastModified: 1 })]];
        const baseline = formFingerprint({ entries });
        assert.equal(formFingerprint({ entries: [...entries, ['audit_version', '3']] }), baseline);
        assert.notEqual(formFingerprint({ entries: [...entries, ['remove_photos[]', '0']] }), baseline);
        assert.notEqual(formFingerprint({ entries: entries.filter(([name]) => name !== 'photos[]') }), baseline);
    } finally { globalThis.FormData = OriginalFormData; }
});
