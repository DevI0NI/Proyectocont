<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// 1. CONTEXTO DE SUCURSAL
if (!isset($_SESSION['sucursal_activa'])) {
    header('Location: ../index.php');
    exit;
}
$sucursal_id = $_SESSION['sucursal_activa'];
$rol_id = (int)$_SESSION['rol_id'];

// BLINDAJE DE ROL
if (!in_array($rol_id, [1, 2, 3])) {
    header('Location: ../index.php');
    exit;
}

require '../config/database.php';
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Despachos de Contenedores</title>
    <link rel="stylesheet" href="../styles/traslados/traslados.css">
    
    <style>
        /* Ajustes específicos para despachos */
        .info-card {
            background-color: #e3f2fd;
            border-left: 5px solid #2196F3;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
            display: none; 
        }
        .info-row { margin: 5px 0; font-size: 1rem; color: #333; }
        .badge-ok { background-color: #c8e6c9; color: #2e7d32; padding: 2px 6px; border-radius: 4px; font-weight: bold; }
        .badge-error { background-color: #ffcdd2; color: #c62828; padding: 2px 6px; border-radius: 4px; font-weight: bold; }
        
        /* ESTILOS NUEVOS PARA OBSERVACIONES */
        .obs-container {
            text-align: center; /* Centra el contenido */
            margin-top: 15px;
        }
        .obs-textarea {
            width: 90%;           /* No ocupa todo el ancho */
            max-width: 900px;     /* Ancho máximo fijo */
            margin: 0 auto;       /* Centrado horizontal */
            display: block;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
            resize: none;         /* Evita que el usuario cambie el tamaño */
            font-family: inherit;
        }
        .char-counter {
            display: block;
            font-size: 0.8rem;
            color: #666;
            margin-top: 5px;
            text-align: center;
        }
        .text-danger { color: #dc3545; font-weight: bold; }

        #toast-container { position: fixed; top: 20px; left: 50%; transform: translateX(-50%); z-index: 9999; }
        .toast { padding: 15px 20px; border-radius: 8px; color: #fff; font-weight: bold; margin-bottom: 10px; opacity: 0; transition: opacity 0.5s; }
        .toast.show { opacity: 1; }
        .toast.error { background-color: #dc3545; }
        .toast.success { background-color: #28a745; }
    </style>
</head>
<body>

<div id="toast-container"></div>

<header>
  <div class="header-left">
    <a href="../index.php">
      <img src="../imagenes/IPSA.png" alt="Logo" class="logo">
      <span class="brand-name">IPROPORTSA</span>
    </a>
  </div>
  <div class="header-right">
    <span class="welcome-label">Bienvenido,</span>
    <span class="username"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
    <a href="../logout.php" class="logout-button">Cerrar sesión</a>
  </div>
</header>

<main>
    <h1>Despacho de Contenedores</h1>
    
    <form action="procesar_despacho.php" method="post" id="formDespacho">
        
        <div class="ubicacion-container">
            <h2>Seleccionar Contenedor</h2>
            <div class="ubicacion-fields" style="position: relative;">
                <input type="text" id="buscar-contenedor" placeholder="Buscar por código..." autocomplete="off" onkeyup="buscarContenedor(this.value)">
                <input type="hidden" id="contenedor_id" name="contenedor_id" required>
                <div id="resultados-contenedores" class="resultados-container" style="display: none;"></div>
            </div>
        </div>

        <div id="info-contenedor" class="info-card">
            <div class="info-row"><strong>Ubicación:</strong> <span id="info-ubicacion"></span></div>
            <div class="info-row"><strong>Estatus IPSA:</strong> <span id="info-ipsa"></span></div>
            <div class="info-row"><strong>Estatus PYP:</strong> <span id="info-pyp"></span></div>
            <div class="info-row" style="margin-top:10px; border-top:1px solid #ccc; padding-top:5px;">
                <strong>Estado de Salida:</strong> <span id="info-salida"></span>
            </div>
        </div>

        <div class="ubicacion-container">
            <h2>Datos de Salida</h2>
            <div class="form-group obs-container">
                <label for="observaciones" style="display:block; margin-bottom:8px; font-weight:bold;">Observaciones:</label>
                
                <textarea 
                    id="observaciones" 
                    name="observaciones" 
                    rows="2" 
                    maxlength="150"
                    class="obs-textarea"
                    required 
                    placeholder="Ingrese información extra..."></textarea>
                
            </div>
        </div>

        <div class="buttons">
            <a href="../index.php" class="regresar-button">
                <img src="../imagenes/regresar.png" alt="Regresar">
                <span>Regresar</span>
            </a>
            <button type="submit" class="trasladar-button" id="btn-despachar" disabled style="background-color: #d32f2f;">
                <img src="../imagenes/egresos.png" alt="Despachar">
                <span>Despachar</span>
            </button>
        </div>
    </form>
</main>

<footer><p>&copy; IPSA Web - 2025</p></footer>

<script>
    // Contador de caracteres
    const obsInput = document.getElementById('observaciones');
    const charCount = document.getElementById('charCount');
    
    obsInput.addEventListener('input', function() {
        const currentLength = this.value.length;
        const maxLength = this.getAttribute('maxlength');
        charCount.textContent = `${currentLength} / ${maxLength}`;
        
        if (currentLength >= maxLength) {
            charCount.classList.add('text-danger');
        } else {
            charCount.classList.remove('text-danger');
        }
    });

    function buscarContenedor(query) {
        const resultados = document.getElementById('resultados-contenedores');
        resultados.innerHTML = '';
        if (!query) { resultados.style.display = 'none'; return; }

        fetch(`obtener_contenedores_despacho.php?query=${encodeURIComponent(query)}`)
            .then(r => r.json())
            .then(data => {
                if (data.length > 0) {
                    resultados.style.display = 'block';
                    data.forEach(c => {
                        const item = document.createElement('div');
                        item.className = 'resultado-item';
                        item.textContent = c.nombre;
                        item.onclick = () => seleccionarContenedor(c);
                        resultados.appendChild(item);
                    });
                } else {
                    resultados.style.display = 'none';
                }
            });
    }

    function seleccionarContenedor(c) {
        document.getElementById('buscar-contenedor').value = c.nombre;
        document.getElementById('contenedor_id').value = c.id;
        document.getElementById('resultados-contenedores').style.display = 'none';
        
        // Mostrar Info
        const card = document.getElementById('info-contenedor');
        const btn = document.getElementById('btn-despachar');
        const spanUbic = document.getElementById('info-ubicacion');
        const spanIpsa = document.getElementById('info-ipsa');
        const spanPyp = document.getElementById('info-pyp');
        const spanSalida = document.getElementById('info-salida');

        card.style.display = 'block';
        spanUbic.textContent = `${c.patio} - Bloque ${c.bloque} - Pos ${c.posicion}`;
        spanIpsa.textContent = c.estatus_ipsa;
        spanPyp.textContent = c.estatus_pyp || 'Sin Asignar';

        // Validaciones Visuales
        let puedeSalir = true;
        let mensaje = [];

        // 1. IPSA Disponible
        if (c.estatus_ipsa_id != 1) { 
            puedeSalir = false;
            mensaje.push("Estatus IPSA no es 'Disponible'.");
        }

        // 2. PYP Asignado
        if (!c.estatus_pyp) {
            puedeSalir = false;
            mensaje.push("Falta aprobación/estatus PYP.");
        }

        // 3. Obstrucción Física
        if (c.bloqueado_fisicamente) {
            puedeSalir = false;
            mensaje.push("Bloqueado físicamente por otros contenedores.");
        }

        if (puedeSalir) {
            spanSalida.innerHTML = '<span class="badge-ok">LISTO PARA DESPACHO</span>';
            btn.disabled = false;
            btn.style.opacity = "1";
            btn.style.cursor = "pointer";
        } else {
            spanSalida.innerHTML = `<span class="badge-error">NO APTO: ${mensaje.join(' ')}</span>`;
            btn.disabled = true;
            btn.style.opacity = "0.5";
            btn.style.cursor = "not-allowed";
        }
    }

    // Toast logic
    function showToast(msg, type='success'){
        const c = document.getElementById('toast-container');
        const t = document.createElement('div');
        t.className = `toast ${type}`;
        t.textContent = msg;
        c.appendChild(t);
        setTimeout(() => t.classList.add('show'), 10);
        setTimeout(()=>{ t.classList.remove('show'); setTimeout(()=>t.remove(), 500); }, 4000);
    }
    <?php if (isset($_SESSION['toast_message'])): ?>
        showToast("<?php echo addslashes($_SESSION['toast_message']); ?>", "<?php echo $_SESSION['toast_type'] ?? 'success'; ?>");
        <?php unset($_SESSION['toast_message'], $_SESSION['toast_type']); ?>
    <?php endif; ?>
</script>
</body>
</html>