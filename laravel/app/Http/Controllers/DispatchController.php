<?php

namespace App\Http\Controllers;

use App\Models\Contenedor;
use App\Models\Sucursal;
use App\Services\ContainerAuditAssignment;
use App\Services\DispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DispatchController extends Controller
{
    private const ACTIVE = 1;

    private const AVAILABLE_IPSA = 1;

    public function create(Request $request): View
    {
        $branch = $this->activeBranch($request);
        abort_if(! $branch, 404, 'No hay sucursal activa.');

        $selectedContainer = null;
        $selectedId = $request->integer('contenedor_id') ?: (int) old('contenedor_id');
        if ($selectedId) {
            $selectedContainer = $this->dispatchableContainerQuery($branch->id)
                ->where('c.id', $selectedId)
                ->first();
        }

        return view('dispatches.create', [
            'sucursal' => $branch,
            'sucursales' => Sucursal::activas()->orderBy('nombre')->get(),
            'selectedContainer' => $selectedContainer,
        ]);
    }

    public function store(Request $request, DispatchService $dispatchService): RedirectResponse
    {
        $branch = $this->activeBranch($request);
        abort_if(! $branch, 404, 'No hay sucursal activa.');

        $data = $request->validate([
            'contenedor_id' => ['required', 'integer', Rule::exists('contenedores', 'id')->where('estado_id', self::ACTIVE)],
            'observaciones' => ['nullable', 'string', 'max:150'],
        ], [
            'contenedor_id.required' => 'Error: Contenedor no valido.',
            'contenedor_id.exists' => 'El contenedor no existe o no pertenece a esta sucursal.',
            'observaciones.max' => 'Las observaciones no pueden superar los 150 caracteres.',
        ]);

        try {
            $containerName = $dispatchService->dispatch($request->user(), $branch->id, $data);
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['dispatch' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('dispatches.create')->with('status', "Contenedor {$containerName} despachado y archivado correctamente.");
    }

    public function search(Request $request): JsonResponse
    {
        $branch = $this->activeBranch($request);
        abort_if(! $branch, 404, 'No hay sucursal activa.');

        $request->validate(['query' => ['nullable', 'string', 'max:255']]);
        $query = trim((string) $request->query('query', ''));
        if (strlen($query) < 2) {
            return response()->json([]);
        }

        $containers = $this->dispatchableContainerQuery($branch->id)
            ->where('c.nombre', 'like', '%'.strtoupper($query).'%')
            ->limit(10)
            ->get()
            ->map(function ($container) {
                $blockers = Contenedor::active()
                    ->where('bloque_id', $container->bloque_id)
                    ->where('posicion', '<', $container->posicion)
                    ->count();

                $ipsaAvailable = (int) $container->estatus_ipsa_id === self::AVAILABLE_IPSA;
                $auditApproved = DispatchService::isAuditApproved($container->audit_status);

                return [
                    'id' => (int) $container->id,
                    'nombre' => $container->nombre,
                    'posicion' => (int) $container->posicion,
                    'bloque_id' => (int) $container->bloque_id,
                    'estatus_ipsa_id' => (int) $container->estatus_ipsa_id,
                    'estatus_ipsa' => $container->estatus_ipsa,
                    'estatus_pyp' => $container->estatus_pyp,
                    'situacion_auditoria' => $container->audit_status,
                    'bloque' => $container->bloque_numero,
                    'patio' => $container->patio_nombre,
                    'bloqueado_fisicamente' => $blockers > 0,
                    'bloqueadores' => $blockers,
                    'ipsa_disponible' => $ipsaAvailable,
                    'auditoria_aprobada' => $auditApproved,
                    'puede_despachar' => $ipsaAvailable && $auditApproved && $blockers === 0,
                ];
            });

        return response()->json($containers);
    }

    private function dispatchableContainerQuery(int $branchId)
    {
        $query = DB::table('contenedores as c')
            ->join('bloques as b', 'c.bloque_id', '=', 'b.id')
            ->join('patios as p', 'b.patio_id', '=', 'p.id')
            ->leftJoin('estatus_ipsa as ei', 'c.estatus_ipsa_id', '=', 'ei.id')
            ->leftJoin('pyp_auditoria as pa', function ($join): void {
                $join->on('c.id', '=', 'pa.contenedor_id')->where('pa.estado_id', self::ACTIVE);
            })
            ->where('c.estado_id', self::ACTIVE)
            ->where('p.sucursal_id', $branchId)
            ->select([
                'c.id',
                'c.nombre',
                'c.posicion',
                'c.bloque_id',
                'c.estatus_ipsa_id',
                'ei.nombre as estatus_ipsa',
                'pa.estatus_pyp',
                'b.numero as bloque_numero',
                'p.nombre as patio_nombre',
            ]);

        return app(ContainerAuditAssignment::class)->joinCurrent($query);
    }

    private function activeBranch(Request $request): ?Sucursal
    {
        $branchId = (int) $request->session()->get('sucursal_activa');

        return $branchId > 0 ? Sucursal::find($branchId) : null;
    }
}
