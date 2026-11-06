const background = document.querySelector('[data-login-background]');

if (background) {
    const reveal = () => requestAnimationFrame(() => background.classList.add('is-loaded'));

    if (background.complete && background.naturalWidth > 0) {
        reveal();
    } else {
        background.addEventListener('load', reveal, { once: true });
        background.addEventListener('error', reveal, { once: true });
    }
}
