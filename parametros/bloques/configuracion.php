<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '../../config/database.php';

$sqlPatios = 'SELECT id, nombre FROM patios';
$stmtPatios = $pdo->query($sqlPatios);
$patios = $stmtPatios->fetchAll();

$sqlBloques = 'SELECT id, patio_id, numero, capacidad_maxima FROM bloques';
$stmtBloques = $pdo->query($sqlBloques);
$bloques = $stmtBloques->fetchAll();

$bloquesPorPatio = [];
foreach ($bloques as $bloque) {
    $bloquesPorPatio[$bloque['patio_id']][] = $bloque;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Configuración de Bloques</title>
    <link rel="stylesheet" href="../../styles/parametros/bloques/configuracion.css" />

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
    <span class="username"><?php echo $_SESSION['username']; ?></span>
    <a href="../../logout.php" class="logout-button">Cerrar sesión</a>
  </div>
</header>

<!-- Toasts -->
<div id="toast-container" class="toast-container"></div>

<main>
    <h1>Configuración de Bloques</h1>

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

      <!-- Modo eliminar (ahora solo activa/desactiva la selección por click) -->
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

              <!-- Checkbox clásico (se oculta en modo eliminar vía JS) -->
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

          <!-- Modo eliminar (activa selección por click) -->
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
  // ===== Toasts =====
  function showToast(message, type = 'success') {
    const toastContainer = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.textContent = message;
    toastContainer.appendChild(toast);
    setTimeout(() => { toast.remove(); }, 4000);
  }
  <?php if (isset($_SESSION['toast_message'])): ?>
  document.addEventListener('DOMContentLoaded', function () {
    showToast('<?php echo $_SESSION['toast_message']; ?>', '<?php echo $_SESSION['toast_type']; ?>');
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
      // Ver Editar, Agregar y Eliminar
      show(btnEditarPatio);
      show(btnAgregarPatio);
      show(btnEliminarPatio);
      setPatiosReadonly(false); // inputs deshabilitados
      btnEliminarPatio.disabled = false;
      enable(btnEliminarPatio);
    }

    if (patiosMode === 'edit'){
      // Solo Cancelar edición y Guardar
      show(btnCancelarEditarPatio);
      show(btnGuardarPatios);
      setPatiosReadonly(true); // habilita inputs
      document.querySelectorAll('#patios-container input[type="text"]').forEach(i=>{
        i.dataset.original = i.value;
        i.disabled = false;
      });
      setPatiosDeleteMode(false);
    }

    if (patiosMode === 'add'){
      // Solo Agregar (puedes agregar más), Cancelar agregar y Guardar
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

    // Si aún existen checkboxes clásicos, ocultarlos (ya no se usan)
    document.querySelectorAll('#patios-container .checkbox-patio').forEach(cb=>{
      cb.style.display = on ? 'none' : '';
      cb.checked = false;
    });

    // Botón Eliminar muta a "Cancelar selección"
    btnEliminarPatio.disabled = false;
    enable(btnEliminarPatio);
    btnEliminarPatio.textContent = on ? 'Cancelar selección' : 'Eliminar Patio';

    if (on){
      // En modo eliminar NO deben verse Editar ni Agregar
      hide(btnEditarPatio);
      hide(btnAgregarPatio);
      show(btnGuardarPatios);  // Guardar aplica la selección
    } else {
      // Limpia marcas
      patiosContainer.querySelectorAll('.to-delete').forEach(r=>r.classList.remove('to-delete'));
      // Si volvemos a idle, restituir botones base
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
    // revertir valores originales
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
    crearFilaPatioTemporal();  // cada click agrega otro
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

  // Activar/Cancelar modo eliminar (PATIOS)
  btnEliminarPatio.disabled = false;
  btnEliminarPatio.addEventListener('click', (e)=>{
    e.preventDefault();
    if (patiosMode !== 'idle') { // no permitir mientras se edita/agrega
      patiosMode = 'idle';
      updatePatiosUI();
    }
    setPatiosDeleteMode(!patiosDeleteMode);
  });

  // Selección por click de filas (PATIOS) en modo eliminar
  patiosContainer.addEventListener('click', (ev)=>{
    if (!patiosDeleteMode) return;
    const row = ev.target.closest('.checkbox-container');
    if (!row) return;
    row.classList.toggle('to-delete');
    show(btnGuardarPatios);
  });

  // Antes de enviar, crear inputs ocultos patios_eliminar[] con selección (PATIOS)
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

  // Guardar patios mantiene scroll (también lo tienes inline en el botón)
  document.getElementById('guardar-patios').onclick = () => submitAndKeepScroll(formPatios);

  /* =====================================================================
     BLOQUES — 'idle' | 'edit' | 'add' + modo eliminar (por patio)
     ===================================================================== */
  const formBloques = document.getElementById('form-bloques');

  // Insertar antes de la botonera
  function insertBeforeButtons(container, node) {
    const buttonsEl = container.querySelector('.bloques-buttons');
    if (buttonsEl) container.insertBefore(node, buttonsEl); else container.appendChild(node);
  }

  // Estado por patio
  const bloquesMode = {};        // { [patioId]: 'idle'|'edit'|'add' }
  const bloquesDeleteMode = {};  // { [patioId]: boolean }

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

    // reset visibilidad (las acciones base dependen del modo)
    [btnEdit, btnCancelEdit, btnAdd, btnCancelAdd, btnGuardar, btnEliminar].forEach(b => b && hide(b));

    const mode = getMode(patioId);

    if (mode === 'idle'){
      show(btnEdit);
      show(btnAdd);
      show(btnEliminar);
      inputs.forEach(i => i.disabled = true);
      btnEliminar.disabled = false; // clickable para entrar a modo eliminar
    }
    if (mode === 'edit'){
      show(btnCancelEdit);
      show(btnGuardar);
      inputs.forEach(i => { i.dataset.original = i.value; i.disabled = false; });
      setBloquesDeleteMode(patioId, false);
    }
    if (mode === 'add'){
      show(btnAdd);        // permitir agregar varios seguidos
      show(btnCancelAdd);
      show(btnGuardar);
      inputs.forEach(i => i.disabled = false);
      setBloquesDeleteMode(patioId, false);
    }
  }

  // === MODO ELIMINAR (BLOQUES) por patio ===
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
      // En modo eliminar NO deben verse Editar ni Agregar
      hide(btnEdit);
      hide(btnAdd);
      show(btnGuardar);
    } else {
      // Limpiar marcas
      cont.querySelectorAll('.to-delete').forEach(r=>r.classList.remove('to-delete'));
      if (getMode(patioId) === 'idle'){
        show(btnEdit);
        show(btnAdd);
        show(btnDel);
        hide(btnGuardar);
      }
    }
  }

  // Eventos BLOQUES
  // EDITAR
  document.querySelectorAll('.editar-bloque').forEach(btn => {
    btn.addEventListener('click', function () {
      const patioId = this.dataset.patioId;
      setMode(patioId, 'edit');
    });
  });

  // CANCELAR EDICIÓN
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

  // AGREGAR BLOQUE
  document.querySelectorAll('.agregar-bloque').forEach(btn => {
    if (btn.classList.contains('btn-cancel')) return; // no tocar los "cancelar"
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

  // CANCELAR AGREGAR
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

  // Activar/Cancelar modo eliminar (BLOQUES) por patio
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

  // Selección por click de filas (BLOQUES) en modo eliminar
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

  // Antes de enviar, crear inputs ocultos bloques_eliminar[] con selección (BLOQUES)
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

  // Inicializar vistas
  updatePatiosUI();
  document.querySelectorAll('[id^="eliminar-bloque-"]').forEach(btn=>{ btn.disabled = false; enable(btn); });
</script>
<script>
  (function(){
    // Asegura que exista el meta viewport
    var vp = document.querySelector('meta[name="viewport"]');
    if (!vp) {
      vp = document.createElement('meta');
      vp.name = 'viewport';
      document.head.appendChild(vp);
    }
    var BASE = 'width=device-width, initial-scale=1, viewport-fit=cover';

    function setViewport(content){ vp.setAttribute('content', content); }
    function lockZoom(){   setViewport(BASE + ', maximum-scale=1'); }   // sin zoom mientras escribes
    function unlockZoom(){ setViewport(BASE + ', maximum-scale=5'); }   // re-habilita zoom al salir

    // Al cargar, normaliza
    unlockZoom();

    // Activa/desactiva durante edición
    var focusSelector = 'input, select, textarea';
    document.addEventListener('focusin', function(e){
      if (e.target.matches(focusSelector)) lockZoom();
    }, {passive:true});
    document.addEventListener('focusout', function(e){
      if (e.target.matches(focusSelector)) {
        // espera a que cierre el teclado y reajuste la viewport
        setTimeout(unlockZoom, 120);
      }
    }, {passive:true});

    // Cambios de orientación: normaliza por si quedó alguna escala pegada
    window.addEventListener('orientationchange', function(){
      // re-habilita zoom y re-aplica escala base
      unlockZoom();
      // si Safari dejó una escala residual, fuerza reflow del meta
      setTimeout(function(){ unlockZoom(); }, 150);
    }, {passive:true});

    // Por si el navegador mantiene una escala distinta de 1 tras cerrar teclado
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
