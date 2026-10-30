<?php

namespace App\Http\Controllers;

use App\Models\Bloque;
use App\Models\Contenedor;
use App\Models\EstatusIpsa;
use App\Models\Patio;
use App\Models\Sucursal;
use App\Rules\DocumentaryText;
use App\Rules\MeaningfulTechnicalText;
use App\Services\ContainerAuditAssignment;
use App\Services\DispatchService;
use App\Services\ExternalAuditAccess;
use App\Services\MaintenanceWorkflow;
use App\Services\TransportPlate;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DispatchController extends Controller
{
    private const ACTIVE = 1;

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
            'dispatchLocations' => Patio::active()->where('sucursal_id', $branch->id)
                ->with(['bloquesActivos' => fn ($query) => $query->orderBy('numero')->withCount('contenedoresActivos')])
                ->orderBy('nombre')->get()->map(fn (Patio $patio) => [
                    'id' => $patio->id, 'nombre' => $patio->nombre,
                    'blocks' => $patio->bloquesActivos->map(fn (Bloque $block) => [
                        'id' => $block->id, 'numero' => $block->numero,
                        'capacity' => $block->capacidad_maxima, 'count' => $block->contenedores_activos_count,
                    ]),
                ]),
        ]);
    }

    public function store(Request $request, DispatchService $dispatchService): RedirectResponse
    {
        $branch = $this->activeBranch($request);
        abort_if(! $branch, 404, 'No hay sucursal activa.');
        foreach (['placa_cabezal', 'placa_chasis'] as $field) {
            if ($request->filled($field)) {
                $request->merge([$field => strtoupper(trim($request->input($field)))]);
            }
        }

        $data = $request->validate([
            'contenedor_id' => ['required', 'integer', Rule::exists('contenedores', 'id')->where('estado_id', self::ACTIVE)],
            'observaciones' => ['nullable', 'string', 'min:40', 'max:150', new MeaningfulTechnicalText],
            'transportista' => ['required', 'string', 'max:100', new DocumentaryText('company')],
            'conductor' => ['required', 'string', 'max:80', new DocumentaryText('driver')],
            'placa_cabezal' => ['required', 'string', 'max:15', 'regex:'.TransportPlate::REGEX],
            'placa_chasis' => ['required', 'string', 'max:15', 'regex:'.TransportPlate::REGEX],
        ], [
            'contenedor_id.required' => 'Error: Contenedor no valido.',
            'contenedor_id.exists' => 'El contenedor no existe o no pertenece a esta sucursal.',
            'observaciones.max' => 'Las observaciones no pueden superar los 150 caracteres.',
            'observaciones.min' => 'Escribe al menos 40 caracteres.',
            'transportista.required' => 'Indica quien retira el contenedor.',
            'conductor.required' => 'Ingresa el nombre del piloto.',
            'placa_chasis.required' => 'Ingresa la placa del chasis.',
            'placa_cabezal.required' => 'Ingresa la placa del cabezal.',
            'placa_cabezal.regex' => TransportPlate::MESSAGE, 'placa_chasis.regex' => TransportPlate::MESSAGE,
        ]);

        try {
            $dispatch = $dispatchService->dispatch($request->user(), $branch->id, $data);
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['dispatch' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('documents.dispatch.ticket', ['event_id' => $dispatch['event_id']])
            ->with('status', "Contenedor {$dispatch['name']} despachado y archivado correctamente.");
    }

    public function search(Request $request): JsonResponse
    {
        $branch = $this->activeBranch($request);
        abort_if(! $branch, 404, 'No hay sucursal activa.');

        $request->validate(['query' => ['nullable', 'string', 'max:255'], 'bloque_id' => ['nullable', 'integer', 'min:1']]);
        $query = trim((string) $request->query('query', ''));
        $blockId = $request->integer('bloque_id');
        if ($blockId) {
            Bloque::active()->whereHas('patio', fn ($query) => $query->where('sucursal_id', $branch->id)->where('estado_id', self::ACTIVE))->findOrFail($blockId);
        }
        if (! $blockId && strlen($query) < 2) {
            return response()->json([]);
        }

        $containers = $this->dispatchableContainerQuery($branch->id)
            ->when($query !== '', fn ($builder) => $builder->where('c.nombre', 'like', '%'.strtoupper($query).'%'))
            ->when($blockId, fn ($builder) => $builder->where('c.bloque_id', $blockId), fn ($builder) => $builder->limit(10))
            ->orderBy('c.posicion')
            ->get()
            ->map(function ($container) {
                $blockers = Contenedor::active()
                    ->where('bloque_id', $container->bloque_id)
                    ->where('posicion', '<', $container->posicion)
                    ->count();

                $ipsaAvailable = EstatusIpsa::available()->whereKey($container->estatus_ipsa_id)->exists();
                $auditApproved = DispatchService::isAuditApproved($container->audit_status);
                $maintenanceOpen = $container->current_ingress_cycle_id && MaintenanceWorkflow::hasOpenWork((int) $container->current_ingress_cycle_id);

                return [
                    'id' => (int) $container->id,
                    'nombre' => $container->nombre,
                    'naviera' => $container->shipping_line_name, 'auditora' => $container->audit_company_name,
                    'posicion' => (int) $container->posicion,
                    'bloque_id' => (int) $container->bloque_id,
                    'patio_id' => (int) $container->patio_id,
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
                    'mantenimiento_pendiente' => (bool) $maintenanceOpen,
                    'puede_despachar' => $ipsaAvailable && $auditApproved && ! $maintenanceOpen && $blockers === 0,
                ];
            });

        return response()->json($containers);
    }

    private function dispatchableContainerQuery(int $branchId): Builder
    {
        $query = DB::table('contenedores as c')
            ->join('bloques as b', 'c.bloque_id', '=', 'b.id')
            ->join('patios as p', 'b.patio_id', '=', 'p.id')
            ->leftJoin('estatus_ipsa as ei', 'c.estatus_ipsa_id', '=', 'ei.id')
            ->leftJoin('pyp_auditoria as pa', function ($join): void {
                $join->on('c.id', '=', 'pa.contenedor_id')->where('pa.estado_id', self::ACTIVE);
            })
            ->where('c.estado_id', self::ACTIVE)
            ->where('b.estado_id', self::ACTIVE)
            ->where('p.estado_id', self::ACTIVE)
            ->where('p.sucursal_id', $branchId)
            ->select([
                'c.id',
                'c.nombre',
                'c.posicion',
                'c.bloque_id',
                'p.id as patio_id',
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
        app(ExternalAuditAccess::class)->authorizeBranch($request->user(), $branchId);

        return $branchId > 0 ? Sucursal::find($branchId) : null;
    }
}
