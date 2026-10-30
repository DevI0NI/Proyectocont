@extends('layouts.app')

@php
    $mechanicMode = $mechanicMode ?? false;
    $externalMode = $externalMode ?? false;
    $restrictedMode = $mechanicMode || $externalMode;
    $infoSections = [
        [
            'tab' => 'Equipo',
            'title' => 'Datos del equipo',
            'columns' => 'two-columns',
            'fields' => [
                ['label' => 'Fecha de ingreso', 'value' => $container->fecha_ingreso ? \Carbon\Carbon::parse($container->fecha_ingreso)->format('d/m/Y H:i') : 'N/D'],
                ['label' => 'Numero de equipo', 'value' => $container->nombre],
                ['label' => 'Tipo de unidad', 'value' => $container->tipo_unidad_nombre ?: 'N/D'],
                ['label' => 'Modelo', 'value' => $container->modelo_nombre ?: 'N/D'],
                ['label' => 'ISO Code', 'value' => $container->iso_code ?: 'N/D'],
                ['label' => 'Marca', 'value' => $container->marca ?: 'N/D'],
            ],
        ],
        [
            'tab' => 'Estados',
            'title' => 'Estados tecnicos',
            'columns' => $restrictedMode ? 'two-columns' : 'three-columns',
            'fields' => [
                ['label' => 'Estado de la unidad', 'value' => $container->estatus_unidad ?: 'N/D'],
                ['label' => 'Estado estructura', 'value' => $container->estatus_box ?: 'N/D'],
                ['label' => 'Estatus IPSA', 'value' => $container->estatus_ipsa ?: 'N/D'],
                ...($mechanicMode ? [['label' => 'Estado de mantenimiento', 'value' => $maintenanceStatus]] : []),
            ],
            'notes' => [
                ['label' => 'Observaciones unidad', 'value' => $container->observaciones_unidad ?: 'Sin observaciones registradas.'],
                ['label' => 'Observaciones estructura', 'value' => $container->observaciones_box ?: 'Sin observaciones registradas.'],
            ],
        ],
        ...($restrictedMode ? [] : [[
            'tab' => 'Transporte',
            'title' => 'Documentacion y transporte',
            'columns' => 'three-columns',
            'fields' => [
                ['label' => 'Naviera', 'value' => $container->naviera ?: 'N/D'],
                ['label' => 'Quien lo trajo', 'value' => $container->transportista ?: 'N/D'],
                ['label' => 'Piloto', 'value' => $container->conductor ?: 'N/D'],
                ['label' => 'Placa cabezal', 'value' => $container->placa_cabezal ?: 'N/D'],
                ['label' => 'Placa chasis', 'value' => $container->placa_chasis ?: 'N/D'],
            ],
        ]]),
        [
            'tab' => 'Auditoria',
            'title' => 'Asignacion de auditoria externa',
            'columns' => 'two-columns',
            'fields' => [
                ['label' => 'Naviera del ingreso', 'value' => $container->shipping_line_name ?: ($container->naviera ?: 'Sin registrar')],
                ['label' => 'Empresa auditora', 'value' => $container->audit_company_name ?: ($restrictedMode ? 'Sin auditora asignada' : 'Pendiente de confirmacion')],
                ['label' => 'Estado de auditoria', 'value' => $container->audit_status ?: 'Sin resultado'],
                ...($restrictedMode ? [] : [
                    ['label' => 'Asignacion del ingreso', 'value' => $container->audit_assignment_notice ?: 'Auditora asignada'],
                    ['label' => 'Observaciones de la asignacion', 'value' => $container->audit_reference ?: 'Sin observaciones registradas'],
                ]),
            ],
            ...($mechanicMode && !$container->current_ingress_cycle_id ? ['notes' => [
                ['label' => 'Faltan datos del ingreso', 'value' => 'PROPORTSA debe completar la asignacion de naviera y auditora. Esto no impide reparar el contenedor si su estado IPSA es OOS.'],
            ]] : []),
        ],
        [
            'tab' => 'Ubicacion',
            'title' => 'Ubicacion actual',
            'columns' => 'three-columns',
            'fields' => [
                ['label' => 'Patio', 'value' => $container->patio_nombre ?: 'Sin patio'],
                ['label' => 'Bloque', 'value' => $container->bloque_numero ?: 'N/D'],
                ['label' => 'Posicion', 'value' => $container->posicion ?: 'N/D'],
            ],
        ],
    ];
@endphp

