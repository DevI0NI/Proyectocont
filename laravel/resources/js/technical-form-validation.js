export function technicalTextError(value, final = true, { min = 30, max = 2000 } = {}) {
    const text = value.trim();
    if (Array.from(value).length > max) return `Utiliza como máximo ${max} caracteres.`;
    if (!final) return '';
    if (Array.from(text).length < min) return `Describe lo observado o realizado con al menos ${min} caracteres.`;
    if (!/\p{L}/u.test(text)) return 'Describe el problema o el trabajo con palabras, no solo números o símbolos.';
    if (/(\p{L})\1{7,}/iu.test(text) || /([^\p{L}\p{N}\s])\1{7,}/u.test(text)
        || /(\p{L}{2,6})\1{5,}/iu.test(text) || /(?<!\p{L})(\p{L}+)(?:\s+\1){4,}(?!\p{L})/iu.test(text)
        || /(?<!\p{L})(\p{L}+(?:\s+\p{L}+){1,7})(?:\s+\1){2,}(?!\p{L})/iu.test(text)) {
        return 'Evita repetir letras, palabras o secuencias para completar la descripción.';
    }
    if (/\p{N}{20,}|\p{L}{40,}/u.test(text)) return 'Revisa la descripción: contiene una secuencia de letras o números demasiado larga sin separar.';
    return '';
}

export function dispatchObservationsError(value) {
    return value.trim() ? technicalTextError(value, true, { min: 40, max: 150 }) : '';
}

if (typeof window !== 'undefined') window.dispatchObservationsError = dispatchObservationsError;

