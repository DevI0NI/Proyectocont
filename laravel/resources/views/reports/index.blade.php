@extends('layouts.app')

@section('content')
    <section class="page-heading">
        <div>
            <span class="eyebrow">Reporteria</span>
            <h1>Reportes</h1>
            <p>Consulta previa de inventario, movimientos y despachos en la sucursal activa.</p>
        </div>
        <div class="heading-metric">
            <span>Resultados</span>
            <strong>{{ $rows->count() }}</strong>
        </div>
    </section>

    <section class="form-panel">
        <form method="GET" action="{{ route('reports.index') }}" class="record-form report-filter-form">
            <label>
                <span>Tipo de reporte</span>
                <select name="tipo">
                    <option value="inventario" @selected($type === 'inventario')>Contenedores activos</option>
                    <option value="movimientos" @selected($type === 'movimientos')>Bitacora de movimientos</option>
                    <option value="despachos" @selected($type === 'despachos')>Contenedores despachados</option>
                    <option value="auditorias" @selected($type === 'auditorias')>Auditorias PYP</option>
                    <option value="traslados" @selected($type === 'traslados')>Traslados</option>
                    <option value="cambios" @selected($type === 'cambios')>Detalle de cambios</option>
                    <option value="historial" @selected($type === 'historial')>Historial de contenedor</option>
                </select>
            </label>

            <label>
                <span>Desde</span>
                <input type="date" name="f_inicio" value="{{ $start }}">
            </label>

            <label>
                <span>Hasta</span>
                <input type="date" name="f_fin" value="{{ $end }}">
            </label>

            <label>
                <span>Patio</span>
                <select name="patio_id" id="patio_id">
                    <option value="">Todos</option>
                    @foreach ($patios as $patio)
                        <option value="{{ $patio->id }}" @selected((int) $patioId === (int) $patio->id)>{{ $patio->nombre }}</option>
                    @endforeach
                </select>
            </label>

            <label>
                <span>Bloque</span>
                <select name="bloque_id" id="bloque_id">
                    <option value="">Todos</option>
                    @foreach ($patios as $patio)
                        @foreach ($patio->bloquesActivos as $block)
                            <option
                                value="{{ $block->id }}"
                                data-patio="{{ $patio->id }}"
                                @selected((int) $blockId === (int) $block->id)
                            >
                                {{ $patio->nombre }} / Bloque {{ $block->numero }}
                            </option>
                        @endforeach
                    @endforeach
                </select>
            </label>

            <label id="containerFilter">
                <span>Contenedor</span>
                <select name="contenedor_id">
                    <option value="">Selecciona un contenedor</option>
                    @foreach ($containers as $container)
                        <option value="{{ $container->id }}" @selected((int) $containerId === (int) $container->id)>
                            {{ $container->nombre }} @if ((int) $container->estado_id !== 1) (inactivo) @endif
                        </option>
                    @endforeach
                </select>
            </label>

            <div class="form-actions-row">
                <button type="submit" class="button primary">Consultar</button>
            </div>
        </form>
    </section>

    <section class="workboard inventory-overview">
        <div class="table-toolbar">
            <div>
                <span class="eyebrow">Vista previa</span>
                <h2 class="section-title">{{ ucfirst($type) }}</h2>
            </div>
            <div class="toolbar-actions">
                @if(auth()->user()->hasPermission('reports.export') || in_array((int)auth()->user()->rol_id, [4, 5], true))
                <a href="{{ route('reports.export', ['format' => 'xlsx'] + request()->query()) }}" class="button secondary">Excel</a>
                <a href="{{ route('reports.export', ['format' => 'pdf'] + request()->query()) }}" class="button secondary">PDF</a>
                @endif
                <span class="table-muted">Maximo 100 registros por consulta</span>
            </div>
        </div>

        <div class="table-scroll">
            <table class="data-table report-table">
                <thead>
                    <tr>
                        @foreach ($headers as $header)
                            <th>{{ $header }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            @foreach ($row as $value)
                                <td>{{ $value }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td class="empty-cell" colspan="{{ count($headers) }}">No se encontraron registros con estos filtros.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <script>
        const patioSelect = document.getElementById('patio_id');
        const blockSelect = document.getElementById('bloque_id');
        const typeSelect = document.querySelector('select[name="tipo"]');
        const containerFilter = document.getElementById('containerFilter');
        const blockOptions = Array.from(blockSelect.options);

        function filterBlocks() {
            const patioId = patioSelect.value;
            blockOptions.forEach(function (option) {
                if (!option.value) {
                    option.hidden = false;
                    return;
                }
                option.hidden = patioId && option.dataset.patio !== patioId;
                if (option.hidden && option.selected) {
                    blockSelect.value = '';
                }
            });
        }

        function toggleContainerFilter() {
            containerFilter.hidden = typeSelect.value !== 'historial';
        }

        patioSelect.addEventListener('change', filterBlocks);
        typeSelect.addEventListener('change', toggleContainerFilter);
        filterBlocks();
        toggleContainerFilter();
    </script>
@endsection
