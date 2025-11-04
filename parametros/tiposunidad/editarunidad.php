<?php
// filepath: c:\xampp\htdocs\proyectocont\parametros\tiposunidad\editarunidad.php

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

// Mostrar errores en desarrollo (puedes desactivar en prod)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '../../config/database.php';

// ========= Helpers de notificación =========
function toast($msg, $type='success'){
    $_SESSION['toast_message'] = $msg;
    $_SESSION['toast_type']    = $type;
}
function back_to_self($id){
    header('Location: editarunidad.php?id='.urlencode($id));
    exit;
}

// ========= Obtener ID del tipo =========
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ========= Cargar tipo de unidad =========
$stmt = $pdo->prepare('SELECT * FROM tipos_unidad WHERE id = :id');
$stmt->execute(['id' => $id]);
$tipoUnidad = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$tipoUnidad) {
    toast('El tipo de unidad no existe.', 'error');
    header('Location: tipos_unidad.php'); exit;
}

// ========= Cargar modelos (con ID para poder renombrar sin borrar) =========
$stmtModelos = $pdo->prepare('SELECT id, nombre FROM modelos WHERE tipo_unidad_id = :id ORDER BY nombre');
$stmtModelos->execute(['id' => $id]);
$modelos = $stmtModelos->fetchAll(PDO::FETCH_ASSOC);

