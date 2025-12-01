<?php
// 1. CONFIGURACIÓN INICIAL
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Forzar Zona Horaria de Guatemala
date_default_timezone_set('America/Guatemala');

session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }

// 2. SEGURIDAD DE SUCURSAL Y ROLES
if (!isset($_SESSION['sucursal_activa'])) { header('Location: ../index.php'); exit; }
$rol_id = $_SESSION['rol_id'];
$sucursal_id = $_SESSION['sucursal_activa'];

if (!in_array($rol_id, [1, 2, 3, 4, 5])) { header('Location: ../index.php'); exit; }

require '../config/database.php';

// 3. CARGAR DATOS

// A. Patios (Para reportes masivos)
$stmtPatios = $pdo->prepare("SELECT id, nombre FROM patios WHERE sucursal_id = :suc AND estado_id = 1 ORDER BY nombre");
$stmtPatios->execute(['suc' => $sucursal_id]);
$patios = $stmtPatios->fetchAll(PDO::FETCH_ASSOC);

// B. Contenedores (Para el buscador de Historial)
$stmtCont = $pdo->prepare("
    SELECT DISTINCT c.id, c.nombre, c.estado_id 
    FROM contenedores c
    JOIN bloques b ON c.bloque_id = b.id
    JOIN patios p ON b.patio_id = p.id
    WHERE p.sucursal_id = :suc
    ORDER BY c.nombre ASC
");
$stmtCont->execute(['suc' => $sucursal_id]);
$listaContenedores = $stmtCont->fetchAll(PDO::FETCH_ASSOC);

// C. Nombre de la sucursal
$stmtSuc = $pdo->prepare("SELECT nombre FROM sucursales WHERE id = :id");
$stmtSuc->execute(['id' => $sucursal_id]);
$nombreSucursal = $stmtSuc->fetchColumn();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generador de Reportes</title>
    <link rel="stylesheet" href="../styles/reportes.css">
    <style>
        /* === ESTILOS PANTALLA === */
        body { overflow-x: hidden; font-family: sans-serif; }
        main { max-width: 98vw !important; width: 98% !important; margin: 20px auto; padding: 0 10px; box-sizing: border-box; }

        .report-builder { display: grid; grid-template-columns: 350px 1fr; gap: 20px; margin-top: 20px; width: 100%; }
        .sidebar-options { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); height: fit-content; }
        .preview-area { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); min-height: 400px; overflow-x: auto; width: 100%; box-sizing: border-box; }
        
        .option-group { margin-bottom: 15px; border-bottom: 1px solid #f0f0f0; padding-bottom: 15px; }
        .option-group h3 { font-size: 1rem; color: #00695C; margin-bottom: 8px; font-weight: bold; text-transform: uppercase; }
        
        select, input[type="date"], input[type="text"] { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 0.9rem; box-sizing: border-box; }
        
        .checkbox-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .checkbox-label { font-size: 0.85rem; display: flex; align-items: center; gap: 5px; cursor: pointer; }
        
        .actions { display: flex; flex-direction: column; gap: 10px; margin-top: 20px; }
        .btn-preview { background-color: #0288D1; color: white; border:none; padding:10px; border-radius:4px; cursor:pointer; font-weight:bold; width:100%; }
        .btn-excel { background-color: #388E3C; color: white; border:none; padding:10px; border-radius:4px; cursor:pointer; font-weight:bold; width:100%; }
        .btn-pdf { background-color: #D32F2F; color: white; border:none; padding:10px; border-radius:4px; cursor:pointer; font-weight:bold; width:100%; }

        /* ESTILOS BUSCADOR */
        #lista-contenedores { max-height: 300px; overflow-y: auto; border: 1px solid #ccc; border-radius: 4px; margin-top: 5px; background: #fafafa; }
        .cont-item { padding: 8px 10px; border-bottom: 1px solid #eee; cursor: pointer; display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem; }
        .cont-item:hover { background-color: #e0f2f1; }
        .cont-item.selected { background-color: #00695C; color: white; }
        .badge { font-size: 0.7rem; padding: 2px 6px; border-radius: 4px; font-weight: bold; }
        .badge-activo { background-color: #C8E6C9; color: #2E7D32; }
        .badge-inactivo { background-color: #FFCDD2; color: #C62828; }

        /* TABLAS */
        .preview-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; margin-bottom: 20px; }
        .preview-table th, .preview-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        .preview-table th { background-color: #f5f5f5; color: #333; font-weight:bold; }
        .group-header { background-color: #e0f2f1; color: #00695C; padding: 8px; margin-top: 15px; border-radius: 4px; font-weight: bold; border-left: 4px solid #00695C; }

        #print-header-content { display: none; }
        @media print {
            header, footer, .sidebar-options, .actions, h1, #loading, .preview-area h2 { display: none !important; }
            main { margin: 0; padding: 0; max-width: 100%; }
            .report-builder { display: block; }
            #print-header-content { display: block !important; text-align: center; margin-bottom: 20px; border-bottom: 2px solid #00695C; }
            .preview-area { box-shadow: none; min-height: 0; overflow: visible; }
            .preview-table { font-size: 9pt; }
            @page { size: landscape; margin: 1cm; }
        }
    </style>
</head>
<body>
<header>
  <div class="header-left">
    <a href="../index.php"><img src="../imagenes/IPSA.png" alt="Logo" class="logo"><span class="brand-name">IPROPORTSA</span></a>
  </div>
  <div class="header-right">
    <span class="welcome-label">Bienvenido,</span> <span class="username"><?php echo $_SESSION['username']; ?></span>
    <a href="../logout.php" class="logout-button">Cerrar sesión</a>
  </div>
</header>

<main>
    <h1 style="text-align:center;">Generador de Reportes</h1>
    <div id="print-header-content">
        <h2 id="ph-titulo">Reporte de Sistema</h2>
        <p><strong>Sucursal:</strong> <?php echo htmlspecialchars($nombreSucursal); ?></p>
        <p><strong>Impreso por:</strong> <?php echo $_SESSION['username']; ?> | <strong>Fecha:</strong> <?php echo date('d/m/Y H:i'); ?></p>
        <p id="ph-rango"></p>
    </div>

    <form id="reportForm" method="GET" target="_blank">
        <div class="report-builder">
            
            <div class="sidebar-options">
                <div class="option-group">
                    <h3>1. Tipo de Reporte</h3>
                    <select id="tipoReporte" name="tipo" onchange="toggleOpciones()">
                        <option value="inventario">📦 Contenedores Activos</option>
                        <option value="despachos">🚚 Contenedores Despachados</option>
                        <option value="movimientos">📋 Bitácora Movimientos</option>
                        <option value="auditorias">✅ Auditorías PYP</option>
                        <option value="traslados">🔄 Reporte de Traslados</option>
                        <option value="cambios">📝 Detalle de Cambios</option> <option value="historial">📜 Historial de Contenedor</option>
                    </select>
                </div>

                <div class="option-group" id="filtros-fecha">
                    <h3>2. Rango de Fechas</h3>
                    <label style="display:block; font-size:0.8rem;">Desde:</label>
                    <input type="date" id="f_inicio" name="f_inicio" value="<?php echo date('Y-m-01'); ?>">
                    <label style="display:block; font-size:0.8rem; margin-top:5px;">Hasta:</label>
                    <input type="date" id="f_fin" name="f_fin" value="<?php echo date('Y-m-t'); ?>">
                </div>

                <div class="option-group" id="filtro-patio">
                    <h3>3. Patio</h3>
                    <select id="patio_id" name="patio_id" onchange="cargarBloques()">
                        <option value="">-- Todos los Patios --</option>
                        <?php foreach ($patios as $p): ?>
                            <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nombre']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="option-group" id="filtro-bloque">
                    <h3>4. Bloque</h3>
                    <select id="bloque_id" name="bloque_id" disabled>
                        <option value="">-- Todos los Bloques --</option>
                    </select>
                </div>

                <div class="option-group" id="filtro-buscador" style="display:none;">
                    <h3>2. Buscar Contenedor</h3>
                    <input type="text" id="searchCont" placeholder="🔎 Escribe código..." onkeyup="filtrarContenedores()" autocomplete="off">
                    <input type="hidden" name="contenedor_id" id="selected_contenedor_id">
                    <div id="lista-contenedores">
                        <?php foreach ($listaContenedores as $c): ?>
                            <div class="cont-item" onclick="seleccionarContenedor(this, <?php echo $c['id']; ?>)">
                                <span class="cont-nombre"><?php echo htmlspecialchars($c['nombre']); ?></span>
                                <?php if($c['estado_id'] == 1): ?>
                                    <span class="badge badge-activo">Activo</span>
                                <?php else: ?>
                                    <span class="badge badge-inactivo">Inactivo</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div id="seleccion-msg" style="margin-top:10px; padding:5px; background:#e0f2f1; border-left:3px solid #00695C; display:none; font-size:0.8rem;">
                        <strong>Seleccionado:</strong> <span id="sel-nombre">Ninguno</span>
                    </div>
                </div>

                <div class="option-group" id="cols-inventario">
                    <h3>5. Columnas</h3>
                    <div class="checkbox-grid">
                        <label class="checkbox-label"><input type="checkbox" name="cols[]" value="patio" checked> Patio</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols[]" value="bloque" checked> Bloque</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols[]" value="posicion" checked> Posición</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols[]" value="nombre" checked> Contenedor</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols[]" value="tipo" checked> Tipo</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols[]" value="modelo" checked> Modelo</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols[]" value="estatus_unidad" checked> Est. Uni.</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols[]" value="observaciones_unidad"> Obs. Unidad</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols[]" value="estatus_box" checked> Est. Box</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols[]" value="observaciones_box"> Obs. Box</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols[]" value="estatus_ipsa" checked> Est. IPSA</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols[]" value="estatus_pyp" checked> Est. PYP</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols[]" value="fecha_ingreso" checked> Ingreso</label>
                    </div>
                </div>

                <div class="option-group" id="cols-despachos" style="display:none;">
                    <h3>5. Columnas</h3>
                    <div class="checkbox-grid">
                        <label class="checkbox-label"><input type="checkbox" name="cols_desp[]" value="patio" checked> Patio</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_desp[]" value="bloque" checked> Bloque</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_desp[]" value="contenedor" checked> Contenedor</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_desp[]" value="tipo"> Tipo</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_desp[]" value="modelo"> Modelo</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_desp[]" value="estatus_unidad"> Est. Uni.</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_desp[]" value="estatus_box"> Est. Box</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_desp[]" value="estatus_ipsa"> Est. IPSA</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_desp[]" value="estatus_pyp"> Est. PYP</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_desp[]" value="fecha_ingreso" checked> Ingreso</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_desp[]" value="fecha_despacho" checked> Despacho</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_desp[]" value="dias_estadia" checked> Días Estadía</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_desp[]" value="usuario" checked> Despachador</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_desp[]" value="observaciones"> Observaciones</label>
                    </div>
                </div>

                <div class="option-group" id="cols-movimientos" style="display:none;">
                    <h3>3. Columnas</h3>
                    <div class="checkbox-grid">
                        <label class="checkbox-label"><input type="checkbox" name="cols_mov[]" value="patio" checked> Patio</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_mov[]" value="bloque" checked> Bloque</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_mov[]" value="contenedor" checked> Contenedor</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_mov[]" value="tipo"> Tipo</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_mov[]" value="modelo"> Modelo</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_mov[]" value="operacion" checked> Operación</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_mov[]" value="usuario" checked> Usuario</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_mov[]" value="detalle" checked> Detalles</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_mov[]" value="fecha" checked> Fecha</label>
                    </div>
                </div>

                <div class="option-group" id="cols-auditorias" style="display:none;">
                    <h3>3. Columnas</h3>
                    <div class="checkbox-grid">
                        <label class="checkbox-label"><input type="checkbox" name="cols_aud[]" value="patio" checked> Patio</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_aud[]" value="bloque" checked> Bloque</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_aud[]" value="contenedor" checked> Equipo</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_aud[]" value="estatus_pyp" checked> Est. PYP</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_aud[]" value="info" checked> Info</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_aud[]" value="usuario"> Auditor</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_aud[]" value="fecha" checked> Fecha</label>
                    </div>
                </div>

                <div class="option-group" id="cols-traslados" style="display:none;">
                    <h3>3. Columnas</h3>
                    <div class="checkbox-grid">
                        <label class="checkbox-label"><input type="checkbox" name="cols_tras[]" value="patio" checked> Patio Destino</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_tras[]" value="bloque" checked> Bloque Destino</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_tras[]" value="fecha" checked> Fecha</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_tras[]" value="contenedor" checked> Equipo</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_tras[]" value="tipo"> Tipo</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_tras[]" value="origen" checked> Desde (Origen)</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_tras[]" value="destino" checked> Hacia (Destino)</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_tras[]" value="usuario" checked> Operador</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_tras[]" value="detalle"> Observaciones</label>
                    </div>
                </div>

                <div class="option-group" id="cols-cambios" style="display:none;">
                    <h3>5. Columnas</h3>
                    <div class="checkbox-grid">
                        <label class="checkbox-label"><input type="checkbox" name="cols_cambios[]" value="patio" checked> Patio</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_cambios[]" value="bloque" checked> Bloque</label>
                        
                        <label class="checkbox-label"><input type="checkbox" name="cols_cambios[]" value="fecha" checked> Fecha</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_cambios[]" value="contenedor" checked> Contenedor</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_cambios[]" value="usuario" checked> Usuario</label>
                        
                        <label class="checkbox-label"><input type="checkbox" name="cols_cambios[]" value="tipo_cambio" checked> Tipo Cambio</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_cambios[]" value="campo" checked> Campo</label>
                        
                        <label class="checkbox-label"><input type="checkbox" name="cols_cambios[]" value="anterior" checked> Valor Anterior</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_cambios[]" value="nuevo" checked> Valor Nuevo</label>
                    </div>
                </div>

                <div class="actions">
                    <button type="button" class="btn-preview" onclick="cargarVistaPrevia()">👁️ Vista Previa</button>
                    <div style="border-top:1px solid #eee; margin:5px 0;"></div>
                    <label style="font-size:0.8rem; color:#555;">Exportar:</label>
                    <div style="display:flex; gap:5px;">
                        <button type="submit" class="btn-excel" onclick="prepararExportacion()">📊 Excel</button>
                        <button type="button" class="btn-pdf" onclick="imprimirPDF()">📄 PDF</button>
                    </div>
                </div>
                <div style="margin-top:15px; text-align:center;">
                     <a href="../index.php" style="text-decoration:none; color:#00695C; font-size:0.9rem;">← Volver al Menú</a>
                </div>
            </div>

            <div class="preview-area">
                <h2 style="margin-top:0; color:#333; border-bottom:2px solid #eee; padding-bottom:10px;">Vista Previa</h2>
                <div id="loading" style="display:none; text-align:center; padding:50px; font-size:1.2rem; color:#666;">Cargando... ⏳</div>
                <div id="tabla-resultado">
                    <div style="text-align:center; margin-top:80px; color:#999;">
                        <p style="font-size:3rem;">📊</p>
                        <p>Configure su reporte y presione <strong>"Vista Previa"</strong>.</p>
                    </div>
                </div>
            </div>
        </div>
    </form>
</main>

<footer><p>&copy; IPSA Web - 2025</p></footer>

<script>
    function toggleOpciones() {
        const tipo = document.getElementById('tipoReporte').value;
        
        // 1. OCULTAR TODO
        const allColDivs = document.querySelectorAll('[id^="cols-"]');
        allColDivs.forEach(div => div.style.display = 'none');
        
        document.getElementById('filtros-fecha').style.display = 'none';
        document.getElementById('filtro-patio').style.display = 'none';
        document.getElementById('filtro-bloque').style.display = 'none';
        document.getElementById('filtro-buscador').style.display = 'none';

        // 2. MOSTRAR SEGÚN TIPO
        if (tipo === 'historial') {
            document.getElementById('filtro-buscador').style.display = 'block';
        } else {
            // Reportes Masivos
            document.getElementById('filtros-fecha').style.display = 'block';
            document.getElementById('filtro-patio').style.display = 'block';
            document.getElementById('filtro-bloque').style.display = 'block';

            if (tipo === 'inventario') document.getElementById('cols-inventario').style.display = 'block';
            else if (tipo === 'despachos') document.getElementById('cols-despachos').style.display = 'block';
            else if (tipo === 'movimientos') document.getElementById('cols-movimientos').style.display = 'block';
            else if (tipo === 'auditorias') document.getElementById('cols-auditorias').style.display = 'block';
            else if (tipo === 'traslados') document.getElementById('cols-traslados').style.display = 'block';
            else if (tipo === 'cambios') document.getElementById('cols-cambios').style.display = 'block'; // NUEVO
        }
    }

    function filtrarContenedores() {
        const input = document.getElementById('searchCont');
        const filter = input.value.toUpperCase();
        const lista = document.getElementById('lista-contenedores');
        const items = lista.getElementsByClassName('cont-item');
        for (let i = 0; i < items.length; i++) {
            const nombre = items[i].querySelector('.cont-nombre').textContent;
            items[i].style.display = (nombre.toUpperCase().indexOf(filter) > -1) ? "" : "none";
        }
    }

    function seleccionarContenedor(el, id) {
        document.getElementById('selected_contenedor_id').value = id;
        const items = document.getElementsByClassName('cont-item');
        for (let i = 0; i < items.length; i++) { items[i].classList.remove('selected'); }
        el.classList.add('selected');
        const nombre = el.querySelector('.cont-nombre').textContent;
        document.getElementById('seleccion-msg').style.display = 'block';
        document.getElementById('sel-nombre').innerText = nombre;
    }

    function cargarBloques() {
        const patioId = document.getElementById('patio_id').value;
        const bloqueSelect = document.getElementById('bloque_id');
        bloqueSelect.innerHTML = '<option value="">-- Todos los Bloques --</option>';
        bloqueSelect.disabled = true;
        if (patioId) {
            fetch(`../ingresos/obtener_bloques.php?patio_id=${patioId}`)
                .then(r => r.json())
                .then(data => {
                    if (data.length > 0) {
                        data.forEach(b => {
                            const opt = document.createElement('option');
                            opt.value = b.id; opt.textContent = `Bloque ${b.numero}`;
                            bloqueSelect.appendChild(opt);
                        });
                        bloqueSelect.disabled = false;
                    }
                })
                .catch(e => console.error("Error cargando bloques", e));
        }
    }

    function getScriptURL() {
        const tipo = document.getElementById('tipoReporte').value;
        if (tipo === 'inventario') return 'generar_inventario.php';
        if (tipo === 'despachos')  return 'generar_despachos.php';
        if (tipo === 'movimientos') return 'generar_movimientos.php';
        if (tipo === 'auditorias')  return 'generar_auditorias.php';
        if (tipo === 'traslados')   return 'generar_traslados.php';
        if (tipo === 'cambios')     return 'generar_cambios.php';
        if (tipo === 'historial')   return 'generar_historial.php';
        return '';
    }

    function cargarVistaPrevia() {
        const tipo = document.getElementById('tipoReporte').value;
        if (tipo === 'historial') {
            if (!document.getElementById('selected_contenedor_id').value) {
                alert("⚠️ Seleccione un contenedor."); return;
            }
        }

        const form = document.getElementById('reportForm');
        const formData = new FormData(form);
        const params = new URLSearchParams(formData);
        params.append('modo', 'preview');

        document.getElementById('loading').style.display = 'block';
        document.getElementById('tabla-resultado').innerHTML = '';

        fetch(`${getScriptURL()}?${params.toString()}`)
        .then(response => response.json())
        .then(data => {
            document.getElementById('loading').style.display = 'none';
            if (data.error) {
                document.getElementById('tabla-resultado').innerHTML = `<p style="color:red;">Error: ${data.error}</p>`;
                return;
            }
            
            if (tipo === 'historial') {
                construirTablaSimple(data.headers, data.rows);
            } else {
                // AHORA CAMBIOS TAMBIEN SE AGRUPA CON AZUL
                construirTablaAgrupada(data.headers, data.rows);
            }
        })
        .catch(err => console.error(err));
    }

    function prepararExportacion() {
        if (document.getElementById('tipoReporte').value === 'historial' && !document.getElementById('selected_contenedor_id').value) {
            alert("Seleccione un contenedor."); event.preventDefault(); return;
        }
        document.getElementById('reportForm').action = getScriptURL();
    }

    function imprimirPDF() {
        const content = document.getElementById('tabla-resultado').innerHTML;
        if(content.includes('📊') || content.trim() === '') {
            alert("Primero genere la VISTA PREVIA."); return;
        }
        
        const select = document.getElementById('tipoReporte');
        const tipo = select.value;
        const textoReporte = select.options[select.selectedIndex].text;
        
        let subtitulo = "";
        if (tipo === 'historial') {
            const nombreCont = document.getElementById('sel-nombre').innerText;
            subtitulo = `<strong>Contenedor:</strong> ${nombreCont}`;
        } else {
            const fInicio = document.getElementById('f_inicio').value;
            const fFin = document.getElementById('f_fin').value;
            subtitulo = `<strong>Rango de Fechas:</strong> Del ${fInicio} al ${fFin}`;
        }

        document.getElementById('ph-titulo').innerText = textoReporte;
        document.getElementById('ph-rango').innerHTML = subtitulo;
        window.print();
    }

    function construirTablaAgrupada(headers, rows) {
        if (!rows || rows.length === 0) {
            document.getElementById('tabla-resultado').innerHTML = '<p style="text-align:center; padding:20px;">No se encontraron datos.</p>';
            return;
        }
        const container = document.getElementById('tabla-resultado');
        container.innerHTML = '';
        const grupos = {};
        rows.forEach(row => {
            const key = (row.patio_nombre && row.bloque_numero) ? `${row.patio_nombre} - Bloque ${row.bloque_numero}` : 'General';
            if (!grupos[key]) grupos[key] = [];
            grupos[key].push(row);
        });
        Object.keys(grupos).forEach(titulo => {
            if (titulo !== 'General' || Object.keys(grupos).length > 1) {
                const h3 = document.createElement('h3'); h3.className = 'group-header'; h3.textContent = titulo; container.appendChild(h3);
            }
            const table = document.createElement('table'); table.className = 'preview-table';
            let thead = '<thead><tr>';
            headers.forEach(h => { if (h !== 'Patio' && h !== 'Bloque') thead += `<th>${h}</th>`; });
            thead += '</tr></thead>'; table.innerHTML = thead;
            const tbody = document.createElement('tbody');
            grupos[titulo].forEach(row => {
                const tr = document.createElement('tr');
                const clavesOcultas = ['patio_nombre', 'bloque_numero'];
                Object.keys(row).forEach(key => {
                    if (!clavesOcultas.includes(key)) { const td = document.createElement('td'); td.textContent = row[key]; tr.appendChild(td); }
                });
                tbody.appendChild(tr);
            });
            table.appendChild(tbody); container.appendChild(table);
        });
    }

    function construirTablaSimple(headers, rows) {
        if (!rows || rows.length === 0) {
            document.getElementById('tabla-resultado').innerHTML = '<p style="text-align:center; padding:20px;">No hay historial disponible.</p>';
            return;
        }
        let html = '<table class="preview-table"><thead><tr>';
        headers.forEach(h => html += `<th>${h}</th>`);
        html += '</tr></thead><tbody>';
        rows.forEach(row => {
            html += '<tr>';
            Object.values(row).forEach(val => html += `<td>${val}</td>`);
            html += '</tr>';
        });
        html += '</tbody></table>';
        document.getElementById('tabla-resultado').innerHTML = html;
    }

    toggleOpciones();
</script>
</body>
</html>