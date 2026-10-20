@extends('layouts.app')

@php
    $errorSteps = [
        ['codigo_contenedor', 'tipo_unidad', 'modelo', 'iso_code', 'marca'],
        ['estado_unidad', 'status_box', 'estatus_ipsa', 'observaciones_estado', 'observaciones_box'],
        ['shipping_line_id', 'audit_company_id', 'audit_reference', 'assignment_reason', 'transportista', 'conductor', 'placa_cabezal', 'placa_chasis'],
    ];
    $initialStep = 0;
    foreach ($errorSteps as $step => $fields) {
        if ($errors->hasAny($fields)) {
            $initialStep = $step;
            break;
        }
    }
@endphp

@section('content')
    <section class="page-heading">
        <div>
            <span class="eyebrow">Modificar contenedor</span>
            <h1>Contenedor {{ $container->nombre }}</h1>
            <p>{{ $container->patio_nombre }}, Bloque {{ $container->bloque_numero }}, Posicion {{ $container->posicion }}</p>
        </div>
    </section>

    @if ($errors->any())
        <div class="form-error page-error">{{ $errors->first() }}</div>
    @endif

    <section class="form-panel container-form-shell">
        <div class="container-form-timeline" aria-label="Secciones del contenedor">
            <button type="button" class="timeline-step active" data-edit-step-target="0"><span>01</span><strong>Equipo</strong></button>
            <button type="button" class="timeline-step" data-edit-step-target="1"><span>02</span><strong>Estados</strong></button>
            <button type="button" class="timeline-step" data-edit-step-target="2"><span>03</span><strong>Transporte</strong></button>
            <button type="button" class="timeline-step" data-edit-step-target="3"><span>04</span><strong>Ubicacion</strong></button>
        </div>

        <form method="POST" action="{{ route('containers.update', ['container' => $container->id]) }}" class="record-form wide-form container-form has-steps" id="containerEditForm" novalidate>
            @csrf
            @method('PATCH')

            <input type="hidden" name="patio_id" value="{{ $backParams['patio_id'] ?? $container->patio_id }}">
            <input type="hidden" name="bloque_id" value="{{ $backParams['bloque_id'] ?? $container->bloque_id }}">

            <div class="container-form-section active" data-edit-step="0">
                <div class="container-form-section-head">
                    <span>01</span>
                    <div>
                        <h2>Datos del equipo</h2>
                    </div>
                </div>

                <div class="container-form-grid">
                    <label>
                        <span>Fecha de ingreso</span>
                        <input type="text" value="{{ $container->fecha_ingreso ? \Carbon\Carbon::parse($container->fecha_ingreso)->format('d/m/Y H:i') : 'N/D' }}" readonly>
                    </label>

                    <label>
                        <span>Numero de equipo</span>
                        <input
                            type="text"
                            id="codigo_contenedor"
                            name="codigo_contenedor"
                            value="{{ old('codigo_contenedor', $container->nombre) }}"
                            maxlength="11"
                            pattern="^[A-Z0-9]{11}$"
                            required
                            class="container-code-input"
                        >
                        <small id="editContainerCheckMessage" class="container-check-message" aria-live="polite"></small>
                    </label>

                    <label>
                        <span>Tipo de unidad</span>
                        <select id="tipo_unidad" name="tipo_unidad" required>
                            @foreach ($tiposUnidad as $type)
                                <option value="{{ $type->id }}" @selected((int) old('tipo_unidad', $container->tipo_unidad_id) === (int) $type->id)>
                                    {{ $type->nombre }}
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label>
                        <span>Modelo</span>
                        <select id="modelo" name="modelo" required>
                            @foreach ($modelos as $model)
                                <option value="{{ $model->id }}" @selected((int) old('modelo', $container->modelo_id) === (int) $model->id)>
                                    {{ $model->nombre }}
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label class="iso-code-field">
                        <span>ISO Code</span>
                        <select id="iso_code" name="iso_code" required data-iso-search>
                            <option value="">Selecciona un ISO Code</option>
                            @foreach($isoCodes as $code => $iso)<option value="{{ $code }}" @selected(old('iso_code', $container->iso_code) === $code)>{{ $code }} - {{ $iso['name'] }}</option>@endforeach
                        </select>
                    </label>

                    <label>
                        <span>Marca</span>
                        <input type="text" name="marca" value="{{ old('marca', $container->marca) }}" maxlength="80" required>
                    </label>
                </div>
            </div>

            <div class="container-form-section" data-edit-step="1">
                <div class="container-form-section-head">
                    <span>02</span>
                    <div>
                        <h2>Estados tecnicos</h2>
                    </div>
                </div>

                <div class="container-form-grid states-form-grid">
                    <label>
                        <span>Estado de unidad</span>
                        @if(!auth()->user()->hasPermission('containers.technical'))<input type="hidden" name="estado_unidad" value="{{ $container->estatus_unidad_id }}" data-requires-observations="{{ $estadosUnidad->firstWhere('id', $container->estatus_unidad_id)?->requiresObservations() ? 'true' : 'false' }}"><span>{{ $estadosUnidad->firstWhere('id', $container->estatus_unidad_id)?->nombre ?? 'Sin definir' }}</span>@else
                        <select name="estado_unidad" required>
                            @foreach ($estadosUnidad as $status)
                                <option value="{{ $status->id }}" data-requires-observations="{{ $status->requiresObservations() ? 'true' : 'false' }}" @selected((int) old('estado_unidad', $container->estatus_unidad_id) === (int) $status->id)>{{ $status->nombre }}</option>
                            @endforeach
                        </select>
                        @endif
                    </label>

                    <label>
                        <span>Estado estructura</span>
                        @if(!auth()->user()->hasPermission('containers.technical'))<input type="hidden" name="status_box" value="{{ $container->estatus_box_id }}" data-requires-observations="{{ $estadosBox->firstWhere('id', $container->estatus_box_id)?->requiresObservations() ? 'true' : 'false' }}"><span>{{ $estadosBox->firstWhere('id', $container->estatus_box_id)?->nombre ?? 'Sin definir' }}</span>@else
                        <select name="status_box" required>
                            @foreach ($estadosBox as $status)
                                <option value="{{ $status->id }}" data-requires-observations="{{ $status->requiresObservations() ? 'true' : 'false' }}" @selected((int) old('status_box', $container->estatus_box_id) === (int) $status->id)>{{ $status->nombre }}</option>
                            @endforeach
                        </select>
                        @endif
                    </label>

                    <label>
                        <span>Estatus IPSA</span>
                        @if(!auth()->user()->hasPermission('containers.ipsa'))<input type="hidden" name="estatus_ipsa" value="{{ $container->estatus_ipsa_id }}"><span>{{ $estatusIpsa->firstWhere('id', $container->estatus_ipsa_id)?->nombre ?? 'Sin definir' }}</span>@else
                        <select name="estatus_ipsa" required>
                            @foreach ($estatusIpsa as $status)
                                <option value="{{ $status->id }}" @selected((int) old('estatus_ipsa', $container->estatus_ipsa_id) === (int) $status->id)>{{ $status->nombre }}</option>
                            @endforeach
                        </select>
                        @endif
                    </label>

                    <label class="status-note">
                        <span>Observaciones unidad</span>
                        <textarea name="observaciones_estado" rows="3" maxlength="255">{{ old('observaciones_estado', $container->observaciones_unidad) }}</textarea>
                    </label>

                    <label class="status-note">
                        <span>Observaciones estructura</span>
                        <textarea name="observaciones_box" rows="3" maxlength="255">{{ old('observaciones_box', $container->observaciones_box) }}</textarea>
                    </label>
                </div>
            </div>

            <div class="container-form-section" data-edit-step="2">
                <div class="container-form-section-head">
                    <span>03</span>
                    <div>
                        <h2>Documentacion y transporte</h2>
                    </div>
                </div>

                <div class="container-form-grid three-columns">
                    @include('partials.container-assignment-fields')

                    <label>
                        <span>Quien lo trajo</span>
                        <input type="text" name="transportista" value="{{ old('transportista', $container->transportista) }}" maxlength="120" required>
                    </label>

                    <label>
                        <span>Piloto</span>
                        <input type="text" name="conductor" value="{{ old('conductor', $container->conductor) }}" maxlength="120" placeholder="Opcional">
                    </label>

                    <div class="plate-field">
                        <div class="plate-field-title">
                            <label for="placa_cabezal">Placa cabezal</label>
                            <span class="plate-help">
                                <button type="button" class="plate-help-trigger" aria-label="Formato de placa del cabezal" aria-describedby="headPlateHelp" data-field-help></button>
                                <span class="plate-help-tooltip" id="headPlateHelp" role="tooltip" hidden>6 a 8 letras o numeros, sin contar espacios o guiones. Prefijo opcional. Ejemplos: C123ABC, C-123ABC o 123456.</span>
                            </span>
                        </div>
                        <input type="text" id="placa_cabezal" name="placa_cabezal" value="{{ old('placa_cabezal', $container->placa_cabezal) }}" maxlength="15" data-plate-max="{{ \App\Services\TransportPlate::MAX_ALPHANUMERIC_LENGTH }}" pattern="{{ \App\Services\TransportPlate::PATTERN }}" required class="container-code-input">
                    </div>

                    <div class="plate-field">
                        <div class="plate-field-title">
                            <label for="placa_chasis">Placa chasis</label>
                            <span class="plate-help">
                                <button type="button" class="plate-help-trigger" aria-label="Formato de placa del chasis" aria-describedby="chassisPlateHelp" data-field-help></button>
                                <span class="plate-help-tooltip" id="chassisPlateHelp" role="tooltip" hidden>6 a 8 letras o numeros, sin contar espacios o guiones. Prefijo opcional. Ejemplos: TC-12ABC, RE123456 o 123456.</span>
                            </span>
                        </div>
                        <input type="text" id="placa_chasis" name="placa_chasis" value="{{ old('placa_chasis', $container->placa_chasis) }}" maxlength="15" data-plate-max="{{ \App\Services\TransportPlate::MAX_ALPHANUMERIC_LENGTH }}" pattern="{{ \App\Services\TransportPlate::PATTERN }}" class="container-code-input">
                    </div>
                    @include('partials.container-assignment-details')
                </div>
            </div>

            <div class="container-form-section" data-edit-step="3">
                <div class="container-form-section-head">
                    <span>04</span>
                    <div>
                        <h2>Ubicacion actual</h2>
                    </div>
                </div>

                <div class="container-form-grid three-columns">
                    <label>
                        <span>Patio</span>
                        <input type="text" value="{{ $container->patio_nombre ?: 'N/D' }}" readonly>
                    </label>
                    <label>
                        <span>Bloque</span>
                        <input type="text" value="Bloque {{ $container->bloque_numero ?: '-' }}" readonly>
                    </label>
                    <label>
                        <span>Posicion</span>
                        <input type="text" value="{{ $container->posicion ?: 'N/D' }}" readonly>
                    </label>
                </div>

            </div>

            <div class="form-actions-row field-wide container-form-actions container-edit-actions">
                <button type="button" class="button container-edit-button previous" id="editPreviousButton">Anterior</button>
                <button type="button" class="button container-edit-button next" id="editNextButton">Siguiente</button>
                <button type="submit" class="button container-edit-button save" id="editSaveButton">Guardar cambios</button>
                <a href="{{ route('containers.show', ['container' => $container->id] + $backParams) }}" class="button container-edit-button cancel" id="editCancelButton">Cancelar</a>
            </div>
        </form>
    </section>

    <dialog class="container-exit-dialog" id="editCancelDialog" role="alertdialog" aria-labelledby="editCancelTitle" aria-describedby="editCancelDescription">
        <span class="container-exit-dialog-kicker">Cambios sin guardar</span>
        <h2 id="editCancelTitle">¿Salir de la modificación?</h2>
        <p id="editCancelDescription">Los cambios realizados en este contenedor se perderán.</p>
        <div class="container-exit-dialog-actions">
            <button type="button" class="button container-exit-stay" id="editStayButton">Seguir editando</button>
            <a href="{{ route('containers.show', ['container' => $container->id] + $backParams) }}" class="button container-exit-leave">Salir sin guardar</a>
        </div>
    </dialog>

    <script>
        const editForm = document.getElementById('containerEditForm');
        const editSections = Array.from(editForm.querySelectorAll('[data-edit-step]'));
        const editTimeline = Array.from(document.querySelectorAll('[data-edit-step-target]'));
        const editPreviousButton = document.getElementById('editPreviousButton');
        const editNextButton = document.getElementById('editNextButton');
        const editSaveButton = document.getElementById('editSaveButton');
        const editCancelButton = document.getElementById('editCancelButton');
        const editCancelDialog = document.getElementById('editCancelDialog');
        const editStayButton = document.getElementById('editStayButton');
        const initialFieldValues = new Map(Array.from(editForm.elements)
            .filter((field) => field.name && field.type !== 'hidden')
            .map((field) => [field, field.value]));
        const tipoSelect = document.getElementById('tipo_unidad');
        const modeloSelect = document.getElementById('modelo');
        const selectedModel = @json((string) old('modelo', $container->modelo_id));
        const originalType = @json((string) $container->tipo_unidad_id);
        const originalCode = @json($container->nombre);
        const codeInput = document.getElementById('codigo_contenedor');
        const codeMessage = document.getElementById('editContainerCheckMessage');
        const isoInput = document.getElementById('iso_code');
        const headPlateInput = document.getElementById('placa_cabezal');
        const chassisPlateInput = document.getElementById('placa_chasis');
        let currentStep = 0;
        let codeCheckTimer = null;
        let codeCheckVersion = 0;
        let codeMessageTimer = null;
        let verifiedCode = '';

        function showStep(index) {
            currentStep = Math.max(0, Math.min(index, editSections.length - 1));
            editSections.forEach((section, sectionIndex) => section.classList.toggle('active', sectionIndex === currentStep));
            editTimeline.forEach((button, buttonIndex) => {
                button.classList.toggle('active', buttonIndex === currentStep);
                button.classList.toggle('completed', buttonIndex < currentStep);
                button.setAttribute('aria-current', buttonIndex === currentStep ? 'step' : 'false');
            });
            editPreviousButton.hidden = currentStep === 0;
            editNextButton.hidden = currentStep === editSections.length - 1;
            editSaveButton.hidden = currentStep !== editSections.length - 1;
        }

        function stepFields(index) {
            return Array.from(editSections[index].querySelectorAll('input[name], select[name], textarea[name]'));
        }

        function clearFieldError(field) {
            field.dataset.validationState = '';
            const message = (field.closest('label') || field.parentElement).querySelector('.field-validation-message');
            if (message) {
                message.classList.remove('is-visible');
                message.textContent = '';
            }
        }

        function showFieldError(field) {
            const label = field.closest('label') || field.parentElement;
            let message = label.querySelector('.field-validation-message');
            if (!message) {
                message = document.createElement('small');
                message.className = 'field-validation-message';
                label.appendChild(message);
            }
            message.textContent = field.validity.customError ? field.validationMessage
                : field.validity.valueMissing ? (field.tagName === 'SELECT' ? 'Selecciona una opcion.' : 'Completa este campo.')
                : field.validity.patternMismatch && field.name === 'iso_code' ? 'Usa un ISO Code valido, por ejemplo 22G1.'
                : field.validity.patternMismatch && (field.name === 'placa_cabezal' || field.name === 'placa_chasis') ? 'Revisa el formato de la placa.'
                : field.validity.patternMismatch ? 'Revisa el formato de este campo.'
                : field.validity.rangeUnderflow || field.validity.rangeOverflow ? 'El valor esta fuera del rango permitido.'
                : 'Revisa este campo.';
            field.dataset.validationState = 'invalid';
            requestAnimationFrame(() => message.classList.add('is-visible'));
            field.focus();
        }

        function hideCodeMessage() {
            clearTimeout(codeMessageTimer);
            codeMessage.classList.remove('is-visible');
            codeMessageTimer = setTimeout(() => {
                codeMessage.dataset.state = '';
                codeMessage.textContent = '';
            }, 240);
        }

        function setCodeState(state, message = '') {
            clearTimeout(codeMessageTimer);
            codeInput.dataset.state = state;
            codeInput.setCustomValidity(state === 'invalid' ? message : state === 'pending' ? 'Espera a que se verifique el equipo.' : '');
            verifiedCode = state === 'valid' ? codeInput.value : '';

            if (!message) {
                hideCodeMessage();
                return;
            }

            codeMessage.dataset.state = state;
            codeMessage.textContent = message;
            requestAnimationFrame(() => codeMessage.classList.add('is-visible'));
            if (state === 'valid') {
                codeMessageTimer = setTimeout(hideCodeMessage, 1600);
            }
        }

        async function verifyCode() {
            clearTimeout(codeCheckTimer);
            codeInput.value = codeInput.value.trim().toUpperCase();
            const code = codeInput.value;
            const version = ++codeCheckVersion;

            if (!code) {
                setCodeState('', '');
                return;
            }
            if (!/^[A-Z0-9]{11}$/.test(code)) {
                setCodeState('invalid', 'Debe tener 11 caracteres, solo mayusculas y numeros.');
                return;
            }
            if (code === originalCode) {
                setCodeState('valid', 'Es el numero actual de este equipo.');
                return;
            }

            setCodeState('pending');
            try {
                const body = new URLSearchParams({ nombre: code, nombre_original: originalCode });
                const response = await fetch('{{ url('/bloques/verificar_contenedor_mod.php') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-CSRF-TOKEN': editForm.querySelector('input[name="_token"]').value,
                    },
                    body,
                });
                if (!response.ok) throw new Error('No se pudo verificar el equipo.');
                const result = (await response.text()).trim();
                if (version !== codeCheckVersion || codeInput.value !== code) return;
                if (result === 'exists') {
                    setCodeState('invalid', 'Este numero ya pertenece a otro contenedor.');
                } else if (result === 'not_exists') {
                    setCodeState('valid', 'Numero disponible para este equipo.');
                } else {
                    throw new Error('Respuesta de verificacion inesperada.');
                }
            } catch (error) {
                if (version === codeCheckVersion && codeInput.value === code) {
                    setCodeState('invalid', 'No se pudo verificar el numero de equipo.');
                }
            }
        }

        function queueCodeCheck() {
            clearTimeout(codeCheckTimer);
            codeCheckVersion += 1;
            codeInput.value = codeInput.value.trim().toUpperCase();
            clearFieldError(codeInput);

            if (!codeInput.value) {
                setCodeState('', '');
            } else if (!/^[A-Z0-9]{11}$/.test(codeInput.value)) {
                setCodeState('invalid', 'Debe tener 11 caracteres, solo mayusculas y numeros.');
            } else if (codeInput.value === originalCode) {
                setCodeState('valid', 'Es el numero actual de este equipo.');
            } else {
                setCodeState('pending');
                codeCheckTimer = setTimeout(verifyCode, 350);
            }
        }

        async function ensureCodeChecked() {
            if (codeInput.dataset.state === 'valid' && verifiedCode === codeInput.value) return;
            await verifyCode();
        }

        async function validateStep(index) {
            if (index === 0) await ensureCodeChecked();
            for (const field of stepFields(index)) {
                clearFieldError(field);
                if (!field.checkValidity()) {
                    showStep(index);
                    if (field === codeInput && codeInput.dataset.state === 'invalid') {
                        field.focus();
                    } else {
                        showFieldError(field);
                    }
                    return false;
                }
            }
            return true;
        }

        async function goToStep(index) {
            if (index > currentStep) {
                for (let step = currentStep; step < index; step += 1) {
                    if (!await validateStep(step)) return;
                }
            }
            showStep(index);
        }

        function fillModels(items, current) {
            modeloSelect.innerHTML = '<option value="">Selecciona un modelo</option>';
            modeloSelect.setCustomValidity('');
            items.forEach(function (item) {
                const option = document.createElement('option');
                option.value = item.id;
                option.textContent = item.nombre;
                if (String(current) === String(item.id)) option.selected = true;
                modeloSelect.appendChild(option);
            });
        }

        async function loadModels(preferredModel = '') {
            const typeId = tipoSelect.value;
            fillModels([], '');
            if (!typeId) return;
            try {
                const response = await fetch('{{ url('/ingresos/modelos') }}/' + typeId);
                if (!response.ok) throw new Error('No se pudieron cargar los modelos.');
                const models = await response.json();
                if (tipoSelect.value === typeId) {
                    fillModels(models, preferredModel);
                }
            } catch (error) {
                modeloSelect.setCustomValidity('No se pudieron cargar los modelos.');
            }
        }

        tipoSelect.addEventListener('change', () => loadModels());
        codeInput.addEventListener('input', queueCodeCheck);
        codeInput.addEventListener('blur', verifyCode);
        headPlateInput.addEventListener('blur', function () {
            headPlateInput.value = headPlateInput.value.trim().toUpperCase();
        });
        chassisPlateInput.addEventListener('blur', function () {
            chassisPlateInput.value = chassisPlateInput.value.trim().toUpperCase();
        });
        editPreviousButton.addEventListener('click', () => showStep(currentStep - 1));
        editNextButton.addEventListener('click', () => goToStep(currentStep + 1));
        editCancelButton.addEventListener('click', (event) => {
            const hasChanges = Array.from(initialFieldValues).some(([field, value]) => field.value !== value);
            if (!hasChanges) return;
            event.preventDefault();
            editCancelDialog.showModal();
            editStayButton.focus();
        });
        editStayButton.addEventListener('click', () => editCancelDialog.close());
        editCancelDialog.addEventListener('click', (event) => {
            if (event.target === editCancelDialog) editCancelDialog.close();
        });
        editTimeline.forEach((button) => button.addEventListener('click', () => goToStep(Number(button.dataset.editStepTarget))));
        editForm.addEventListener('input', (event) => {
            if (event.target.matches('input, textarea')) clearFieldError(event.target);
        });
        editForm.addEventListener('change', (event) => {
            if (event.target.matches('select')) {
                event.target.setCustomValidity('');
                clearFieldError(event.target);
            }
        });
        editForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            for (let step = 0; step < editSections.length; step += 1) {
                showStep(step);
                if (!await validateStep(step)) return;
            }
            HTMLFormElement.prototype.submit.call(editForm);
        });


        if (String(tipoSelect.value) !== originalType) {
            loadModels(selectedModel).then(() => initialFieldValues.set(modeloSelect, modeloSelect.value));
        }

        queueCodeCheck();
        showStep(@json($initialStep));
    </script>
@endsection
