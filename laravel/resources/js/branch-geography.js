import { createElement, ChevronDown } from 'lucide';

const geography = () => JSON.parse(document.querySelector('#branchGeographyData')?.textContent || '[]');
function closeMenus(except) {
    document.querySelectorAll('.record-select-fields .form-select-dropdown.open:not(.iso-search-dropdown)').forEach((dropdown) => {
        if (dropdown === except) return;
        dropdown.classList.remove('open');
        dropdown.querySelector('button').setAttribute('aria-expanded', 'false');
    });
}
function municipalities(form, department, selected = '') {
    const select = form.elements.municipio_codigo;
    select.replaceChildren(new Option('Selecciona un municipio', ''));
    const items = geography().find((item) => item.code === department)?.municipalities || [];
    items.forEach((item) => select.append(new Option(item.name, item.code)));
    select.disabled = !items.length;
    select.value = selected;
    select.dispatchEvent(new Event('change', { bubbles:true }));
}
export function setBranchGeography(form, department, municipality, legacy) {
    if (!department && legacy) department = geography().find((item) => item.name.localeCompare(legacy, 'es', { sensitivity:'base' }) === 0)?.code || '';
    form.elements.departamento_codigo.value = department || '';
    form.elements.departamento_codigo.dispatchEvent(new Event('change', { bubbles:true }));
    municipalities(form, department, municipality || '');
    form.querySelectorAll('.branch-geography-fields .form-select-trigger').forEach((trigger) => trigger.classList.remove('invalid'));
    closeMenus();
}
export function initializeRecordSelects() {
    document.querySelectorAll('[data-admin-select]:not([data-enhanced])').forEach((select, index) => {
        select.dataset.enhanced = 'true';
        select.classList.add('enhanced-native-select');
        select.tabIndex = -1;
        select.setAttribute('aria-hidden', 'true');
        const dropdown = document.createElement('div');
        dropdown.className = 'form-select-dropdown';
        const trigger = document.createElement('button');
        trigger.type = 'button'; trigger.className = 'form-select-trigger';
        trigger.setAttribute('aria-label', select.closest('label').querySelector('span').textContent);
        trigger.setAttribute('aria-haspopup', 'listbox'); trigger.setAttribute('aria-expanded', 'false');
        const value = document.createElement('span');
        trigger.append(value, createElement(ChevronDown, { 'aria-hidden':'true' }));
        const menu = document.createElement('div');
        let menuIndex = index;
        while (document.getElementById('branch-geography-menu-' + menuIndex)) menuIndex++;
        menu.className = 'form-select-menu'; menu.id = 'branch-geography-menu-' + menuIndex;
        menu.setAttribute('role', 'listbox'); trigger.setAttribute('aria-controls', menu.id);
        let search;
        let optionsList = menu;
        if (select.hasAttribute('data-admin-search')) {
            menu.removeAttribute('role');
            menu.classList.add('form-select-search-menu');
            search = document.createElement('input');
            search.type = 'search'; search.className = 'form-select-search';
            search.placeholder = 'Buscar...';
            search.setAttribute('aria-label', 'Buscar ' + trigger.getAttribute('aria-label').toLocaleLowerCase('es'));
            search.autocomplete = 'off';
            optionsList = document.createElement('div');
            optionsList.className = 'form-select-search-options';
            optionsList.setAttribute('role', 'listbox'); optionsList.id = menu.id + '-options';
            trigger.setAttribute('aria-controls', optionsList.id);
            menu.append(search, optionsList);
            search.addEventListener('input', render);
            search.addEventListener('click', (event) => event.preventDefault());
            search.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter') return;
                event.preventDefault();
                optionsList.querySelector('button')?.click();
            });
        }
        dropdown.append(trigger, menu); select.after(dropdown);
        function placeMenu() {
            const bounds = select.closest('dialog')?.getBoundingClientRect() || { top: 0, bottom: window.innerHeight };
            const triggerBounds = trigger.getBoundingClientRect();
            const menuHeight = Math.min(menu.scrollHeight, search ? 230 : 180);
            dropdown.classList.toggle('opens-up', !!bounds && bounds.bottom - triggerBounds.bottom < menuHeight + 16 && triggerBounds.top - bounds.top > menuHeight + 16);
        }
        function render() {
            trigger.disabled = select.disabled;
            value.textContent = select.selectedOptions[0]?.textContent || 'Selecciona';
            optionsList.replaceChildren();
            const normalize = (text) => text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('es');
            const query = normalize(search?.value.trim() || '');
            Array.from(select.options).filter((option) => option.value && !option.disabled && !option.hidden && normalize(option.textContent).includes(query)).forEach((option) => {
                const button = document.createElement('button');
                button.type = 'button'; button.className = 'topbar-dropdown-option'; button.textContent = option.textContent;
                button.setAttribute('role', 'option'); button.setAttribute('aria-selected', String(option.selected));
                button.classList.toggle('selected', option.selected);
                button.addEventListener('click', () => { select.value = option.value; select.dispatchEvent(new Event('change', { bubbles:true })); closeMenus(); trigger.classList.remove('invalid'); trigger.focus(); });
                optionsList.append(button);
            });
            if (search && !optionsList.children.length) {
                const empty = document.createElement('p');
                empty.className = 'form-select-no-results'; empty.textContent = 'Sin resultados';
                empty.setAttribute('role', 'status'); optionsList.append(empty);
            }
        }
        trigger.addEventListener('click', (event) => { if (search) event.preventDefault(); const open = !dropdown.classList.contains('open'); closeMenus(dropdown); if (open) { if (search) { search.value = ''; render(); } placeMenu(); } dropdown.classList.toggle('open', open); trigger.setAttribute('aria-expanded', String(open)); if (open && !matchMedia('(pointer: coarse)').matches) search?.focus(); });
        dropdown.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && dropdown.classList.contains('open')) { event.preventDefault(); event.stopPropagation(); closeMenus(); trigger.focus(); }
            if (!['ArrowUp', 'ArrowDown'].includes(event.key)) return;
            event.preventDefault(); closeMenus(dropdown); placeMenu(); dropdown.classList.add('open'); trigger.setAttribute('aria-expanded', 'true');
            const options = Array.from(menu.querySelectorAll('button')); const index = options.indexOf(document.activeElement);
            options[(index + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length]?.focus();
        });
        select.addEventListener('invalid', (event) => { event.preventDefault(); trigger.classList.add('invalid'); trigger.focus(); });
        select.addEventListener('change', () => { if (select.name === 'departamento_codigo') municipalities(select.form, select.value); render(); });
        select.addEventListener('options-updated', render);
        render();
    });
}
export const initializeBranchGeography = initializeRecordSelects;
document.addEventListener('click', (event) => { if (!event.target.closest('.record-select-fields')) closeMenus(); });
