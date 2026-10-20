import { createElement, Check, CircleAlert } from 'lucide';
let notification;
let notificationTimer;

function bringNotificationForward() {
    if (!notification?.isConnected || typeof notification.showPopover !== 'function') return;
    const times = new Map(notification.getAnimations({ subtree:true }).map((animation) => [animation.animationName, animation.currentTime]));
    if (notification.matches(':popover-open')) notification.hidePopover();
    notification.showPopover();
    notification.getAnimations({ subtree:true }).forEach((animation) => {
        if (times.has(animation.animationName)) animation.currentTime = times.get(animation.animationName);
    });
}
export function notify(message, type = 'success') {
    clearTimeout(notificationTimer);
    notification?.remove();
    const toast = document.createElement('div');
    toast.className = 'toast-notification branch-toast' + (type === 'error' ? ' branch-toast-error' : '');
    toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
    const icon = document.createElement('div');
    icon.className = 'toast-icon';
    icon.append(createElement(type === 'error' ? CircleAlert : Check, { 'aria-hidden':'true' }));
    const copy = document.createElement('div');
    copy.className = 'toast-copy';
    const title = document.createElement('strong');
    title.textContent = type === 'error' ? 'No se pudo completar' : 'Listo';
    const text = document.createElement('span');
    text.textContent = message;
    copy.append(title, text);
    const progress = document.createElement('div');
    progress.className = 'toast-progress';
    progress.setAttribute('aria-hidden', 'true');
    progress.innerHTML = '<svg viewBox="0 0 36 36"><circle cx="18" cy="18" r="15.5" pathLength="100"></circle></svg>';
    toast.append(icon, copy, progress);
    notification = toast;
    if (typeof toast.showPopover === 'function') {
        toast.setAttribute('popover', 'manual');
        document.body.append(toast);
        toast.showPopover();
    } else {
        document.body.append(toast);
    }
    notificationTimer = setTimeout(() => {
        toast.classList.add('toast-leaving');
        notificationTimer = setTimeout(() => { toast.remove(); if (notification === toast) notification = null; }, 300);
    }, 2700);
}

export function open(dialog) {
    document.dispatchEvent(new Event('administration:pause'));
    dialog.showModal();
    bringNotificationForward();
    dialog.animate([
        { opacity:0, transform:'translate(-50%, -48%) scale(.98)' },
        { opacity:1, transform:'translate(-50%, -50%) scale(1)' },
    ], { duration:220, easing:'cubic-bezier(.2,0,0,1)' });
}
export async function close(dialog) {
    if (!dialog?.open || dialog.dataset.closing) return;
    dialog.dataset.closing = 'true';
    await dialog.animate([
        { opacity:1, transform:'translate(-50%, -50%) scale(1)' },
        { opacity:0, transform:'translate(-50%, -48%) scale(.98)' },
    ], { duration:160, easing:'ease-out' }).finished;
    dialog.close();
    delete dialog.dataset.closing;
}
export function initializeNotifications(selector) {
    const toast = document.querySelector('#statusToast');
    if (toast) {
        const message = toast.querySelector('.toast-copy span').textContent;
        toast.remove();
        notify(message);
    }
    const errors = document.querySelector(selector);
    if (errors) { notify(JSON.parse(errors.textContent).join(' '), 'error'); errors.remove(); }
}
