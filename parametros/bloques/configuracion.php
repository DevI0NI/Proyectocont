<?php
session_start();

// 1. Seguridad de Sesión
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

// 2. Seguridad de Rol (Solo Super Admin y Admin)
if (!in_array($_SESSION['rol_id'], [1, 2])) {
    // Si no tiene permiso, lo mandamos al inicio
    header('Location: ../../index.php');
    exit;
}

// 3. Contexto de Sucursal
// Si no hay sucursal activa (por error), redirigir al index para que se asigne
if (!isset($_SESSION['sucursal_activa'])) {
    header('Location: ../../index.php');
    exit;
}
$sucursal_id = $_SESSION['sucursal_activa'];

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '../../config/database.php';

// 4. Cargar Patios Activos (FILTRADOS POR SUCURSAL)
$sqlPatios = 'SELECT id, nombre FROM patios WHERE estado_id = 1 AND sucursal_id = :sucursal_id ORDER BY nombre ASC';
$stmtPatios = $pdo->prepare($sqlPatios);
$stmtPatios->execute(['sucursal_id' => $sucursal_id]);
$patios = $stmtPatios->fetchAll(PDO::FETCH_ASSOC);

// 5. Cargar Bloques Activos (De los patios de ESTA sucursal)
// Usamos un JOIN para asegurar que los bloques pertenezcan a patios de la sucursal actual
$sqlBloques = '
    SELECT b.id, b.patio_id, b.numero, b.capacidad_maxima 
    FROM bloques b
    JOIN patios p ON b.patio_id = p.id
    WHERE b.estado_id = 1 AND p.sucursal_id = :sucursal_id
    ORDER BY b.numero ASC
';
$stmtBloques = $pdo->prepare($sqlBloques);
$stmtBloques->execute(['sucursal_id' => $sucursal_id]);
$bloques = $stmtBloques->fetchAll(PDO::FETCH_ASSOC);

// Organizar bloques por patio para la vista
$bloquesPorPatio = [];
foreach ($bloques as $bloque) {
    $bloquesPorPatio[$bloque['patio_id']][] = $bloque;
}

