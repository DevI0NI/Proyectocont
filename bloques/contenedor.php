<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

if (!isset($_SESSION['sucursal_activa'])) {
    header('Location: ../index.php');
    exit;
}
$sucursal_id = $_SESSION['sucursal_activa'];
$rol_usuario = (int)$_SESSION['rol_id'];

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '../config/database.php';

// 2. OBTENER TODOS LOS CONTENEDORES
$sqlTodosContenedores = "
    SELECT 
        c.id, 
        c.nombre,
        c.posicion,
        eu.nombre AS estatus,
        eb.nombre AS estatus_box,
        ei.nombre AS estatus_ipsa,
        c.estatus_ipsa_id,
        pa.estatus_pyp,
        pa.informacion_adicional, -- <--- NUEVO CAMPO NECESARIO
        pa.contenedor_id AS es_asignado,
        b.id as bloque_id,
        b.numero as bloque_numero,
        p.id as patio_id,
        p.nombre as patio_nombre
    FROM contenedores c
    JOIN bloques b ON c.bloque_id = b.id
    JOIN patios p ON b.patio_id = p.id
    LEFT JOIN estatus_contenedores eu ON c.estatus_unidad_id = eu.id
    LEFT JOIN estatus_contenedores eb ON c.estatus_box_id = eb.id
    LEFT JOIN estatus_ipsa ei ON c.estatus_ipsa_id = ei.id
    LEFT JOIN pyp_auditoria pa ON c.id = pa.contenedor_id
    WHERE c.estado_id = 1 
      AND p.sucursal_id = :sucursal
    ORDER BY p.id, b.numero, c.posicion
";

$stmtTodosContenedores = $pdo->prepare($sqlTodosContenedores);
$stmtTodosContenedores->execute(['sucursal' => $sucursal_id]);
$todosContenedores = $stmtTodosContenedores->fetchAll(PDO::FETCH_ASSOC);

// 3. LISTA DE PATIOS
$sqlPatios = 'SELECT id, nombre FROM patios WHERE estado_id = 1 AND sucursal_id = :sucursal ORDER BY nombre ASC';
$stmtPatios = $pdo->prepare($sqlPatios);
$stmtPatios->execute(['sucursal' => $sucursal_id]);
$patios = $stmtPatios->fetchAll(PDO::FETCH_ASSOC);

// Verificaciones de Patio y Bloque
$patioSeleccionado = isset($_GET['patio_id']) ? (int)$_GET['patio_id'] : null;
$bloques = [];
if ($patioSeleccionado) {
    $stmtCheck = $pdo->prepare('SELECT id FROM patios WHERE id = :id AND sucursal_id = :sucursal');
    $stmtCheck->execute(['id' => $patioSeleccionado, 'sucursal' => $sucursal_id]);
    
    if ($stmtCheck->fetch()) {
        $sqlBloques = 'SELECT b.id, b.numero AS bloque, b.capacidad_actual, b.capacidad_maxima FROM bloques b WHERE b.patio_id = :patio_id AND b.estado_id = 1 ORDER BY b.numero';
        $stmtBloques = $pdo->prepare($sqlBloques);
        $stmtBloques->execute(['patio_id' => $patioSeleccionado]);
        $bloques = $stmtBloques->fetchAll(PDO::FETCH_ASSOC);

        if (!isset($_GET['bloque_id']) && count($bloques) > 0) {
            header('Location: ?patio_id=' . $patioSeleccionado . '&bloque_id=' . $bloques[0]['id']);
            exit;
        }
    } else {
        $patioSeleccionado = null; 
    }
}

$bloqueSeleccionado = isset($_GET['bloque_id']) ? (int)$_GET['bloque_id'] : null;
$nombreBloqueSeleccionado = null;
$bloqueActual = 0;
if ($bloqueSeleccionado) {
    foreach ($bloques as $index => $bloque) {
        if ($bloque['id'] == $bloqueSeleccionado) {
            $nombreBloqueSeleccionado = $bloque['bloque'];
            $bloqueActual = $index;
            break;
        }
    }
}

