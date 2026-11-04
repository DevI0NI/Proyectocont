import { technicalTextError } from './technical-form-validation.js';

export function recoveryReasonError(value) {
    if (Array.from(value.trim()).length < 60) return 'Explica el motivo con al menos 60 caracteres.';
    return technicalTextError(value, true, { min: 60, max: 150 });
}

export function initializeRecoveryDialog(root = document, frame = requestAnimationFrame, delay = setTimeout) {
    const dialog = root.querySelector('#accountRecoveryDialog');
    if (!dialog || dialog.dataset.animationReady) return;
    dialog.dataset.animationReady = 'true';
    const reason = root.querySelector('[data-recovery-reason]');
    const hint = root.querySelector('#recoveryReasonHint');
    if (reason && hint) {
        const validate = (show) => {
            const error = recoveryReasonError(reason.value);
            reason.setCustomValidity(error);
            reason.setAttribute('aria-invalid', String(show && Boolean(error)));
            hint.textContent = show ? error : '';
            hint.hidden = !hint.textContent;
        };
        reason.addEventListener('input', () => validate(reason.value.length > 0));
        reason.addEventListener('blur', () => validate(reason.value.length > 0));
        reason.addEventListener('invalid', (event) => {
            event.preventDefault();
            validate(true);
            reason.focus();
        });
        validate(false);
    }
    let closing = false;

    root.querySelector('[data-recovery-open]')?.addEventListener('click', () => {
        if (dialog.open || closing) return;
        dialog.classList.remove('is-visible');
        dialog.showModal();
        // Paint the initial state before starting the entrance transition.
        frame(() => frame(() => {
            if (dialog.open && !closing) dialog.classList.add('is-visible');
        }));
    });

    const close = () => {
        if (!dialog.open || closing) return;
        closing = true;
        dialog.classList.remove('is-visible');
        delay(() => {
            dialog.close();
            closing = false;
        }, 280);
    };
    root.querySelector('[data-recovery-close]')?.addEventListener('click', close);
    root.querySelector('[data-recovery-dismiss]')?.addEventListener('click', close);
    dialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        close();
    });
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => initializeRecoveryDialog());
    } else {
        initializeRecoveryDialog();
    }
}
