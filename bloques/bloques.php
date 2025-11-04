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

require '../config/database.php';

// Obtener todos los contenedores para búsqueda global
$sqlTodosContenedores = "
    SELECT 
        c.nombre,
        c.posicion,
        c.estatus_unidad AS estatus,
        c.estatus_box,
        c.estatus_ipsa,
        c.fecha_ingreso,
        b.id as bloque_id,
        b.numero as bloque_numero,
        p.id as patio_id,
        p.nombre as patio_nombre
    FROM contenedores c
    JOIN bloques b ON c.bloque_id = b.id
    JOIN patios p ON b.patio_id = p.id
    ORDER BY p.id, b.numero, c.posicion
";
$stmtTodosContenedores = $pdo->query($sqlTodosContenedores);
$todosContenedores = $stmtTodosContenedores->fetchAll(PDO::FETCH_ASSOC);

// Obtener la lista de patios
$sqlPatios = 'SELECT id, nombre FROM patios';
$stmtPatios = $pdo->query($sqlPatios);
$patios = $stmtPatios->fetchAll();

// Verificar si se seleccionó un patio
$patioSeleccionado = isset($_GET['patio_id']) ? (int)$_GET['patio_id'] : null;

// Obtener los bloques del patio seleccionado
$bloques = [];
if ($patioSeleccionado) {
    $sqlBloques = '
        SELECT b.id, b.numero AS bloque, b.capacidad_actual, b.capacidad_maxima
        FROM bloques b
        WHERE b.patio_id = :patio_id
        ORDER BY b.numero
    ';
    $stmtBloques = $pdo->prepare($sqlBloques);
    $stmtBloques->execute(['patio_id' => $patioSeleccionado]);
    $bloques = $stmtBloques->fetchAll();

    if (!isset($_GET['bloque_id']) && count($bloques) > 0) {
        header('Location: ?patio_id=' . $patioSeleccionado . '&bloque_id=' . $bloques[0]['id']);
        exit;
    }
}

// Verificar si se seleccionó un bloque
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

// Obtener los contenedores del bloque seleccionado
$contenedores = [];
if ($bloqueSeleccionado) {
    $sqlContenedores = '
    SELECT c.posicion, c.nombre, c.estatus_unidad AS estatus, c.estatus_box, c.estatus_ipsa, c.fecha_ingreso, c.observaciones_unidad
    FROM contenedores c
    WHERE c.bloque_id = :bloque_id
    ORDER BY c.posicion
';
    $stmtContenedores = $pdo->prepare($sqlContenedores);
    $stmtContenedores->execute(['bloque_id' => $bloqueSeleccionado]);
    $contenedores = $stmtContenedores->fetchAll();
}

