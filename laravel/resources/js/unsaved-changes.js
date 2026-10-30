export function shouldGuardLink(event, link, currentUrl) {
    if (event.defaultPrevented || !link || link.hasAttribute('download')
        || (link.target && link.target !== '_self') || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return false;
    const destination = new URL(link.href, currentUrl);
    const current = new URL(currentUrl);
    return ['http:', 'https:'].includes(destination.protocol)
        && !(destination.origin === current.origin && destination.pathname === current.pathname && destination.search === current.search && destination.hash);
}

export function formFingerprint(form) {
    return JSON.stringify([...new FormData(form)].filter(([name, value]) =>
        !['_token', '_method', 'version', 'audit_version', 'maintenance_version', 'active_step', 'action'].includes(name)
        && !(value instanceof File && !value.name)
    ).map(([name, value]) => [name, value instanceof File ? [value.name, value.size, value.lastModified] : value]));
}

export function initializeUnsavedChanges(form, dialog, { documentRef = document, windowRef = window, fingerprint = formFingerprint } = {}) {
    const stay = dialog.querySelector('[data-unsaved-stay]');
    const leave = dialog.querySelector('[data-unsaved-leave]');
    let baseline = fingerprint(form);
    let touched = form.dataset.restoredChanges === 'true';
    let dirty = touched;
    let submitting = false;
    let proceed;
    let trigger;
    const update = () => {
        dirty = form.dataset.restoredChanges === 'true' || fingerprint(form) !== baseline;
    };
    const close = () => { dialog.close(); proceed = null; trigger?.focus(); };
    const ask = (action, source) => {
        proceed = action;
        trigger = source;
        if (!dialog.open) dialog.showModal();
        stay.focus();
    };
    form.addEventListener('input', () => { touched = true; update(); });
    form.addEventListener('change', () => { touched = true; update(); });
    form.addEventListener('click', () => { touched = true; });
    const observer = new MutationObserver(() => {
        if (!touched) baseline = fingerprint(form);
        update();
    });
    observer.observe(form, { childList: true, subtree: true });
    documentRef.addEventListener('click', (event) => {
        const branch = event.target.closest('[data-livewire-branch]');
        if (submitting || !branch?.matches?.('[data-livewire-branch]') || branch.getAttribute('aria-selected') === 'true') return;
        update();
        if (!dirty) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        ask(() => branch.click(), branch);
    }, true);
    documentRef.addEventListener('click', (event) => {
        const link = event.target.closest('a[href]');
        if (submitting || !shouldGuardLink(event, link, windowRef.location.href)) return;
        update();
        if (!dirty) return;
        event.preventDefault();
        ask(() => windowRef.location.assign(link.href), link);
    });
    documentRef.addEventListener('submit', (event) => {
        if (event.defaultPrevented) return;
        if (event.target === form) { submitting = true; return; }
        if (submitting) return;
        update();
        if (!dirty) return;
        event.preventDefault();
        const otherForm = event.target;
        const submitter = event.submitter;
        ask(() => otherForm.requestSubmit(submitter || undefined), submitter);
    });
    form.addEventListener('unsaved:submit', () => { submitting = true; });
    windowRef.addEventListener('beforeunload', (event) => {
        update();
        if (!dirty || submitting) return;
        event.preventDefault();
        event.returnValue = '';
    });
    windowRef.addEventListener('pageshow', (event) => {
        if (event.persisted) { submitting = false; update(); }
    });
    stay.addEventListener('click', close);
    leave.addEventListener('click', () => {
        const action = proceed;
        if (!action || submitting) return;
        submitting = true;
        close();
        action();
    });
    dialog.addEventListener('cancel', (event) => { event.preventDefault(); close(); });
    dialog.addEventListener('click', (event) => {
        if (event.target !== dialog) return;
        const bounds = dialog.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) close();
    });
}

if (typeof document !== 'undefined') document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-unsaved-form]').forEach((form) => {
        const dialog = document.getElementById(form.dataset.unsavedForm);
        if (dialog) initializeUnsavedChanges(form, dialog);
    });
});
