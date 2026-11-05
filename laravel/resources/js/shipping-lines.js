import { open, close, notify, initializeNotifications } from './action-feedback';
import { initializeRecordSelects } from './branch-geography';

const feedbackTimers = new WeakMap();
const selectionTransitions = new WeakMap();

function selectEditorTab(form, name, focus = false) {
    if (!form?.querySelector('[data-shipping-line-tab="' + name + '"]')) return;
    form.querySelectorAll('[data-relation-trigger][aria-expanded=true]').forEach((trigger) => closeSelection(trigger.closest('[data-relation-selection]')));
    form.querySelectorAll('[data-shipping-line-tab]').forEach((tab) => {
        const selected = tab.dataset.shippingLineTab === name;
        tab.setAttribute('aria-selected', String(selected));
        tab.tabIndex = selected ? 0 : -1;
        if (selected && focus) tab.focus();
    });
    form.querySelectorAll('[data-shipping-line-panel]').forEach((panel) => {
        const selected = panel.dataset.shippingLinePanel === name;
        const wasHidden = panel.hidden;
        panel.hidden = !selected;
        if (selected && wasHidden && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
            panel.animate([{ opacity:0, transform:'translateY(10px)' }, { opacity:1, transform:'translateY(0)' }], { duration:260, easing:'cubic-bezier(.2,.8,.2,1)' });
        }
    });
}

function revealEditorField(input) {
    const panel = input?.closest('[data-shipping-line-panel]');
    if (panel?.hidden) selectEditorTab(input.form, panel.dataset.shippingLinePanel);
}

function fieldFeedback(input, output, error, showSuccess = true) {
    clearTimeout(feedbackTimers.get(output));
    output.classList.remove('feedback-hiding');
    input.setCustomValidity(error);
    if (error) input.setAttribute('aria-invalid', 'true');
    else input.removeAttribute('aria-invalid');
    output.hidden = !error && (!input.value || !showSuccess);
    output.textContent = error || (input.value ? 'Formato valido.' : '');
    output.dataset.state = error ? 'error' : 'success';
    if (!output.hidden && !error) {
        feedbackTimers.set(output, setTimeout(() => {
            output.classList.add('feedback-hiding');
            feedbackTimers.set(output, setTimeout(() => {
                output.hidden = true;
                output.classList.remove('feedback-hiding');
            }, 240));
        }, 1600));
    }
    return !error;
}

function validateEmail(input) {
    const valid = !input.value || (!input.validity.typeMismatch && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(input.value) && input.value.length <= 150);
    return fieldFeedback(input, input.closest('label').querySelector('[data-email-feedback]'), valid ? '' : 'Ingresa un correo valido, por ejemplo contacto@empresa.com.');
}

function validatePhone(input) {
    const field = input.closest('.shipping-line-phone-field');
    let error = '';
    if (input.value && !/^[0-9]{8}$/.test(input.value)) error = 'El telefono debe tener exactamente 8 digitos.';
    return fieldFeedback(input, field.querySelector('[data-phone-feedback]'), error);
}

function updateSelection(selection) {
    const query = selection.querySelector('[data-relation-search]').value.trim().toLocaleLowerCase();
    let visible = 0;
    selection.querySelectorAll('[data-relation-choice]').forEach((choice) => {
        choice.hidden = choice.dataset.relationAllowed === 'false' || !choice.textContent.toLocaleLowerCase().includes(query);
        if (!choice.hidden) visible++;
    });
    const selected = Array.from(selection.querySelectorAll('input[type=checkbox]:checked'));
    selection.querySelector('[data-relation-count]').textContent = selected.length + ' seleccionadas';
    const trigger = selection.querySelector('[data-relation-trigger]');
    const names = selected.map((input) => input.closest('label').querySelector('span').textContent.trim());
    const summary = names.length ? names.join(', ') : trigger.dataset.placeholder;
    trigger.querySelector('[data-relation-summary]').textContent = summary;
    trigger.title = summary;
    const authorizationCount = selection.querySelector('[data-authorization-count]');
    if (authorizationCount) {
        authorizationCount.textContent = selected.length ? selected.length + (selected.length === 1 ? ' auditora asignada' : ' auditoras asignadas') : 'Sin asignar';
        selection.dataset.assigned = String(selected.length > 0);
    }
    selection.querySelector('[data-relation-empty]').hidden = visible > 0;
}

