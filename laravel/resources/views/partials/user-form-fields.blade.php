<fieldset class="user-editor-section" aria-label="Datos del usuario">
    <div class="user-field-grid">
        <label><span>Usuario</span><input type="text" name="username" value="{{ $values['username'] ?? '' }}" required maxlength="50" autocomplete="off" placeholder="Nombre de usuario" aria-describedby="usernameFeedback"><small id="usernameFeedback" data-field-feedback="username" aria-live="polite"></small></label>
        <label><span>Correo electrónico</span><input type="email" name="email" value="{{ $values['email'] ?? '' }}" required maxlength="100" autocomplete="off" placeholder="usuario@empresa.com" aria-describedby="emailFeedback" @readonly(isset($userModel) && $userModel->exists && ((int) $userModel->rol_id === 1 || (int) $userModel->id === (int) auth()->id()))><small id="emailFeedback" data-field-feedback="email" aria-live="polite"></small></label>
    </div>
</fieldset>
<fieldset class="user-editor-section" aria-label="Acceso">
    <div class="record-select-fields" data-user-access-fields>
        <label><span>Rol</span>
        @if(isset($userModel) && $userModel->exists && (int)$userModel->rol_id === 1)
            <input type="hidden" name="rol_id" value="1">
            <input type="text" value="{{ $userModel->rol->nombre ?? 'Super Admin' }}" readonly aria-describedby="roleFeedback">
            <small id="roleFeedback" data-field-feedback="rol_id">Este rol esta protegido y no se puede cambiar.</small>
        @else
        <select name="rol_id" data-admin-select required aria-describedby="roleFeedback">
            <option value="">Selecciona un rol</option>
            @foreach($roles as $role)<option value="{{ $role->id }}" @selected((int)($values['rol_id'] ?? 0) === (int)$role->id)>{{ $role->nombre }}</option>@endforeach
        </select><small id="roleFeedback" data-field-feedback="rol_id" aria-live="polite"></small>
        @endif
        </label>
        <label data-user-branch-field @if((int)($values['rol_id'] ?? 0) === 5) hidden @endif><span data-user-branch-title>Sucursal</span><select name="sucursal_id" data-admin-select @required((int)($values['rol_id'] ?? 0) !== 5) @disabled((int)($values['rol_id'] ?? 0) === 5) aria-describedby="branchFeedback">
            <option value="">Selecciona una sucursal</option>
            @foreach($branchOptions as $branch)<option value="{{ $branch->id }}" @selected((int)($values['sucursal_id'] ?? 0) === (int)$branch->id)>{{ $branch->nombre }}</option>@endforeach
        </select><small id="branchFeedback" data-field-feedback="sucursal_id" aria-live="polite"></small></label>
        <div class="user-external-branch-notice" data-user-branch-notice @if((int)($values['rol_id'] ?? 0) !== 5) hidden @endif>
            <strong>Sucursales de trabajo</strong>
            <p>Se configuran en Accesos y permisos, despues de elegir la empresa auditora. Este usuario puede trabajar en una o varias sucursales autorizadas.</p>
        </div>
    </div>
</fieldset>
