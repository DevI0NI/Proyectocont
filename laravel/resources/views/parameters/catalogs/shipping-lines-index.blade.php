@php
    $liveTable = true;
@endphp
<section class="page-heading standard-records-heading"><div><span class="eyebrow">Catalogos</span><h1>{{ $definition['title'] }}</h1></div></section>
@if($errors->any())<script type="application/json" data-shipping-line-errors>@json($errors->all())</script>@endif
<section class="workboard standard-records-table shipping-lines-table" data-shipping-lines>
    @include('partials.administration-toolbar', ['paginator' => $records, 'createUrl' => $catalog === 'iso' ? null : route('catalogs.create', $catalog), 'createLabel' => ($catalog === 'repuestos' ? 'Nuevo ' : 'Nueva ').$definition['singular'], 'clearKeys' => ['estado']])
    <div class="standard-records-panel"><div class="table-scroll"><table class="data-table">
        <thead><tr>@include('partials.administration-table-head', ['actionsFirst' => true])</tr></thead>
        <tbody>
            @forelse($records as $record)
            <tr>
                <td>@if(auth()->user()->hasPermission($catalog.'.manage'))<div class="row-options" data-record-options>
                    <button type="button" class="options-trigger" aria-label="Opciones de {{ $record->name }}" aria-expanded="false" aria-haspopup="true">Opciones<span data-admin-icon="chevron-down" aria-hidden="true"></span></button>
                    <div class="options-menu record-options-menu" hidden>
                        @if((int)$record->status === 1)
                            <a href="{{ route('catalogs.edit', ['catalog' => $catalog, 'record' => $record->id]) }}">Editar</a>
                        @endif
                        <button type="button" data-shipping-line-status data-company-kind="{{ $definition['singular'] }}" data-action="{{ route('catalogs.status', ['catalog' => $catalog, 'record' => $record->id]) }}" data-name="{{ $record->name }}" data-status="{{ (int)$record->status === 1 ? 2 : 1 }}">{{ (int)$record->status === 1 ? 'Desactivar' : 'Reactivar' }}</button>
                    </div>
                </div>@else<span class="table-muted">Sin acciones</span>@endif</td>
                @if(in_array($catalog, ['iso', 'repuestos', 'reparaciones'], true))<td><strong>{{ $record->code }}</strong></td>@endif
                <td><strong>{{ $record->name }}</strong>@if((int)$record->status === 2) <small class="table-muted">{{ $catalog === 'repuestos' || $catalog === 'iso' ? '(Inactivo)' : '(Inactiva)' }}</small>@endif</td>
                @if($catalog === 'navieras')
                <td><div class="tag-list">@forelse($branches->get($record->id, collect()) as $branch)<span class="tag">{{ $branch->name }}{{ (int)$branch->status === 2 ? ' (inactiva)' : '' }}</span>@empty<span class="table-muted">Sin sucursales</span>@endforelse</div></td>
                <td><div class="tag-list">@forelse($auditors->get($record->id, collect()) as $auditor)<span class="tag">{{ $auditor->name }}{{ (int)$auditor->status === 2 ? ' (inactiva)' : '' }} · {{ $auditor->branch_name ?? 'Sin sucursal definida' }}</span>@empty<span class="table-muted">Sin auditoras</span>@endforelse</div></td>
                @elseif($catalog === 'iso')
                <td>{{ $record->type_name }}</td>
                <td>{{ number_format($record->length_mm / 1000, 3) }} × {{ number_format($record->width_mm / 1000, 3) }} × {{ number_format($record->height_mm / 1000, 3) }} m</td>
                @elseif(in_array($catalog, ['repuestos', 'reparaciones'], true))
                <td>{{ $record->unit }}</td>
                <td>{{ $record->reference_cost === null ? 'Sin definir' : $record->currency.' '.number_format((float)$record->reference_cost, 2) }}</td>
                @else
                <td>{{ $record->contact_name ?: 'Sin contacto' }}</td>
                <td>{{ $record->email ?: 'Sin correo' }}</td>
                <td><span class="catalog-phone">{{ $record->phone ? preg_replace('/^(\+50[23])(\d{4})(\d{4})$/', '$1 $2-$3', $record->phone) : 'Sin telefono' }}</span></td>
                @endif
            </tr>
            @empty<tr><td colspan="{{ count($tableColumns) + 1 }}" class="empty-cell">{{ $catalog === 'iso' ? 'No hay codigos ISO para estos filtros.' : 'No hay '.mb_strtolower($definition['title']).' para estos filtros.' }}</td></tr>@endforelse
        </tbody>
    </table></div><div class="pagination-wrap">@include('partials.administration-pagination', ['paginator' => $records])</div></div>
</section>
<dialog class="container-exit-dialog action-modal" id="shippingLineStatusDialog" aria-labelledby="shippingLineStatusTitle" aria-describedby="shippingLineStatusDescription">
    <span class="container-exit-dialog-kicker" data-shipping-line-kicker></span><h2 id="shippingLineStatusTitle"></h2><p id="shippingLineStatusDescription"></p>
    <form method="POST" data-shipping-line-status-form novalidate>@csrf @method('PATCH')<input type="hidden" name="status">
        <div class="container-exit-dialog-actions"><button type="button" class="button secondary" data-shipping-line-close>Cancelar</button><button type="submit" class="button" data-shipping-line-confirm></button></div>
    </form>
</dialog>
