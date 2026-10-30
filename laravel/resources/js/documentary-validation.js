import { technicalTextError } from './technical-form-validation.js';

export function documentaryTextError(value, kind, required = false) {
    const text = value.trim();
    if (!text) return required ? 'Completa este campo.' : '';
    const max = { brand: 40, driver: 80, company: 100 }[kind];
    if (Array.from(value).length > max) return `Utiliza como maximo ${max} caracteres.`;
    const allowed = kind === 'driver' ? /^[\p{L}\p{M}\s.'\u2019-]+$/u : /^[\p{L}\p{M}\p{N}\s.,&()'\u2019/-]+$/u;
    if (!/\p{L}/u.test(text) || !allowed.test(text)) {
        if (!/\p{L}/u.test(text)) return { brand: 'La marca debe incluir letras.', driver: 'El nombre debe incluir letras.', company: 'La empresa debe incluir letras.' }[kind];
        return kind === 'driver' ? 'El nombre no admite numeros ni simbolos.' : 'Revisa los signos utilizados.';
    }
    const letters = (text.match(/\p{L}/gu) || []).length;
    const numbers = (text.match(/\p{N}/gu) || []).length;
    if (numbers > letters * 2) return 'Demasiados numeros para un nombre.';
    if (/\p{N}{12,}/u.test(text)) return 'La cadena de numeros es demasiado larga.';
    if (/qwerty|asdfgh|zxcvbn|qazwsx/iu.test(text)) return 'Evita las secuencias de teclado.';
    return technicalTextError(text, true, { min: 0, max }) ? 'Evita repeticiones o secuencias largas.' : '';
}

export function initializeDocumentaryFields(form) {
    form.querySelectorAll('textarea').forEach((field) => {
        if (!['observaciones_estado', 'observaciones_box'].includes(field.name)) return;
        const validate = () => field.setCustomValidity(observationLengthError(field.value));
        field.addEventListener('input', validate, true);
        validate();
    });
    const kinds = { marca: 'brand', conductor: 'driver', transportista: 'company' };
    form.querySelectorAll('input').forEach((field) => {
        const kind = kinds[field.name];
        if (!kind) return;
        const hint = document.createElement('small');
        hint.className = 'container-check-message documentary-check-message';
        hint.id = `${form.id}-${field.name}-check`;
        hint.setAttribute('aria-live', 'polite');
        field.insertAdjacentElement('afterend', hint);
        field.setAttribute('aria-describedby', [field.getAttribute('aria-describedby'), hint.id].filter(Boolean).join(' '));
        let messageTimer;
        const validate = (show = true) => {
            const message = documentaryTextError(field.value, kind, field.required);
            field.setCustomValidity(message);
            if (show) {
                clearTimeout(messageTimer);
                const state = message ? 'invalid' : field.value.trim() ? 'valid' : '';
                field.dataset.state = state;
                field.setAttribute('aria-invalid', String(Boolean(message)));
                hint.dataset.state = state;
                hint.textContent = message || (state ? 'Dato valido.' : '');
                hint.title = hint.textContent;
                hint.classList.toggle('is-visible', Boolean(state));
                if (state === 'valid') {
                    messageTimer = setTimeout(() => {
                        hint.classList.toggle('is-visible', false);
                        messageTimer = setTimeout(() => { hint.textContent = ''; }, 240);
                    }, 1600);
                }
            }
            return !message;
        };
        field.addEventListener('input', () => validate(), true);
        field.addEventListener('blur', () => validate());
        field.addEventListener('invalid', (event) => { event.preventDefault(); validate(); });
        validate(false);
    });
}

export function observationLengthError(value) {
    const length = Array.from(value.trim()).length;
    return length && length < 40 ? 'Escribe al menos 40 caracteres.' : '';
}

if (typeof document !== 'undefined') document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('#containerIngressForm, #containerEditForm, #dispatchForm').forEach(initializeDocumentaryFields);
});
