<?php

namespace App\Services;

use App\Models\Contenedor;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PypAuditService
{
    public const STATES = ['Pendiente de revision', 'En revision', 'Aprobado', 'Rechazado'];

    private const ACTIVE = 1;

    private const AVAILABLE_IPSA = 1;

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

            if ((int) $record->estatus_ipsa_id !== self::AVAILABLE_IPSA) {
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
            app(SystemNotifications::class)->auditAssigned($container, $branchId);
            app(SystemNotifications::class)->activity('audits', 'Contenedor asignado a revisión externa', $container->nombre,
                [['field' => 'Seguimiento de auditoría', 'before' => null, 'after' => 'Asignado']], [$branchId], $user);
        });
    }

    public function update(User $user, int $branchId, int $containerId, array $data): void
    {
        abort_unless((int) $user->rol_id === 5, 403, 'El veredicto externo corresponde a la empresa auditora.');
        if ((int) $user->rol_id === 5) {
            app(ExternalAuditAccess::class)->authorizeBranch($user, $branchId);
        }
        $data = Validator::make($data, [
            'estatus_pyp' => ['required', Rule::in(self::STATES)],
            'informacion_adicional' => ['required', 'string', 'max:150'],
            'maintenance_version' => ['nullable', 'integer'],
        ], ['estatus_pyp.in' => 'Selecciona un estado de auditoria valido.',
            'informacion_adicional.required' => 'Describe la revision o el motivo del resultado.'])->validate();
        DB::transaction(function () use ($user, $branchId, $containerId, $data): void {
            $container = Contenedor::where('id', $containerId)->lockForUpdate()->firstOrFail();
            $hasOpenWork = $container->current_ingress_cycle_id
                ? MaintenanceWorkflow::hasOpenWork((int) $container->current_ingress_cycle_id)
                : app(MaintenanceWorkflow::class)->currentJobs($container)->whereIn('status', MaintenanceWorkflow::OPEN_STATES)->exists();
            if ($hasOpenWork) {
                throw new \RuntimeException('El contenedor tiene mantenimiento o revision interna pendiente.');
            }
            $job = app(MaintenanceWorkflow::class)->currentJobs($container)->latest('id')->lockForUpdate()->first();
            if ($job && (int) ($data['maintenance_version'] ?? 0) !== (int) $job->version) {
                throw new \RuntimeException('La reparación cambió. Recarga y revisa las evidencias antes de emitir el dictamen.');
            }
            $record = app(ExternalAuditAccess::class)->scope($this->containerQuery($branchId), $user, $branchId)
                ->where('c.id', $containerId)
                ->whereNotNull('pa.contenedor_id')
                ->lockForUpdate()
                ->first();

            if (! $record) {
                throw new \RuntimeException('No se encontro una auditoria PYP activa para este contenedor.');
            }
            if ((int) $record->estatus_ipsa_id !== self::AVAILABLE_IPSA) {
                throw new \RuntimeException('El contenedor está pendiente de liberación IPSA. Puedes consultar sus antecedentes, pero todavía no emitir un dictamen.');
            }

            $newStatus = trim($data['estatus_pyp']);
            $newInfo = trim($data['informacion_adicional']);
            if ($newStatus === 'Rechazado') {
                app(MaintenanceCorrections::class)->request($container, $user, $newInfo);
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

            if ($record->estatus_pyp !== $newStatus || $record->informacion_adicional !== $newInfo) {
                if ($job && $newStatus !== 'Rechazado') {
                    DB::table('maintenance_jobs')->where('id', $job->id)->update(['version' => $job->version + 1, 'updated_at' => now()]);
                }
                DB::table('container_assignment_events')->insert([
                    'container_id' => $record->id, 'ingress_cycle_id' => $container->current_ingress_cycle_id,
                    'user_id' => $user->id, 'event_type' => 'external_audit_status', 'reason' => $newInfo,
                    'before_data' => json_encode(['audit' => ['estatus_pyp' => $record->estatus_pyp,
                        'informacion_adicional' => $record->informacion_adicional, 'usuario_id' => $record->usuario_id,
                        'external_audit_company_id' => $record->external_audit_company_id]], JSON_THROW_ON_ERROR),
                    'after_data' => json_encode(['audit' => ['estatus_pyp' => $newStatus,
                        'informacion_adicional' => $newInfo, 'usuario_id' => $user->id,
                        'external_audit_company_id' => $record->external_audit_company_id]], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
                $this->logEvent($record->id, $user->id, 'Auditoria PYP actualizada. Estatus: '.$newStatus.'.');
                $changes = app(SystemNotifications::class)->changes(['status' => $record->estatus_pyp, 'notes' => $record->informacion_adicional],
                    ['status' => $newStatus, 'notes' => $newInfo], ['status' => 'Dictamen', 'notes' => 'Observaciones']);
                app(SystemNotifications::class)->activity('audits', 'Auditoría externa actualizada', $container->nombre, $changes, [$branchId], $user);
            }
        });
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
