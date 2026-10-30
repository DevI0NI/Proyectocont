import { open, close, notify, initializeNotifications } from './action-feedback';

function initialize() {
    if (!document.querySelector('[data-yard-layout]')) return;
    initializeNotifications('[data-yard-errors]');
    document.querySelectorAll('#yardEditorDialog, #yardActionDialog').forEach((dialog) => dialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        if (!dialog.querySelector('[aria-busy=true]')) close(dialog);
    }));
}
document.addEventListener('DOMContentLoaded', initialize);
document.addEventListener('administration:updated', initialize);
document.addEventListener('click', (event) => {
    const editor = event.target.closest('[data-yard-new], [data-yard-edit]');
    if (editor && !editor.disabled) {
        const dialog = document.querySelector('#yardEditorDialog'), form = dialog.querySelector('form');
        const edit = editor.hasAttribute('data-yard-edit'), block = (editor.dataset.yardEdit || editor.dataset.yardNew) === 'block';
        form.reset();
        form.action = editor.dataset.action;
        form.elements._method.value = edit ? 'PATCH' : 'POST';
        form.querySelector('[data-yard-name-field]').hidden = block;
        form.querySelector('[data-yard-capacity-field]').hidden = !block;
        form.elements.nombre.disabled = block; form.elements.nombre.required = !block;
        form.elements.capacidad_maxima.disabled = !block; form.elements.capacidad_maxima.required = block;
        form.elements.nombre.value = edit ? editor.dataset.name : '';
        form.elements.capacidad_maxima.value = edit && block ? editor.dataset.capacity : 25;
        form.elements.capacidad_maxima.min = edit && block ? Math.max(1, Number(editor.dataset.occupied)) : 1;
        form.querySelector('[data-yard-capacity-hint]').textContent = edit && Number(editor.dataset.occupied) > 0 ? editor.dataset.occupied + ' contenedores activos. Máximo 25.' : 'De 1 a 25 contenedores.';
        form.querySelectorAll('[aria-invalid]').forEach((field) => field.removeAttribute('aria-invalid'));
        dialog.querySelector('h2').textContent = edit ? (block ? 'Editar capacidad de ' + editor.dataset.name : 'Editar patio') : (block ? 'Nuevo bloque' : 'Nuevo patio');
        form.querySelector('[data-yard-save-label]').textContent = edit ? 'Guardar cambios' : (block ? 'Crear bloque' : 'Crear patio');
        open(dialog);
        (block ? form.elements.capacidad_maxima : form.elements.nombre).focus();
    }
    const deactivate = event.target.closest('[data-yard-deactivate]');
    if (deactivate) {
        const dialog = document.querySelector('#yardActionDialog');
        dialog.querySelector('form').action = deactivate.dataset.action;
        dialog.querySelector('h2').textContent = '¿Desactivar ' + deactivate.dataset.name + '?';
        dialog.querySelector('p').textContent = deactivate.dataset.yardDeactivate === 'patio' ? 'No se puede desactivar un patio con bloques activos. Su historial se conservará.' : 'No se puede desactivar un bloque con contenedores activos. Su historial se conservará.';
        open(dialog);
        dialog.querySelector('[data-yard-close]').focus();
    }
    const cancel = event.target.closest('[data-yard-close]');
    if (cancel && !cancel.closest('dialog').querySelector('[aria-busy=true]')) close(cancel.closest('dialog'));
});
document.addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-yard-save]');
    if (!form) return;
    event.preventDefault();
    if (form.hasAttribute('aria-busy')) return;
    const data = new FormData(form);
    const controls = Array.from(form.querySelectorAll('input, button')).map((control) => ({ control, disabled:control.disabled }));
    form.setAttribute('aria-busy', 'true');
    controls.forEach(({ control }) => { control.disabled = true; });
    form.querySelectorAll('[aria-invalid]').forEach((field) => field.removeAttribute('aria-invalid'));
    try {
        const response = await fetch(form.action, { method:'POST', body:data, headers:{ Accept:'application/json' } });
        const result = await response.json();
        if (!response.ok) {
            notify(Object.values(result.errors || {}).flat().join(' ') || result.message || 'No se pudo completar la acción.', 'error');
            Object.keys(result.errors || {}).forEach((name) => form.elements[name]?.setAttribute('aria-invalid', 'true'));
        } else {
            await close(form.closest('dialog'));
            const selected = document.querySelector('[data-yard-layout]').dataset.currentPatio;
            if (String(result.patio_id ?? '') !== selected) location.assign(result.url);
            else document.dispatchEvent(new Event('administration:refresh'));
        }
    } catch { notify('No se pudo confirmar la acción. Revisa la conexión antes de volver a intentarlo.', 'error'); }
    finally {
        form.removeAttribute('aria-busy');
        controls.forEach(({ control, disabled }) => { control.disabled = disabled; });
        form.querySelector('[aria-invalid=true]')?.focus();
    }
});
