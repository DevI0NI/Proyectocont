<section class="panel-card reports-module" data-reports>
    <header class="reports-head">
        <div><h1>Generador de reportes</h1><p>{{ $sucursal->nombre }}</p></div>
        <span class="reports-branch"><span data-report-icon="branch" aria-hidden="true"></span>{{ now('America/Guatemala')->format('d/m/Y') }}</span>
    </header>
    <div class="reports-builder">
        <form id="reportBuilder" method="GET" action="{{ route('reports.index') }}" class="record-select-fields reports-options" data-report-form>
            @if($errors->any())<p class="reports-error" role="alert">{{ $errors->first() }}</p>@endif
            <div class="reports-option-group">
                <label><span class="reports-option-heading"><span>01</span>Tipo de reporte</span>
                    <select name="tipo" data-admin-select data-report-type>
                        @foreach(['inventario' => 'Inventario actual', 'ingresos' => 'Ingresos por ciclo', 'despachos' => 'Despachos con EIR de salida', 'movimientos' => 'Bitacora de movimientos', 'auditorias' => 'Auditorias externas', 'reparaciones' => 'Reparaciones y correcciones', 'costos' => 'Costos de mantenimiento', 'traslados' => 'Traslados', 'cambios' => 'Detalle de cambios', 'historial' => 'Historial de contenedor'] as $value => $label)
                            <option value="{{ $value }}" @selected($type === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            @if($type !== 'inventario' && $type !== 'historial')
                <div class="reports-option-group">
                    <h2 class="reports-option-heading"><span>02</span>Periodo</h2>
                    <div class="reports-date-grid">
                        <label><span>Desde</span><input type="date" name="f_inicio" value="{{ $start }}" required></label>
                        <label><span>Hasta</span><input type="date" name="f_fin" value="{{ $end }}" required></label>
                    </div>
                </div>
            @endif
            <div class="reports-option-group">
                <h2 class="reports-option-heading"><span>{{ $type === 'inventario' || $type === 'historial' ? '02' : '03' }}</span>Alcance</h2>
                @if($operational)
                    <label><span>Contenedor</span><input type="search" name="search" value="{{ $filters['search'] }}" maxlength="100" placeholder="Numero de equipo" autocomplete="off"></label>
                @elseif($type === 'historial')
                    <label><span>Contenedor</span><select name="contenedor_id" data-admin-select data-admin-search required><option value="">Seleccionar</option>
                        @foreach($containers as $container)<option value="{{ $container->id }}" @selected((int)$containerId === (int)$container->id)>{{ $container->nombre }}</option>@endforeach
                    </select></label>
                @endif
                @if(!in_array($type, ['ingresos', 'despachos', 'historial'], true))
                    <div class="reports-locations" data-report-locations>
                        @foreach($selectedLocations ?: [['patio_id' => null, 'bloque_ids' => []]] as $locationIndex => $location)
                            @include('reports.location-row')
                        @endforeach
                    </div>
                    <button type="button" class="mw-button reports-location-add" data-report-add-location><span data-report-icon="add" aria-hidden="true"></span>Añadir patio</button>
                    <template data-report-location-template>@include('reports.location-row', ['locationIndex' => '__INDEX__', 'location' => ['patio_id' => null, 'bloque_ids' => []]])</template>
                    <script type="application/json" data-report-location-data>@json($patios->map(fn ($yard) => ['id' => $yard->id, 'blocks' => $yard->bloquesActivos->map(fn ($block) => ['id' => $block->id, 'number' => $block->numero])->values()])->values())</script>
                @endif
            </div>
            <fieldset class="reports-option-group reports-columns" data-report-columns>
                <legend class="reports-option-heading"><span>{{ $type === 'inventario' || $type === 'historial' ? '03' : '04' }}</span>Columnas</legend>
                <div class="reports-column-tools"><span data-report-column-count>{{ count($selectedColumns) }} / {{ count($columnOptions) }}</span><div>
                    <button type="button" class="reports-icon-button" data-report-columns-all title="Seleccionar todas" aria-label="Seleccionar todas las columnas"><span data-report-icon="all" aria-hidden="true"></span></button>
                    <button type="button" class="reports-icon-button" data-report-columns-none title="Limpiar seleccion" aria-label="Limpiar seleccion de columnas"><span data-report-icon="none" aria-hidden="true"></span></button>
                </div></div>
                <div class="reports-column-options"><input type="hidden" name="columns_selected" value="1">
                    @foreach($columnOptions as $index => $label)<label><input type="checkbox" name="columns[]" value="{{ $index }}" @checked(in_array($index, $selectedColumns, true))><span>{{ $label }}</span></label>@endforeach
                </div>
            </fieldset>
            <div class="reports-actions">
                @if(auth()->user()->hasPermission('reports.export'))
                    <div class="reports-export-grid">
                        <button type="submit" class="mw-button reports-excel-button" data-report-export formaction="{{ route('reports.export', ['format' => 'xlsx']) }}" formtarget="_blank"><span data-report-icon="excel" aria-hidden="true"></span>Excel</button>
                        <button type="submit" class="mw-button reports-pdf-button" data-report-export formaction="{{ route('reports.export', ['format' => 'pdf']) }}" formtarget="_blank"><span data-report-icon="pdf" aria-hidden="true"></span>PDF</button>
                    </div>
                @endif
                <a class="reports-clear" href="{{ route('reports.index', ['tipo' => $type]) }}">Limpiar filtros</a>
            </div>
        </form>
        <section class="reports-preview" aria-labelledby="reportPreviewHeading">
            <header class="reports-preview-head"><div><span class="reports-eyebrow">Vista previa</span><h2 id="reportPreviewHeading">{{ $title }}</h2></div>
                <div class="reports-preview-controls">
                <label class="record-select-fields reports-row-count"><span>Filas</span>
                    @if($paginator)<select name="per_page" form="reportBuilder" data-admin-select>@foreach([10, 25, 50] as $size)<option value="{{ $size }}" @selected((int)$filters['per_page'] === $size)>{{ $size }}</option>@endforeach</select>
                    @else<span>100</span>@endif
                </label>
                <button type="submit" form="reportBuilder" class="mw-button reports-preview-button" data-report-preview><span data-report-icon="search" aria-hidden="true"></span>Vista previa</button>
                </div>
            </header>
            <div class="reports-preview-meta"><span>{{ number_format($paginator?->total() ?? $rows->count()) }} registros</span><span>{{ $type === 'inventario' ? 'Inventario actual' : ($type === 'historial' ? 'Historial del contenedor' : $start.' / '.$end) }}</span><span data-report-dirty hidden>Filtros modificados</span></div>
            <div class="reports-table-wrap" tabindex="0" aria-label="Resultados del reporte">
                <table class="report-table"><thead><tr>@foreach($headers as $header)<th scope="col">{{ $header }}</th>@endforeach</tr></thead>
                    <tbody>@forelse($rows as $row)<tr>@foreach($row as $index => $value)@php($displayValue = is_numeric($value) && str_contains($headers[$index], '(GTQ)') ? 'Q '.number_format((float)$value, 2) : $value)<td><span class="reports-cell" title="{{ $displayValue }}" tabindex="0">{{ $displayValue }}</span></td>@endforeach</tr>
                    @empty<tr><td colspan="{{ count($headers) }}" class="reports-empty"><span data-report-icon="empty" aria-hidden="true"></span><p>No se encontraron registros con estos filtros.</p></td></tr>@endforelse</tbody>
                </table>
            </div>
            @if($paginator)
                <footer class="reports-pagination"><span>Pagina {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }}</span><nav aria-label="Paginas del reporte">
                    @foreach(['first' => [1, 'Primera pagina'], 'previous' => [$paginator->currentPage() - 1, 'Pagina anterior'], 'next' => [$paginator->currentPage() + 1, 'Pagina siguiente'], 'last' => [$paginator->lastPage(), 'Ultima pagina']] as $icon => [$page, $label])
                        @if(in_array($icon, ['first', 'previous'], true) ? $paginator->onFirstPage() : !$paginator->hasMorePages())
                            <button class="mw-button" type="button" disabled aria-label="{{ $label }}"><span data-report-icon="{{ $icon }}" aria-hidden="true"></span></button>
                        @else<a class="mw-button" href="{{ $paginator->url($page) }}" aria-label="{{ $label }}" title="{{ $label }}"><span data-report-icon="{{ $icon }}" aria-hidden="true"></span></a>@endif
                    @endforeach
                </nav></footer>
            @endif
        </section>
    </div>
</section>