function updateAuthorizationBranches(form) {
    const selected = new Set(Array.from(form.querySelectorAll('[name="branches[]"]:checked'), (input) => input.value));
    form.querySelectorAll('[data-authorization-branch]').forEach((selection) => {
        const visible = selected.has(selection.dataset.authorizationBranch);
        if (!visible && selection.querySelector('[data-relation-trigger]').getAttribute('aria-expanded') === 'true') closeSelection(selection);
        selection.hidden = !visible;
        selection.querySelectorAll('input[type=checkbox]').forEach((input) => { input.disabled = !visible || input.dataset.authorizationAllowed === 'false'; });
    });
    const empty = form.querySelector('[data-authorization-empty]');
    if (empty) empty.hidden = selected.size > 0;
}

function closeSelection(selection, focus = false) {
    const trigger = selection.querySelector('[data-relation-trigger]');
    const menu = selection.querySelector('.shipping-line-select-menu');
    if (trigger.getAttribute('aria-expanded') !== 'true') return;
    const previous = selectionTransitions.get(menu);
    if (previous?.frame) cancelAnimationFrame(previous.frame);
    clearTimeout(previous?.timer);
    trigger.setAttribute('aria-expanded', 'false');
    menu.inert = true;
    menu.classList.remove('is-open');
    const state = {
        timer:setTimeout(() => {
            if (selectionTransitions.get(menu) !== state) return;
            menu.hidePopover();
            selectionTransitions.delete(menu);
        }, 180),
    };
    selectionTransitions.set(menu, state);
    if (focus) trigger.focus();
}

function positionSelection(selection) {
    const trigger = selection.querySelector('[data-relation-trigger]');
    const menu = selection.querySelector('.shipping-line-select-menu');
    const rect = trigger.getBoundingClientRect();
    const width = Math.min(rect.width, innerWidth - 24);
    menu.style.width = width + 'px';
    menu.style.left = Math.max(12, Math.min(rect.left, innerWidth - width - 12)) + 'px';
    menu.style.maxHeight = 'none';
    const height = Math.min(menu.offsetHeight, innerHeight - 24);
    const below = innerHeight - rect.bottom - 12;
    const above = rect.top - 12;
    const upward = below < height && above > below;
    menu.dataset.opensUp = String(upward);
    menu.style.transformOrigin = 'top center';
    menu.style.maxHeight = Math.max(100, upward ? above - 6 : below - 6) + 'px';
    menu.style.top = (upward ? Math.max(12, rect.top - Math.min(height, above - 6) - 6) : rect.bottom + 6) + 'px';
}

function openSelection(selection) {
    document.querySelectorAll('[data-relation-trigger][aria-expanded=true]').forEach((trigger) => closeSelection(trigger.closest('[data-relation-selection]')));
    const trigger = selection.querySelector('[data-relation-trigger]');
    const menu = selection.querySelector('.shipping-line-select-menu');
    const previous = selectionTransitions.get(menu);
    if (previous?.frame) cancelAnimationFrame(previous.frame);
    clearTimeout(previous?.timer);
    menu.inert = false;
    menu.showPopover();
    positionSelection(selection);
    trigger.setAttribute('aria-expanded', 'true');
    const state = {};
    selectionTransitions.set(menu, state);
    state.frame = requestAnimationFrame(() => {
        state.frame = requestAnimationFrame(() => {
            if (selectionTransitions.get(menu) !== state) return;
            state.frame = null;
            menu.classList.add('is-open');
            if (!matchMedia('(pointer: coarse)').matches) selection.querySelector('[data-relation-search]').focus({ preventScroll:true });
        });
    });
}

