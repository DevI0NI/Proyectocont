export function initializeAccountFlash(root = document, delay = setTimeout) {
    root.querySelectorAll('[data-account-flash]').forEach((notice) => {
        if (notice.dataset.dismissReady) return;
        notice.dataset.dismissReady = 'true';
        delay(() => {
            notice.classList.add('is-dismissed');
            notice.setAttribute('aria-hidden', 'true');
        }, 5000);
    });
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => initializeAccountFlash());
    } else {
        initializeAccountFlash();
    }
}
