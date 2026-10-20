import { open, close, notify, initializeNotifications } from './action-feedback';
import { initializeRecordSelects } from './branch-geography';

function initialize() {
    if (!document.querySelector('[data-container-statuses]')) return;
    initializeRecordSelects();
    initializeNotifications('[data-status-errors]');
    document.querySelectorAll('#statusFormDialog, #statusActionDialog').forEach((dialog) => dialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        if (!dialog.querySelector('[aria-busy=true]')) close(dialog);
    }));
}
document.addEventListener('DOMContentLoaded', initialize);
document.addEventListener('administration:updated', initialize);
document.addEventListener('click', (event) => {
    const edit = event.target.closest('[data-status-edit], .statuses-table .container-new-button');
    if (edit && event.button === 0 && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey) {
        event.preventDefault();
        const dialog = document.querySelector('#statusFormDialog'), form = dialog.querySelector('form');
        const editing = edit.hasAttribute('data-status-edit');
        form.reset();
        form.action = editing ? edit.dataset.action : form.dataset.createAction;
        form.elements._method.value = editing ? 'PATCH' : 'POST';
        form.elements.nombre.value = editing ? edit.dataset.name : '';
        form.elements.tipo_estatus_id.value = editing ? edit.dataset.type : form.dataset.defaultType;
        form.elements.tipo_estatus_id.dispatchEvent(new Event('change', { bubbles:true }));
        form.querySelectorAll('[aria-invalid]').forEach((field) => field.removeAttribute('aria-invalid'));
        form.querySelector('.form-select-trigger')?.classList.remove('invalid');
        dialog.querySelector('h2').textContent = editing ? 'Editar estatus' : 'Nuevo estatus';
        open(dialog);
        form.elements.nombre.focus();
    }
    const action = event.target.closest('[data-status-action]');
    if (action) {
        const dialog = document.querySelector('#statusActionDialog'), form = dialog.querySelector('form');
        const reactivate = action.dataset.statusAction === 'reactivate';
        form.action = action.dataset.action;
        form.elements._method.value = reactivate ? 'PATCH' : 'DELETE';
        dialog.querySelector('h2').textContent = (reactivate ? '¿Reactivar ' : '¿Desactivar ') + action.dataset.name + '?';
        dialog.querySelector('[data-status-action-kicker]').textContent = reactivate ? 'Reactivar estatus' : 'Desactivar estatus';
        dialog.querySelector('p').textContent = reactivate ? 'Volverá a estar disponible para nuevos ingresos. Su historial se conservará.' : 'Dejará de estar disponible para nuevos ingresos. No se puede desactivar si lo usan contenedores activos. Su historial se conservará.';
        const confirm = dialog.querySelector('[data-status-action-confirm]');
        confirm.textContent = reactivate ? 'Reactivar estatus' : 'Desactivar estatus';
        confirm.className = 'button ' + (reactivate ? 'primary' : 'container-exit-leave');
        open(dialog);
        dialog.querySelector('[data-status-close]').focus();
    }
    const cancel = event.target.closest('[data-status-close]');
    if (cancel && !cancel.closest('dialog').querySelector('[aria-busy=true]')) close(cancel.closest('dialog'));
});
document.addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-status-save]');
    if (!form) return;
    event.preventDefault();
    if (form.hasAttribute('aria-busy')) return;
    const data = new FormData(form);
    const controls = Array.from(form.querySelectorAll('input, select, button')).map((control) => ({ control, disabled:control.disabled }));
    form.setAttribute('aria-busy', 'true');
    controls.forEach(({ control }) => { control.disabled = true; });
    form.querySelectorAll('[aria-invalid]').forEach((field) => field.removeAttribute('aria-invalid'));
    try {
        const response = await fetch(form.action, { method:'POST', body:data, headers:{ Accept:'application/json' } });
        const result = await response.json();
        if (!response.ok) {
            notify(Object.values(result.errors || {}).flat().join(' ') || result.message || 'No se pudo completar la acción.', 'error');
            Object.keys(result.errors || {}).forEach((name) => {
                const field = form.elements[name];
                field?.setAttribute('aria-invalid', 'true');
                if (field?.matches('select')) field.nextElementSibling?.querySelector('button')?.classList.add('invalid');
            });
        } else {
            if (form.closest('dialog')) await close(form.closest('dialog'));
            if (form.hasAttribute('data-status-standalone') || result.type !== form.dataset.currentCategory) {
                const url = new URL(form.dataset.returnBase);
                url.searchParams.set('tipo', result.type);
                location.assign(url);
            } else document.dispatchEvent(new Event('administration:refresh'));
        }
    } catch { notify('No se pudo confirmar la acción. Revisa la conexión antes de volver a intentarlo.', 'error'); }
    finally {
        form.removeAttribute('aria-busy');
        controls.forEach(({ control, disabled }) => { control.disabled = disabled; });
        const invalid = form.querySelector('[aria-invalid=true]');
        if (invalid?.matches('select')) invalid.nextElementSibling?.querySelector('button')?.focus();
        else invalid?.focus();
    }
});