document.addEventListener('keydown', (event) => {
    const tab = event.target.closest('[data-shipping-line-tab]');
    if (tab && ['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
        event.preventDefault();
        if (tab.form.hasAttribute('aria-busy')) return;
        const tabs = Array.from(tab.form.querySelectorAll('[data-shipping-line-tab]'));
        const index = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (tabs.indexOf(tab) + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
        selectEditorTab(tab.form, tabs[index].dataset.shippingLineTab, true);
    }
    if (event.key === 'Escape') {
        const trigger = document.querySelector('[data-relation-trigger][aria-expanded=true]');
        if (trigger) { event.preventDefault(); closeSelection(trigger.closest('[data-relation-selection]'), true); }
    }
});
document.addEventListener('focusin', (event) => {
    document.querySelectorAll('[data-relation-trigger][aria-expanded=true]').forEach((trigger) => {
        const selection = trigger.closest('[data-relation-selection]');
        if (!selection.contains(event.target)) closeSelection(selection);
    });
});
window.addEventListener('resize', () => document.querySelectorAll('[data-relation-trigger][aria-expanded=true]').forEach((trigger) => positionSelection(trigger.closest('[data-relation-selection]'))));
document.addEventListener('scroll', (event) => {
    if (event.target instanceof Element && event.target.closest('.shipping-line-select-menu')) return;
    document.querySelectorAll('[data-relation-trigger][aria-expanded=true]').forEach((trigger) => positionSelection(trigger.closest('[data-relation-selection]')));
}, true);

function initialize() {
    if (!document.querySelector('[data-shipping-lines]')) return;
    initializeNotifications('[data-shipping-line-errors]');
    initializeRecordSelects();
    document.querySelectorAll('[data-relation-selection]').forEach(updateSelection);
    document.querySelectorAll('[data-shipping-line-form]').forEach(updateAuthorizationBranches);
    const dialog = document.querySelector('#shippingLineStatusDialog');
    dialog?.addEventListener('cancel', (event) => {
        event.preventDefault();
        if (!dialog.querySelector('[aria-busy=true]')) close(dialog);
    });
}
document.addEventListener('DOMContentLoaded', initialize);
document.addEventListener('administration:updated', initialize);

document.addEventListener('paste', (event) => {
    if (event.target.matches('[data-nonnegative-cost]')) {
        const pasted = event.clipboardData.getData('text').trim();
        if (pasted && !/^\d+(?:\.\d{0,2})?$/.test(pasted)) {
            event.preventDefault();
        }
        return;
    }
    if (!event.target.matches('[data-shipping-line-phone]')) return;
    event.preventDefault();
    const input = event.target;
    const pasted = event.clipboardData.getData('text').replace(/[^0-9]/g, '');
    const start = input.selectionStart ?? input.value.length;
    const end = input.selectionEnd ?? start;
    input.value = (input.value.slice(0, start) + pasted + input.value.slice(end)).slice(0, 8);
    const position = Math.min(start + pasted.length, 8);
    input.setSelectionRange(position, position);
    input.dispatchEvent(new Event('input', { bubbles:true }));
});
document.addEventListener('input', (event) => {
    if (event.target.matches('[data-nonnegative-cost]') && Number(event.target.value) < 0) {
        event.target.value = '';
    }
    if (event.target.matches('[data-iso-code]')) {
        const input = event.target;
        input.value = input.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 4);
        fieldFeedback(input, input.closest('label').querySelector('[data-field-feedback]'), input.value && input.value.length !== 4 ? 'El codigo ISO debe tener 4 caracteres.' : '');
    }
    if (event.target.matches('[data-shipping-line-email]')) validateEmail(event.target);
    if (event.target.matches('[data-shipping-line-phone]')) {
        const input = event.target;
        const original = input.value;
        const cursor = input.selectionStart;
        const clean = (value) => {
            return value.replace(/[^0-9]/g, '').slice(0, 8);
        };
        input.value = clean(original);
        if (cursor !== null) {
            const position = clean(original.slice(0, cursor)).length;
            input.setSelectionRange(position, position);
        }
        validatePhone(input);
    }
    const selection = event.target.closest('[data-relation-selection]');
    if (selection) updateSelection(selection);
});
document.addEventListener('keydown', (event) => {
    if (event.target.matches('[data-nonnegative-cost]') && !event.ctrlKey && !event.metaKey && ['-', '+', 'e', 'E'].includes(event.key)) {
        event.preventDefault();
    }
});
document.addEventListener('beforeinput', (event) => {
    if (event.target.matches('[data-nonnegative-cost]') && event.data && /[-+eE]/.test(event.data)) {
        event.preventDefault();
    }
});
document.addEventListener('change', (event) => {
    if (event.target.matches('[name="branches[]"]')) updateAuthorizationBranches(event.target.form);
    if (event.target.closest('[data-catalog-item-form]') && event.target.validity?.valid) {
        event.target.removeAttribute('aria-invalid');
    }
    if (event.target.matches('[data-iso-preset]')) {
        const form = event.target.form;
        const preset = JSON.parse(form.querySelector('#isoPresetsData').textContent)[event.target.value];
        if (preset) {
            form.querySelector('[data-iso-name]').textContent = preset.name;
            form.querySelector('[data-iso-type]').textContent = preset.type_name;
            form.querySelector('[data-iso-size]').textContent = [preset.length_mm, preset.width_mm, preset.height_mm].map((value) => (value / 1000).toFixed(3)).join(' × ') + ' m';
        }
    }
    if (event.target.matches('[name=phone_country]')) {
        validatePhone(event.target.closest('.shipping-line-phone-field').querySelector('[data-shipping-line-phone]'));
    }
});
document.addEventListener('click', (event) => {
    const tab = event.target.closest('[data-shipping-line-tab]');
    if (tab && !tab.form.hasAttribute('aria-busy')) selectEditorTab(tab.form, tab.dataset.shippingLineTab);
    const relationTrigger = event.target.closest('[data-relation-trigger]');
    document.querySelectorAll('[data-relation-trigger][aria-expanded=true]').forEach((trigger) => {
        if (!trigger.closest('[data-relation-selection]').contains(event.target)) closeSelection(trigger.closest('[data-relation-selection]'));
    });
    if (relationTrigger) {
        const selection = relationTrigger.closest('[data-relation-selection]');
        if (relationTrigger.getAttribute('aria-expanded') === 'true') closeSelection(selection);
        else openSelection(selection);
    }
    const trigger = event.target.closest('[data-shipping-line-status]');
    if (trigger) {
        const dialog = document.querySelector('#shippingLineStatusDialog');
        const reactivating = trigger.dataset.status === '1';
        const verb = reactivating ? 'Reactivar' : 'Desactivar';
        const kind = trigger.dataset.companyKind || 'naviera';
        const form = dialog.querySelector('form');
        form.action = trigger.dataset.action;
        form.elements.status.value = trigger.dataset.status;
        const kicker = dialog.querySelector('[data-shipping-line-kicker]');
        kicker.textContent = verb + ' ' + kind;
        kicker.className = reactivating ? 'eyebrow' : 'container-exit-dialog-kicker';
        dialog.querySelector('h2').textContent = '¿' + verb + ' ' + trigger.dataset.name + '?';
        dialog.querySelector('p').textContent = kind === 'reparacion'
            ? (reactivating ? 'La reparacion volvera a estar disponible. Su historial se conservara.' : 'La reparacion dejara de estar disponible para nuevos trabajos. Su historial se conservara.')
            : kind === 'repuesto'
            ? (reactivating ? 'El repuesto volvera a estar disponible. Su historial se conservara.' : 'El repuesto dejara de estar disponible para nuevas operaciones. Su historial se conservara.')
            : kind === 'codigo ISO'
            ? (reactivating ? 'El codigo ISO volvera a estar disponible para nuevos ingresos. Su historial se conservara.' : 'El codigo ISO dejara de estar disponible para nuevos ingresos. Su historial se conservara.')
            : kind === 'auditora'
            ? (reactivating ? 'La auditora volvera a estar disponible. Se conservaran su historial y sus navieras vinculadas.' : 'La auditora dejara de estar disponible para nuevas operaciones. Su historial y sus navieras vinculadas se conservaran.')
            : reactivating
            ? 'La naviera volvera a estar disponible. Se conservaran sus sucursales y auditoras vinculadas.'
            : 'Dejara de estar disponible para nuevas operaciones. Su historial y sus vinculos con sucursales y auditoras se conservaran.';
        const confirm = dialog.querySelector('[data-shipping-line-confirm]');
        confirm.textContent = verb + ' ' + kind;
        confirm.classList.toggle('container-exit-leave', !reactivating);
        confirm.classList.toggle('primary', reactivating);
        open(dialog);
        dialog.querySelector('[data-shipping-line-close]').focus();
    }
    const cancel = event.target.closest('[data-shipping-line-close]');
    if (cancel && !cancel.closest('dialog').querySelector('[aria-busy=true]')) close(cancel.closest('dialog'));
});
document.addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-shipping-line-form], [data-shipping-line-status-form]');
    if (!form) return;
    event.preventDefault();
    if (form.hasAttribute('aria-busy')) return;
    if (form.hasAttribute('data-iso-form') || form.hasAttribute('data-catalog-item-form')) {
        const invalid = Array.from(form.querySelectorAll('input, textarea, select')).find((field) => !field.checkValidity());
        if (invalid) {
            invalid.setAttribute('aria-invalid', 'true');
            invalid.reportValidity();
            const trigger = invalid.matches('[data-admin-select]') ? invalid.nextElementSibling?.querySelector('.form-select-trigger') : null;
            (trigger || invalid).focus();
            return;
        }
    }
    const phone = form.querySelector('[data-shipping-line-phone]');
    const email = form.querySelector('[data-shipping-line-email]');
    const emailValid = !email || validateEmail(email);
    const phoneValid = !phone || validatePhone(phone);
    if (!emailValid || !phoneValid) {
        const invalid = !emailValid ? email : phone;
        revealEditorField(invalid);
        invalid.focus();
        return;
    }
    const data = new FormData(form);
    const controls = Array.from(form.querySelectorAll('input, textarea, select, button')).map((control) => ({ control, disabled:control.disabled }));
    form.setAttribute('aria-busy', 'true');
    controls.forEach(({ control }) => { control.disabled = true; });
    form.querySelectorAll('[aria-invalid]').forEach((field) => field.removeAttribute('aria-invalid'));
    try {
        const response = await fetch(form.action, { method:'POST', body:data, headers:{ Accept:'application/json' } });
        const result = await response.json();
        if (!response.ok) {
            notify([...new Set(Object.values(result.errors || {}).flat())].join(' ') || result.message || 'No se pudo completar la accion.', 'error');
            Object.keys(result.errors || {}).forEach((name) => {
                const assignment = name.split('.');
                const field = assignment[0] === 'auditor_assignments' ? 'auditor_assignments[' + assignment[1] + ']' : (name === 'name_key' ? 'name' : (name === 'phone' ? 'phone_national' : name.split('.')[0]));
                const input = form.querySelector('[name="' + field + '"], [name="' + field + '[]"]');
                input?.setAttribute('aria-invalid', 'true');
                if (input?.matches('[data-iso-code]')) {
                    fieldFeedback(input, input.closest('label').querySelector('[data-field-feedback]'), result.errors[name].join(' '));
                }
            });
        } else if (form.hasAttribute('data-shipping-line-form')) location.assign(form.dataset.returnUrl);
        else {
            await close(form.closest('dialog'));
            document.dispatchEvent(new Event('administration:refresh'));
            notify(result.message);
        }
    } catch {
        notify('No se pudo confirmar la accion. Revisa la conexion antes de volver a intentarlo.', 'error');
    } finally {
        form.removeAttribute('aria-busy');
        controls.forEach(({ control, disabled }) => { control.disabled = disabled; });
        const invalid = form.querySelector('[aria-invalid=true]');
        revealEditorField(invalid);
        const selection = invalid?.closest('[data-relation-selection]');
        if (selection) openSelection(selection);
        invalid?.focus();
    }
});
