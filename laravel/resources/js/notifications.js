import { createElement, CheckCheck, MailOpen, Archive, ArchiveRestore, Inbox, X, Wrench, ShieldCheck, Search, ChevronDown, ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight, Eye, Image as ImageIcon, Plus, Send, Ellipsis, ListX } from 'lucide';
import { initializeRecordSelects } from './branch-geography';

const icons = { 'check-check': CheckCheck, 'mail-open': MailOpen, archive: Archive, 'archive-restore': ArchiveRestore, inbox: Inbox, x: X, wrench: Wrench, 'shield-check': ShieldCheck, search: Search, 'chevron-down': ChevronDown, 'chevron-left': ChevronLeft, 'chevron-right': ChevronRight, 'chevrons-left': ChevronsLeft, 'chevrons-right': ChevronsRight, eye: Eye, image: ImageIcon, plus: Plus, send: Send, ellipsis: Ellipsis, 'list-x': ListX };

window.proportsaNotificationSelection = (wire) => ({
    selected: wire.entangle('selected'),
    excluded: wire.entangle('excluded'),
    all: wire.entangle('allSelected'),
    refreshingAll: false,
    get total() { return Number(wire.notificationTotal); },
    get count() {
        if (this.refreshingAll) return this.total;
        return this.all ? Math.max(0, Number(wire.selectionTotal) - this.excluded.length + this.selected.length) : this.selected.length;
    },
    isSelected(id) {
        id = String(id);
        return this.all && Number(id) <= Number(wire.selectionMaxId) ? !this.excluded.includes(id) : this.selected.includes(id);
    },
    toggle(id) {
        id = String(id);
        const property = this.all && Number(id) <= Number(wire.selectionMaxId) ? 'excluded' : 'selected';
        this[property] = this[property].includes(id) ? this[property].filter((value) => value !== id) : [...this[property], id];
    },
    clear() {
        this.selected = [];
        this.excluded = [];
        this.all = false;
    },
    async toggleAll() {
        if (this.count > 0 && this.count === this.total) return this.clear();
        const refreshSnapshot = Number(wire.selectionTotal) < this.total;
        this.selected = [];
        this.excluded = [];
        this.all = this.total > 0;
        if (refreshSnapshot) {
            this.refreshingAll = true;
            try { await wire.toggleAll(true); }
            finally { this.refreshingAll = false; }
        }
    },
});

const initializedBells = new WeakSet();
window.addEventListener('pageshow', (event) => {
    if (event.persisted && document.querySelector('[data-notification-review]')) location.reload();
});

document.addEventListener('submit', (event) => {
    const form = event.target.closest('form[data-notification-open]');
    if (form) form.elements.return_to.value = location.pathname + location.search + location.hash;
}, true);

function initializeBells(root) {
    const selector = '[data-notification-bell], [data-notification-filter], [data-notification-actions]';
    const bells = [...(root.matches?.(selector) ? [root] : []), ...root.querySelectorAll(selector)];
    bells.forEach((bell) => {
        if (initializedBells.has(bell)) return;
        initializedBells.add(bell);
        const trigger = bell.querySelector('[data-notification-toggle]');
        const panel = bell.querySelector('[data-notification-popup]');
        const isFilter = bell.hasAttribute('data-notification-filter');
        const isActions = bell.hasAttribute('data-notification-actions');
        let open = false;
        let animation;

        function setOpen(nextOpen, restoreFocus = false) {
            if (open === nextOpen) return;
            if (nextOpen) document.dispatchEvent(new CustomEvent('notification-popover-opening', { detail: bell }));
            const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;
            const closedTransform = reducedMotion ? 'none' : 'translateY(-10px) scale(.98)';
            const computed = getComputedStyle(panel);
            const from = panel.hidden ? { opacity: 0, transform: closedTransform }
                : { opacity: computed.opacity, transform: computed.transform };
            animation?.cancel();
            open = nextOpen;
            trigger.setAttribute('aria-expanded', String(open));
            panel.hidden = false;
            panel.inert = !open;
            const to = { opacity: open ? 1 : 0, transform: open ? (reducedMotion ? 'none' : 'translateY(0) scale(1)') : closedTransform };
            panel.style.opacity = String(to.opacity);
            panel.style.transform = to.transform;
            const current = panel.animate([from, to], {
                duration: reducedMotion ? 140 : (open ? 300 : 240),
                easing: open ? 'cubic-bezier(.2,.7,.2,1)' : 'cubic-bezier(.4,0,.2,1)',
            });
            animation = current;
            current.finished.then(() => {
                if (animation !== current) return;
                panel.hidden = !open;
                panel.style.removeProperty('opacity');
                panel.style.removeProperty('transform');
                animation = null;
            }).catch(() => {});
            if (restoreFocus) trigger.focus();
        }

        trigger.addEventListener('click', () => setOpen(!open));
        document.addEventListener('notification-popover-opening', (event) => {
            if (event.detail !== bell) setOpen(false);
        });
        if (isFilter || isActions) {
            panel.addEventListener('click', (event) => {
                if (event.target.closest('[data-notification-filter-option], [role="menuitem"]')) setOpen(false, true);
            });
            bell.addEventListener('keydown', (event) => {
                if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
                event.preventDefault();
                setOpen(true);
                const options = [...panel.querySelectorAll(isActions ? '[role="menuitem"]' : '[role="option"]')];
                if (!options.length) return;
                const current = options.indexOf(document.activeElement);
                let next = event.key === 'Home' ? 0 : event.key === 'End' ? options.length - 1
                    : current < 0 ? (event.key === 'ArrowUp' ? options.length - 1 : 0)
                    : (current + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length;
                options[next].focus();
            });
        }
        document.addEventListener('pointerdown', (event) => {
            if (!bell.contains(event.target)) setOpen(false);
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && open) setOpen(false, true);
        });
        document.addEventListener('focusin', (event) => {
            if (!bell.contains(event.target)) setOpen(false);
        });
    });
}

