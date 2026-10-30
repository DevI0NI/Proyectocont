# Cambios futuros despues de la migracion

Este documento separa los pendientes de mejora/redisenio de la migracion principal a Laravel.
La meta inmediata sigue siendo igualar la funcionalidad operativa del sistema original; estos puntos se retoman cuando la migracion principal este estable y probada.

## Funcionalidad pendiente de diseno

### Rentas

- El modulo no se considera parte funcional del sistema original listo para migrar.
- Queda pendiente definir reglas reales de negocio, pantallas, estados, reportes y permisos.
- No debe bloquear la migracion de ingresos, inventario, traslados, PYP, despachos y reportes.

### Mantenimiento

- El modulo queda como backlog porque no esta completamente pensado ni terminado en el sistema original.
- Antes de implementarlo se debe definir que datos controla, como se relaciona con contenedores y que roles pueden operarlo.
- No debe exponerse como funcional hasta tener reglas claras.

## Limpieza de base de datos

- No se implementara borrado fisico de registros historicos.
- Las acciones de baja deben usar estado inactivo, normalmente `estado_id = 2` o la columna equivalente.
- La herramienta de limpieza queda descartada porque el proyecto debe conservar trazabilidad.

## Reingreso de contenedores

- Cuando un contenedor despachado vuelva a ingresar, se debe reutilizar el registro principal del contenedor y reactivarlo.
- La informacion operacional vigente debe actualizarse con el nuevo ingreso: ubicacion, posicion, estatus y datos actuales.
- La trazabilidad anterior debe conservarse en logs, reportes e historial.
- La auditoria PYP actual usa `contenedor_id` como llave primaria, por lo que solo permite un registro PYP por contenedor. Como solucion temporal, la fila PYP inactiva puede reactivarse/resetearse en un nuevo ciclo.
- Como mejora futura, conviene redisenar el historial por ciclo del contenedor, por ejemplo con un identificador de ciclo o una tabla historica que permita guardar multiples auditorias PYP por el mismo contenedor.

## Exports y reporteria

- Revisar formato final de exports Excel/PDF cuando los flujos principales ya esten cerrados.
- Mejorar diseno, orden de columnas, titulos y presentacion impresa sin bloquear la migracion funcional.

