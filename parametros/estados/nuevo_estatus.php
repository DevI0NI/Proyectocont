<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

// Habilitar la visualización de errores
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '../../config/database.php';

$error_message = '';

// 1. Cargar los Tipos de Estatus para el Select (Unidad, Estructura)
// Esto asegura que enviemos el ID correcto a la base de datos
$stmtTipos = $pdo->query("SELECT id, nombre FROM tipos_estatus WHERE estado_id = 1");
$tipos_disponibles = $stmtTipos->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $tipo_id = $_POST['tipo_estatus_id']; // Recibimos el ID
    $nombre = trim($_POST['nombre']); 

    try {
        $pdo->beginTransaction();

        // 2. Verificar si el estatus ya existe para ese TIPO específico
        // Obtenemos el estado_id para saber qué mensaje mostrar
        $stmt = $pdo->prepare('SELECT id, estado_id FROM estatus_contenedores WHERE nombre = :nombre AND tipo_estatus_id = :tipo_id');
        $stmt->execute(['nombre' => $nombre, 'tipo_id' => $tipo_id]);
        $existente = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existente) {
            if ($existente['estado_id'] == 1) {
                // Caso: Existe y está activo
                throw new Exception('Este estatus ya existe para la categoría seleccionada.');
            } else {
                // Caso: Existe y está inactivo (Soft Deleted)
                throw new Exception('Este estatus existe en el historial pero está deshabilitado. Contacte al administrador si desea reactivarlo.');
            }
        }

        // 3. Insertar el nuevo estatus
        // Usamos tipo_estatus_id y forzamos estado_id = 1
        $stmt = $pdo->prepare('INSERT INTO estatus_contenedores (nombre, tipo_estatus_id, estado_id) VALUES (:nombre, :tipo_id, 1)');
        $stmt->execute([
            'nombre' => $nombre,
            'tipo_id' => $tipo_id
        ]);

        $pdo->commit();

        // Redirigir
        $_SESSION['toast'] = ['type' => 'success', 'text' => 'Estatus creado correctamente.'];
        
        // Para redirigir correctamente al filtro, buscamos el nombre del tipo
        $stmtNombre = $pdo->prepare("SELECT nombre FROM tipos_estatus WHERE id = ?");
        $stmtNombre->execute([$tipo_id]);
        $nombreTipo = $stmtNombre->fetchColumn() ?: 'Unidad';

        header('Location: estados.php?tipo=' . urlencode($nombreTipo));
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error_message = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nuevo Estatus</title>
    <link rel="stylesheet" href="../../styles/parametros/estados/nuevo_estatus.css">
    <style>
        .error { border: 2px solid red; }
        .valid { border: 2px solid green; }
        /* Estilos para el mensaje de error PHP */
        .alert-error { 
            background-color: #f8d7da; 
            color: #721c24; 
            padding: 10px; 
            border-radius: 5px; 
            margin-bottom: 15px; 
            text-align: center; 
            border: 1px solid #f5c6cb;
            font-weight: bold;
        }
        /* Estilos para validación JS */
        .error-message { color: red; display: none; margin-top: 5px; font-size: 0.9em; }
        .valid-message { color: green; display: none; opacity: 1; transition: opacity 1s ease-out; margin-top: 5px; font-size: 0.9em; }
        .valid-message.fade-out { opacity: 0; }
    </style>
</head>
<body>
<header>
  <div class="header-left">
    <a href="../../index.php">
      <img src="../../imagenes/IPSA.png" alt="Logo" class="logo">
      <span class="brand-name">IPROPORTSA</span>
    </a>
  </div>
  <div class="header-right">
    <span class="welcome-label">Bienvenido,</span>
    <span class="username"><?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?></span>
    <a href="../../logout.php" class="logout-button">Cerrar sesión</a>
  </div>
