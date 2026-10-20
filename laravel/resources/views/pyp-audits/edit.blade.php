@extends('layouts.app')

@section('content')
    <section class="page-heading">
        <div>
            <span class="eyebrow">Seguimiento PYP</span>
            <h1>Auditoria PYP</h1>
        </div>
        <p>Actualiza el estado y el detalle de revision del contenedor asignado.</p>
    </section>

    @if ($errors->any())
        <div class="form-error page-error">{{ $errors->first() }}</div>
    @endif

    <section class="form-panel">
        <form method="POST" action="{{ route('pyp-audits.update', ['container' => $container->id]) }}" class="record-form wide-form record-select-fields" data-external-audit-form>
            @csrf
            @method('PATCH')

            <input type="hidden" name="patio_id" value="{{ $backParams['patio_id'] ?? $container->patio_id }}">
            <input type="hidden" name="bloque_id" value="{{ $backParams['bloque_id'] ?? $container->bloque_id }}">

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
                <select name="estatus_pyp" required data-admin-select>
                    @foreach(\App\Services\PypAuditService::STATES as $state)
                        <option value="{{ $state }}" @selected(old('estatus_pyp', in_array($container->estatus_pyp, \App\Services\PypAuditService::STATES, true) ? $container->estatus_pyp : 'Pendiente de revision') === $state)>{{ $state }}</option>
                    @endforeach
                </select>
            </label>

            <label>
                <span>Fecha de registro</span>
                <input type="text" value="{{ $container->fecha_registro ? \Carbon\Carbon::parse($container->fecha_registro)->format('d/m/Y H:i') : 'N/D' }}" readonly>
            </label>

            <label class="field-wide">
                <span>Detalle de revision o motivo del resultado</span>
                <textarea name="informacion_adicional" maxlength="150" rows="5" required>{{ old('informacion_adicional', $container->informacion_adicional) }}</textarea>
                <small>Maximo 150 caracteres.</small>
            </label>

            <div class="form-actions-row field-wide">
                <button type="submit" class="button primary">Guardar cambios</button>
                <a href="{{ route('pyp-audits.index', $backParams) }}" class="button secondary">Cancelar</a>
            </div>
        </form>
    </section>
@endsection