export function repairStepErrors(index, values) {
    const errors = {};
    const photoErrors = (field, photos) => {
        const count = photos.saved + photos.files.length;
        if (!count) errors[field] = ['Adjunta al menos una foto de esta etapa antes de continuar.'];
        else if (count > 5) errors[field] = ['Se permiten hasta 5 fotos por etapa.'];
        else if (photos.files.some((file) => !['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024)) {
            errors[field] = ['Usa fotos JPG, PNG o WEBP de máximo 2 MB cada una.'];
        }
    };
    if (index === 0 || index === 2) {
        const field = index === 0 ? 'diagnosis' : 'work_performed';
        const error = technicalTextError(values[field] || '');
        if (error) errors[field] = [error];
        photoErrors(values.photoField, values.photos);
    } else if (index === 1) {
        if (!values.serviceId) errors.repair_service_id = ['Selecciona el tipo de reparación antes de continuar.'];
        const selected = new Set();
        values.materials.forEach((material) => {
            const partField = `materials.${material.index}.part_id`;
            if (!material.partId) errors[partField] = ['Selecciona un material o elimina esta fila.'];
            else if (selected.has(material.partId)) errors[partField] = ['No repitas el material; ajusta la cantidad en una sola fila.'];
            selected.add(material.partId);
            if (!/^\d+(?:\.\d{1,3})?$/.test(material.quantity) || Number(material.quantity) < 0.001 || Number(material.quantity) > material.max) {
                errors[`materials.${material.index}.quantity`] = [`Usa una cantidad entre 0.001 y ${material.max}, con máximo 3 decimales.`];
            }
        });
    }
    return errors;
}

export async function sendTechnicalForm(url, body, fetchRequest = fetch) {
    const response = await fetchRequest(url, { method: 'POST', body, credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
    if (response.status === 422) {
        const result = await response.json();
        return { errors: result.errors || { form: [result.message || 'Revisa los datos antes de guardar.'] } };
    }
    if (!response.ok) return { errors: { form: [response.status === 419 ? 'La sesión venció. Tus datos siguen aquí; abre otra pestaña para iniciar sesión antes de reintentar.'
        : response.status === 409 ? 'Este trabajo cambió o ya fue enviado. Tus datos siguen aquí; comprueba el estado del trabajo antes de reintentar.'
            : `El servidor no pudo procesar el guardado (HTTP ${response.status}). Tus datos y fotos siguen aquí.`] } };
    if (response.headers?.get('Content-Type')?.includes('application/json')) {
        const result = await response.json();
        if (typeof result.redirect === 'string' && result.redirect) return { redirect: result.redirect };
        return { errors: { form: ['No se pudo confirmar el guardado. Tus datos y fotos siguen en el formulario.'] } };
    }
    if (!response.redirected || new URL(response.url).pathname === '/login') {
        return { errors: { form: ['No se pudo confirmar el guardado. Tus datos y fotos siguen en el formulario.'] } };
    }
    return { redirect: response.url };
}

export function initializeTechnicalForm(form) {
    const textFields = [...form.querySelectorAll('[data-maintenance-text], [data-correction-text], [data-audit-notes]')];
    const hints = new Map();
    let attempted = false;
    let busy = false;
    const isRepair = form.querySelectorAll('[data-maintenance-panel]').length === 3
        && Boolean(form.querySelector('[data-maintenance-submit], [data-correction-submit]'));
    form.noValidate = true;
    const summary = document.createElement('p');
    summary.className = 'technical-form-hint';
    summary.setAttribute('role', 'alert');
    summary.hidden = true;
    form.prepend(summary);
    const hintFor = (field) => {
        if (hints.has(field)) return hints.get(field);
        const hint = document.createElement('small');
        hint.className = 'technical-field-hint';
        hint.id = `technicalHint${document.querySelectorAll('.technical-field-hint').length}`;
        hint.setAttribute('role', 'alert');
        hint.hidden = true;
        field.insertAdjacentElement('beforebegin', hint);
        field.setAttribute('aria-describedby', [field.getAttribute('aria-describedby'), hint.id].filter(Boolean).join(' '));
        hints.set(field, hint);
        return hint;
    };
    const showFieldError = (field, message) => {
        const hint = hintFor(field);
        hint.textContent = message;
        hint.hidden = !message;
        if (message) field.setAttribute('aria-invalid', 'true');
        else field.removeAttribute('aria-invalid');
    };
    const focusField = (field) => {
        const panel = field.closest('[data-maintenance-panel]');
        if (panel) form.querySelector(`[data-maintenance-tab="${panel.dataset.maintenancePanel}"]`)?.click();
        if (field.type === 'file') field.closest('section')?.querySelector('button')?.focus();
        else if (field.tagName === 'SELECT') (field.parentElement.querySelector('.form-select-trigger') || field).focus();
        else field.focus();
        hints.get(field)?.scrollIntoView?.({ block: 'nearest', behavior: 'smooth' });
    };
    const showErrors = (errors) => {
        let first;
        const general = [];
        Object.entries(errors).forEach(([key, messages]) => {
            const alias = { work: 'work_performed', evidence: 'evidence_after' }[key] || key;
            const name = alias.replace(/\.([^.]+)/g, '[$1]');
            const field = [...form.elements].find((input) => input.name === name || input.name === `${name}[]`)
                || [...form.elements].find((input) => input.name === `${alias.split('.')[0]}[]`);
            const message = Array.isArray(messages) ? messages[0] : messages;
            if (field) { showFieldError(field, message); first ??= field; }
            else general.push(message);
        });
        summary.textContent = general.join(' ');
        summary.hidden = general.length === 0;
        if (first) focusField(first);
    };
    const stepErrors = (index) => {
        const panel = form.querySelector(`[data-maintenance-panel="${index}"]`);
        const input = panel.querySelector('input[type="file"]');
        const group = input?.closest('[data-correction-photo-group]');
        const quantityRows = [...panel.querySelectorAll('[data-material-row]')];
        return repairStepErrors(index, {
            diagnosis: panel.querySelector('[name="diagnosis"]')?.value,
            work_performed: panel.querySelector('[name="work_performed"]')?.value,
            photoField: input?.name.replace('[]', ''),
            photos: { saved: group ? [...group.querySelectorAll('[data-correction-photo-remove]')].filter((checkbox) => !checkbox.checked).length : Number(input?.dataset.existingCount || 0), files: [...(input?.files || [])] },
            serviceId: panel.querySelector('[name="repair_service_id"]')?.value,
            materials: quantityRows.map((row) => {
                const part = row.querySelector('select');
                const quantity = row.querySelector('input[type="number"]');
                return { index: part.name.match(/\[(\d+)\]/)[1], partId: part.value, quantity: quantity.value, max: Number(quantity.max || 100000) };
            }),
        });
    };
    const validateSteps = (start, end) => {
        attempted = true;
        summary.hidden = true;
        for (let index = start; index <= end; index++) {
            const panel = form.querySelector(`[data-maintenance-panel="${index}"]`);
            hints.forEach((_hint, field) => { if (panel.contains(field)) showFieldError(field, ''); });
            const errors = stepErrors(index);
            if (Object.keys(errors).length) { showErrors(errors); return false; }
        }
        return true;
    };
    if (isRepair) {
        const guardStep = (event, target) => {
            const current = Number(form.elements.namedItem('active_step').value);
            if (target <= current) return;
            if (!validateSteps(0, Math.min(1, target - 1))) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        };
        form.addEventListener('click', (event) => {
            const tab = event.target.closest('[data-maintenance-tab]');
            const next = event.target.closest('[data-maintenance-next], [data-correction-next]');
            if (tab || next) guardStep(event, tab ? Number(tab.dataset.maintenanceTab) : Number(form.elements.namedItem('active_step').value) + 1);
        }, true);
        form.addEventListener('keydown', (event) => {
            if (!event.target.matches('[data-maintenance-tab]') || !['ArrowRight', 'ArrowLeft', 'End', 'Home'].includes(event.key)) return;
            const index = Number(event.target.dataset.maintenanceTab);
            const target = event.key === 'ArrowRight' ? (index + 1) % 3 : event.key === 'ArrowLeft' ? (index + 2) % 3 : event.key === 'End' ? 2 : event.key === 'Home' ? 0 : index;
            guardStep(event, target);
        }, true);
    }
    textFields.forEach((field) => {
        hintFor(field);
        field.addEventListener('input', () => {
            field.setCustomValidity('');
            if (attempted) showFieldError(field, technicalTextError(field.value));
        });
    });
    form.addEventListener('input', (event) => {
        if (!textFields.includes(event.target) && hints.has(event.target)) showFieldError(event.target, '');
    });
    form.addEventListener('change', (event) => {
        if (!textFields.includes(event.target) && hints.has(event.target)) showFieldError(event.target, '');
    });
    form.addEventListener('submit', (event) => {
        if (busy) { event.preventDefault(); event.stopImmediatePropagation(); return; }
        const final = ['submit', 'finalize'].includes(event.submitter?.value);
        if (final && isRepair && !validateSteps(0, 2)) {
            event.preventDefault();
            event.stopImmediatePropagation();
            return;
        }
        attempted = final;
        let first;
        textFields.filter((field) => !field.disabled).forEach((field) => {
            field.setCustomValidity('');
            const message = technicalTextError(field.value, final);
            showFieldError(field, message);
            if (message) first ??= field;
        });
        if (first) {
            event.preventDefault();
            event.stopImmediatePropagation();
            focusField(first);
        }
    }, true);
    form.addEventListener('submit', async (event) => {
        if (event.defaultPrevented) return;
        event.preventDefault();
        const body = new FormData(form);
        if (event.submitter?.name) body.set(event.submitter.name, event.submitter.value);
        busy = true;
        form.inert = true;
        form.setAttribute('aria-busy', 'true');
        summary.hidden = true;
        hints.forEach((hint, field) => showFieldError(field, ''));
        delete form.dataset.submitting;
        form.dispatchEvent(new Event('technical:submission-failed'));
        try {
            const result = await sendTechnicalForm(form.getAttribute('action'), body);
            form.inert = false;
            if (result.errors) showErrors(result.errors);
            else {
                form.dispatchEvent(new Event('unsaved:submit'));
                location.assign(result.redirect);
            }
        } catch {
            form.inert = false;
            showErrors({ form: ['No se pudo confirmar el guardado. Tus datos y fotos siguen aquí; revisa la conexión antes de reintentar.'] });
        } finally {
            busy = false;
            form.inert = false;
            form.removeAttribute('aria-busy');
        }
    });
}

if (typeof document !== 'undefined') document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-maintenance-form], [data-correction-form], form[data-external-audit-form]').forEach(initializeTechnicalForm);
});
