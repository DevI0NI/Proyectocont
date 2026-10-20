import { initializeBranchGeography, setBranchGeography } from './branch-geography';
import { notify, open, close, initializeNotifications } from './action-feedback';
let deactivateForm;
const confirmed = new WeakSet();
function initialize() {
    if (!document.querySelector('#branchGeographyData')) return;
    initializeBranchGeography();
    document.querySelectorAll('.branch-modal').forEach((dialog) => {
        dialog.addEventListener('cancel', (event) => {
            event.preventDefault();
            if (!dialog.hasAttribute('aria-busy') && !dialog.querySelector('[aria-busy=true]')) close(dialog);
        });
    });
    initializeNotifications('[data-branch-errors]');
}
document.addEventListener('DOMContentLoaded', initialize);
document.addEventListener('administration:updated', initialize);
document.addEventListener('click', async (event) => {
    const trigger = event.target.closest('[data-branch-form], .branches-table .container-new-button');
    if (trigger && !event.ctrlKey && !event.metaKey && !event.shiftKey && event.button === 0) {
        event.preventDefault();
        const dialog = document.querySelector('#branchFormDialog');
        const form = dialog.querySelector('form');
        form.reset();
        const edit = trigger.dataset.branchForm === 'edit';
        form.action = edit ? trigger.dataset.action : form.dataset.createAction;
        form.elements._method.value = edit ? 'PATCH' : 'POST';
        form.elements.nombre.value = edit ? trigger.dataset.name : '';
        setBranchGeography(form, edit ? trigger.dataset.department : '', edit ? trigger.dataset.municipality : '', edit ? trigger.dataset.location : '');
        form.elements.direccion.value = edit ? trigger.dataset.address : '';
        const legacy = form.querySelector('[data-branch-legacy]');
        legacy.hidden = !edit || !!trigger.dataset.municipality;
        legacy.textContent = legacy.hidden ? '' : 'Ubicacion anterior: ' + trigger.dataset.location;
        form.querySelectorAll('[aria-invalid]').forEach((field) => field.removeAttribute('aria-invalid'));
        dialog.querySelector('h2').textContent = edit ? 'Editar sucursal' : 'Nueva sucursal';
        open(dialog);
        form.elements.nombre.focus();
    }
    const dismiss = event.target.closest('[data-branch-modal-close]');
    if (dismiss && !dismiss.closest('dialog').hasAttribute('aria-busy') && !dismiss.closest('dialog').querySelector('[aria-busy=true]')) close(dismiss.closest('dialog'));
    const deactivateConfirm = event.target.closest('[data-branch-deactivate-confirm]');
    if (deactivateConfirm && deactivateForm?.isConnected) {
        const form = deactivateForm;
        const dialog = deactivateConfirm.closest('dialog');
        if (dialog.hasAttribute('aria-busy')) return;
        const controls = Array.from(dialog.querySelectorAll('button')).map((control) => ({ control, disabled:control.disabled }));
        dialog.setAttribute('aria-busy', 'true');
        controls.forEach(({ control }) => { control.disabled = true; });
        try {
            const response = await fetch(form.action, { method:'POST', body:new FormData(form), headers:{ Accept:'application/json' } });
            const result = await response.json();
            if (!response.ok) {
                notify(Object.values(result.errors || {}).flat().join(' ') || result.message || 'No se pudo desactivar la sucursal.', 'error');
            } else {
                await close(dialog);
                deactivateForm = null;
                if (result.refreshShell) location.assign(location.href);
                else document.dispatchEvent(new Event('administration:refresh'));
            }
        } catch {
            notify('No se pudo confirmar la desactivacion. Revisa la conexion antes de volver a intentarlo.', 'error');
        } finally {
            dialog.removeAttribute('aria-busy');
            controls.forEach(({ control, disabled }) => { control.disabled = disabled; });
            if (dialog.open) deactivateConfirm.focus();
        }
    }
    if (event.target.closest('[data-branch-reactivate-confirm]') && deactivateForm?.isConnected) {
        const form = deactivateForm;
        confirmed.add(form);
        close(event.target.closest('dialog')).then(() => form.requestSubmit());
        deactivateForm = null;
    }
});
document.addEventListener('submit', async (event) => {
    const deactivate = event.target.closest('[data-branch-deactivate], [data-branch-reactivate]');
    if (deactivate && !confirmed.has(deactivate)) {
        event.preventDefault();
        deactivateForm = deactivate;
        const reactivate = deactivate.hasAttribute('data-branch-reactivate');
        const dialog = document.querySelector(reactivate ? '#branchReactivateDialog' : '#branchDeactivateDialog');
        dialog.querySelector('h2').textContent = (reactivate ? '¿Reactivar ' : '¿Desactivar ') + deactivate.dataset.name + '?';
        open(dialog);
        dialog.querySelector('[data-branch-modal-close]').focus();
    }
    const form = event.target.closest('#branchModalForm');
    if (!form) return;
    event.preventDefault();
    if (form.hasAttribute('aria-busy')) return;
    const data = new FormData(form);
    const controls = Array.from(form.querySelectorAll('input, select, textarea, button')).map((control) => ({ control, disabled:control.disabled }));
    let invalid = false;
    form.querySelectorAll('[aria-invalid]').forEach((field) => field.removeAttribute('aria-invalid'));
    form.setAttribute('aria-busy', 'true');
    controls.forEach(({ control }) => { control.disabled = true; });
    try {
        const response = await fetch(form.action, { method:'POST', body:data, headers:{ Accept:'application/json' } });
        const result = await response.json();
        if (!response.ok) {
            invalid = true;
            notify(Object.values(result.errors || {}).flat().join(' ') || result.message || 'No se pudo guardar la sucursal.', 'error');
            Object.keys(result.errors || {}).forEach((name) => form.elements[name]?.setAttribute('aria-invalid', 'true'));
        } else {
            await close(form.closest('dialog'));
            document.dispatchEvent(new Event('administration:refresh'));
        }
    } catch {
        invalid = true;
        notify('No se pudo confirmar el guardado. Revisa la conexion antes de volver a intentarlo.', 'error');
    } finally {
        form.removeAttribute('aria-busy');
        controls.forEach(({ control, disabled }) => { control.disabled = disabled; });
        if (invalid) form.elements.nombre.focus();
    }
});
