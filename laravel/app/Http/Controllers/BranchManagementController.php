<?php

namespace App\Http\Controllers;

use App\Models\Sucursal;
use App\Services\AdministrationTable;
use App\Services\GuatemalaGeography;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BranchManagementController extends Controller
{
    private const ACTIVE = 1;

    private const INACTIVE = 2;

    public function index(Request $request): View
    {
        $branchState = in_array($request->query('estado'), ['inactivas', 'todas'], true) ? $request->query('estado') : 'activas';
        $query = Sucursal::query();
        if ($branchState !== 'todas') {
            $query->where('estado_id', $branchState === 'inactivas' ? self::INACTIVE : self::ACTIVE);
        }

        return view('branches.index', [
            'sucursal' => $this->activeBranch($request),
            'sucursales' => Sucursal::activas()->orderBy('nombre')->get(),
            'branches' => AdministrationTable::paginate($query, $request, 'branches'),
            'branchState' => $branchState,
            'tableColumns' => AdministrationTable::columns('branches'),
            'geography' => GuatemalaGeography::departments(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('branches.form', [
            'sucursal' => $this->activeBranch($request),
            'sucursales' => Sucursal::activas()->orderBy('nombre')->get(),
            'branch' => new Sucursal,
            'mode' => 'create',
            'geography' => GuatemalaGeography::departments(),
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $data = $this->validatedBranchData($request);

        $inactive = Sucursal::query()
            ->where('nombre', $data['nombre'])
            ->where('estado_id', self::INACTIVE)
            ->latest('id')
            ->first();

        if ($inactive) {
            $inactive->update([
                'nombre' => $data['nombre'],
                'ubicacion' => $data['ubicacion'],
                'departamento_codigo' => $data['departamento_codigo'],
                'municipio_codigo' => $data['municipio_codigo'],
                'direccion' => $data['direccion'],
                'estado_id' => self::ACTIVE,
            ]);

            return $this->saved($request, 'Sucursal reactivada correctamente.');
        }

        Sucursal::create($data + ['estado_id' => self::ACTIVE]);

        return $this->saved($request, 'Sucursal creada correctamente.');
    }

    public function edit(Request $request, Sucursal $branch): View
    {
        $this->ensureActive($branch);

        return view('branches.form', [
            'sucursal' => $this->activeBranch($request),
            'sucursales' => Sucursal::activas()->orderBy('nombre')->get(),
            'branch' => $branch,
            'mode' => 'edit',
            'geography' => GuatemalaGeography::departments(),
        ]);
    }

    public function update(Request $request, Sucursal $branch): RedirectResponse|JsonResponse
    {
        $this->ensureActive($branch);

        $data = $this->validatedBranchData($request, $branch);

        $branch->update($data);

        return $this->saved($request, 'Sucursal actualizada correctamente.');
    }

    private function saved(Request $request, string $message): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            $request->session()->flash('status', $message);

            return response()->json(['message' => $message]);
        }

        return redirect()->route('branches.index')->with('status', $message);
    }

    public function destroy(Request $request, Sucursal $branch): RedirectResponse|JsonResponse
    {
        $this->ensureActive($branch);

        $activePatios = $branch->patios()->where('estado_id', self::ACTIVE)->count();
        if ($activePatios > 0) {
            if ($request->expectsJson()) {
                throw ValidationException::withMessages(['branch' => "No se puede desactivar: esta sucursal tiene {$activePatios} patio(s) activos asociados."]);
            }

            return back()->withErrors(['branch' => "No se puede desactivar: esta sucursal tiene {$activePatios} patio(s) activos asociados."]);
        }

        $activeUsers = $branch->users()->where('estado_id', self::ACTIVE)->count();
        if ($activeUsers > 0) {
            if ($request->expectsJson()) {
                throw ValidationException::withMessages(['branch' => "No se puede desactivar: hay {$activeUsers} usuario(s) activos asignados a esta sucursal."]);
            }

            return back()->withErrors(['branch' => "No se puede desactivar: hay {$activeUsers} usuario(s) activos asignados a esta sucursal."]);
        }

        $branch->update(['estado_id' => self::INACTIVE]);

        $refreshShell = (int) $request->session()->get('sucursal_activa') === (int) $branch->id;
        if ($refreshShell) {
            $newActiveBranch = Sucursal::activas()->orderBy('nombre')->first();
            $newActiveBranch
                ? $request->session()->put('sucursal_activa', (int) $newActiveBranch->id)
                : $request->session()->forget('sucursal_activa');
        }

        if ($request->expectsJson()) {
            $request->session()->flash('status', 'Sucursal desactivada correctamente.');

            return response()->json(['message' => 'Sucursal desactivada correctamente.', 'refreshShell' => $refreshShell]);
        }

        return redirect()->route('branches.index')->with('status', 'Sucursal desactivada correctamente.');
    }

    public function legacyCheck(Request $request)
    {
        $name = trim((string) $request->input('nombre', ''));
        $id = (int) $request->input('id', 0);

        if ($name === '') {
            return response('error');
        }

        $exists = Sucursal::query()
            ->where('nombre', $name)
            ->where('estado_id', self::ACTIVE)
            ->when($id > 0, fn ($query) => $query->where('id', '!=', $id))
            ->exists();

        return response($exists ? 'exists' : 'available');
    }

    public function reactivate(Request $request, Sucursal $branch): RedirectResponse
    {
        abort_unless((int) $branch->estado_id === self::INACTIVE, 404);
        $exists = Sucursal::query()->where('nombre', $branch->nombre)
            ->where('estado_id', self::ACTIVE)
            ->whereKeyNot($branch->id)
            ->exists();
        if ($exists) {
            return back()->withErrors(['branch' => 'No se puede reactivar: ya existe una sucursal activa con ese nombre.']);
        }
        $branch->update(['estado_id' => self::ACTIVE]);

        return back()->with('status', 'Sucursal reactivada correctamente. Sus patios y usuarios conservan su estado.');
    }

    private function validatedBranchData(Request $request, ?Sucursal $branch = null): array
    {
        $department = $request->input('departamento_codigo');
        $department = is_string($department) ? $department : null;
        $data = $request->validate([
            'nombre' => [
                'required',
                'string',
                'max:100',
                Rule::unique('sucursales', 'nombre')
                    ->where('estado_id', self::ACTIVE)
                    ->ignore($branch?->id),
            ],
            'departamento_codigo' => ['required', 'string', Rule::in(array_column(GuatemalaGeography::departments(), 'code'))],
            'municipio_codigo' => ['required', 'string', Rule::in(array_column(GuatemalaGeography::municipalities($department), 'code'))],
            'direccion' => ['nullable', 'string', 'max:255'],
        ], [
            'nombre.required' => 'Completa el nombre de la sucursal.',
            'nombre.unique' => 'Ya existe una sucursal activa con este nombre.',
            'departamento_codigo.required' => 'Selecciona el departamento.',
            'departamento_codigo.in' => 'Selecciona un departamento valido de Guatemala.',
            'municipio_codigo.required' => 'Selecciona el municipio.',
            'municipio_codigo.in' => 'El municipio no pertenece al departamento seleccionado.',
        ]);
        $data['direccion'] = $data['direccion'] ?? null;
        $data['ubicacion'] = GuatemalaGeography::summary($data['departamento_codigo'], $data['municipio_codigo']);

        return $data;
    }

    private function ensureActive(Sucursal $branch): void
    {
        if ((int) $branch->estado_id !== self::ACTIVE) {
            abort(404);
        }
    }

    private function activeBranch(Request $request): ?Sucursal
    {
        $branchId = (int) $request->session()->get('sucursal_activa');

        return $branchId > 0 ? Sucursal::find($branchId) : null;
    }
}