// Configuración de paginación
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
    <title>Configuración de Bloques</title>
    <link rel="stylesheet" href="../styles/parametros/bloques/bloques.css">
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
    <div class="title-container">
        <h1>Configuración de Bloques</h1>
        <div class="actions-container">
            <input type="text" id="buscarInput" placeholder="Buscar contenedores..." onkeyup="buscarContenedores()">
            <a href="../ingresos/ingresos.php" class="nuevo-button">
                <div class="nuevo-content">
                    <img src="../imagenes/nuevo.png" alt="Nuevo" class="nuevo-icon">
                    <span>Nuevo</span>
                </div>
            </a>
        </div>
    </div>

    <!-- Contenedor combinado del título de patio + botón + menú -->
    <div class="patio-header">
        <?php if ($patioSeleccionado): ?>
            <h2 class="patio-titulo">
                <?php echo htmlspecialchars($patios[array_search($patioSeleccionado, array_column($patios, 'id'))]['nombre']); ?>
                <?php if ($nombreBloqueSeleccionado): ?> 
                    - Bloque <?php echo htmlspecialchars($nombreBloqueSeleccionado); ?>
                <?php endif; ?>
            </h2>
        <?php else: ?>
            <h2 class="patio-titulo"></h2>
        <?php endif; ?>

        <!-- NUEVO contenedor flexible para el botón y su menú -->
        <div class="patio-selector">
            <a href="javascript:void(0)" class="patio-button" id="patio-button">Seleccionar Patio</a>
            <div id="patio-list" class="patio-menu" style="display:none;">
                <?php foreach ($patios as $patio): ?>
                    <a href="?patio_id=<?php echo $patio['id']; ?>"><?php echo htmlspecialchars($patio['nombre']); ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Paginación superior móvil -->
    <div class="pagination-container mobile-top">
      <div class="pagination">
        <?php if ($bloqueActual > 0): ?>
          <a href="?patio_id=<?php echo $patioSeleccionado; ?>&bloque_id=<?php echo $bloques[$bloqueActual - 1]['id']; ?>" class="pagination-button">Anterior</a>
        <?php endif; ?>

        <?php for ($i = $rangoInicio; $i < $rangoFin; $i++): ?>
          <a href="?patio_id=<?php echo $patioSeleccionado; ?>&bloque_id=<?php echo $bloques[$i]['id']; ?>"
             class="pagination-button <?php echo ($bloqueActual == $i) ? 'active' : ''; ?>">
             <?php echo $bloques[$i]['bloque']; ?>
          </a>
        <?php endfor; ?>

        <?php if ($bloqueActual < $totalBloques - 1): ?>
          <a href="?patio_id=<?php echo $patioSeleccionado; ?>&bloque_id=<?php echo $bloques[$bloqueActual + 1]['id']; ?>" class="pagination-button">Siguiente</a>
        <?php endif; ?>
      </div>
    </div>

    <!-- Tabla de contenedores -->
    <div class="table-container full-mobile">
        <table>
            <thead>
                <tr>
                    <th>Posición</th>
                    <th>Nombre</th>
                    <th>Estatus Unidad</th>
                    <th>Estatus Box</th>
                    <th>Estatus IPSA</th>
                    <th>Fecha Ingreso</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody id="contenedoresTabla">
                <?php if (count($contenedores) > 0): ?>
                    <?php foreach ($contenedores as $contenedor): ?>
                    <tr>
                        <td data-label="Posición"><?php echo $contenedor['posicion']; ?></td>
                        <td data-label="Nombre"><?php echo htmlspecialchars($contenedor['nombre']); ?></td>
                        <td data-label="Estatus Unidad"><?php echo htmlspecialchars($contenedor['estatus']); ?></td>
                        <td data-label="Estatus Box"><?php echo htmlspecialchars($contenedor['estatus_box']); ?></td>
                        <td data-label="Estatus IPSA"><?php echo htmlspecialchars($contenedor['estatus_ipsa']); ?></td>
                        <td data-label="Fecha Ingreso"><?php echo htmlspecialchars($contenedor['fecha_ingreso']); ?></td>
                        <td data-label="Acciones">
                            <a href="modificar_contenedor.php?nombre=<?php echo htmlspecialchars($contenedor['nombre']); ?>" class="modificar">Modificar</a> |
                            <a href="informacion_contenedor.php?nombre=<?php echo htmlspecialchars($contenedor['nombre']); ?>" class="informacion">Información</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" style="text-align: center;">No hay contenedores registrados para este bloque</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
        
    <!-- Paginación inferior -->
    <div class="pagination-container">
        <a href="../index.php" class="regresar-button">
            <div class="regresar-content">
                <img src="../imagenes/regresar.png" alt="Regresar" class="regresar-icon">
                <span>Regresar</span>
            </div>
        </a>
        <div class="pagination">
            <?php if ($bloqueActual > 0): ?>
                <a href="?patio_id=<?php echo $patioSeleccionado; ?>&bloque_id=<?php echo $bloques[$bloqueActual - 1]['id']; ?>" 
                   class="pagination-button">Anterior</a>
            <?php endif; ?>

            <?php for ($i = $rangoInicio; $i < $rangoFin; $i++): ?>
                <a href="?patio_id=<?php echo $patioSeleccionado; ?>&bloque_id=<?php echo $bloques[$i]['id']; ?>" 
                   class="pagination-button <?php echo ($bloqueActual == $i) ? 'active' : ''; ?>">
                    <?php echo $bloques[$i]['bloque']; ?>
                </a>
            <?php endfor; ?>

            <?php if ($bloqueActual < $totalBloques - 1): ?>
                <a href="?patio_id=<?php echo $patioSeleccionado; ?>&bloque_id=<?php echo $bloques[$bloqueActual + 1]['id']; ?>" 
                   class="pagination-button">Siguiente</a>
            <?php endif; ?>
        </div>
            <a href="../index.php" class="regresar-button regresar-movil">
        <div class="regresar-content">
            <img src="../imagenes/regresar.png" alt="Regresar" class="regresar-icon">
            <span>Regresar</span>
        </div>
    </a>
    </div>
</main>

<footer>
    <p>&copy; IPSA Web - 2025</p>
</footer>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const todosContenedores = <?php echo json_encode($todosContenedores); ?>;
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
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td data-label="Posición">${contenedor.posicion}</td>
                    <td data-label="Nombre">${contenedor.nombre}</td>
                    <td data-label="Estatus Unidad">${contenedor.estatus || ''}</td>
                    <td data-label="Estatus Box">${contenedor.estatus_box || ''}</td>
                    <td data-label="Estatus IPSA">${contenedor.estatus_ipsa || ''}</td>
                    <td data-label="Fecha Ingreso">${contenedor.fecha_ingreso || ''}</td>
                    <td data-label="Acciones">
                        <a href="modificar_contenedor.php?nombre=${contenedor.nombre}" class="modificar">Modificar</a> |
                        <a href="informacion_contenedor.php?nombre=${contenedor.nombre}" class="informacion">Información</a>
                    </td>
                `;
                if (mostrarUbicacion && contenedor.patio_id) {
                    tr.addEventListener('click', () => {
                        window.location.href = `?patio_id=${contenedor.patio_id}&bloque_id=${contenedor.bloque_id}`;
                    });
                    tr.style.cursor = 'pointer';
                }
                contenedoresTabla.appendChild(tr);
            });
        } else {
            contenedoresTabla.innerHTML = `
                <tr><td colspan="7" style="text-align:center;">No se encontraron contenedores</td></tr>
            `;
        }
    }

    buscarInput.addEventListener('input', buscarContenedores);

    patioButton.addEventListener('click', function () {
        patioList.style.display = patioList.style.display === 'none' ? 'block' : 'none';
    });
});
</script>
</body>
</html>
