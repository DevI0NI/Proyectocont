import { createElement, Search, FileSpreadsheet, FileText, ChevronFirst, ChevronLast, ChevronLeft, ChevronRight, ChevronDown, Building2, ListChecks, ListX, FileSearch, Plus, Trash2 } from 'lucide';
import { initializeRecordSelects } from './branch-geography';
import '../css/reports.css';

function updateReportLocations(root) {
    const rows = [...root.querySelectorAll('[data-report-location-row]')];
    const yards = rows.map((row) => row.querySelector('[data-report-yard]').value).filter(Boolean);
    rows.forEach((row) => {
        const yard = row.querySelector('[data-report-yard]');
        yard.required = rows.length > 1;
        yard.options[0].textContent = rows.length === 1 ? 'Todos los patios' : 'Seleccionar patio';
        [...yard.options].forEach((option) => { option.disabled = option.value !== yard.value && yards.includes(option.value); });
        yard.dispatchEvent(new Event('options-updated'));
        row.querySelectorAll('[data-report-block-option]').forEach((input) => {
            input.disabled = !yard.value || input.dataset.patio !== yard.value;
            input.closest('label').hidden = input.disabled;
            if (input.disabled) input.checked = false;
        });
        const menu = row.querySelector('[data-report-location]');
        const selected = [...menu.querySelectorAll('input[name]')].filter((input) => input.checked && !input.disabled);
        menu.querySelector('[data-report-location-value]').textContent = selected.length === 1
            ? selected[0].closest('label').querySelector('span').textContent : selected.length ? `${selected.length} bloques` : 'Todos';
        menu.querySelector('[data-report-location-all]').checked = selected.length === 0;
        const trigger = menu.querySelector('[data-report-location-trigger]');
        trigger.disabled = !yard.value || ![...menu.querySelectorAll('input[name]')].some((input) => !input.disabled);
        if (trigger.disabled) { menu.classList.remove('open'); trigger.setAttribute('aria-expanded', 'false'); }
    });
    const add = root.querySelector('[data-report-add-location]');
    if (add) add.disabled = !rows.length || yards.length !== rows.length || rows.length >= Math.min(50, rows[0].querySelector('[data-report-yard]').options.length - 1);
}

function initializeReportLocations(root) {
    const geography = JSON.parse(root.querySelector('[data-report-location-data]')?.textContent || '[]');
    const close = () => root.querySelectorAll('[data-report-location]').forEach((menu) => {
        menu.classList.remove('open'); menu.querySelector('[data-report-location-trigger]').setAttribute('aria-expanded', 'false');
    });
    const list = root.querySelector('[data-report-locations]');
    let nextIndex = list?.querySelectorAll('[data-report-location-row]').length || 0;
    const icons = () => root.querySelectorAll('[data-report-location-row] [data-report-icon]').forEach((element) => element.replaceChildren(createElement(element.dataset.reportIcon === 'remove' ? Trash2 : ChevronDown, { 'aria-hidden': 'true' })));
    root.addEventListener('click', (event) => {
        const add = event.target.closest('[data-report-add-location]');
        if (add && !add.disabled && list) {
            const fragment = root.querySelector('[data-report-location-template]').content.cloneNode(true);
            fragment.querySelectorAll('[name]').forEach((input) => { input.name = input.name.replace('__INDEX__', String(nextIndex)); });
            nextIndex++;
            list.append(fragment); icons(); initializeRecordSelects(); updateReportLocations(root);
            list.lastElementChild.querySelector('[data-report-yard]').nextElementSibling.querySelector('button').focus();
            root.querySelector('[data-report-dirty]').hidden = false;
        }
        const remove = event.target.closest('[data-report-remove-location]');
        if (remove) {
            const row = remove.closest('[data-report-location-row]');
            if (list.children.length > 1) row.remove();
            else { row.querySelector('[data-report-yard]').value = ''; row.querySelectorAll('input[name]').forEach((input) => { input.checked = false; }); }
            updateReportLocations(root); root.querySelector('[data-report-dirty]').hidden = false;
        }
        const trigger = event.target.closest('[data-report-location-trigger]');
        if (!trigger || trigger.disabled) return;
        const menu = trigger.closest('[data-report-location]');
        const open = !menu.classList.contains('open'); close();
        if (open) { menu.classList.add('open'); trigger.setAttribute('aria-expanded', 'true'); }
    });
    root.addEventListener('change', (event) => {
        if (event.target.matches('[data-report-yard]')) {
            const yard = event.target;
            const row = yard.closest('[data-report-location-row]');
            const options = row.querySelector('[data-report-block-options]');
            const blocks = geography.find((item) => String(item.id) === yard.value)?.blocks || [];
            options.replaceChildren(...blocks.map((block) => {
                const label = document.createElement('label');
                const input = document.createElement('input');
                input.type = 'checkbox'; input.name = yard.name.replace('[patio_id]', '[bloque_ids][]'); input.value = String(block.id);
                input.dataset.reportBlockOption = ''; input.dataset.patio = yard.value;
                const text = document.createElement('span'); text.textContent = `Bloque ${block.number}`;
                label.append(input, text);
                return label;
            }));
            updateReportLocations(root);
        }
        const menu = event.target.closest('[data-report-location]');
        if (!menu) return;
        if (event.target.hasAttribute('data-report-location-all')) menu.querySelectorAll('input[name]').forEach((input) => { input.checked = false; });
        updateReportLocations(root);
    });
    root.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && event.target.closest('[data-report-location]')) { event.preventDefault(); close(); event.target.closest('[data-report-location]').querySelector('[data-report-location-trigger]').focus(); }
    });
    document.addEventListener('click', (event) => { if (!event.target.closest('[data-report-location]')) close(); });
    updateReportLocations(root);
    return () => updateReportLocations(root);
}

