<?php
// Activar errores
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
if (!isset($_SESSION['sucursal_activa'])) { header('Location: ../index.php'); exit; }
$rol_id = $_SESSION['rol_id'];
$sucursal_id = $_SESSION['sucursal_activa'];

if (!in_array($rol_id, [1, 2, 3, 4, 5])) { header('Location: ../index.php'); exit; }

require '../config/database.php';

// Cargar Patios para el filtro (Solo activos y de la sucursal)
$stmtPatios = $pdo->prepare("SELECT id, nombre FROM patios WHERE sucursal_id = :suc AND estado_id = 1 ORDER BY nombre");
$stmtPatios->execute(['suc' => $sucursal_id]);
$patios = $stmtPatios->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generador de Reportes</title>
    <link rel="stylesheet" href="../styles/reportes.css">
    <style>
        .report-builder { display: grid; grid-template-columns: 350px 1fr; gap: 20px; margin-top: 20px; }
        .sidebar-options { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); height: fit-content; }
        .preview-area { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); min-height: 400px; overflow-x: auto; }
        
        .option-group { margin-bottom: 15px; border-bottom: 1px solid #f0f0f0; padding-bottom: 15px; }
        .option-group:last-child { border-bottom: none; }
        .option-group h3 { font-size: 1rem; color: #00695C; margin-bottom: 8px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; }
        
        /* Inputs y Selects */
        select, input[type="date"] { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 0.9rem; box-sizing: border-box; }
        
        /* CHECKBOXES EN 2 COLUMNAS (TU REQUERIMIENTO) */
        .checkbox-grid { 
            display: grid; 
            grid-template-columns: 1fr 1fr; /* Dos columnas forzadas */
            gap: 8px; 
        }
        .checkbox-label { font-size: 0.85rem; display: flex; align-items: center; gap: 5px; cursor: pointer; }
        
        /* Botones */
        .actions { display: flex; flex-direction: column; gap: 10px; margin-top: 20px; }
        .btn-preview { background-color: #0288D1; color: white; border:none; padding:10px; border-radius:4px; cursor:pointer; font-weight:bold; width:100%; }
        .btn-excel { background-color: #388E3C; color: white; border:none; padding:10px; border-radius:4px; cursor:pointer; font-weight:bold; text-decoration:none; text-align:center; display:block; width: 100%; box-sizing: border-box; }
        .btn-preview:hover { background-color: #0277bd; }

        /* Tabla Preview */
        .preview-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; margin-bottom: 20px; }
        .preview-table th, .preview-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        .preview-table th { background-color: #f5f5f5; color: #333; font-weight:bold; white-space: nowrap; }
        .preview-table tr:nth-child(even) { background-color: #fafafa; }
        
        /* Cabecera de Grupos */
        .group-header { background-color: #e0f2f1; color: #00695C; padding: 8px; margin-top: 15px; border-radius: 4px; font-weight: bold; border-left: 4px solid #00695C; }

        @media (max-width: 768px) {
            .report-builder { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<header>
  <div class="header-left">
    <a href="../index.php">
      <img src="../imagenes/IPSA.png" alt="Logo" class="logo">
      <span class="brand-name">IPROPORTSA</span>
    </a>
  </div>
  <div class="header-right">
    <span class="welcome-label">Bienvenido,</span>
    <span class="username"><?php echo $_SESSION['username']; ?></span>
    <a href="../logout.php" class="logout-button">Cerrar sesión</a>
  </div>
</header>

<main>
    <h1 style="text-align:center;">Generador de Reportes</h1>

    <form id="reportForm" method="GET" target="_blank">
        <div class="report-builder">
            
            <!-- === BARRA LATERAL === -->
            <div class="sidebar-options">
                
                <!-- 1. TIPO DE REPORTE -->
                <div class="option-group">
                    <h3>1. Tipo de Reporte</h3>
                    <select id="tipoReporte" name="tipo" onchange="toggleOpciones()">
                        <option value="inventario">📦 Inventario Actual</option>
                        <option value="movimientos">📋 Bitácora Movimientos</option>
                        <option value="auditorias">✅ Auditorías PYP</option>
                    </select>
                </div>

                <!-- 2. FECHAS -->
                <div class="option-group" id="filtros-fecha">
                    <h3>2. Rango de Fechas</h3>
                    <label style="display:block; font-size:0.8rem; margin-bottom:2px;">Desde:</label>
                    <input type="date" name="f_inicio" value="<?php echo date('Y-m-d'); ?>" style="margin-bottom:5px;">
                    <label style="display:block; font-size:0.8rem; margin-bottom:2px;">Hasta:</label>
                    <input type="date" name="f_fin" value="<?php echo date('Y-m-d'); ?>">
                </div>

                <!-- 3. PATIOS (Solo Inventario) -->
                <div class="option-group" id="filtro-patio" style="display:none;">
                    <h3>3. Patio</h3>
                    <select id="patio_id" name="patio_id" onchange="cargarBloques()">
                        <option value="">-- Todos los Patios --</option>
                        <?php foreach ($patios as $p): ?>
                            <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nombre']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 4. BLOQUES (Solo Inventario) -->
                <div class="option-group" id="filtro-bloque" style="display:none;">
                    <h3>4. Bloque</h3>
                    <select id="bloque_id" name="bloque_id" disabled>
                        <option value="">-- Todos los Bloques --</option>
                    </select>
                </div>

                <!-- 5. COLUMNAS (INVENTARIO) -->
                <div class="option-group" id="cols-inventario">
                    <h3>5. Columnas</h3>
                    <div class="checkbox-grid">
                        <label class="checkbox-label"><input type="checkbox" name="cols[]" value="posicion" checked> Posición</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols[]" value="nombre" checked> Contenedor</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols[]" value="tipo" checked> Tipo</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols[]" value="modelo" checked> Modelo</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols[]" value="estatus_unidad" checked> Est. Uni.</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols[]" value="estatus_box" checked> Est. Box</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols[]" value="estatus_ipsa" checked> Est. IPSA</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols[]" value="estatus_pyp" checked> Est. PYP</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols[]" value="fecha_ingreso" checked> Ingreso</label>
                        <!-- Ocultos visualmente pero seleccionables si se quiere -->
                        <input type="hidden" name="cols[]" value="patio">
                        <input type="hidden" name="cols[]" value="bloque">
                    </div>
                </div>

                <!-- COLUMNAS ALTERNATIVAS (MOVIMIENTOS) -->
                <div class="option-group" id="cols-movimientos" style="display:none;">
                    <h3>3. Columnas</h3>
                    <div class="checkbox-grid">
                        <label class="checkbox-label"><input type="checkbox" name="cols_mov[]" value="fecha" checked> Fecha</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_mov[]" value="operacion" checked> Operación</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_mov[]" value="contenedor" checked> Equipo</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_mov[]" value="usuario" checked> Usuario</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_mov[]" value="detalle" checked> Detalles</label>
                    </div>
                </div>

                 <!-- COLUMNAS ALTERNATIVAS (AUDITORIAS) -->
                 <div class="option-group" id="cols-auditorias" style="display:none;">
                    <h3>3. Columnas</h3>
                    <div class="checkbox-grid">
                        <label class="checkbox-label"><input type="checkbox" name="cols_aud[]" value="contenedor" checked> Equipo</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_aud[]" value="estatus_pyp" checked> Est. PYP</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_aud[]" value="info" checked> Info</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_aud[]" value="fecha" checked> Fecha</label>
                        <label class="checkbox-label"><input type="checkbox" name="cols_aud[]" value="usuario"> Auditor</label>
                    </div>
                </div>

                <!-- ACCIONES -->
                <div class="actions">
                    <button type="button" class="btn-preview" onclick="cargarVistaPrevia()">👁️ Vista Previa</button>
                    <div style="border-top:1px solid #eee; margin:5px 0;"></div>
                    <label style="font-size:0.8rem; color:#555;">Exportar:</label>
                    <button type="submit" class="btn-excel" onclick="prepararExportacion()">📊 Excel (.xls)</button>
                </div>
                
                <div style="margin-top:15px; text-align:center;">
                     <a href="../index.php" style="text-decoration:none; color:#00695C; font-size:0.9rem;">← Volver al Menú</a>
                </div>
            </div>

            <!-- === VISTA PREVIA === -->
            <div class="preview-area">
                <h2 style="margin-top:0; color:#333; border-bottom:2px solid #eee; padding-bottom:10px;">Vista Previa</h2>
                <div id="loading" style="display:none; text-align:center; padding:50px; font-size:1.2rem; color:#666;">Cargando datos... ⏳</div>
                <div id="tabla-resultado">
                    <div style="text-align:center; margin-top:80px; color:#999;">
                        <p style="font-size:3rem;">📊</p>
                        <p>Configure su reporte a la izquierda y presione <strong>"Vista Previa"</strong>.</p>
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
        
        // Ocultar todo por defecto
        document.getElementById('cols-inventario').style.display = 'none';
        document.getElementById('cols-movimientos').style.display = 'none';
        document.getElementById('cols-auditorias').style.display = 'none';
        document.getElementById('filtro-patio').style.display = 'none';
        document.getElementById('filtro-bloque').style.display = 'none';
        
        if (tipo === 'inventario') {
            document.getElementById('cols-inventario').style.display = 'block';
            document.getElementById('filtro-patio').style.display = 'block';
            document.getElementById('filtro-bloque').style.display = 'block';
        } else if (tipo === 'movimientos') {
            document.getElementById('cols-movimientos').style.display = 'block';
        } else if (tipo === 'auditorias') {
            document.getElementById('cols-auditorias').style.display = 'block';
        }
    }

    function cargarBloques() {
        const patioId = document.getElementById('patio_id').value;
        const bloqueSelect = document.getElementById('bloque_id');
        
        bloqueSelect.innerHTML = '<option value="">-- Todos los Bloques --</option>';
        bloqueSelect.disabled = true;

        if (patioId) {
            // Reutilizamos tu API existente para cargar bloques
            fetch(`../ingresos/obtener_bloques.php?patio_id=${patioId}`)
                .then(r => r.json())
                .then(data => {
                    if (data.length > 0) {
                        data.forEach(b => {
                            const opt = document.createElement('option');
                            opt.value = b.id;
                            opt.textContent = `Bloque ${b.numero}`;
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
        if (tipo === 'movimientos') return 'generar_movimientos.php';
        if (tipo === 'auditorias')  return 'generar_auditorias.php';
        return '';
    }

    function cargarVistaPrevia() {
        const form = document.getElementById('reportForm');
        const formData = new FormData(form);
        const params = new URLSearchParams(formData);
        params.append('modo', 'preview');

        const scriptURL = getScriptURL();
        const tipo = document.getElementById('tipoReporte').value;

        document.getElementById('loading').style.display = 'block';
        document.getElementById('tabla-resultado').innerHTML = '';

        fetch(`${scriptURL}?${params.toString()}`)
        .then(response => response.json())
        .then(data => {
            document.getElementById('loading').style.display = 'none';
            if (data.error) {
                document.getElementById('tabla-resultado').innerHTML = `<p style="color:red;">Error: ${data.error}</p>`;
                return;
            }
            
            if (tipo === 'inventario') {
                construirTablaAgrupada(data.headers, data.rows);
            } else {
                construirTablaSimple(data.headers, data.rows);
            }
        })
        .catch(err => {
            document.getElementById('loading').style.display = 'none';
            console.error(err);
            document.getElementById('tabla-resultado').innerHTML = '<p style="color:red;">Error de conexión.</p>';
        });
    }

    function prepararExportacion() {
        const form = document.getElementById('reportForm');
        form.action = getScriptURL();
    }

    // --- LÓGICA DE AGRUPACIÓN (INVENTARIO) ---
    function construirTablaAgrupada(headers, rows) {
        if (!rows || rows.length === 0) {
            document.getElementById('tabla-resultado').innerHTML = '<p style="text-align:center; padding:20px;">No se encontraron datos.</p>';
            return;
        }

        const container = document.getElementById('tabla-resultado');
        container.innerHTML = '';

        const grupos = {};
        rows.forEach(row => {
            // Usamos los alias definidos en generar_inventario.php (patio_nom, bloq_num)
            const key = (row.patio_nom && row.bloq_num) 
                ? `${row.patio_nom} - Bloque ${row.bloq_num}` 
                : 'General';
                
            if (!grupos[key]) grupos[key] = [];
            grupos[key].push(row);
        });

        Object.keys(grupos).forEach(titulo => {
            const h3 = document.createElement('h3');
            h3.className = 'group-header';
            h3.textContent = titulo;
            container.appendChild(h3);

            const table = document.createElement('table');
            table.className = 'preview-table';
            
            // Headers (Excluyendo Patio y Bloque que ya están en el título)
            let thead = '<thead><tr>';
            headers.forEach(h => {
                if (h !== 'Patio' && h !== 'Bloque') {
                   thead += `<th>${h}</th>`;
                }
            });
            thead += '</tr></thead>';
            table.innerHTML = thead;

            // Body
            const tbody = document.createElement('tbody');
            grupos[titulo].forEach(row => {
                const tr = document.createElement('tr');
                // Filtramos las claves que no queremos mostrar en la tabla (las que usamos para agrupar)
                const clavesOcultas = ['patio_nom', 'bloq_num'];
                
                // Recorremos los valores del row pero necesitamos saber a qué header corresponden
                // Lo mejor es confiar en el orden, pero omitiendo los índices 0 y 1 si corresponden a Patio/Bloque
                // Como usamos FETCH_ASSOC, iteramos las keys.
                Object.keys(row).forEach(key => {
                    if (!clavesOcultas.includes(key)) {
                        const td = document.createElement('td');
                        td.textContent = row[key];
                        tr.appendChild(td);
                    }
                });
                tbody.appendChild(tr);
            });
            table.appendChild(tbody);
            container.appendChild(table);
        });
    }

    function construirTablaSimple(headers, rows) {
        if (!rows || rows.length === 0) {
            document.getElementById('tabla-resultado').innerHTML = '<p style="text-align:center; padding:20px;">No hay datos.</p>';
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