<?php

namespace App\Http\Controllers;

use App\Models\Sucursal;
use App\Services\ExternalAuditAccess;
use App\Services\MaintenanceWorkflow;
use App\Services\PypAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

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

        $job = app(MaintenanceWorkflow::class)->currentJobs(DB::table('contenedores')->find($container))->latest('id')->first();

        return view('pyp-audits.edit', [
            'maintenanceJob' => $job,
            'canInspect' => (int) $record->estatus_ipsa_id === 1 && (! $job || $job->status === 'completed'),
            'sucursal' => $branch,
            'sucursales' => app(ExternalAuditAccess::class)->branches($request->user())->orderBy('nombre')->get(),
            'externalCompanyName' => DB::table('external_audit_companies')->where('id', $request->user()->external_audit_company_id)->value('name'),
            'container' => $record,
            'backParams' => $this->redirectParams($request),
            'returnUrl' => $this->localReturnUrl($request),
        ]);
    }

    public function update(Request $request, int $container, PypAuditService $pypAuditService): RedirectResponse
    {
        $branch = $this->activeBranch($request);
        abort_if(! $branch, 404, 'No hay sucursal activa.');

        $data = $request->validate([
            'estatus_pyp' => ['required', Rule::in(PypAuditService::STATES)],
            'informacion_adicional' => ['required', 'string', 'max:150'],
            'patio_id' => ['nullable', 'integer'],
            'bloque_id' => ['nullable', 'integer'],
            'maintenance_version' => ['nullable', 'integer'],
        ], [
            'estatus_pyp.required' => 'El estado de auditoría externa es obligatorio.',
            'estatus_pyp.in' => 'Selecciona un estado de auditoria valido.',
            'estatus_pyp.max' => 'El estatus no puede superar los 25 caracteres.',
            'informacion_adicional.required' => 'La informacion adicional es obligatoria.',
            'informacion_adicional.max' => 'La informacion adicional no puede superar los 150 caracteres.',
        ]);

        $redirectParams = $this->redirectParams($request);

        try {
            $pypAuditService->update($request->user(), $branch->id, $container, $data);
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['pyp' => $exception->getMessage()])->withInput();
        }

        return redirect()->to($this->localReturnUrl($request) ?? route('pyp-audits.index', $redirectParams))->with('status', 'Auditoria guardada correctamente.');
    }

    private function containerQuery(int $branchId)
    {
        $query = DB::table('contenedores as c')
            ->join('bloques as b', 'c.bloque_id', '=', 'b.id')
            ->join('patios as p', 'b.patio_id', '=', 'p.id')
            ->leftJoin('pyp_auditoria as pa', function ($join): void {
                $join->on('c.id', '=', 'pa.contenedor_id')->where('pa.estado_id', self::ACTIVE);
            })
            ->where('c.estado_id', self::ACTIVE)
            ->where('p.sucursal_id', $branchId)
            ->select([
                'c.id',
                'c.nombre',
                'c.posicion',
                'c.estatus_ipsa_id',
                'pa.estatus_pyp',
                'pa.informacion_adicional',
                'pa.fecha_registro',
                'pa.contenedor_id as es_asignado',
                'b.id as bloque_id',
                'b.numero as bloque_numero',
                'p.id as patio_id',
                'p.nombre as patio_nombre',
            ]);

        return app(ExternalAuditAccess::class)->scope($query, auth()->user(), $branchId);
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
