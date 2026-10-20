@extends('layouts.app')

@php
    $returnParams = request()->only([
        'search', 'per_page', 'page', 'sort', 'order', 'patio_id', 'bloque_id',
        'posicion', 'nombre', 'estatus_unidad', 'estatus_box', 'estatus_ipsa',
        'estatus_pyp', 'fecha_desde', 'fecha_hasta', 'vista',
    ]);
@endphp

@section('content')
    <section class="page-heading">
        <div>
            <span class="eyebrow">Operacion</span>
            <h1>Nuevo ingreso</h1>
            <p>Registra un equipo nuevo o procesa un reingreso.</p>
        </div>
        <div class="heading-side-info">
            <span>Sucursal activa</span>
            <strong>{{ $sucursal->nombre ?? 'Sucursal activa' }}</strong>
        </div>
    </section>

    @if ($errors->any())
        <div class="form-error page-error">{{ $errors->first() }}</div>
    @endif

    <section class="form-panel container-form-shell">
        <div class="container-form-timeline" aria-label="Fases del ingreso">
            <button type="button" class="timeline-step active" data-step-target="0">
                <span>01</span>
                <strong>Equipo</strong>
            </button>
            <button type="button" class="timeline-step" data-step-target="1">
                <span>02</span>
                <strong>Estados</strong>
            </button>
            <button type="button" class="timeline-step" data-step-target="2">
                <span>03</span>
                <strong>Transporte</strong>
            </button>
            <button type="button" class="timeline-step" data-step-target="3">
                <span>04</span>
                <strong>Ubicacion</strong>
            </button>
        </div>

        <form method="POST" action="{{ route('ingresos.store') }}" class="record-form wide-form container-form has-steps" id="containerIngressForm" novalidate>
            @csrf

            <div class="container-form-section active" data-form-step="0">
                <div class="container-form-section-head">
                    <span>01</span>
                    <div>
                        <h2>Datos del equipo</h2>
                        <p>Identifica el contenedor y define sus caracteristicas base.</p>
                    </div>
                </div>

                <div class="container-form-grid">
                    <label>
                        <span>Fecha de ingreso</span>
                        <input type="date" name="fecha_ingreso" value="{{ old('fecha_ingreso', now()->format('Y-m-d')) }}" required>
                    </label>

                    <label>
                        <span>Numero de equipo</span>
                        <input type="text" id="codigo_contenedor" name="codigo_contenedor" value="{{ old('codigo_contenedor') }}" maxlength="11" pattern="^[A-Z0-9]{11}$" required class="container-code-input">
                        <small id="containerCheckMessage" class="container-check-message"></small>
                    </label>

                    <label>
                        <span>Tipo de unidad</span>
                        <select id="tipo_unidad" name="tipo_unidad" required>
                            <option value="">Selecciona un tipo</option>
                            @foreach ($tiposUnidad as $type)
                                <option value="{{ $type->id }}" @selected((int) old('tipo_unidad') === (int) $type->id)>{{ $type->nombre }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label>
                        <span>Modelo</span>
                        <select id="modelo" name="modelo" required>
                            <option value="">Selecciona un modelo</option>
                        </select>
                    </label>

                    <label class="iso-code-field">
                        <span>ISO Code</span>
                        <select id="iso_code" name="iso_code" required data-iso-search>
                            <option value="">Selecciona un ISO Code</option>
                            @foreach($isoCodes as $code => $iso)<option value="{{ $code }}" @selected(old('iso_code') === $code)>{{ $code }} - {{ $iso['name'] }}</option>@endforeach
                        </select>
                    </label>

                    <label>
                        <span>Marca</span>
                        <input type="text" name="marca" value="{{ old('marca') }}" maxlength="80" placeholder="CIMC, Maersk, Triton..." required>
                    </label>
                </div>
            </div>

            <div class="container-form-section" data-form-step="1">
                <div class="container-form-section-head">
                    <span>02</span>
                    <div>
                        <h2>Estados tecnicos</h2>
                        <p>Registra la condicion de la unidad, estructura y disponibilidad IPSA.</p>
                    </div>
                </div>

                <div class="container-form-grid states-form-grid">
                    <label>
                        <span>Estado de unidad</span>
                        <select name="estado_unidad" required>
                            <option value="">Selecciona un estado</option>
                            @foreach ($estadosUnidad as $status)
                                <option value="{{ $status->id }}" data-requires-observations="{{ $status->requiresObservations() ? 'true' : 'false' }}" @selected((int) old('estado_unidad') === (int) $status->id)>{{ $status->nombre }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label>
                        <span>Estado estructura</span>
                        <select name="status_box" required>
                            <option value="">Selecciona un estado</option>
                            @foreach ($estadosBox as $status)
                                <option value="{{ $status->id }}" data-requires-observations="{{ $status->requiresObservations() ? 'true' : 'false' }}" @selected((int) old('status_box') === (int) $status->id)>{{ $status->nombre }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label>
                        <span>Estatus IPSA</span>
                        <select name="estatus_ipsa" required>
                            <option value="">Selecciona estatus</option>
                            @foreach ($estatusIpsa as $status)
                                <option value="{{ $status->id }}" @selected((int) old('estatus_ipsa') === (int) $status->id)>{{ $status->nombre }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="status-note">
                        <span>Observaciones unidad</span>
                        <textarea name="observaciones_estado" rows="3" maxlength="255">{{ old('observaciones_estado') }}</textarea>
                    </label>

                    <label class="status-note">
                        <span>Observaciones estructura</span>
                        <textarea name="observaciones_box" rows="3" maxlength="255">{{ old('observaciones_box') }}</textarea>
                    </label>
                </div>
            </div>

            <div class="container-form-section" data-form-step="2">
                <div class="container-form-section-head">
                    <span>03</span>
                    <div>
                        <h2>Documentacion y transporte</h2>
                        <p>Datos necesarios para constancias EIR, ticket de ingreso y trazabilidad documental.</p>
                    </div>
                </div>

                <div class="container-form-grid three-columns">
                    @include('partials.container-assignment-fields')

                    <label>
                        <span>Quien lo trajo</span>
                        <input type="text" name="transportista" value="{{ old('transportista') }}" maxlength="120" placeholder="Transportista o empresa" required>
                    </label>

                    <label>
                        <span>Piloto</span>
                        <input type="text" name="conductor" value="{{ old('conductor') }}" maxlength="120" placeholder="Opcional">
                    </label>

                    <div class="plate-field">
                        <div class="plate-field-title">
                            <label for="placa_cabezal">Placa cabezal</label>
                            <span class="plate-help">
                                <button type="button" class="plate-help-trigger" aria-label="Formato de placa del cabezal" aria-describedby="headPlateHelp" data-field-help></button>
                                <span class="plate-help-tooltip" id="headPlateHelp" role="tooltip" hidden>6 a 8 letras o numeros, sin contar espacios o guiones. Prefijo opcional. Ejemplos: C123ABC, C-123ABC o 123456.</span>
                            </span>
                        </div>
                        <input type="text" id="placa_cabezal" name="placa_cabezal" value="{{ old('placa_cabezal') }}" maxlength="15" data-plate-max="{{ \App\Services\TransportPlate::MAX_ALPHANUMERIC_LENGTH }}" pattern="{{ \App\Services\TransportPlate::PATTERN }}" required class="container-code-input">
                    </div>

                    <div class="plate-field">
                        <div class="plate-field-title">
                            <label for="placa_chasis">Placa chasis</label>
                            <span class="plate-help">
                                <button type="button" class="plate-help-trigger" aria-label="Formato de placa del chasis" aria-describedby="chassisPlateHelp" data-field-help></button>
                                <span class="plate-help-tooltip" id="chassisPlateHelp" role="tooltip" hidden>6 a 8 letras o numeros, sin contar espacios o guiones. Prefijo opcional. Ejemplos: TC-12ABC, RE123456 o 123456.</span>
                            </span>
                        </div>
                        <input type="text" id="placa_chasis" name="placa_chasis" value="{{ old('placa_chasis') }}" maxlength="15" data-plate-max="{{ \App\Services\TransportPlate::MAX_ALPHANUMERIC_LENGTH }}" pattern="{{ \App\Services\TransportPlate::PATTERN }}" class="container-code-input">
                    </div>
                    @include('partials.container-assignment-details')
                </div>
            </div>

            <div class="container-form-section" data-form-step="3">
                <div class="container-form-section-head">
                    <span>04</span>
                    <div>
                        <h2>Ubicacion inicial</h2>
                        <p>Selecciona patio y bloque para asignar la posicion disponible.</p>
                    </div>
                </div>

                <div class="container-form-grid three-columns">
                    <label>
                        <span>Patio</span>
                        <select id="patio" name="patio" required>
                            <option value="">Selecciona un patio</option>
                            @foreach ($patios as $patio)
                                <option value="{{ $patio->id }}" @selected((int) old('patio') === (int) $patio->id)>{{ $patio->nombre }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label>
                        <span>Bloque</span>
                        <select id="bloque" name="bloque" required>
                            <option value="">Selecciona un bloque</option>
                        </select>
                    </label>

                    <label>
                        <span>Posicion</span>
                        <input type="number" id="posicion" name="posicion" value="{{ old('posicion') }}" min="1" required readonly>
                    </label>
                </div>

                <div class="ingress-location3d-panel" id="ingressLocation3dPanel">
                    <div class="ingress-location3d-head">
                        <div>
                            <strong>Vista previa 3D de ubicacion</strong>
                            <span id="ingressLocation3dCaption">Selecciona un bloque para previsualizar la posicion del nuevo contenedor.</span>
                        </div>
                        <small>Preview</small>
                    </div>
                    <div class="ingress-location3d-stage">
                        <canvas class="ingress-location3d" id="ingressLocation3d" data-scene="{}" aria-label="Vista previa 3D de ubicacion"></canvas>
                        <div class="ingress-location3d-tooltip" hidden>
                            <strong data-ingress-tooltip-position></strong>
                            <span data-ingress-tooltip-name></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-actions-row field-wide container-form-actions container-edit-actions">
                <button type="button" class="button container-edit-button previous" id="prevStepButton">Anterior</button>
                <button type="button" class="button container-edit-button next" id="nextStepButton">Siguiente</button>
                <button type="button" class="button container-edit-button save" id="saveIngressButton">Guardar ingreso</button>
                <a href="{{ route('containers.index', $returnParams) }}" class="button container-edit-button cancel" id="cancelIngressButton">Cancelar</a>
            </div>
        </form>
    </section>

    <dialog class="container-exit-dialog" id="cancelIngressDialog" role="alertdialog" aria-labelledby="cancelIngressTitle" aria-describedby="cancelIngressDescription">
        <span class="container-exit-dialog-kicker">Ingreso sin guardar</span>
        <h2 id="cancelIngressTitle">¿Cancelar nuevo ingreso?</h2>
        <p id="cancelIngressDescription">Los datos que ingresaste no se guardarán.</p>
        <div class="container-exit-dialog-actions">
            <button type="button" class="button container-exit-stay" id="stayIngressButton">Seguir registrando</button>
            <a href="{{ route('containers.index', $returnParams) }}" class="button container-exit-leave">Salir sin guardar</a>
        </div>
    </dialog>

    <script>
        const oldModelo = @json(old('modelo'));
        const oldBloque = @json(old('bloque'));
        const oldPatio = @json(old('patio'));
        const modeloSelect = document.getElementById('modelo');
        const tipoSelect = document.getElementById('tipo_unidad');
        const patioSelect = document.getElementById('patio');
        const bloqueSelect = document.getElementById('bloque');
        const posicionInput = document.getElementById('posicion');
        const ingressLocation3d = document.getElementById('ingressLocation3d');
        const ingressLocation3dCaption = document.getElementById('ingressLocation3dCaption');
        const containerInput = document.getElementById('codigo_contenedor');
        const containerMessage = document.getElementById('containerCheckMessage');
        const isoInput = document.getElementById('iso_code');
        const headPlateInput = document.getElementById('placa_cabezal');
        const chassisPlateInput = document.getElementById('placa_chasis');
        const ingressForm = document.getElementById('containerIngressForm');
        const formSteps = Array.from(document.querySelectorAll('[data-form-step]'));
        const timelineSteps = Array.from(document.querySelectorAll('[data-step-target]'));
        const prevStepButton = document.getElementById('prevStepButton');
        const nextStepButton = document.getElementById('nextStepButton');
        const saveIngressButton = document.getElementById('saveIngressButton');
        const cancelIngressButton = document.getElementById('cancelIngressButton');
        const cancelIngressDialog = document.getElementById('cancelIngressDialog');
        const stayIngressButton = document.getElementById('stayIngressButton');
        let hasUserChanges = @json($errors->any());
        let currentStep = 0;
        let containerCheckTimer = null;
        let containerMessageTimer = null;
        let currentBlocks = [];
        let currentBlockPreview = null;

        function stepFields(stepIndex) {
            return Array.from(formSteps[stepIndex].querySelectorAll('input, select, textarea'));
        }

        function validateStep(stepIndex) {
            clearStepErrors(stepIndex);

            const invalidField = stepFields(stepIndex).find(function (field) {
                return !field.checkValidity();
            });

            if (!invalidField) return true;

            setFieldError(invalidField);

            if (invalidField.classList.contains('enhanced-native-select')) {
                const trigger = invalidField.parentElement.querySelector('.form-select-trigger');
                trigger?.focus();
                return false;
            }

            invalidField.focus();
            return false;
        }

        function clearStepErrors(stepIndex) {
            stepFields(stepIndex).forEach(clearFieldError);
        }

        function validationMessageFor(field) {
            if (field.name === 'codigo_contenedor' && field.validationMessage && field.dataset.state === 'invalid') {
                return field.validationMessage;
            }

            if (field.name === 'codigo_contenedor' && field.validationMessage && field.dataset.state === 'pending') {
                return field.validationMessage;
            }

            if (field.validity.valueMissing) {
                return field.tagName === 'SELECT' ? 'Selecciona una opcion.' : 'Completa este campo.';
            }

            if (field.validity.patternMismatch) {
                if (field.name === 'codigo_contenedor') return 'Usa 11 caracteres: letras mayusculas y numeros.';
                if (field.name === 'iso_code') return 'Usa un ISO Code valido, por ejemplo 22G1.';
                if (field.name === 'placa_cabezal' || field.name === 'placa_chasis') return 'Revisa el formato de la placa.';
            }

            if (field.validity.rangeUnderflow || field.validity.rangeOverflow) {
                return 'El valor esta fuera del rango permitido.';
            }

            return 'Revisa este campo.';
        }

        function fieldErrorSlot(field) {
            const owner = field.closest('label') || field.parentElement;
            let slot = owner.querySelector('.field-validation-message[data-for="' + field.name + '"]');

            if (!slot) {
                slot = document.createElement('small');
                slot.className = 'field-validation-message';
                slot.dataset.for = field.name;
                owner.appendChild(slot);
            }

            return slot;
        }

        function existingFieldErrorSlot(field) {
            const owner = field.closest('label') || field.parentElement;
            return owner.querySelector('.field-validation-message[data-for="' + field.name + '"]');
        }

        function setFieldError(field) {
            const slot = fieldErrorSlot(field);
            field.dataset.validationState = 'invalid';
            field.parentElement?.querySelector('.form-select-trigger')?.classList.add('invalid');
            slot.textContent = validationMessageFor(field);
            requestAnimationFrame(function () {
                slot.classList.add('is-visible');
            });
        }

        function clearFieldError(field) {
            const slot = existingFieldErrorSlot(field);
            field.dataset.validationState = '';
            field.parentElement?.querySelector('.form-select-trigger')?.classList.remove('invalid');
            if (!slot) return;

            slot.classList.remove('is-visible');

            setTimeout(function () {
                if (!slot.classList.contains('is-visible')) {
                    slot.textContent = '';
                }
            }, 180);
        }

        function showStep(stepIndex) {
            currentStep = Math.max(0, Math.min(stepIndex, formSteps.length - 1));

            formSteps.forEach(function (section, index) {
                section.classList.toggle('active', index === currentStep);
            });

            timelineSteps.forEach(function (button, index) {
                button.classList.toggle('active', index === currentStep);
                button.classList.toggle('completed', index < currentStep);
                button.setAttribute('aria-current', index === currentStep ? 'step' : 'false');
            });

            prevStepButton.hidden = currentStep === 0;
            nextStepButton.hidden = currentStep === formSteps.length - 1;
            saveIngressButton.hidden = currentStep !== formSteps.length - 1;
        }

        function goToStep(stepIndex) {
            if (stepIndex > currentStep) {
                for (let index = currentStep; index < stepIndex; index += 1) {
                    showStep(index);
                    if (!validateStep(index)) return;
                }
            }

            showStep(stepIndex);
        }

        function submitIngress() {
            for (let index = 0; index < formSteps.length; index += 1) {
                showStep(index);
                if (!validateStep(index)) {
                    return;
                }
            }

            ingressForm.submit();
        }

        function fillSelect(select, items, placeholder, selectedValue, labelCallback) {
            select.innerHTML = '<option value="">' + placeholder + '</option>';
            items.forEach(function (item) {
                const option = document.createElement('option');
                option.value = item.id;
                option.textContent = labelCallback ? labelCallback(item) : item.nombre;
                if (String(selectedValue || '') === String(item.id)) option.selected = true;
                select.appendChild(option);
            });
            select.dispatchEvent(new Event('options-updated'));
        }

        async function loadModels() {
            if (!tipoSelect.value) {
                fillSelect(modeloSelect, [], 'Selecciona un modelo');
                return;
            }
            const response = await fetch('{{ url('/ingresos/modelos') }}/' + tipoSelect.value);
            fillSelect(modeloSelect, await response.json(), 'Selecciona un modelo', oldModelo);
        }

        async function loadBlocks() {
            posicionInput.value = '';
            if (!patioSelect.value) {
                fillSelect(bloqueSelect, [], 'Selecciona un bloque');
                currentBlocks = [];
                currentBlockPreview = null;
                renderLocation3dPreview();
                return;
            }
            const response = await fetch('{{ url('/ingresos/bloques') }}/' + patioSelect.value);
            currentBlocks = await response.json();
            fillSelect(bloqueSelect, currentBlocks, 'Selecciona un bloque', oldBloque, function (item) {
                return 'Bloque ' + item.numero + ' (' + item.capacidad_actual + '/' + item.capacidad_maxima + ')';
            });
            if (bloqueSelect.value) loadPosition();
        }

        async function loadPosition() {
            posicionInput.value = '';
            currentBlockPreview = null;
            renderLocation3dPreview();
            if (!bloqueSelect.value) return;
            try {
                const [positionResponse, previewResponse] = await Promise.all([
                    fetch('{{ url('/ingresos/posicion') }}/' + bloqueSelect.value),
                    fetch('{{ url('/ingresos/bloque-preview') }}/' + bloqueSelect.value),
                ]);
                const data = await positionResponse.json();
                if (data.posicion) posicionInput.value = data.posicion;
                currentBlockPreview = await previewResponse.json();
            } catch (error) {
                currentBlockPreview = null;
            }
            renderLocation3dPreview();
        }

        function renderLocation3dPreview() {
            const selectedBlock = currentBlocks.find(function (block) {
                return String(block.id) === String(bloqueSelect.value);
            });

            if (!selectedBlock || !currentBlockPreview || !posicionInput.value) {
                ingressLocation3dCaption.textContent = 'Selecciona un bloque para previsualizar la posicion del nuevo contenedor.';
                ingressLocation3d.dataset.scene = '{}';
                window.dispatchEvent(new CustomEvent('ingress-location3d:update'));
                return;
            }

            const nextPosition = Number(posicionInput.value);
            const existing = currentBlockPreview.containers;
            const occupiedSummary = existing.length
                ? ' Ya hay ' + existing.length + ' contenedor' + (existing.length === 1 ? '' : 'es') + ' en el bloque.'
                : ' El bloque esta vacio.';
            ingressLocation3dCaption.textContent = 'El nuevo contenedor se ubicara en la posicion ' + nextPosition + ' del Bloque ' + selectedBlock.numero + '.' + occupiedSummary;
            ingressLocation3d.dataset.scene = JSON.stringify({
                block: currentBlockPreview.block,
                containers: currentBlockPreview.containers,
                preview_position: nextPosition,
            });
            window.dispatchEvent(new CustomEvent('ingress-location3d:update'));
        }

        async function checkContainer() {
            const code = containerInput.value.trim().toUpperCase();
            containerInput.value = code;
            setContainerState('', '');

            if (!code) return;

            if (code.length !== 11 || !/^[A-Z0-9]{11}$/.test(code)) {
                setContainerState('invalid', 'Debe tener 11 caracteres, solo mayusculas y numeros.');
                return;
            }

            containerInput.dataset.state = 'pending';
            containerInput.setCustomValidity('Espera a que se verifique el equipo.');

            try {
                const response = await fetch('{{ url('/ingresos/verificar') }}/' + code);
                const data = await response.json();
                setContainerState(
                    data.status === 'exists' ? 'invalid' : 'valid',
                    data.status === 'exists' ? 'Este equipo ya esta activo.' : 'Disponible para ingreso o reingreso.'
                );
            } catch (error) {
                setContainerState('invalid', 'No se pudo verificar el equipo.');
            }
        }

        function queueContainerCheck() {
            clearTimeout(containerCheckTimer);
            setContainerState('', '');
            containerInput.value = containerInput.value.trim().toUpperCase();

            if (containerInput.value.length !== 11) {
                return;
            }

            containerInput.dataset.state = 'pending';
            containerInput.setCustomValidity('Espera a que se verifique el equipo.');
            containerCheckTimer = setTimeout(checkContainer, 350);
        }

        function setContainerState(state, message) {
            clearTimeout(containerMessageTimer);
            containerInput.dataset.state = state;
            containerInput.setCustomValidity(state === 'invalid' ? message : '');

            if (!message) {
                hideContainerMessage();
                return;
            }

            containerMessage.dataset.state = state;
            containerMessage.textContent = message;
            requestAnimationFrame(function () {
                containerMessage.classList.add('is-visible');
            });

            if (state === 'valid') {
                containerMessageTimer = setTimeout(function () {
                    hideContainerMessage();
                }, 1600);
            }
        }

        function hideContainerMessage() {
            containerMessage.classList.remove('is-visible');
            containerMessageTimer = setTimeout(function () {
                containerMessage.dataset.state = '';
                containerMessage.textContent = '';
            }, 240);
        }

        function closeEnhancedSelects(exceptDropdown = null) {
            document.querySelectorAll('.form-select-dropdown.open').forEach(function (dropdown) {
                if (dropdown === exceptDropdown) return;
                dropdown.classList.remove('open');
                dropdown.querySelector('.form-select-trigger')?.setAttribute('aria-expanded', 'false');
            });
        }

        function enhanceSelect(select) {
            if (select.hasAttribute('data-iso-search') || select.hasAttribute('data-searchable-select')) return;
            if (select.dataset.enhanced === 'true') return;
            select.dataset.enhanced = 'true';
            select.classList.add('enhanced-native-select');

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

            function selectedText() {
                return select.selectedOptions[0]?.textContent || select.options[0]?.textContent || 'Selecciona';
            }

            function renderOptions() {
                trigger.querySelector('span').textContent = selectedText();
                trigger.disabled = select.disabled;
                menu.replaceChildren();

                Array.from(select.options).forEach(function (option) {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'topbar-dropdown-option';
                    button.textContent = option.textContent;
                    button.dataset.value = option.value;
                    button.setAttribute('role', 'option');
                    button.classList.toggle('selected', option.selected);
                    button.disabled = option.disabled;

                    button.addEventListener('click', function () {
                        select.value = option.value;
                        select.dispatchEvent(new Event('change', { bubbles: true }));
                        if (select.checkValidity()) clearFieldError(select);
                        trigger.classList.remove('invalid');
                        closeEnhancedSelects();
                        renderOptions();
                    });

                    menu.appendChild(button);
                });
            }

            trigger.addEventListener('click', function (event) {
                event.preventDefault();
                const shouldOpen = !dropdown.classList.contains('open');
                closeEnhancedSelects(dropdown);
                dropdown.classList.toggle('open', shouldOpen);
                trigger.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
            });

            select.addEventListener('change', renderOptions);
            select.addEventListener('options-updated', renderOptions);
            renderOptions();
        }

        function normalizeUppercase(input) {
            input.value = input.value.trim().toUpperCase();
        }


        tipoSelect.addEventListener('change', loadModels);
        patioSelect.addEventListener('change', loadBlocks);
        bloqueSelect.addEventListener('change', loadPosition);
        containerInput.addEventListener('input', queueContainerCheck);
        containerInput.addEventListener('blur', checkContainer);
        ingressForm.addEventListener('input', function (event) {
            if (event.target.name && event.target.type !== 'hidden') hasUserChanges = true;
            if (event.target.matches('input, textarea') && event.target.checkValidity()) {
                clearFieldError(event.target);
            }
        });
        ingressForm.addEventListener('change', function (event) {
            if (event.target.name && event.target.type !== 'hidden') hasUserChanges = true;
            if (event.target.matches('select, input') && event.target.checkValidity()) {
                clearFieldError(event.target);
            }
        });
        headPlateInput.addEventListener('blur', function () {
            normalizeUppercase(headPlateInput);
        });
        chassisPlateInput.addEventListener('blur', function () {
            normalizeUppercase(chassisPlateInput);
        });
        prevStepButton.addEventListener('click', function () {
            showStep(currentStep - 1);
        });
        nextStepButton.addEventListener('click', function () {
            goToStep(currentStep + 1);
        });
        saveIngressButton.addEventListener('click', submitIngress);
        cancelIngressButton.addEventListener('click', function (event) {
            if (!hasUserChanges) return;
            event.preventDefault();
            cancelIngressDialog.showModal();
            stayIngressButton.focus();
        });
        stayIngressButton.addEventListener('click', () => cancelIngressDialog.close());
        cancelIngressDialog.addEventListener('click', function (event) {
            if (event.target === cancelIngressDialog) cancelIngressDialog.close();
        });
        timelineSteps.forEach(function (button) {
            button.addEventListener('click', function () {
                goToStep(Number(button.dataset.stepTarget));
            });
        });
        document.querySelectorAll('.container-form select').forEach(enhanceSelect);
        document.addEventListener('click', function (event) {
            if (!event.target.closest('.form-select-dropdown, .iso-search-menu')) {
                closeEnhancedSelects();
            }
        });

        if (tipoSelect.value) loadModels();
        if (oldPatio || patioSelect.value) loadBlocks();
        showStep(0);
    </script>
@endsection
