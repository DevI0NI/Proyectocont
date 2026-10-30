<?php

namespace App\Services;

use App\Models\Contenedor;
use App\Models\EstatusIpsa;
use App\Models\User;
use App\Rules\MeaningfulTechnicalText;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PypAuditService
{
    public const STATES = ['Pendiente de revision', 'En revision', 'Aprobado', 'Rechazado'];

    public const RESULTS = ['Aprobado', 'Rechazado'];

    public static function withRevision(Builder $query): Builder
    {
        return $query->selectSub(fn (Builder $events) => $events->from('container_assignment_events as revision_event')
            ->selectRaw('COALESCE(MAX(revision_event.id), 0)')->whereColumn('revision_event.container_id', 'c.id'), 'audit_revision');
    }

    private const ACTIVE = 1;

    public function history(object $container): Builder
    {
        $events = DB::table('container_assignment_events as e')->leftJoin('users as inspector', 'inspector.id', '=', 'e.user_id')
            ->where('e.container_id', $container->id)->where('e.ingress_cycle_id', $container->current_ingress_cycle_id)
            ->where('e.event_type', 'external_audit_status')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(e.after_data, '$.audit.external_audit_company_id')) = ?", [(string) $container->external_audit_company_id])
            ->whereIn(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(e.after_data, '$.audit.estatus_pyp'))"), ['Aprobado', 'Rechazado'])
            ->select('e.id', 'e.created_at', 'e.user_id as inspector_id')
            ->selectRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(e.after_data, '$.audit.inspector_name')), inspector.username, 'Usuario no disponible') as inspector_name")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(e.after_data, '$.audit.estatus_pyp')) as status")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(e.after_data, '$.audit.informacion_adicional')) as notes")
            ->selectRaw("JSON_EXTRACT(e.after_data, '$.audit.photos') as photos");
        // Older assignments may have a verdict without a corresponding event.
        $legacy = DB::table('pyp_auditoria as legacy')->leftJoin('users as inspector', 'inspector.id', '=', 'legacy.usuario_id')
            ->where('legacy.contenedor_id', $container->id)->where('legacy.estado_id', self::ACTIVE)
            ->where('legacy.ingress_cycle_id', $container->current_ingress_cycle_id)
            ->where('legacy.external_audit_company_id', $container->external_audit_company_id)
            ->whereIn('legacy.estatus_pyp', ['Aprobado', 'Rechazado'])
            ->whereNotExists((clone $events)->reorder()->selectRaw('1')->whereColumn('e.created_at', 'legacy.fecha_registro')
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(e.after_data, '$.audit.estatus_pyp')) = legacy.estatus_pyp"))
            ->selectRaw("0 as id, legacy.fecha_registro as created_at, legacy.usuario_id as inspector_id, COALESCE(inspector.username, 'Usuario no disponible') as inspector_name, legacy.estatus_pyp as status, legacy.informacion_adicional as notes, NULL as photos");

        return DB::query()->fromSub($events->unionAll($legacy), 'audit_history')->select('audit_history.*')
            ->selectRaw("DATE_FORMAT(DATE_SUB(audit_history.created_at, INTERVAL 6 HOUR), '%d/%m/%Y %H:%i') as display_date");
    }

    public function assign(User $user, int $branchId, int $containerId): void
    {
        abort_unless(in_array((int) $user->rol_id, [1, 2], true) && $user->hasPermission('pyp.assign'), 403);
        abort_if((int) $user->rol_id === 5, 403, 'Una empresa externa no puede adjudicarse contenedores.');
        DB::transaction(function () use ($user, $branchId, $containerId): void {
            $container = Contenedor::where('id', $containerId)->lockForUpdate()->firstOrFail();
            $cycle = $container->current_ingress_cycle_id ? DB::table('container_ingress_cycles')->find($container->current_ingress_cycle_id) : null;
            if (! $cycle || ! $cycle->audit_company_id) {
                throw new \RuntimeException('Confirma la naviera y auditora desde Modificar contenedor antes de asignar una revision.');
            }
            $record = $this->containerQuery($branchId)
                ->where('c.id', $containerId)
                ->lockForUpdate()
                ->first();

            if (! $record) {
                throw new \RuntimeException('El contenedor no existe.');
            }

            if (! EstatusIpsa::available()->whereKey($record->estatus_ipsa_id)->exists()) {
                throw new \RuntimeException('El contenedor no esta Disponible (IPSA). No se puede asignar.');
            }

            $alreadyAssigned = DB::table('pyp_auditoria')
                ->where('contenedor_id', $record->id)
                ->where('estado_id', self::ACTIVE)
                ->exists();

            if ($alreadyAssigned) {
                throw new \RuntimeException('Este contenedor ya esta asignado/auditado.');
            }

            DB::table('pyp_auditoria')->insert([
                'contenedor_id' => $record->id,
                'external_audit_company_id' => $cycle->audit_company_id,
                'ingress_cycle_id' => $cycle->id,
                'estatus_pyp' => 'Asignado',
                'fecha_registro' => now(),
                'usuario_id' => $user->id,
                'estado_id' => self::ACTIVE,
            ]);

            $this->logEvent($record->id, $user->id, 'Contenedor asignado para auditoria PYP');
            app(SystemNotifications::class)->activity('audits', 'Contenedor asignado a revisión externa', $container->nombre,
                [['field' => 'Seguimiento de auditoría', 'before' => null, 'after' => 'Asignado']], [$branchId], $user);
        });
    }

    public function begin(User $user, int $branchId, int $containerId, int $revision): void
    {
        DB::transaction(function () use ($user, $branchId, $containerId, $revision): void {
            [$container, $record] = $this->reviewContext($user, $branchId, $containerId);
            if ($record->estatus_pyp === 'En revision') {
                return;
            }
            if ($revision !== (int) $record->audit_revision) {
                throw new \RuntimeException('La auditoría cambió. Recarga la tabla antes de iniciar.');
            }
            $this->preserveLegacyVerdict($container, $record);
            DB::table('pyp_auditoria')->where('contenedor_id', $containerId)->where('estado_id', self::ACTIVE)
                ->update(['estatus_pyp' => 'En revision', 'informacion_adicional' => null, 'usuario_id' => $user->id, 'fecha_registro' => now()]);
            $this->recordReview($container, $record, $user, 'En revision', '');
            $this->logEvent($containerId, $user->id, 'Auditoria externa iniciada.');
        });
    }

    public function update(User $user, int $branchId, int $containerId, array $data): void
    {
        abort_unless((int) $user->rol_id === 5, 403, 'El veredicto externo corresponde a la empresa auditora.');
        if ((int) $user->rol_id === 5) {
            app(ExternalAuditAccess::class)->authorizeBranch($user, $branchId);
        }
        $action = $data['action'] ?? 'finalize';
        $data = Validator::make($data + ['action' => $action], [
            'action' => ['required', Rule::in(['save', 'finalize'])],
            'estatus_pyp' => [Rule::requiredIf($action === 'finalize'), 'nullable', Rule::in(self::RESULTS)],
            'informacion_adicional' => [Rule::requiredIf($action === 'finalize'), 'nullable', 'string', 'max:2000'],
            'maintenance_version' => ['nullable', 'integer'],
            'audit_version' => ['nullable', 'integer', 'min:0'],
            'photos' => ['nullable', 'array', 'max:5'],
            'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:2048'],
            'remove_photos' => ['nullable', 'array', 'max:5'],
            'remove_photos.*' => ['integer', 'min:0', 'max:4', 'distinct'],
        ], ['estatus_pyp.in' => 'Selecciona un estado de auditoria valido.',
            'informacion_adicional.required' => 'Describe la revision o el motivo del resultado.'])->validate();
        $stored = [];
        try {
            DB::transaction(function () use ($user, $branchId, $containerId, $data, &$stored): void {
                [$container, $record, $job] = $this->reviewContext($user, $branchId, $containerId);
                if ($job && (int) ($data['maintenance_version'] ?? 0) !== (int) $job->version) {
                    throw new \RuntimeException('La reparación cambió. Recarga y revisa las evidencias antes de emitir el dictamen.');
                }
                if ($record->estatus_pyp !== 'En revision') {
                    throw new \RuntimeException('La auditoría no está en revisión o ya fue finalizada. Inicia una auditoría desde la tabla principal.');
                }
                if (! isset($data['audit_version']) || (int) $data['audit_version'] !== (int) $record->audit_revision) {
                    throw ValidationException::withMessages(['audit_version' => 'La auditoría cambió. Recarga antes de guardar para no sobrescribir otro avance.']);
                }
                $finalize = $data['action'] === 'finalize';
                $newStatus = $finalize ? $data['estatus_pyp'] : 'En revision';
                $newInfo = trim($data['informacion_adicional'] ?? '');
                if ($finalize && mb_strlen($newInfo) < 30) {
                    throw ValidationException::withMessages(['informacion_adicional' => 'Escribe al menos 30 caracteres en las observaciones antes de finalizar.']);
                }
                if ($finalize) {
                    Validator::make(['informacion_adicional' => $newInfo], [
                        'informacion_adicional' => [new MeaningfulTechnicalText],
                    ])->validate();
                }
                $previousPhotos = json_decode($this->latestReview($container, (int) $record->external_audit_company_id)?->after_data ?? '{}', true)['audit']['photos'] ?? [];
                $photos = $this->storePhotos($container, $record, $previousPhotos, $data, $stored);
                if ($newStatus === 'Rechazado' && ! count($photos)) {
                    throw ValidationException::withMessages(['photos' => 'Adjunta al menos una foto del defecto antes de rechazar la auditoría.']);
                }
                $changed = $record->estatus_pyp !== $newStatus || (string) $record->informacion_adicional !== $newInfo || $previousPhotos !== $photos;
                if (! $changed) {
                    return;
                }
                $correctionId = $job ? DB::table('maintenance_corrections')->where('job_id', $job->id)->where('status', 'submitted')->latest('number')->value('id') : null;
                if ($newStatus === 'Rechazado') {
                    app(MaintenanceCorrections::class)->request($container, $user, $newInfo, $photos);
                } elseif ($newStatus === 'Aprobado') {
                    app(MaintenanceCorrections::class)->approve($container);
                }

                DB::table('pyp_auditoria')
                    ->where('contenedor_id', $record->id)
                    ->where('estado_id', self::ACTIVE)
                    ->update([
                        'estatus_pyp' => $newStatus,
                        'informacion_adicional' => $newInfo,
                        'usuario_id' => $user->id,
                        'fecha_registro' => now(),
                    ]);

                if ($finalize && $job && $newStatus !== 'Rechazado') {
                    DB::table('maintenance_jobs')->where('id', $job->id)->update(['version' => $job->version + 1, 'updated_at' => now()]);
                }
                $reviewId = $this->recordReview($container, $record, $user, $newStatus, $newInfo, $photos, $correctionId ? (int) $correctionId : null);
                if ($newStatus === 'Aprobado' && $job) {
                    app(SystemNotifications::class)->maintenanceResult((int) $job->id,
                        $correctionId ? 'Corrección aprobada por la auditora' : 'Reparación aprobada por la auditora',
                        $newInfo, 'audit-approved', $reviewId,
                        ['actor' => $user->username, 'correction_id' => $correctionId ? (int) $correctionId : null, 'audit_id' => $reviewId]);
                }
                if ($newStatus === 'Rechazado' && ! $job) {
                    app(SystemNotifications::class)->auditRepairRequested($container, $branchId);
                }
                $this->logEvent($record->id, $user->id, 'Auditoria PYP actualizada. Estatus: '.$newStatus.'.');
                $changes = app(SystemNotifications::class)->changes(['status' => $record->estatus_pyp, 'notes' => $record->informacion_adicional],
                    ['status' => $newStatus, 'notes' => $newInfo], ['status' => 'Dictamen', 'notes' => 'Observaciones']);
                app(SystemNotifications::class)->activity('audits', 'Auditoría externa actualizada', $container->nombre, $changes, [$branchId], $user);
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($stored);
            throw $exception;
        }
    }

    public function latestReview(object $container, int $companyId): ?object
    {
        return DB::table('container_assignment_events')->where('container_id', $container->id)
            ->where('ingress_cycle_id', $container->current_ingress_cycle_id)->where('event_type', 'external_audit_status')
            ->where('after_data->audit->external_audit_company_id', $companyId)
            ->latest('id')->first();
    }

    private function storePhotos(object $container, object $record, array $previous, array $data, array &$stored): array
    {
        $photos = array_values(array_filter($previous, fn (array $photo, int $index): bool => ! in_array($index, array_map('intval', $data['remove_photos'] ?? []), true), ARRAY_FILTER_USE_BOTH));
        $uploads = $data['photos'] ?? [];
        if (count($photos) + count($uploads) > 5) {
            throw ValidationException::withMessages(['photos' => 'Se permiten hasta 5 fotos por auditoría.']);
        }
        foreach ($uploads as $file) {
            $hash = hash_file('sha256', $file->getRealPath());
            if (collect($photos)->contains(fn (array $photo): bool => ($photo['hash'] ?? null) === $hash)) {
                throw ValidationException::withMessages(['photos' => 'Esta fotografía ya está adjunta a la auditoría.']);
            }
            $path = $file->store('external-audits/'.$container->id.'/'.$container->current_ingress_cycle_id.'/'.$record->external_audit_company_id, 'local');
            if (! $path) {
                throw new \RuntimeException('No se pudo guardar la fotografía.');
            }
            $stored[] = $path;
            $photos[] = ['path' => $path, 'name' => mb_substr(basename($file->getClientOriginalName()), 0, 255), 'mime' => $file->getMimeType(), 'hash' => $hash];
        }

        return $photos;
    }

    private function containerQuery(int $branchId)
    {
        return DB::table('contenedores as c')
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
                'pa.usuario_id',
                'pa.external_audit_company_id',
                'pa.contenedor_id as es_asignado',
                'b.id as bloque_id',
                'b.numero as bloque_numero',
                'p.id as patio_id',
                'p.nombre as patio_nombre',
            ]);
    }

    private function reviewContext(User $user, int $branchId, int $containerId): array
    {
        abort_unless((int) $user->rol_id === 5, 403);
        app(ExternalAuditAccess::class)->authorizeBranch($user, $branchId);
        $container = Contenedor::whereKey($containerId)->lockForUpdate()->firstOrFail();
        $workflow = app(MaintenanceWorkflow::class);
        if ($workflow->currentJobs($container)->whereIn('status', MaintenanceWorkflow::OPEN_STATES)->exists()) {
            throw new \RuntimeException('El contenedor tiene mantenimiento o revision interna pendiente.');
        }
        $job = $workflow->currentJobs($container)->latest('id')->lockForUpdate()->first();
        $record = app(ExternalAuditAccess::class)->scope(self::withRevision($this->containerQuery($branchId)), $user, $branchId)
            ->where('c.id', $containerId)->whereNotNull('pa.contenedor_id')->lockForUpdate()->first();
        if (! $record) {
            throw new \RuntimeException('No se encontro una auditoria activa para este contenedor.');
        }
        if (! EstatusIpsa::available()->whereKey($record->estatus_ipsa_id)->exists()) {
            throw new \RuntimeException('El contenedor está pendiente de liberación IPSA. Puedes consultar sus antecedentes, pero todavía no emitir un dictamen.');
        }

        return [$container, $record, $job];
    }

    private function recordReview(object $container, object $before, User $user, string $status, string $notes, array $photos = [], ?int $correctionId = null): int
    {
        return DB::table('container_assignment_events')->insertGetId([
            'container_id' => $container->id, 'ingress_cycle_id' => $container->current_ingress_cycle_id,
            'user_id' => $user->id, 'event_type' => 'external_audit_status', 'reason' => mb_substr($notes, 0, 255),
            'before_data' => json_encode(['audit' => ['estatus_pyp' => $before->estatus_pyp, 'informacion_adicional' => $before->informacion_adicional,
                'usuario_id' => $before->usuario_id, 'external_audit_company_id' => $before->external_audit_company_id]], JSON_THROW_ON_ERROR),
            'after_data' => json_encode(['audit' => ['estatus_pyp' => $status, 'informacion_adicional' => $notes,
                'usuario_id' => $user->id, 'inspector_name' => $user->username, 'external_audit_company_id' => $before->external_audit_company_id,
                'maintenance_correction_id' => $correctionId, 'photos' => $photos]], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    private function preserveLegacyVerdict(object $container, object $record): void
    {
        if (! in_array($record->estatus_pyp, ['Aprobado', 'Rechazado'], true)) {
            return;
        }
        $exists = DB::table('container_assignment_events')->where('container_id', $container->id)
            ->where('ingress_cycle_id', $container->current_ingress_cycle_id)->where('event_type', 'external_audit_status')
            ->where('created_at', $record->fecha_registro)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(after_data, '$.audit.external_audit_company_id')) = ?", [(string) $record->external_audit_company_id])
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(after_data, '$.audit.estatus_pyp')) = ?", [$record->estatus_pyp])->exists();
        if (! $exists) {
            DB::table('container_assignment_events')->insert([
                'container_id' => $container->id, 'ingress_cycle_id' => $container->current_ingress_cycle_id,
                'user_id' => $record->usuario_id, 'event_type' => 'external_audit_status', 'reason' => mb_substr($record->informacion_adicional ?? '', 0, 255),
                'before_data' => null, 'after_data' => json_encode(['audit' => [
                    'estatus_pyp' => $record->estatus_pyp, 'informacion_adicional' => $record->informacion_adicional,
                    'usuario_id' => $record->usuario_id, 'external_audit_company_id' => $record->external_audit_company_id,
                ]], JSON_THROW_ON_ERROR), 'created_at' => $record->fecha_registro,
            ]);
        }
    }

    private function logEvent(int $containerId, int $userId, string $message): void
    {
        DB::table('logs_eventos')->insert([
            'contenedor_id' => $containerId,
            'usuario_id' => $userId,
            'tipo_operacion_id' => 4,
            'observaciones' => $message,
            'estado_id' => self::ACTIVE,
        ]);
    }
}