function renderIcons(root) {
    const elements = [...(root.matches?.('[data-notification-icon]') ? [root] : []), ...root.querySelectorAll('[data-notification-icon]')];
    elements.forEach((element) => {
        const icon = icons[element.dataset.notificationIcon];
        if (icon && !element.firstElementChild) element.append(createElement(icon, { 'aria-hidden': 'true' }));
    });
}

function initializeRepairReview() {
    const review = document.querySelector('[data-notification-review]');
    if (!review) return;
    const tabs = [...review.querySelectorAll('[data-maintenance-tab]')];
    const panels = [...review.querySelectorAll('[data-maintenance-panel]')];
    const previous = review.querySelector('[data-review-previous]');
    const next = review.querySelector('[data-review-next]');
    const finish = review.querySelector('[data-review-finish]');
    let step = Number(review.dataset.initialStep);
    const reveal = (element) => {
        if (!matchMedia('(prefers-reduced-motion: reduce)').matches) {
            element.animate([{ opacity: 0, transform: 'translateY(5px)' }, { opacity: 1, transform: 'none' }], { duration: 200, easing: 'ease-out' });
        }
    };
    function selectStep(index, focusTab = false) {
        step = Math.max(0, Math.min(tabs.length - 1, index));
        tabs.forEach((tab, position) => {
            tab.classList.toggle('active', position === step);
            tab.classList.toggle('completed', position < step);
            tab.setAttribute('aria-selected', String(position === step));
            tab.tabIndex = position === step ? 0 : -1;
        });
        panels.forEach((panel) => {
            const active = Number(panel.dataset.maintenancePanel) === step;
            panel.hidden = !active;
            if (active) reveal(panel);
        });
        previous.hidden = step === 0;
        next.hidden = step === tabs.length - 1;
        finish.hidden = !next.hidden;
        const url = new URL(location.href);
        url.searchParams.set('step', String(step));
        history.replaceState(history.state, '', url);
        if (focusTab) tabs[step].focus();
    }
    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => selectStep(index));
        tab.addEventListener('keydown', (event) => {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            selectStep(event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (step + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length, true);
        });
    });
    previous.addEventListener('click', () => selectStep(step - 1, true));
    next.addEventListener('click', () => selectStep(step + 1, true));
    review.querySelectorAll('[data-review-gallery]').forEach((gallery) => {
        const slides = [...gallery.querySelectorAll('[data-review-slide]')];
        const back = gallery.querySelector('[data-review-photo-previous]');
        const forward = gallery.querySelector('[data-review-photo-next]');
        let active = 0;
        function selectPhoto(index) {
            active = Math.max(0, Math.min(slides.length - 1, index));
            slides.forEach((slide, position) => { slide.hidden = position !== active; });
            back.disabled = active === 0;
            forward.disabled = active === slides.length - 1;
            gallery.querySelector('[data-review-photo-position]').textContent = `${active + 1} / ${slides.length}`;
            reveal(slides[active]);
        }
        back.addEventListener('click', () => selectPhoto(active - 1));
        forward.addEventListener('click', () => selectPhoto(active + 1));
        gallery.addEventListener('keydown', (event) => {
            if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
            event.preventDefault();
            selectPhoto(active + (event.key === 'ArrowRight' ? 1 : -1));
        });
    });
    const viewer = review.querySelector('[data-review-viewer]');
    let closing = false;
    async function closeViewer() {
        if (!viewer.open || closing) return;
        closing = true;
        if (!matchMedia('(prefers-reduced-motion: reduce)').matches) {
            await viewer.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 160, easing: 'ease-in' }).finished;
        }
        viewer.close();
        viewer.querySelector('img').removeAttribute('src');
        closing = false;
    }
    review.querySelectorAll('[data-review-photo]').forEach((link) => link.addEventListener('click', (event) => {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        const image = viewer.querySelector('[data-review-viewer-image]');
        image.src = link.href;
        image.alt = link.dataset.name;
        viewer.querySelector('[data-review-viewer-name]').textContent = link.dataset.name;
        viewer.showModal();
        reveal(viewer);
    }));
    viewer.querySelector('[data-review-viewer-close]').addEventListener('click', closeViewer);
    viewer.addEventListener('cancel', (event) => { event.preventDefault(); closeViewer(); });
    viewer.addEventListener('click', (event) => { if (event.target === viewer) closeViewer(); });
}

