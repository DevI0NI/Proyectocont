<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

// 1. CONTEXTO DE SUCURSAL (Obligatorio)
if (!isset($_SESSION['sucursal_activa'])) {
    header('Location: ../index.php');
    exit;
}
$sucursal_id = $_SESSION['sucursal_activa'];

require '../config/database.php';

// 2. OBTENER PATIOS (Filtrados por Sucursal Activa y Estado)
$sqlPatios = 'SELECT id, nombre FROM patios WHERE estado_id = 1 AND sucursal_id = :sucursal ORDER BY nombre ASC';
$stmtPatios = $pdo->prepare($sqlPatios);
$stmtPatios->execute(['sucursal' => $sucursal_id]);
$patios = $stmtPatios->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Traslados de Contenedores</title>
    <link rel="stylesheet" href="../styles/traslados/traslados.css">
    
    <!-- Estilos para las notificaciones flotantes -->
    <style>
        #toast-container { position: fixed; top: 20px; left: 50%; transform: translateX(-50%); z-index: 9999; width: 90%; max-width: 400px; pointer-events: none; }
        .toast { padding: 15px 20px; border-radius: 8px; color: #fff; font-weight: 500; box-shadow: 0 4px 12px rgba(0,0,0,0.15); opacity: 0; transform: translateY(-20px); transition: all 0.3s ease; display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 10px; }
        .toast.show { opacity: 1; transform: translateY(0); }
        .toast.error { background-color: #dc3545; border-left: 5px solid #bd2130; }
        .toast.success { background-color: #28a745; border-left: 5px solid #1e7e34; }
    </style>

    <script>
        // Función para buscar contenedores en tiempo real
        function buscarContenedor(query) {
            const resultados = document.getElementById('resultados-contenedores');
            resultados.innerHTML = ''; // Limpiar resultados anteriores

            if (!query) {
                resultados.style.display = 'none';
                return;
            }

            fetch(`obtener_contenedores.php?query=${query}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        console.error(data.error);
                    } else {
                        resultados.style.display = 'block';
                        data.forEach(contenedor => {
                            const item = document.createElement('div');
                            item.className = 'resultado-item';
                            item.textContent = contenedor.nombre;
                            item.onclick = () => seleccionarContenedor(contenedor.id, contenedor.nombre);
                            resultados.appendChild(item);
                        });
                    }
                })
                .catch(error => console.error('Error al buscar contenedores:', error));
        }

        // Función para seleccionar un contenedor
        function seleccionarContenedor(id, nombre) {
            document.getElementById('buscar-contenedor').value = nombre;
            document.getElementById('contenedor_id').value = id;
            document.getElementById('resultados-contenedores').style.display = 'none';
            mostrarUbicacion(id);
        }

        // Función para mostrar la ubicación actual del contenedor seleccionado
        function mostrarUbicacion(contenedorId) {
            if (!contenedorId) {
                limpiarUbicacionActual();
                return;
            }

            fetch(`obtener_info_contenedor.php?contenedor_id=${contenedorId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert(data.error);
                        limpiarUbicacionActual();
                    } else {
                        document.getElementById('ubicacion-actual').innerHTML = `
                            <input type="text" value="${data.patio_nombre}" readonly>
                            <input type="text" value="Bloque ${data.bloque_numero}" readonly>
                            <input type="text" value="Posición ${data.posicion}" readonly>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error al obtener la ubicación:', error);
                    limpiarUbicacionActual();
                });
        }

        // Función para limpiar la sección de ubicación actual
        function limpiarUbicacionActual() {
            document.getElementById('ubicacion-actual').innerHTML = `
                <div class="ubicacion-fields">
                    <input type="text" value="" readonly>
                    <input type="text" value="" readonly>
                    <input type="text" value="" readonly>
                </div>
            `;
        }

        function cargarBloques(patioId) {
            const bloqueSelect = document.getElementById('nuevo_bloque_id');
            const posicionInput = document.getElementById('nueva_posicion');
            const btnTrasladar = document.querySelector('.trasladar-button');

            bloqueSelect.innerHTML = '<option value="">Seleccione un bloque</option>';
            bloqueSelect.disabled = true;
            posicionInput.value = '';
            posicionInput.disabled = true;
            // Deshabilitar botón al cambiar de patio para evitar errores
            if(btnTrasladar) btnTrasladar.disabled = true;

            if (!patioId) return;

            fetch(`obtener_bloques_disponibles.php?patio_id=${patioId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert(data.error);
                    } else if (data.length > 0) {
                        data.forEach(bloque => {
                            const option = document.createElement('option');
                            option.value = bloque.id;
                            
                            // Lógica de previsualización de espacio (opcional, si el API lo envía)
                            let label = `Bloque ${bloque.numero}`;
                            if(bloque.capacidad_maxima) {
                                const libres = bloque.capacidad_maxima - bloque.capacidad_actual;
                                label += ` (Libres: ${libres})`;
                            }
                            
                            option.textContent = label;
                            bloqueSelect.appendChild(option);
                        });
                        bloqueSelect.disabled = false;
                    } else {
                        alert('No hay bloques disponibles para este patio.');
                    }
                })
                .catch(error => console.error('Error al cargar bloques:', error));
        }

        function cargarPosiciones(bloqueId) {
            const posicionInput = document.getElementById('nueva_posicion');
            const trasladarButton = document.querySelector('.trasladar-button');
            
            posicionInput.value = 'Calculando...';
            posicionInput.disabled = true;
            trasladarButton.disabled = true;

            if (!bloqueId) {
                posicionInput.value = '';
                return;
            }

            fetch(`obtener_posiciones_disponibles.php?bloque_id=${bloqueId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert(data.error);
                        posicionInput.value = '';
                    } else if (data.posicion) {
                        posicionInput.value = data.posicion; // Asignar la posición disponible
                        posicionInput.disabled = false; // Habilitar el campo
                        trasladarButton.disabled = false; // Habilitar el botón
                    } else {
                        alert('No hay posiciones disponibles en este bloque.');
                        posicionInput.value = '';
                    }
                })
                .catch(error => console.error('Error al cargar posiciones:', error));
        }

        // Función para mostrar notificaciones (Toast)
        function showToast(message, type = 'success') {
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.textContent = message;
            container.appendChild(toast);
            
            // Animación de entrada
            void toast.offsetWidth; 
            toast.classList.add('show');

            // Eliminar
            setTimeout(() => { 
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 300);
            }, 4000);
        }
    </script>
</head>
<body>

<!-- Contenedor de Toasts -->
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
        <h1>Traslados de Contenedores</h1>
        <form action="procesar_traslado.php" method="post">
            <!-- Sección de Búsqueda de Contenedor -->
            <div class="ubicacion-container">
                <h2>Buscar Contenedor</h2>
                <div class="ubicacion-fields" style="position: relative;">
                    <input type="text" id="buscar-contenedor" placeholder="Buscar contenedor..." onkeyup="buscarContenedor(this.value)" autocomplete="off">
                    <input type="hidden" id="contenedor_id" name="contenedor_id" required>
                    <div id="resultados-contenedores" class="resultados-container" style="display: none;"></div>
                </div>
            </div>

            <!-- Sección de Ubicación Actual -->
            <div class="ubicacion-container">
                <h2>Ubicación Actual</h2>
                <div id="ubicacion-actual" class="ubicacion-fields" style="display: flex; gap: 10px;">
                    <input type="text" value="" readonly>
                    <input type="text" value="" readonly>
                    <input type="text" value="" readonly>
                </div>
            </div>

            <!-- Sección de Nueva Ubicación -->
            <div class="ubicacion-container">
                <h2>Nueva Ubicación</h2>
                <div class="ubicacion-fields">
                    <select id="nuevo_patio_id" name="nuevo_patio_id" onchange="cargarBloques(this.value)" required>
                        <option value="">Seleccione un patio</option>
                        <?php foreach ($patios as $patio): ?>
                            <option value="<?php echo $patio['id']; ?>"><?php echo htmlspecialchars($patio['nombre']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    
                    <select id="nuevo_bloque_id" name="nuevo_bloque_id" onchange="cargarPosiciones(this.value)" required disabled>
                        <option value="">Seleccione un bloque</option>
                    </select>
                    
                    <input type="text" id="nueva_posicion" name="nueva_posicion" placeholder="Posición" readonly required>
                </div>
            </div>

            <!-- Botones -->
            <div class="buttons">
                <a href="../index.php" class="regresar-button">
                    <img src="../imagenes/regresar.png" alt="Regresar">
                    <span>Regresar</span>
                </a>
                <button type="submit" class="trasladar-button" disabled>
                    <img src="../imagenes/trasladar.png" alt="Trasladar">
                    <span>Trasladar</span>
                </button>
            </div>
        </form>
    </main>
    <footer>
        <p>&copy; IPSA Web - 2025</p>
    </footer>

    <!-- Script PHP al final para inyectar mensajes de sesión -->
    <script>
    <?php if (isset($_SESSION['toast_message'])): ?>
        document.addEventListener('DOMContentLoaded', function () {
            showToast(
                '<?php echo addslashes($_SESSION['toast_message']); ?>', 
                '<?php echo $_SESSION['toast_type'] ?? "success"; ?>'
            );
        });
        <?php unset($_SESSION['toast_message'], $_SESSION['toast_type']); ?>
    <?php endif; ?>
    </script>
</body>
</html>