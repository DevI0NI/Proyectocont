export function accountPasswordChecks(value) {
    return {
        length: [...value].length >= 8 && [...value].length <= 64,
        uppercase: /[A-Z]/.test(value),
        number: /[0-9]/.test(value),
        symbol: /[\p{P}\p{S}]/u.test(value),
    };
}

export function initializeAccountPassword(root = document) {
    const input = root.querySelector('[data-account-password]');
    if (!input || input.dataset.checklistReady) return;
    input.dataset.checklistReady = 'true';
    const help = root.querySelector('#accountPasswordHelp');
    const confirmation = root.querySelector('[data-password-confirmation]');
    const hint = root.querySelector('#passwordMatchHint');
    let focused = root.activeElement === input;

    const updateConfirmation = () => {
        if (!confirmation || !hint) return;
        const entered = confirmation.value.length > 0;
        const matches = entered && input.value === confirmation.value;
        confirmation.setCustomValidity(entered && !matches ? 'Las contraseñas no coinciden.' : '');
        confirmation.setAttribute('aria-invalid', String(entered && !matches));
        hint.hidden = !entered;
        hint.textContent = entered ? (matches ? 'Las contraseñas coinciden.' : 'Las contraseñas no coinciden.') : '';
        hint.classList.toggle('is-valid', matches);
    };

    const update = () => {
        const visible = focused && input.value.length > 0;
        help?.classList.toggle('is-visible', visible);
        help?.setAttribute('aria-hidden', String(!visible));
        const checks = accountPasswordChecks(input.value);
        root.querySelectorAll('[data-account-password-rule]').forEach((item) => {
            const met = checks[item.dataset.accountPasswordRule] === true;
            item.classList.toggle('met', met);
            item.querySelector('input').checked = met;
        });
        updateConfirmation();
    };
    input.addEventListener('focus', () => { focused = true; update(); });
    input.addEventListener('blur', () => { focused = false; update(); });
    input.addEventListener('input', update);
    input.addEventListener('change', update);
    root.querySelector('#togglePassword')?.addEventListener('pointerdown', (event) => {
        event.preventDefault();
    });
    root.querySelector('#togglePassword')?.addEventListener('click', () => {
        input.focus();
    });
    confirmation?.addEventListener('input', updateConfirmation);
    confirmation?.addEventListener('change', updateConfirmation);
    update();
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => initializeAccountPassword());
    } else {
        initializeAccountPassword();
    }
    window.addEventListener('pageshow', () => {
        document.querySelector('[data-account-password]')?.dispatchEvent(new Event('input'));
    });
}
