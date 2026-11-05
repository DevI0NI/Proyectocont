@extends('layouts.app')

@section('content')
    <section class="page-heading transfer-page-heading">
        <div>
            <span class="eyebrow">Operacion</span>
            <h1>Traslados</h1>
            <p>Movimiento de contenedores entre posiciones de la sucursal.</p>
        </div>
    </section>

    @if ($errors->any())
        <div class="form-error page-error">{{ $errors->first() }}</div>
    @endif

    <section class="form-panel transfer-panel">
        <form method="POST" action="{{ route('transfers.store') }}" id="transferForm" class="container-form transfer-form" data-unsaved-form="transferExitDialog" data-restored-changes="{{ $errors->any() ? 'true' : 'false' }}">
            @csrf
            <input type="hidden" id="contenedor_id" name="contenedor_id" value="{{ old('contenedor_id', $selectedContainer?->id) }}" required>
            <input type="hidden" id="origen_patio_hidden" name="origen_patio_id" value="{{ old('origen_patio_id', $selectedContainer?->patio_id) }}">
            <input type="hidden" id="destino_patio_hidden" name="destino_patio_id" value="{{ old('destino_patio_id') }}">

            <div class="container-form-timeline transfer-timeline" aria-label="Pasos del traslado">
                <button type="button" class="timeline-step active" data-transfer-step="0"><span>01</span><strong>Origen</strong></button>
                <button type="button" class="timeline-step" data-transfer-step="1"><span>02</span><strong>Destino</strong></button>
                <button type="button" class="timeline-step" data-transfer-step="2"><span>03</span><strong>Confirmar</strong></button>
            </div>

            <div class="transfer-workspace">
            <div class="transfer-phase" data-transfer-phase="0">
                <div class="transfer-column">
                    <div class="transfer-section-heading">
                        <span class="transfer-step">01</span>
                        <div>
                            <span class="eyebrow">Origen</span>
                            <h2 class="section-title">Seleccionar contenedor</h2>
                        </div>
                        <span class="transfer-count" id="containerCount" aria-live="polite"></span>
                    </div>

                    <div class="transfer-search">
                        <label for="filtro_contenedor">Buscar contenedor en la sucursal</label>
                        <input type="search" id="filtro_contenedor" placeholder="Numero de equipo" maxlength="255" autocomplete="off" aria-controls="globalSearchResults" aria-expanded="false">
                        <div class="transfer-search-results" id="globalSearchResults" role="listbox" aria-label="Resultados de contenedores" hidden></div>
                    </div>

                    <div class="record-form transfer-fields">
                        <label>
                            <span>Patio origen</span>
                            <select id="origen_patio_id">
                                <option value="">Selecciona un patio</option>
                                @foreach ($patios as $patio)
                                    <option value="{{ $patio->id }}" @selected((int) old('origen_patio_id', $selectedContainer?->patio_id) === (int) $patio->id)>
                                        {{ $patio->nombre }}
                                    </option>
                                @endforeach
                            </select>
                        </label>

                        <label>
                            <span>Bloque origen</span>
                            <select id="origen_bloque_id" data-selected="{{ old('origen_bloque_id', $selectedContainer?->bloque_id) }}" disabled>
                                <option value="">Selecciona un bloque</option>
                            </select>
                        </label>

                    </div>

                    <div class="transfer-list" id="containerList" aria-label="Contenedores del bloque" aria-live="polite">
                        <p class="empty-cell">Selecciona un patio y un bloque de origen.</p>
                    </div>
                </div>
            </div>

            <div class="transfer-phase" data-transfer-phase="1" hidden>
                <div class="transfer-column" id="transferDestination">
                    <div class="transfer-section-heading">
                        <span class="transfer-step">02</span>
                        <div>
                            <span class="eyebrow">Destino</span>
                            <h2 class="section-title">Nueva ubicacion</h2>
                        </div>
                    </div>

                    <div class="selected-transfer" id="selectedTransfer" aria-live="polite">
                        <span class="selected-transfer-label">Equipo a trasladar</span>
                        <strong id="selectedName">Ninguno seleccionado</strong>
                        <span id="selectedLocation">Selecciona un contenedor libre en el origen.</span>
                    </div>

                    <div class="record-form transfer-fields">
                        <label>
                            <span>Patio destino</span>
                            <select id="destino_patio_id" disabled>
                                <option value="">Selecciona un patio</option>
                                @foreach ($patios as $patio)
                                    <option value="{{ $patio->id }}" @selected((int) old('destino_patio_id') === (int) $patio->id)>
                                        {{ $patio->nombre }}
                                    </option>
                                @endforeach
                            </select>
                        </label>

                        <label>
                            <span>Bloque destino</span>
                            <select id="nuevo_bloque_id" name="nuevo_bloque_id" data-selected="{{ old('nuevo_bloque_id') }}" required disabled>
                                <option value="">Selecciona un bloque</option>
                            </select>
                        </label>

                        <label class="transfer-field-wide">
                            <span>Posicion asignada</span>
                            <input type="number" id="nueva_posicion" name="nueva_posicion" value="{{ old('nueva_posicion') }}" min="1" required readonly placeholder="Se calcula al elegir el bloque">
                        </label>
                    </div>

                </div>
            </div>

            <div class="transfer-phase transfer-confirm" data-transfer-phase="2" hidden>
                <div class="transfer-section-heading">
                    <span class="transfer-step">03</span>
                    <div><span class="eyebrow">Confirmacion</span><h2 class="section-title">Revisar traslado</h2></div>
                </div>
                <div class="transfer-confirm-content">
                    <p class="transfer-confirm-intro">Comprueba el contenedor y la ubicacion a la que se movera antes de registrarlo.</p>
                    <div class="transfer-confirm-equipment">
                        <span>Contenedor a trasladar</span>
                        <strong id="confirmContainer">-</strong>
                    </div>
                    <div class="transfer-confirm-flow" aria-label="Recorrido del traslado">
                        <div class="transfer-confirm-location">
                            <span>Ubicacion actual</span>
                            <strong id="confirmOrigin">-</strong>
                        </div>
                        <span class="transfer-confirm-arrow" aria-hidden="true">&#8594;</span>
                        <div class="transfer-confirm-location destination">
                            <span>Nueva ubicacion</span>
                            <strong id="confirmDestination">-</strong>
                        </div>
                    </div>
                    <p class="transfer-confirm-note">Al registrar el traslado, se comprobara de nuevo que el contenedor y la posicion de destino sigan disponibles.</p>
                </div>
            </div>

            <div class="ingress-location3d-panel transfer-preview" id="transferPreview" hidden>
                <div class="ingress-location3d-head">
                    <div><strong id="transferPreviewTitle">Vista 3D del bloque</strong><span id="transferPreviewCaption"></span></div>
                    <small>3D</small>
                </div>
                <div class="ingress-location3d-stage">
                    <canvas class="ingress-location3d" id="transferPreviewCanvas" data-scene="{}" aria-label="Vista tridimensional interactiva del bloque"></canvas>
                    <p class="transfer-preview-empty" id="transferPreviewEmpty">Selecciona un bloque para ver los contenedores.</p>
                    <div class="ingress-location3d-tooltip" hidden><strong data-ingress-tooltip-position></strong><span data-ingress-tooltip-name></span></div>
                </div>
                <p class="transfer-inline-message" id="originMessage" role="status">Selecciona un contenedor para ver su posicion y disponibilidad.</p>
                <p class="transfer-inline-message" id="destinationMessage" role="status" hidden>Elige primero el contenedor que se movera.</p>
                <div class="transfer-actions" id="transferActions">
                    <button type="button" class="container-edit-button previous" id="transferPrevious" hidden>Anterior</button>
                    <button type="button" class="container-edit-button next" id="transferNext">Siguiente</button>
                    <button type="submit" class="container-edit-button save @if(!auth()->user()->hasPermission('transfers.operate')) permission-readonly-submit @endif" id="transferSubmit" disabled hidden>Registrar traslado</button>
                </div>
            </div>
            </div>
        </form>
    </section>

    @include('partials.unsaved-changes', ['exitDialogId' => 'transferExitDialog', 'exitDialogKicker' => 'Confirmar salida', 'exitDialogTitle' => '¿Seguro que quieres salir?', 'exitDialogDescription' => 'Los cambios se perderán.'])

    <script>
        document.addEventListener('DOMContentLoaded', function () {
        const preselectedContainer = @json($selectedContainer);
        const origenPatio = document.getElementById('origen_patio_id');
        const origenBloque = document.getElementById('origen_bloque_id');
        const destinoPatio = document.getElementById('destino_patio_id');
        const destinoBloque = document.getElementById('nuevo_bloque_id');
        const posicionDestino = document.getElementById('nueva_posicion');
        const containerList = document.getElementById('containerList');
        const containerIdInput = document.getElementById('contenedor_id');
        const selectedTransfer = document.getElementById('selectedTransfer');
        const filterInput = document.getElementById('filtro_contenedor');
        const globalSearchResults = document.getElementById('globalSearchResults');
        const origenPatioHidden = document.getElementById('origen_patio_hidden');
        const destinoPatioHidden = document.getElementById('destino_patio_hidden');
        const selectedName = document.getElementById('selectedName');
        const selectedLocation = document.getElementById('selectedLocation');
        const containerCount = document.getElementById('containerCount');
        const destinationMessage = document.getElementById('destinationMessage');
        const transferSubmit = document.getElementById('transferSubmit');
        const transferPrevious = document.getElementById('transferPrevious');
        const transferNext = document.getElementById('transferNext');
        const transferActions = document.getElementById('transferActions');
        const transferConfirm = document.querySelector('.transfer-confirm');
        const transferPreview = document.getElementById('transferPreview');
        const transferPreviewCanvas = document.getElementById('transferPreviewCanvas');
        const transferPreviewTitle = document.getElementById('transferPreviewTitle');
        const transferPreviewCaption = document.getElementById('transferPreviewCaption');
        const transferPreviewEmpty = document.getElementById('transferPreviewEmpty');
        const originMessage = document.getElementById('originMessage');
        const transferPhases = Array.from(document.querySelectorAll('[data-transfer-phase]'));
        const transferSteps = Array.from(document.querySelectorAll('[data-transfer-step]'));
        let currentContainers = [];
        let selectedItem = null;
        let originPreview = null;
        let destinationPreview = null;
        let currentStep = 0;
        let pendingSelectionId = String(preselectedContainer?.id || @json(old('contenedor_id')) || '');
        let searchResults = [];
        let searchRequestId = 0;
        let searchTimer;
        const selectionGate = window.createInteractionGate((busy) => {
            containerList.setAttribute('aria-busy', String(busy));
            globalSearchResults.setAttribute('aria-busy', String(busy));
            document.querySelectorAll('#containerList [data-id], #globalSearchResults [data-id]').forEach((button) => {
                button.disabled = busy || button.dataset.id === String(selectedItem?.id);
            });
        });

        function escapeHtml(value) {
            return String(value).replace(/[&<>"']/g, function (character) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character];
            });
        }

        function selectedText(select) {
            return select.selectedOptions[0]?.textContent.trim() || '';
        }

        function updateSummary() {
            const canMove = Boolean(selectedItem?.puede_trasladar);
            destinoPatio.disabled = !canMove;
            syncBlockAvailability(origenPatio, origenBloque);
            syncBlockAvailability(destinoPatio, destinoBloque, canMove);
            destinoPatio.dispatchEvent(new Event('options-updated'));
            destinoBloque.dispatchEvent(new Event('options-updated'));

            const sameBlock = Boolean(destinoBloque.value) && origenBloque.value === destinoBloque.value;
            const ready = canMove && Boolean(destinoBloque.value) && Boolean(posicionDestino.value) && !sameBlock;
            transferSubmit.disabled = !ready;
            transferSteps[1].disabled = !canMove;
            transferSteps[2].disabled = !ready;

            if (!selectedItem) {
                destinationMessage.textContent = 'Elige primero el contenedor que se movera.';
                originMessage.textContent = 'Selecciona un contenedor para ver su posicion y disponibilidad.';
            } else if (!canMove) {
                destinationMessage.textContent = 'Este contenedor no puede trasladarse todavia.';
                originMessage.textContent = selectedItem.motivo_bloqueo + ' Los contenedores delante se muestran en rojo en la vista 3D.';
            } else if (sameBlock) {
                destinationMessage.textContent = 'Selecciona otro bloque de destino.';
            } else if (!destinoBloque.value) {
                destinationMessage.textContent = destinoPatio.value && destinoPatio.value === origenPatio.value
                    ? 'El bloque de origen aparece deshabilitado porque el contenedor ya esta ahi. Elige otro bloque.'
                    : 'Selecciona el patio y bloque de destino.';
                originMessage.textContent = 'El contenedor seleccionado puede trasladarse.';
            } else if (!posicionDestino.value) {
                destinationMessage.textContent = 'No hay una posicion libre en este bloque.';
            } else {
                destinationMessage.textContent = 'La posicion se asigno segun el espacio disponible.';
            }
            transferNext.disabled = currentStep === 0 ? !canMove : currentStep === 1 ? !ready : true;
            refreshPreview();
        }

        function clearSelection() {
            selectedItem = null;
            containerIdInput.value = '';
            selectedName.textContent = 'Ninguno seleccionado';
            selectedLocation.textContent = 'Selecciona un contenedor libre en el origen.';
            selectedLocation.dataset.position = '';
            updateSummary();
        }

        async function fetchBlockPreview(blockId) {
            if (!blockId) return null;
            const response = await fetch('{{ url('/ingresos/bloque-preview') }}/' + blockId);
            if (!response.ok) throw new Error('No se pudo cargar la vista 3D.');
            return response.json();
        }

        function refreshPreview() {
            const origin = currentStep === 0;
            const preview = origin ? originPreview : currentStep === 1 ? destinationPreview : null;
            const position = Number(posicionDestino.value || 0);
            const hasScene = Boolean(preview && (origin || position));
            transferPreview.hidden = currentStep === 2;
            transferPreviewEmpty.hidden = hasScene;
            transferPreviewEmpty.textContent = origin
                ? 'Selecciona un bloque para ver los contenedores.'
                : 'Selecciona un bloque de destino para ver la posicion prevista.';
            const scene = !hasScene || transferPreview.hidden ? {} : origin ? {
                ...preview,
                transfer_focus: true,
                selected_id: selectedItem?.id || null,
                selected_position: selectedItem?.posicion || 0,
                blocker_positions: selectedItem && !selectedItem.puede_trasladar
                    ? currentContainers.filter((item) => item.posicion < selectedItem.posicion).map((item) => item.posicion)
                    : [],
            } : {
                ...preview,
                transfer_focus: true,
                containers: preview.containers.filter((item) => String(item.id) !== String(selectedItem?.id)),
                preview_position: position,
            };
            transferPreviewTitle.textContent = origin ? 'Vista 3D del origen' : 'Vista previa 3D del destino';
            transferPreviewCaption.textContent = !hasScene ? '' : origin
                ? selectedItem ? 'Posicion ' + selectedItem.posicion + (selectedItem.puede_trasladar ? ' · salida libre' : ' · salida bloqueada') : 'Selecciona un equipo para identificar su ubicacion.'
                : 'El contenedor quedara en la posicion ' + position + ' del ' + selectedText(destinoBloque).split(' (')[0] + '.';
            const serialized = JSON.stringify(scene);
            if (transferPreviewCanvas.dataset.scene !== serialized) {
                transferPreviewCanvas.dataset.scene = serialized;
                window.dispatchEvent(new CustomEvent('ingress-location3d:update'));
            }
        }

        function setStep(step) {
            if (step > 0 && !selectedItem?.puede_trasladar) return;
            if (step > 1 && transferSubmit.disabled) return;
            currentStep = step;
            transferPhases.forEach((phase, index) => { phase.hidden = index !== step; });
            transferSteps.forEach((button, index) => {
                button.classList.toggle('active', index === step);
                button.classList.toggle('completed', index < step);
                button.setAttribute('aria-current', index === step ? 'step' : 'false');
            });
            transferPrevious.hidden = step === 0;
            transferNext.hidden = step === 2;
            transferSubmit.hidden = step !== 2;
            originMessage.hidden = step !== 0;
            destinationMessage.hidden = step !== 1;
            (step === 2 ? transferConfirm : transferPreview).appendChild(transferActions);
            if (step === 2) {
                document.getElementById('confirmContainer').textContent = selectedItem.nombre;
                document.getElementById('confirmOrigin').textContent = selectedText(origenPatio) + ' · ' + selectedText(origenBloque).split(' (')[0] + ' · Posicion ' + selectedItem.posicion;
                document.getElementById('confirmDestination').textContent = selectedText(destinoPatio) + ' · ' + selectedText(destinoBloque).split(' (')[0] + ' · Posicion ' + posicionDestino.value;
            }
            updateSummary();
        }

        function blockLabel(item) {
            return 'Bloque ' + item.numero + ' (' + item.capacidad_actual + '/' + item.capacidad_maxima + ')';
        }

        function fillSelect(select, items, placeholder, selectedValue) {
            select.innerHTML = '<option value="">' + placeholder + '</option>';
            items.forEach(function (item) {
                const option = document.createElement('option');
                option.value = item.id;
                option.textContent = blockLabel(item) + (item.isOrigin ? ' - Origen (no disponible)' : '');
                option.disabled = Boolean(item.isOrigin);
                if (!item.isOrigin && String(selectedValue || '') === String(item.id)) option.selected = true;
                select.appendChild(option);
            });
            select.dispatchEvent(new Event('options-updated'));
        }

        function closeEnhancedSelects(exceptDropdown = null) {
            document.querySelectorAll('#transferForm .form-select-dropdown.open').forEach(function (dropdown) {
                if (dropdown === exceptDropdown) return;
                dropdown.classList.remove('open');
                dropdown.querySelector('.form-select-trigger')?.setAttribute('aria-expanded', 'false');
            });
        }

        function enhanceSelect(select) {
            select.classList.add('enhanced-native-select');
            select.tabIndex = -1;
            const dropdown = document.createElement('div');
            dropdown.className = 'form-select-dropdown';

            const trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.className = 'form-select-trigger';
            trigger.setAttribute('aria-haspopup', 'listbox');
            trigger.setAttribute('aria-expanded', 'false');
            trigger.innerHTML = '<span></span><svg class="dropdown-caret" viewBox="0 0 20 20" aria-hidden="true"><path d="M5 7.5L10 12.5L15 7.5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

            const menu = document.createElement('div');
            menu.className = 'form-select-menu';
            menu.setAttribute('role', 'listbox');
            dropdown.append(trigger, menu);
            select.insertAdjacentElement('afterend', dropdown);

            function renderOptions() {
                trigger.querySelector('span').textContent = select.selectedOptions[0]?.textContent || select.options[0]?.textContent || 'Selecciona';
                trigger.disabled = select.disabled;
                if (select.disabled) closeEnhancedSelects();
                menu.replaceChildren();

                Array.from(select.options).forEach(function (option) {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'topbar-dropdown-option';
                    button.textContent = option.textContent;
                    button.dataset.value = option.value;
                    button.setAttribute('role', 'option');
                    button.setAttribute('aria-selected', String(option.selected));
                    button.classList.toggle('selected', option.selected);
                    button.disabled = option.disabled;
                    button.addEventListener('click', function () {
                        select.value = option.value;
                        select.dispatchEvent(new Event('change', { bubbles: true }));
                        closeEnhancedSelects();
                        renderOptions();
                    });
                    menu.appendChild(button);
                });
            }

            trigger.addEventListener('click', function () {
                const shouldOpen = !dropdown.classList.contains('open');
                closeEnhancedSelects(dropdown);
                dropdown.classList.toggle('open', shouldOpen);
                trigger.setAttribute('aria-expanded', String(shouldOpen));
            });
            trigger.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') closeEnhancedSelects();
            });
            select.addEventListener('change', renderOptions);
            select.addEventListener('options-updated', renderOptions);
            renderOptions();
        }

        function syncBlockAvailability(patioSelect, blockSelect, allowed = true) {
            blockSelect.disabled = !allowed || !patioSelect.value || blockSelect.dataset.loading === 'true'
                || !Array.from(blockSelect.options).some((option) => option.value && !option.disabled);
            blockSelect.dispatchEvent(new Event('options-updated'));
        }

        async function loadBlocks(patioSelect, blockSelect, callback) {
            const patioId = patioSelect.value;
            const selected = blockSelect.dataset.selected || blockSelect.value;
            blockSelect.dataset.loading = 'true';
            blockSelect.disabled = true;
            fillSelect(blockSelect, [], 'Selecciona un bloque');
            if (!patioId) {
                blockSelect.dataset.loading = 'false';
                if (callback) await callback();
                updateSummary();
                return;
            }
            try {
                const response = await fetch('{{ url('/traslados/bloques') }}/' + patioId);
                if (!response.ok) throw new Error('No se pudieron cargar los bloques.');
                const blocks = await response.json();
                if (patioSelect.value !== patioId) return;
                const options = blocks.map((block) => ({
                    ...block,
                    isOrigin: blockSelect === destinoBloque && patioId === origenPatio.value &&
                        String(block.id) === origenBloque.value,
                }));
                fillSelect(blockSelect, options, 'Selecciona un bloque', selected);
                blockSelect.dataset.selected = '';
                if (callback) await callback();
                if (blockSelect === origenBloque && destinoPatio.value) {
                    await loadBlocks(destinoPatio, destinoBloque, loadPosition);
                }
            } catch (error) {
                (blockSelect === origenBloque ? originMessage : destinationMessage).textContent = error.message;
            } finally {
                if (patioSelect.value === patioId) {
                    blockSelect.dataset.loading = 'false';
                    updateSummary();
                }
            }
        }

        async function loadContainers() {
            const blockId = origenBloque.value;
            clearSelection();
            currentContainers = [];
            originPreview = null;
            containerCount.textContent = '';
            if (!blockId) {
                containerList.innerHTML = '<p class="empty-cell">Selecciona un patio y un bloque de origen.</p>';
                refreshPreview();
                return;
            }
            containerList.innerHTML = '<p class="empty-cell">Cargando contenedores...</p>';
            try {
                const response = await fetch('{{ url('/traslados/contenedores') }}/' + blockId);
                if (!response.ok) throw new Error('No se pudieron cargar los contenedores.');
                const containers = await response.json();
                if (origenBloque.value !== blockId) return;
                currentContainers = containers;
                const free = currentContainers.filter(function (item) { return item.puede_trasladar; }).length;
                containerCount.textContent = free + ' libres de ' + currentContainers.length;
                renderContainers();
                const preselected = currentContainers.find((item) => String(item.id) === pendingSelectionId);
                if (preselected) {
                    selectContainer(preselected, true);
                    pendingSelectionId = '';
                }
                const preview = await fetchBlockPreview(blockId).catch(() => null);
                if (origenBloque.value !== blockId) return;
                originPreview = preview;
                refreshPreview();
            } catch (error) {
                containerList.innerHTML = '<p class="empty-cell">No se pudieron cargar los contenedores.</p>';
                originMessage.textContent = error.message;
            }
        }

        function renderContainers() {
            if (!currentContainers.length) {
                containerList.innerHTML = '<p class="empty-cell">No hay contenedores activos en este bloque.</p>';
                return;
            }

            containerList.innerHTML = currentContainers.map(function (item) {
                return `
                    <button type="button" class="transfer-item ${item.puede_trasladar ? '' : 'blocked'} ${String(item.id) === containerIdInput.value ? 'selected' : ''}" data-id="${item.id}" aria-pressed="${String(item.id) === containerIdInput.value}" ${selectionGate.busy || String(item.id) === containerIdInput.value ? 'disabled' : ''}>
                        <span class="transfer-item-position">P${item.posicion}</span>
                        <span class="transfer-item-body"><strong>${escapeHtml(item.nombre)}</strong><small>${item.puede_trasladar ? 'Libre para traslado' : escapeHtml(item.motivo_bloqueo)}</small></span>
                        <span class="transfer-item-status">${item.puede_trasladar ? 'Libre' : 'Bloqueado'}</span>
                    </button>
                `;
            }).join('');
        }

        function selectContainer(item, loaded = false) {
            if (String(selectedItem?.id) === String(item.id)) return;
            if (!loaded) return selectionGate.run(() => selectContainer(item, true));
            selectedItem = item;
            containerIdInput.value = item.id;
            filterInput.value = item.nombre;
            closeSearchResults();
            selectedName.textContent = item.nombre;
            selectedLocation.textContent = selectedText(origenPatio) + ' · ' + selectedText(origenBloque).split(' (')[0] + ' · Posicion ' + item.posicion;
            selectedLocation.dataset.position = item.posicion;
            document.querySelectorAll('.transfer-item').forEach(function (button) {
                const selected = String(button.dataset.id) === String(item.id);
                button.classList.toggle('selected', selected);
                button.setAttribute('aria-pressed', String(selected));
                button.disabled = selectionGate.busy || selected;
            });
            updateSummary();
        }

        async function loadPosition() {
            const blockId = destinoBloque.value;
            posicionDestino.value = '';
            destinationPreview = null;
            if (!blockId) {
                updateSummary();
                return;
            }
            try {
                const response = await fetch('{{ url('/traslados/posicion') }}/' + blockId);
                const data = await response.json();
                if (destinoBloque.value !== blockId) return;
                if (data.posicion) posicionDestino.value = data.posicion;
                updateSummary();
                const preview = await fetchBlockPreview(blockId).catch(() => null);
                if (destinoBloque.value !== blockId) return;
                destinationPreview = preview;
            } catch (error) {
                destinationMessage.textContent = 'No se pudo calcular la posicion.';
            }
            updateSummary();
        }

        function closeSearchResults() {
            globalSearchResults.hidden = true;
            filterInput.setAttribute('aria-expanded', 'false');
        }

        function renderSearchResults() {
            if (!searchResults.length) {
                globalSearchResults.innerHTML = '<p class="empty-cell">No hay contenedores activos con ese numero en esta sucursal.</p>';
            } else {
                globalSearchResults.innerHTML = searchResults.map((item) => `
                    <button type="button" class="transfer-search-result" role="option" data-id="${item.id}" ${selectionGate.busy || String(item.id) === String(selectedItem?.id) ? 'disabled' : ''}>
                        <span><strong>${escapeHtml(item.nombre)}</strong><small>${escapeHtml(item.patio_nombre)} · Bloque ${escapeHtml(item.bloque_numero)} · Posicion ${item.posicion}</small></span>
                        <em class="${item.puede_trasladar ? '' : 'blocked'}">${item.puede_trasladar ? 'Libre' : 'Bloqueado'}</em>
                    </button>
                `).join('');
            }
            globalSearchResults.hidden = false;
            filterInput.setAttribute('aria-expanded', 'true');
        }

        function selectSearchResult(item) {
            if (String(selectedItem?.id) === String(item.id)) return;
            return selectionGate.run(() => loadSearchSelection(item));
        }

        async function loadSearchSelection(item) {
            searchRequestId++;
            clearTimeout(searchTimer);
            closeSearchResults();
            filterInput.value = item.nombre;
            pendingSelectionId = String(item.id);
            origenPatio.value = String(item.patio_id);
            origenPatioHidden.value = origenPatio.value;
            origenPatio.dispatchEvent(new Event('options-updated'));
            origenBloque.dataset.selected = String(item.bloque_id);
            currentContainers = [];
            originPreview = null;
            clearSelection();
            containerList.innerHTML = '<p class="empty-cell">Cargando contenedor seleccionado...</p>';
            await loadBlocks(origenPatio, origenBloque, loadContainers);
        }

        filterInput.addEventListener('input', function () {
            clearTimeout(searchTimer);
            const query = filterInput.value.trim();
            if (selectedItem && query.toUpperCase() !== selectedItem.nombre) {
                clearSelection();
                renderContainers();
            }
            const requestId = ++searchRequestId;
            searchResults = [];
            if (query.length < 2) {
                closeSearchResults();
                return;
            }
            searchTimer = setTimeout(async function () {
                try {
                    const response = await fetch('{{ route('transfers.search') }}?query=' + encodeURIComponent(query));
                    if (!response.ok) throw new Error();
                    const results = await response.json();
                    if (requestId !== searchRequestId) return;
                    searchResults = results;
                    renderSearchResults();
                } catch (error) {
                    if (requestId !== searchRequestId) return;
                    globalSearchResults.innerHTML = '<p class="empty-cell">No se pudo buscar en la sucursal.</p>';
                    globalSearchResults.hidden = false;
                    filterInput.setAttribute('aria-expanded', 'true');
                }
            }, 220);
        });

        filterInput.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') closeSearchResults();
            if (event.key === 'Enter' && !globalSearchResults.hidden && searchResults.length) {
                event.preventDefault();
                selectSearchResult(searchResults[0]);
            }
        });

        globalSearchResults.addEventListener('click', function (event) {
            const button = event.target.closest('[data-id]');
            const item = searchResults.find((result) => String(result.id) === button?.dataset.id);
            if (item) selectSearchResult(item);
        });

        containerList.addEventListener('click', function (event) {
            const button = event.target.closest('.transfer-item');
            if (!button) return;
            const item = currentContainers.find(function (row) {
                return String(row.id) === String(button.dataset.id);
            });
            if (item) selectContainer(item);
        });

        origenPatio.addEventListener('change', function () {
            pendingSelectionId = '';
            filterInput.value = '';
            closeSearchResults();
            origenPatioHidden.value = origenPatio.value;
            origenBloque.dataset.selected = '';
            clearSelection();
            loadBlocks(origenPatio, origenBloque, loadContainers);
        });
        origenBloque.addEventListener('change', function () {
            pendingSelectionId = '';
            filterInput.value = '';
            closeSearchResults();
            loadContainers();
            if (destinoPatio.value) loadBlocks(destinoPatio, destinoBloque, loadPosition);
        });
        destinoPatio.addEventListener('change', function () {
            destinoPatioHidden.value = destinoPatio.value;
            destinoBloque.dataset.selected = '';
            updateSummary();
            loadBlocks(destinoPatio, destinoBloque, loadPosition);
        });
        destinoBloque.addEventListener('change', loadPosition);
        transferPrevious.addEventListener('click', function () { setStep(currentStep - 1); });
        transferNext.addEventListener('click', function () { setStep(currentStep + 1); });
        transferSteps.forEach(function (button) {
            button.addEventListener('click', function () { setStep(Number(button.dataset.transferStep)); });
        });
        document.getElementById('transferForm').addEventListener('submit', function (event) {
            if (currentStep !== 2 || transferSubmit.disabled) event.preventDefault();
        });
        document.querySelectorAll('#transferForm select').forEach(enhanceSelect);
        document.addEventListener('click', function (event) {
            if (!event.target.closest('#transferForm .form-select-dropdown')) closeEnhancedSelects();
            if (!event.target.closest('.transfer-search')) closeSearchResults();
        });

        if (origenPatio.value) {
            loadBlocks(origenPatio, origenBloque, loadContainers);
        }
        if (!origenPatio.value && destinoPatio.value) loadBlocks(destinoPatio, destinoBloque, loadPosition);
        setStep(0);
        });
    </script>
@endsection
