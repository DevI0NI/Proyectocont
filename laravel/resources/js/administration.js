import { createElement, Pencil, CirclePause, RotateCcw, Plus, ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight, ChevronDown, ArrowUp, ArrowDown, ArrowUpDown, Search } from 'lucide';

function initializeAdministration() {
    const main = document.querySelector('.administration-main');
    if (!main) return;
    const size = new URL(location.href).searchParams.get('per_page');
    if (size) {
        main.querySelectorAll('.filter-pill, .catalog-status-tabs a, .toolbar-actions a.button.secondary').forEach((link) => {
            const url = new URL(link.href);
            url.searchParams.set('per_page', size);
            url.searchParams.delete('page');
            link.href = url.href;
        });
        const search = main.querySelector('.catalog-search');
        if (search && !search.querySelector('[name=per_page]')) {
            const input = document.createElement('input');
            input.type = 'hidden'; input.name = 'per_page'; input.value = size;
            search.append(input);
        }
    }
    const icons = { 'chevron-left':ChevronLeft, 'chevron-right':ChevronRight, 'chevrons-left':ChevronsLeft, 'chevrons-right':ChevronsRight, 'chevron-down':ChevronDown, 'arrow-up':ArrowUp, 'arrow-down':ArrowDown, 'arrow-up-down':ArrowUpDown, search:Search, plus:Plus };
    main.querySelectorAll('[data-admin-icon]').forEach((element) => element.replaceChildren(createElement(icons[element.dataset.adminIcon], { 'aria-hidden':'true' })));
    main.querySelectorAll('.row-actions .action-link').forEach((control) => {
        const label = control.textContent.trim();
        const name = control.closest('tr')?.querySelector('td strong')?.textContent.trim();
        control.title = name ? label + ' ' + name : label;
        control.setAttribute('aria-label', control.title);
        control.classList.add('admin-action');
        control.replaceChildren(createElement(control.classList.contains('danger') ? CirclePause : label === 'Reactivar' ? RotateCcw : Pencil, { 'aria-hidden':'true' }));
    });
    main.querySelectorAll('.table-toolbar a.button.primary, .page-heading a.button.primary').forEach((control) => {
        if (!control.querySelector('[data-catalog-icon]')) control.prepend(createElement(Plus, { 'aria-hidden':'true' }));
    });
}
document.addEventListener('DOMContentLoaded', initializeAdministration);
document.addEventListener('administration:updated', initializeAdministration);
document.addEventListener('administration:refresh', () => loadPage(location.href, false));

