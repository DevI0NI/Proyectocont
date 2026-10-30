@php
    $accessService = \App\Services\AccessPermissions::class;
    $selectedPermissions = old('permissions', $userModel->exists ? $accessService::effective($userModel) : []);
    $permissionMode = old('permission_mode', $userModel->permissions === null ? 'role' : 'custom');
    $mayAssign = (int)auth()->user()->rol_id === 1;
    $permissionDefaults = [1 => $accessService::defaults(1), 2 => $accessService::defaults(2), 3 => $accessService::defaults(3), 4 => [], 5 => []];
    $modules = $accessService::modules();
    $modules['pyp'] = ['Auditoría externa', ['view' => 'Consultar', 'assign' => 'Asignar revisiones externas']];
    $modules['statuses'][0] = 'Estatus de contenedores';
    $permissionGroups = [
        'Operacion' => ['dashboard', 'containers', 'pyp', 'maintenance', 'transfers', 'dispatches', 'reports'],
        'Parametros' => ['users', 'unit-types', 'statuses', 'yard-layout'],
        'Catalogos' => ['navieras', 'auditoras', 'repuestos', 'reparaciones'],
    ];
    $permissionDescriptions = [
        'dashboard.view' => 'Ver los indicadores y el resumen de operaciones de la sucursal.',
        'containers.view' => 'Ver el listado, los datos, la ubicacion y el historial de los contenedores. No permite modificar informacion.',
        'containers.create' => 'Registrar el ingreso de un contenedor nuevo o procesar su reingreso.',
        'containers.edit' => 'Modificar los datos de un contenedor. No autoriza traslados, despachos ni cambios de estados tecnicos o disponibilidad IPSA por si solo.',
        'containers.technical' => 'Cambiar los estados tecnicos de unidad y estructura del contenedor. Tambien habilita consultar y modificar su informacion.',
        'containers.ipsa' => 'Cambiar la disponibilidad IPSA del contenedor. Tambien habilita consultar y modificar su informacion.',
        'pyp.view' => 'Consultar los contenedores y resultados de auditoría externa de la sucursal.',
        'pyp.assign' => 'Confirmar o cambiar la auditora indicada por la naviera. No permite emitir el veredicto externo.',
        'maintenance.view' => 'Recibir solicitudes de reparación de la sucursal y consultar su diagnóstico, materiales y evidencias. No permite modificar el trabajo del mecánico ni aprobar estados técnicos.',
        'maintenance.confirm' => 'Confirmar la entrega del mecánico para habilitar la revisión externa. No modifica automáticamente los estados técnicos ni el dictamen de la auditora.',
        'transfers.view' => 'Consultar los contenedores disponibles en la vista de traslados, sin confirmar movimientos.',
        'transfers.operate' => 'Confirmar traslados de contenedores entre ubicaciones permitidas. Tambien habilita consultar la vista de traslados.',
        'dispatches.view' => 'Consultar los contenedores en la vista de despachos, sin confirmar su salida.',
        'dispatches.operate' => 'Confirmar el despacho y la salida de contenedores cuando cumplan las reglas operativas. Tambien habilita consultar la vista de despachos.',
        'reports.view' => 'Abrir la vista de reportes y consultar sus resultados.',
        'reports.export' => 'Generar y descargar los reportes disponibles. Tambien habilita consultar la vista de reportes.',
        'users.manage' => 'Crear, editar y desactivar usuarios dentro del alcance permitido por tu rol y sucursal. Asignar permisos y reactivar cuentas sigue reservado al Super Admin.',
        'yard-layout.manage' => 'Crear y modificar patios y bloques, ajustar capacidades y desactivarlos cuando las reglas operativas lo permitan.',
    ];
    $selectedExternalBranches = array_map('intval', old('external_branches', $userModel->exists ? $userModel->authorizedExternalBranches()->pluck('sucursales.id')->all() : []));
