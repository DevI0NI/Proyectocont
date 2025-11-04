document.addEventListener('DOMContentLoaded', function () {
    const errorMessage = document.querySelector('.error-message');
    const loginContainer = document.querySelector('.login-container');

    if (errorMessage) {
        setTimeout(() => {
            errorMessage.classList.add('fade-out');
            setTimeout(() => {
                errorMessage.remove();
                loginContainer.style.height = 'auto';
            }, 500); // Tiempo para la animación de fade-out
        }, 4000);
    }
});