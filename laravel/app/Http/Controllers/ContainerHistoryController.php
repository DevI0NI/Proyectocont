<?php

namespace App\Http\Controllers;

use App\Models\Sucursal;
use App\Services\ContainerHistoryTimeline;
use App\Services\ExternalAuditAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ContainerHistoryController extends Controller
{
    public function index(Request $request, ExternalAuditAccess $access): View
    {
        $branch = $this->branch($request, $access);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:60'],
            'state' => ['nullable', Rule::in(['all', 'active', 'dispatched', 'legacy'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50])],
            'sort' => ['nullable', Rule::in(['name', 'shipping_line', 'stays', 'reentries', 'ingress', 'exit'])],
            'order' => ['nullable', Rule::in(['asc', 'desc'])],
            'shipping_line' => ['nullable', 'string', 'max:120'],
            'stays' => ['nullable', 'integer', 'min:0'],
            'reentries' => ['nullable', 'integer', 'min:0'],
            'ingress_from' => ['nullable', 'date_format:Y-m-d'],
            'ingress_to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('ingress_from'), 'after_or_equal:ingress_from')],
            'exit_from' => ['nullable', 'date_format:Y-m-d'],
            'exit_to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('exit_from'), 'after_or_equal:exit_from')],
        ]);
        $query = $this->containers($branch->id);
        if ($search = trim($filters['search'] ?? '')) {
            $query->whereRaw("c.nombre LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%']);
        }
        match ($filters['state'] ?? 'all') {
            'active' => $query->whereNull('cycle.closed_at')->whereNotNull('cycle.id')
                ->whereColumn('c.current_ingress_cycle_id', 'cycle.id')->where('c.estado_id', 1),
            'dispatched' => $query->whereNotNull('cycle.closed_at'),
            'legacy' => $query->whereNull('cycle.id'),
            default => null,
        };
        if ($shippingLine = trim($filters['shipping_line'] ?? '')) {
            $query->whereRaw("cycle.shipping_line_name LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $shippingLine).'%']);
        }
        foreach (['ingress' => 'cycle.entered_at', 'exit' => 'cycle.closed_at'] as $prefix => $field) {
            foreach (['from' => '>=', 'to' => '<'] as $boundary => $operator) {
                if (! empty($filters[$prefix.'_'.$boundary])) {
                    $date = CarbonImmutable::parse($filters[$prefix.'_'.$boundary], $prefix === 'exit' ? 'America/Guatemala' : 'UTC');
                    $query->where($field, $operator, ($boundary === 'to' ? $date->addDay() : $date)->utc());
                }
            }
        }
        $query = DB::query()->fromSub($query, 'history_rows')->select('history_rows.*');
        if (isset($filters['stays'])) {
            $query->where('stay_count', $filters['stays']);
        }
        if (isset($filters['reentries'])) {
            $query->where('reentry_count', $filters['reentries']);
        }
        $sorts = ['name' => 'nombre', 'shipping_line' => 'shipping_line_name', 'stays' => 'stay_count', 'reentries' => 'reentry_count', 'ingress' => 'entered_at', 'exit' => 'closed_at'];
        $sort = $filters['sort'] ?? 'ingress';
        $direction = $filters['order'] ?? 'desc';

        return view('containers.history-index', [
            'sucursal' => $branch, 'sucursales' => $access->branches($request->user())->orderBy('nombre')->get(),
            'containers' => $query->orderBy($sorts[$sort], $direction)->orderByDesc('id')
                ->paginate((int) ($filters['per_page'] ?? 10))->withQueryString(),
        ]);
    }

    public function show(Request $request, int $container, ExternalAuditAccess $access): View
    {
        $branch = $this->branch($request, $access);
        $request->validate(['cycle' => ['nullable', 'integer', 'min:1']]);
        $record = $this->containers($branch->id)->where('c.id', $container)->firstOrFail();
        $cycleQuery = DB::table('container_ingress_cycles')->where('container_id', $container)->where('branch_id', $branch->id);
        $cycles = (clone $cycleQuery)->orderBy('entered_at')->orderBy('id')->get(['id', 'entered_at', 'closed_at']);
        $selectedCycle = $cycles->first();
        if ($request->filled('cycle')) {
            $selectedCycle = $cycles->firstWhere('id', $request->integer('cycle'));
            abort_unless($selectedCycle, 404);
        }
        $timeline = app(ContainerHistoryTimeline::class)->query($branch->id, $container)
            ->when($selectedCycle, fn (Builder $query) => $query->where('cycle_id', $selectedCycle->id), fn (Builder $query) => $query->whereNull('cycle_id'))
            ->get();
        $timeline->transform(function (object $entry): object {
            $entry->snapshot = json_decode($entry->payload ?? '{}', true) ?: [];
            $entry->title = match ($entry->kind) {
                'ingress' => 'Ingreso', 'reentry' => 'Reingreso', 'dispatch' => 'Salida del contenedor',
                'repair' => 'Inicio de reparacion', 'correction_requested' => 'Correccion solicitada',
                'correction_submitted' => 'Correccion enviada a auditoria', 'external_audit_status' => 'Auditoria externa',
                'reassignment' => 'Cambio de naviera o auditora', 'instruction' => 'Instruccion de naviera',
                'transfer' => 'Traslado', 'modification' => 'Modificaciones del contenedor',
                default => 'Actualizacion del ingreso',
            };
            if (in_array($entry->kind, ['ingress', 'reentry'], true)) {
                $entry->notes = $entry->snapshot['cycle']['shipping_line_name'] ?? $entry->notes;
            } elseif ($entry->kind === 'external_audit_status') {
                $entry->notes = $entry->snapshot['audit']['informacion_adicional'] ?? $entry->notes;
                $entry->result = $entry->snapshot['audit']['estatus_pyp'] ?? null;
                $entry->actor = $entry->snapshot['audit']['inspector_name'] ?? $entry->actor;
            }

            return $entry;
        });

        app(ContainerHistoryTimeline::class)->attachChanges($timeline, $branch->id, $container);
        app(ContainerHistoryTimeline::class)->attachDetails($timeline, $branch->id, $container);

        return view('containers.history-show', [
            'sucursal' => $branch, 'sucursales' => $access->branches($request->user())->orderBy('nombre')->get(),
            'container' => $record, 'timeline' => $timeline, 'cycleCount' => $cycles->count(), 'cycles' => $cycles, 'selectedCycle' => $selectedCycle,
            'returnUrl' => $this->returnUrl($request),
        ]);
    }

    public function photo(Request $request, int $container, int $event, int $photo, ExternalAuditAccess $access): BinaryFileResponse
    {
        $branch = $this->branch($request, $access);
        $record = DB::table('container_assignment_events as event')
            ->join('container_ingress_cycles as cycle', 'cycle.id', '=', 'event.ingress_cycle_id')
            ->whereColumn('cycle.container_id', 'event.container_id')->where('cycle.branch_id', $branch->id)
            ->where('event.container_id', $container)->where('event.id', $event)->where('event.event_type', 'external_audit_status')
            ->select('event.after_data')->firstOrFail();
        $image = (json_decode($record->after_data, true)['audit']['photos'] ?? [])[$photo] ?? null;
        abort_unless(is_array($image) && in_array($image['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)
            && is_string($image['path'] ?? null) && Storage::disk('local')->exists($image['path']), 404);

        return response()->file(Storage::disk('local')->path($image['path']), [
            'Content-Type' => $image['mime'], 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
    }

    private function branch(Request $request, ExternalAuditAccess $access): Sucursal
    {
        $branchId = (int) $request->session()->get('sucursal_activa', $request->user()->sucursal_id);
        $access->authorizeBranch($request->user(), $branchId);

        return Sucursal::findOrFail($branchId);
    }

    private function returnUrl(Request $request): string
    {
        $fallback = route('containers.history.index', [], false);
        $value = $request->query('return_to');
        if (! is_string($value) || strlen($value) > 4000 || str_contains($value, '\\') || str_contains($value, "\r") || str_contains($value, "\n")) {
            return $fallback;
        }
        $parts = parse_url($value);

        return is_array($parts) && ! isset($parts['scheme']) && ! isset($parts['host']) && ! str_starts_with($value, '//')
            && ($parts['path'] ?? '') === $fallback ? $value : $fallback;
    }

    private function containers(int $branchId): Builder
    {
        $latest = DB::table('container_ingress_cycles')->where('branch_id', $branchId)
            ->select('container_id')->selectRaw('MAX(id) as latest_cycle_id')->selectRaw('COUNT(*) as stay_count')->groupBy('container_id');

        return DB::table('contenedores as c')->leftJoinSub($latest, 'history', 'history.container_id', '=', 'c.id')
            ->leftJoin('container_ingress_cycles as cycle', 'cycle.id', '=', 'history.latest_cycle_id')
            ->leftJoin('bloques as block', 'block.id', '=', 'c.bloque_id')->leftJoin('patios as yard', 'yard.id', '=', 'block.patio_id')
            ->where(function (Builder $visible) use ($branchId): void {
                $visible->whereNotNull('history.latest_cycle_id')->orWhere(function (Builder $legacy) use ($branchId): void {
                    $legacy->where('yard.sucursal_id', $branchId)->whereNotExists(fn (Builder $cycles) => $cycles
                        ->selectRaw('1')->from('container_ingress_cycles as any_cycle')->whereColumn('any_cycle.container_id', 'c.id'));
                });
            })
            ->select('c.id', 'c.nombre', 'cycle.entered_at', 'cycle.closed_at', 'cycle.shipping_line_name', 'cycle.audit_company_name')
            ->selectRaw('COALESCE(history.stay_count, 0) as stay_count')
            ->selectRaw('GREATEST(COALESCE(history.stay_count, 0) - 1, 0) as reentry_count')
            ->selectRaw("CASE WHEN cycle.id IS NULL THEN 'legacy' WHEN cycle.closed_at IS NOT NULL THEN 'dispatched' WHEN c.current_ingress_cycle_id = cycle.id AND c.estado_id = 1 THEN 'active' ELSE 'legacy' END as history_state");
    }
}
