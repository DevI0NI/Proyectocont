<?php

namespace App\Http\Controllers;

use App\Models\Bloque;
use App\Models\Patio;
use App\Models\Sucursal;
use App\Services\AdministrationTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class YardLayoutController extends Controller
{
    private const ACTIVE = 1;

    private const INACTIVE = 2;

    private const MAX_BLOCK_CAPACITY = 25;

    private const MAX_BLOCKS_PER_PATIO = 25;

    public function index(Request $request): View
    {
        $branch = $this->activeBranch($request);
        abort_if(! $branch, 404, 'No hay sucursal activa.');

        $patioQuery = Patio::active()
            ->where('sucursal_id', $branch->id)
            ->withCount('bloquesActivos')
            ->selectSub(DB::table('bloques')->whereColumn('bloques.patio_id', 'patios.id')->where('estado_id', self::ACTIVE)->selectRaw('COALESCE(SUM(capacidad_maxima), 0)'), 'capacidad_total')
            ->selectSub(DB::table('contenedores as c')->join('bloques as b', 'b.id', '=', 'c.bloque_id')->whereColumn('b.patio_id', 'patios.id')->where('b.estado_id', self::ACTIVE)->where('c.estado_id', self::ACTIVE)->selectRaw('COUNT(*)'), 'ocupacion_total');
        $patios = AdministrationTable::paginate(DB::query()->fromSub($patioQuery, 'yard_patios'), $request, 'yard-patios', tableAlias: 'yard_patios');

        return view('yard-layout.index', [
            'sucursal' => $branch,
            'sucursales' => Sucursal::activas()->orderBy('nombre')->get(),
            'patios' => $patios,
            'selectedPatio' => null,
            'tableColumns' => AdministrationTable::columns('yard-patios'),
        ]);
    }

    public function blocks(Request $request, Patio $patio): View
    {
        $branch = $this->activeBranch($request);
        $this->ensurePatioBelongsToBranch($patio, $branch);
        $blockQuery = Bloque::active()->where('patio_id', $patio->id)->withCount('contenedoresActivos');
        $tableQuery = DB::query()->fromSub($blockQuery, 'yard_blocks');
        $summary = (clone $tableQuery)->selectRaw('COUNT(*) as blocks, COALESCE(SUM(capacidad_maxima), 0) as capacity, COALESCE(SUM(contenedores_activos_count), 0) as occupied')->first();
        $blocks = AdministrationTable::paginate($tableQuery, $request, 'yard-blocks', tableAlias: 'yard_blocks');

        return view('yard-layout.blocks', [
            'sucursal' => $branch,
            'sucursales' => Sucursal::activas()->orderBy('nombre')->get(),
            'selectedPatio' => $patio,
            'blocks' => $blocks,
            'summary' => $summary,
            'tableColumns' => AdministrationTable::columns('yard-blocks'),
        ]);
    }

    public function storePatio(Request $request): RedirectResponse|JsonResponse
    {
        $branch = $this->activeBranch($request);
        abort_if(! $branch, 404);

        $data = $request->validate([
            'nombre' => [
                'required',
                'string',
                'max:255',
                Rule::unique('patios', 'nombre')
                    ->where('sucursal_id', $branch->id)
                    ->where('estado_id', self::ACTIVE),
            ],
        ], [
            'nombre.required' => 'Escribe un nombre para el patio.',
            'nombre.unique' => "Ya existe un patio activo con el nombre \"{$request->input('nombre')}\" en esta sucursal.",
        ]);

        $inactive = Patio::query()
            ->where('sucursal_id', $branch->id)
            ->where('nombre', $data['nombre'])
            ->where('estado_id', self::INACTIVE)
            ->first();

        if ($inactive) {
            $inactive->update(['estado_id' => self::ACTIVE]);

            return $this->saved($request, 'Patio reactivado correctamente.', $inactive->id);
        }

        $patio = Patio::create([
            'nombre' => $data['nombre'],
            'sucursal_id' => $branch->id,
            'estado_id' => self::ACTIVE,
        ]);

        return $this->saved($request, 'Patio creado correctamente.', $patio->id);
    }

    public function updatePatio(Request $request, Patio $patio): RedirectResponse|JsonResponse
    {
        $branch = $this->activeBranch($request);
        $this->ensurePatioBelongsToBranch($patio, $branch);

        $data = $request->validate([
            'nombre' => [
                'required',
                'string',
                'max:255',
                Rule::unique('patios', 'nombre')
                    ->where('sucursal_id', $branch->id)
                    ->where('estado_id', self::ACTIVE)
                    ->ignore($patio->id),
            ],
        ], [
            'nombre.required' => 'Escribe un nombre para el patio.',
            'nombre.unique' => "Ya existe un patio activo con el nombre \"{$request->input('nombre')}\" en esta sucursal.",
        ]);

        $inactiveConflict = Patio::query()
            ->where('sucursal_id', $branch->id)
            ->where('nombre', $data['nombre'])
            ->where('estado_id', self::INACTIVE)
            ->where('id', '!=', $patio->id)
            ->exists();

        if ($inactiveConflict) {
            return $this->blocked($request, 'nombre', 'Ya existe un patio inactivo con ese nombre. Crealo como nuevo para reactivarlo.');
        }

        $patio->update(['nombre' => $data['nombre']]);

        return $this->saved($request, 'Patio actualizado correctamente.', $patio->id);
    }

    public function destroyPatio(Request $request, Patio $patio): RedirectResponse|JsonResponse
    {
        $branch = $this->activeBranch($request);
        $this->ensurePatioBelongsToBranch($patio, $branch);

        $activeBlocks = $patio->bloquesActivos()->count();
        if ($activeBlocks > 0) {
            return $this->blocked($request, 'patio', 'No se puede desactivar el patio porque tiene bloques activos.');
        }

        $patio->update(['estado_id' => self::INACTIVE]);

        return $this->saved($request, 'Patio desactivado correctamente.');
    }

    public function storeBlock(Request $request): RedirectResponse|JsonResponse
    {
        $branch = $this->activeBranch($request);
        abort_if(! $branch, 404);

        $data = $request->validate([
            'patio_id' => ['required', 'integer', Rule::exists('patios', 'id')->where('estado_id', self::ACTIVE)],
            'capacidad_maxima' => ['required', 'integer', 'min:1', 'max:'.self::MAX_BLOCK_CAPACITY],
        ], [
            'capacidad_maxima.min' => 'La capacidad minima de un bloque es de 1 contenedor.',
            'capacidad_maxima.max' => 'La capacidad maxima de un bloque es de '.self::MAX_BLOCK_CAPACITY.' contenedores.',
        ]);

        $patio = Patio::findOrFail($data['patio_id']);
        $this->ensurePatioBelongsToBranch($patio, $branch);

        DB::transaction(function () use ($patio, $data): void {
            Patio::whereKey($patio->id)->lockForUpdate()->first();
            if ($patio->bloquesActivos()->count() >= self::MAX_BLOCKS_PER_PATIO) {
                throw ValidationException::withMessages(['patio_id' => 'Este patio ya tiene el maximo de '.self::MAX_BLOCKS_PER_PATIO.' bloques. Crea otro patio para seguir agregando.']);
            }
            $nextNumber = ((int) Bloque::where('patio_id', $patio->id)->max('numero')) + 1;

            Bloque::create([
                'patio_id' => $patio->id,
                'numero' => $nextNumber,
                'capacidad_maxima' => $data['capacidad_maxima'],
                'capacidad_actual' => 0,
                'estado_id' => self::ACTIVE,
            ]);
        });

        return $this->saved($request, 'Bloque creado correctamente.', $patio->id, blocks: true);
    }

    public function updateBlock(Request $request, Bloque $block): RedirectResponse|JsonResponse
    {
        $branch = $this->activeBranch($request);
        $this->ensureBlockBelongsToBranch($block, $branch);

        $data = $request->validate([
            'capacidad_maxima' => ['required', 'integer', 'min:1', 'max:'.self::MAX_BLOCK_CAPACITY],
        ], [
            'capacidad_maxima.min' => 'La capacidad minima de un bloque es de 1 contenedor.',
            'capacidad_maxima.max' => 'La capacidad maxima de un bloque es de '.self::MAX_BLOCK_CAPACITY.' contenedores.',
        ]);

        $activeContainers = $block->contenedoresActivos()->count();
        if ((int) $data['capacidad_maxima'] < $activeContainers) {
            return $this->blocked($request, 'capacidad_maxima', "No puedes dejar el Bloque {$block->numero} con capacidad menor a sus {$activeContainers} contenedor(es) activos.");
        }

        $block->update(['capacidad_maxima' => $data['capacidad_maxima']]);

        return $this->saved($request, 'Bloque actualizado correctamente.', $block->patio_id, blocks: true);
    }

    public function destroyBlock(Request $request, Bloque $block): RedirectResponse|JsonResponse
    {
        $branch = $this->activeBranch($request);
        $this->ensureBlockBelongsToBranch($block, $branch);

        $activeContainers = $block->contenedoresActivos()->count();
        if ($activeContainers > 0) {
            return $this->blocked($request, 'block', "No se puede desactivar el Bloque {$block->numero} porque tiene {$activeContainers} contenedor(es) activos.");
        }

        $patioId = $block->patio_id;
        $block->update(['estado_id' => self::INACTIVE]);

        return $this->saved($request, 'Bloque desactivado correctamente.', $patioId, blocks: true);
    }

    private function saved(Request $request, string $message, ?int $patioId = null, bool $blocks = false): RedirectResponse|JsonResponse
    {
        $url = $blocks && $patioId ? route('yard-layout.blocks', ['patio' => $patioId]) : route('yard-layout.index');
        if ($request->expectsJson()) {
            $request->session()->flash('status', $message);

            return response()->json(['message' => $message, 'patio_id' => $blocks ? $patioId : null, 'url' => $url]);
        }

        return redirect($url)->with('status', $message);
    }

    private function blocked(Request $request, string $field, string $message): RedirectResponse
    {
        if ($request->expectsJson()) {
            throw ValidationException::withMessages([$field => $message]);
        }

        return back()->withErrors([$field => $message]);
    }

    private function selectedPatio(Request $request, $patios): ?Patio
    {
        if ($patios->isEmpty()) {
            return null;
        }

        $patioId = (int) $request->query('patio_id', 0);

        return $patios->firstWhere('id', $patioId) ?? $patios->first();
    }

    private function ensurePatioBelongsToBranch(Patio $patio, ?Sucursal $branch): void
    {
        abort_if(! $branch, 404);
        abort_if((int) $patio->estado_id !== self::ACTIVE, 404);
        abort_if((int) $patio->sucursal_id !== (int) $branch->id, 403);
    }

    private function ensureBlockBelongsToBranch(Bloque $block, ?Sucursal $branch): void
    {
        abort_if(! $branch, 404);
        abort_if((int) $block->estado_id !== self::ACTIVE, 404);
        $block->loadMissing('patio');
        abort_if(! $block->patio || (int) $block->patio->sucursal_id !== (int) $branch->id, 403);
    }

    private function activeBranch(Request $request): ?Sucursal
    {
        $branchId = (int) $request->session()->get('sucursal_activa');

        return $branchId > 0 ? Sucursal::find($branchId) : null;
    }
}
