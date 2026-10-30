# Plan de migracion a Laravel

Este proyecto Laravel vive en paralelo al sistema PHP actual. La regla de trabajo es migrar por modulos, mantener la base actual al inicio y validar cada hito antes de apagar la pantalla equivalente en PHP procedural.

## Hito 1: base Laravel

- Laravel instalado en `laravel/`.
- Conexion inicial a la base actual `proyectocont_v2.1`.
- Sesiones en archivos para no requerir tablas nuevas al principio.
- Login contra la tabla existente `users`.
- Middleware de roles.
- Sucursal activa guardada en sesion.
- Layout base con navegacion por modulos.
- Dashboard inicial.
- Dashboard revisado contra el original en visibilidad por rol y calculo de capacidad por patio.
- Primer CRUD de usuarios y roles migrado.
- CRUD de sucursales migrado con desactivacion por `estado_id`.
- CRUD de tipos de unidad y modelos migrado con desactivacion logica.
- CRUD de estatus de contenedores migrado para categorias Unidad y Estructura.
- Configuracion de patios y bloques migrada por sucursal activa.
- Primer flujo de ingresos migrado con reingreso, posicion disponible y logs.
- Logica de ingreso extraida a `IngresoService`.
- Vista de contenedores por patio/bloque migrada para roles internos, mecanico y PYP.
- Asignacion y actualizacion de auditoria PYP migradas con transacciones y logs.
- Logica de PYP extraida a `PypAuditService`.
- Detalle de contenedor migrado en modo lectura para roles internos.
- Modificacion de contenedor migrada para roles internos con logs de datos y estatus.
- Logica de modificacion de contenedores extraida a `ContainerUpdateService`.
- Primer flujo de traslados migrado con validacion de apilamiento, destino, posicion y logs.
- Logica de traslados extraida a `TransferService`.
- Primer flujo de despachos migrado con validaciones, salida logica y archivado de auditoria/logs.
- Logica de despacho extraida a `DispatchService`.
- Primera pantalla de reportes migrada con vista previa de inventario, movimientos y despachos.
- Vistas previas de auditorias PYP, traslados, cambios e historial de contenedor agregadas a reportes.
- Exports XLSX y PDF iniciales agregados a reportes usando el vendor legacy como puente temporal.
- Reportes revisados para paridad basica de Excel/PDF con el original: tipo, formato, preview y filtros principales.
- Logica de consultas/export helpers de reportes extraida a `ReportService`.
- Hub de parametros migrado enlazando los catalogos ya migrados.
- Vistas EIR/ticket de ingreso y salida migradas como documentos visuales imprimibles.
- Pruebas de rutas y permisos principales agregadas.
- Rentas y mantenimiento quedan fuera del alcance funcional actual; se retomaran al finalizar la migracion principal.
- Redirecciones de compatibilidad agregadas para pantallas PHP legacy principales.
- Endpoints auxiliares legacy principales de ingresos, traslados y despachos agregados con rutas `.php`.
- Endpoints auxiliares legacy de verificacion para parametros y modificacion de contenedores agregados.
- Endpoints legacy de autenticacion, cambio de sucursal y generadores de reportes `.php` agregados como compatibilidad.
- CSRF excluido solo para endpoints legacy `.php` de compatibilidad que eran llamados por formularios o JS sin token.
- Campos/acciones visibles por rol revisados contra el original en navegacion principal, inventario/PYP y asignacion de auditoria.
- Herramienta legacy de limpieza descartada: no se implementara borrado fisico de historico.

## Orden de migracion recomendado

1. Autenticacion, logout y cambio de sucursal.
2. Dashboard.
3. Usuarios y roles. Listado, creacion, edicion, desactivacion y reactivacion iniciales migrados.
4. Sucursales. Listado, creacion, edicion, reactivacion por nombre y desactivacion iniciales migrados.
5. Tipos de unidad y modelos. Listado, creacion, edicion, sincronizacion de modelos y desactivacion iniciales migrados.
6. Estados y estatus. Listado por categoria, creacion, edicion, reactivacion y desactivacion iniciales migrados.
7. Patios y bloques. Creacion, edicion, reactivacion/desactivacion de patios y gestion de bloques iniciales migradas.
8. Ingresos. Formulario, validaciones, endpoints de apoyo, creacion/reingreso y logs iniciales migrados.
9. Bloques / contenedores. Vista de inventario por patio/bloque, detalle, modificacion interna, asignacion y auditoria PYP migradas. Movimientos quedan para la siguiente pasada.
10. Traslados. Formulario, endpoints de apoyo, validaciones, actualizacion de ubicacion/capacidad y logs iniciales migrados.
11. Despachos. Busqueda, validaciones de IPSA/PYP/apilamiento, salida por estado inactivo, ajuste de capacidad y archivado inicial migrados.
12. Reportes. Vista previa migrada para inventario, movimientos, despachos, auditorias PYP, traslados, cambios e historial. Exports XLSX/PDF iniciales agregados; queda refinar formato por reporte.
13. Rentas. Pendiente de diseno funcional; no se considera parte de la migracion principal actual.
14. Mantenimiento. Pendiente de diseno funcional; no se considera parte de la migracion principal actual.

## Mapeo inicial de carpetas

| PHP actual | Laravel destino |
| --- | --- |
| `login.php`, `authenticate.php`, `logout.php` | `AuthController` |
| `cambiar_sucursal.php` | `BranchController` |
| `index.php` | `DashboardController` |
| `parametros/usuarios` | `UserController` |
| `parametros/sucursales` | `SucursalController` |
| `parametros/tiposunidad` | `TipoUnidadController`, `ModeloController` |
| `parametros/estados` | `EstatusController` |
| `parametros/bloques` | `PatioController`, `BloqueController` |
| `ingresos` | `IngresoController`, `IngresoService` |
| `bloques` | `ContenedorController`, `InventarioService` |
| `traslados` | `TrasladoController`, `TrasladoService` |
| `despachos` | `DespachoController`, `DespachoService` |
| `reportes` | `ReporteController`, `Exports/*` |

## Criterios por modulo

- Toda accion que modifique datos debe usar `POST`, `PUT`, `PATCH` o `DELETE`.
- Toda accion protegida debe pasar por `auth` y, cuando aplique, `role`.
- Toda validacion de formularios debe vivir en `FormRequest` o en el controlador durante la primera pasada.
- La logica de negocio repetida debe pasar a `app/Services`.
- No redisenar tablas durante la primera migracion salvo que sea inevitable.
- No correr migraciones contra la base actual hasta tener respaldo y aprobacion.

## Pendientes tecnicos

- Confirmar nombres definitivos de roles.
- Convertir el dashboard actual completo.
- Crear seeders opcionales solo para entorno local.
- Agregar pruebas de CRUDs criticos y flujos de escritura con base de datos de prueba.
- Probar flujos completos con datos reales fuera de tests automatizados: ingreso, traslado, PYP, despacho y reporte.
- Continuar moviendo consultas de inventario/documentos a `app/Services` si se quiere dejar los controladores aun mas delgados.
- Afinar columnas seleccionables y estilos avanzados de reportes si se decide igualar el export legacy al detalle visual completo.
- Revisar `POST_MIGRATION_CHANGES.md` para mejoras futuras que no bloquean la migracion principal.
