@extends('layouts.app')
@section('content')
    @php
        $liveTable = true;
    @endphp
    <section class="page-heading standard-records-heading"><div><span class="eyebrow">Parametros</span><h1>Patios</h1></div></section>
    @if($errors->any())<script type="application/json" data-yard-errors>@json($errors->all())</script>@endif
    <section class="workboard standard-records-table yard-workspace" data-yard-layout data-current-patio="">
        @include('partials.administration-toolbar', ['paginator' => $patios, 'createUrl' => null, 'createAction' => route('yard-layout.patios.store'), 'createKind' => 'patio', 'createLabel' => 'Nuevo patio'])
        <div class="standard-records-panel"><div class="table-scroll"><table class="data-table">
            <thead><tr>@include('partials.administration-table-head', ['actionsFirst' => true])</tr></thead>
            <tbody>
                @forelse($patios as $patio)
                <tr>
                    <td><div class="row-options" data-record-options>
                        <button type="button" class="options-trigger" aria-label="Opciones de {{ $patio->nombre }}" aria-expanded="false" aria-haspopup="true">Opciones<span class="dropdown-caret" data-admin-icon="chevron-down" aria-hidden="true"></span></button>
                        <div class="options-menu record-options-menu" hidden>
                            <a href="{{ route('yard-layout.blocks', ['patio' => $patio->id]) }}">Bloques</a>
                            @if(auth()->user()->hasPermission('yard-layout.manage'))
                            <button type="button" data-yard-edit="patio" data-name="{{ $patio->nombre }}" data-action="{{ route('yard-layout.patios.update', ['patio' => $patio->id]) }}">Editar</button>
                            <button type="button" data-yard-deactivate="patio" data-name="{{ $patio->nombre }}" data-action="{{ route('yard-layout.patios.destroy', ['patio' => $patio->id]) }}">Desactivar</button>
                            @endif
                        </div>
                    </div></td>
                    <td><strong>{{ $patio->nombre }}</strong></td>
                    <td>{{ $patio->bloques_activos_count }}</td>
                    <td>{{ $patio->capacidad_total }} espacios</td>
                    <td>{{ $patio->ocupacion_total }} / {{ $patio->capacidad_total }}</td>
                </tr>
                @empty<tr><td colspan="5" class="empty-cell">No hay patios para estos filtros.</td></tr>@endforelse
            </tbody>
        </table></div><div class="pagination-wrap">@include('partials.administration-pagination', ['paginator' => $patios])</div></div>
    </section>
    @include('partials.yard-layout-dialogs')
@endsection