// 5. OBTENER CONTENEDORES FILTRADOS
$contenedores = [];
if ($bloqueSeleccionado) {
    $sqlContenedores = '
    SELECT 
        c.id,
        c.posicion, 
        c.nombre, 
        eu.nombre AS estatus,
        eb.nombre AS estatus_box,
        ei.nombre AS estatus_ipsa,
        c.estatus_ipsa_id,
        pa.estatus_pyp,
        pa.informacion_adicional, -- <--- NUEVO CAMPO
        pa.contenedor_id AS es_asignado
    FROM contenedores c
    LEFT JOIN estatus_contenedores eu ON c.estatus_unidad_id = eu.id
    LEFT JOIN estatus_contenedores eb ON c.estatus_box_id = eb.id
    LEFT JOIN estatus_ipsa ei ON c.estatus_ipsa_id = ei.id
    LEFT JOIN pyp_auditoria pa ON c.id = pa.contenedor_id
    WHERE c.bloque_id = :bloque_id AND c.estado_id = 1
    ORDER BY c.posicion DESC
    ';
    $stmtContenedores = $pdo->prepare($sqlContenedores);
    $stmtContenedores->execute(['bloque_id' => $bloqueSeleccionado]);
    $contenedores = $stmtContenedores->fetchAll(PDO::FETCH_ASSOC);
}

