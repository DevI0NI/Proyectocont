# Guia Base De Diseno UI

Este documento fija el lenguaje visual que ya existe en el proyecto y que debe reutilizarse en futuras vistas.

La referencia oficial para nuevas pantallas es:

- `index.php` + `styles/index.css`
- `ingresos/ingresos.php` + `styles/ingresos.css/ingresos.css`
- `bloques/bloques.php` + `styles/parametros/bloques/bloques.css`
- `traslados/traslados.php` + `styles/traslados/traslados.css`

Si una nueva vista necesita un componente parecido a uno de esos modulos, se debe extender ese patron, no inventar uno nuevo.

## Regla General

- No usar estilos visuales completamente nuevos si ya existe una solucion en `dashboard`, `ingresos`, `bloques` o `traslados`.
- No usar selects HTML nativos visibles cuando el resto del modulo use dropdown personalizado.
- No usar botones genericos de navegador.
- No usar bloques planos sin jerarquia visual si la vista corresponde al sistema principal.
- Toda nueva vista debe verse coherente con `index.php`.

## Base Visual Del Sistema

### Paleta

- Fondo general: degradado suave claro entre `--bg-top` y `--bg`.
- Superficie principal: `--surface`.
- Superficie secundaria: `--surface-2`.
- Texto principal: `--text`.
- Texto secundario: `--muted`.
- Lineas y bordes: `--line`.
- Color acento: `--primary` y `--primary-dark`.
- Accion destructiva o salida: `--red` y `--red-dark`.

La fuente visual actual base es `Segoe UI, Tahoma, Geneva, Verdana, sans-serif`. Mientras no se rehaga todo el sistema, eso se conserva.

### Modo Oscuro

El modo oscuro ya esta definido por variables CSS en `styles/index.css` con `:root[data-theme="dark"]`.

Reglas:

- Toda nueva vista debe apoyarse en variables, no en colores hardcodeados.
- Si se crea un componente nuevo, debe tener su variante para `data-theme="dark"`.
- El fondo oscuro no es negro puro; usa superficies azuladas/verdosas oscuras.
- Los contrastes deben seguir el estilo actual: suaves, legibles y sin brillos agresivos.
- Los dropdowns, tarjetas, inputs y paneles deben verse integrados al dark mode, no solo invertir texto.

## Layout Base

### Estructura

- Usar `app-shell`.
- Mantener `sidebar` izquierda.
- Mantener `page-wrap`.
- Mantener `topbar` para acciones globales.
- El contenido principal debe ir como paneles o tarjetas dentro del main de la vista.

### Sidebar y Topbar

- Deben reutilizar el mismo estilo del dashboard.
- Sidebar con degradado verde profundo.
- Topbar translucidada, ligera, con blur y borde inferior suave.
- Menus de usuario y sucursal deben seguir el patron flotante actual.
- En movil, sidebar off-canvas y boton flotante hamburguesa como en `index.css`.

## Tarjetas Y Paneles

- Usar superficies blancas o semioscuras con borde suave.
- Bordes redondeados amplios: entre `16px` y `22px`.
- Sombras suaves, nunca duras.
- Los paneles principales deben sentirse elevados, limpios y respirados.
- Los bloques de contenido extensos deben ir dentro de cards, no directamente sobre el fondo general.

Patrones validos:

- `stat-card`
- `panel-card`
- `traslado-panel`
- `side-card`
- `bloques-module`

## Botones

### Boton Principal De Guardar O Confirmar

El estilo oficial de accion principal es el boton ambar/naranja ya usado en `ingresos`, `bloques` y `traslados`.

Reglas:

- Fondo principal tipo ambar: familia `#f7ab37`, `#f7b54a`, `#ee9d21`, `#eea73a`.
- Texto oscuro, no blanco.
- Bordes redondeados generosos: `16px` a `18px`.
- Peso visual alto: `font-weight: 700` o `800`.
- Debe tener sombra calida.
- En hover debe levantar ligeramente con `translateY(-1px)` o `translateY(-2px)`.
- No usar el azul clasico de navegador como accion principal.

Patrones de referencia:

- `.guardar-button`
- `.toolbar-button-primary`

### Boton Secundario

- Puede usar acento verde suave translúcido.
- Debe sentirse complementario al principal, nunca competir con el boton guardar.
- Referencia: `.toolbar-button-secondary`.

### Botones De Accion En Tabla

- Deben ser tipo pill.
- Altura compacta.
- Color segun contexto.
- Hover suave y pequeño lift.

Patrones:

- `.table-action`
- `.action-info`
- `.action-move`

## Inputs

- Alturas alrededor de `46px` a `48px`.
- Bordes redondeados `14px` a `16px`.
- Fondo `var(--surface)`.
- Borde suave con `var(--line)` o una variante del acento.
- Sombra ligera.
- Focus con halo del acento verde, no azul del navegador.

No se debe:

- Dejar el focus default del navegador.
- Mezclar inputs cuadrados con tarjetas muy redondeadas.
- Usar inputs mucho mas delgados que los ya existentes.

## Labels

Hay dos estilos validos segun contexto:

- Label tipo badge/tarjeta como en `ingresos`.
- Label simple tipografico como en `traslados`.

