<?php

namespace App\Http\Controllers;

use App\Models\Sucursal;
use App\Services\AdministrationTable;
use App\Services\ExternalAuditAccess;
use App\Services\MaintenanceWorkflow;
use App\Services\PypAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PypAuditController extends Controller
{
    private const ACTIVE = 1;

    public function dashboard(Request $request): View
    {
        $access = app(ExternalAuditAccess::class);
        abort_unless($access->isSupervisor($request->user()), 403);
        $branchId = (int) $request->session()->get('sucursal_activa');
        $access->authorizeBranch($request->user(), $branchId);

        return view('external-audits.dashboard', [
            'sucursal' => Sucursal::activas()->findOrFail($branchId),
            'sucursales' => $access->branches($request->user())->orderBy('nombre')->get(),
        ]);
    }

    public function index(Request $request): View|RedirectResponse
    {
        if ((int) $request->user()->rol_id !== 5) {
            return redirect()->route('containers.index', $request->only(['patio_id', 'bloque_id']));
        }
        app(ExternalAuditAccess::class)->authorizeBranch($request->user(), (int) $request->session()->get('sucursal_activa'));
        $request->validate(['search' => ['nullable', 'string', 'max:255'], 'nombre' => ['nullable', 'string', 'max:255']]);
        $branch = $this->activeBranch($request);
        abort_if(! $branch, 404, 'No hay sucursal activa.');

        return view('containers.index', [
            'sucursal' => $branch,
            'sucursales' => app(ExternalAuditAccess::class)->branches($request->user())->orderBy('nombre')->get(),
            'pypMode' => true,
            'externalCompanyName' => DB::table('external_audit_companies')->where('id', $request->user()->external_audit_company_id)->value('name'),
            'returnUrl' => $this->localReturnUrl($request),
        ]);
    }

    public function assign(Request $request, int $container, PypAuditService $pypAuditService): RedirectResponse
    {
        $branch = $this->activeBranch($request);
        abort_if(! $branch, 404, 'No hay sucursal activa.');

        $redirectParams = $this->redirectParams($request);

        try {
            $pypAuditService->assign($request->user(), $branch->id, $container);
        } catch (\RuntimeException $exception) {
            return redirect()->route('pyp-audits.index', $redirectParams)->withErrors(['pyp' => $exception->getMessage()]);
        }

        return redirect()->route('pyp-audits.index', $redirectParams)->with('status', 'Contenedor asignado correctamente.');
    }

    public function edit(Request $request, int $container): View|RedirectResponse
    {
        abort_unless((int) $request->user()->rol_id === 5, 403);
        app(ExternalAuditAccess::class)->authorizeBranch($request->user(), (int) $request->session()->get('sucursal_activa'));
        $branch = $this->activeBranch($request);
        abort_if(! $branch, 404, 'No hay sucursal activa.');

        $record = $this->containerQuery($branch->id)
            ->where('c.id', $container)
            ->whereNotNull('pa.contenedor_id')
            ->first();

        if (! $record) {
            return redirect()
                ->route('pyp-audits.index', $this->redirectParams($request))
                ->withErrors(['pyp' => 'No se encontró una auditoría externa asignada para este contenedor.']);
        }
        if (! $record->ipsa_available) {
            return redirect()->route('pyp-audits.index', $this->redirectParams($request))
                ->withErrors(['pyp' => 'Solo puedes auditar contenedores con estado IPSA Disponible.']);
        }
        if ($record->estatus_pyp !== 'En revision') {
            return redirect()->route('pyp-audits.history', array_filter(['container' => $container, 'return_to' => $this->localReturnUrl($request)]));
        }

        $job = app(MaintenanceWorkflow::class)->currentJobs(DB::table('contenedores')->find($container))->latest('id')->first();
        $correctionId = $job ? DB::table('maintenance_corrections')->where('job_id', $job->id)
            ->whereIn('status', ['submitted', 'approved', 'rejected'])->orderByDesc('number')->value('id') : null;

        return view('pyp-audits.edit', [
            'photoReview' => app(PypAuditService::class)->latestReview($record, (int) $record->external_audit_company_id),
            'maintenanceJob' => $job,
            'maintenanceCorrectionId' => $correctionId,
            'canInspect' => (bool) $record->ipsa_available && (! $job || $job->status === 'completed'),
            'sucursal' => $branch,
            'sucursales' => app(ExternalAuditAccess::class)->branches($request->user())->orderBy('nombre')->get(),
            'externalCompanyName' => DB::table('external_audit_companies')->where('id', $request->user()->external_audit_company_id)->value('name'),
            'container' => $record,
            'backParams' => $this->redirectParams($request),
            'returnUrl' => $this->localReturnUrl($request),
        ]);
    }

    public function information(Request $request, int $container): View
    {
        $context = $this->auditContext($request, $container);

        return view('containers.show', $context + ['externalMode' => true]);
    }

    public function begin(Request $request, int $container, PypAuditService $audits): RedirectResponse
    {
        $request->validate(['audit_version' => ['required', 'integer', 'min:0']]);
        $branch = $this->activeBranch($request);
        abort_unless($branch, 404);
        try {
            $audits->begin($request->user(), (int) $branch->id, $container, $request->integer('audit_version'));
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['pyp' => $exception->getMessage()]);
        }

        return redirect()->route('pyp-audits.edit', array_filter(['container' => $container, 'return_to' => $this->localReturnUrl($request)]));
    }

    public function history(Request $request, int $container, PypAuditService $audits): View
    {
        $context = $this->auditContext($request, $container);
        $query = $audits->history($context['container']);
        $inspectors = (clone $query)->get()->unique('inspector_id')->sortBy('inspector_name')->pluck('inspector_name', 'inspector_id')->all();

        return view('pyp-audits.history', $context + [
            'audits' => AdministrationTable::paginate($query, $request, 'audit-history', 'audit_history'),
            'tableColumns' => AdministrationTable::columns('audit-history'),
            'filterOptions' => ['inspector' => $inspectors, 'status' => ['Aprobado' => 'Aprobado', 'Rechazado' => 'Rechazado']],
        ]);
    }

    public function show(Request $request, int $container, int $audit, PypAuditService $audits): View
    {
        $context = $this->auditContext($request, $container);
        $record = $audits->history($context['container'])->where('audit_history.id', $audit)->firstOrFail();

        return view('pyp-audits.show', array_replace($context, [
            'audit' => $record,
            'returnUrl' => $this->localReturnUrl($request) ?? route('pyp-audits.history', ['container' => $container]),
        ]));
    }

    public function photo(Request $request, int $container, int $audit, int $photo, PypAuditService $audits): BinaryFileResponse
    {
        $context = $this->auditContext($request, $container);
        $record = $audits->history($context['container'])->where('audit_history.id', $audit)->first();
        $photos = json_decode($record->photos ?? '[]', true) ?? [];
        if (! $record) {
            $draft = $audits->latestReview($context['container'], (int) $context['container']->external_audit_company_id);
            abort_unless($draft && (int) $draft->id === $audit && $context['container']->estatus_pyp === 'En revision', 404);
            $photos = json_decode($draft->after_data, true)['audit']['photos'] ?? [];
        }
        $image = $photos[$photo] ?? null;
        abort_unless($image && in_array($image['mime'], ['image/jpeg', 'image/png', 'image/webp'], true) && Storage::disk('local')->exists($image['path']), 404);

        return response()->file(Storage::disk('local')->path($image['path']), ['Content-Type' => $image['mime'], 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    private function auditContext(Request $request, int $container): array
    {
        abort_unless((int) $request->user()->rol_id === 5, 403);
        $branchId = (int) $request->session()->get('sucursal_activa');
        $access = app(ExternalAuditAccess::class);
        $access->authorizeBranch($request->user(), $branchId);
        $record = $this->containerQuery($branchId)->where('c.id', $container)->whereNotNull('pa.contenedor_id')->firstOrFail();
        $job = app(MaintenanceWorkflow::class)->currentJobs($record)->latest('id')->first();

        return [
            'container' => $record, 'maintenanceJob' => $job,
            'canInspect' => (bool) $record->ipsa_available && (! $job || $job->status === 'completed'),
            'sucursal' => Sucursal::findOrFail($branchId),
            'sucursales' => $access->branches($request->user())->orderBy('nombre')->get(),
            'externalCompanyName' => DB::table('external_audit_companies')->where('id', $request->user()->external_audit_company_id)->value('name'),
            'backParams' => $this->redirectParams($request),
            'returnUrl' => $this->localReturnUrl($request) ?? route('pyp-audits.index', $this->redirectParams($request)),
        ];
    }

    public function update(Request $request, int $container, PypAuditService $pypAuditService): RedirectResponse|JsonResponse
    {
        $branch = $this->activeBranch($request);
        abort_if(! $branch, 404, 'No hay sucursal activa.');

        $data = $request->validate([
            'action' => ['nullable', Rule::in(['save', 'finalize'])],
            'estatus_pyp' => ['nullable', Rule::in(PypAuditService::RESULTS)],
            'informacion_adicional' => ['nullable', 'string', 'max:2000'],
            'patio_id' => ['nullable', 'integer'],
            'bloque_id' => ['nullable', 'integer'],
            'maintenance_version' => ['nullable', 'integer'],
            'audit_version' => ['nullable', 'integer', 'min:0'],
            'photos' => ['nullable', 'array', 'max:5'],
            'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:2048'],
            'remove_photos' => ['nullable', 'array', 'max:5'],
            'remove_photos.*' => ['integer', 'min:0', 'max:4', 'distinct'],
        ], [
            'estatus_pyp.required' => 'El estado de auditoría externa es obligatorio.',
            'estatus_pyp.in' => 'Selecciona un estado de auditoria valido.',
            'estatus_pyp.max' => 'El estatus no puede superar los 25 caracteres.',
            'informacion_adicional.required' => 'La informacion adicional es obligatoria.',
            'informacion_adicional.max' => 'Las observaciones no pueden superar los 2000 caracteres.',
        ]);

        try {
            $pypAuditService->update($request->user(), $branch->id, $container, $data);
        } catch (\RuntimeException $exception) {
            if ($request->expectsJson()) {
                throw ValidationException::withMessages(['pyp' => $exception->getMessage()]);
            }

            return back()->withErrors(['pyp' => $exception->getMessage()])->withInput();
        }

        $params = array_filter(['container' => $container, 'return_to' => $this->localReturnUrl($request)]);
        if (($data['action'] ?? 'finalize') === 'save') {
            $response = redirect()->route('pyp-audits.edit', $params)->with('status', 'Avance de auditoría guardado.');
        } else {
            $response = redirect()->route('pyp-audits.history', $params)->with('status', 'Auditoría finalizada correctamente.');
        }

        return $request->expectsJson() ? response()->json(['redirect' => $response->getTargetUrl()]) : $response;
    }

    private function containerQuery(int $branchId)
    {
        $query = DB::table('contenedores as c')
            ->join('bloques as b', 'c.bloque_id', '=', 'b.id')
            ->join('patios as p', 'b.patio_id', '=', 'p.id')
            ->leftJoin('pyp_auditoria as pa', function ($join): void {
                $join->on('c.id', '=', 'pa.contenedor_id')->where('pa.estado_id', self::ACTIVE);
            })
            ->leftJoin('estatus_contenedores as eu', 'eu.id', '=', 'c.estatus_unidad_id')
            ->leftJoin('estatus_contenedores as eb', 'eb.id', '=', 'c.estatus_box_id')
            ->leftJoin('estatus_ipsa as ei', 'ei.id', '=', 'c.estatus_ipsa_id')
            ->leftJoin('tipos_unidad as t', 't.id', '=', 'c.tipo_unidad_id')
            ->leftJoin('modelos as m', 'm.id', '=', 'c.modelo_id')
            ->leftJoin('container_ingress_cycles as ic', 'ic.id', '=', 'c.current_ingress_cycle_id')
            ->where('c.estado_id', self::ACTIVE)
            ->where('p.sucursal_id', $branchId)
            ->select([
                'c.id',
                'c.nombre',
                'c.posicion',
                'c.estatus_ipsa_id',
                'c.current_ingress_cycle_id', 'c.fecha_ingreso', 'c.iso_code', 'c.marca', 'c.naviera',
                'c.observaciones_unidad', 'c.observaciones_box',
                'eu.nombre as estatus_unidad', 'eb.nombre as estatus_box', 'ei.nombre as estatus_ipsa',
                't.nombre as tipo_unidad_nombre', 'm.nombre as modelo_nombre',
                'ic.shipping_line_name', 'ic.audit_company_name',
                'pa.external_audit_company_id', 'pa.estatus_pyp as audit_status',
                'pa.estatus_pyp',
                'pa.informacion_adicional',
                'pa.fecha_registro',
                'pa.contenedor_id as es_asignado',
                'b.id as bloque_id',
                'b.numero as bloque_numero',
                'p.id as patio_id',
                'p.nombre as patio_nombre',
            ]);

        $query->selectRaw("CASE WHEN UPPER(TRIM(ei.nombre)) = 'DISPONIBLE' AND ei.estado_id = 1 THEN 1 ELSE 0 END as ipsa_available");

        return app(ExternalAuditAccess::class)->scope(PypAuditService::withRevision($query), auth()->user(), $branchId);
    }

    private function redirectParams(Request $request): array
    {
        return array_filter([
            'patio_id' => $request->integer('patio_id') ?: null,
            'bloque_id' => $request->integer('bloque_id') ?: null,
        ]);
    }

    private function activeBranch(Request $request): ?Sucursal
    {
        $branchId = (int) $request->session()->get('sucursal_activa');

        return $branchId > 0 ? Sucursal::find($branchId) : null;
    }
}
