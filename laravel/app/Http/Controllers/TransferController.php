<?php

namespace App\Http\Controllers;

use App\Models\Bloque;
use App\Models\Contenedor;
use App\Models\Patio;
use App\Models\Sucursal;
use App\Services\TransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TransferController extends Controller
{
    private const ACTIVE = 1;

    public function create(Request $request): View
    {
        $branch = $this->activeBranch($request);
        abort_if(! $branch, 404, 'No hay sucursal activa.');

        $selectedContainer = null;
        if ($request->integer('contenedor_id')) {
            $selectedContainer = $this->containerInfo($branch->id, $request->integer('contenedor_id'));
        }

        return view('transfers.create', [
            'sucursal' => $branch,
            'sucursales' => Sucursal::activas()->orderBy('nombre')->get(),
            'patios' => Patio::active()->where('sucursal_id', $branch->id)->orderBy('nombre')->get(),
            'selectedContainer' => $selectedContainer,
        ]);
    }

    public function store(Request $request, TransferService $transferService): RedirectResponse
    {
        $branch = $this->activeBranch($request);
        abort_if(! $branch, 404, 'No hay sucursal activa.');

        $data = $request->validate([
            'contenedor_id' => ['required', 'integer', Rule::exists('contenedores', 'id')->where('estado_id', self::ACTIVE)],
            'nuevo_bloque_id' => ['required', 'integer', Rule::exists('bloques', 'id')->where('estado_id', self::ACTIVE)],
            'nueva_posicion' => ['required', 'integer', 'min:1'],
        ], [
            'contenedor_id.required' => 'Selecciona un contenedor para trasladar.',
            'contenedor_id.exists' => 'El contenedor no existe o no esta activo.',
            'nuevo_bloque_id.required' => 'Selecciona un bloque destino.',
            'nuevo_bloque_id.exists' => 'El bloque destino no existe.',
            'nueva_posicion.required' => 'No se pudo calcular la posicion destino.',
            'nueva_posicion.min' => 'La posicion destino debe ser mayor a cero.',
        ]);

        try {
            $transferService->transfer($request->user(), $branch->id, $data);
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['transfer' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('transfers.create')->with('status', 'Contenedor trasladado exitosamente.');
    }

    public function blocksByPatio(Request $request, int $patio): JsonResponse
    {
        $branch = $this->activeBranch($request);
        $patioModel = Patio::active()->where('sucursal_id', $branch?->id)->findOrFail($patio);

        return response()->json(
            Bloque::active()
                ->where('patio_id', $patioModel->id)
                ->orderBy('numero')
                ->withCount(['contenedoresActivos as ocupacion_activa'])
                ->get(['id', 'numero', 'capacidad_maxima'])
                ->map(fn (Bloque $block) => [
                    'id' => $block->id,
                    'numero' => $block->numero,
                    'capacidad_maxima' => $block->capacidad_maxima,
                    'capacidad_actual' => $block->ocupacion_activa,
                ])
        );
    }

    public function searchContainers(Request $request): JsonResponse
    {
        $branch = $this->activeBranch($request);
        abort_if(! $branch, 404, 'No hay sucursal activa.');

        $request->validate(['query' => ['nullable', 'string', 'max:255']]);
        $query = trim((string) $request->query('query', ''));
        if (strlen($query) < 2) {
            return response()->json([]);
        }

        $containers = DB::table('contenedores as c')
            ->join('bloques as b', 'c.bloque_id', '=', 'b.id')
            ->join('patios as p', 'b.patio_id', '=', 'p.id')
            ->where('p.sucursal_id', $branch->id)
            ->where('c.estado_id', self::ACTIVE)
            ->where('b.estado_id', self::ACTIVE)
            ->where('p.estado_id', self::ACTIVE)
            ->where('c.nombre', 'like', '%'.strtoupper($query).'%')
            ->orderBy('c.nombre')
            ->limit(12)
            ->get([
                'c.id', 'c.nombre', 'c.posicion',
                'p.id as patio_id', 'p.nombre as patio_nombre',
                'b.id as bloque_id', 'b.numero as bloque_numero',
            ]);

        return response()->json($containers->map(function ($container) {
            $blockers = Contenedor::active()
                ->where('bloque_id', $container->bloque_id)
                ->where('posicion', '<', $container->posicion)
                ->count();

            return [
                'id' => (int) $container->id,
                'nombre' => $container->nombre,
                'posicion' => (int) $container->posicion,
                'patio_id' => (int) $container->patio_id,
                'patio_nombre' => $container->patio_nombre,
                'bloque_id' => (int) $container->bloque_id,
                'bloque_numero' => $container->bloque_numero,
                'puede_trasladar' => $blockers === 0,
            ];
        }));
    }

    public function containersByBlock(Request $request, int $block): JsonResponse
    {
        $branch = $this->activeBranch($request);
        $blockModel = Bloque::active()->with('patio')->findOrFail($block);
        abort_if(! $blockModel->patio || (int) $blockModel->patio->sucursal_id !== (int) $branch?->id, 403);

        $containers = DB::table('contenedores as c')
            ->leftJoin('estatus_contenedores as eu', 'c.estatus_unidad_id', '=', 'eu.id')
            ->leftJoin('estatus_contenedores as eb', 'c.estatus_box_id', '=', 'eb.id')
            ->leftJoin('estatus_ipsa as ei', 'c.estatus_ipsa_id', '=', 'ei.id')
            ->where('c.bloque_id', $blockModel->id)
            ->where('c.estado_id', self::ACTIVE)
            ->orderBy('c.posicion')
            ->orderBy('c.nombre')
            ->select([
                'c.id',
                'c.nombre',
                'c.posicion',
                'eu.nombre as estatus_unidad',
                'eb.nombre as estatus_box',
                'ei.nombre as estatus_ipsa',
            ])
            ->get()
            ->map(function ($container) use ($blockModel) {
                $blockers = Contenedor::active()
                    ->where('bloque_id', $blockModel->id)
                    ->where('posicion', '<', $container->posicion)
                    ->count();

                return [
                    'id' => (int) $container->id,
                    'nombre' => $container->nombre,
                    'posicion' => (int) $container->posicion,
                    'estatus_unidad' => $container->estatus_unidad ?: '',
                    'estatus_box' => $container->estatus_box ?: '',
                    'estatus_ipsa' => $container->estatus_ipsa ?: '',
                    'bloqueadores' => $blockers,
                    'puede_trasladar' => $blockers === 0,
                    'motivo_bloqueo' => $blockers === 0
                        ? 'Libre para traslado.'
                        : 'Tiene '.$blockers.' contenedor(es) bloqueando la salida.',
                ];
            });

        return response()->json($containers);
    }

    public function nextPosition(Request $request, int $block): JsonResponse
    {
        $branch = $this->activeBranch($request);
        $blockModel = Bloque::active()->with('patio')->findOrFail($block);
        abort_if(! $blockModel->patio || (int) $blockModel->patio->sucursal_id !== (int) $branch?->id, 403);

        $occupied = Contenedor::active()->where('bloque_id', $blockModel->id)->pluck('posicion')->map(fn ($pos) => (int) $pos)->all();
        $position = (int) $blockModel->capacidad_maxima;

        while ($position > 0 && in_array($position, $occupied, true)) {
            $position--;
        }

        if ($position <= 0) {
            return response()->json(['error' => 'El bloque esta completamente lleno.'], 422);
        }

        return response()->json(['posicion' => $position, 'ocupadas' => $occupied]);
    }

    public function legacyBlocks(Request $request): JsonResponse
    {
        return $this->blocksByPatio($request, $request->integer('patio_id'));
    }

    public function legacySearchContainers(Request $request): JsonResponse
    {
        $branch = $this->activeBranch($request);
        abort_if(! $branch, 404, 'No hay sucursal activa.');

        $query = trim((string) $request->query('query', ''));
        if ($query === '') {
            return response()->json([]);
        }

        return response()->json(
            DB::table('contenedores as c')
                ->join('bloques as b', 'c.bloque_id', '=', 'b.id')
                ->join('patios as p', 'b.patio_id', '=', 'p.id')
                ->where('c.nombre', 'like', '%'.strtoupper($query).'%')
                ->where('c.estado_id', self::ACTIVE)
                ->where('p.sucursal_id', $branch->id)
                ->orderBy('c.nombre')
                ->limit(10)
                ->get(['c.id', 'c.nombre'])
        );
    }

    public function legacyContainersByBlock(Request $request): JsonResponse
    {
        $branch = $this->activeBranch($request);
        $blockModel = Bloque::active()->with('patio')->findOrFail($request->integer('bloque_id'));
        abort_if(! $blockModel->patio || (int) $blockModel->patio->sucursal_id !== (int) $branch?->id, 403);

        $containers = $this->containersByBlock($request, $blockModel->id)->getData(true);
        $transferable = collect($containers)->where('puede_trasladar', true)->count();

        $containers = array_map(function (array $container) use ($blockModel): array {
            $container['patio_nombre'] = $blockModel->patio->nombre;
            $container['bloque_numero'] = (string) $blockModel->numero;

            return $container;
        }, $containers);

        return response()->json([
            'meta' => [
                'patio_id' => (int) $blockModel->patio->id,
                'patio_nombre' => $blockModel->patio->nombre,
                'bloque_id' => (int) $blockModel->id,
                'bloque_numero' => (string) $blockModel->numero,
                'total' => count($containers),
                'trasladables' => $transferable,
            ],
            'contenedores' => $containers,
        ]);
    }

    public function legacyContainerInfo(Request $request): JsonResponse
    {
        $branch = $this->activeBranch($request);
        abort_if(! $branch, 404, 'No hay sucursal activa.');

        $container = $this->containerInfo($branch->id, $request->integer('contenedor_id'));
        abort_if(! $container, 404, 'Contenedor no encontrado o acceso denegado');

        return response()->json($container);
    }

    public function legacyPosition(Request $request): JsonResponse
    {
        return $this->nextPosition($request, $request->integer('bloque_id'));
    }

    private function containerInfo(int $branchId, int $containerId): ?object
    {
        return DB::table('contenedores as c')
            ->join('bloques as b', 'c.bloque_id', '=', 'b.id')
            ->join('patios as p', 'b.patio_id', '=', 'p.id')
            ->where('c.id', $containerId)
            ->where('c.estado_id', self::ACTIVE)
            ->where('p.sucursal_id', $branchId)
            ->select([
                'c.id',
                'c.nombre',
                'c.posicion',
                'b.id as bloque_id',
                'b.numero as bloque_numero',
                'p.id as patio_id',
                'p.nombre as patio_nombre',
            ])
            ->first();
    }

    private function activeBranch(Request $request): ?Sucursal
    {
        $branchId = (int) $request->session()->get('sucursal_activa');

        return $branchId > 0 ? Sucursal::find($branchId) : null;
    }
}