Regla:

- Si la vista es formulario principal tipo captura, priorizar el estilo de `ingresos`.
- Si la vista es mas operativa/filtro/listado, puede usar el estilo de `traslados`.
- Dentro de una misma vista no mezclar demasiados tipos de labels.

## Selects Y Menus Desplegables

Esto queda fijo: cuando el sistema use dropdowns visibles al usuario, deben ser personalizados, no el select nativo por defecto.

### Reglas Del Dropdown Personalizado

- El `select` real puede quedar oculto para compatibilidad.
- El usuario debe ver `custom-select`.
- Trigger redondeado, con sombra y caret personalizado.
- Menu flotante con borde, sombra, animacion y fondo consistente con el tema.
- Opciones con hover suave y opcion activa destacada.
- Texto truncado con `ellipsis` si el valor es largo.

Patrones oficiales:

- `.custom-select`
- `.custom-select-trigger`
- `.custom-select-menu`
- `.custom-select-option`

### Scroll En Dropdowns Largos

Esto tambien queda fijo:

- Todo dropdown con muchas opciones debe tener `max-height`.
- Debe activar `overflow-y: auto`.
- No debe crecer infinitamente y romper la pantalla.
- Debe mantener padding interno y esquinas redondeadas aunque tenga scroll.

Referencias reales:

- `ingresos`: `max-height: 240px`
- `ingresos` ubicacion: `max-height: 190px`
- `traslados`: `max-height: 250px`
- topbar/sidebar menus: colapsables o flotantes con altura controlada

## Scroll En Contenedores Largos

Cuando una lista o panel pueda crecer mucho:

- Debe tener altura maxima razonable.
- Debe usar scroll interno.
- El layout general no debe desarmarse.

Patrones:

- `.container-list`
- `.bloques-table-wrap`
- `.table-wrap`
- Menus dropdown con `overflow-y:auto`

## Tablas

Las tablas del sistema no deben ser planas ni de navegador.

Reglas:

- Encabezados con tratamiento visual claro.
- En `bloques`, el header degradado verde es referencia fuerte.
- Celdas con padding comodo.
- Hover por fila suave.
- Estados visuales en badges, no texto crudo cuando aplique.
- En movil, la tabla debe permitir scroll horizontal.
- Usar `min-width` cuando haga falta.

Patrones:

- `.bloques-table`
- `.bloques-table-wrap`
- `.table-wrap`

## Badges Y Estados

Los estados deben verse como badges o pills.

Estilos esperados:

- Exito: verde suave.
- Advertencia: ambar suave.
- Peligro o bloqueo: rojo suave.
- Neutral o informativo: acento verdoso suave.

Patrones:

- `.status-badge`
- `.badge-success`
- `.badge-warning`
- `.badge-danger`
- `.badge-neutral`
- `.transfer-badge`
- `.data-badge`

## Busquedas Y Filtros

- Los buscadores deben verse integrados a la toolbar.
- Bordes redondeados amplios.
- Sombra ligera.
- El area de filtros debe verse ordenada, no como formulario legacy.
- Si los filtros son varios, usar grid o flex consistente como en `bloques` y `traslados`.

## Estados Vacios

No dejar texto suelto tipo "no hay datos" sin contenedor.

Usar empty states con:

- icono o forma visual
- titulo corto
- texto explicativo
- contenedor con borde o fondo suave

Patrones:

- `.table-empty-state`
- `.empty-state`
- `.selected-empty`

## Responsive

Toda nueva vista debe considerar movil desde el inicio.

Reglas minimas:

- Toolbar apilable en tablets y moviles.
- Tablas con scroll horizontal si no caben.
- Formularios multicolumna deben bajar a una columna.
- Botones principales deben poder ocupar ancho completo en movil.
- Sidebar movil debe respetar el comportamiento actual del dashboard.

## Lo Que No Se Debe Hacer

- No meter grandes bloques de `style=""` inline para resolver diseño nuevo.
- No crear otro estilo de boton guardar distinto al actual.
- No usar dropdowns nativos visibles en una vista moderna del sistema.
- No dejar paneles sin dark mode si la vista ya usa el shell principal.
- No mezclar muchas familias de sombras, radios y paddings sin motivo.
- No volver a interfaces tipo formulario plano legacy si la vista pertenece al flujo principal.

## Orden De Prioridad Al Disenar Nuevas Vistas

1. Reutilizar `styles/index.css` para shell, topbar, sidebar, colores y modo oscuro.
2. Reutilizar patrones de `ingresos` para formularios y boton principal.
3. Reutilizar patrones de `bloques` para tablas, toolbars y selects operativos.
4. Reutilizar patrones de `traslados` para listas, side cards y dropdowns largos.
5. Solo crear una variante nueva si ninguna de las anteriores resuelve el caso.

## Decision Final De Diseno

Desde este punto, la base visual oficial del proyecto es:

- Dashboard para estructura global.
- Ingresos para formularios principales.
- Bloques para toolbars y tablas.
- Traslados para listas operativas y seleccion contextual.

Cualquier vista nueva o rediseño futuro debe parecer parte de esa misma familia visual.