// ========= POST (guardar) =========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Normalizamos entradas
    $nombre        = trim($_POST['nombre'] ?? '');
    $observaciones = trim($_POST['observaciones'] ?? '');

    // Pares id/nombre de modelos desde el form
    $modelos_ids     = $_POST['modelos_ids']     ?? [];
    $modelos_nombres = $_POST['modelos_nombres'] ?? [];

    // Validación de nombre del tipo
    if ($nombre === '') {
        toast('El nombre no puede estar vacío.', 'error');
        back_to_self($id);
    }

    try {
        $pdo->beginTransaction();

        // 1) Validar duplicado de tipo (excluyendo el actual)
        $q = $pdo->prepare('SELECT COUNT(*) FROM tipos_unidad WHERE nombre = :nombre AND id <> :id');
        $q->execute(['nombre' => $nombre, 'id' => $id]);
        if ((int)$q->fetchColumn() > 0) {
            throw new Exception('Ese tipo de unidad ya existe.');
        }

        // 2) Actualizar tipo (solo nombre/descripcion)
        $upTipo = $pdo->prepare('UPDATE tipos_unidad SET nombre = :nombre, descripcion = :descripcion WHERE id = :id');
        $upTipo->execute([
            'nombre'      => $nombre,
            'descripcion' => $observaciones,
            'id'          => $id
        ]);

        // 3) Sincronizar modelos sin romper FKs
        //    - Renombrar los existentes por ID
        //    - Insertar los nuevos (id vacío)
        //    - Borrar los que ya no vienen *solo si no tienen usos* (usos = contenedores)
        //
        //    Esto reemplaza el "DELETE all + INSERT" y evita el error 1451.

        // a) Mapear existentes (id => nombre) para saber cuáles desaparecen
        $stmtExist = $pdo->prepare('SELECT id, nombre FROM modelos WHERE tipo_unidad_id = ?');
        $stmtExist->execute([$id]);
        $existentes = $stmtExist->fetchAll(PDO::FETCH_KEY_PAIR); // [id => nombre]

        $ids_enviados = [];
        $nuevos_insertados = 0;
        $renombrados = 0;

        // Recorremos el form
        if (is_array($modelos_nombres) && is_array($modelos_ids)) {
            $total = max(count($modelos_nombres), count($modelos_ids));
            for ($i=0; $i<$total; $i++){
                $mid = isset($modelos_ids[$i]) ? (int)$modelos_ids[$i] : 0;
                $mnom = trim($modelos_nombres[$i] ?? '');
                if ($mnom === '') continue; // ignorar vacíos

                if ($mid > 0) {
                    // Renombrar existente (si cambia)
                    if (!isset($existentes[$mid])) {
                        // Caso raro: me mandaron un ID que no pertenece a este tipo
                        throw new Exception('Modelo inválido para este tipo.');
                    }
                    if ($existentes[$mid] !== $mnom) {
                        // Intentar renombrar; capturar duplicados (índice único nombre+tipo)
                        try {
                            $upM = $pdo->prepare('UPDATE modelos SET nombre = ? WHERE id = ? AND tipo_unidad_id = ?');
                            $upM->execute([$mnom, $mid, $id]);
                            $renombrados++;
                        } catch (PDOException $e) {
                            // Nombre duplicado dentro del mismo tipo
                            throw new Exception('Ya existe un modelo con el nombre "'.$mnom.'" en este tipo.');
                        }
                    }
                    $ids_enviados[] = $mid;
                } else {
                    // Insertar nuevo
                    try {
                        $ins = $pdo->prepare('INSERT INTO modelos (nombre, tipo_unidad_id) VALUES (?, ?)');
                        $ins->execute([$mnom, $id]);
                        $nuevos_insertados++;
                        $ids_enviados[] = (int)$pdo->lastInsertId();
                    } catch (PDOException $e) {
                        throw new Exception('Ya existe un modelo con el nombre "'.$mnom.'" en este tipo.');
                    }
                }
            }
        }

        // b) Determinar cuáles borrar (los existentes que ya no vienen en el form)
        $ids_existentes = array_map('intval', array_keys($existentes));
        $ids_enviados   = array_map('intval', $ids_enviados);
        $ids_a_borrar   = array_diff($ids_existentes, $ids_enviados);

        // c) Borrar solo los que no tienen usos; si tienen usos, los bloqueamos y avisamos
        $bloqueados = [];
        foreach ($ids_a_borrar as $mid) {
            // usos en contenedores
            $q = $pdo->prepare('SELECT COUNT(*) FROM contenedores WHERE modelo_id = ?');
            $q->execute([$mid]);
            $usos = (int)$q->fetchColumn();

            if ($usos > 0) {
                $bloqueados[] = ['id'=>$mid, 'nombre'=>$existentes[$mid] ?? ('#'.$mid), 'usos'=>$usos];
                continue;
            }

            $del = $pdo->prepare('DELETE FROM modelos WHERE id = ? AND tipo_unidad_id = ?');
            $del->execute([$mid, $id]);
        }

        $pdo->commit();

        // Mensajes bonitos
        if (!empty($bloqueados)) {
            $nombres = implode(', ', array_map(fn($b)=>$b['nombre'].' ('.$b['usos'].' uso/s)', $bloqueados));
            toast('Guardado con advertencias: no se eliminaron modelos en uso → '.$nombres, 'error');
        } else {
            toast('Cambios guardados: tipo actualizado, '.$renombrados.' renombrado(s), '.$nuevos_insertados.' nuevo(s).', 'success');
        }

        back_to_self($id);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        toast($e->getMessage(), 'error');
        back_to_self($id);
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Tipo de Unidad</title>
    <link rel="stylesheet" href="../../styles/parametros/tiposunidad/nuevaunidad.css">
    <style>
        .error { border: 2px solid red; }
        .valid { border: 2px solid green; }
        .error-message { color: red; display: none; }
        .valid-message { color: green; display: none; opacity: 1; transition: opacity 1s ease-out; }
        .valid-message.fade-out { opacity: 0; }

        /* ===== Phone Toast (clases únicas, sin conflicto con frameworks) ===== */
        #toast-container{
          position: fixed;
          top: 10px;
          left: 50%;
          transform: translateX(-50%);
          z-index: 11000;
          display: flex;
          flex-direction: column;
          gap: 10px;
          pointer-events: none; /* no bloquea clics del contenido detrás */
        }

        .phone-toast{
          --dur: 4200ms;
          position: relative;
          min-width: clamp(260px, 60vw, 520px);
          padding: 12px 14px;
          border-radius: 14px;
          box-shadow: 0 10px 28px rgba(0,0,0,.22);
          color: #fff;
          font-weight: 600;
          background: #111;
          display: grid;
          grid-template-columns: 24px 1fr auto;
          align-items: center;
          column-gap: 10px;

          /* Estado inicial: fuera de vista */
          transform: translateY(-120%);
          opacity: 0;

          pointer-events: auto; /* permite click en la X */
          will-change: transform, opacity;
        }

        .phone-toast.show{
          animation: phoneToastDown 450ms cubic-bezier(.2,.9,.2,1) forwards;
        }
        .phone-toast.hide{
          animation: phoneToastUp 350ms cubic-bezier(.4,0,1,.6) forwards;
        }

        .phone-toast.success{ background: #0f5132; }
        .phone-toast.error  { background: #842029; }
        .phone-toast.info   { background: #1d4ed8; }

        .phone-toast .icon{
          width: 24px;
          height: 24px;
          display: grid;
          place-items: center;
          border-radius: 999px;
          background: rgba(255,255,255,.18);
          font-size: 14px;
          line-height: 1;
        }
        .phone-toast .close{
          cursor: pointer;
          padding: 6px 8px;
          user-select: none;
          opacity: .9;
        }
        .phone-toast .life{
          position: absolute;
          left: 0;
          right: 0;
          bottom: 0;
          height: 3px;
          background: rgba(255,255,255,.65);
          transform-origin: left;
          animation: phoneToastLife var(--dur) linear forwards;
        }

        @keyframes phoneToastDown{
          to{ transform: translateY(0); opacity: 1; }
        }
        @keyframes phoneToastUp{
          to{ transform: translateY(-120%); opacity: 0; }
        }
        @keyframes phoneToastLife{
          from{ transform: scaleX(1); }
          to  { transform: scaleX(0); }
        }

        /* Inputs modelos en fila */
        .modelo-row{ display:flex; gap:10px; margin-bottom:8px; align-items:center; }
        .modelo-row input[type="text"]{ flex:1; }
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
    <span class="username"><?php echo $_SESSION['username']; ?></span>
    <a href="../../logout.php" class="logout-button">Cerrar sesión</a>
  </div>
</header>

<main>
  <h1>Modificar Tipo de Unidad</h1>

  <form id="tipoUnidadForm" action="editarunidad.php?id=<?php echo $id; ?>" method="post">
    <div class="form-group">
      <label for="nombre">Nombre:</label>
      <input
        type="text" id="nombre" name="nombre"
        value="<?php echo htmlspecialchars($tipoUnidad['nombre']); ?>"
        required pattern="[A-Za-zÁÉÍÓÚáéíóúÑñ\s]+"
        title="Solo se permiten letras y espacios.">
      <span class="error-message" id="nombre-error">Ese tipo de unidad ya existe.</span>
      <span class="valid-message" id="nombre-valid">Nombre disponible.</span>
    </div>

    <div class="form-group">
      <label for="observaciones">Observaciones:</label>
      <textarea id="observaciones" name="observaciones"><?php echo htmlspecialchars($tipoUnidad['descripcion']); ?></textarea>
    </div>

    <div class="form-group">
      <label for="modelos">Modelos:</label>
      <div id="modelos-container">
        <?php foreach ($modelos as $modelo): ?>
          <div class="modelo-row">
            <!-- ID oculto para poder RENOMBRAR sin borrar -->
            <input type="hidden" name="modelos_ids[]" value="<?php echo (int)$modelo['id']; ?>">
            <input type="text" name="modelos_nombres[]" value="<?php echo htmlspecialchars($modelo['nombre']); ?>">
          </div>
        <?php endforeach; ?>
        <?php if (empty($modelos)): ?>
          <div class="modelo-row">
            <input type="hidden" name="modelos_ids[]" value="0">
            <input type="text" name="modelos_nombres[]" placeholder="Modelo 1">
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="form-group">
      <div class="modelos-buttons">
        <button type="button" id="agregarModelo" class="guardar-button">
          <img src="../../imagenes/mas.png" alt="Agregar">
          <span>Agregar Modelo</span>
        </button>
        <button type="button" id="eliminarModelo" class="eliminar-button">
          <img src="../../imagenes/menos.png" alt="Eliminar">
          <span>Eliminar Modelo</span>
        </button>
      </div>
    </div>

    <div class="buttons">
      <a href="tipos_unidad.php" class="regresar-button">
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

<!-- Toasts -->
<div id="toast-container" aria-live="polite" aria-atomic="true"></div>
<script>
  // Notificación tipo teléfono (clases únicas .phone-toast)
  function showToast(message, type = 'success', duration = 4200){
    // 1) Contenedor
    const container = document.getElementById('toast-container') || (() => {
      const c = document.createElement('div');
      c.id = 'toast-container';
      document.body.appendChild(c);
      return c;
    })();

    // 2) Crear toast
    const el = document.createElement('div');
    el.className = `phone-toast ${type}`;
    el.style.setProperty('--dur', duration + 'ms');

    const icons = { success:'✓', error:'!', info:'ℹ︎' };
    el.innerHTML = `
      <div class="icon">${icons[type] || '•'}</div>
      <div class="msg">${message}</div>
      <div class="close" aria-label="Cerrar">×</div>
      <div class="life"></div>
    `;

    // 3) Insertar arriba
    container.prepend(el);

    // 4) Fuerza reflow y activa entrada
    void el.offsetWidth;          // asegura que el estado inicial se registre
    el.classList.add('show');

    // 5) Cierre con animación de salida
    let closed = false;
    const safeRemove = () => { if (el && el.remove) el.remove(); };
    const hide = () => {
      if (closed) return;
      closed = true;
      void el.offsetWidth;        // asegura transición show->hide
      el.classList.remove('show');
      el.classList.add('hide');
      // Fallback por si algún CSS externo cancela animaciones:
      const failsafe = setTimeout(safeRemove, 650);
      el.addEventListener('animationend', () => { clearTimeout(failsafe); safeRemove(); }, { once: true });
    };

    // 6) Botón cerrar y autocierre
    const timer = setTimeout(hide, duration);
    el.querySelector('.close').addEventListener('click', () => {
      clearTimeout(timer);
      hide();
    });
  }

  // Disparo desde PHP (sesión)
  <?php if (isset($_SESSION['toast_message'])): ?>
  document.addEventListener('DOMContentLoaded', function(){
    showToast('<?php echo addslashes($_SESSION['toast_message']); ?>',
              '<?php echo addslashes($_SESSION['toast_type'] ?? "info"); ?>');
  });
  <?php unset($_SESSION['toast_message'], $_SESSION['toast_type']); endif; ?>
</script>


<script>
  // Verificar en tiempo real si el nombre ya existe
  document.getElementById('nombre').addEventListener('input', function() {
      var nombre = document.getElementById('nombre').value;
      var xhr = new XMLHttpRequest();
      xhr.open('POST', 'verificar_nombre.php', true);
      xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
      xhr.onreadystatechange = function() {
          if (xhr.readyState === 4 && xhr.status === 200) {
              if (xhr.responseText === 'exists') {
                  document.getElementById('nombre').classList.add('error');
                  document.getElementById('nombre').classList.remove('valid');
                  document.getElementById('nombre-error').style.display = 'inline';
                  document.getElementById('nombre-valid').style.display = 'none';
              } else {
                  document.getElementById('nombre').classList.add('valid');
                  document.getElementById('nombre').classList.remove('error');
                  document.getElementById('nombre-error').style.display = 'none';
                  document.getElementById('nombre-valid').style.display = 'inline';
                  setTimeout(function() {
                      document.getElementById('nombre-valid').classList.add('fade-out');
                  }, 2000);
                  setTimeout(function() {
                      document.getElementById('nombre-valid').style.display = 'none';
                      document.getElementById('nombre-valid').classList.remove('fade-out');
                  }, 3000);
              }
          }
      };
      xhr.send('nombre=' + encodeURIComponent(nombre) + '&id=<?php echo $id; ?>');
  });

  // Agregar más campos de modelo dinámicamente
  document.getElementById('agregarModelo').addEventListener('click', function() {
      var container = document.getElementById('modelos-container');
      var row = document.createElement('div');
      row.className = 'modelo-row';
      // id 0 => nuevo
      row.innerHTML = '<input type="hidden" name="modelos_ids[]" value="0">' +
                      '<input type="text" name="modelos_nombres[]" placeholder="Modelo ' + (container.children.length + 1) + '">';
      container.appendChild(row);
  });

  // Eliminar el último campo de modelo agregado (sólo quita el input del formulario, no borra en BD si está en uso)
  document.getElementById('eliminarModelo').addEventListener('click', function() {
      var container = document.getElementById('modelos-container');
      if (container.children.length > 1) {
          container.removeChild(container.lastElementChild);
      }
  });
</script>
</body>
</html>
