<?php

namespace App\Http\Controllers;

use App\Models\Contenedor;
use App\Models\EstatusContenedor;
use App\Models\Sucursal;
use App\Models\TipoEstatus;
use App\Services\AdministrationTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ContainerStatusController extends Controller
{
    private const ACTIVE = 1;

    private const INACTIVE = 2;

    public function index(Request $request): View
    {
        $types = TipoEstatus::active()->orderBy('id')->get();
        $selectedType = $this->selectedType($request, $types);
        $state = in_array($request->query('estado'), ['inactivos', 'todos'], true) ? $request->query('estado') : 'activos';
        $query = EstatusContenedor::query()->with('tipo')->where('tipo_estatus_id', $selectedType?->id);
        $state === 'todos' ? $query->whereIn('estado_id', [self::ACTIVE, self::INACTIVE]) : $query->where('estado_id', $state === 'inactivos' ? self::INACTIVE : self::ACTIVE);

        return view('container-statuses.index', [
            'sucursal' => $this->activeBranch($request),
            'sucursales' => Sucursal::activas()->orderBy('nombre')->get(),
            'types' => $types,
            'selectedType' => $selectedType,
            'statuses' => AdministrationTable::paginate($query, $request, 'statuses'),
            'recordState' => $state,
            'recordStateLabel' => 'Estado de estatus de contenedores',
            'tableColumns' => AdministrationTable::columns('statuses'),
        ]);
    }

    public function create(Request $request): View
    {
        $types = TipoEstatus::active()->orderBy('id')->get();
        $selectedType = $this->selectedType($request, $types);

        return view('container-statuses.form', [
            'sucursal' => $this->activeBranch($request),
            'sucursales' => Sucursal::activas()->orderBy('nombre')->get(),
            'types' => $types,
            'selectedType' => $selectedType,
            'status' => new EstatusContenedor(['tipo_estatus_id' => $selectedType?->id]),
            'mode' => 'create',
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $data = $this->validatedStatusData($request);

        $existing = EstatusContenedor::query()
            ->where('nombre', $data['nombre'])
            ->where('tipo_estatus_id', $data['tipo_estatus_id'])
            ->first();

        if ($existing) {
            if ((int) $existing->estado_id === self::ACTIVE) {
                return back()
                    ->withErrors(['nombre' => 'Este estatus ya existe para la categoria seleccionada.'])
                    ->withInput();
            }

            $existing->update(['estado_id' => self::ACTIVE]);

            return $this->saved($request, $existing, 'Estatus reactivado correctamente.');
        }

        $status = EstatusContenedor::create([
            'nombre' => $data['nombre'],
            'tipo_estatus_id' => $data['tipo_estatus_id'],
            'estado_id' => self::ACTIVE,
        ]);

        return $this->saved($request, $status, 'Estatus creado correctamente.');
    }

    public function edit(Request $request, EstatusContenedor $status): View
    {
        $this->ensureActive($status);
        $types = TipoEstatus::active()->orderBy('id')->get();

        return view('container-statuses.form', [
            'sucursal' => $this->activeBranch($request),
            'sucursales' => Sucursal::activas()->orderBy('nombre')->get(),
            'types' => $types,
            'selectedType' => $status->tipo,
            'status' => $status,
            'mode' => 'edit',
        ]);
    }

    public function update(Request $request, EstatusContenedor $status): RedirectResponse|JsonResponse
    {
        $this->ensureActive($status);
        $data = $this->validatedStatusData($request, $status);

        $status->update($data);
        $status->load('tipo');

        return $this->saved($request, $status, 'Estatus actualizado correctamente.');
    }

    public function destroy(Request $request, EstatusContenedor $status): RedirectResponse|JsonResponse
    {
        $this->ensureActive($status);

        $activeUses = Contenedor::query()
            ->where('estado_id', self::ACTIVE)
            ->where(function ($query) use ($status): void {
                $query->where('estatus_unidad_id', $status->id)
                    ->orWhere('estatus_box_id', $status->id);
            })
            ->count();

        if ($activeUses > 0) {
            if ($request->expectsJson()) {
                throw ValidationException::withMessages(['status' => "No se puede desactivar: hay {$activeUses} contenedor(es) activo(s) usando este estatus."]);
            }

            return back()->withErrors(['status' => "No se puede desactivar: hay {$activeUses} contenedor(es) activo(s) usando este estatus."]);
        }

        $status->update(['estado_id' => self::INACTIVE]);

        return $this->saved($request, $status, 'Estatus desactivado correctamente.');
    }

    public function reactivate(Request $request, EstatusContenedor $status): RedirectResponse|JsonResponse
    {
        abort_unless((int) $status->estado_id === self::INACTIVE, 404);
        $this->validatedStatusData($request->merge(['nombre' => $status->nombre, 'tipo_estatus_id' => $status->tipo_estatus_id]), $status);
        $status->update(['estado_id' => self::ACTIVE]);

        return $this->saved($request, $status, 'Estatus reactivado correctamente.');
    }

    private function saved(Request $request, EstatusContenedor $status, string $message): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            $request->session()->flash('status', $message);

            return response()->json(['message' => $message, 'type' => $status->tipo?->nombre]);
        }

        return redirect()->route('statuses.index', ['tipo' => $status->tipo?->nombre])->with('status', $message);
    }

    public function legacyCheckName(Request $request)
    {
        $name = trim((string) $request->input('nombre', ''));
        $typeName = (string) $request->input('tipo', '');
        $id = (int) $request->input('id', 0);
        $typeId = TipoEstatus::where('nombre', $typeName)->value('id');

        if ($name === '' || ! $typeId) {
            return response('error');
        }

        $exists = EstatusContenedor::query()
            ->where('nombre', $name)
            ->where('tipo_estatus_id', $typeId)
            ->when($id > 0, fn ($query) => $query->where('id', '!=', $id))
            ->exists();

        return response($exists ? 'exists' : 'available');
    }

    public function legacyCheckNameWithState(Request $request)
    {
        $name = trim((string) $request->input('nombre', ''));
        $typeName = (string) $request->input('tipo', '');
        $id = (int) $request->input('id', 0);
        $typeId = TipoEstatus::where('nombre', $typeName)->value('id');

        if ($name === '' || ! $typeId) {
            return response('error');
        }

        $record = EstatusContenedor::query()
            ->where('nombre', $name)
            ->where('tipo_estatus_id', $typeId)
            ->when($id > 0, fn ($query) => $query->where('id', '!=', $id))
            ->first(['estado_id']);

        if (! $record) {
            return response('available');
        }

        return response((int) $record->estado_id === self::ACTIVE ? 'exists' : 'inactive');
    }

    private function validatedStatusData(Request $request, ?EstatusContenedor $status = null): array
    {
        $request->merge(['nombre' => trim((string) $request->input('nombre'))]);
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'tipo_estatus_id' => [
                'required',
                'integer',
                Rule::exists('tipos_estatus', 'id')->where('estado_id', self::ACTIVE),
            ],
        ], [
            'nombre.required' => 'El nombre del estatus no puede ir vacio.',
            'nombre.max' => 'El nombre del estatus no puede superar 255 caracteres.',
            'tipo_estatus_id.required' => 'Selecciona una categoria valida.',
            'tipo_estatus_id.exists' => 'Selecciona una categoria valida.',
        ]);

        $duplicate = EstatusContenedor::query()
            ->where('nombre', $data['nombre'])
            ->where('tipo_estatus_id', $data['tipo_estatus_id'])
            ->when($status, fn ($query) => $query->where('id', '!=', $status->id))
            ->first();

        if ($duplicate && (int) $duplicate->estado_id === self::ACTIVE) {
            throw ValidationException::withMessages([
                'nombre' => 'Este estatus ya existe para la categoria seleccionada.',
            ]);
        }

        if ($duplicate && $status) {
            throw ValidationException::withMessages([
                'nombre' => 'Este nombre ya existe en el historial para esta categoria.',
            ]);
        }

        return $data;
    }

    private function selectedType(Request $request, $types): ?TipoEstatus
    {
        if ($types->isEmpty()) {
            return null;
        }

        $typeName = $request->query('tipo', 'Unidad');

        return $types->firstWhere('nombre', $typeName) ?? $types->first();
    }

    private function ensureActive(EstatusContenedor $status): void
    {
        if ((int) $status->estado_id !== self::ACTIVE) {
            abort(404);
        }
    }

    private function activeBranch(Request $request): ?Sucursal
    {
        $branchId = (int) $request->session()->get('sucursal_activa');

        return $branchId > 0 ? Sucursal::find($branchId) : null;
    }
}
