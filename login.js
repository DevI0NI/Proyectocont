document.addEventListener('DOMContentLoaded', function () {
    
    // --- 1. LÓGICA MOSTRAR/OCULTAR CONTRASEÑA ---
    const togglePassword = document.querySelector('#togglePassword');
    const passwordInput = document.querySelector('#password');

    if (togglePassword && passwordInput) {
        togglePassword.addEventListener('click', function () {
            // Cambiar el atributo tipo: de password a text y viceversa
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            
            // Alternar las clases del icono (FontAwesome)
            this.classList.toggle('fa-eye');
            this.classList.toggle('fa-eye-slash');
        });
    }

    // --- 2. LÓGICA MENSAJE DE ERROR (FADE OUT) ---
    const errorMessage = document.querySelector('.error-message');
    // No necesitamos seleccionar loginContainer para la altura, el DOM se ajusta solo al remover el hijo.

    if (errorMessage) {
        // Espera 3.5 segundos antes de empezar a desaparecer (un poco más ágil que 4s)
        setTimeout(() => {
            errorMessage.classList.add('fade-out');
            
            // Espera 0.5s (lo que dura la animación CSS) para eliminarlo del DOM
            setTimeout(() => {
                errorMessage.remove();
            }, 500); 
        }, 3500);
    }
});