// Obtener nombre de la sucursal para mostrarlo
$stmtNomSuc = $pdo->prepare("SELECT nombre FROM sucursales WHERE id = ?");
$stmtNomSuc->execute([$sucursal_id]);
$nombreSucursal = $stmtNomSuc->fetchColumn();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Configuración de Bloques</title>
    <link rel="stylesheet" href="../../styles/parametros/bloques/configuracion.css" />
    
    <style>
        /* Estilos para Toast */
        #toast-container { position: fixed; top: 20px; left: 50%; transform: translateX(-50%); z-index: 9999; width: 90%; max-width: 400px; pointer-events: none; }
        .toast { padding: 15px 20px; border-radius: 8px; color: #fff; font-weight: 500; box-shadow: 0 4px 12px rgba(0,0,0,0.15); opacity: 0; transform: translateY(-20px); transition: all 0.3s ease; display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 10px; }
        .toast.show { opacity: 1; transform: translateY(0); }
        .toast.error { background-color: #dc3545; border-left: 5px solid #bd2130; }
        .toast.success { background-color: #28a745; border-left: 5px solid #1e7e34; }
        
        /* Estilo para el subtítulo de sucursal */
        .sucursal-info {
            text-align: center;
            color: #00695C;
            background-color: #E0F2F1;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #80CBC4;
            display: inline-block;
        }
    </style>

    <script>
      // Mantener scroll después de guardar
      (function(){
        const pos = sessionStorage.getItem('scrollY');
        if (pos) {
          window.addEventListener('load', () => {
            window.scrollTo(0, parseInt(pos,10));
            sessionStorage.removeItem('scrollY');
          });
        }
      })();
    </script>
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

<!-- Contenedor de Toasts -->
<div id="toast-container"></div>

<main>
    <h1>Configuración de Bloques</h1>
    
    <div style="text-align: center;">
        <div class="sucursal-info">
            Editando inventario de: <strong><?php echo htmlspecialchars($nombreSucursal); ?></strong>
        </div>
    </div>

<!-- ===== Patios ===== -->
<section>
  <h2>Configurar Patios</h2>

  <form method="post" action="guardar_configuracion_patios.php" id="form-patios">
    <div id="patios-container">
      <?php foreach ($patios as $patio): ?>
        <div class="checkbox-container" data-id="<?php echo $patio['id']; ?>" id="patio-row-<?php echo $patio['id']; ?>">
          <label for="patio-<?php echo $patio['id']; ?>">
            <?php echo htmlspecialchars($patio['nombre']); ?>
          </label>

          <input
            type="text"
            id="patio-<?php echo $patio['id']; ?>"
            name="patios[<?php echo $patio['id']; ?>][nombre]"
            value="<?php echo htmlspecialchars($patio['nombre']); ?>"
            disabled
          >

          <!-- Checkbox clásico (se oculta en modo eliminar vía JS) -->
          <input
            type="checkbox"
            name="patios_eliminar[]"
            value="<?php echo $patio['id']; ?>"
            class="checkbox-patio"
          >
        </div>
      <?php endforeach; ?>
    </div>

    <div class="patios-buttons" id="patios-buttons">
      <!-- Modo edición -->
      <button type="button" class="editar-patio" id="editar-patio">Editar</button>
      <button type="button" class="agregar-patio btn-cancel hidden" id="cancelar-editar-patio">Cancelar edición</button>

      <!-- Modo agregar -->
      <button type="button" class="agregar-patio" id="agregar-patio">Agregar Patio</button>
      <button type="button" class="agregar-patio btn-cancel hidden" id="cancelar-patio">Cancelar agregar</button>

      <!-- Guardar (se muestra en edición o agregar) -->
      <button
        type="button"
        class="guardar-local hidden"
        id="guardar-patios"
        onclick="submitAndKeepScroll(document.getElementById('form-patios'))"
      >
        Confirmar
      </button>

      <!-- Modo eliminar -->
      <button type="button" class="eliminar-button" id="eliminar-patio" disabled>
        Eliminar Patio
      </button>
    </div>
  </form>
</section>


    <!-- ===== Bloques ===== -->
<section>
  <h2>Configurar Bloques</h2>

  <form method="post" action="guardar_configuracion_bloques.php" id="form-bloques">
    <?php foreach ($patios as $patio): ?>
      <h3><?php echo htmlspecialchars($patio['nombre']); ?></h3>

      <div id="bloques-container-<?php echo $patio['id']; ?>">
        <?php if (isset($bloquesPorPatio[$patio['id']])): ?>
          <?php foreach ($bloquesPorPatio[$patio['id']] as $bloque): ?>
            <div
              class="checkbox-container"
              data-id="<?php echo $bloque['id']; ?>"
              id="bloque-row-<?php echo $bloque['id']; ?>">
              <label for="bloque-<?php echo $bloque['id']; ?>">
                Bloque <?php echo $bloque['numero']; ?>:
              </label>

              <input
                type="number"
                id="bloque-<?php echo $bloque['id']; ?>"
                name="bloques[<?php echo $bloque['id']; ?>]"
                value="<?php echo $bloque['capacidad_maxima']; ?>"
                disabled
              >

              <!-- Checkbox clásico -->
              <input
                type="checkbox"
                name="bloques_eliminar[]"
                value="<?php echo $bloque['id']; ?>"
                class="checkbox-bloque"
              >
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <p>No hay bloques registrados en este patio.</p>
        <?php endif; ?>

        <div class="bloques-buttons" id="bloques-buttons-<?php echo $patio['id']; ?>">
          <!-- Modo edición -->
          <button type="button" class="editar-bloque" data-patio-id="<?php echo $patio['id']; ?>">Editar</button>
          <button type="button" class="agregar-bloque btn-cancel hidden" id="cancelar-editar-bloque-<?php echo $patio['id']; ?>" data-patio-id="<?php echo $patio['id']; ?>">
            Cancelar edición
          </button>

          <!-- Modo agregar -->
          <button type="button" class="agregar-bloque" data-patio-id="<?php echo $patio['id']; ?>">Agregar Bloque</button>
          <button type="button" class="agregar-bloque btn-cancel hidden" id="cancelar-bloque-<?php echo $patio['id']; ?>" data-patio-id="<?php echo $patio['id']; ?>">
            Cancelar agregar
          </button>

          <!-- Guardar local de BLOQUES para este patio -->
          <button
            type="button"
            class="guardar-local hidden"
            id="guardar-bloques-<?php echo $patio['id']; ?>"
            onclick="submitAndKeepScroll(document.getElementById('form-bloques'))">
            Confirmar
          </button>

          <!-- Modo eliminar -->
          <button type="button" class="eliminar-button" id="eliminar-bloque-<?php echo $patio['id']; ?>" disabled>
            Eliminar Bloque
          </button>
        </div>
      </div>
    <?php endforeach; ?>
  </form>
</section>


    <div class="buttons">
        <a href="../parametros.php" class="regresar-button">
            <img src="../../imagenes/regresar.png" alt="Regresar">
            <span>Regresar</span>
        </a>
    </div>

<script>
  // ===== Toasts Logic =====
  function showToast(message, type = 'success') {
    const toastContainer = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.textContent = message;
    toastContainer.appendChild(toast);
    
    // Forzar reflow
    void toast.offsetWidth; 
    toast.classList.add('show');

    // Eliminar después de 4s
    setTimeout(() => { 
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 4000);
  }

  // Inyectar mensaje de PHP si existe
  <?php if (isset($_SESSION['toast_message'])): ?>
  document.addEventListener('DOMContentLoaded', function () {
    showToast(
        '<?php echo addslashes($_SESSION['toast_message']); ?>', 
        '<?php echo $_SESSION['toast_type'] ?? "success"; ?>'
    );
  });
  <?php unset($_SESSION['toast_message'], $_SESSION['toast_type']); ?>
  <?php endif; ?>

  // ===== helpers =====
  const show    = el => el && el.classList.remove('hidden');
  const hide    = el => el && el.classList.add('hidden');
  const disable = el => el && el.classList.add('disabled');
  const enable  = el => el && el.classList.remove('disabled');

  // ===== mantener scroll después de submit =====
  function submitAndKeepScroll(form) {
    sessionStorage.setItem('scrollY', String(window.scrollY));
    form.requestSubmit();
  }

  /* =====================================================================
     PATIOS — 'idle' | 'edit' | 'add' + modo eliminar (por click)
     ===================================================================== */
  const formPatios             = document.getElementById('form-patios');
  const patiosContainer        = document.getElementById('patios-container');
  const btnEditarPatio         = document.getElementById('editar-patio');
  const btnCancelarEditarPatio = document.getElementById('cancelar-editar-patio');
  const btnAgregarPatio        = document.getElementById('agregar-patio');
  const btnCancelarPatio       = document.getElementById('cancelar-patio');
  const btnEliminarPatio       = document.getElementById('eliminar-patio');
  const btnGuardarPatios       = document.getElementById('guardar-patios');

  let patiosMode = 'idle';        // 'idle' | 'edit' | 'add'
  let patiosDeleteMode = false;   // modo eliminar por click

  function setPatiosReadonly(editable){
    document.querySelectorAll('#patios-container input[type="text"]').forEach(i=>{
      i.disabled = !editable;
    });
  }

  function updatePatiosUI(){
    // reset (ocultar todo)
    hide(btnEditarPatio);
    hide(btnCancelarEditarPatio);
    hide(btnAgregarPatio);
    hide(btnCancelarPatio);
    hide(btnGuardarPatios);
    hide(btnEliminarPatio);

    if (patiosMode === 'idle'){
      show(btnEditarPatio);
      show(btnAgregarPatio);
      show(btnEliminarPatio);
      setPatiosReadonly(false); 
      btnEliminarPatio.disabled = false;
      enable(btnEliminarPatio);
    }

    if (patiosMode === 'edit'){
      show(btnCancelarEditarPatio);
      show(btnGuardarPatios);
      setPatiosReadonly(true); 
      document.querySelectorAll('#patios-container input[type="text"]').forEach(i=>{
        i.dataset.original = i.value;
        i.disabled = false;
      });
      setPatiosDeleteMode(false);
    }

    if (patiosMode === 'add'){
      show(btnAgregarPatio);
      show(btnCancelarPatio);
      show(btnGuardarPatios);
      setPatiosReadonly(true);
      setPatiosDeleteMode(false);
    }
  }

  function crearFilaPatioTemporal() {
    const div = document.createElement('div');
    div.classList.add('checkbox-container','is-temp');
    div.dataset.temp = 'patio';
    div.innerHTML = `
      <label>Nuevo Patio</label>
      <input type="text" name="patios_nuevos[][nombre]" placeholder="Nombre del Patio" required>
      <span style="min-width:20px;"></span>
    `;
    patiosContainer.appendChild(div);
  }

  // === MODO ELIMINAR (PATIOS) ===
  function setPatiosDeleteMode(on){
    patiosDeleteMode = on;
    patiosContainer.classList.toggle('delete-mode-hint', on);

    // Si aún existen checkboxes clásicos, ocultarlos
    document.querySelectorAll('#patios-container .checkbox-patio').forEach(cb=>{
      cb.style.display = on ? 'none' : '';
      cb.checked = false;
    });

    btnEliminarPatio.disabled = false;
    enable(btnEliminarPatio);
    btnEliminarPatio.textContent = on ? 'Cancelar selección' : 'Eliminar Patio';

    if (on){
      hide(btnEditarPatio);
      hide(btnAgregarPatio);
      show(btnGuardarPatios); 
    } else {
      patiosContainer.querySelectorAll('.to-delete').forEach(r=>r.classList.remove('to-delete'));
      if (patiosMode === 'idle'){
        show(btnEditarPatio);
        show(btnAgregarPatio);
        hide(btnGuardarPatios);
      }
    }
  }

  // Eventos PATIOS
  btnEditarPatio.addEventListener('click', () => {
    setPatiosDeleteMode(false);
    patiosMode = 'edit';
    updatePatiosUI();
  });

  btnCancelarEditarPatio.addEventListener('click', () => {
    document.querySelectorAll('#patios-container input[type="text"]').forEach(i=>{
      if ('original' in i.dataset){ i.value = i.dataset.original; delete i.dataset.original; }
      i.disabled = true;
    });
    patiosMode = 'idle';
    updatePatiosUI();
  });

  btnAgregarPatio.addEventListener('click', () => {
    setPatiosDeleteMode(false);
    if (patiosMode !== 'add') patiosMode = 'add';
    crearFilaPatioTemporal(); 
    updatePatiosUI();
  });

  btnCancelarPatio.addEventListener('click', () => {
    const temps = patiosContainer.querySelectorAll('[data-temp="patio"]');
    if (temps.length) temps[temps.length-1].remove();
    if (!patiosContainer.querySelector('[data-temp="patio"]')){
      patiosMode = 'idle';
    }
    updatePatiosUI();
  });

  btnEliminarPatio.disabled = false;
  btnEliminarPatio.addEventListener('click', (e)=>{
    e.preventDefault();
    if (patiosMode !== 'idle') { 
      patiosMode = 'idle';
      updatePatiosUI();
    }
    setPatiosDeleteMode(!patiosDeleteMode);
  });

  patiosContainer.addEventListener('click', (ev)=>{
    if (!patiosDeleteMode) return;
    const row = ev.target.closest('.checkbox-container');
    if (!row) return;
    row.classList.toggle('to-delete');
    show(btnGuardarPatios);
  });

  formPatios.addEventListener('submit', ()=>{
    formPatios.querySelectorAll('input[name="patios_eliminar[]"]').forEach(n=>n.remove());
    patiosContainer.querySelectorAll('.to-delete').forEach(row=>{
      const id = row.dataset.id;
      if (!id) return;
      const hidden = document.createElement('input');
      hidden.type  = 'hidden';
      hidden.name  = 'patios_eliminar[]';
      hidden.value = id;
      formPatios.appendChild(hidden);
    });
    setPatiosDeleteMode(false);
  });

  document.getElementById('guardar-patios').onclick = () => submitAndKeepScroll(formPatios);

  /* =====================================================================
     BLOQUES — 'idle' | 'edit' | 'add' + modo eliminar (por patio)
     ===================================================================== */
  const formBloques = document.getElementById('form-bloques');

  function insertBeforeButtons(container, node) {
    const buttonsEl = container.querySelector('.bloques-buttons');
    if (buttonsEl) container.insertBefore(node, buttonsEl); else container.appendChild(node);
  }

  const bloquesMode = {};        
  const bloquesDeleteMode = {};  

  function getMode(patioId){ return bloquesMode[patioId] || 'idle'; }
  function setMode(patioId, m){ bloquesMode[patioId] = m; updateBloquesUI(patioId); }

  function updateBloquesUI(patioId){
    const contBtns      = document.getElementById(`bloques-buttons-${patioId}`);
    const btnEdit       = contBtns.querySelector(`.editar-bloque[data-patio-id="${patioId}"]`);
    const btnCancelEdit = document.getElementById(`cancelar-editar-bloque-${patioId}`);
    const btnAdd        = contBtns.querySelector(`.agregar-bloque:not(.btn-cancel)[data-patio-id="${patioId}"]`);
    const btnCancelAdd  = document.getElementById(`cancelar-bloque-${patioId}`);
    const btnGuardar    = document.getElementById(`guardar-bloques-${patioId}`);
    const btnEliminar   = document.getElementById(`eliminar-bloque-${patioId}`);
    const inputs        = document.querySelectorAll(`#bloques-container-${patioId} input[type="number"]`);

    [btnEdit, btnCancelEdit, btnAdd, btnCancelAdd, btnGuardar, btnEliminar].forEach(b => b && hide(b));

    const mode = getMode(patioId);

    if (mode === 'idle'){
      show(btnEdit);
      show(btnAdd);
      show(btnEliminar);
      inputs.forEach(i => i.disabled = true);
      btnEliminar.disabled = false; 
    }
    if (mode === 'edit'){
      show(btnCancelEdit);
      show(btnGuardar);
      inputs.forEach(i => { i.dataset.original = i.value; i.disabled = false; });
      setBloquesDeleteMode(patioId, false);
    }
    if (mode === 'add'){
      show(btnAdd);        
      show(btnCancelAdd);
      show(btnGuardar);
      inputs.forEach(i => i.disabled = false);
      setBloquesDeleteMode(patioId, false);
    }
  }

  function setBloquesDeleteMode(patioId, on){
    bloquesDeleteMode[patioId] = on;
    const cont = document.getElementById(`bloques-container-${patioId}`);
    if (!cont) return;

    cont.classList.toggle('delete-mode-hint', on);
    cont.querySelectorAll('.checkbox-bloque').forEach(cb=>{
      cb.style.display = on ? 'none' : '';
      cb.checked = false;
    });

    const contBtns = document.getElementById(`bloques-buttons-${patioId}`);
    const btnEdit  = contBtns.querySelector(`.editar-bloque[data-patio-id="${patioId}"]`);
    const btnAdd   = contBtns.querySelector(`.agregar-bloque:not(.btn-cancel)[data-patio-id="${patioId}"]`);
    const btnDel   = document.getElementById(`eliminar-bloque-${patioId}`);
    const btnGuardar = document.getElementById(`guardar-bloques-${patioId}`);

    if (btnDel){
      btnDel.disabled = false;
      enable(btnDel);
      btnDel.textContent = on ? 'Cancelar selección' : 'Eliminar Bloque';
    }

    if (on){
      hide(btnEdit);
      hide(btnAdd);
      show(btnGuardar);
    } else {
      cont.querySelectorAll('.to-delete').forEach(r=>r.classList.remove('to-delete'));
      if (getMode(patioId) === 'idle'){
        show(btnEdit);
        show(btnAdd);
        show(btnDel);
        hide(btnGuardar);
      }
    }
  }

  document.querySelectorAll('.editar-bloque').forEach(btn => {
    btn.addEventListener('click', function () {
      const patioId = this.dataset.patioId;
      setMode(patioId, 'edit');
    });
  });

  document.querySelectorAll('[id^="cancelar-editar-bloque-"]').forEach(btn => {
    btn.addEventListener('click', function () {
      const patioId = this.dataset.patioId || this.id.split('-').pop();
      const inputs = document.querySelectorAll(`#bloques-container-${patioId} input[type="number"]`);
      inputs.forEach(i => {
        if ('original' in i.dataset){ i.value = i.dataset.original; delete i.dataset.original; }
        i.disabled = true;
      });
      setMode(patioId, 'idle');
    });
  });

  document.querySelectorAll('.agregar-bloque').forEach(btn => {
    if (btn.classList.contains('btn-cancel')) return; 
    btn.addEventListener('click', function () {
      const patioId = this.dataset.patioId;
      if (getMode(patioId) !== 'add') setMode(patioId, 'add');

      const container   = document.getElementById(`bloques-container-${patioId}`);
      const visibles    = container.querySelectorAll('.checkbox-container:not(.is-temp)').length;
      const temporales  = container.querySelectorAll('[data-temp="bloque"]').length;
      const numero      = visibles + temporales + 1;

      const div = document.createElement('div');
      div.classList.add('checkbox-container','is-temp');
      div.dataset.temp = 'bloque';
      div.innerHTML = `
        <label>Nuevo Bloque ${numero} - Capacidad Máxima:</label>
        <input type="number" name="bloques_nuevos[${patioId}][]" value="25" placeholder="Capacidad Máxima" min="0" required>
        <span style="min-width:20px;"></span>
      `;
      insertBeforeButtons(container, div);
      updateBloquesUI(patioId);
    });
  });

  document.querySelectorAll('[id^="cancelar-bloque-"]').forEach(btn => {
    btn.addEventListener('click', function () {
      const patioId   = this.dataset.patioId || this.id.split('-').pop();
      const container = document.getElementById(`bloques-container-${patioId}`);
      const temps = container.querySelectorAll('[data-temp="bloque"]');
      if (temps.length) temps[temps.length-1].remove();
      if (!container.querySelector('[data-temp="bloque"]')) setMode(patioId, 'idle');
      else updateBloquesUI(patioId);
    });
  });

  document.querySelectorAll('[id^="eliminar-bloque-"]').forEach(btn=>{
    btn.disabled = false;
    enable(btn);
    btn.addEventListener('click', (e)=>{
      e.preventDefault();
      const patioId = btn.id.split('-').pop();
      if (getMode(patioId) !== 'idle') setMode(patioId, 'idle');
      const on = !!bloquesDeleteMode[patioId];
      setBloquesDeleteMode(patioId, !on);
    });
  });

  document.querySelectorAll('[id^="bloques-container-"]').forEach(container=>{
    container.addEventListener('click', (ev)=>{
      const patioId = container.id.split('-').pop();
      if (!bloquesDeleteMode[patioId]) return;
      const row = ev.target.closest('.checkbox-container');
      if (!row) return;
      row.classList.toggle('to-delete');
      const btnGuardar = document.getElementById(`guardar-bloques-${patioId}`);
      show(btnGuardar);
    });
  });

  formBloques.addEventListener('submit', ()=>{
    formBloques.querySelectorAll('input[name="bloques_eliminar[]"]').forEach(n=>n.remove());
    document.querySelectorAll('[id^="bloques-container-"]').forEach(cont=>{
      cont.querySelectorAll('.to-delete').forEach(row=>{
        const id = row.dataset.id;
        if (!id) return;
        const hidden = document.createElement('input');
        hidden.type  = 'hidden';
        hidden.name  = 'bloques_eliminar[]';
        hidden.value = id;
        formBloques.appendChild(hidden);
      });
      const patioId = cont.id.split('-').pop();
      setBloquesDeleteMode(patioId, false);
    });
  });

  updatePatiosUI();
  document.querySelectorAll('[id^="eliminar-bloque-"]').forEach(btn=>{ btn.disabled = false; enable(btn); });
</script>
<script>
  (function(){
    var vp = document.querySelector('meta[name="viewport"]');
    if (!vp) {
      vp = document.createElement('meta');
      vp.name = 'viewport';
      document.head.appendChild(vp);
    }
    var BASE = 'width=device-width, initial-scale=1, viewport-fit=cover';

    function setViewport(content){ vp.setAttribute('content', content); }
    function lockZoom(){   setViewport(BASE + ', maximum-scale=1'); } 
    function unlockZoom(){ setViewport(BASE + ', maximum-scale=5'); } 

    unlockZoom();

    var focusSelector = 'input, select, textarea';
    document.addEventListener('focusin', function(e){
      if (e.target.matches(focusSelector)) lockZoom();
    }, {passive:true});
    document.addEventListener('focusout', function(e){
      if (e.target.matches(focusSelector)) {
        setTimeout(unlockZoom, 120);
      }
    }, {passive:true});

    window.addEventListener('orientationchange', function(){
      unlockZoom();
      setTimeout(function(){ unlockZoom(); }, 150);
    }, {passive:true});

    function normalizeIfScaled(){
      if (window.visualViewport && Math.abs(window.visualViewport.scale - 1) > 0.01) {
        unlockZoom();
      }
    }
    window.addEventListener('resize', normalizeIfScaled, {passive:true});
  })();
</script>

</main>

<footer>
    <p>&copy; IPSA Web - 2025</p>
</footer>
</body>
</html>