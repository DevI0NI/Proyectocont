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
$estadosPorPagina = 10;
$paginaActual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$inicio = ($paginaActual > 1) ? ($paginaActual * $estadosPorPagina) - $estadosPorPagina : 0;

// 1. Lógica de Filtro (Nombre -> ID)
$nombreTipo = isset($_GET['tipo']) && $_GET['tipo'] === 'Estructura' ? 'Estructura' : 'Unidad';

$stmtTipo = $pdo->prepare('SELECT id FROM tipos_estatus WHERE nombre = :nombre');
$stmtTipo->execute(['nombre' => $nombreTipo]);
$tipoId = $stmtTipo->fetchColumn();

if (!$tipoId) $tipoId = 1;

// 2. Consultas (Solo Activos)
$sqlTotal = 'SELECT COUNT(*) FROM estatus_contenedores WHERE tipo_estatus_id = :tipo_id AND estado_id = 1';
$stmtTotal = $pdo->prepare($sqlTotal);
$stmtTotal->execute(['tipo_id' => $tipoId]);
$totalEstados = $stmtTotal->fetchColumn();
$totalPaginas = ceil($totalEstados / $estadosPorPagina);

$sql = '
    SELECT id, nombre
    FROM estatus_contenedores
    WHERE tipo_estatus_id = :tipo_id AND estado_id = 1
    ORDER BY nombre ASC
    LIMIT :inicio, :estadosPorPagina
';
$stmt = $pdo->prepare($sql);
$stmt->bindParam(':tipo_id', $tipoId, PDO::PARAM_INT);
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
    <style>
        /* Estilos simples para las alertas en esta página */
        .alert {
            padding: 15px;
            margin: 20px auto;
            width: 65%; /* Mismo ancho que tus contenedores principales */
            border-radius: 5px;
            text-align: center;
            font-weight: bold;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
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

    <!-- BLOQUE DE MENSAJES -->
    <?php if (isset($_SESSION['toast'])): ?>
        <div class="alert alert-<?php echo $_SESSION['toast']['type']; ?>">
            <?php echo htmlspecialchars($_SESSION['toast']['text']); ?>
        </div>
        <?php unset($_SESSION['toast']); ?>
    <?php endif; ?>

    <main>
        <div class="title-container">
            <h1>Estatus de Contenedores - <?php echo htmlspecialchars($nombreTipo); ?></h1>
            <div class="action-buttons">
                <a href="?tipo=<?php echo $nombreTipo === 'Unidad' ? 'Estructura' : 'Unidad'; ?>" class="cambiar-button">
                    <div class="cambiar-content">
                        <img src="../../imagenes/cambiar.png" alt="Cambiar" class="cambiar-icon">
                        <span>Cambiar a <?php echo $nombreTipo === 'Unidad' ? 'Estructura' : 'Unidad'; ?></span>
                    </div>
                </a>
                <a href="nuevo_estatus.php?tipo=<?php echo urlencode($nombreTipo); ?>" class="nuevo-button">
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
                        <th>Nombre</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($estados as $estado): ?>
                    <tr>
                        <!-- Mostramos solo el nombre como en tu original, ID es interno -->
                        <td><?php echo htmlspecialchars($estado['nombre']); ?></td>
                        <td>
                            <a href="editar_estatus.php?id=<?php echo (int)$estado['id']; ?>" class="modificar">Modificar</a> |
                            <a href="eliminar_estatus.php?id=<?php echo (int)$estado['id']; ?>" class="eliminar" onclick="return confirm('¿Está seguro de eliminar este estatus?')">Eliminar</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if (count($estados) === 0): ?>
                    <tr>
                        <td colspan="2" style="text-align: center;">No hay estatus registrados</td>
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
                        <a href="?tipo=<?php echo $nombreTipo; ?>&pagina=<?php echo $paginaActual - 1; ?>" class="pagination-button">Anterior</a>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $totalPaginas; $i++): ?>
                        <a href="?tipo=<?php echo $nombreTipo; ?>&pagina=<?php echo $i; ?>" class="pagination-button <?php echo ($i == $paginaActual) ? 'active' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>

                    <?php if ($paginaActual < $totalPaginas): ?>
                        <a href="?tipo=<?php echo $nombreTipo; ?>&pagina=<?php echo $paginaActual + 1; ?>" class="pagination-button">Siguiente</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
    <footer>
        <p>&copy; IPSA Web - 2025</p>
    </footer>
    
    <!-- Script para ocultar la alerta después de 3 segundos -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const alertBox = document.querySelector('.alert');
            if (alertBox) {
                setTimeout(() => {
                    alertBox.style.transition = "opacity 0.5s ease";
                    alertBox.style.opacity = "0";
                    setTimeout(() => alertBox.remove(), 500);
                }, 3000);
            }
        });
    </script>
</body>
</html>