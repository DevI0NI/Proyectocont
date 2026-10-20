import { createElement, Eye, EyeOff, RotateCcw, Info, ShieldCheck, UserRound, LockKeyhole } from 'lucide';
import { open, close, notify, initializeNotifications } from './action-feedback';
import { initializeRecordSelects } from './branch-geography';

const checks = new WeakMap();
const permissionHelp = { item:null, pinned:false };
function closePermissionHelp() {
    document.querySelector('#userPermissionHelp')?.hidePopover();
    permissionHelp.item?.querySelector('[data-permission-help]')?.setAttribute('aria-expanded', 'false');
    permissionHelp.item?.querySelectorAll('[aria-describedby="userPermissionHelp"]').forEach((control) => control.removeAttribute('aria-describedby'));
    permissionHelp.item = null;
    permissionHelp.pinned = false;
}
function showPermissionHelp(item, pinned = false) {
    const tooltip = document.querySelector('#userPermissionHelp');
    if (!tooltip || !item) return;
    if (permissionHelp.pinned && !pinned) return;
    closePermissionHelp();
    permissionHelp.item = item;
    permissionHelp.pinned = pinned;
    tooltip.querySelector('[data-permission-help-title]').textContent = item.dataset.helpTitle;
    tooltip.querySelector('[data-permission-help-description]').textContent = item.dataset.helpDescription;
    item.querySelector('[data-permission-help]').setAttribute('aria-expanded', 'true');
    item.querySelectorAll('input, button').forEach((control) => control.setAttribute('aria-describedby', tooltip.id));
    tooltip.showPopover();
    const anchor = item.querySelector('[data-permission-help]').getBoundingClientRect();
    const box = tooltip.getBoundingClientRect();
    const top = anchor.top >= box.height + 20 ? anchor.top - box.height - 8 : anchor.bottom + 8;
    tooltip.style.left = Math.max(12, Math.min(anchor.left, innerWidth - box.width - 12)) + 'px';
    tooltip.style.top = Math.max(12, Math.min(top, innerHeight - box.height - 12)) + 'px';
}
function updatePermissionHelpHover(event) {
    if (event.pointerType !== 'mouse') return;
    const button = event.target.closest('[data-permission-help]');
    if (button) {
        const item = button.closest('[data-permission-help-item]');
        if (permissionHelp.item !== item) showPermissionHelp(item);
    } else if (permissionHelp.item && !permissionHelp.pinned) closePermissionHelp();
}
document.addEventListener('pointerover', updatePermissionHelpHover);
document.addEventListener('pointermove', updatePermissionHelpHover);
document.addEventListener('pointerout', (event) => {
    if (event.pointerType !== 'mouse' || permissionHelp.pinned) return;
    const source = event.target.closest('[data-permission-help]');
    if ((source && !source.contains(event.relatedTarget)) || !event.relatedTarget) closePermissionHelp();
});
document.addEventListener('focusin', (event) => {
    const button = event.target.closest('[data-permission-help]');
    if (button?.matches(':focus-visible')) showPermissionHelp(button.closest('[data-permission-help-item]'));
});
document.addEventListener('focusout', (event) => {
    const button = event.target.closest('[data-permission-help]');
    if (button && !button.contains(event.relatedTarget) && !permissionHelp.pinned) closePermissionHelp();
});
document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && permissionHelp.item) { event.preventDefault(); closePermissionHelp(); } });
window.addEventListener('resize', closePermissionHelp);
document.addEventListener('scroll', (event) => { if (!event.target.closest?.('#userPermissionHelp')) closePermissionHelp(); }, true);
function showUserTab(form, name) {
    closePermissionHelp();
    form.querySelectorAll('[data-user-tab]').forEach((tab) => { const active = tab.dataset.userTab === name; tab.setAttribute('aria-selected', String(active)); tab.tabIndex = active ? 0 : -1; });
    form.querySelectorAll('[data-user-panel]').forEach((panel) => { const active = panel.dataset.userPanel === name; const wasHidden = panel.hidden; panel.hidden = !active; if (active && wasHidden && !matchMedia('(prefers-reduced-motion:reduce)').matches) panel.animate([{ opacity:0, transform:'translateY(8px)' }, { opacity:1, transform:'translateY(0)' }], { duration:180, easing:'ease' }); });
}
function revealUserField(form, field) { const panel = field?.closest('[data-user-panel]'); if (panel?.hidden) showUserTab(form, panel.dataset.userPanel); }
function showPermissionTab(form, name) {
    closePermissionHelp();
    form.querySelectorAll('[data-permission-tab]').forEach((tab) => {
        const active = tab.dataset.permissionTab === name;
        tab.setAttribute('aria-selected', String(active));
        tab.tabIndex = active ? 0 : -1;
    });
    form.querySelectorAll('[data-permission-panel]').forEach((panel) => {
        const active = panel.dataset.permissionPanel === name;
        const wasHidden = panel.hidden;
        panel.hidden = !active;
        if (active && wasHidden && !matchMedia('(prefers-reduced-motion:reduce)').matches) {
            panel.animate([{ opacity:0, transform:'translateY(8px)' }, { opacity:1, transform:'translateY(0)' }], { duration:180, easing:'ease' });
        }
    });
}
function updateExternalBranches(form) {
    const section = form.querySelector('[data-user-permissions]');
    if (section?.dataset.mayAssign !== 'true') return;
    const external = form.querySelector('[data-user-external-access]');
    const company = form.elements.external_audit_company_id.value;
    const roleIsExternal = Number(form.elements.rol_id.value) === 5;
    const map = JSON.parse(external.querySelector('[data-external-company-branches]').textContent);
    const allowed = new Set((map[company] || []).map(String));
    external.querySelectorAll('[name="external_branches[]"]').forEach((input) => {
        const eligible = allowed.has(input.value);
        input.disabled = !roleIsExternal || !eligible;
        input.closest('[data-relation-choice]').dataset.relationAllowed = String(eligible);
        if (!eligible) input.checked = false;
    });
    const trigger = external.querySelector('[data-relation-trigger]');
    trigger.disabled = !roleIsExternal || !allowed.size;
    const status = external.querySelector('[data-external-branch-status]');
    status.hidden = !!allowed.size;
    status.textContent = company ? 'Sin sucursales vinculadas a esta auditora en navieras activas.' : 'Empresa auditora pendiente de seleccionar.';
    external.querySelector('[data-relation-search]').dispatchEvent(new Event('input', { bubbles:true }));
}
function updatePermissions(form, reset = false) {
    const section = form.querySelector('[data-user-permissions]');
    if (!section) return;
    const role = Number(form.elements.rol_id.value);
    form.querySelector('[data-user-branch-field]').hidden = role === 5;
    form.querySelector('[data-user-branch-notice]').hidden = role !== 5;
    form.elements.sucursal_id.disabled = role === 5;
    form.elements.sucursal_id.required = role !== 5;
    form.elements.sucursal_id.nextElementSibling?.querySelector('button')?.toggleAttribute('disabled', role === 5);
    feedback(form, 'sucursal_id');
    const branchTitle = form.querySelector('[data-user-branch-title]');
    branchTitle.textContent = 'Sucursal';
    form.elements.sucursal_id.nextElementSibling?.querySelector('button')?.setAttribute('aria-label', branchTitle.textContent);
    const internal = [2, 3].includes(role);
    const defaults = JSON.parse(section.querySelector('[data-permission-defaults]').textContent)[role] || [];
    const custom = section.querySelector('[data-custom-permissions]');
    const mode = form.elements.permission_mode;
    if (reset && custom) custom.checked = false;
    if (!internal && custom) custom.checked = false;
    if (mode) mode.value = custom?.checked ? 'custom' : 'role';
    section.querySelector('[data-internal-permissions]').hidden = !internal;
    const fixed = section.querySelector('[data-fixed-access]'); fixed.hidden = internal;
    const external = section.querySelector('[data-user-external-access]');
    external.hidden = role !== 5;
    external.querySelectorAll('input, select, button').forEach((control) => { control.disabled = role !== 5 || section.dataset.mayAssign !== 'true'; });
    external.querySelectorAll('select').forEach((control) => {
        control.required = role === 5 && section.dataset.mayAssign === 'true';
        control.nextElementSibling?.querySelector('button')?.toggleAttribute('disabled', control.disabled);
    });
    updateExternalBranches(form);
    fixed.dataset.state = role ? 'fixed' : 'pending';
    fixed.querySelector('[data-access-state-icon]').replaceChildren(createElement(role === 1 ? ShieldCheck : role ? LockKeyhole : UserRound, { 'aria-hidden':'true' }));
    fixed.querySelector('[data-access-state-title]').textContent = role === 1 ? 'Acceso global' : role === 4 ? 'Acceso de mecanico' : role === 5 ? 'Acceso de empresa externa' : 'Rol pendiente';
    fixed.querySelector('[data-access-state-description]').textContent = role === 1 ? 'Super Admin tiene acceso a todas las vistas y acciones del sistema. Sus permisos no se personalizan.' : role === 5 ? 'Acceso limitado a la empresa auditora y las sucursales autorizadas. Su configuracion esta reservada al Super Admin.' : role ? 'Este rol conserva los accesos definidos para su interfaz. No utiliza permisos del sistema interno.' : 'Selecciona el rol del usuario para definir sus accesos.';
    section.querySelectorAll('[name="permissions[]"]').forEach((input) => {
        if (reset || (custom && !custom.checked)) input.checked = defaults.includes(input.value);
        input.disabled = !internal || section.dataset.mayAssign !== 'true' || !custom?.checked;
    });
}
const passwordRules = {
    length: (value) => value.length >= 8 && value.length <= 64,
    uppercase: (value) => /[A-Z]/.test(value),
    number: (value) => /[0-9]/.test(value),
    symbol: (value) => /[\p{P}\p{S}]/u.test(value),
};
function feedback(form, name, message = '', state = '') {
    const field = form.elements[name];
    const output = form.querySelector(`[data-field-feedback="${name}"]`);
    if (output) { output.textContent = message; output.dataset.state = state; }
    field?.setAttribute('aria-invalid', String(state === 'error'));
    if (field?.matches('select')) field.nextElementSibling?.querySelector('button')?.classList.toggle('invalid', state === 'error');
}
function validateField(form, name, required = false) {
    const field = form.elements[name];
    if (field.disabled) return true;
    const value = field.value;
    let error = '';
    if (name === 'username') {
        if (!value.trim()) error = required ? 'El usuario es obligatorio.' : '';
        else if (!/^[\p{L} ]+$/u.test(value.trim()) || value.trim().length > 50) error = 'Usa solo letras y espacios, máximo 50 caracteres.';
    } else if (name === 'email') {
        if (!value.trim()) error = required ? 'El correo es obligatorio.' : '';
        else if (field.validity.typeMismatch || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value.trim())) error = 'Introduce un correo válido, como usuario@empresa.com.';
    } else if (name === 'password') {
        Object.entries(passwordRules).forEach(([rule, test]) => {
            const item = form.querySelector(`[data-password-rule="${rule}"]`);
            const met = !!value && test(value);
            item?.classList.toggle('met', met);
            if (item) item.querySelector('input').checked = met;
        });
        if (!value) error = required && field.required ? 'La contraseña es obligatoria.' : '';
        else if (!Object.values(passwordRules).every((test) => test(value))) error = 'Usa entre 8 y 64 caracteres, una mayúscula, un número y un símbolo.';
    } else if (name === 'password_confirmation') {
        if (form.elements.password.value && !value) error = required ? 'Confirma la contraseña.' : '';
        else if (value !== form.elements.password.value) error = 'Las contraseñas no coinciden.';
    } else if (required && !value) error = name === 'rol_id' ? 'Selecciona un rol.' : name === 'external_audit_company_id' ? 'Selecciona la empresa auditora.' : name === 'external_access_level' ? 'Selecciona la responsabilidad.' : 'Selecciona una sucursal.';
    feedback(form, name, error || (name === 'password' && !value && !field.required ? 'Déjala vacía para conservar la actual.' : ''), error ? 'error' : '');
    return !error;
}
function checkAvailability(form, field) {
    const previous = checks.get(field);
    clearTimeout(previous?.timer); previous?.controller?.abort();
    const controller = new AbortController();
    const value = field.value.trim();
    if (!validateField(form, field.name) || !value) { checks.delete(field); return; }
    feedback(form, field.name, 'Comprobando disponibilidad…');
    const timer = setTimeout(async () => {
        try {
            const data = new FormData();
            data.append('_token', form.elements._token.value);
            data.append('id', form.dataset.userId || '0');
            data.append(field.name, value);
            const response = await fetch(form.dataset.checkUrl, { method:'POST', body:data, headers:{ Accept:'application/json' }, signal:controller.signal });
            const result = await response.json();
            if (controller.signal.aborted || field.value.trim() !== value) return;
            if (!response.ok) { feedback(form, field.name, Object.values(result.errors || {}).flat().join(' ') || 'No se pudo comprobar la disponibilidad.', 'error'); return; }
            feedback(form, field.name, result.status === 'available' ? 'Disponible.' : result.status === 'inactive' ? 'Ya pertenece a una cuenta inactiva. Usa Reactivar.' : 'Ya está registrado en el sistema.', result.status === 'available' ? 'success' : 'error');
        } catch (error) {
            if (error.name !== 'AbortError') feedback(form, field.name, 'No se pudo comprobar. Se verificará al guardar.');
        }
    }, 350);
    checks.set(field, { timer, controller });
}
document.addEventListener('input', (event) => {
    const form = event.target.closest('[data-user-standalone]');
    if (!form || !event.target.name) return;
    const name = event.target.name;
    if (['username', 'email'].includes(name)) checkAvailability(form, event.target);
    else if (['password', 'password_confirmation'].includes(name)) {
        validateField(form, name);
        if (name === 'password' && form.elements.password_confirmation.value) validateField(form, 'password_confirmation');
    }
});
document.addEventListener('change', (event) => {
    const form = event.target.closest('[data-user-standalone]');
    if (form && event.target.name === 'rol_id') updatePermissions(form, true);
    if (form && event.target.name === 'external_audit_company_id') updateExternalBranches(form);
    if (form && event.target.matches('[data-custom-permissions]')) updatePermissions(form);
    if (form && event.target.matches('[name="permissions[]"]')) {
        const input = event.target;
        const siblings = form.querySelectorAll('[data-permission-module="' + input.dataset.permissionModule + '"]');
        if (input.checked && input.dataset.permissionAction !== 'view') Array.from(siblings).find((item) => item.dataset.permissionAction === 'view').checked = true;
        if (input.checked && ['technical', 'ipsa'].includes(input.dataset.permissionAction)) Array.from(siblings).find((item) => item.dataset.permissionAction === 'edit').checked = true;
        if (!input.checked && input.dataset.permissionAction === 'edit' && input.dataset.permissionModule === 'containers') siblings.forEach((item) => { if (['technical', 'ipsa'].includes(item.dataset.permissionAction)) item.checked = false; });
        if (!input.checked && input.dataset.permissionAction === 'view') siblings.forEach((item) => { item.checked = false; });
    }
    if (form && ['rol_id', 'sucursal_id'].includes(event.target.name) && !event.detail?.skipValidation) validateField(form, event.target.name, true);
});
document.addEventListener('keydown', (event) => {
    const permissionTab = event.target.closest('[data-permission-tab]');
    if (permissionTab && ['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key) && !permissionTab.form.hasAttribute('aria-busy')) {
        event.preventDefault();
        const tabs = Array.from(permissionTab.form.querySelectorAll('[data-permission-tab]'));
        const index = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (tabs.indexOf(permissionTab) + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
        showPermissionTab(permissionTab.form, tabs[index].dataset.permissionTab);
        tabs[index].focus();
        return;
    }
    const tab = event.target.closest('[data-user-tab]');
    if (!tab || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key) || tab.form.hasAttribute('aria-busy')) return;
    event.preventDefault();
    const tabs = Array.from(tab.form.querySelectorAll('[data-user-tab]'));
    const index = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (tabs.indexOf(tab) + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
    showUserTab(tab.form, tabs[index].dataset.userTab); tabs[index].focus();
});

function initialize() {
    if (!document.querySelector('#userDeactivateDialog, [data-user-standalone]')) return;
    initializeRecordSelects();
    initializeNotifications('[data-user-errors]');
    document.querySelectorAll('[data-user-standalone]').forEach((form) => updatePermissions(form));
    document.querySelectorAll('.user-modal').forEach((dialog) => dialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        if (!dialog.querySelector('[aria-busy=true]')) close(dialog);
    }));
    document.querySelectorAll('[data-user-password-toggle]').forEach((button) => button.replaceChildren(createElement(Eye, { 'aria-hidden':'true' })));
    document.querySelectorAll('[data-permission-reset-icon]').forEach((icon) => icon.replaceChildren(createElement(RotateCcw, { 'aria-hidden':'true' })));
    document.querySelectorAll('[data-permission-help]').forEach((button) => button.replaceChildren(createElement(Info, { 'aria-hidden':'true' })));
}