// Paginación
$numBotonesMostrados = 10;
$totalBloques = count($bloques);
if ($bloqueActual < 8) {
    $rangoInicio = 0;
    $rangoFin = min($numBotonesMostrados, $totalBloques);
} else {
    $rangoInicio = $bloqueActual - 7;
    $rangoFin = min($bloqueActual + 3, $totalBloques);
}
if ($rangoFin - $rangoInicio < $numBotonesMostrados && $totalBloques >= $numBotonesMostrados) {
    if ($bloqueActual < $totalBloques - 2) {
        $rangoFin = min($rangoInicio + $numBotonesMostrados, $totalBloques);
    } else {
        $rangoInicio = max(0, $totalBloques - $numBotonesMostrados);
        $rangoFin = $totalBloques;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventario Contenedores</title>
    <link rel="stylesheet" href="../styles/parametros/bloques/contenedor.css">
    <style>
        .pyp-badge { font-weight: bold; color: #004d40; }
        .pyp-vacio { color: #999; font-style: italic; }
        .censurado { background-color: #f5f5f5; color: #aaa; }
        .censurado .censored-text { background: #e0e0e0; color: #e0e0e0; border-radius: 4px; user-select: none; }
        
        /* Modal Estilos */
        .modal {
            display: none; position: fixed; z-index: 10000; left: 0; top: 0;
            width: 100%; height: 100%; overflow: auto;
            background-color: rgba(0,0,0,0.5); backdrop-filter: blur(2px);
        }
        .modal-content {
            background-color: #fff; margin: 10% auto; padding: 25px;
            border-radius: 8px; width: 90%; max-width: 500px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3); text-align: center;
            border-top: 5px solid #00695C;
            position: relative;
        }
        .modal-buttons { margin-top: 20px; display: flex; justify-content: center; gap: 15px; }
        .btn-modal { padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; font-weight: bold; font-size: 1rem; }
        .btn-confirm { background-color: #00695C; color: white; }
        .btn-cancel { background-color: #dc3545; color: white; }
        
        /* Botones de Acción Tabla */
        .btn-asignar { color: #00695C; font-weight: bold; text-decoration: none; cursor: pointer; }
        .btn-asignar:hover { text-decoration: underline; }
        .btn-mas-info { color: #0277BD; font-weight: bold; text-decoration: none; cursor: pointer; }
        
        /* Contenido del Modal Info */
        .info-text-box {
            background: #f9f9f9; border: 1px solid #ddd; padding: 15px;
            margin-top: 15px; text-align: left; max-height: 300px; overflow-y: auto;
            border-radius: 4px; white-space: pre-wrap; /* Respeta saltos de línea */
        }

        /* Toast */
        #toast-container { position: fixed; top: 20px; right: 20px; z-index: 99999; }
        .toast { padding: 15px; border-radius: 5px; color: white; margin-bottom: 10px; opacity: 0; transition: opacity 0.5s; }
        .toast.show { opacity: 1; }
        .toast.success { background-color: #28a745; }
        .toast.error { background-color: #dc3545; }
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

<div id="toast-container"></div>

<main>
    <div class="title-container">
        <h1>Inventario de Contenedores</h1>
        <div class="actions-container">
            <input type="text" id="buscarInput" placeholder="Buscar contenedores..." onkeyup="buscarContenedores()">
            
            <?php if(in_array($rol_usuario, [1, 2, 4])): ?>
            <a href="../ingresos/ingresos.php" class="nuevo-button">
                <div class="nuevo-content">
                    <img src="../imagenes/nuevo.png" alt="Nuevo" class="nuevo-icon">
                    <span>Nuevo</span>
                </div>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="patio-header">
        <?php if ($patioSeleccionado): ?>
            <h2 class="patio-titulo">
                <?php echo htmlspecialchars($patios[array_search($patioSeleccionado, array_column($patios, 'id'))]['nombre']); ?>
                <?php if ($nombreBloqueSeleccionado): ?> - Bloque <?php echo htmlspecialchars($nombreBloqueSeleccionado); ?><?php endif; ?>
            </h2>
        <?php else: ?>
            <h2 class="patio-titulo"></h2>
        <?php endif; ?>

        <div class="patio-selector">
            <a href="javascript:void(0)" class="patio-button" id="patio-button">Seleccionar Patio</a>
            <div id="patio-list" class="patio-menu" style="display:none;">
                <?php foreach ($patios as $patio): ?>
                    <a href="?patio_id=<?php echo $patio['id']; ?>"><?php echo htmlspecialchars($patio['nombre']); ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="pagination-container mobile-top">
      <div class="pagination">
        <?php if ($bloqueActual > 0): ?>
          <a href="?patio_id=<?php echo $patioSeleccionado; ?>&bloque_id=<?php echo $bloques[$bloqueActual - 1]['id']; ?>" class="pagination-button">Anterior</a>
        <?php endif; ?>
        <?php for ($i = $rangoInicio; $i < $rangoFin; $i++): ?>
          <a href="?patio_id=<?php echo $patioSeleccionado; ?>&bloque_id=<?php echo $bloques[$i]['id']; ?>" class="pagination-button <?php echo ($bloqueActual == $i) ? 'active' : ''; ?>">
             <?php echo $bloques[$i]['bloque']; ?>
          </a>
        <?php endfor; ?>
        <?php if ($bloqueActual < $totalBloques - 1): ?>
          <a href="?patio_id=<?php echo $patioSeleccionado; ?>&bloque_id=<?php echo $bloques[$bloqueActual + 1]['id']; ?>" class="pagination-button">Siguiente</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="table-container full-mobile">
        <table>
            <thead>
                <tr>
                    <th>Posición</th>
                    <th>Nombre</th>
                    <th>Estatus Unidad</th>
                    <th>Estatus Box</th>
                    <th>Estatus IPSA</th>
                    <th>Estatus PYP</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody id="contenedoresTabla">
                <?php if (count($contenedores) > 0): ?>
                    <?php foreach ($contenedores as $contenedor): ?>
                        <?php
                            $esPYP = ($rol_usuario == 5);
                            $esMecanico = ($rol_usuario == 4);
                            $esInterno = in_array($rol_usuario, [1, 2, 3]);

                            $esDisponible = ($contenedor['estatus_ipsa_id'] == 1);
                            $yaAsignado = !empty($contenedor['es_asignado']);

                            $rolesRestringidos = ($esPYP || $esMecanico);
                            $censurado = ($rolesRestringidos && !$esDisponible && !$yaAsignado);
                            
                            // Texto info adicional para el modal (Encoding seguro)
                            $infoAdicional = $contenedor['informacion_adicional'] ?? '';
                        ?>
                        <tr class="<?php echo $censurado ? 'censurado' : ''; ?>">
                            <td data-label="Posición"><?php echo $contenedor['posicion']; ?></td>
                            
                            <td data-label="Nombre">
                                <?php echo $censurado ? '<span class="censored-text">XXXXXXXXXXX</span>' : htmlspecialchars($contenedor['nombre']); ?>
                            </td>

                            <td data-label="Estatus Unidad"><?php echo $censurado ? '---' : htmlspecialchars($contenedor['estatus']); ?></td>
                            <td data-label="Estatus Box"><?php echo $censurado ? '---' : htmlspecialchars($contenedor['estatus_box']); ?></td>
                            <td data-label="Estatus IPSA"><?php echo $censurado ? 'No Disponible' : htmlspecialchars($contenedor['estatus_ipsa']); ?></td>
                            
                            <td data-label="Estatus PYP">
                                <?php if ($censurado): ?>
                                    ---
                                <?php elseif(!empty($contenedor['estatus_pyp'])): ?>
                                    <span class="pyp-badge"><?php echo htmlspecialchars($contenedor['estatus_pyp']); ?></span>
                                <?php else: ?>
                                    <span class="pyp-vacio">Sin Asignar</span>
                                <?php endif; ?>
                            </td>

                            <td data-label="Acciones">
                                <?php if (!$censurado): ?>
                                    <?php 
                                        $params = "nombre=" . urlencode($contenedor['nombre']);
                                        if ($patioSeleccionado && $bloqueSeleccionado) {
                                            $params .= "&ref_patio=$patioSeleccionado&ref_bloque=$bloqueSeleccionado";
                                        }
                                        $botones = [];
                                    ?>
                                    
                                    <?php if ($esPYP && $esDisponible && !$yaAsignado): ?>
                                        <a href="#" onclick="confirmarAsignacion(<?php echo $contenedor['id']; ?>, '<?php echo $contenedor['nombre']; ?>'); return false;" class="btn-asignar">Asignar</a>
                                    
                                    <?php else: ?>
                                        
                                        <?php if (($esPYP || $esMecanico) && $yaAsignado): ?>
                                            <?php $botones[] = '<a href="auditoria_pyp.php?' . $params . '" class="informacion">Estatus PYP</a>'; ?>
                                        <?php endif; ?>

                                        <?php if ($yaAsignado): ?>
                                            <?php 
                                                // Preparamos el texto para JS (encoding URL)
                                                $textoJs = rawurlencode($infoAdicional);
                                            ?>
                                            <?php $botones[] = '<a href="#" onclick="verInfo(\'' . $textoJs . '\'); return false;" class="modificar">Mas Info</a>'; ?>
                                        <?php elseif ($esInterno): ?>
                                            <?php $botones[] = '<a href="#" onclick="verInfo(\'\'); return false;" class="modificar">Mas Info</a>'; ?>
                                        <?php endif; ?>

                                        <?php echo implode(' | ', $botones); ?>

                                    <?php endif; ?>

                                <?php else: ?>
                                    <span style="color:#aaa; font-size:0.8em;">Bloqueado</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7" style="text-align: center;">No hay contenedores registrados para este bloque</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
        
    <div class="pagination-container">
        <a href="../index.php" class="regresar-button">
            <div class="regresar-content">
                <img src="../imagenes/regresar.png" alt="Regresar" class="regresar-icon">
                <span>Regresar</span>
            </div>
        </a>
        <div class="pagination">
            <?php if ($bloqueActual > 0): ?>
                <a href="?patio_id=<?php echo $patioSeleccionado; ?>&bloque_id=<?php echo $bloques[$bloqueActual - 1]['id']; ?>" class="pagination-button">Anterior</a>
            <?php endif; ?>
            <?php for ($i = $rangoInicio; $i < $rangoFin; $i++): ?>
                <a href="?patio_id=<?php echo $patioSeleccionado; ?>&bloque_id=<?php echo $bloques[$i]['id']; ?>" class="pagination-button <?php echo ($bloqueActual == $i) ? 'active' : ''; ?>"><?php echo $bloques[$i]['bloque']; ?></a>
            <?php endfor; ?>
            <?php if ($bloqueActual < $totalBloques - 1): ?>
                <a href="?patio_id=<?php echo $patioSeleccionado; ?>&bloque_id=<?php echo $bloques[$bloqueActual + 1]['id']; ?>" class="pagination-button">Siguiente</a>
            <?php endif; ?>
        </div>
        <a href="../index.php" class="regresar-button regresar-movil">
            <div class="regresar-content"><img src="../imagenes/regresar.png" alt="Regresar" class="regresar-icon"><span>Regresar</span></div>
        </a>
    </div>
</main>

<div id="modalAsignar" class="modal">
    <div class="modal-content">
        <h3>Asignar Contenedor</h3>
        <p>¿Deseas asignarte el contenedor <strong id="modalContenedorNombre"></strong>?</p>
        <p style="font-size:0.9em; color:#666;">Se registrará el inicio de la auditoría.</p>
        <div class="modal-buttons">
            <button class="btn-modal btn-cancel" onclick="cerrarModal('modalAsignar')">Cancelar</button>
            <form action="asignar_contenedor.php" method="POST" style="display:inline;">
                <input type="hidden" name="contenedor_id" id="modalContenedorId">
                <button type="submit" class="btn-modal btn-confirm">Sí, asignar</button>
            </form>
        </div>
    </div>
</div>

<div id="modalInfo" class="modal">
    <div class="modal-content">
        <h3>Información Adicional (PYP)</h3>
        <div class="info-text-box" id="textoInfoAdicional">
            </div>
        <div class="modal-buttons">
            <button class="btn-modal btn-confirm" onclick="cerrarModal('modalInfo')">Cerrar</button>
        </div>
    </div>
</div>

<footer><p>&copy; IPSA Web - 2025</p></footer>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const todosContenedores = <?php echo json_encode($todosContenedores); ?>;
    const userRole = <?php echo $rol_usuario; ?>; 
    
    const buscarInput = document.getElementById('buscarInput');
    const contenedoresTabla = document.getElementById('contenedoresTabla');
    const patioButton = document.getElementById('patio-button');
    const patioList = document.getElementById('patio-list');

    function buscarContenedores() {
        const busqueda = buscarInput.value.toLowerCase();
        const patioTitulo = document.querySelector('.patio-titulo');

        if (patioTitulo) {
            if (busqueda.length > 0) {
                patioTitulo.style.display = 'none';
                if (patioButton) patioButton.style.display = 'none';
                if (patioList) patioList.style.display = 'none';

                const resultados = todosContenedores.filter(contenedor =>
                    contenedor.nombre.toLowerCase().includes(busqueda)
                );
                mostrarContenedores(resultados, true);
            } else {
                patioTitulo.style.display = '';
                if (patioButton) patioButton.style.display = '';
                location.reload();
            }
        }
    }

    function mostrarContenedores(contenedores, mostrarUbicacion = false) {
        contenedoresTabla.innerHTML = '';
        
        if (contenedores.length > 0) {
            contenedores.forEach(contenedor => {
                const esPYP = (userRole === 5);
                const esMecanico = (userRole === 4);
                const esInterno = [1, 2, 3].includes(userRole);

                const esDisponible = (contenedor.estatus_ipsa_id == 1);
                const esAsignado = !!contenedor.es_asignado; 

                const rolesRestringidos = (esPYP || esMecanico);
                const censurado = (rolesRestringidos && !esDisponible && !esAsignado);

                const tr = document.createElement('tr');
                if (censurado) tr.classList.add('censurado');

                const nombreHtml = censurado ? '<span class="censored-text">XXXXXXXXXXX</span>' : contenedor.nombre;
                const estatusUni = censurado ? '---' : (contenedor.estatus || '');
                const estatusBox = censurado ? '---' : (contenedor.estatus_box || '');
                const estatusIpsa = censurado ? 'No Disponible' : (contenedor.estatus_ipsa || '');
                
                const pypHtml = censurado ? '---' : (contenedor.estatus_pyp 
                    ? `<span class="pyp-badge">${contenedor.estatus_pyp}</span>` 
                    : `<span class="pyp-vacio">Sin Asignar</span>`);

                // --- BOTONES DINÁMICOS JS ---
                let botones = '';
                
                if (!censurado) {
                    const params = `nombre=${encodeURIComponent(contenedor.nombre)}`;
                    let links = [];

                    // 1. Asignar (Solo PYP + Disp + NoAsig)
                    if (esPYP && esDisponible && !esAsignado) {
                        botones = `<a href="#" onclick="confirmarAsignacion(${contenedor.id}, '${contenedor.nombre}'); return false;" class="btn-asignar">Asignar</a>`;
                    } 
                    else {
                        // 2. Estatus PYP (Ext + Asignado)
                        if ((esPYP || esMecanico) && esAsignado) {
                            links.push(`<a href="auditoria_pyp.php?${params}" class="informacion">Estatus PYP</a>`);
                        }
                        
                        // 3. Mas Info (Modal)
                        if (esAsignado || esInterno) {
                            // En JS tenemos el objeto contenedor directo, no necesitamos urldecode
                            // Pero encodeamos para pasar la string segura a la funcion
                            const infoRaw = contenedor.informacion_adicional || '';
                            const infoSafe = encodeURIComponent(infoRaw);
                            links.push(`<a href="#" onclick="verInfo('${infoSafe}'); return false;" class="modificar">Mas Info</a>`);
                        }
                        
                        botones = links.join(' | ');
                    }
                } else {
                    botones = '<span style="color:#aaa; font-size:0.8em;">Bloqueado</span>';
                }

                tr.innerHTML = `
                    <td data-label="Posición">${contenedor.posicion}</td>
                    <td data-label="Nombre">${nombreHtml}</td>
                    <td data-label="Estatus Unidad">${estatusUni}</td>
                    <td data-label="Estatus Box">${estatusBox}</td>
                    <td data-label="Estatus IPSA">${estatusIpsa}</td>
                    <td data-label="Estatus PYP">${pypHtml}</td>
                    <td data-label="Acciones">${botones}</td>
                `;

                if (mostrarUbicacion && contenedor.patio_id && !censurado) {
                    tr.addEventListener('click', (e) => {
                        if (e.target.tagName !== 'A') {
                            window.location.href = `?patio_id=${contenedor.patio_id}&bloque_id=${contenedor.bloque_id}`;
                        }
                    });
                    tr.style.cursor = 'pointer';
                }
                contenedoresTabla.appendChild(tr);
            });
        } else {
            contenedoresTabla.innerHTML = `<tr><td colspan="7" style="text-align:center;">No se encontraron contenedores</td></tr>`;
        }
    }

    buscarInput.addEventListener('input', buscarContenedores);
    patioButton.addEventListener('click', () => {
        patioList.style.display = patioList.style.display === 'none' ? 'block' : 'none';
    });
});

// --- FUNCIONES GLOBALES ---

function confirmarAsignacion(id, nombre) {
    document.getElementById('modalContenedorId').value = id;
    document.getElementById('modalContenedorNombre').textContent = nombre;
    document.getElementById('modalAsignar').style.display = 'block';
}

function verInfo(textoEncoded) {
    const texto = decodeURIComponent(textoEncoded);
    const divTexto = document.getElementById('textoInfoAdicional');
    
    if (!texto || texto.trim() === '') {
        divTexto.innerHTML = '<em style="color:#999">No hay información adicional registrada.</em>';
    } else {
        divTexto.textContent = texto; // textContent para seguridad XSS
    }
    
    document.getElementById('modalInfo').style.display = 'block';
}

function cerrarModal(id) {
    document.getElementById(id).style.display = 'none';
}

// Cerrar cualquier modal al hacer clic fuera
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = "none";
    }
}

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