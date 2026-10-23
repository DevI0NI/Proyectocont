<?php

namespace App\Http\Controllers;

use App\Models\Sucursal;
use App\Services\ContainerAuditAssignment;
use App\Services\MaintenanceExchangeRate;
use App\Services\MaintenanceWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MaintenanceController extends Controller
{
    private function context(Request $request): array
    {
        $branch = Sucursal::findOrFail((int) $request->session()->get('sucursal_activa'));
        app(MaintenanceWorkflow::class)->authorize($request->user(), (int) $branch->id);

        return ['sucursal' => $branch, 'sucursales' => Sucursal::activas()->orderBy('nombre')->get()];
    }

    public function orders(Request $request): View
    {
        return view('containers.index', $this->context($request) + ['maintenanceMode' => true]);
    }

    private function inventoryBackParams(Request $request, array $fallback = []): array
    {
        $fields = ['patio_id', 'bloque_id', 'search', 'per_page', 'page', 'sort', 'order', 'posicion', 'nombre',
            'estatus_unidad', 'estatus_box', 'estatus_ipsa', 'estatus_pyp', 'mantenimiento', 'fecha_desde', 'fecha_hasta', 'vista'];
        $source = $request->input('back');
        if (! is_array($source) || $source === []) {
            $source = array_replace($fallback, $request->only(['patio_id', 'bloque_id']));
        }
        $params = [];
        foreach (array_intersect_key($source, array_flip($fields)) as $key => $value) {
            if ((is_string($value) || is_int($value)) && (string) $value !== '') {
                $params[$key] = mb_substr((string) $value, 0, 255);
            }
        }

        return $params;
    }

    public function jobs(Request $request, MaintenanceWorkflow $workflow): View
    {
        $context = $this->context($request);
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(array_keys(MaintenanceWorkflow::STATES))], 'mine' => ['nullable', 'boolean']]);
        $query = $workflow->jobs((int) $context['sucursal']->id);
        if (! empty($data['search'])) {
            $query->where('c.nombre', 'like', '%'.$data['search'].'%');
        }
        if (! empty($data['status'])) {
            $query->where('j.status', $data['status']);
        }
        if ($request->boolean('mine')) {
            $query->where('j.mechanic_id', $request->user()->id);
        }

        return view('maintenance.orders', $context + ['jobs' => $query->orderByDesc('j.updated_at')->paginate(15)->withQueryString()]);
    }

    public function container(Request $request, int $container, MaintenanceWorkflow $workflow): View
    {
        $context = $this->context($request);
        $record = $workflow->containers((int) $context['sucursal']->id)->where('c.id', $container)
            ->leftJoin('estatus_contenedores as eu', 'eu.id', '=', 'c.estatus_unidad_id')
            ->leftJoin('estatus_contenedores as eb', 'eb.id', '=', 'c.estatus_box_id')
            ->leftJoin('estatus_ipsa as ei', 'ei.id', '=', 'c.estatus_ipsa_id')
            ->leftJoin('tipos_unidad as t', 't.id', '=', 'c.tipo_unidad_id')
            ->leftJoin('modelos as m', 'm.id', '=', 'c.modelo_id')
            ->leftJoin('container_ingress_cycles as ic', function ($join): void {
                $join->on('ic.id', '=', 'c.current_ingress_cycle_id')->on('ic.container_id', '=', 'c.id');
            })
            ->leftJoin('pyp_auditoria as pa', function ($join): void {
                $join->on('pa.contenedor_id', '=', 'c.id')->where('pa.estado_id', 1);
            })
            ->select(['c.id', 'c.nombre', 'c.fecha_ingreso', 'c.posicion', 'c.iso_code', 'c.marca', 'c.naviera',
                'c.current_ingress_cycle_id', 'c.estatus_ipsa_id', 'c.observaciones_unidad', 'c.observaciones_box',
                't.nombre as tipo_unidad_nombre', 'm.nombre as modelo_nombre', 'eu.nombre as estatus_unidad',
                'eb.nombre as estatus_box', 'ei.nombre as estatus_ipsa', 'p.id as patio_id', 'p.nombre as patio_nombre',
                'b.id as bloque_id', 'b.numero as bloque_numero', 'ic.shipping_line_name', 'ic.audit_company_name'])
            ->selectRaw(ContainerAuditAssignment::statusSql().' as audit_status')->first();
        abort_unless($record, 404);
        $job = $workflow->currentJobs($record)->where('branch_id', $context['sucursal']->id)->orderByDesc('id')->first();

        return view('maintenance.container', $context + ['container' => $record, 'mechanicMode' => true,
            'maintenanceStatus' => $job ? (MaintenanceWorkflow::STATES[$job->status] ?? $job->status)
                : ($workflow->outOfServiceStatuses()->where('id', $record->estatus_ipsa_id)->exists() ? 'Por atender' : 'Sin mantenimiento pendiente'),
            'backParams' => $this->inventoryBackParams($request, ['patio_id' => $record->patio_id, 'bloque_id' => $record->bloque_id])]);
    }

    public function create(Request $request, MaintenanceWorkflow $workflow): View|JsonResponse|RedirectResponse
    {
        $context = $this->context($request);
        if (! $request->filled('container_id')) {
            return redirect()->route('maintenance.orders');
        }
        $request->validate(['container_id' => ['required', 'integer']]);
        $container = $workflow->startableContainers((int) $context['sucursal']->id)
            ->where('c.id', $request->integer('container_id'))
            ->whereNotExists(function ($query): void {
                MaintenanceWorkflow::scopeCurrentIngress($query->selectRaw('1')->from('maintenance_jobs as j'))
                    ->whereIn('j.status', MaintenanceWorkflow::OPEN_STATES);
            })->leftJoin('estatus_contenedores as eu', 'eu.id', '=', 'c.estatus_unidad_id')
            ->leftJoin('estatus_contenedores as eb', 'eb.id', '=', 'c.estatus_box_id')
            ->addSelect('eu.nombre as unit_status', 'eb.nombre as box_status', 'p.id as yard_id', 'b.id as block_id')->first();
        abort_unless($container, 404, 'El contenedor ya no esta disponible para iniciar una reparacion.');
        $backParams = $this->inventoryBackParams($request, ['patio_id' => $container->yard_id, 'bloque_id' => $container->block_id]);
        if ($request->expectsJson()) {
            return response()->json(['name' => $container->nombre,
                'location' => $container->yard_name.' / Bloque '.$container->block_number.' / Pos. '.$container->posicion,
                'unit_status' => $container->unit_status, 'box_status' => $container->box_status,
                'unit_notes' => $container->observaciones_unidad, 'box_notes' => $container->observaciones_box,
                'evaluation_url' => route('maintenance.create', ['container_id' => $container->id, 'back' => $backParams])])
                ->header('Cache-Control', 'private, no-store');
        }

        return view('maintenance.create', $context + compact('container', 'backParams') + [
            'exchangeQuote' => app(MaintenanceExchangeRate::class)->quote(),
            'starting' => true, 'editable' => true, 'review' => null, 'materials' => collect(), 'evidence' => collect(),
            'parts' => DB::table('repair_parts')->where('status', 1)->orderBy('name')->get(),
            'repairServices' => DB::table('repair_services')->where('status', 1)->orderBy('name')->get(),
            'job' => (object) ['id' => null, 'version' => 0, 'container_name' => $container->nombre,
                'mechanic_name' => $request->user()->username, 'status' => 'Por atender',
                'diagnosis' => '', 'repair_service_id' => null, 'repair_type' => null, 'work_performed' => null],
        ]);
    }

    public function store(Request $request, MaintenanceWorkflow $workflow): RedirectResponse
    {
        $context = $this->context($request);
        $data = $request->validate(['container_id' => ['required', 'integer'], 'diagnosis' => ['nullable', 'string', 'max:1000'],
            'action' => ['sometimes', Rule::in(['save', 'submit'])], 'active_step' => ['nullable', 'integer', 'min:0', 'max:2'],
            'repair_service_id' => ['nullable', 'integer', 'min:1'], 'repair_type' => ['prohibited'], 'work_performed' => ['nullable', 'string', 'max:8000'],
            'exchange_quote' => ['nullable', 'string', 'max:2048'],
            'materials' => ['nullable', 'array', 'max:40'], 'materials.*.part_id' => ['required', 'integer', 'distinct'],
            'materials.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'min:0.001', 'max:100000'],
            'evidence_test' => ['prohibited'], 'evidence_after' => ['nullable', 'array', 'max:5'],
            'evidence_after.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'evidence_before' => ['nullable', 'array', 'max:5'],
            'evidence_before.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']], [
                'container_id.required' => 'Selecciona un contenedor.', 'diagnosis.required' => 'Describe el diagnostico inicial.',
                'diagnosis.min' => 'El diagnostico debe tener al menos 10 caracteres.',
                'diagnosis.max' => 'El diagnóstico no puede superar los 1000 caracteres.',
                'evidence_before.max' => 'Adjunta hasta 5 fotos del estado inicial.',
                'evidence_before.*.image' => 'Adjunta fotografias JPG, PNG o WEBP.',
                'evidence_before.*.max' => 'Cada fotografia debe pesar como maximo 2 MB.',
            ]);
        $photos = $request->file('evidence_before', []);
        $fingerprints = [];
        foreach ($photos as $index => $photo) {
            $fingerprint = hash_file('sha256', $photo->getRealPath());
            if ($fingerprint === false) {
                throw ValidationException::withMessages(['evidence_before' => 'No se pudo leer una fotografía. Selecciónala nuevamente.']);
            }
            if (isset($fingerprints[$fingerprint])) {
                throw ValidationException::withMessages(['evidence_before.'.$index => 'Esta fotografía está repetida. Adjunta imágenes diferentes.']);
            }
            $fingerprints[$fingerprint] = true;
        }
        $id = $workflow->start($request->user(), (int) $context['sucursal']->id, $data, $photos);

        return redirect()->route('maintenance.show', ['job' => $id, 'step' => $request->integer('active_step', 0), 'back' => $this->inventoryBackParams($request)])
            ->with('status', ($data['action'] ?? 'save') === 'submit' ? 'Solicitud de revisión registrada.' : 'Cambios guardados.');
    }

    public function show(Request $request, int $job, MaintenanceWorkflow $workflow): View
    {
        $context = $this->context($request);
        $record = $workflow->jobs((int) $context['sucursal']->id)->where('j.id', $job)->first();
        abort_unless($record, 404);
        $materials = DB::table('maintenance_materials')->where('job_id', $job)->get();
        $parts = DB::table('repair_parts')->where('status', 1)->orWhereIn('id', $materials->pluck('part_id'))->orderBy('name')->get();
        foreach ($parts as $part) {
            if ($snapshot = $materials->firstWhere('part_id', $part->id)) {
                $part->name = $snapshot->name;
                $part->unit = $snapshot->unit;
                if ($snapshot->currency !== null || $record->status !== 'in_progress') {
                    $part->reference_cost = $snapshot->unit_cost;
                    $part->currency = $snapshot->currency ?? $part->currency;
                }
            }
        }
        $repairServices = DB::table('repair_services')->where('status', 1)->orWhere('id', $record->repair_service_id)->orderBy('name')->get();
        foreach ($repairServices as $service) {
            if ((int) $service->id === (int) $record->repair_service_id && $record->repair_type) {
                $service->name = $record->repair_type;
                if ($record->service_unit !== null || $record->status !== 'in_progress') {
                    $service->unit = $record->service_unit ?? $service->unit;
                    $service->reference_cost = $record->service_unit_cost;
                    $service->currency = $record->service_currency ?? $service->currency;
                }
            }
        }
        $container = $workflow->containers((int) $context['sucursal']->id)->where('c.id', $record->container_id)
            ->addSelect('p.id as yard_id')->first();
        $editable = (int) $request->user()->rol_id === 4 && (int) $record->mechanic_id === (int) $request->user()->id
            && $record->status === 'in_progress' && $container && $workflow->currentJobs($container)->where('id', $job)->exists()
            && $workflow->repairableContainers((int) $context['sucursal']->id)->where('c.id', $record->container_id)->exists();
        $readonlyReason = $editable ? null : match ($record->status) {
            'pending_review' => 'Solo lectura: enviado a revisión',
            'completed' => 'Solo lectura: reparación finalizada',
            'returned_for_repair' => 'Solo lectura: reparación devuelta',
            default => (int) $record->mechanic_id !== (int) $request->user()->id
                ? 'Solo lectura: asignado a otro mecánico'
                : 'Solo lectura: el contenedor no está habilitado para reparación',
        };

        return view('maintenance.show', $context + ['job' => $record, 'materials' => $materials, 'parts' => $parts, 'repairServices' => $repairServices, 'editable' => $editable,
            'exchangeQuote' => $record->usd_gtq_rate !== null || $editable ? app(MaintenanceExchangeRate::class)->quote($record) : ['rate' => null, 'date' => null, 'token' => null],
            'container' => $container, 'readonlyReason' => $readonlyReason, 'returnUrl' => $this->localReturnUrl($request), 'backParams' => $this->inventoryBackParams($request, $container ? ['patio_id' => $container->yard_id, 'bloque_id' => $container->bloque_id] : []),
            'evidence' => DB::table('maintenance_evidence')->where('job_id', $job)->get(),
            'review' => DB::table('maintenance_review_requests')->where('job_id', $job)->first()]);
    }

    public function update(Request $request, int $job, MaintenanceWorkflow $workflow): RedirectResponse
    {
        $context = $this->context($request);
        $rules = ['version' => ['required', 'integer', 'min:1'], 'action' => ['required', Rule::in(['save', 'submit'])],
            'active_step' => ['nullable', 'integer', 'min:0', 'max:2'],
            'diagnosis' => ['sometimes', 'nullable', 'string', 'max:1000'], 'work_performed' => ['nullable', 'string', 'max:8000'],
            'repair_service_id' => ['nullable', 'integer', 'min:1'], 'repair_type' => ['prohibited'], 'evidence_test' => ['prohibited'],
            'exchange_quote' => ['nullable', 'string', 'max:2048'],
            'materials' => ['nullable', 'array', 'max:40'], 'materials.*.part_id' => ['required', 'integer', 'distinct'],
            'materials.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'min:0.001', 'max:100000']];
        foreach (['before', 'after'] as $stage) {
            $rules['evidence_'.$stage] = ['nullable', 'array', 'max:5'];
            $rules['evidence_'.$stage.'.*'] = ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'];
        }
        $data = $request->validate($rules, [
            'diagnosis.required' => 'Describe el diagnostico.', 'diagnosis.min' => 'El diagnostico debe tener al menos 10 caracteres.',
            'diagnosis.max' => 'El diagnóstico no puede superar los 1000 caracteres.',
            'materials.*.quantity.min' => 'La cantidad debe ser mayor que cero.', 'materials.*.quantity.decimal' => 'Utiliza hasta 3 decimales.',
            'materials.*.part_id.distinct' => 'No repitas el mismo material; ajusta su cantidad.',
            'repair_service_id.integer' => 'Selecciona una reparación del catálogo.',
            'evidence_before.max' => 'Se permiten hasta 5 fotos del antes.',
            'evidence_after.max' => 'Se permiten hasta 5 fotos del después.',
            'evidence_before.*.image' => 'Adjunta fotografías JPG, PNG o WEBP.',
            'evidence_after.*.image' => 'Adjunta fotografías JPG, PNG o WEBP.',
            'evidence_before.*.mimes' => 'Adjunta fotografías JPG, PNG o WEBP.',
            'evidence_after.*.mimes' => 'Adjunta fotografías JPG, PNG o WEBP.',
        ]);
        $uploads = [];
        foreach (['before', 'after'] as $stage) {
            $uploads[$stage] = $request->file('evidence_'.$stage, []);
        }
        if (collect($uploads)->flatten()->sum(fn ($file): int => $file->getSize()) > 20 * 1024 * 1024) {
            throw ValidationException::withMessages(['evidence' => 'Adjunta hasta 20 MB en cada guardado.']);
        }
        $workflow->save($request->user(), (int) $context['sucursal']->id, $job, $data, $uploads);

        return redirect()->route('maintenance.show', array_filter(['job' => $job, 'step' => $request->integer('active_step', 1), 'back' => $this->inventoryBackParams($request), 'return_to' => $this->localReturnUrl($request)], fn ($value): bool => $value !== null))->with('status', $data['action'] === 'submit'
            ? 'Solicitud de revision registrada. La reparacion no libera el contenedor.' : 'Avance guardado.');
    }

    public function evidence(Request $request, int $job, int $evidence): StreamedResponse
    {
        $context = $this->context($request);
        abort_unless(DB::table('maintenance_jobs')->where('id', $job)->where('branch_id', $context['sucursal']->id)->exists(), 404);
        $file = DB::table('maintenance_evidence')->where('id', $evidence)->where('job_id', $job)->first();
        abort_unless($file && Storage::disk('local')->exists($file->path), 404);

        $headers = ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'];
        if ($request->boolean('inline')) {
            abort_unless(in_array($file->mime, ['image/jpeg', 'image/png', 'image/webp'], true), 415);

            return Storage::disk('local')->response($file->path, $file->original_name, $headers);
        }

        return Storage::disk('local')->download($file->path, $file->original_name, $headers);
    }
}
