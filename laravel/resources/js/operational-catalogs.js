import { createElement, Bell, Check, CheckCheck, Inbox, Ship, ShieldCheck, Container, Package, Wrench, Plus, Search, Pencil, CirclePause, RotateCcw, BookOpen, ChevronDown, ArrowLeft, ArrowRight, Save, Send, Download } from 'lucide';
const icons = { bell: Bell, check: Check, 'check-check': CheckCheck, inbox: Inbox, ship: Ship, 'shield-check': ShieldCheck, container: Container, package: Package, wrench: Wrench, plus: Plus, search: Search, pencil: Pencil, 'circle-pause': CirclePause, 'rotate-ccw': RotateCcw, 'book-open': BookOpen, 'chevron-down': ChevronDown, 'arrow-left': ArrowLeft, 'arrow-right': ArrowRight, save: Save, send: Send, download: Download };
function initializeCatalogs() {
    document.querySelectorAll('[data-catalog-icon]').forEach((element) => {
        const icon = icons[element.dataset.catalogIcon];
        if (icon) element.replaceChildren(createElement(icon, { 'aria-hidden': 'true' }));
    });
    const dialog = document.querySelector('#catalogStatusDialog');
    if (!dialog) return;
    document.querySelectorAll('[data-catalog-status]').forEach((button) => button.addEventListener('click', () => {
        const disable = button.dataset.status === '2';
        document.querySelector('#catalogStatusTitle').textContent = (disable ? 'Desactivar ' : 'Reactivar ') + button.dataset.name;
        document.querySelector('#catalogStatusDescription').textContent = disable
            ? 'El registro dejara de estar disponible para nuevas selecciones. Sus relaciones y su historial se conservaran.'
            : 'El registro volvera a estar disponible para nuevas selecciones.';
        document.querySelector('#catalogStatusForm').action = button.dataset.action;
        document.querySelector('#catalogNewStatus').value = button.dataset.status;
        dialog.showModal();
        dialog.querySelector('[data-catalog-close]').focus();
    }));
    dialog.querySelector('[data-catalog-close]').addEventListener('click', () => dialog.close());
}
document.addEventListener('DOMContentLoaded', initializeCatalogs);
document.addEventListener('administration:updated', initializeCatalogs);
