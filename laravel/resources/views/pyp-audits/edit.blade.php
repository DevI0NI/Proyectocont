@extends('layouts.app')

@section('content')
    <section class="page-heading external-audit-heading">
        <div>
            <span class="eyebrow">Auditoría externa · {{ $externalCompanyName }}</span>
            <h1>{{ $container->nombre }}</h1>
            <p>{{ auth()->user()->external_access_level === 'supervisor' ? 'Supervisor' : 'Inspector' }} · {{ $container->patio_nombre }} / Bloque {{ $container->bloque_numero }} / Pos. {{ $container->posicion }}</p>
        </div>
        <a href="{{ $returnUrl ?? route('pyp-audits.index', $backParams) }}" class="mw-button"><span data-notification-icon="chevron-left" aria-hidden="true"></span>Volver</a>
    </section>

    @if ($errors->any())
        <div class="form-error page-error">{{ $errors->first() }}</div>
    @endif
    @if($maintenanceJob && $maintenanceJob->status !== 'in_progress')
        <div class="mw-actions"><a class="mw-button" href="{{ route('corrections.show', ['job' => $maintenanceJob->id, 'return_to' => request()->getRequestUri()]) }}">Ver reparación y correcciones<span data-catalog-icon="arrow-right" aria-hidden="true"></span></a></div>
    @endif

    <section class="form-panel">
        <form method="POST" action="{{ route('pyp-audits.update', ['container' => $container->id]) }}" class="record-form wide-form record-select-fields" data-external-audit-form>
            @csrf
            @method('PATCH')
            @if($maintenanceJob)<input type="hidden" name="maintenance_version" value="{{ $maintenanceJob->version }}">@endif
            @if(!empty($returnUrl))<input type="hidden" name="return_to" value="{{ $returnUrl }}">@endif

            <input type="hidden" name="patio_id" value="{{ $backParams['patio_id'] ?? $container->patio_id }}">
            <input type="hidden" name="bloque_id" value="{{ $backParams['bloque_id'] ?? $container->bloque_id }}">

            <fieldset class="external-audit-fields" @disabled(!$canInspect)>
            <label>
                <span>Contenedor</span>
                <input type="text" value="{{ $container->nombre }}" readonly>
            </label>

            <label>
                <span>Ubicacion</span>
                <input type="text" value="{{ $container->patio_nombre }}, Bloque {{ $container->bloque_numero }}, Posicion {{ $container->posicion }}" readonly>
            </label>

            <label>
                <span>Estado de auditoria</span>
                <select name="estatus_pyp" required data-admin-select @disabled($maintenanceJob && in_array($maintenanceJob->status, \App\Services\MaintenanceWorkflow::OPEN_STATES, true))>
                    @foreach(\App\Services\PypAuditService::STATES as $state)
                        <option value="{{ $state }}" @selected(old('estatus_pyp', in_array($container->estatus_pyp, \App\Services\PypAuditService::STATES, true) ? $container->estatus_pyp : 'Pendiente de revision') === $state)>{{ $state }}</option>
                    @endforeach
                </select>
            </label>

            <label>
                <span>Última actualización</span>
                <input type="text" value="{{ $container->fecha_registro ? \Carbon\Carbon::parse($container->fecha_registro, 'UTC')->timezone('America/Guatemala')->format('d/m/Y H:i') : 'N/D' }}" readonly>
            </label>

            <label class="field-wide">
                <span>Detalle de revision o motivo del resultado</span>
                <textarea name="informacion_adicional" maxlength="150" rows="5" required>{{ old('informacion_adicional', $container->informacion_adicional) }}</textarea>
                <small>Maximo 150 caracteres.</small>
            </label>

            </fieldset>
            <div class="form-actions-row field-wide">
                @if($canInspect)<button type="submit" class="button primary">Guardar dictamen</button>@else<span class="notification-body">{{ $maintenanceJob && $maintenanceJob->status === 'returned_for_repair' ? 'Esperando la corrección del mecánico.' : (($maintenanceJob && $maintenanceJob->status !== 'completed') ? 'Esperando la entrega y confirmación del trabajo.' : 'Esperando liberación IPSA.') }}</span>@endif
                <a href="{{ $returnUrl ?? route('pyp-audits.index', $backParams) }}" class="button secondary">{{ !empty($returnUrl) ? 'Volver' : 'Cancelar' }}</a>
            </div>
        </form>
    </section>
@endsection