let hooksRegistered = false;
function initializeCorrectionHistory() {
    const history = document.querySelector('[data-correction-history]');
    if (!history) return;
    const viewer = history.querySelector('[data-correction-viewer]');
    let source;
    let closing = false;
    async function close() {
        if (!viewer.open || closing) return;
        closing = true;
        if (!matchMedia('(prefers-reduced-motion: reduce)').matches) {
            await viewer.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 160 }).finished;
        }
        viewer.close();
        viewer.querySelector('img').removeAttribute('src');
        source?.focus();
        closing = false;
    }
    history.addEventListener('click', (event) => {
        const link = event.target.closest('.notification-evidence a');
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        source = link;
        const image = viewer.querySelector('img');
        image.src = link.href;
        image.alt = link.querySelector('img').alt;
        viewer.querySelector('h2').textContent = image.alt;
        viewer.showModal();
        if (!matchMedia('(prefers-reduced-motion: reduce)').matches) {
            viewer.animate([{ opacity: 0, transform: 'translateY(8px)' }, { opacity: 1, transform: 'none' }], { duration: 200, easing: 'ease-out' });
        }
    });
    viewer.querySelector('[data-correction-viewer-close]').addEventListener('click', close);
    viewer.addEventListener('cancel', (event) => { event.preventDefault(); close(); });
    viewer.addEventListener('click', (event) => { if (event.target === viewer) close(); });
}

function registerHooks() {
    if (hooksRegistered || !window.Livewire) return;
    hooksRegistered = true;
    window.Livewire.hook('morph.added', ({ el }) => {
        if (el.closest?.('[data-notification-surface]')) {
            renderIcons(el);
            initializeBells(el);
        }
    });
}

registerHooks();
document.addEventListener('administration:updated', () => renderIcons(document));
document.addEventListener('DOMContentLoaded', () => {
    renderIcons(document);
    initializeBells(document);
    initializeRepairReview();
    initializeCorrectionHistory();
    document.querySelectorAll('[data-correction-form]').forEach((form) => {
        const list = form.querySelector('[data-correction-materials]');
        let index = Math.max(-1, ...[...list.querySelectorAll('select')].map((select) => Number(select.name.match(/\[(\d+)\]/)?.[1] ?? -1))) + 1;
        form.querySelector('[data-correction-add]').addEventListener('click', () => {
            if (list.children.length >= 40) return;
            list.insertAdjacentHTML('beforeend', form.querySelector('[data-correction-template]').innerHTML.replaceAll('__INDEX__', String(index++)));
            initializeRecordSelects();
            renderIcons(list);
            list.lastElementChild.querySelector('.form-select-trigger')?.focus();
        });
        list.addEventListener('click', (event) => event.target.closest('[data-correction-remove]')?.closest('[data-correction-material-row]').remove());
        form.addEventListener('submit', (event) => {
            if (form.dataset.submitting) { event.preventDefault(); return; }
            form.dataset.submitting = 'true';
        });
    });
    registerHooks();
});
document.addEventListener('livewire:init', registerHooks);
