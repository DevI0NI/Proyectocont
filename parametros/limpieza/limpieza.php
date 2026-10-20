<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

$_SESSION['toast_message'] = 'Esta herramienta de mantenimiento no esta disponible en el sistema.';
$_SESSION['toast_type'] = 'error';
header('Location: ../parametros.php');
exit;

// 2. CONFIGURACIÓN REGIONAL
date_default_timezone_set('America/Guatemala');
$fechaInicioDefecto = date('Y-m-01');
$fechaFinDefecto    = date('Y-m-t');

require '../../config/database.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Limpieza de Datos</title>
    <link rel="stylesheet" href="../../styles/reportes.css">
    <style>
        .clean-wrapper {
            max-width: 900px; /* Más ancho para acomodar las tarjetas */
            margin: 30px auto;
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            border-top: 6px solid #d32f2f;
        }
        
        .warning-banner {
            background-color: #fff5f5;
            color: #c62828;
            border: 1px solid #ffcdd2;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            display: flex;
            gap: 15px;
            align-items: center;
        }
        .warning-icon { font-size: 2rem; }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        label { font-weight: bold; color: #555; display: block; margin-bottom: 5px; font-size: 0.9rem; }
        input[type="date"] { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 1rem; box-sizing: border-box; }

        .btn-analyze {
            background-color: #1976D2;
            color: white;
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 4px;
            font-size: 1rem;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-analyze:hover { background-color: #1565C0; }

        /* GRID DE RESULTADOS (RESPONSIVE) */
        #resultado-zona { display: none; margin-top: 30px; border-top: 1px solid #eee; padding-top: 20px; }
        
        .results-grid {
            display: grid;
            /* Se ajusta automáticamente: mínimo 140px por tarjeta, si cabe más se expande */
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            border: 1px solid #e0e0e0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        /* Diferenciar visualmente */
        .stat-card.main { background: #ffebee; border-color: #ffcdd2; } /* Rojo claro para principal */
        .stat-card.sub { background: #e3f2fd; border-color: #bbdefb; }  /* Azul claro para detalles */
        
        .stat-value { font-size: 1.5rem; font-weight: bold; display: block; color: #333; margin-bottom: 5px; }
        .stat-label { font-size: 0.8rem; color: #666; text-transform: uppercase; letter-spacing: 0.5px; }

        .btn-danger {
            background-color: #d32f2f;
            color: white;
            width: 100%;
            padding: 15px;
            border: none;
            border-radius: 4px;
            font-size: 1.1rem;
            font-weight: bold;
            cursor: pointer;
            text-transform: uppercase;
            box-shadow: 0 4px 6px rgba(211, 47, 47, 0.3);
            display: none; 
        }
        .btn-danger:hover { background-color: #b71c1c; }
        
        .loading { color: #1976D2; font-weight: bold; display: none; text-align: center; margin-top: 15px; }

        /* Ajustes Móviles */
        @media (max-width: 600px) {
            .form-grid { grid-template-columns: 1fr; }
            .clean-wrapper { margin: 10px; padding: 15px; }
            .stat-value { font-size: 1.2rem; }
        }

        /* Botón Cancelar (Gris/Azulado para que combine pero sea secundario) */
        .btn-cancel {
            display: inline-block;
            background-color: #607D8B; /* Gris azulado elegante */
            color: white;
            padding: 12px 30px;
            border-radius: 4px;
            text-decoration: none;
            font-weight: bold;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            transition: all 0.2s ease;
            font-size: 1rem;
        }
        .btn-cancel:hover {
            background-color: #455A64; /* Más oscuro al pasar el mouse */
            transform: translateY(-2px); /* Pequeño efecto de elevación */
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
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
    <span class="username"><?php echo $_SESSION['username']; ?></span>
    <a href="../../logout.php" class="logout-button">Cerrar sesión</a>
  </div>
</header>

<main>
    <h1 style="text-align:center; color:#d32f2f; margin-top:20px;">Limpieza de Base de Datos</h1>

    <div class="clean-wrapper">
        <div class="warning-banner">
            <div class="warning-icon">⚠️</div>
            <div>
                <strong>ZONA DE PELIGRO:</strong>
                Se eliminarán permanentemente los registros históricos que cumplan:
                <ul style="margin: 5px 0 0 20px; padding:0;">
                    <li>Estar Inactivos (Estado 2).</li>
                    <li>Haber sido despachados (Retiro) dentro del rango seleccionado.</li>
                </ul>
            </div>
        </div>

        <form id="formLimpieza">
            <div class="form-grid">
                <div>
                    <label>Fecha Inicio (Retiro):</label>
                    <input type="date" id="f_inicio" value="<?php echo $fechaInicioDefecto; ?>">
                </div>
                <div>
                    <label>Fecha Fin (Retiro):</label>
                    <input type="date" id="f_fin" value="<?php echo $fechaFinDefecto; ?>">
                </div>
            </div>
            
            <button type="button" class="btn-analyze" onclick="analizarDatos()">🔍 Analizar Impacto</button>
            <div id="loader" class="loading">Escaneando base de datos... ⏳</div>
        </form>

        <div id="resultado-zona">
            <h3 style="text-align:center; color:#333; margin-bottom:15px; font-size:1.1rem;">Registros encontrados para eliminar:</h3>
            
            <div class="results-grid">
                <!-- PRINCIPAL -->
                <div class="stat-card main">
                    <span class="stat-value" id="count-contenedores">0</span>
                    <span class="stat-label">Contenedores</span>
                </div>
                
                <!-- TABLAS DIRECTAS -->
                <div class="stat-card sub">
                    <span class="stat-value" id="count-logs">0</span>
                    <span class="stat-label">Logs Eventos</span>
                </div>
                <div class="stat-card sub">
                    <span class="stat-value" id="count-pyp">0</span>
                    <span class="stat-label">Auditorías PYP</span>
                </div>

                <!-- TABLAS HIJAS DE EVENTOS -->
                <div class="stat-card">
                    <span class="stat-value" id="count-traslados">0</span>
                    <span class="stat-label">Detalles Traslados</span>
                </div>
                <div class="stat-card">
                    <span class="stat-value" id="count-estatus">0</span>
                    <span class="stat-label">Cambios Estatus</span>
                </div>
                <div class="stat-card">
                    <span class="stat-value" id="count-ipsa">0</span>
                    <span class="stat-label">Cambios IPSA</span>
                </div>
                <div class="stat-card">
                    <span class="stat-value" id="count-mods">0</span>
                    <span class="stat-label">Modif. Datos</span>
                </div>
            </div>
            
            <p style="text-align:center; color:#666; font-size:0.9rem; margin-bottom:20px;">
                Total general de registros a purgar: <strong id="total-global" style="color:#d32f2f;">0</strong>
            </p>

            <button type="button" id="btn-ejecutar" class="btn-danger" onclick="confirmarLimpieza()">
                🗑️ CONFIRMAR Y ELIMINAR
            </button>
        </div>

       <div style="text-align:center; margin-top:25px;">
            <a href="../parametros.php" class="btn-cancel">
                ← Regresar al Menú
            </a>
        </div>
    </div>
</main>

<footer><p>&copy; IPSA Web - 2025</p></footer>

<script>
    function analizarDatos() {
        const ini = document.getElementById('f_inicio').value;
        const fin = document.getElementById('f_fin').value;
        const loader = document.getElementById('loader');
        const resultado = document.getElementById('resultado-zona');
        const btnBorrar = document.getElementById('btn-ejecutar');
        
        if (!ini || !fin) { alert("Seleccione el rango de fechas."); return; }

        loader.style.display = 'block';
        resultado.style.display = 'none';

        const formData = new FormData();
        formData.append('accion', 'analizar');
        formData.append('inicio', ini);
        formData.append('fin', fin);

        fetch('procesar_limpieza.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            loader.style.display = 'none';
            if (data.error) {
                alert("Error: " + data.error);
            } else {
                // Llenar las tarjetas con los datos detallados
                document.getElementById('count-contenedores').textContent = data.contenedores;
                document.getElementById('count-logs').textContent = data.logs_eventos;
                document.getElementById('count-traslados').textContent = data.logs_traslados;
                document.getElementById('count-pyp').textContent = data.pyp_auditoria;
                document.getElementById('count-estatus').textContent = data.logs_estatus;
                document.getElementById('count-ipsa').textContent = data.logs_ipsa;
                document.getElementById('count-mods').textContent = data.logs_modificaciones;
                
                // Suma total visual
                const total = parseInt(data.contenedores) + 
                              parseInt(data.logs_eventos) + 
                              parseInt(data.logs_traslados) + 
                              parseInt(data.pyp_auditoria) +
                              parseInt(data.logs_estatus) + 
                              parseInt(data.logs_ipsa) + 
                              parseInt(data.logs_modificaciones);
                              
                document.getElementById('total-global').textContent = total;

                resultado.style.display = 'block';
                
                // Solo habilitar botón si hay algo que borrar
                if (parseInt(data.contenedores) > 0) {
                    btnBorrar.style.display = 'block';
                } else {
                    btnBorrar.style.display = 'none';
                }
            }
        })
        .catch(e => {
            loader.style.display = 'none';
            alert("Error de conexión.");
            console.error(e);
        });
    }

    function confirmarLimpieza() {
        const totalCont = document.getElementById('count-contenedores').textContent;
        const totalReg = document.getElementById('total-global').textContent;
        const ini = document.getElementById('f_inicio').value;
        const fin = document.getElementById('f_fin').value;

        if (!confirm(`⚠️ ¡ÚLTIMA ADVERTENCIA! ⚠️\n\nSe van a eliminar ${totalCont} contenedores y un total de ${totalReg} registros asociados de la base de datos.\n\nEsta acción NO se puede deshacer.\n\n¿Estás seguro de continuar?`)) {
            return;
        }

        const formData = new FormData();
        formData.append('accion', 'eliminar');
        formData.append('inicio', ini);
        formData.append('fin', fin);

        fetch('procesar_limpieza.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert(`✅ Limpieza completada.\n\nSe eliminaron exitosamente ${data.eliminados} contenedores principales y todos sus registros dependientes.`);
                location.reload();
            } else {
                alert("❌ Error: " + (data.error || "Fallo desconocido"));
            }
        })
        .catch(e => {
            alert("Error crítico al eliminar.");
            console.error(e);
        });
    }
</script>
</body>
</html>
