@php
    $editing = isset($record['id']);
    $selectedBranches = array_map('intval', old('branches', $record['branches'] ?? []));
    $selectedAuditors = array_map('intval', old('auditors', $record['auditors'] ?? []));
    $selectedAssignments = old('auditor_assignments', $record['auditor_assignments'] ?? []);
    $savedPhone = preg_replace('/[\s()\-]+/', '', old('phone', $record['phone'] ?? ''));
    $savedPhone = preg_replace('/^00/', '+', $savedPhone);
    $phoneCountry = old('phone_country', str_starts_with($savedPhone, '+503') ? '503' : '502');
    $phoneNational = old('phone_national', preg_replace('/^\+50[23]/', '', $savedPhone));
@endphp
<section class="page-heading standard-records-heading user-editor-heading"><div><span class="eyebrow">{{ $definition['title'] }}</span><h1>{{ ($editing ? 'Editar ' : 'Nueva ').$definition['singular'] }}</h1></div></section>
<section class="form-panel container-form-shell user-editor shipping-line-editor" data-shipping-lines>
    @if($errors->any())<script type="application/json" data-shipping-line-errors>@json($errors->all())</script>@endif
    <form method="POST" class="record-form container-form" data-shipping-line-form data-return-url="{{ route('catalogs.index', $catalog) }}" action="{{ $editing ? route('catalogs.update', ['catalog' => $catalog, 'record' => $record['id']]) : route('catalogs.store', $catalog) }}" novalidate>
        @csrf @if($editing) @method('PATCH') @endif
        @if($catalog === 'navieras')
        <div class="shipping-line-editor-tabs" role="tablist" aria-label="Apartados de la naviera">
            <button type="button" id="shippingLineGeneralTab" role="tab" aria-selected="true" aria-controls="shippingLineGeneralPanel" data-shipping-line-tab="general">Datos generales</button>
            <button type="button" id="shippingLineOperationTab" role="tab" aria-selected="false" aria-controls="shippingLineOperationPanel" tabindex="-1" data-shipping-line-tab="operation">Operacion y auditoras</button>
        </div>
        <div id="shippingLineGeneralPanel" role="tabpanel" aria-labelledby="shippingLineGeneralTab" data-shipping-line-panel="general">
        @endif
        <div class="user-field-grid">
            <label><span>Nombre</span><input name="name" type="text" required maxlength="120" value="{{ old('name', $record['name'] ?? '') }}" placeholder="Nombre de la {{ $definition['singular'] }}" autocomplete="organization"></label>
            <label><span class="user-field-title">Contacto <small class="table-muted">(opcional)</small></span><input name="contact_name" type="text" maxlength="120" value="{{ old('contact_name', $record['contact_name'] ?? '') }}" autocomplete="name"></label>
            <label class="shipping-line-feedback-field"><span class="user-field-title">Correo electronico <small class="table-muted">(opcional)</small></span><input name="email" type="email" maxlength="150" value="{{ old('email', $record['email'] ?? '') }}" autocomplete="email" placeholder="contacto@empresa.com" data-shipping-line-email aria-describedby="shippingLineEmailFeedback"><small id="shippingLineEmailFeedback" data-email-feedback data-field-feedback aria-live="polite" hidden></small></label>
            <div class="shipping-line-phone-field shipping-line-feedback-field record-select-fields">
                <span class="user-field-title">Telefono <small class="table-muted">(opcional)</small></span>
                <div class="shipping-line-phone-control">
                    <label class="shipping-line-phone-country"><span class="sr-only">Pais del telefono</span><select name="phone_country" data-admin-select autocomplete="tel-country-code"><option value="502" @selected($phoneCountry === '502')>+502</option><option value="503" @selected($phoneCountry === '503')>+503</option></select></label>
                    <label class="shipping-line-phone-number"><span class="sr-only">Numero de telefono</span><input name="phone_national" type="tel" inputmode="numeric" maxlength="8" value="{{ $phoneNational }}" autocomplete="tel-national" placeholder="55551234" data-shipping-line-phone aria-describedby="shippingLinePhoneFeedback"></label>
                </div>
                <small id="shippingLinePhoneFeedback" data-phone-feedback data-field-feedback aria-live="polite" hidden></small>
            </div>
        </div>
        <label class="shipping-line-description-field"><span class="user-field-title">Descripcion <small class="table-muted">(opcional)</small></span><textarea name="description" rows="2" maxlength="255">{{ old('description', $record['description'] ?? '') }}</textarea></label>
        @if($catalog === 'navieras')
        </div>
        <div id="shippingLineOperationPanel" role="tabpanel" aria-labelledby="shippingLineOperationTab" data-shipping-line-panel="operation" hidden>
        <div class="shipping-line-relations">
            <fieldset class="shipping-line-selection" data-relation-selection>
                <legend>Sucursales de operacion</legend>
                <button type="button" class="shipping-line-select-trigger" data-relation-trigger data-placeholder="Seleccionar sucursales" aria-expanded="false" aria-controls="shippingLineBranches" aria-label="Sucursales de operacion"><span data-relation-summary>Seleccionar sucursales</span><span data-admin-icon="chevron-down" aria-hidden="true"></span></button>
                <div class="shipping-line-select-menu" id="shippingLineBranches" popover="manual">
                <div class="shipping-line-selection-toolbar"><input type="search" placeholder="Buscar sucursal" aria-label="Buscar sucursal" data-relation-search maxlength="120"><small class="table-muted" data-relation-count aria-live="polite"></small></div>
                <div class="shipping-line-choice-list">
                    @foreach($branches as $branch)<label class="shipping-line-choice" data-relation-choice><input type="checkbox" name="branches[]" value="{{ $branch->id }}" @checked(in_array((int)$branch->id, $selectedBranches, true))><span>{{ $branch->nombre }}@if((int)$branch->estado_id === 2) <small class="table-muted">(inactiva)</small>@endif</span></label>@endforeach
                </div>
                <p class="table-muted" data-relation-empty hidden>{{ $branches->isEmpty() ? 'No hay sucursales disponibles.' : 'Sin coincidencias.' }}</p>
                </div>
            </fieldset>
        </div>
        <section class="shipping-line-authorizations">
            <h2>Auditoras por sucursal <small class="table-muted">(opcional)</small></h2>
            <p class="table-muted" data-authorization-empty @if($selectedBranches) hidden @endif>Sin sucursales seleccionadas.</p>
            <div class="shipping-line-authorization-list">
            @foreach($branches as $branch)
                @php
                    $branchAuditors = array_map('intval', $selectedAssignments[$branch->id] ?? []);
                    $existingAuditors = array_map('intval', $record['auditor_assignments'][$branch->id] ?? []);
                    $availableAuditors = $auditors->filter(fn ($auditor) => (int)$auditor->status === 1 || in_array((int)$auditor->id, $existingAuditors, true));
                @endphp
                <div class="shipping-line-selection shipping-line-authorization" role="group" aria-labelledby="authorizationBranch{{ $branch->id }}" data-relation-selection data-authorization-branch="{{ $branch->id }}" @if(!in_array((int)$branch->id, $selectedBranches, true)) hidden @endif>
                    <div class="shipping-line-authorization-identity"><strong id="authorizationBranch{{ $branch->id }}">{{ $branch->nombre }}@if((int)$branch->estado_id === 2) <small class="table-muted">(inactiva)</small>@endif</strong><small class="table-muted" data-authorization-count>Sin asignar</small></div>
                    <button type="button" class="shipping-line-select-trigger" data-relation-trigger data-placeholder="Seleccionar auditoras" aria-expanded="false" aria-controls="shippingLineAuditors{{ $branch->id }}" aria-label="Auditoras de {{ $branch->nombre }}"><span data-relation-summary>Seleccionar auditoras</span><span data-admin-icon="chevron-down" aria-hidden="true"></span></button>
                    <div class="shipping-line-select-menu" id="shippingLineAuditors{{ $branch->id }}" popover="manual">
                        <div class="shipping-line-selection-toolbar"><input type="search" placeholder="Buscar auditora" aria-label="Buscar auditora en {{ $branch->nombre }}" data-relation-search maxlength="120"><small class="table-muted" data-relation-count aria-live="polite"></small></div>
                        <div class="shipping-line-choice-list">
                        @foreach($availableAuditors as $auditor)
                            <label class="shipping-line-choice" data-relation-choice><input type="checkbox" name="auditor_assignments[{{ $branch->id }}][]" value="{{ $auditor->id }}" @checked(in_array((int)$auditor->id, $branchAuditors, true)) @disabled(!in_array((int)$branch->id, $selectedBranches, true) || ((int)$branch->estado_id !== 1 && !in_array((int)$auditor->id, $existingAuditors, true))) data-authorization-allowed="{{ (int)$branch->estado_id === 1 || in_array((int)$auditor->id, $existingAuditors, true) ? 'true' : 'false' }}"><span>{{ $auditor->name }}@if((int)$auditor->status === 2) <small class="table-muted">(inactiva)</small>@endif</span></label>
                        @endforeach
                        </div>
                        <p class="table-muted" data-relation-empty hidden>{{ $availableAuditors->isEmpty() ? 'No hay auditoras disponibles.' : 'Sin coincidencias.' }}</p>
                    </div>
                </div>
            @endforeach
            </div>
            @if(!empty($record['auditors']))
            <fieldset class="shipping-line-selection shipping-line-unscoped" data-relation-selection>
                <legend>Pendientes de definir sucursal</legend>
                <button type="button" class="shipping-line-select-trigger" data-relation-trigger data-placeholder="Sin vinculos pendientes" aria-expanded="false" aria-controls="shippingLineUnscoped" aria-label="Auditoras sin sucursal definida"><span data-relation-summary>Sin vinculos pendientes</span><span data-admin-icon="chevron-down" aria-hidden="true"></span></button>
                <div class="shipping-line-select-menu" id="shippingLineUnscoped" popover="manual">
                    <div class="shipping-line-selection-toolbar"><input type="search" placeholder="Buscar auditora" aria-label="Buscar auditora pendiente" data-relation-search maxlength="120"><small class="table-muted" data-relation-count aria-live="polite"></small></div>
                    <div class="shipping-line-choice-list">@foreach($auditors->whereIn('id', $record['auditors']) as $auditor)<label class="shipping-line-choice" data-relation-choice><input type="checkbox" name="auditors[]" value="{{ $auditor->id }}" @checked(in_array((int)$auditor->id, $selectedAuditors, true))><span>{{ $auditor->name }}@if((int)$auditor->status === 2) <small class="table-muted">(inactiva)</small>@endif</span></label>@endforeach</div>
                    <p class="table-muted" data-relation-empty hidden>Sin coincidencias.</p>
                </div>
            </fieldset>
            @endif
        </section>
        </div>
        @endif
        <div class="form-actions-row container-edit-actions"><a class="container-edit-button cancel" href="{{ route('catalogs.index', $catalog) }}">Cancelar</a><button type="submit" class="container-edit-button save">{{ $editing ? 'Guardar cambios' : 'Crear '.$definition['singular'] }}</button></div>
    </form>
</section>