function initializeReports() {
    const root = document.querySelector('[data-reports]');
    if (!root || root.dataset.initialized) return;
    root.dataset.initialized = 'true';
    const icons = { search: Search, excel: FileSpreadsheet, pdf: FileText, first: ChevronFirst, last: ChevronLast, previous: ChevronLeft, next: ChevronRight, down: ChevronDown, add: Plus, remove: Trash2, branch: Building2, all: ListChecks, none: ListX, empty: FileSearch };
    root.querySelectorAll('[data-report-icon]').forEach((element) => element.replaceChildren(createElement(icons[element.dataset.reportIcon], { 'aria-hidden': 'true' })));
    const form = root.querySelector('[data-report-form]');
    const originalDisabled = new Map(Array.from(form.querySelectorAll('input, select'), (input) => [input, input.disabled]));
    const updateBlocks = initializeReportLocations(root);
    initializeRecordSelects();
    root.querySelector('[data-report-type]').addEventListener('change', () => {
        form.querySelectorAll('input, select').forEach((input) => { input.disabled = input.name !== 'tipo'; });
        form.requestSubmit();
    });
    const columns = Array.from(form.querySelectorAll('input[name="columns[]"]'));
    const updateColumns = () => {
        columns[0]?.setCustomValidity(columns.some((column) => column.checked) ? '' : 'Selecciona al menos una columna.');
        root.querySelector('[data-report-column-count]').textContent = `${columns.filter((column) => column.checked).length} / ${columns.length}`;
    };
    columns.forEach((input) => input.addEventListener('change', updateColumns));
    root.querySelector('[data-report-columns-all]').addEventListener('click', () => { columns.forEach((column) => { column.checked = true; }); updateColumns(); root.querySelector('[data-report-dirty]').hidden = false; });
    root.querySelector('[data-report-columns-none]').addEventListener('click', () => { columns.forEach((column) => { column.checked = false; }); updateColumns(); root.querySelector('[data-report-dirty]').hidden = false; });
    root.addEventListener('input', () => { root.querySelector('[data-report-dirty]').hidden = false; });
    root.addEventListener('change', () => { root.querySelector('[data-report-dirty]').hidden = false; });
    form.addEventListener('submit', (event) => {
        if (event.submitter?.hasAttribute('data-report-export')) return;
        if (form.dataset.submitting) { event.preventDefault(); return; }
        form.dataset.submitting = 'true';
        root.querySelector('[data-report-preview]').disabled = true;
    });
    window.addEventListener('pageshow', () => {
        delete form.dataset.submitting;
        originalDisabled.forEach((disabled, input) => { input.disabled = disabled; input.dispatchEvent(new Event('options-updated')); });
        updateBlocks();
        root.querySelector('[data-report-preview]').disabled = false;
    });
}

document.addEventListener('DOMContentLoaded', initializeReports);
document.addEventListener('livewire:navigated', initializeReports);
