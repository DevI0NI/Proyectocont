<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ContainerHistoryTimeline
{
    public function query(int $branchId, int $containerId): Builder
    {
        $base = DB::table('container_ingress_cycles as cycle')->where('cycle.branch_id', $branchId)->where('cycle.container_id', $containerId);
        $firstCycle = (clone $base)->orderBy('cycle.entered_at')->orderBy('cycle.id')->value('cycle.id');
        $ingress = (clone $base)->select('cycle.id as cycle_id', 'cycle.entered_at as cycle_start')
            ->selectRaw("CASE WHEN cycle.id = ? THEN 'ingress' ELSE 'reentry' END as kind", [$firstCycle])
            ->selectRaw('0 as phase, LEAST(DATE_ADD(cycle.entered_at, INTERVAL 6 HOUR), COALESCE(cycle.created_at, DATE_ADD(cycle.entered_at, INTERVAL 6 HOUR))) as occurred_at, cycle.id as entry_id, NULL as actor, cycle.shipping_line_name as notes, NULL as result, NULL as job_id, NULL as round_id, NULL as event_id')
            ->selectSub(fn (Builder $events) => $events->from('container_assignment_events as original')->select('original.after_data')
                ->whereColumn('original.ingress_cycle_id', 'cycle.id')->whereColumn('original.container_id', 'cycle.container_id')
                ->whereIn('original.event_type', ['ingress', 'reentry'])->orderBy('original.id')->limit(1), 'payload');
        $exit = (clone $base)->whereNotNull('cycle.closed_at')->select('cycle.id as cycle_id', 'cycle.entered_at as cycle_start')
            ->selectRaw("'dispatch' as kind, 2 as phase, cycle.closed_at as occurred_at, cycle.id as entry_id, NULL as actor, NULL as notes, NULL as result, NULL as job_id, NULL as round_id")
            ->selectSub(fn (Builder $events) => $events->from('container_assignment_events as dispatch')->select('dispatch.id')
                ->whereColumn('dispatch.ingress_cycle_id', 'cycle.id')->whereColumn('dispatch.container_id', 'cycle.container_id')
                ->where('dispatch.event_type', 'dispatch')->orderByDesc('dispatch.id')->limit(1), 'event_id')
            ->selectRaw('NULL as payload');
        $audits = (clone $base)->join('container_assignment_events as event', function ($join): void {
            $join->on('event.ingress_cycle_id', '=', 'cycle.id')->on('event.container_id', '=', 'cycle.container_id');
        })->leftJoin('users as actor', 'actor.id', '=', 'event.user_id')
            ->whereNotIn('event.event_type', ['ingress', 'reentry', 'dispatch'])
            ->select('cycle.id as cycle_id', 'cycle.entered_at as cycle_start', 'event.event_type as kind')
            ->selectRaw('1 as phase, event.created_at as occurred_at, event.id as entry_id, actor.username as actor, event.reason as notes, NULL as result, NULL as job_id, NULL as round_id, event.id as event_id, event.after_data as payload');
        $jobs = (clone $base)->join('maintenance_jobs as job', function ($join) use ($branchId): void {
            $join->on('job.ingress_cycle_id', '=', 'cycle.id')->on('job.container_id', '=', 'cycle.container_id')->where('job.branch_id', $branchId);
        })->leftJoin('users as mechanic', 'mechanic.id', '=', 'job.mechanic_id');
        $repairs = (clone $jobs)->select('cycle.id as cycle_id', 'cycle.entered_at as cycle_start')
            ->selectRaw("'repair' as kind, 1 as phase, job.created_at as occurred_at, job.id as entry_id, mechanic.username as actor, job.repair_type as notes, job.status as result, job.id as job_id, NULL as round_id, NULL as event_id, NULL as payload");
        $rounds = (clone $jobs)->join('maintenance_corrections as correction', 'correction.job_id', '=', 'job.id')
            ->leftJoin('users as requester', 'requester.id', '=', 'correction.requested_by');
        $requested = (clone $rounds)->select('cycle.id as cycle_id', 'cycle.entered_at as cycle_start')
            ->selectRaw("'correction_requested' as kind, 1 as phase, correction.created_at as occurred_at, correction.id as entry_id, requester.username as actor, correction.reason as notes, NULL as result, job.id as job_id, correction.id as round_id, NULL as event_id, NULL as payload");
        $submitted = (clone $rounds)->whereNotNull('correction.submitted_at')->where('correction.status', '!=', 'draft')
            ->select('cycle.id as cycle_id', 'cycle.entered_at as cycle_start')
            ->selectRaw("'correction_submitted' as kind, 1 as phase, correction.submitted_at as occurred_at, correction.id as entry_id, mechanic.username as actor, correction.work_performed as notes, correction.status as result, job.id as job_id, correction.id as round_id, NULL as event_id, NULL as payload");

        $columns = ['cycle_id', 'cycle_start', 'kind', 'phase', 'occurred_at', 'entry_id', 'actor', 'notes', 'result', 'job_id', 'round_id', 'event_id', 'payload'];
        $parts = array_map(fn (Builder $part): Builder => $this->normalizeText($part, $columns, ['kind', 'actor', 'notes', 'result', 'payload']),
            [$ingress, $audits, $repairs, $requested, $submitted, $exit, $this->transfers($branchId, $containerId), $this->modifications($branchId, $containerId)]);
        $combined = array_shift($parts);
        foreach ($parts as $part) {
            $combined->unionAll($part);
        }

        return DB::query()->fromSub($combined, 'timeline')
            ->select('timeline.*')->orderBy('occurred_at')
            ->orderBy('phase')->orderBy('kind')->orderBy('entry_id');
    }

    private function logs(int $containerId): Builder
    {
        $candidates = DB::table('container_ingress_cycles as candidate')->whereColumn('candidate.container_id', 'source.contenedor_id')
            ->whereRaw('LEAST(DATE_ADD(candidate.entered_at, INTERVAL 6 HOUR), COALESCE(candidate.created_at, DATE_ADD(candidate.entered_at, INTERVAL 6 HOUR))) <= DATE_ADD(source.fecha_evento, INTERVAL 6 HOUR)')
            ->where(fn (Builder $query) => $query->whereNull('candidate.closed_at')
                ->orWhereRaw('candidate.closed_at >= DATE_ADD(source.fecha_evento, INTERVAL 6 HOUR)'));

        return DB::table('logs_eventos as source')->where('source.contenedor_id', $containerId)->select('source.*')
            ->selectSub((clone $candidates)->selectRaw('COUNT(*)'), 'candidate_count')
            ->selectSub((clone $candidates)->selectRaw('MIN(candidate.id)'), 'candidate_id');
    }

    private function transfers(int $branchId, int $containerId): Builder
    {
        return DB::query()->fromSub($this->logs($containerId), 'log')
            ->join('logs_traslados as transfer', 'transfer.evento_id', '=', 'log.id')
            ->join('bloques as origin', 'origin.id', '=', 'transfer.bloque_anterior_id')->join('patios as origin_yard', 'origin_yard.id', '=', 'origin.patio_id')
            ->join('bloques as destination', 'destination.id', '=', 'transfer.bloque_nuevo_id')->join('patios as destination_yard', 'destination_yard.id', '=', 'destination.patio_id')
            ->leftJoin('users as actor', 'actor.id', '=', 'log.usuario_id')
            ->leftJoin('container_ingress_cycles as cycle', function ($join) use ($branchId): void {
                $join->on('cycle.id', '=', 'log.candidate_id')->where('log.candidate_count', 1)->where('cycle.branch_id', $branchId);
            })
            ->where('origin_yard.sucursal_id', $branchId)->where('destination_yard.sucursal_id', $branchId)
            ->select('cycle.id as cycle_id', 'cycle.entered_at as cycle_start')
            ->selectRaw("'transfer' as kind, 1 as phase, DATE_ADD(log.fecha_evento, INTERVAL 6 HOUR) as occurred_at, log.id as entry_id, actor.username as actor,
                CONCAT(origin_yard.nombre, ' / B', origin.numero, ' / P', transfer.posicion_anterior, ' -> ', destination_yard.nombre, ' / B', destination.numero, ' / P', transfer.posicion_nueva) as notes,
                NULL as result, NULL as job_id, NULL as round_id, NULL as event_id,
                JSON_OBJECT('association', IF(cycle.id IS NULL, 'unassigned', 'date'), 'origin', CONCAT(origin_yard.nombre, ' / B', origin.numero, ' / P', transfer.posicion_anterior),
                    'destination', CONCAT(destination_yard.nombre, ' / B', destination.numero, ' / P', transfer.posicion_nueva)) as payload");
    }

    private function modificationFields(int $branchId, int $containerId): Builder
    {
        $base = DB::query()->fromSub($this->logs($containerId), 'log')
            ->join('container_ingress_cycles as cycle', 'cycle.id', '=', 'log.candidate_id')
            ->where('log.candidate_count', 1)->where('cycle.branch_id', $branchId)
            ->leftJoin('users as actor', 'actor.id', '=', 'log.usuario_id')
            ->select('cycle.id as cycle_id', 'cycle.entered_at as cycle_start', 'log.id', 'log.usuario_id', 'actor.username as actor')
            ->selectRaw('DATE_ADD(log.fecha_evento, INTERVAL 6 HOUR) as occurred_at');
        $fields = (clone $base)->join('logs_modificaciones as change', 'change.evento_id', '=', 'log.id')
            ->addSelect('change.campo_modificado as field', 'change.valor_anterior as old_value', 'change.valor_nuevo as new_value');
        $technical = (clone $base)->join('logs_estatus as change', 'change.evento_id', '=', 'log.id')
            ->leftJoin('tipos_estatus as category', 'category.id', '=', 'change.tipo_estatus_id')
            ->leftJoin('estatus_contenedores as previous', 'previous.id', '=', 'change.valor_anterior_id')
            ->leftJoin('estatus_contenedores as current', 'current.id', '=', 'change.valor_nuevo_id')
            ->selectRaw("CONCAT('Estado ', COALESCE(category.nombre, 'tecnico')) as field, previous.nombre as old_value, current.nombre as new_value");
        $ipsa = (clone $base)->join('logs_ipsa as change', 'change.evento_id', '=', 'log.id')
            ->leftJoin('estatus_ipsa as previous', 'previous.id', '=', 'change.estatus_anterior_id')
            ->leftJoin('estatus_ipsa as current', 'current.id', '=', 'change.estatus_nuevo_id')
            ->selectRaw("'Estado IPSA' as field, previous.nombre as old_value, current.nombre as new_value");

        $columns = ['cycle_id', 'cycle_start', 'id', 'usuario_id', 'actor', 'occurred_at', 'field', 'old_value', 'new_value'];
        $parts = array_map(fn (Builder $part): Builder => $this->normalizeText($part, $columns, ['actor', 'field', 'old_value', 'new_value']), [$fields, $technical, $ipsa]);

        return $parts[0]->unionAll($parts[1])->unionAll($parts[2]);
    }

    /** @param list<string> $columns
     * @param  list<string>  $textColumns
     */
    private function normalizeText(Builder $query, array $columns, array $textColumns): Builder
    {
        return DB::query()->fromSub($query, 'source_rows')->select(array_map(fn (string $column) => in_array($column, $textColumns, true)
            ? DB::raw('CONVERT(source_rows.'.$column.' USING utf8mb4) COLLATE utf8mb4_unicode_ci as '.$column)
            : 'source_rows.'.$column, $columns));
    }

    private function modifications(int $branchId, int $containerId): Builder
    {
        return DB::query()->fromSub($this->modificationFields($branchId, $containerId), 'changes')
            ->select('cycle_id', 'cycle_start')->selectRaw("'modification' as kind, 1 as phase, occurred_at, MIN(id) as entry_id, actor,
                CONCAT(COUNT(*), ' campo(s) actualizado(s)') as notes, NULL as result, NULL as job_id, NULL as round_id, NULL as event_id,
                JSON_OBJECT('association', 'date', 'user_id', usuario_id) as payload")
            ->groupBy('cycle_id', 'cycle_start', 'occurred_at', 'usuario_id', 'actor');
    }

    public function attachChanges(Collection $entries, int $branchId, int $containerId): void
    {
        $groups = $entries->where('kind', 'modification');
        if ($groups->isEmpty()) {
            return;
        }
        $changes = DB::query()->fromSub($this->modificationFields($branchId, $containerId), 'fields')
            ->where(function (Builder $query) use ($groups): void {
                foreach ($groups as $entry) {
                    $query->orWhere(fn (Builder $group) => $group->where('cycle_id', $entry->cycle_id)
                        ->where('usuario_id', $entry->snapshot['user_id'])->where('occurred_at', $entry->occurred_at));
                }
            })->orderBy('id')->get()->groupBy(fn (object $field): string => $field->cycle_id.':'.$field->usuario_id.':'.$field->occurred_at);
        foreach ($groups as $entry) {
            $entry->snapshot['changes'] = $changes->get($entry->cycle_id.':'.$entry->snapshot['user_id'].':'.$entry->occurred_at, collect())
                ->map(fn (object $field): array => ['field' => $field->field, 'before' => $field->old_value, 'after' => $field->new_value])->all();
        }
    }

    public function attachDetails(Collection $entries, int $branchId, int $containerId): void
    {
        $jobs = DB::table('maintenance_jobs as job')->leftJoin('users as mechanic', 'mechanic.id', '=', 'job.mechanic_id')
            ->where('job.branch_id', $branchId)->where('job.container_id', $containerId)
            ->whereIn('job.id', $entries->pluck('job_id')->filter()->unique())->get(['job.*', 'mechanic.username as mechanic_name'])->keyBy('id');
        $rounds = DB::table('maintenance_corrections')->whereIn('job_id', $jobs->keys())
            ->whereIn('id', $entries->pluck('round_id')->filter()->unique())->get()->keyBy('id');
        $cycles = DB::table('container_ingress_cycles as cycle')->leftJoin('users as u', 'u.id', '=', 'cycle.created_by')
            ->where('cycle.branch_id', $branchId)->where('cycle.container_id', $containerId)
            ->whereIn('cycle.id', $entries->pluck('cycle_id')->filter()->unique())->get(['cycle.id', 'u.username'])->keyBy('id');
        foreach ($entries as $entry) {
            $entry->details = [];
            $entry->sections = [];
            if (in_array($entry->kind, ['ingress', 'reentry'], true)) {
                $entry->actor = $entry->snapshot['document']['usuario'] ?? $cycles->get($entry->cycle_id)?->username;
                $document = $entry->snapshot['document'] ?? [];
                $entry->details = ['Naviera del ingreso' => $entry->snapshot['cycle']['shipping_line_name'] ?? $entry->notes,
                    'Auditora del ingreso' => $entry->snapshot['cycle']['audit_company_name'] ?? null];
                foreach (['tipo' => 'Tipo de unidad', 'modelo' => 'Modelo', 'iso_code' => 'ISO Code', 'marca' => 'Marca',
                    'estado_unidad' => 'Estado de unidad', 'estado_box' => 'Estado de estructura', 'ipsa' => 'Estado IPSA',
                    'transportista' => 'Transportista / empresa', 'conductor' => 'Piloto', 'placa_cabezal' => 'Placa cabezal', 'placa_chasis' => 'Placa chasis'] as $key => $label) {
                    if (array_key_exists($key, $document)) {
                        $entry->details[$label] = $document[$key];
                    }
                }
                if ($document) {
                    $entry->details['Ubicacion al ingresar'] = ($document['patio'] ?? '-').' / Bloque '.($document['bloque'] ?? '-').' / Posicion '.($document['posicion'] ?? '-');
                    $entry->sections = ['Observaciones de unidad' => $document['observaciones_unidad'] ?? null, 'Observaciones de estructura' => $document['observaciones_box'] ?? null];
                }
            } elseif ($entry->job_id && ($job = $jobs->get($entry->job_id))) {
                $round = $rounds->get($entry->round_id);
                $service = $round ? json_decode($round->repair_service_snapshot ?? '{}', true) : [];
                $entry->details = ['Tipo de reparacion' => $round ? ($service['name'] ?? 'Por definir en esta correccion') : $job->repair_type,
                    'Intervencion' => $round ? 'Correccion '.$round->number : 'Reparacion original', 'Mecanico' => $job->mechanic_name];
                $entry->sections = $entry->kind === 'correction_requested'
                    ? ['Solicitud de la auditora' => $entry->notes]
                    : ['Diagnostico y ubicacion del daño' => $round ? $round->diagnosis : $job->diagnosis,
                        'Trabajo realizado' => $round ? $round->work_performed : $job->work_performed];
            } elseif ($entry->kind === 'external_audit_status') {
                $audit = $entry->snapshot['audit'] ?? [];
                $entry->details = ['Inspector' => $entry->actor, 'Etapa' => in_array($entry->result, ['Aprobado', 'Rechazado'], true) ? 'Dictamen emitido' : 'Revision en curso'];
                $entry->sections = ['Observaciones de la revision' => $entry->notes ?: ($entry->result === 'En revision'
                    ? 'Se inicio la revision del contenedor. Todavia no se habia emitido un dictamen ni registrado observaciones.' : 'Sin observaciones registradas en este evento.')];
                if (! empty($audit['maintenance_correction_id'])) {
                    $entry->details['Revision de correccion'] = '#'.$audit['maintenance_correction_id'];
                }
            }
        }
    }
}