document.addEventListener('DOMContentLoaded', initialize);
document.addEventListener('administration:updated', initialize);

document.addEventListener('click', (event) => {
    const helpButton = event.target.closest('[data-permission-help]');
    if (helpButton) {
        const item = helpButton.closest('[data-permission-help-item]');
        const pinned = event.pointerType === 'touch' || event.detail === 0;
        if (permissionHelp.item === item && permissionHelp.pinned && pinned) closePermissionHelp();
        else showPermissionHelp(item, pinned);
        return;
    }
    if (!event.target.closest('#userPermissionHelp')) closePermissionHelp();
    const permissionTab = event.target.closest('[data-permission-tab]');
    if (permissionTab && !permissionTab.form.hasAttribute('aria-busy')) showPermissionTab(permissionTab.form, permissionTab.dataset.permissionTab);
    const tab = event.target.closest('[data-user-tab]');
    if (tab && !tab.form.hasAttribute('aria-busy')) showUserTab(tab.form, tab.dataset.userTab);
    const reset = event.target.closest('[data-reset-permissions]');
    if (reset) updatePermissions(reset.form, true);
    const deactivate = event.target.closest('[data-user-deactivate]');
    if (deactivate && !deactivate.disabled) {
        const dialog = document.querySelector('#userDeactivateDialog');
        dialog.querySelector('form').action = deactivate.dataset.action;
        dialog.querySelector('h2').textContent = '¿Desactivar ' + deactivate.dataset.name + '?';
        open(dialog);
        dialog.querySelector('[data-user-modal-close]').focus();
    }
    const dismiss = event.target.closest('[data-user-modal-close]');
    if (dismiss && !dismiss.closest('dialog').querySelector('[aria-busy=true]')) close(dismiss.closest('dialog'));
    const toggle = event.target.closest('[data-user-password-toggle]');
    if (toggle) {
        const password = toggle.closest('.user-password-control').querySelector('input');
        const visible = password.type === 'password';
        password.type = visible ? 'text' : 'password';
        toggle.setAttribute('aria-label', visible ? 'Ocultar contrasena' : 'Mostrar contrasena');
        toggle.title = toggle.getAttribute('aria-label');
        toggle.replaceChildren(createElement(visible ? EyeOff : Eye, { 'aria-hidden':'true' }));
    }
});

