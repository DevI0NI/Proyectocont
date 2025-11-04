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

    // Seleccionar automáticamente el Bloque 1 si no se seleccionó ningún bloque
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

// Calcular el rango basado en el bloque actual
if ($bloqueActual < 8) {
    // Si estamos en los primeros bloques, mostrar los primeros 10
    $rangoInicio = 0;
    $rangoFin = min($numBotonesMostrados, $totalBloques);
} else {
    // Si estamos más adelante, mantener el bloque actual con 7 antes y 2 después
    $rangoInicio = $bloqueActual - 7;
    $rangoFin = min($bloqueActual + 3, $totalBloques);
}

// Asegurar que siempre se muestren 10 bloques si hay disponibles
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
    <link rel="stylesheet" href="../../styles/parametros/bloques/bloques.css">
</head>
<body>
    <header>
        <div class="header-left">
            <a href="../../index.php">
                <img src="../../imagenes/IPSA.png" alt="Logo" class="logo">
                <span>IPROPORTSA</span>
            </a>
        </div>
        <div class="header-right">
            <span>Bienvenido, <?php echo $_SESSION['username']; ?></span>
            <a href="../../logout.php" class="logout-button">Cerrar sesión</a>
        </div>
    </header>
    <main>
        <div class="title-container">
            <h1>Configuración de Bloques</h1>
            <?php if ($patioSeleccionado): ?>
                <div class="actions-container">
                    <input type="text" id="buscarInput" placeholder="Buscar contenedores..." onkeyup="buscarContenedores()">
                    <a href="../../ingresos/ingresos.php" class="nuevo-button">
                        <div class="nuevo-content">
                            <img src="../../imagenes/nuevo.png" alt="Nuevo" class="nuevo-icon">
                            <span>Nuevo</span>
                        </div>
                    </a>
                </div>
            <?php endif; ?>
        </div>

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
            <a href="javascript:void(0)" class="patio-button" id="patio-button">Seleccionar Patio</a>
        </div>

        <div id="patio-list" class="patio-menu" style="display: none;">
            <?php foreach ($patios as $patio): ?>
                <a href="?patio_id=<?php echo $patio['id']; ?>"><?php echo htmlspecialchars($patio['nombre']); ?></a>
            <?php endforeach; ?>
        </div>
        
        <?php if ($patioSeleccionado): ?>
            <div class="table-container">
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
                <td><?php echo $contenedor['posicion']; ?></td>
                <td><?php echo htmlspecialchars($contenedor['nombre']); ?></td>
                <td><?php echo htmlspecialchars($contenedor['estatus']); ?></td>
                <td><?php echo htmlspecialchars($contenedor['estatus_box']); ?></td>
                <td><?php echo htmlspecialchars($contenedor['estatus_ipsa']); ?></td>
                <td><?php echo htmlspecialchars($contenedor['fecha_ingreso']); ?></td>
                <td>
                    <a href="../../bloques/modificar_contenedor.php?nombre=<?php echo htmlspecialchars($contenedor['nombre']); ?>" class="modificar">Modificar</a> |
                    <a href="../../bloques/informacion_contenedor.php?nombre=<?php echo htmlspecialchars($contenedor['nombre']); ?>" class="informacion">Información</a>
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
            
            <div class="pagination-container">
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
            </div>
        <?php endif; ?>

        <!-- Botón de regresar siempre visible -->
        <div style="margin: 30px 0 0 30px;">
            <a href="../../index.php" class="regresar-button">
                <div class="regresar-content">
                    <img src="../../imagenes/regresar.png" alt="Regresar" class="regresar-icon">
                    <span>Regresar</span>
                </div>
            </a>
        </div>

        <div class="configuracion-button">
            <a href="configuracion.php">
                <img src="../../imagenes/parametros.png" alt="Configuración">
                <span>Configuración</span>
            </a>
        </div>
    </main>
    <footer>
        <p>&copy; IPSA Web - 2025</p>
    </footer>
    <script>
        document.getElementById('patio-button').addEventListener('click', function () {
            const patioList = document.getElementById('patio-list');
            patioList.style.display = patioList.style.display === 'none' ? 'block' : 'none';
        });

        function buscarContenedores() {
            let input = document.getElementById('buscarInput');
            let filter = input.value.toLowerCase();
            let tbody = document.querySelector('table tbody');
            let tr = tbody.getElementsByTagName('tr');

            for (let i = 0; i < tr.length; i++) {
                let td = tr[i].getElementsByTagName('td')[1]; // Columna del nombre
                if (td) {
                    let txtValue = td.textContent || td.innerText;
                    if (txtValue.toLowerCase().indexOf(filter) > -1) {
                        tr[i].style.display = '';
                    } else {
                        tr[i].style.display = 'none';
                    }
                }
            }
        }
    </script>
</body>
</html>