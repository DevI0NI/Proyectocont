@extends('layouts.app')
@section('content')
    @php
        $liveTable = true;
    @endphp
    <section class="page-heading standard-records-heading">
        <div><span class="eyebrow">Patios y bloques</span><h1>Bloques de {{ $selectedPatio->nombre }}</h1></div>
        <a class="button secondary" href="{{ route('yard-layout.index') }}"><span data-admin-icon="chevron-left" aria-hidden="true"></span>Volver a patios</a>
    </section>
    @if($errors->any())<script type="application/json" data-yard-errors>@json($errors->all())</script>@endif
    <section class="workboard standard-records-table yard-workspace" data-yard-layout data-current-patio="{{ $selectedPatio->id }}">
        <dl class="yard-summary" aria-label="Resumen de {{ $selectedPatio->nombre }}">
            <div><dt>Bloques</dt><dd>{{ $summary->blocks }}</dd></div>
            <div><dt>Capacidad</dt><dd>{{ $summary->capacity }}</dd></div>
            <div><dt>Ocupados</dt><dd>{{ $summary->occupied }}</dd></div>
            <div><dt>Disponibles</dt><dd>{{ max(0, $summary->capacity - $summary->occupied) }}</dd></div>
        </dl>
        @include('partials.administration-toolbar', ['paginator' => $blocks, 'createUrl' => null, 'createAction' => route('yard-layout.blocks.store'), 'createKind' => 'block', 'createLabel' => 'Nuevo bloque'])
        <div class="standard-records-panel"><div class="table-scroll"><table class="data-table">
            <thead><tr>@include('partials.administration-table-head', ['actionsFirst' => true])</tr></thead>
            <tbody>
                @forelse($blocks as $block)
                <tr>
                    <td>@if(auth()->user()->hasPermission('yard-layout.manage'))<div class="row-options" data-record-options>
                        <button type="button" class="options-trigger" aria-label="Opciones del Bloque {{ $block->numero }}" aria-expanded="false" aria-haspopup="true">Opciones<span class="dropdown-caret" data-admin-icon="chevron-down" aria-hidden="true"></span></button>
                        <div class="options-menu record-options-menu" hidden>
                            <button type="button" data-yard-edit="block" data-name="Bloque {{ $block->numero }}" data-capacity="{{ $block->capacidad_maxima }}" data-occupied="{{ $block->contenedores_activos_count }}" data-action="{{ route('yard-layout.blocks.update', ['block' => $block->id]) }}">Editar capacidad</button>
                            <button type="button" data-yard-deactivate="block" data-name="Bloque {{ $block->numero }}" data-action="{{ route('yard-layout.blocks.destroy', ['block' => $block->id]) }}">Desactivar bloque</button>
                        </div>
                    </div>@else<span class="table-muted">Sin acciones</span>@endif</td>
                    <td><strong>Bloque {{ $block->numero }}</strong></td>
                    <td>{{ $block->capacidad_maxima }} espacios</td>
                    <td><div class="yard-occupancy"><span>{{ $block->contenedores_activos_count }} / {{ $block->capacidad_maxima }}</span><progress max="{{ max(1, $block->capacidad_maxima) }}" value="{{ $block->contenedores_activos_count }}" aria-label="Ocupación del Bloque {{ $block->numero }}"></progress></div></td>
                </tr>
                @empty<tr><td colspan="4" class="empty-cell">{{ !$selectedPatio ? 'No hay patios activos en esta sucursal.' : ($blocks->total() === 0 && request('q') ? 'No hay bloques para estos filtros.' : 'No hay bloques para mostrar en este patio.') }}</td></tr>@endforelse
            </tbody>
        </table></div><div class="pagination-wrap">@include('partials.administration-pagination', ['paginator' => $blocks])</div></div>

    </section>
    @include('partials.yard-layout-dialogs')
@endsection
