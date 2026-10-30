<?php

namespace App\Services;

use App\Models\Contenedor;
use App\Models\User;
use App\Rules\MeaningfulTechnicalText;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MaintenanceWorkflow
{
    public const STATES = ['in_progress' => 'En reparacion', 'returned_for_repair' => 'Devuelto a reparacion', 'pending_review' => 'Reparado', 'completed' => 'Reparado'];

    public const OPEN_STATES = ['in_progress', 'pending_review', 'returned_for_repair'];

    public function authorize(User $user, int $branchId): void
    {
        abort_unless((int) $user->estado_id === 1 && (int) $user->rol_id === 4, 403, 'Esta interfaz es exclusiva del mecanico.');
        app(ExternalAuditAccess::class)->authorizeBranch($user, $branchId);
    }

    public function containers(int $branchId): Builder
    {
        return DB::table('contenedores as c')->join('bloques as b', 'b.id', '=', 'c.bloque_id')
            ->join('patios as p', 'p.id', '=', 'b.patio_id')->where('p.sucursal_id', $branchId)
            ->where('c.estado_id', 1)->select('c.*', 'p.nombre as yard_name', 'b.numero as block_number');
    }

    public function jobs(int $branchId): Builder
    {
        return DB::table('maintenance_jobs as j')->join('contenedores as c', 'c.id', '=', 'j.container_id')
            ->join('users as u', 'u.id', '=', 'j.mechanic_id')->where('j.branch_id', $branchId)
            ->select('j.*', 'c.nombre as container_name', 'u.username as mechanic_name');
    }

    public function outOfServiceStatuses(): Builder
    {
        return DB::table('estatus_ipsa')->where('estado_id', 1)->whereRaw("UPPER(TRIM(nombre)) = 'OOS'");
    }

    public function repairableContainers(int $branchId): Builder
    {
        return $this->containers($branchId)->where(function (Builder $eligible) use ($branchId): void {
            $eligible->whereIn('c.estatus_ipsa_id', $this->outOfServiceStatuses()->select('id'))
                ->orWhere(function (Builder $audit) use ($branchId): void {
                    $audit->whereIn('c.estatus_ipsa_id', DB::table('estatus_ipsa')->where('estado_id', 1)->whereRaw("UPPER(TRIM(nombre)) = 'DISPONIBLE'")->select('id'))
                        ->where(function (Builder $requests) use ($branchId): void {
                            $requests->whereIn('c.id', $this->auditRequestedContainers($branchId)->select('c.id'))
                                ->orWhereExists(function (Builder $jobs): void {
                                    self::scopeCurrentIngress($jobs->selectRaw('1')->from('maintenance_jobs as j'))
                                        ->where('j.status', 'in_progress')->whereNotNull('j.source_audit_event_id');
                                });
                        });
                });
        })
            ->where(function (Builder $ingress) use ($branchId): void {
                $ingress->where(function (Builder $legacy): void {
                    $legacy->whereNull('c.current_ingress_cycle_id')->whereNotNull('c.fecha_ingreso');
                })->orWhereExists(function (Builder $query) use ($branchId): void {
                    $query->selectRaw('1')->from('container_ingress_cycles as cycle')
                        ->whereColumn('cycle.id', 'c.current_ingress_cycle_id')->whereColumn('cycle.container_id', 'c.id')
                        ->where('cycle.branch_id', $branchId)->whereNull('cycle.closed_at');
                });
            });
    }

    public static function scopeCurrentIngress(Builder $query): Builder
    {
        return $query->whereColumn('j.container_id', 'c.id')->where(function (Builder $ingress): void {
            $ingress->whereColumn('j.ingress_cycle_id', 'c.current_ingress_cycle_id')
                ->orWhere(function (Builder $legacy): void {
                    $legacy->whereNull('j.ingress_cycle_id')->whereNull('c.current_ingress_cycle_id')
                        ->whereColumn('j.legacy_ingress_at', 'c.fecha_ingreso');
                });
        });
    }

    public function auditRequestedContainers(int $branchId): Builder
    {
        return $this->containers($branchId)
            ->join('container_ingress_cycles as request_cycle', 'request_cycle.id', '=', 'c.current_ingress_cycle_id')
            ->join('pyp_auditoria as request_audit', 'request_audit.contenedor_id', '=', 'c.id')
            ->whereColumn('request_cycle.container_id', 'c.id')->where('request_cycle.branch_id', $branchId)->whereNull('request_cycle.closed_at')
            ->where('request_audit.estado_id', 1)->where('request_audit.estatus_pyp', 'Rechazado')
            ->whereColumn('request_audit.ingress_cycle_id', 'request_cycle.id')
            ->whereColumn('request_audit.external_audit_company_id', 'request_cycle.audit_company_id')
            ->whereNotExists(fn (Builder $jobs) => self::scopeCurrentIngress($jobs->selectRaw('1')->from('maintenance_jobs as j')))
            ->whereExists(function (Builder $events): void {
                $events->selectRaw('1')->from('container_assignment_events as request_event')
                    ->whereColumn('request_event.container_id', 'c.id')->whereColumn('request_event.ingress_cycle_id', 'request_cycle.id')
                    ->where('request_event.event_type', 'external_audit_status')
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(request_event.after_data, '$.audit.estatus_pyp')) = 'Rechazado'")
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(request_event.after_data, '$.audit.external_audit_company_id')) = CAST(request_cycle.audit_company_id AS CHAR)")
                    ->whereNotExists(function (Builder $newer): void {
                        $newer->selectRaw('1')->from('container_assignment_events as newer_request')
                            ->whereColumn('newer_request.container_id', 'request_event.container_id')
                            ->whereColumn('newer_request.ingress_cycle_id', 'request_event.ingress_cycle_id')
                            ->where('newer_request.event_type', 'external_audit_status')->whereColumn('newer_request.id', '>', 'request_event.id')
                            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(newer_request.after_data, '$.audit.external_audit_company_id')) = CAST(request_cycle.audit_company_id AS CHAR)");
                    });
            });
    }

    public function pendingAuditRequest(object $container, int $branchId): ?object
    {
        if (! $this->auditRequestedContainers($branchId)->where('c.id', $container->id)->exists()) {
            return null;
        }
        $company = DB::table('container_ingress_cycles')->where('id', $container->current_ingress_cycle_id)->value('audit_company_id');

        return app(PypAuditService::class)->latestReview($container, (int) $company);
    }

    public function startableContainers(int $branchId): Builder
    {
        return $this->repairableContainers($branchId)->whereNotExists(function (Builder $query): void {
            $query->selectRaw('1')->from('estatus_contenedores as pending_status')
                ->whereRaw("UPPER(TRIM(pending_status.nombre)) LIKE 'PENDIENT%'")
                ->where(function (Builder $status): void {
                    $status->whereColumn('pending_status.id', 'c.estatus_unidad_id')
                        ->orWhereColumn('pending_status.id', 'c.estatus_box_id');
                });
        });
    }

    public function currentJobs(object $container): Builder
    {
        $query = DB::table('maintenance_jobs')->where('container_id', $container->id);
        if (! $container->current_ingress_cycle_id && ! $container->fecha_ingreso) {
            return $query->whereRaw('1 = 0');
        }

        return $container->current_ingress_cycle_id
            ? $query->where('ingress_cycle_id', $container->current_ingress_cycle_id)
            : $query->whereNull('ingress_cycle_id')->where('legacy_ingress_at', Carbon::parse($container->fecha_ingreso)->toDateTimeString());
    }

    public static function hasOpenWork(int $cycleId): bool
    {
        return DB::table('maintenance_jobs')->where('ingress_cycle_id', $cycleId)
            ->whereIn('status', self::OPEN_STATES)->exists();
    }

    public function start(User $user, int $branchId, array $data, array $photos = []): int
    {
        $this->authorize($user, $branchId);

        $stored = [];
        try {
            return DB::transaction(function () use ($user, $branchId, $data, $photos, &$stored): int {
                $container = Contenedor::whereKey($data['container_id'])->lockForUpdate()->firstOrFail();
                abort_unless($this->containers($branchId)->where('c.id', $container->id)->exists(), 404);
                if ($this->currentJobs($container)->whereIn('status', self::OPEN_STATES)->exists()) {
                    throw ValidationException::withMessages(['container_id' => 'Este ingreso ya tiene un trabajo abierto o pendiente de revision.']);
                }
                if (! $this->repairableContainers($branchId)->where('c.id', $container->id)->exists()) {
                    throw ValidationException::withMessages(['container_id' => 'Se requiere estado IPSA OOS o una solicitud técnica vigente de la auditora, y un ingreso activo en esta sucursal.']);
                }
                if (! $this->startableContainers($branchId)->where('c.id', $container->id)->exists()) {
                    throw ValidationException::withMessages(['container_id' => 'Revisa primero los estados de Unidad y Box. No se puede iniciar una reparacion mientras alguno este pendiente.']);
                }
                $hasDetails = collect([$data['diagnosis'] ?? '', $data['repair_service_id'] ?? '', $data['work_performed'] ?? ''])
                    ->contains(fn ($value): bool => trim((string) $value) !== '');
                if (! $hasDetails
                    && $photos === [] && empty($data['materials']) && empty($data['evidence_after'])) {
                    throw ValidationException::withMessages(['work' => 'Registra algún dato o una fotografía antes de guardar.']);
                }
                $auditRequest = $this->pendingAuditRequest($container, $branchId);
                if (($auditRequest || ! empty($data['audit_request_version'])) && (int) ($data['audit_request_version'] ?? 0) !== (int) $auditRequest?->id) {
                    throw ValidationException::withMessages(['container_id' => 'La solicitud de la auditora cambió. Recarga y revisa sus observaciones antes de iniciar.']);
                }
                $id = DB::table('maintenance_jobs')->insertGetId([
                    'source_audit_event_id' => $auditRequest?->id,
                    'container_id' => $container->id, 'branch_id' => $branchId, 'ingress_cycle_id' => $container->current_ingress_cycle_id,
                    'legacy_ingress_at' => $container->current_ingress_cycle_id ? null : $container->fecha_ingreso,
                    'mechanic_id' => $user->id, 'status' => 'in_progress', 'diagnosis' => $data['diagnosis'] ?? '',
                    'version' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach ($photos as $photo) {
                    $this->storeEvidence($id, 'before', $photo, $user, $stored);
                }
                $audit = DB::table('pyp_auditoria')->where('contenedor_id', $container->id)->where('estado_id', 1)->first();
                $this->event($id, $user, 'started', null, ['job' => DB::table('maintenance_jobs')->find($id), 'previous_audit' => $audit, 'new_evidence' => $stored]);
                DB::table('pyp_auditoria')->where('contenedor_id', $container->id)->where('estado_id', 1)
                    ->update(['estatus_pyp' => 'Asignado', 'informacion_adicional' => null, 'usuario_id' => $user->id, 'fecha_registro' => now()]);

                if (! empty($data['repair_service_id']) || ! empty($data['work_performed']) || ! empty($data['materials']) || ! empty($data['evidence_after']) || ($data['action'] ?? 'save') === 'submit') {
                    $this->save($user, $branchId, $id, array_merge($data, ['version' => 1]), ['after' => $data['evidence_after'] ?? []]);
                }

                return $id;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($stored);
            throw $exception;
        }
    }

    public function save(User $user, int $branchId, int $jobId, array $data, array $uploads): void
    {
        $this->authorize($user, $branchId);
        $stored = [];
        try {
            DB::transaction(function () use ($user, $branchId, $jobId, $data, $uploads, &$stored): void {
                $reference = DB::table('maintenance_jobs')->where('id', $jobId)->where('branch_id', $branchId)->first();
                abort_unless($reference, 404);
                $container = Contenedor::whereKey($reference->container_id)->lockForUpdate()->firstOrFail();
                $job = DB::table('maintenance_jobs')->where('id', $jobId)->lockForUpdate()->first();
                abort_unless((int) $job->mechanic_id === (int) $user->id, 403, 'No puedes modificar el trabajo de otro mecanico.');
                if ($job->status !== 'in_progress' || (int) $job->version !== (int) $data['version']) {
                    throw ValidationException::withMessages(['work' => 'El trabajo cambio o ya fue enviado a revision. Recarga la pagina antes de continuar.']);
                }
                abort_unless((int) $container->estado_id === 1 && $this->currentJobs($container)->where('id', $jobId)->exists()
                    && $this->containers($branchId)->where('c.id', $container->id)->exists()
                    && (! $job->ingress_cycle_id || DB::table('container_ingress_cycles')->where('id', $job->ingress_cycle_id)->whereNull('closed_at')->exists()), 409, 'El ingreso ya no esta activo en esta sucursal.');
                $materials = [];
                if (! $this->repairableContainers($branchId)->where('c.id', $container->id)->exists()) {
                    throw ValidationException::withMessages(['work' => 'El contenedor ya no tiene estado IPSA OOS. El trabajo se conserva en consulta; no se pueden registrar reparaciones.']);
                }
                foreach ($data['materials'] ?? [] as $item) {
                    $part = DB::table('repair_parts')->where('id', $item['part_id'])->lockForUpdate()->first();
                    $previous = DB::table('maintenance_materials')->where('job_id', $jobId)->where('part_id', $item['part_id'])->first();
                    if (! $part || ((int) $part->status !== 1 && ! $previous)) {
                        throw ValidationException::withMessages(['materials' => 'Selecciona materiales activos del catalogo.']);
                    }
                    $materials[] = ['job_id' => $jobId, 'part_id' => $part->id, 'name' => $previous->name ?? $part->name,
                        'unit' => $previous->unit ?? $part->unit, 'quantity' => $item['quantity'],
                        'unit_cost' => $previous?->currency !== null ? $previous->unit_cost : $part->reference_cost,
                        'currency' => $previous->currency ?? $part->currency];
                }
                $before = ['job' => $job, 'materials' => DB::table('maintenance_materials')->where('job_id', $jobId)->get()];
                $evidence = DB::table('maintenance_evidence')->where('job_id', $jobId)->get();
                foreach (['before', 'after'] as $stage) {
                    if ($evidence->where('stage', $stage)->count() + count($uploads[$stage] ?? []) > 5) {
                        throw ValidationException::withMessages(['evidence' => 'Se permiten hasta 5 fotos del antes y 5 del después.']);
                    }
                }
                $submit = ($data['action'] ?? 'save') === 'submit';
                $diagnosis = array_key_exists('diagnosis', $data) ? trim((string) $data['diagnosis']) : $job->diagnosis;
                if ($submit && mb_strlen(trim($diagnosis)) < 30) {
                    throw ValidationException::withMessages(['diagnosis' => 'Describe el diagnóstico con al menos 30 caracteres antes de solicitar revisión.']);
                }
                $serviceId = array_key_exists('repair_service_id', $data) ? ((int) $data['repair_service_id'] ?: null) : $job->repair_service_id;
                $repairType = null;
                $serviceUnit = $serviceCost = $serviceCurrency = null;
                if ($serviceId) {
                    $service = DB::table('repair_services')->where('id', $serviceId)->lockForUpdate()->first();
                    if (! $service || ((int) $service->status !== 1 && (int) $job->repair_service_id !== (int) $serviceId)) {
                        throw ValidationException::withMessages(['repair_service_id' => 'Selecciona una reparación activa del catálogo.']);
                    }
                    $repairType = (int) $job->repair_service_id === (int) $serviceId && $job->repair_type ? $job->repair_type : $service->name;
                    $recorded = (int) $job->repair_service_id === (int) $serviceId && $job->service_unit !== null;
                    $serviceUnit = 'Servicio';
                    $serviceCost = $recorded ? $job->service_unit_cost : $service->reference_cost;
                    $serviceCurrency = $recorded ? $job->service_currency : $service->currency;
                }
                if ($submit && ! $serviceId) {
                    throw ValidationException::withMessages(['repair_service_id' => 'Selecciona el tipo de reparación antes de solicitar revisión.']);
                }
                $stages = $evidence->whereIn('mime', ['image/jpeg', 'image/png', 'image/webp'])->pluck('stage')->all();
                foreach ($uploads as $stage => $files) {
                    foreach ($files as $file) {
                        if (str_starts_with((string) $file->getMimeType(), 'image/')) {
                            $stages[] = $stage;
                        }
                    }
                }
                if ($submit && mb_strlen(trim($data['work_performed'] ?? '')) < 30) {
                    throw ValidationException::withMessages(['work_performed' => 'Describe el trabajo realizado con al menos 30 caracteres antes de solicitar revisión.']);
                }
                if ($submit) {
                    Validator::make(['diagnosis' => $diagnosis, 'work_performed' => $data['work_performed']], [
                        'diagnosis' => [new MeaningfulTechnicalText],
                        'work_performed' => [new MeaningfulTechnicalText],
                    ])->validate();
                }
                if ($submit && (! in_array('before', $stages, true) || ! in_array('after', $stages, true))) {
                    throw ValidationException::withMessages(['evidence' => 'Adjunta al menos una foto de antes y una de después de la reparación. Ambas son obligatorias para solicitar revisión.']);
                }
                foreach ($uploads as $stage => $files) {
                    foreach ($files as $file) {
                        $this->storeEvidence($jobId, $stage, $file, $user, $stored);
                    }
                }
                DB::table('maintenance_materials')->where('job_id', $jobId)->delete();
                if ($materials !== []) {
                    DB::table('maintenance_materials')->insert($materials);
                }
                $usesDollars = $serviceCurrency === 'USD' || collect($materials)->contains('currency', 'USD');
                $exchange = $usesDollars ? app(MaintenanceExchangeRate::class)->snapshot($job, $data['exchange_quote'] ?? null) : [];
                DB::table('maintenance_jobs')->where('id', $jobId)->update($exchange + [
                    'diagnosis' => $diagnosis,
                    'repair_service_id' => $serviceId, 'repair_type' => $repairType, 'work_performed' => $data['work_performed'] ?? null,
                    'service_quantity' => 1,
                    'service_unit' => $serviceUnit, 'service_unit_cost' => $serviceCost, 'service_currency' => $serviceCurrency,
                    'version' => $job->version + 1, 'status' => $submit ? 'pending_review' : 'in_progress',
                    'submitted_at' => $submit ? now() : null, 'updated_at' => now(),
                ]);
                if ($submit) {
                    $counter = DB::table('maintenance_counters')->where('name', 'review_request')->lockForUpdate()->first();
                    if (! $counter) {
                        throw new \LogicException('Falta configurar el consecutivo de solicitudes de mantenimiento.');
                    }
                    $folio = (int) $counter->last_value + 1;
                    DB::table('maintenance_counters')->where('name', 'review_request')->update(['last_value' => $folio]);
                    $reviewId = DB::table('maintenance_review_requests')->insertGetId([
                        'folio' => $folio,
                        'job_id' => $jobId, 'branch_id' => $branchId, 'requested_by' => $user->id,
                        'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    app(SystemNotifications::class)->reviewRequested($reviewId);
                }
                $this->event($jobId, $user, $submit ? 'review_requested' : 'saved', $before, [
                    'job' => DB::table('maintenance_jobs')->find($jobId), 'materials' => $materials, 'new_evidence' => $stored,
                ]);
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($stored);
            throw $exception;
        }
    }

    private function storeEvidence(int $jobId, string $stage, UploadedFile $file, User $user, array &$stored): void
    {
        $path = $file->store('maintenance/'.$jobId, 'local');
        if (! $path) {
            throw new \RuntimeException('No se pudo guardar la evidencia. Intenta de nuevo.');
        }
        $stored[] = $path;
        DB::table('maintenance_evidence')->insert([
            'job_id' => $jobId, 'stage' => $stage, 'path' => $path,
            'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
            'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'uploaded_by' => $user->id, 'created_at' => now(),
        ]);
    }

    private function event(int $jobId, User $user, string $action, ?array $before, array $after): void
    {
        DB::table('maintenance_events')->insert(['job_id' => $jobId, 'user_id' => $user->id, 'action' => $action,
            'before_data' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_data' => json_encode($after, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}