@section('content')
    <section class="page-heading">
        <div>
            <span class="eyebrow">Informacion del contenedor</span>
            <h1>Contenedor {{ $container->nombre }}</h1>
        </div>
        <div class="toolbar-actions container-info-actions @if($restrictedMode) is-read-only @endif">
            @if(!$restrictedMode)
            <a href="{{ route('containers.history.show', $container->id) }}" class="container-info-action back">Historial</a>
            <a href="{{ route('documents.ingress.ticket', ['contenedor_id' => $container->id] + $backParams) }}" class="container-info-action ticket">
                Constancia ingreso
            </a>
            @if(auth()->user()->hasPermission('containers.edit'))<a href="{{ route('containers.edit', ['container' => $container->id] + $backParams) }}" class="container-info-action edit">
                Modificar
            </a>
            @endif
            @endif
            <a href="{{ $externalMode ? $returnUrl : route($mechanicMode ? 'maintenance.orders' : 'containers.index', $backParams) }}" class="container-info-action back">
                Volver
            </a>
        </div>
    </section>

    <section class="form-panel container-form-shell container-info-shell" id="containerInfoShell" style="--info-section-count: {{ count($infoSections) }}">
        <div class="container-form-timeline" role="tablist" aria-label="Datos del contenedor">
            @foreach ($infoSections as $index => $section)
                <button type="button" class="timeline-step {{ $index === 0 ? 'active' : '' }}" id="container-info-tab-{{ $index }}" role="tab" aria-controls="container-info-panel-{{ $index }}" aria-selected="{{ $index === 0 ? 'true' : 'false' }}" tabindex="{{ $index === 0 ? '0' : '-1' }}" data-info-step="{{ $index }}">
                    <span>{{ sprintf('%02d', $index + 1) }}</span>
                    <strong>{{ $section['tab'] }}</strong>
                </button>
            @endforeach
        </div>

        <div class="container-info-content">
            @foreach ($infoSections as $index => $section)
                <section class="container-form-section container-info-section {{ $index === 0 ? 'active' : '' }}" id="container-info-panel-{{ $index }}" role="tabpanel" aria-labelledby="container-info-tab-{{ $index }}" tabindex="0" data-info-panel="{{ $index }}">
                    <div class="container-form-section-head">
                        <span>{{ sprintf('%02d', $index + 1) }}</span>
                        <div><h2>{{ $section['title'] }}</h2></div>
                    </div>

                    <div class="container-info-grid {{ $section['columns'] }}">
                        @foreach ($section['fields'] as $field)
                            <div class="container-info-field">
                                <span>{{ $field['label'] }}</span>
                                <strong>{{ $field['value'] }}</strong>
                            </div>
                        @endforeach
                    </div>

                    @isset($section['notes'])
                        <div class="container-info-grid two-columns container-info-notes">
                            @foreach ($section['notes'] as $note)
                                <div class="container-info-field">
                                    <span>{{ $note['label'] }}</span>
                                    <p>{{ $note['value'] }}</p>
                                </div>
                            @endforeach
                        </div>
                    @endisset
                </section>
            @endforeach

            <div class="container-info-nav">
                <button type="button" class="button container-edit-button previous" id="containerInfoPrevious" hidden>Anterior</button>
                <button type="button" class="button container-edit-button next" id="containerInfoNext">Siguiente</button>
            </div>
        </div>
    </section>

    <script>
        (() => {
            const shell = document.getElementById('containerInfoShell');
            const tabs = Array.from(shell.querySelectorAll('[data-info-step]'));
            const panels = Array.from(shell.querySelectorAll('[data-info-panel]'));
            const previous = document.getElementById('containerInfoPrevious');
            const next = document.getElementById('containerInfoNext');
            let currentStep = 0;

            function showStep(index, focusTab = false) {
                currentStep = index;
                tabs.forEach((tab, tabIndex) => {
                    tab.classList.toggle('active', tabIndex === index);
                    tab.classList.toggle('completed', tabIndex < index);
                    tab.setAttribute('aria-selected', tabIndex === index ? 'true' : 'false');
                    tab.tabIndex = tabIndex === index ? 0 : -1;
                });
                panels.forEach((panel, panelIndex) => {
                    panel.classList.toggle('active', panelIndex === index);
                    panel.hidden = panelIndex !== index;
                });
                previous.hidden = index === 0;
                next.hidden = index === tabs.length - 1;
                if (focusTab) tabs[index].focus();
            }

            tabs.forEach((tab, index) => {
                tab.addEventListener('click', () => showStep(index));
                tab.addEventListener('keydown', (event) => {
                    const target = event.key === 'ArrowRight' ? (index + 1) % tabs.length
                        : event.key === 'ArrowLeft' ? (index - 1 + tabs.length) % tabs.length
                        : event.key === 'Home' ? 0
                        : event.key === 'End' ? tabs.length - 1 : null;
                    if (target === null) return;
                    event.preventDefault();
                    showStep(target, true);
                });
            });
            previous.addEventListener('click', () => showStep(currentStep - 1));
            next.addEventListener('click', () => showStep(currentStep + 1));
            shell.classList.add('is-interactive');
            showStep(0);
        })();
    </script>
@endsection
