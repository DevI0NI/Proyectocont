import { createElement, Trash2 } from 'lucide';
import { open, close, notify, initializeNotifications } from './action-feedback';

function updateModels(rows) {
    if (!rows) return;
    const count = rows.children.length;
    rows.closest('.model-list-field').querySelector('[data-model-count]').textContent = count + (count === 1 ? ' modelo' : ' modelos');
    Array.from(rows.children).forEach((row, index) => {
        row.querySelector('[data-model-number]').textContent = String(index + 1).padStart(2, '0');
        row.querySelector('[type=text]').setAttribute('aria-label', 'Nombre del modelo ' + (index + 1));
        row.querySelector('[data-remove-model]').setAttribute('aria-label', 'Quitar modelo ' + (index + 1));
    });
}

function initialize() {
    if (!document.querySelector('[data-unit-types]')) return;
    initializeNotifications('[data-unit-type-errors]');
    const dialog = document.querySelector('#unitTypeDeactivateDialog');
    dialog?.addEventListener('cancel', (event) => {
        event.preventDefault();
        if (!dialog.querySelector('[aria-busy=true]')) close(dialog);
    });
    document.querySelectorAll('[data-remove-model]').forEach((button) => button.replaceChildren(createElement(Trash2, { 'aria-hidden':'true' })));
    updateModels(document.querySelector('[data-model-rows]'));
}
document.addEventListener('DOMContentLoaded', initialize);
document.addEventListener('administration:updated', initialize);
document.addEventListener('click', (event) => {
    const deactivate = event.target.closest('[data-unit-type-deactivate]');
    if (deactivate) {
        const dialog = document.querySelector('#unitTypeDeactivateDialog');
        dialog.querySelector('form').action = deactivate.dataset.action;
        dialog.querySelector('h2').textContent = '¿Desactivar ' + deactivate.dataset.name + '?';
        open(dialog);
        dialog.querySelector('[data-unit-type-close]').focus();
    }
    const cancel = event.target.closest('[data-unit-type-close]');
    if (cancel && !cancel.closest('dialog').querySelector('[aria-busy=true]')) close(cancel.closest('dialog'));
    const add = event.target.closest('[data-add-model]');
    if (add) {
        const rows = add.closest('form').querySelector('[data-model-rows]');
        const row = rows.firstElementChild.cloneNode(true);
        row.querySelector('[type=hidden]').value = '0';
        const input = row.querySelector('[type=text]');
        input.value = '';
        input.setAttribute('aria-label', 'Nombre del nuevo modelo');
        input.removeAttribute('aria-invalid');
        rows.append(row);
        updateModels(rows);
        row.animate([{ opacity:0, transform:'translateY(-5px)' }, { opacity:1, transform:'translateY(0)' }], { duration:180, easing:'ease-out' });
        input.focus();
    }
    const remove = event.target.closest('[data-remove-model]');
    if (remove) {
        const row = remove.closest('.model-row');
        const rows = row.parentElement;
        if (row.parentElement.children.length === 1) {
            row.querySelector('[type=text]').value = '';
            row.querySelector('[type=text]').focus();
        } else {
            const target = row.nextElementSibling || row.previousElementSibling;
            row.remove();
            target.querySelector('[type=text]').focus();
        }
        updateModels(rows);
    }
});
document.addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-unit-type-form], #unitTypeDeactivateForm');
    if (!form) return;
    event.preventDefault();
    if (form.hasAttribute('aria-busy')) return;
    const data = new FormData(form);
    const controls = Array.from(form.querySelectorAll('input, textarea, button')).map((control) => ({ control, disabled:control.disabled }));
    form.setAttribute('aria-busy', 'true');
    controls.forEach(({ control }) => { control.disabled = true; });
    form.querySelectorAll('[aria-invalid]').forEach((field) => field.removeAttribute('aria-invalid'));
    try {
        const response = await fetch(form.action, { method:'POST', body:data, headers:{ Accept:'application/json' } });
        const result = await response.json();
        if (!response.ok) {
            notify(Object.values(result.errors || {}).flat().join(' ') || result.message || 'No se pudo completar la acción.', 'error');
            Object.keys(result.errors || {}).forEach((name) => {
                const [field, index] = name.split('.');
                const input = field === 'modelos_nombres' ? form.querySelectorAll('[name="modelos_nombres[]"]')[Number(index || 0)] : form.elements[field];
                input?.setAttribute('aria-invalid', 'true');
            });
        } else if (form.hasAttribute('data-unit-type-form')) location.assign(form.dataset.returnUrl);
        else { await close(form.closest('dialog')); document.dispatchEvent(new Event('administration:refresh')); }
    } catch {
        notify('No se pudo confirmar la acción. Revisa la conexión antes de volver a intentarlo.', 'error');
    } finally {
        form.removeAttribute('aria-busy');
        controls.forEach(({ control, disabled }) => { control.disabled = disabled; });
        form.querySelector('[aria-invalid=true]')?.focus();
    }
});