</header>

    <main>
        <h1>Nuevo Estatus</h1>
        
        <?php if ($error_message): ?>
            <div class="alert-error">
                ⚠️ <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <form action="nuevo_estatus.php" method="post">
            <div class="form-group">
                <label for="tipo_estatus_id">Tipo de Estatus:</label>
                <!-- El name cambia a tipo_estatus_id para ser más claro, el ID es lo que importa -->
                <select id="tipo_estatus_id" name="tipo_estatus_id" required>
                    <option value="">Seleccione un tipo</option>
                    <?php foreach ($tipos_disponibles as $t): ?>
                        <?php 
                            // Mantener selección si hubo error o viene por URL
                            $selected = '';
                            if (isset($_POST['tipo_estatus_id']) && $_POST['tipo_estatus_id'] == $t['id']) {
                                $selected = 'selected';
                            } elseif (isset($_GET['tipo']) && $_GET['tipo'] === $t['nombre']) {
                                $selected = 'selected';
                            }
                        ?>
                        <option value="<?php echo $t['id']; ?>" <?php echo $selected; ?>>
                            <?php echo htmlspecialchars($t['nombre']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="nombre">Nombre del Estatus:</label>
                <input type="text" id="nombre" name="nombre" required 
                       pattern="[A-Za-zÁÉÍÓÚáéíóúÑñ0-9\s\-\.]+" 
                       title="Solo se permiten letras, números, espacios, guiones y puntos."
                       value="<?php echo htmlspecialchars($_POST['nombre'] ?? ''); ?>"
                       autocomplete="off">
                
                <span class="error-message" id="nombre-error">Este estatus ya existe.</span>
                <span class="valid-message" id="nombre-valid">Nombre disponible.</span>
            </div>

            <div class="buttons">
                <!-- El botón regresar intenta volver al filtro correcto -->
                <a href="estados.php?tipo=<?php echo urlencode($_GET['tipo'] ?? 'Unidad'); ?>" class="regresar-button">
                    <img src="../../imagenes/regresar.png" alt="Regresar">
                    <span>Regresar</span>
                </a>
                <button type="submit" class="guardar-button">
                    <img src="../../imagenes/Save.png" alt="Guardar">
                    <span>Guardar</span>
                </button>
            </div>
        </form>
    </main>
    <footer>
        <p>&copy; IPSA Web - 2025</p>
    </footer>

    <script>
        const nombreInput = document.getElementById('nombre');
        const tipoSelect = document.getElementById('tipo_estatus_id');
        const errorSpan = document.getElementById('nombre-error');
        const validSpan = document.getElementById('nombre-valid');

        function validarNombre() {
            const nombre = nombreInput.value.trim();
            // Necesitamos enviar el nombre del tipo para que coincida con tu verificar anterior, 
            // O BIEN actualizar verificar_nombre_estatus.php para que acepte ID.
            // Por compatibilidad con tu estructura visual, enviamos el texto del select.
            const tipoTexto = tipoSelect.options[tipoSelect.selectedIndex]?.text || '';

            // Resetear
            nombreInput.classList.remove('error', 'valid');
            errorSpan.style.display = 'none';
            validSpan.style.display = 'none';

            if (nombre.length === 0 || tipoSelect.value === "") return;

            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'verificar_nombre_estatus.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            
            xhr.onload = function() {
                if (xhr.status === 200) {
                    if (xhr.responseText === 'exists') {
                        nombreInput.classList.add('error');
                        errorSpan.style.display = 'inline';
                    } else {
                        nombreInput.classList.add('valid');
                        validSpan.style.display = 'inline';
                        
                        validSpan.classList.remove('fade-out');
                        setTimeout(() => validSpan.classList.add('fade-out'), 2000);
                        setTimeout(() => validSpan.style.display = 'none', 3000);
                    }
                }
            };
            // Enviamos 'tipo' como texto porque verificar_nombre_estatus.php (el original) buscaba por nombre.
            // Si cambias ese archivo, ajusta esto.
            xhr.send('nombre=' + encodeURIComponent(nombre) + '&tipo=' + encodeURIComponent(tipoTexto));
        }

        nombreInput.addEventListener('input', validarNombre);
        tipoSelect.addEventListener('change', validarNombre);
    </script>
</body>
</html>