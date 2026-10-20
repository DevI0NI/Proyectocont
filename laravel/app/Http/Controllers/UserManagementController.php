<?php

namespace App\Http\Controllers;

use App\Models\Rol;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\AccessPermissions;
use App\Services\AdministrationTable;
use App\Services\ExternalAuditAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserManagementController extends Controller
{
    private const ACTIVE = 1;

    private const INACTIVE = 2;

    public function index(Request $request): View
    {
        $currentUser = $request->user();
        $showInactive = $request->query('estado') === 'inactivos';
        $showAll = $request->query('estado') === 'todos';

        if (($showInactive || $showAll) && (int) $currentUser->rol_id !== 1) {
            abort(403, 'Solo el Super Admin puede ver usuarios inactivos.');
        }

        $roleFilter = max(0, (int) $request->query('rol_filtro', 0));
        $usersQuery = $this->baseVisibleUsersQuery($currentUser, $showInactive);
        if ($showAll) {
            $usersQuery = User::query()->with(['rol', 'sucursal'])->whereIn('estado_id', [self::ACTIVE, self::INACTIVE]);
        }

        if ($roleFilter > 0) {
            $usersQuery->where('rol_id', $roleFilter);
        }

        return view('users.index', [
            'sucursal' => $this->activeBranch($request),
            'sucursales' => Sucursal::activas()->orderBy('nombre')->get(),
            'users' => AdministrationTable::paginate($usersQuery, $request, 'users'),
            'tableColumns' => AdministrationTable::columns('users'),
            'roles' => $this->availableRoles($currentUser),
            'branchOptions' => $this->availableBranches($currentUser),
            'roleFilter' => $roleFilter,
            'showInactive' => $showInactive,
            'userState' => $showAll ? 'todos' : ($showInactive ? 'inactivos' : 'activos'),
        ]);
    }

    public function create(Request $request): View
    {
        return view('users.form', [
            'sucursal' => $this->activeBranch($request),
            'sucursales' => Sucursal::activas()->orderBy('nombre')->get(),
            'userModel' => new User,
            'roles' => $this->availableRoles($request->user()),
            'branchOptions' => $this->availableBranches($request->user()),
            'mode' => 'create',
            'auditCompanies' => $this->auditCompanies(),
            'auditCompanyBranches' => app(ExternalAuditAccess::class)->eligibleBranchMap(),
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $data = $this->validatedUserData($request, null, true);

        $inactiveMatch = User::inactive()
            ->where(function ($query) use ($data): void {
                $query->where('username', $data['username'])
                    ->orWhere('email', $data['email']);
            })
            ->first();

        if ($inactiveMatch && (int) $request->user()->rol_id === 1) {
            if ($request->expectsJson()) {
                throw ValidationException::withMessages(['username' => 'Existe una cuenta inactiva con estos datos. Selecciona Inactivos y usa Reactivar para recuperar esa misma cuenta.']);
            }

            return redirect()
                ->route('users.edit', ['user' => $inactiveMatch, 'reactivar' => 1])
                ->with('status', 'Existe una cuenta inactiva con esos datos. Puedes reactivarla desde aqui.');
        }

        if ($inactiveMatch) {
            if ($request->expectsJson()) {
                throw ValidationException::withMessages(['username' => 'Ya existe un usuario inactivo con estos datos. Solo un Super Admin puede reactivarlo.']);
            }

            return back()
                ->withErrors(['username' => 'Ya existe un usuario inactivo con estos datos. Solo un Super Admin puede reactivarlo desde usuarios inactivos.'])
                ->withInput();
        }

        DB::transaction(function () use ($data): void {
            $user = User::create([
                'username' => $data['username'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'rol_id' => $data['rol_id'],
                'sucursal_id' => $data['sucursal_id'],
                'estado_id' => self::ACTIVE,
                'permissions' => $data['permissions'] ?? null,
                'external_audit_company_id' => $data['external_audit_company_id'],
                'external_access_level' => $data['external_access_level'],
            ]);
            $user->authorizedExternalBranches()->sync($data['external_branches']);
        });

        return $this->saved($request, 'Usuario creado correctamente.');
    }

    public function edit(Request $request, User $user): View|JsonResponse
    {
        if ((int) $user->estado_id === self::INACTIVE && (int) $request->user()->rol_id !== 1) {
            abort(403, 'Solo el Super Admin puede reactivar usuarios.');
        }

        $this->authorizeManageTarget($request->user(), $user, false);
        if ($request->expectsJson()) {
            return response()->json([
                'username' => $user->username,
                'email' => $user->email,
                'rol_id' => $user->rol_id,
                'sucursal_id' => $user->sucursal_id,
                'roles' => $this->availableRoles($request->user(), $user)->map(fn ($role) => ['id' => $role->id, 'name' => $role->nombre]),
                'branches' => $this->availableBranches($request->user())->map(fn ($branch) => ['id' => $branch->id, 'name' => $branch->nombre]),
                'external_audit_company_id' => $user->external_audit_company_id,
                'external_access_level' => $user->external_access_level,
                'external_branches' => $user->authorizedExternalBranches()->pluck('sucursales.id'),
            ]);
        }

        return view('users.form', [
            'sucursal' => $this->activeBranch($request),
            'sucursales' => Sucursal::activas()->orderBy('nombre')->get(),
            'userModel' => $user,
            'roles' => $this->availableRoles($request->user(), $user),
            'branchOptions' => $this->availableBranches($request->user()),
            'mode' => $request->boolean('reactivar') ? 'reactivate' : 'edit',
            'auditCompanies' => $this->auditCompanies($user),
            'auditCompanyBranches' => app(ExternalAuditAccess::class)->eligibleBranchMap(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse|JsonResponse
    {
        $this->authorizeManageTarget($request->user(), $user, false);

        $reactivate = $request->boolean('reactivate');
        $data = $this->validatedUserData($request, $user, $reactivate);

        if ($reactivate && (int) $request->user()->rol_id !== 1) {
            abort(403, 'Solo el Super Admin puede reactivar usuarios.');
        }

        $payload = [
            'username' => $data['username'],
            'email' => $data['email'],
            'rol_id' => $data['rol_id'],
            'sucursal_id' => $data['sucursal_id'],
            'external_audit_company_id' => $data['external_audit_company_id'],
            'external_access_level' => $data['external_access_level'],
        ];
        if (array_key_exists('permissions', $data)) {
            $payload['permissions'] = $data['permissions'];
        }

        if (! empty($data['password'])) {
            $payload['password'] = Hash::make($data['password']);
        }

        if ($reactivate) {
            $payload['estado_id'] = self::ACTIVE;
        }

        DB::transaction(function () use ($user, $payload, $data): void {
            $user->update($payload);
            $user->authorizedExternalBranches()->sync($data['external_branches']);
        });

        if ((int) $request->user()->id === (int) $user->id) {
            $request->session()->put('sucursal_activa', (int) $data['sucursal_id']);
        }

        if ($request->expectsJson()) {
            return $this->saved($request, $reactivate ? 'Usuario reactivado correctamente.' : 'Usuario actualizado correctamente.', (int) $request->user()->id === (int) $user->id);
        }

        return redirect()
            ->route('users.index', $reactivate ? ['estado' => 'inactivos'] : [])
            ->with('status', $reactivate ? 'Usuario reactivado correctamente.' : 'Usuario actualizado correctamente.');
    }

    public function destroy(Request $request, User $user): RedirectResponse|JsonResponse
    {
        $this->authorizeManageTarget($request->user(), $user, true);

        if ((int) $request->user()->id === (int) $user->id) {
            if ($request->expectsJson()) {
                throw ValidationException::withMessages(['delete' => 'No puedes desactivar tu propia cuenta mientras estas conectado.']);
            }

            return back()->withErrors(['delete' => 'No puedes desactivar tu propia cuenta mientras estas conectado.']);
        }

        $user->update(['estado_id' => self::INACTIVE]);

        return $this->saved($request, 'Usuario desactivado correctamente.');
    }

    private function saved(Request $request, string $message, bool $refreshShell = false): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            $request->session()->flash('status', $message);

            return response()->json(['message' => $message, 'refreshShell' => $refreshShell]);
        }

        return redirect()->route('users.index')->with('status', $message);
    }

    public function legacyCheck(Request $request)
    {
        $currentId = (int) $request->input('id', 0);
        if ($currentId > 0) {
            $this->authorizeManageTarget($request->user(), User::findOrFail($currentId), false);
        }
        $field = $request->has('username') ? 'username' : ($request->has('email') ? 'email' : '');
        $value = trim((string) $request->input($field));
        if ($field === 'email') {
            $value = strtolower($value);
        }

        if ($request->expectsJson()) {
            $request->validate($field === 'email'
                ? ['email' => ['required', 'string', 'email:rfc', 'max:100']]
                : ['username' => ['required', 'string', 'max:50', 'regex:/^[\pL ]+$/u']]);
        }

        if ($field === '' || $value === '') {
            return response('error');
        }

        $estado = User::query()
            ->where($field, $value)
            ->when($currentId > 0, fn ($query) => $query->where('id', '!=', $currentId))
            ->orderBy('estado_id')
            ->orderByDesc('id')
            ->value('estado_id');

        if ($request->expectsJson()) {
            return response()->json(['status' => $estado === null ? 'available' : ((int) $estado === self::ACTIVE ? 'exists' : 'inactive')]);
        }

        if ($estado === null) {
            return response('available');
        }

        return response((int) $estado === self::ACTIVE ? 'exists' : 'inactive');
    }

    private function baseVisibleUsersQuery(User $currentUser, bool $showInactive)
    {
        $query = User::query()
            ->with(['rol', 'sucursal'])
            ->where('estado_id', $showInactive ? self::INACTIVE : self::ACTIVE);

        if (in_array((int) $currentUser->rol_id, [2, 3], true)) {
            $query->where('sucursal_id', $currentUser->sucursal_id)
                ->where('rol_id', '>', 2);
        }

        return $query;
    }

    private function validatedUserData(Request $request, ?User $target, bool $passwordRequired): array
    {
        $request->merge([
            'username' => trim((string) $request->input('username')),
            'email' => strtolower(trim((string) $request->input('email'))),
        ]);
        $currentUser = $request->user();
        $minRole = (int) $currentUser->rol_id === 1 ? ((int) $target?->rol_id === 1 ? 1 : 2) : 3;
        $isExternal = (int) $request->input('rol_id') === 5;
        if ($isExternal) {
            $request->merge(['sucursal_id' => (int) $currentUser->rol_id === 1 ? null : $target?->sucursal_id]);
        }
        $allowedBranchIds = $this->availableBranches($currentUser)->pluck('id')->all();
        $usernameUniqueRule = $target
            ? Rule::unique('users', 'username')->ignore($target->id)
            : Rule::unique('users', 'username')->where('estado_id', self::ACTIVE);
        $emailUniqueRule = $target
            ? Rule::unique('users', 'email')->ignore($target->id)
            : Rule::unique('users', 'email')->where('estado_id', self::ACTIVE);

        $data = $request->validate([
            'username' => [
                'required',
                'string',
                'max:50',
                'regex:/^[\pL ]+$/u',
                $usernameUniqueRule,
            ],
            'email' => [
                'required',
                'email:rfc',
                'max:100',
                $emailUniqueRule,
            ],
            'password' => [
                $passwordRequired ? 'required' : 'nullable',
                'string',
                'min:8',
                'max:64',
                'regex:/^(?=.*[A-Z])(?=.*[0-9])(?=.*[\pP\pS]).+$/u',
                'confirmed',
            ],
            'rol_id' => [
                'required',
                'integer',
                Rule::exists('roles', 'id')->where('estado_id', self::ACTIVE),
                'min:'.$minRole,
                Rule::in($this->availableRoles($currentUser, $target)->pluck('id')->all()),
            ],
            'sucursal_id' => [
                $isExternal ? 'nullable' : 'required',
                'integer',
                Rule::in($allowedBranchIds),
            ],
        ], [
            'username.required' => 'El nombre de usuario es obligatorio.',
            'username.regex' => 'El nombre de usuario solo debe contener letras.',
            'username.unique' => 'El nombre de usuario ya esta ocupado.',
            'email.required' => 'El correo electronico es obligatorio.',
            'email.email' => 'El formato del correo electronico es incorrecto.',
            'email.unique' => 'El correo electronico ya esta registrado.',
            'password.required' => 'La contrasena es obligatoria.',
            'password.min' => 'La contrasena debe tener al menos 8 caracteres.',
            'password.max' => 'La contrasena no puede superar 64 caracteres.',
            'password.regex' => 'La contrasena debe incluir una mayuscula, un numero y un simbolo.',
            'password.confirmed' => 'La confirmacion de la contrasena no coincide.',
            'rol_id.required' => 'Selecciona un rol y una sucursal validos.',
            'rol_id.exists' => 'Selecciona un rol y una sucursal validos.',
            'rol_id.min' => 'No tienes permisos para asignar este rol.',
            'rol_id.in' => (int) $target?->rol_id === 1 ? 'El rol Super Admin esta protegido y no se puede cambiar.' : 'No tienes permisos para asignar este rol.',
            'sucursal_id.required' => 'Selecciona un rol y una sucursal validos.',
            'sucursal_id.in' => 'No puedes asignar usuarios a otra sucursal.',
        ]);

        $data['rol_id'] = (int) $data['rol_id'];
        $data['sucursal_id'] = (int) $data['sucursal_id'];
        $data = array_replace($data, $this->validatedExternalAccess($request, $target, $data));
        if ($request->hasAny(['permission_mode', 'permissions'])) {
            abort_unless((int) $currentUser->rol_id === 1, 403, 'Solo el Super Admin puede asignar permisos.');
            $access = $request->validate([
                'permission_mode' => ['required', Rule::in(['role', 'custom'])],
                'permissions' => ['sometimes', 'array', 'max:'.count(AccessPermissions::keys())],
                'permissions.*' => ['required', 'string', 'distinct', Rule::in(AccessPermissions::keys())],
            ]);
            if ($access['permission_mode'] === 'custom' && ! in_array($data['rol_id'], [2, 3], true)) {
                throw ValidationException::withMessages(['permissions' => 'Este rol tiene accesos fijos y no admite permisos internos personalizados.']);
            }
            $data['permissions'] = $access['permission_mode'] === 'role' ? null : AccessPermissions::normalize($access['permissions'] ?? []);
        } elseif ($target && (int) $target->rol_id !== $data['rol_id']) {
            $data['permissions'] = null;
        }

        return $data;
    }

    private function validatedExternalAccess(Request $request, ?User $target, array $data): array
    {
        $fields = ['external_audit_company_id', 'external_access_level', 'external_branches'];
        if ($request->hasAny($fields)) {
            abort_unless((int) $request->user()->rol_id === 1, 403, 'Solo el Super Admin puede configurar accesos de empresas externas.');
        }
        if ($data['rol_id'] !== 5) {
            return ['external_audit_company_id' => null, 'external_access_level' => null, 'external_branches' => []];
        }
        if ((int) $request->user()->rol_id !== 1) {
            abort_unless((int) $target?->rol_id === 5, 403, 'Solo el Super Admin puede habilitar cuentas externas.');
            abort_unless($data['sucursal_id'] === (int) $target->sucursal_id, 403, 'Solo el Super Admin puede cambiar las sucursales de una cuenta externa.');

            return ['external_audit_company_id' => $target->external_audit_company_id, 'external_access_level' => $target->external_access_level, 'external_branches' => $target->authorizedExternalBranches()->pluck('sucursales.id')->all()];
        }
        $companyIds = $this->auditCompanies($target)->pluck('id')->all();
        $eligibleBranches = app(ExternalAuditAccess::class)->eligibleBranchMap()[(int) $request->input('external_audit_company_id')] ?? [];
        $allowedBranches = array_intersect($eligibleBranches, $this->availableBranches($request->user())->pluck('id')->all());
        $access = $request->validate([
            'external_audit_company_id' => ['required', 'integer', Rule::in($companyIds)],
            'external_access_level' => ['required', Rule::in(['inspector', 'supervisor'])],
            'external_branches' => ['required', 'array', 'min:1'],
            'external_branches.*' => ['required', 'integer', 'distinct', Rule::in($allowedBranches)],
        ], [
            'external_audit_company_id.required' => 'Selecciona la empresa auditora del usuario externo.',
            'external_audit_company_id.in' => 'Selecciona una empresa auditora activa.',
            'external_access_level.required' => 'Selecciona la responsabilidad del usuario externo.',
            'external_access_level.in' => 'Selecciona Inspector o Supervisor.',
            'external_branches.required' => 'Selecciona al menos una sucursal autorizada.',
            'external_branches.*.in' => 'La sucursal no esta autorizada para esta auditora en una naviera activa.',
            'external_branches.*.distinct' => 'No repitas una sucursal autorizada.',
        ]);
        $access['external_audit_company_id'] = (int) $access['external_audit_company_id'];
        $access['external_branches'] = array_map('intval', $access['external_branches']);
        $access['sucursal_id'] = $target && in_array((int) $target->sucursal_id, $access['external_branches'], true)
            ? (int) $target->sucursal_id : min($access['external_branches']);

        return $access;
    }

    private function auditCompanies(?User $target = null)
    {
        return DB::table('external_audit_companies')->where(function ($query) use ($target): void {
            $query->where('status', 1);
            if ($target?->external_audit_company_id) {
                $query->orWhere('id', $target->external_audit_company_id);
            }
        })->orderBy('name')->get();
    }

    private function authorizeManageTarget(User $currentUser, User $target, bool $deleting): void
    {
        if ((int) $currentUser->rol_id === 1) {
            if ($deleting && (int) $target->rol_id === 1) {
                throw ValidationException::withMessages(['delete' => 'No puedes desactivar una cuenta Super Admin.']);
            }

            return;
        }

        if (! in_array((int) $currentUser->rol_id, [2, 3], true) || ! $currentUser->hasPermission('users.manage')) {
            abort(403);
        }

        if ((int) $target->sucursal_id !== (int) $currentUser->sucursal_id) {
            abort(403);
        }

        if ((int) $target->rol_id <= 2) {
            abort(403, $deleting ? 'No tienes permisos para desactivar administradores.' : 'No tienes permisos para editar administradores.');
        }
    }

    private function availableRoles(User $user, ?User $target = null)
    {
        if ((int) $target?->rol_id === 1) {
            return Rol::active()->where('id', 1)->get();
        }

        $minRole = (int) $user->rol_id === 1 ? ((int) $target?->rol_id === 1 ? 1 : 2) : 3;

        return Rol::active()->where('id', '>=', $minRole)->orderBy('id')->get();
    }

    private function availableBranches(User $user)
    {
        if ((int) $user->rol_id === 1) {
            return Sucursal::activas()->orderBy('nombre')->get();
        }

        return Sucursal::activas()->where('id', $user->sucursal_id)->orderBy('nombre')->get();
    }

    private function activeBranch(Request $request): ?Sucursal
    {
        $branchId = (int) $request->session()->get('sucursal_activa');

        return $branchId > 0 ? Sucursal::find($branchId) : null;
    }
}
