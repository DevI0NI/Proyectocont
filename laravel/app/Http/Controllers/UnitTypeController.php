<?php

namespace App\Http\Controllers;

use App\Models\Sucursal;
use App\Models\TipoUnidad;
use App\Services\AdministrationTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UnitTypeController extends Controller
{
    private const ACTIVE = 1;

    private const INACTIVE = 2;

    public function index(Request $request): View
    {
        $state = in_array($request->query('estado'), ['inactivos', 'todos'], true) ? $request->query('estado') : 'activos';
        $query = TipoUnidad::query()->with('modelosActivos')->withCount('modelosActivos');
        if ($state !== 'todos') {
            $query->where('estado_id', $state === 'inactivos' ? self::INACTIVE : self::ACTIVE);
        } else {
            $query->whereIn('estado_id', [self::ACTIVE, self::INACTIVE]);
        }

        return view('unit-types.index', [
            'sucursal' => $this->activeBranch($request),
            'sucursales' => Sucursal::activas()->orderBy('nombre')->get(),
            'types' => AdministrationTable::paginate($query, $request, 'unit-types'),
            'recordState' => $state,
            'tableColumns' => AdministrationTable::columns('unit-types'),
        ]);
    }

    public function create(Request $request): View
    {
        return view('unit-types.form', [
            'sucursal' => $this->activeBranch($request),
            'sucursales' => Sucursal::activas()->orderBy('nombre')->get(),
            'type' => new TipoUnidad,
            'modelos' => [['id' => 0, 'nombre' => '']],
            'mode' => 'create',
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $data = $this->validatedTypeData($request);

        $inactive = TipoUnidad::query()
            ->where('nombre', $data['nombre'])
            ->where('estado_id', self::INACTIVE)
            ->first();

        if ($inactive) {
            if ($request->expectsJson()) {
                throw ValidationException::withMessages(['nombre' => 'Ese tipo ya existe inactivo. Selecciona Inactivos y usa Reactivar para conservar sus registros.']);
            }

            return back()
                ->withErrors(['nombre' => 'Ese tipo de unidad existe pero esta deshabilitado. Contacta al administrador si deseas reactivarlo.'])
                ->withInput();
        }

        DB::transaction(function () use ($data): void {
            $type = TipoUnidad::create([
                'nombre' => $data['nombre'],
                'descripcion' => $data['descripcion'] ?? '',
                'estado_id' => self::ACTIVE,
            ]);

            foreach ($data['modelos'] as $modelName) {
                $type->modelos()->create([
                    'nombre' => $modelName,
                    'estado_id' => self::ACTIVE,
                ]);
            }
        });

        return $this->saved($request, 'Tipo de unidad creado correctamente.');
    }

    public function edit(Request $request, TipoUnidad $unitType): View
    {
        $reactivate = $request->boolean('reactivar') && (int) $unitType->estado_id === self::INACTIVE;
        if (! $reactivate) {
            $this->ensureActive($unitType);
        }

        return view('unit-types.form', [
            'sucursal' => $this->activeBranch($request),
            'sucursales' => Sucursal::activas()->orderBy('nombre')->get(),
            'type' => $unitType,
            'modelos' => ($reactivate ? $unitType->modelos() : $unitType->modelosActivos())
                ->orderBy('nombre')
                ->get(['id', 'nombre'])
                ->map(fn ($model) => ['id' => $model->id, 'nombre' => $model->nombre])
                ->values()
                ->all(),
            'mode' => $reactivate ? 'reactivate' : 'edit',
        ]);
    }

    public function update(Request $request, TipoUnidad $unitType): RedirectResponse|JsonResponse
    {
        $reactivate = $request->boolean('reactivate') && (int) $unitType->estado_id === self::INACTIVE;
        if (! $reactivate) {
            $this->ensureActive($unitType);
        }
        $data = $this->validatedTypeData($request, $unitType);

        DB::transaction(function () use ($unitType, $data): void {
            $unitType->update([
                'nombre' => $data['nombre'],
                'descripcion' => $data['descripcion'] ?? '',
                'estado_id' => self::ACTIVE,
            ]);

            $activeModelIds = $unitType->modelosActivos()->pluck('id')->map(fn ($id) => (int) $id)->all();
            $receivedIds = [];

            foreach ($data['modelos_payload'] as $model) {
                if ($model['id'] > 0) {
                    $unitType->modelos()->findOrFail($model['id'])->update([
                        'nombre' => $model['nombre'],
                        'estado_id' => self::ACTIVE,
                    ]);
                    $receivedIds[] = $model['id'];

                    continue;
                }

                $unitType->modelos()->create([
                    'nombre' => $model['nombre'],
                    'estado_id' => self::ACTIVE,
                ]);
            }

            $idsToDisable = array_diff($activeModelIds, $receivedIds);
            if ($idsToDisable !== []) {
                if ($unitType->contenedores()->where('estado_id', self::ACTIVE)->whereIn('modelo_id', $idsToDisable)->exists()) {
                    throw ValidationException::withMessages(['modelos_nombres' => 'No puedes quitar un modelo que usan contenedores activos.']);
                }
                $unitType->modelos()->whereIn('id', $idsToDisable)->get()->each(fn ($model) => $model->update(['estado_id' => self::INACTIVE]));
            }
        });

        return $this->saved($request, $reactivate ? 'Tipo de unidad reactivado correctamente.' : 'Tipo de unidad actualizado correctamente.');
    }

    public function destroy(Request $request, TipoUnidad $unitType): RedirectResponse|JsonResponse
    {
        $this->ensureActive($unitType);

        $activeUses = $unitType->contenedores()->where('estado_id', self::ACTIVE)->count();
        if ($activeUses > 0) {
            if ($request->expectsJson()) {
                throw ValidationException::withMessages(['unit_type' => "No se puede desactivar: hay {$activeUses} contenedor(es) activo(s) usando este tipo de unidad."]);
            }

            return back()->withErrors(['unit_type' => "No se puede desactivar: hay {$activeUses} contenedor(es) activo(s) usando este tipo de unidad."]);
        }

        DB::transaction(function () use ($unitType): void {
            $unitType->modelos()->get()->each(fn ($model) => $model->update(['estado_id' => self::INACTIVE]));
            $unitType->update(['estado_id' => self::INACTIVE]);
        });

        return $this->saved($request, 'Tipo de unidad desactivado correctamente.');
    }

    private function saved(Request $request, string $message): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            $request->session()->flash('status', $message);

            return response()->json(['message' => $message]);
        }

        return redirect()->route('unit-types.index')->with('status', $message);
    }

    public function legacyCheckName(Request $request)
    {
        $name = trim((string) $request->input('nombre', ''));
        $id = (int) $request->input('id', 0);

        if ($name === '') {
            return response('error');
        }

        $exists = TipoUnidad::query()
            ->where('nombre', $name)
            ->when($id > 0, fn ($query) => $query->where('id', '!=', $id))
            ->exists();

        return response($exists ? 'exists' : 'available');
    }

    private function validatedTypeData(Request $request, ?TipoUnidad $type = null): array
    {
        $data = $request->validate([
            'nombre' => [
                'required',
                'string',
                'max:50',
                $type
                    ? Rule::unique('tipos_unidad', 'nombre')->ignore($type->id)
                    : Rule::unique('tipos_unidad', 'nombre')->where('estado_id', self::ACTIVE),
            ],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'modelos_ids' => ['array'],
            'modelos_ids.*' => ['nullable', 'integer'],
            'modelos_nombres' => ['required', 'array', 'min:1'],
            'modelos_nombres.*' => ['nullable', 'string', 'max:50'],
        ], [
            'nombre.required' => 'El nombre del tipo de unidad no puede ir vacio.',
            'nombre.unique' => 'El nombre ya esta en uso por otro registro.',
            'modelos_nombres.required' => 'Agrega al menos un modelo.',
        ]);

        $payload = [];
        $seenNames = [];
        $seenIds = [];
        $names = $data['modelos_nombres'] ?? [];
        $ids = $data['modelos_ids'] ?? [];

        foreach ($names as $index => $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }

            $id = (int) ($ids[$index] ?? 0);
            $normalized = mb_strtolower($name);
            if (isset($seenNames[$normalized])) {
                throw ValidationException::withMessages(['modelos_nombres.'.$index => 'No repitas el nombre de un modelo dentro del mismo tipo.']);
            }
            if ($id > 0 && (! $type || isset($seenIds[$id]) || ! $type->modelos()->whereKey($id)->exists())) {
                throw ValidationException::withMessages(['modelos_nombres.'.$index => 'El modelo seleccionado no pertenece a este tipo de unidad.']);
            }
            $seenNames[$normalized] = true;
            $seenIds[$id] = true;

            $payload[] = [
                'id' => $id,
                'nombre' => $name,
            ];
        }

        if ($payload === []) {
            throw ValidationException::withMessages([
                'modelos_nombres' => 'Agrega al menos un modelo valido.',
            ]);
        }

        $data['modelos_payload'] = $payload;
        $data['modelos'] = array_map(fn ($model) => $model['nombre'], $payload);

        return $data;
    }

    private function ensureActive(TipoUnidad $type): void
    {
        if ((int) $type->estado_id !== self::ACTIVE) {
            abort(404);
        }
    }

    private function activeBranch(Request $request): ?Sucursal
    {
        $branchId = (int) $request->session()->get('sucursal_activa');

        return $branchId > 0 ? Sucursal::find($branchId) : null;
    }
}