document.addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-user-standalone], #userDeactivateForm');
    if (!form) return;
    event.preventDefault();
    if (form.hasAttribute('aria-busy')) return;
    if (form.hasAttribute('data-user-standalone')) {
        const fields = ['username', 'email', 'password', 'password_confirmation', 'rol_id', 'sucursal_id'];
        const external = Number(form.elements.rol_id.value) === 5 && form.querySelector('[data-user-permissions]').dataset.mayAssign === 'true';
        if (external) fields.push('external_audit_company_id', 'external_access_level');
        const valid = fields.map((name) => validateField(form, name, true)).every(Boolean);
        if (!valid) {
            notify('Revisa los campos señalados antes de guardar.', 'error');
            const invalid = form.querySelector('[aria-invalid=true]');
            revealUserField(form, invalid);
            if (invalid?.matches('select')) invalid.nextElementSibling?.querySelector('button')?.focus();
            else invalid?.focus();
            return;
        }
        if (external && !form.querySelector('[name="external_branches[]"]:checked')) {
            notify('Selecciona al menos una sucursal autorizada.', 'error');
            showUserTab(form, 'access');
            form.querySelector('[data-relation-trigger]')?.focus();
            return;
        }
        ['username', 'email'].forEach((name) => { const pending = checks.get(form.elements[name]); clearTimeout(pending?.timer); pending?.controller?.abort(); });
    }
    const data = new FormData(form);
    const controls = Array.from(form.querySelectorAll('input, select, button')).map((control) => ({ control, disabled:control.disabled }));
    form.setAttribute('aria-busy', 'true');
    controls.forEach(({ control }) => { control.disabled = true; });
    form.querySelectorAll('[aria-invalid]').forEach((field) => field.removeAttribute('aria-invalid'));
    try {
        const response = await fetch(form.action, { method:'POST', body:data, headers:{ Accept:'application/json' } });
        const result = await response.json();
        if (!response.ok) {
            notify(Object.values(result.errors || {}).flat().join(' ') || result.message || 'No se pudo completar la accion.', 'error');
            Object.entries(result.errors || {}).forEach(([name, messages]) => feedback(form, name, messages.join(' '), 'error'));
        } else if (form.hasAttribute('data-user-standalone')) {
            form.elements.password.value = '';
            location.assign(form.dataset.returnUrl);
        } else {
            await close(form.closest('dialog'));
            document.dispatchEvent(new Event('administration:refresh'));
        }
    } catch {
        notify('No se pudo confirmar la accion. Revisa la conexion antes de volver a intentarlo.', 'error');
    } finally {
        form.removeAttribute('aria-busy');
        controls.forEach(({ control, disabled }) => { control.disabled = disabled; });
        const invalid = form.querySelector('[aria-invalid=true]');
        revealUserField(form, invalid);
        if (invalid?.matches('select')) invalid.nextElementSibling?.querySelector('button')?.focus();
        else invalid?.focus();
    }
});
