@extends('layouts.app')

@section('content')
    @if ($errors->any())
        <div class="form-error page-error">{{ $errors->first() }}</div>
    @endif

    <livewire:containers.inventory-table :pyp-mode="$pypMode ?? false" :maintenance-mode="$maintenanceMode ?? false" />
    @if($maintenanceMode ?? false)
        <dialog class="mw-damage-dialog" data-maintenance-summary data-create-url="{{ route('maintenance.create') }}" aria-labelledby="damageSummaryTitle">
            <header><span class="eyebrow">Daños reportados</span><h2 id="damageSummaryTitle" data-damage-name>Contenedor</h2><p data-damage-location></p></header>
            <p class="mw-error" role="alert" data-damage-error hidden></p>
            <dl class="mw-damage-details">
                <div><dt>Unidad <span data-damage-unit-status></span></dt><dd data-damage-unit-notes></dd></div>
                <div><dt>Estructura <span data-damage-box-status></span></dt><dd data-damage-box-notes></dd></div>
            </dl>
            <footer class="mw-actions"><button type="button" class="mw-button" data-damage-cancel>Cancelar</button><a class="mw-button mw-primary" data-damage-continue>Continuar con evaluacion</a></footer>
        </dialog>
    @endif
@endsection
