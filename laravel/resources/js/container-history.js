import { initializeRecordSelects } from './branch-geography';
import '../css/container-history.css';

function selectHistoryEvent(root, index, focus = false) {
    const tabs = [...root.querySelectorAll('[data-history-event]')];
    if (!tabs.length) return;
    const selected = Math.max(0, Math.min(index, tabs.length - 1));
    tabs.forEach((tab, position) => {
        tab.setAttribute('aria-selected', String(position === selected));
        tab.tabIndex = position === selected ? 0 : -1;
    });
    root.querySelectorAll('[data-history-panel]').forEach((panel, position) => { panel.hidden = position !== selected; });
    root.querySelector('[data-history-previous]').disabled = selected === 0;
    root.querySelector('[data-history-next]').disabled = selected === tabs.length - 1;
    if (focus) {
        tabs[selected].focus({ preventScroll: true });
        tabs[selected].scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'instant' });
    }
}

function initializeHistory() {
    if (!document.querySelector('[data-container-history]')) return;
    initializeRecordSelects();
    if (!document.querySelector('.history-filters')) return;
    try {
        const saved = JSON.parse(sessionStorage.getItem('proportsa-history-return') || 'null');
        if (saved?.url === location.pathname + location.search) {
            sessionStorage.removeItem('proportsa-history-return');
            requestAnimationFrame(() => window.scrollTo({ top: saved.scrollY, behavior: 'instant' }));
        }
    } catch { /* Navigation remains available when browser storage is disabled. */ }
}

document.addEventListener('DOMContentLoaded', initializeHistory);
document.addEventListener('administration:updated', initializeHistory);
document.addEventListener('click', (event) => {
    const root = event.target.closest('[data-history-timeline]');
    if (root) {
        const tab = event.target.closest('[data-history-event]');
        if (tab) selectHistoryEvent(root, Number(tab.dataset.historyEvent));
        const direction = event.target.closest('[data-history-previous], [data-history-next]');
        if (direction) {
            const current = root.querySelector('[data-history-event][aria-selected="true"]');
            selectHistoryEvent(root, Number(current?.dataset.historyEvent || 0) + (direction.hasAttribute('data-history-next') ? 1 : -1), true);
        }
    }
    if (!event.target.closest('[data-history-view]')) return;
    try {
        sessionStorage.setItem('proportsa-history-return', JSON.stringify({ url: location.pathname + location.search, scrollY: window.scrollY }));
    } catch { /* Browser storage is optional. */ }
});

document.addEventListener('keydown', (event) => {
    const tab = event.target.closest('[data-history-event]');
    if (!tab || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
    event.preventDefault();
    const root = tab.closest('[data-history-timeline]');
    const index = event.key === 'Home' ? 0 : event.key === 'End' ? root.querySelectorAll('[data-history-event]').length - 1
        : Number(tab.dataset.historyEvent) + (event.key === 'ArrowRight' ? 1 : -1);
    selectHistoryEvent(root, index, true);
});
document.addEventListener('change', (event) => {
    const input = event.target;
    if (input.matches('select[name=cycle]') && input.closest('[data-history-cycle-form]')) {
        input.form?.requestSubmit();
        return;
    }
    if (!input.closest('[data-history-query]') || !input.matches('select, input[type=date], input[type=number]')) return;
    input.form?.requestSubmit();
});