let pending;
let columnMenu;
let rowMenu;
function closeRowMenu(immediate = false) {
    if (!rowMenu) return;
    const { root, menu, trigger } = rowMenu;
    rowMenu = null;
    root.classList.remove('open');
    trigger.setAttribute('aria-expanded', 'false');
    menu.getAnimations().forEach((animation) => animation.cancel());
    const finish = () => { menu.hidden = true; menu.classList.remove('is-open'); root.append(menu); };
    if (immediate) finish();
    else menu.animate([{ opacity:1, transform:'translateY(0) scale(1)' }, { opacity:0, transform:'translateY(-6px) scale(.98)' }], { duration:160, easing:'ease-out' }).onfinish = finish;
}
function positionRowMenu() {
    if (!rowMenu) return;
    const box = rowMenu.trigger.getBoundingClientRect();
    rowMenu.menu.style.setProperty('--options-left', Math.max(8, Math.min(box.left, innerWidth - rowMenu.menu.offsetWidth - 8)) + 'px');
    rowMenu.menu.style.setProperty('--options-top', Math.max(8, Math.min(box.bottom + 8, innerHeight - rowMenu.menu.offsetHeight - 8)) + 'px');
}
document.addEventListener('administration:pause', () => closeRowMenu(true));
function closeColumnMenu(animate = true) {
    if (!columnMenu) return;
    const { menu, trigger, parent } = columnMenu;
    menu.querySelectorAll('[data-admin-choice].open').forEach((dropdown) => togglePageSize(dropdown, false));
    menu.getAnimations().forEach((animation) => animation.cancel());
    trigger.setAttribute('aria-expanded', 'false');
    columnMenu = null;
    const finish = () => { menu.hidden = true; parent.append(menu); };
    if (menu.classList.contains('containers-header-menu')) {
        menu.classList.remove('is-open');
        if (animate) setTimeout(() => { if (!menu.classList.contains('is-open')) finish(); }, 160);
        else finish();
    } else if (animate && menu.classList.contains('live-column-menu')) {
        menu.style.pointerEvents = 'none';
        menu.animate([{ opacity:1, transform:'translateY(0)' }, { opacity:0, transform:'translateY(-4px)' }], { duration:160, easing:'ease-out' }).onfinish = finish;
    } else finish();
}
function openColumnMenu(trigger, animate = true) {
    const parent = trigger.parentElement;
    const menu = parent.querySelector('.admin-column-menu, .containers-header-menu') || trigger.adminColumnMenu;
    trigger.adminColumnMenu = menu;
    menu.getAnimations().forEach((animation) => animation.cancel());
    columnMenu = { menu, trigger, parent };
    document.body.append(menu);
    menu.hidden = false;
    menu.style.pointerEvents = '';
    trigger.setAttribute('aria-expanded', 'true');
    positionColumnMenu();
    if (menu.classList.contains('containers-header-menu')) {
        if (animate) requestAnimationFrame(() => { if (columnMenu?.menu === menu) menu.classList.add('is-open'); });
        else menu.classList.add('is-open');
    }
    if (animate && menu.classList.contains('live-column-menu')) menu.animate([{ opacity:0, transform:'translateY(-4px)' }, { opacity:1, transform:'translateY(0)' }], { duration:200, easing:'cubic-bezier(.2,0,0,1)' });
}
function togglePageSize(dropdown, open) {
    const menu = dropdown.querySelector('.form-select-menu');
    dropdown.classList.toggle('open', open);
    dropdown.querySelector('button').setAttribute('aria-expanded', String(open));
    if (dropdown.matches('[data-admin-choice]')) {
        menu.inert = !open;
        if (open) {
            const box = dropdown.getBoundingClientRect();
            const below = innerHeight - box.bottom - 16;
            const upwards = below < 120 && box.top > below;
            dropdown.classList.toggle('opens-above', upwards);
            menu.style.maxHeight = `${Math.max(80, Math.min(172, upwards ? box.top - 16 : below))}px`;
        }
        return;
    }
    if (dropdown.closest('.standard-records-table') || dropdown.matches('[data-admin-choice]')) {
        menu.getAnimations().forEach((animation) => animation.cancel());
        const closed = { opacity:0, transform:'translateY(-6px) scale(.98)' };
        const opened = { opacity:1, transform:'translateY(0) scale(1)' };
        menu.animate(open ? [closed, opened] : [opened, closed], { duration:matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 200, easing:'cubic-bezier(.2,0,0,1)' });
    }
}
function positionColumnMenu() {
    if (!columnMenu) return;
    const box = columnMenu.trigger.getBoundingClientRect();
    if (columnMenu.menu.classList.contains('containers-header-menu')) {
        const header = columnMenu.trigger.closest('th')?.getBoundingClientRect() || box;
        const width = Math.max(190, columnMenu.menu.offsetWidth || 190);
        const left = Math.min(header.left + header.width / 2 - width / 2, innerWidth - width - 12);
        columnMenu.menu.style.setProperty('--column-menu-left', `${Math.max(12, left)}px`);
        const height = columnMenu.menu.offsetHeight;
        const fitsBelow = header.bottom + 8 + height <= innerHeight - 12;
        const top = fitsBelow ? header.bottom + 8 : Math.max(12, header.top - height - 8);
        columnMenu.menu.style.setProperty('--column-menu-top', `${top}px`);
        return;
    }
    columnMenu.menu.style.left = Math.max(8, Math.min(box.left, innerWidth - columnMenu.menu.offsetWidth - 8)) + 'px';
    columnMenu.menu.style.top = Math.max(8, Math.min(box.bottom + 8, innerHeight - columnMenu.menu.offsetHeight - 8)) + 'px';
}
async function loadPage(url, push = true, live = false) {
    closeRowMenu(true);
    if (!live) clearTimeout(liveTimer);
    if (!live) closeColumnMenu();
    pending?.abort();
    const controller = new AbortController();
    pending = controller;
    const main = document.querySelector('.administration-main');
    main.setAttribute('aria-busy', 'true');
    try {
        const response = await fetch(url, { signal:controller.signal });
        if (!response.ok || response.redirected) { location.assign(response.url || url); return; }
        const documentNext = new DOMParser().parseFromString(await response.text(), 'text/html');
        const content = documentNext.querySelector('.administration-main');
        if (!content) { location.assign(url); return; }
        if (pending !== controller || controller.signal.aborted) return;
        const active = document.activeElement;
        const focus = live && active?.matches('[data-admin-live-query] input[type=search]') ? { name:active.name, start:active.selectionStart, end:active.selectionEnd } : null;
        const menuKey = live ? columnMenu?.trigger.dataset.columnKey : null;
        closeColumnMenu(false);
        main.replaceChildren(...content.childNodes);
        if (push) history[live ? 'replaceState' : 'pushState']({ administration:true }, '', url);
        document.dispatchEvent(new Event('administration:updated'));
        if (menuKey) {
            const trigger = Array.from(main.querySelectorAll('[data-admin-column-trigger]')).find((element) => element.dataset.columnKey === menuKey);
            if (trigger) openColumnMenu(trigger, false);
        }
        if (focus) {
            const input = Array.from(document.querySelectorAll('[data-admin-live-query] input[type=search]')).find((element) => element.name === focus.name);
            input?.focus({ preventScroll:true });
            input?.setSelectionRange(focus.start, focus.end);
        }
        if (!live) main.querySelector('.admin-table-toolbar')?.scrollIntoView({ block:'nearest' });
    } catch (error) {
        if (error.name !== 'AbortError') location.assign(url);
    } finally {
        if (pending === controller) main.removeAttribute('aria-busy');
    }
}
document.addEventListener('click', (event) => {
    const optionsTrigger = event.target.closest('[data-record-options] .options-trigger');
    if (optionsTrigger) {
        const showing = rowMenu?.trigger === optionsTrigger;
        closeRowMenu();
        if (!showing) {
            const root = optionsTrigger.closest('[data-record-options]');
            const menu = root.querySelector('.record-options-menu') || optionsTrigger.branchOptionsMenu;
            optionsTrigger.branchOptionsMenu = menu;
            menu.getAnimations().forEach((animation) => animation.cancel());
            rowMenu = { root, menu, trigger:optionsTrigger };
            document.body.append(menu);
            menu.hidden = false;
            menu.classList.add('is-open');
            root.classList.add('open');
            optionsTrigger.setAttribute('aria-expanded', 'true');
            positionRowMenu();
            menu.animate([{ opacity:0, transform:'translateY(-6px) scale(.98)' }, { opacity:1, transform:'translateY(0) scale(1)' }], { duration:180, easing:'ease-out' });
        }
    } else if (rowMenu && !rowMenu.menu.contains(event.target)) closeRowMenu();
    const columnTrigger = event.target.closest('[data-admin-column-trigger]');
    if (columnTrigger) {
        const showing = columnMenu?.trigger === columnTrigger;
        closeColumnMenu();
        if (!showing) {
            openColumnMenu(columnTrigger);
        }
    } else if (columnMenu && !columnMenu.menu.contains(event.target)) closeColumnMenu();
    const trigger = event.target.closest('[data-admin-page-size] .form-select-trigger');
    if (trigger) {
        const dropdown = trigger.closest('[data-admin-page-size]');
        togglePageSize(dropdown, !dropdown.classList.contains('open'));
    }
    document.querySelectorAll('[data-admin-page-size].open').forEach((dropdown) => {
        if (!dropdown.contains(event.target)) {
            togglePageSize(dropdown, false);
        }
    });
    const link = event.target.closest('[data-admin-page]');
    if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
    event.preventDefault();
    loadPage(link.href || link.dataset.url);
});
document.addEventListener('keydown', (event) => {
    const choice = event.target.closest('[data-admin-choice]');
    if (choice && ['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
        event.preventDefault();
        if (!choice.classList.contains('open')) togglePageSize(choice, true);
        const options = [...choice.querySelectorAll('[role=option]')];
        if (!options.length) return;
        const current = options.indexOf(document.activeElement);
        const next = event.key === 'Home' ? 0 : event.key === 'End' ? options.length - 1
            : current < 0 ? (event.key === 'ArrowUp' ? options.length - 1 : 0)
            : (current + (event.key === 'ArrowUp' ? -1 : 1) + options.length) % options.length;
        options[next].focus();
        return;
    }
    if (event.key !== 'Escape') return;
    const optionsTrigger = rowMenu?.trigger;
    closeRowMenu();
    optionsTrigger?.focus();
    const trigger = columnMenu?.trigger;
    closeColumnMenu();
    trigger?.focus();
    document.querySelectorAll('[data-admin-page-size].open').forEach((dropdown) => {
        togglePageSize(dropdown, false);
        const trigger = dropdown.querySelector('button');
        trigger.setAttribute('aria-expanded', 'false');
        trigger.focus();
    });
});
document.addEventListener('submit', (event) => {
    const form = event.target.closest('[data-admin-query]');
    if (!form) return;
    event.preventDefault();
    const url = new URL(form.action);
    url.search = new URLSearchParams(new FormData(form)).toString();
    clearTimeout(liveTimer);
    loadPage(url.href, true, form.hasAttribute('data-admin-live-query'));
});
let liveTimer;
document.addEventListener('administration:pause', () => { clearTimeout(liveTimer); pending?.abort(); });
document.addEventListener('input', (event) => {
    const input = event.target.closest('[data-admin-live-query] input[type=search]');
    if (!input || event.isComposing) return;
    clearTimeout(liveTimer);
    pending?.abort();
    liveTimer = setTimeout(() => {
        const form = input.form;
        if (!form?.isConnected) return;
        const url = new URL(form.action);
        url.search = new URLSearchParams(new FormData(form)).toString();
        url.searchParams.set('page', '1');
        loadPage(url.href, true, true);
    }, 300);
});
window.addEventListener('resize', positionColumnMenu);
window.addEventListener('resize', positionRowMenu);
document.addEventListener('scroll', positionRowMenu, true);
document.addEventListener('scroll', positionColumnMenu, true);
window.addEventListener('popstate', () => {
    if (document.querySelector('.admin-pagination')) loadPage(location.href, false);
});
