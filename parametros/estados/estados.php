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

// Configuración de la paginación
$estadosPorPagina = 10; // Número de estados por página
$paginaActual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1; // Página actual
$inicio = ($paginaActual > 1) ? ($paginaActual * $estadosPorPagina) - $estadosPorPagina : 0; // Cálculo del inicio

// Determinar el tipo de tabla a mostrar (Unidad o Estructura)
$tipo = isset($_GET['tipo']) && $_GET['tipo'] === 'Estructura' ? 'Estructura' : 'Unidad';

// Obtener el total de estados
$sqlTotal = 'SELECT COUNT(*) FROM estatus_contenedores WHERE tipo = :tipo';
$stmtTotal = $pdo->prepare($sqlTotal);
$stmtTotal->execute(['tipo' => $tipo]);
$totalEstados = $stmtTotal->fetchColumn();
$totalPaginas = ceil($totalEstados / $estadosPorPagina);

// Obtener los estados según el tipo seleccionado
$sql = '
    SELECT id, nombre
    FROM estatus_contenedores
    WHERE tipo = :tipo
    LIMIT :inicio, :estadosPorPagina
';
$stmt = $pdo->prepare($sql);
$stmt->bindParam(':tipo', $tipo, PDO::PARAM_STR);
$stmt->bindParam(':inicio', $inicio, PDO::PARAM_INT);
$stmt->bindParam(':estadosPorPagina', $estadosPorPagina, PDO::PARAM_INT);
$stmt->execute();
$estados = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Estatus de Contenedores</title>
    <link rel="stylesheet" href="../../styles/parametros/estados/estados.css">
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
        <div class="title-container">
            <h1>Estatus de Contenedores - <?php echo htmlspecialchars($tipo); ?></h1>
            <div class="action-buttons">
                <a href="?tipo=<?php echo $tipo === 'Unidad' ? 'Estructura' : 'Unidad'; ?>" class="cambiar-button">
                    <div class="cambiar-content">
                        <img src="../../imagenes/cambiar.png" alt="Cambiar" class="cambiar-icon">
                        <span>Cambiar a <?php echo $tipo === 'Unidad' ? 'Estructura' : 'Unidad'; ?></span>
                    </div>
                </a>
                <a href="nuevo_estatus.php?tipo=<?php echo $tipo; ?>" class="nuevo-button">
                    <div class="nuevo-content">
                        <img src="../../imagenes/nuevo.png" alt="Nuevo" class="nuevo-icon">
                        <span>Nuevo</span>
                    </div>
                </a>
            </div>
        </div>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nombre</th>
                        <th>Acciones</th> <!-- Nueva columna de acciones -->
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($estados as $estado): ?>
                    <tr>
                        <td><?php echo $estado['id']; ?></td>
                        <td><?php echo htmlspecialchars($estado['nombre']); ?></td>
                        <td>
                            <a href="editar_estatus.php?id=<?php echo $estado['id']; ?>" class="modificar">Modificar</a> |
                            <a href="eliminar_estatus.php?id=<?php echo $estado['id']; ?>" class="eliminar" onclick="return confirm('¿Está seguro de eliminar este estatus?')">Eliminar</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (count($estados) === 0): ?>
                    <tr>
                        <td colspan="3" style="text-align: center;">No hay estados registrados</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <div class="button-container">
                <a href="../parametros.php" class="regresar-button">
                    <div class="regresar-content">
                        <img src="../../imagenes/regresar.png" alt="Regresar" class="regresar-icon">
                        <span>Regresar</span>
                    </div>
                </a>
                <div class="pagination">
                    <?php if ($paginaActual > 1): ?>
                        <a href="?tipo=<?php echo $tipo; ?>&pagina=<?php echo $paginaActual - 1; ?>" class="pagination-button">Anterior</a>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $totalPaginas; $i++): ?>
                        <a href="?tipo=<?php echo $tipo; ?>&pagina=<?php echo $i; ?>" class="pagination-button <?php echo ($i == $paginaActual) ? 'active' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>

                    <?php if ($paginaActual < $totalPaginas): ?>
                        <a href="?tipo=<?php echo $tipo; ?>&pagina=<?php echo $paginaActual + 1; ?>" class="pagination-button">Siguiente</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
    <footer>
        <p>&copy; IPSA Web - 2025</p>
    </footer>
</body>
</html>