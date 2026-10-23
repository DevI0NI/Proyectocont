<?php

namespace App\Http\Controllers;

use App\Models\Sucursal;
use App\Services\AdministrationTable;
use App\Services\ExternalAuditAccess;
use App\Services\MaintenanceCorrections;
use App\Services\MaintenanceWorkflow;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MaintenanceCorrectionController extends Controller
{
    public function detail(Request $request, int $job, MaintenanceCorrections $corrections): View
    {
        $branchId = (int) session('sucursal_activa');
        $record = $corrections->visibleJob($request->user(), $branchId, $job, true);
        $rounds = DB::table('maintenance_corrections')->where('job_id', $job)
            ->when((int) $request->user()->rol_id !== 4, fn ($query) => $query->where('status', '!=', 'draft'))
            ->orderBy('number')->get();
        $selected = $request->integer('intervention', 0);
        $intervention = $selected ? $rounds->firstWhere('id', $selected) : null;
        abort_if($selected && ! $intervention, 404);
        $actions = $intervention ? ['canConfirmWork' => false, 'canUpdateTechnicalStates' => false]
            : $corrections->reviewActions($request->user(), $branchId, $record);
        $materials = DB::table('maintenance_materials')->where('job_id', $job)->get();
        $evidence = DB::table('maintenance_evidence')->where('job_id', $job)->get();
        $originalCreatedAt = $record->created_at;
        if ($intervention) {
            $record = clone $record;
            $record->diagnosis = $intervention->reason;
            $record->work_performed = $intervention->work_performed;
            $record->created_at = $intervention->created_at;
            $record->service_unit_cost = null;
            $materials = collect(json_decode($intervention->materials ?? '[]', true))->map(fn (array $material): object => (object) $material);
            $evidence = collect(json_decode($intervention->photos ?? '[]', true))->map(fn (array $photo, int $index): object => (object) [
                'id' => $index, 'stage' => 'after', 'original_name' => $photo['name'],
                'url' => route('corrections.photo', ['job' => $job, 'round' => $intervention->id, 'photo' => $index]),
            ]);
        }

        return view('notifications.repair', $actions + [
            'job' => $record,
            'readOnlyDetail' => true,
            'review' => DB::table('maintenance_review_requests')->where('job_id', $job)->latest('id')->first(),
            'confirmation' => DB::table('maintenance_events as e')->leftJoin('users as u', 'u.id', '=', 'e.user_id')
                ->where('e.job_id', $job)->where('e.action', 'repair_confirmed')->orderByDesc('e.id')
                ->select('e.created_at', 'u.username')->first(),
            'materials' => $materials,
            'evidence' => $evidence,
            'interventions' => $rounds,
            'selectedIntervention' => $intervention,
            'originalCreatedAt' => $originalCreatedAt,
            'sucursal' => Sucursal::findOrFail($branchId),
            'sucursales' => app(ExternalAuditAccess::class)->branches($request->user())->orderBy('nombre')->get(),
            'returnUrl' => $this->localReturnUrl($request) ?? route('corrections.history', ['job' => $job], false),
        ]);
    }

    public function history(Request $request, int $job, MaintenanceCorrections $corrections): View
    {
        $branchId = (int) session('sucursal_activa');
        $record = $corrections->visibleJob($request->user(), $branchId, $job, true);
        $role = (int) $request->user()->rol_id;
        $query = app(MaintenanceWorkflow::class)->jobs($branchId)->where('j.container_id', $record->container_id);
        if ($role === 4) {
            $query->where('j.mechanic_id', $request->user()->id);
        } elseif ($role === 5) {
            $query->where('j.ingress_cycle_id', $record->ingress_cycle_id)->where('j.status', '!=', 'in_progress');
        }

        return $this->historyResponse($request, $record, $query, $role === 4 ? route('maintenance.show', $job) : route($role === 5 ? 'pyp-audits.index' : 'containers.index'));
    }

    public function confirm(Request $request, int $job, MaintenanceCorrections $corrections): RedirectResponse
    {
        $request->validate(['version' => ['required', 'integer']]);
        $corrections->confirm($request->user(), (int) session('sucursal_activa'), $job, $request->integer('version'));

        return redirect()->route('corrections.detail', array_filter(['job' => $job, 'step' => 2, 'return_to' => $this->localReturnUrl($request)]))
            ->with('status', 'Trabajo confirmado. Los estados del contenedor se actualizan por separado.');
    }

    public function containerHistory(Request $request, int $container): View
    {
        $branchId = (int) session('sucursal_activa');
        abort_unless(in_array((int) $request->user()->rol_id, [1, 2, 3], true) && $request->user()->hasPermission('containers.view'), 403);
        app(ExternalAuditAccess::class)->authorizeBranch($request->user(), $branchId);
        $record = DB::table('contenedores as c')->join('bloques as b', 'b.id', '=', 'c.bloque_id')
            ->join('patios as p', 'p.id', '=', 'b.patio_id')->where('p.sucursal_id', $branchId)
            ->where('c.id', $container)->where('c.estado_id', 1)
            ->select('c.id as container_id', 'c.nombre as container_name')->firstOrFail();
        $query = app(MaintenanceWorkflow::class)->jobs($branchId)->where('j.container_id', $container);

        return $this->historyResponse($request, $record, $query, route('containers.index'));
    }

    private function historyResponse(Request $request, object $record, Builder $query, string $defaultReturn): View
    {
        $branchId = (int) session('sucursal_activa');
        $role = (int) $request->user()->rol_id;
        $query->selectSub(function ($rounds) use ($role): void {
            $rounds->from('maintenance_corrections')->selectRaw('COUNT(*)')->whereColumn('job_id', 'j.id');
            if ($role !== 4) {
                $rounds->where('status', '!=', 'draft');
            }
        }, 'correction_count');
        $cases = [];
        $bindings = [];
        foreach (MaintenanceWorkflow::STATES as $status => $label) {
            $cases[] = 'WHEN ? THEN ?';
            array_push($bindings, $status, $label);
        }
        $query->selectRaw('CASE j.status '.implode(' ', $cases).' ELSE j.status END as status_label', $bindings)
            ->selectRaw("DATE_FORMAT(DATE_SUB(j.created_at, INTERVAL 6 HOUR), '%d/%m/%Y %H:%i') as display_date");
        $historyQuery = DB::query()->fromSub($query, 'repair_history')->select('repair_history.*');
        $available = (clone $historyQuery)->select('repair_history.repair_type', 'repair_history.mechanic_id', 'repair_history.mechanic_name')->distinct()->get();
        $filterOptions = [
            'name' => $available->pluck('repair_type')->filter(fn ($name): bool => is_string($name) && trim($name) !== '')
                ->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->mapWithKeys(fn (string $name): array => [$name => $name])->all(),
            'mechanic' => $available->unique('mechanic_id')->sortBy('mechanic_name', SORT_NATURAL | SORT_FLAG_CASE)
                ->pluck('mechanic_name', 'mechanic_id')->all(),
            'status' => MaintenanceWorkflow::STATES,
        ];

        return view('maintenance.history', [
            'job' => $record,
            'jobs' => AdministrationTable::paginate($historyQuery, $request, 'repair-history', 'repair_history'),
            'tableColumns' => AdministrationTable::columns('repair-history'),
            'filterOptions' => $filterOptions,
            'sucursal' => Sucursal::findOrFail($branchId),
            'sucursales' => app(ExternalAuditAccess::class)->branches($request->user())->orderBy('nombre')->get(),
            'returnUrl' => $this->localReturnUrl($request) ?? $defaultReturn,
        ]);
    }

    public function show(Request $request, int $job, MaintenanceCorrections $corrections): View
    {
        $branchId = (int) session('sucursal_activa');
        $record = $corrections->visibleJob($request->user(), $branchId, $job, true);
        $rounds = DB::table('maintenance_corrections')->where('job_id', $job)->orderBy('number')->get();
        $editable = (int) $request->user()->rol_id === 4 && $record->status === 'returned_for_repair';
        if (! $editable) {
            $rounds = $rounds->where('status', '!=', 'draft');
        }
        $draft = $editable ? $rounds->firstWhere('status', 'draft') : null;
        $partIds = collect(json_decode($draft->materials ?? '[]', true))->pluck('part_id');

        return view('maintenance.corrections', ['job' => $record, 'rounds' => $rounds, 'draft' => $draft,
            'originalPhotos' => DB::table('maintenance_evidence')->where('job_id', $job)->get(),
            'originalMaterials' => DB::table('maintenance_materials')->where('job_id', $job)->get(),
            'parts' => DB::table('repair_parts')->where('status', 1)->orWhereIn('id', $partIds)->orderBy('name')->get(),
            'sucursal' => Sucursal::findOrFail($branchId), 'sucursales' => app(ExternalAuditAccess::class)->branches($request->user())->orderBy('nombre')->get(),
            'returnUrl' => $this->localReturnUrl($request) ?? ((int) $request->user()->rol_id === 4 ? route('maintenance.show', $job) : route('pyp-audits.index'))]);
    }

    public function update(Request $request, int $job, MaintenanceCorrections $corrections): RedirectResponse
    {
        $request->validate(['photos' => ['nullable', 'array']]);
        $corrections->save($request->user(), (int) session('sucursal_activa'), $job, $request->all(), $request->file('photos', []));

        return redirect()->route('corrections.show', array_filter(['job' => $job, 'return_to' => $this->localReturnUrl($request)]))
            ->with('status', $request->input('action') === 'submit' ? 'Corrección enviada a la auditora.' : 'Borrador de corrección guardado.');
    }

    public function photo(Request $request, int $job, int $round, int $photo, MaintenanceCorrections $corrections): BinaryFileResponse
    {
        $corrections->visibleJob($request->user(), (int) session('sucursal_activa'), $job, true);
        $record = DB::table('maintenance_corrections')->where('job_id', $job)->where('id', $round)->firstOrFail();
        abort_if($record->status === 'draft' && (int) $request->user()->rol_id !== 4, 404);
        $image = json_decode($record->photos ?? '[]', true)[$photo] ?? null;
        abort_unless($image && in_array($image['mime'], ['image/jpeg', 'image/png', 'image/webp'], true) && Storage::disk('local')->exists($image['path']), 404);

        return response()->file(Storage::disk('local')->path($image['path']), ['Content-Type' => $image['mime'], 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function originalPhoto(Request $request, int $job, int $photo, MaintenanceCorrections $corrections): BinaryFileResponse
    {
        $corrections->visibleJob($request->user(), (int) session('sucursal_activa'), $job, true);
        $image = DB::table('maintenance_evidence')->where('job_id', $job)->where('id', $photo)->firstOrFail();
        abort_unless(in_array($image->mime, ['image/jpeg', 'image/png', 'image/webp'], true) && Storage::disk('local')->exists($image->path), 404);

        return response()->file(Storage::disk('local')->path($image->path), ['Content-Type' => $image->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
