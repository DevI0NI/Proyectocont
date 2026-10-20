@extends('layouts.app')

@section('content')
    <section class="page-heading transfer-page-heading">
        <div>
            <span class="eyebrow">Operacion</span>
            <h1>Despachos</h1>
            <p>Revisa las condiciones del contenedor antes de registrar su salida.</p>
        </div>
        <div class="toolbar-actions">
            <a href="{{ route('documents.dispatch.eir') }}" class="button secondary">EIR salida</a>
            <a href="{{ route('documents.dispatch.ticket') }}" class="button secondary">Ticket salida</a>
        </div>
    </section>

    @if ($errors->any())
        <div class="form-error page-error">{{ $errors->first() }}</div>
    @endif

    <section class="form-panel transfer-panel">
        <form method="POST" action="{{ route('dispatches.store') }}" class="container-form transfer-form dispatch-form" id="dispatchForm">
            @csrf
            <input type="hidden" id="contenedor_id" name="contenedor_id" value="{{ old('contenedor_id', $selectedContainer?->id) }}" required>

            <div class="container-form-timeline transfer-timeline" aria-label="Pasos del despacho">
                <button type="button" class="timeline-step active" data-dispatch-step="0"><span>01</span><strong>Seleccionar</strong></button>
                <button type="button" class="timeline-step" data-dispatch-step="1" disabled><span>02</span><strong>Revisar</strong></button>
                <button type="button" class="timeline-step" data-dispatch-step="2" disabled><span>03</span><strong>Confirmar</strong></button>
            </div>

            <div class="transfer-workspace dispatch-workspace">
                <div class="transfer-phase dispatch-phase" data-dispatch-phase="0">
                    <div class="transfer-section-heading">
                        <span class="transfer-step">01</span>
                        <div><span class="eyebrow">Seleccion</span><h2 class="section-title">Elegir contenedor</h2></div>
                    </div>
                    <div class="transfer-search">
                        <label for="buscar_contenedor">Buscar en la sucursal</label>
                        <input type="search" id="buscar_contenedor" value="{{ old('buscar_contenedor') }}" maxlength="255" autocomplete="off" placeholder="Numero de equipo">
                    </div>
                    <div class="transfer-list dispatch-list" id="dispatchResults" aria-label="Contenedores encontrados" aria-live="polite">
                        <p class="empty-cell">Escribe al menos 2 caracteres para buscar un contenedor activo.</p>
                    </div>
                </div>

                <div class="transfer-phase dispatch-phase" data-dispatch-phase="1" hidden>
                    <div class="transfer-section-heading">
                        <span class="transfer-step">02</span>
                        <div><span class="eyebrow">Validacion</span><h2 class="section-title">Condiciones de salida</h2></div>
                    </div>
                    <div class="dispatch-selected">
                        <strong id="dispatchSelectedName">-</strong>
                        <span id="dispatchSelectedLocation">-</span>
                    </div>
                    <div class="dispatch-checklist" aria-label="Requisitos del despacho">
                        <div class="dispatch-check" id="dispatchIpsaCheck">
                            <div><strong>Disponibilidad IPSA</strong><span id="dispatchIpsaDetail">-</span></div>
                            <em id="dispatchIpsaStatus">Pendiente</em>
                        </div>
                        <div class="dispatch-check" id="dispatchAuditCheck">
                            <div><strong>Auditoria PYP</strong><span id="dispatchAuditDetail">-</span></div>
                            <em id="dispatchAuditStatus">Pendiente</em>
                        </div>
                        <div class="dispatch-check" id="dispatchAccessCheck">
                            <div><strong>Acceso fisico</strong><span id="dispatchAccessDetail">-</span></div>
                            <em id="dispatchAccessStatus">Pendiente</em>
                        </div>
                    </div>
                    <p class="dispatch-eligibility" id="dispatchEligibility" role="status"></p>
                    <label class="record-form dispatch-observations" for="observaciones">
                        <span>Observaciones de salida <small>(opcional)</small></span>
                        <textarea id="observaciones" name="observaciones" rows="3" maxlength="150">{{ old('observaciones') }}</textarea>
                        <small id="charCount">0 / 150</small>
                    </label>
                </div>

                <div class="transfer-phase transfer-confirm dispatch-phase dispatch-confirm" data-dispatch-phase="2" hidden>
                    <div class="transfer-section-heading">
                        <span class="transfer-step">03</span>
                        <div><span class="eyebrow">Confirmacion</span><h2 class="section-title">Confirmar salida</h2></div>
                    </div>
                    <div class="transfer-confirm-content">
                        <p class="transfer-confirm-intro">Comprueba los datos antes de retirar el contenedor del inventario activo.</p>
                        <div class="transfer-confirm-equipment">
                            <span>Contenedor a despachar</span>
                            <strong id="dispatchConfirmName">-</strong>
                        </div>
                        <dl class="dispatch-confirm-details">
                            <div><dt>Ubicacion actual</dt><dd id="dispatchConfirmLocation">-</dd></div>
                            <div><dt>Validaciones</dt><dd>IPSA disponible · PYP aprobado · Salida libre</dd></div>
                            <div><dt>Observaciones</dt><dd id="dispatchConfirmObservations">Sin observaciones.</dd></div>
                        </dl>
                        <p class="transfer-confirm-note">La disponibilidad se comprobara de nuevo al registrar el despacho.</p>
                    </div>
                </div>

                <aside class="ingress-location3d-panel transfer-preview dispatch-preview" id="dispatchPreview">
                    <div class="ingress-location3d-head">
                        <div><strong>Vista 3D del bloque</strong><span id="dispatchPreviewCaption">Selecciona un contenedor para ver su ubicacion.</span></div>
                        <small>3D</small>
                    </div>
                    <div class="ingress-location3d-stage">
                        <canvas class="ingress-location3d" id="dispatchPreviewCanvas" data-scene="{}" aria-label="Vista tridimensional del bloque del contenedor"></canvas>
                        <p class="transfer-preview-empty" id="dispatchPreviewEmpty">Selecciona un contenedor para ver el bloque.</p>
                        <div class="ingress-location3d-tooltip" hidden><strong data-ingress-tooltip-position></strong><span data-ingress-tooltip-name></span></div>
                    </div>
                    <p class="transfer-inline-message" id="dispatchPreviewMessage" role="status">Busca un contenedor para revisar si puede salir.</p>
                    <div class="transfer-actions dispatch-actions" id="dispatchActions">
                        <button type="button" class="container-edit-button previous" id="dispatchPrevious" hidden>Anterior</button>
                        <button type="button" class="container-edit-button next" id="dispatchNext" disabled>Siguiente</button>
                        <button type="submit" class="container-edit-button save @if(!auth()->user()->hasPermission('dispatches.operate')) permission-readonly-submit @endif" id="dispatchButton" disabled hidden>Registrar despacho</button>
                    </div>
                </aside>
            </div>
        </form>
    </section>

    <script>
        const preselectedContainer = @json($selectedContainer);
        let pendingContainerId = @json((string) old('contenedor_id', $selectedContainer?->id));
        const searchInput = document.getElementById('buscar_contenedor');
        const resultsWrap = document.getElementById('dispatchResults');
        const containerInput = document.getElementById('contenedor_id');
        const dispatchButton = document.getElementById('dispatchButton');
        const previousButton = document.getElementById('dispatchPrevious');
        const nextButton = document.getElementById('dispatchNext');
        const actions = document.getElementById('dispatchActions');
        const previewPanel = document.getElementById('dispatchPreview');
        const previewCanvas = document.getElementById('dispatchPreviewCanvas');
        const previewEmpty = document.getElementById('dispatchPreviewEmpty');
        const previewCaption = document.getElementById('dispatchPreviewCaption');
        const previewMessage = document.getElementById('dispatchPreviewMessage');
        const observations = document.getElementById('observaciones');
        const charCount = document.getElementById('charCount');
        const phases = Array.from(document.querySelectorAll('[data-dispatch-phase]'));
        const steps = Array.from(document.querySelectorAll('[data-dispatch-step]'));
        let results = [];
        let selectedItem = null;
        let blockPreview = null;
        let previewFailed = false;
        let currentStep = 0;
        let searchRequestId = 0;
        let previewRequestId = 0;
        let searchTimer;

        function escapeHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, function (character) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character];
            });
        }

        function locationLabel(item) {
            return item.patio + ' · Bloque ' + item.bloque + ' · Posicion ' + item.posicion;
        }

        function blockingReasons(item) {
            const reasons = [];
            if (!item.ipsa_disponible) reasons.push('IPSA no esta disponible');
            if (!item.auditoria_aprobada) reasons.push(item.situacion_auditoria || (item.estatus_pyp ? 'PYP: ' + item.estatus_pyp : 'Falta auditoria PYP'));
            if (item.bloqueado_fisicamente) reasons.push(item.bloqueadores + ' contenedor(es) delante');
            return reasons;
        }

        function renderResults() {
            if (!results.length) {
                resultsWrap.innerHTML = '<p class="empty-cell">No hay contenedores activos con ese numero en esta sucursal.</p>';
                return;
            }
            resultsWrap.innerHTML = results.map(function (item) {
                const selected = String(item.id) === String(selectedItem?.id);
                const detail = item.puede_despachar ? 'Listo para despacho' : blockingReasons(item).join(' · ');
                return `<button type="button" class="transfer-item ${item.puede_despachar ? '' : 'blocked'} ${selected ? 'selected' : ''}" data-id="${item.id}" aria-pressed="${selected}">
                    <span class="transfer-item-position">P${item.posicion}</span>
                    <span class="transfer-item-body"><strong>${escapeHtml(item.nombre)}</strong><small>${escapeHtml(detail)}</small></span>
                    <span class="transfer-item-status ${item.puede_despachar ? '' : 'blocked'}">${item.puede_despachar ? 'Listo' : 'Revisar'}</span>
                </button>`;
            }).join('');
        }

        function updateCheck(id, passed, detail, status) {
            const row = document.getElementById(id + 'Check');
            row.classList.toggle('passed', passed);
            row.classList.toggle('blocked', !passed);
            document.getElementById(id + 'Detail').textContent = detail;
            document.getElementById(id + 'Status').textContent = status;
        }

        function updateValidation() {
            if (!selectedItem) return;
            document.getElementById('dispatchSelectedName').textContent = selectedItem.nombre;
            document.getElementById('dispatchSelectedLocation').textContent = locationLabel(selectedItem);
            updateCheck('dispatchIpsa', selectedItem.ipsa_disponible, selectedItem.estatus_ipsa || 'Sin estatus', selectedItem.ipsa_disponible ? 'Correcto' : 'Pendiente');
            updateCheck('dispatchAudit', selectedItem.auditoria_aprobada, selectedItem.situacion_auditoria || selectedItem.estatus_pyp || 'Sin auditoria', selectedItem.auditoria_aprobada ? 'Aprobado' : 'No aprobado');
            updateCheck('dispatchAccess', !selectedItem.bloqueado_fisicamente,
                selectedItem.bloqueado_fisicamente ? selectedItem.bloqueadores + ' contenedor(es) delante de la salida' : 'Sin contenedores delante',
                selectedItem.bloqueado_fisicamente ? 'Bloqueado' : 'Libre');
            const eligibility = document.getElementById('dispatchEligibility');
            eligibility.classList.toggle('blocked', !selectedItem.puede_despachar);
            eligibility.textContent = selectedItem.puede_despachar
                ? 'El contenedor cumple las condiciones para salir.'
                : 'No se puede despachar todavia. Corrige los puntos pendientes antes de confirmar.';
        }

        function refreshPreview() {
            previewPanel.hidden = currentStep === 2;
            const hasScene = Boolean(selectedItem && blockPreview);
            previewEmpty.hidden = hasScene;
            previewEmpty.textContent = previewFailed ? 'No se pudo cargar la vista 3D.' : 'Selecciona un contenedor para ver el bloque.';
            previewCaption.textContent = selectedItem ? locationLabel(selectedItem) : 'Selecciona un contenedor para ver su ubicacion.';
            previewMessage.textContent = selectedItem
                ? selectedItem.bloqueado_fisicamente
                    ? selectedItem.bloqueadores + ' contenedor(es) bloquean su salida. Se muestran en rojo.'
                    : selectedItem.puede_despachar ? 'El contenedor esta listo para salir.' : blockingReasons(selectedItem).join(' · ')
                : 'Busca un contenedor para revisar si puede salir.';
            const scene = !hasScene || previewPanel.hidden ? {} : {
                ...blockPreview,
                transfer_focus: true,
                selected_id: selectedItem.id,
                selected_position: selectedItem.posicion,
                blocker_positions: selectedItem.bloqueado_fisicamente
                    ? blockPreview.containers.filter((item) => item.posicion < selectedItem.posicion).map((item) => item.posicion)
                    : [],
            };
            const serialized = JSON.stringify(scene);
            if (previewCanvas.dataset.scene !== serialized) {
                previewCanvas.dataset.scene = serialized;
                window.dispatchEvent(new CustomEvent('ingress-location3d:update'));
            }
        }

        async function loadPreview(item) {
            const requestId = ++previewRequestId;
            blockPreview = null;
            previewFailed = false;
            refreshPreview();
            try {
                const response = await fetch('{{ url('/ingresos/bloque-preview') }}/' + item.bloque_id);
                if (!response.ok) throw new Error();
                const preview = await response.json();
                if (requestId !== previewRequestId) return;
                blockPreview = preview;
            } catch (error) {
                if (requestId !== previewRequestId) return;
                previewFailed = true;
            }
            refreshPreview();
        }

        function updateConfirmation() {
            if (!selectedItem) return;
            document.getElementById('dispatchConfirmName').textContent = selectedItem.nombre;
            document.getElementById('dispatchConfirmLocation').textContent = locationLabel(selectedItem);
            document.getElementById('dispatchConfirmObservations').textContent = observations.value.trim() || 'Sin observaciones.';
        }

        function setStep(step) {
            if (step > 0 && !selectedItem) return;
            if (step > 1 && !selectedItem?.puede_despachar) return;
            currentStep = step;
            phases.forEach((phase, index) => { phase.hidden = index !== step; });
            steps.forEach((button, index) => {
                button.classList.toggle('active', index === step);
                button.classList.toggle('completed', index < step);
                button.setAttribute('aria-current', index === step ? 'step' : 'false');
            });
            previousButton.hidden = step === 0;
            nextButton.hidden = step === 2;
            nextButton.disabled = step === 0 ? !selectedItem : step === 1 ? !selectedItem?.puede_despachar : true;
            dispatchButton.hidden = step !== 2;
            dispatchButton.disabled = !selectedItem?.puede_despachar;
            (step === 2 ? phases[2] : previewPanel).appendChild(actions);
            if (step === 2) updateConfirmation();
            refreshPreview();
        }

        function updateSelection() {
            containerInput.value = selectedItem?.id || '';
            if (!selectedItem && currentStep > 0) setStep(0);
            steps[1].disabled = !selectedItem;
            steps[2].disabled = !selectedItem?.puede_despachar;
            nextButton.disabled = currentStep === 0 ? !selectedItem : !selectedItem?.puede_despachar;
            dispatchButton.disabled = !selectedItem?.puede_despachar;
            if (currentStep === 2 && !selectedItem?.puede_despachar) setStep(1);
            if (selectedItem) updateValidation();
            renderResults();
            refreshPreview();
        }

        function selectContainer(item) {
            selectedItem = item;
            searchInput.value = item.nombre;
            updateSelection();
            loadPreview(item);
        }

        function clearSelection() {
            selectedItem = null;
            blockPreview = null;
            previewFailed = false;
            previewRequestId++;
            updateSelection();
        }

        async function searchContainers(query, requestId) {
            try {
                const response = await fetch('{{ route('dispatches.search') }}?query=' + encodeURIComponent(query));
                if (!response.ok) throw new Error();
                const matches = await response.json();
                if (requestId !== searchRequestId) return;
                results = matches;
                renderResults();
                if (pendingContainerId && !selectedItem) {
                    const preselected = matches.find((item) => String(item.id) === pendingContainerId);
                    if (preselected) {
                        pendingContainerId = '';
                        selectContainer(preselected);
                    }
                }
            } catch (error) {
                if (requestId !== searchRequestId) return;
                resultsWrap.innerHTML = '<p class="empty-cell">No se pudo buscar en la sucursal.</p>';
            }
        }

        searchInput.addEventListener('input', function () {
            clearTimeout(searchTimer);
            pendingContainerId = '';
            const query = searchInput.value.trim();
            if (selectedItem && query.toUpperCase() !== selectedItem.nombre) clearSelection();
            const requestId = ++searchRequestId;
            if (query.length < 2) {
                results = [];
                resultsWrap.innerHTML = '<p class="empty-cell">Escribe al menos 2 caracteres para buscar un contenedor activo.</p>';
                return;
            }
            resultsWrap.innerHTML = '<p class="empty-cell">Buscando contenedores...</p>';
            searchTimer = setTimeout(() => searchContainers(query, requestId), 220);
        });

        resultsWrap.addEventListener('click', function (event) {
            const button = event.target.closest('[data-id]');
            const item = results.find((row) => String(row.id) === button?.dataset.id);
            if (item) selectContainer(item);
        });

        nextButton.addEventListener('click', () => setStep(currentStep + 1));
        previousButton.addEventListener('click', () => setStep(currentStep - 1));
        steps.forEach((button, index) => button.addEventListener('click', () => setStep(index)));

        observations.addEventListener('input', function () {
            charCount.textContent = observations.value.length + ' / 150';
            if (currentStep === 2) updateConfirmation();
        });
        charCount.textContent = observations.value.length + ' / 150';

        document.getElementById('dispatchForm').addEventListener('submit', function (event) {
            if (!selectedItem?.puede_despachar || currentStep !== 2) {
                event.preventDefault();
                return;
            }
            dispatchButton.disabled = true;
            dispatchButton.textContent = 'Registrando...';
        });

        if (preselectedContainer) {
            searchInput.value = preselectedContainer.nombre;
            searchContainers(preselectedContainer.nombre, ++searchRequestId);
        } else if (searchInput.value.trim().length >= 2) {
            searchContainers(searchInput.value.trim(), ++searchRequestId);
        }
    </script>
@endsection