@endphp
<div data-user-permissions data-may-assign="{{ $mayAssign ? 'true' : 'false' }}">
    <script type="application/json" data-permission-defaults>@json($permissionDefaults)</script>
    <div class="user-access-state" data-fixed-access hidden>
        <span class="user-access-state-icon" data-access-state-icon aria-hidden="true"></span>
        <div class="user-access-state-copy">
            <h2 data-access-state-title></h2>
            <p data-access-state-description></p>
        </div>
    </div>
    <div class="user-external-access" data-user-external-access data-shipping-lines hidden>
        <script type="application/json" data-external-company-branches>@json($auditCompanyBranches)</script>
        @if($mayAssign)
        <div class="record-select-fields">
            <label><span>Empresa auditora</span><select id="externalAuditCompany" name="external_audit_company_id" data-searchable-select data-search-label="Empresa auditora" data-search-placeholder="Buscar auditora por nombre" data-search-empty="No hay auditoras que coincidan." aria-describedby="externalCompanyFeedback">
                <option value="">Selecciona una auditora</option>
                @foreach($auditCompanies as $company)<option value="{{ $company->id }}" @selected((int)old('external_audit_company_id', $userModel->external_audit_company_id) === (int)$company->id)>{{ $company->name }}@if((int)$company->status !== 1) (Inactiva) @endif</option>@endforeach
            </select><small id="externalCompanyFeedback" data-field-feedback="external_audit_company_id" aria-live="polite"></small></label>
            <label><span>Responsabilidad</span><select name="external_access_level" data-admin-select aria-describedby="externalLevelFeedback">
                <option value="inspector" @selected(old('external_access_level', $userModel->external_access_level ?: 'inspector') === 'inspector')>Inspector</option>
                <option value="supervisor" @selected(old('external_access_level', $userModel->external_access_level) === 'supervisor')>Supervisor</option>
            </select><small id="externalLevelFeedback" data-field-feedback="external_access_level" aria-live="polite"></small></label>
        </div>
        <fieldset class="shipping-line-selection" data-relation-selection>
            <legend>Sucursales autorizadas</legend>
            <button type="button" class="shipping-line-select-trigger" data-relation-trigger data-placeholder="Seleccionar sucursales" aria-expanded="false" aria-controls="externalUserBranches" aria-label="Sucursales autorizadas"><span data-relation-summary>Seleccionar sucursales</span><span data-admin-icon="chevron-down" aria-hidden="true"></span></button>
            <div class="shipping-line-select-menu" id="externalUserBranches" popover="manual">
                <div class="shipping-line-selection-toolbar"><input type="search" placeholder="Buscar sucursal" aria-label="Buscar sucursal autorizada" data-relation-search maxlength="120"><small class="table-muted" data-relation-count aria-live="polite"></small></div>
                <div class="shipping-line-choice-list">
                    @foreach($branchOptions as $branch)<label class="shipping-line-choice" data-relation-choice><input type="checkbox" name="external_branches[]" value="{{ $branch->id }}" @checked(in_array((int)$branch->id, $selectedExternalBranches, true))><span>{{ $branch->nombre }}</span></label>@endforeach
                </div>
                <p class="table-muted" data-relation-empty hidden>Sin coincidencias.</p>
            </div>
            <small class="table-muted" data-external-branch-status hidden></small>
            <small data-field-feedback="external_branches" aria-live="polite"></small>
        </fieldset>
        @else
            <dl class="user-external-summary"><dt>Empresa auditora</dt><dd>{{ $auditCompanies->firstWhere('id', $userModel->external_audit_company_id)->name ?? 'Pendiente de vincular' }}</dd><dt>Responsabilidad</dt><dd>{{ $userModel->external_access_level === 'supervisor' ? 'Supervisor' : ($userModel->external_access_level === 'inspector' ? 'Inspector' : 'Pendiente') }}</dd><dt>Sucursales autorizadas</dt><dd>{{ $userModel->authorizedExternalBranches->pluck('nombre')->join(', ') ?: 'Pendientes de autorizar' }}</dd></dl>
        @endif
    </div>
    <div data-internal-permissions>
        <div class="user-permission-header">
        <div class="shipping-line-editor-tabs user-permission-tabs" role="tablist" aria-label="Secciones de permisos">
            @foreach($permissionGroups as $group => $moduleKeys)
                <button type="button" role="tab" id="permission-tab-{{ $loop->index }}" aria-controls="permission-group-{{ $loop->index }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}" tabindex="{{ $loop->first ? '0' : '-1' }}" data-permission-tab="{{ $loop->index }}">{{ $group }}</button>
            @endforeach
        </div>
        @if($mayAssign)
        <div class="user-permission-toolbar">
            <label class="user-permission-mode"><input type="checkbox" role="switch" data-custom-permissions @checked($permissionMode === 'custom')><span>Personalizar permisos</span></label>
            <input type="hidden" name="permission_mode" value="{{ $permissionMode }}">
            <button type="button" class="user-permission-reset" data-reset-permissions aria-label="Restablecer permisos del rol" title="Restablecer permisos del rol"><span data-permission-reset-icon aria-hidden="true"></span><span>Restablecer</span></button>
        </div>
        @endif
        </div>
        <div class="user-permission-groups">
        @foreach($permissionGroups as $group => $moduleKeys)
            <section class="user-permission-group" role="tabpanel" id="permission-group-{{ $loop->index }}" aria-labelledby="permission-tab-{{ $loop->index }}" data-permission-panel="{{ $loop->index }}" @if(!$loop->first) hidden @endif>
                @foreach($moduleKeys as $module)
                    @php
                        [$label, $actions] = $modules[$module];
                    @endphp
                    <div class="user-permission-module" role="group" aria-labelledby="permission-module-{{ $module }}">
                        <h3 id="permission-module-{{ $module }}">{{ $label }}</h3>
                        <div class="user-permission-actions">
                        @foreach($actions as $key => $action)
                            @php
                                $description = $permissionDescriptions[$module.'.'.$key] ?? ($key === 'view'
                                    ? 'Ver el listado y consultar la informacion de '.$label.'. No permite crear, editar ni desactivar registros.'
                                    : 'Crear, editar y desactivar registros de '.$label.', segun las restricciones del sistema. Tambien habilita consultar esta vista.');
                            @endphp
                            <div class="user-permission-item" data-permission-help-item data-help-title="{{ $label.' - '.$action }}" data-help-description="{{ $description }}">
                                <label class="user-permission-choice"><input type="checkbox" name="permissions[]" value="{{ $module.'.'.$key }}" data-permission-module="{{ $module }}" data-permission-action="{{ $key }}" @checked(in_array($module.'.'.$key, $selectedPermissions, true)) @disabled(!$mayAssign || $permissionMode !== 'custom')><span>{{ $action }}</span></label>
                                <button type="button" class="user-permission-help" data-permission-help aria-label="Acerca de {{ $action }} en {{ $label }}" aria-controls="userPermissionHelp" aria-expanded="false"></button>
                            </div>
                        @endforeach
                        </div>
                    </div>
                @endforeach
            </section>
        @endforeach
        </div>
    </div>
    <div id="userPermissionHelp" class="user-permission-tooltip" role="tooltip" popover="manual"><strong data-permission-help-title></strong><p data-permission-help-description></p></div>
</div>
