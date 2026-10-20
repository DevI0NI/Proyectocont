<?php

namespace App\Services;

use App\Models\Contenedor;
use App\Models\Modelo;
use App\Models\TipoEstatus;
use App\Models\TipoUnidad;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContainerUpdateService
{
    private const ACTIVE = 1;

    public function update(User $user, int $branchId, int $containerId, array $data): string
    {
        return DB::transaction(function () use ($user, $branchId, $containerId, $data): string {
            $container = Contenedor::where('id', $containerId)->lockForUpdate()->firstOrFail();
            $record = $this->containerDetailQuery($branchId)
                ->where('c.id', $containerId)
                ->lockForUpdate()
                ->first();

            if (! $record) {
                throw new \RuntimeException('Contenedor no encontrado en la sucursal activa.');
            }

            $lineName = app(ContainerAuditAssignment::class)->change($user, $container, $branchId, $data);

            $payload = [
                'nombre' => strtoupper(trim($data['codigo_contenedor'])),
                'tipo_unidad_id' => (int) $data['tipo_unidad'],
                'modelo_id' => (int) $data['modelo'],
                'iso_code' => strtoupper(trim((string) $data['iso_code'])),
                'marca' => trim((string) $data['marca']),
                'estatus_unidad_id' => (int) $data['estado_unidad'],
                'observaciones_unidad' => trim((string) ($data['observaciones_estado'] ?? '')),
                'estatus_box_id' => (int) $data['status_box'],
                'observaciones_box' => trim((string) ($data['observaciones_box'] ?? '')),
                'estatus_ipsa_id' => (int) $data['estatus_ipsa'],
                'naviera' => $lineName ?? $record->naviera,
                'transportista' => trim((string) $data['transportista']),
                'conductor' => trim((string) ($data['conductor'] ?? '')),
                'placa_cabezal' => strtoupper(trim((string) $data['placa_cabezal'])),
                'placa_chasis' => strtoupper(trim((string) ($data['placa_chasis'] ?? ''))),
            ];

            // Compare sensitive changes with the locked record, not hidden form fields.
            foreach (['estado_unidad' => ['estatus_unidad_id', 'containers.technical'], 'status_box' => ['estatus_box_id', 'containers.technical'], 'estatus_ipsa' => ['estatus_ipsa_id', 'containers.ipsa']] as $field => [$column, $permission]) {
                if (! $user->hasPermission($permission) && (int) $data[$field] !== (int) $record->{$column}) {
                    throw ValidationException::withMessages([$field => 'No tienes permiso para cambiar este estado.']);
                }
            }

            Contenedor::where('id', $record->id)->update($payload);
            $this->writeModificationLogs($record, $payload, $user->id);

            return $payload['nombre'];
        });
    }

    private function containerDetailQuery(int $branchId)
    {
        return DB::table('contenedores as c')
            ->leftJoin('tipos_unidad as t', 'c.tipo_unidad_id', '=', 't.id')
            ->leftJoin('modelos as m', 'c.modelo_id', '=', 'm.id')
            ->leftJoin('bloques as b', 'c.bloque_id', '=', 'b.id')
            ->leftJoin('patios as p', 'b.patio_id', '=', 'p.id')
            ->leftJoin('estatus_contenedores as eu', 'c.estatus_unidad_id', '=', 'eu.id')
            ->leftJoin('estatus_contenedores as eb', 'c.estatus_box_id', '=', 'eb.id')
            ->leftJoin('estatus_ipsa as ei', 'c.estatus_ipsa_id', '=', 'ei.id')
            ->where('c.estado_id', self::ACTIVE)
            ->where('p.sucursal_id', $branchId)
            ->select([
                'c.id',
                'c.nombre',
                'c.fecha_ingreso',
                'c.posicion',
                'c.tipo_unidad_id',
                'c.modelo_id',
                'c.iso_code',
                'c.marca',
                'c.estatus_unidad_id',
                'c.observaciones_unidad',
                'c.estatus_box_id',
                'c.observaciones_box',
                'c.estatus_ipsa_id',
                'c.naviera',
                'c.transportista',
                'c.conductor',
                'c.placa_cabezal',
                'c.placa_chasis',
                't.nombre as tipo_unidad_nombre',
                'm.nombre as modelo_nombre',
                'eu.nombre as estatus_unidad',
                'eb.nombre as estatus_box',
                'ei.nombre as estatus_ipsa',
                'b.id as bloque_id',
                'b.numero as bloque_numero',
                'p.id as patio_id',
                'p.nombre as patio_nombre',
            ]);
    }

    private function writeModificationLogs(object $old, array $new, int $userId): void
    {
        [$unitStatusType, $boxStatusType] = $this->statusTypeIds();

        if ($old->nombre !== $new['nombre']) {
            $this->logFieldChange($old->id, $userId, 'Cambio de Nombre', 'Nombre', $old->nombre, $new['nombre']);
        }

        if ((int) $old->tipo_unidad_id !== $new['tipo_unidad_id']) {
            $newType = TipoUnidad::find($new['tipo_unidad_id'])?->nombre ?? (string) $new['tipo_unidad_id'];
            $this->logFieldChange($old->id, $userId, 'Cambio de Tipo de Unidad', 'Tipo Unidad', $old->tipo_unidad_nombre, $newType);
        }

        if ((int) $old->modelo_id !== $new['modelo_id']) {
            $newModel = Modelo::find($new['modelo_id'])?->nombre ?? (string) $new['modelo_id'];
            $this->logFieldChange($old->id, $userId, 'Cambio de Modelo', 'Modelo', $old->modelo_nombre, $newModel);
        }

        foreach ([
            'iso_code' => 'ISO Code',
            'marca' => 'Marca',
            'naviera' => 'Naviera',
            'transportista' => 'Transportista',
            'conductor' => 'Conductor',
            'placa_cabezal' => 'Placa Cabezal',
            'placa_chasis' => 'Placa Chasis',
        ] as $field => $label) {
            if ((string) ($old->{$field} ?? '') !== (string) ($new[$field] ?? '')) {
                $this->logFieldChange($old->id, $userId, 'Cambio de '.$label, $label, $old->{$field} ?? '', $new[$field] ?? '');
            }
        }

        if ((string) $old->observaciones_unidad !== $new['observaciones_unidad']) {
            $this->logFieldChange($old->id, $userId, 'Actualizacion Obs. Unidad', 'Obs. Unidad', $old->observaciones_unidad, $new['observaciones_unidad']);
        }

        if ((string) $old->observaciones_box !== $new['observaciones_box']) {
            $this->logFieldChange($old->id, $userId, 'Actualizacion Obs. Box', 'Obs. Box', $old->observaciones_box, $new['observaciones_box']);
        }

        if ((int) $old->estatus_unidad_id !== $new['estatus_unidad_id']) {
            $eventId = $this->logEvent($old->id, $userId, 3, 'Cambio Estatus Unidad');
            DB::table('logs_estatus')->insert([
                'evento_id' => $eventId,
                'tipo_estatus_id' => $unitStatusType,
                'valor_anterior_id' => $old->estatus_unidad_id,
                'valor_nuevo_id' => $new['estatus_unidad_id'],
            ]);
        }

        if ((int) $old->estatus_box_id !== $new['estatus_box_id']) {
            $eventId = $this->logEvent($old->id, $userId, 3, 'Cambio Estatus Estructura');
            DB::table('logs_estatus')->insert([
                'evento_id' => $eventId,
                'tipo_estatus_id' => $boxStatusType,
                'valor_anterior_id' => $old->estatus_box_id,
                'valor_nuevo_id' => $new['estatus_box_id'],
            ]);
        }

        if ((int) $old->estatus_ipsa_id !== $new['estatus_ipsa_id']) {
            $eventId = $this->logEvent($old->id, $userId, 3, 'Cambio Estatus IPSA');
            DB::table('logs_ipsa')->insert([
                'evento_id' => $eventId,
                'estatus_anterior_id' => $old->estatus_ipsa_id,
                'estatus_nuevo_id' => $new['estatus_ipsa_id'],
            ]);
        }
    }

    private function logFieldChange(int $containerId, int $userId, string $summary, string $field, mixed $oldValue, mixed $newValue): void
    {
        $eventId = $this->logEvent($containerId, $userId, 4, $summary);

        DB::table('logs_modificaciones')->insert([
            'evento_id' => $eventId,
            'campo_modificado' => $field,
            'valor_anterior' => $oldValue,
            'valor_nuevo' => $newValue,
        ]);
    }

    private function logEvent(int $containerId, int $userId, int $operationId, string $summary): int
    {
        return DB::table('logs_eventos')->insertGetId([
            'contenedor_id' => $containerId,
            'usuario_id' => $userId,
            'tipo_operacion_id' => $operationId,
            'observaciones' => $summary,
            'estado_id' => self::ACTIVE,
        ]);
    }

    private function statusTypeIds(): array
    {
        return [
            TipoEstatus::where('nombre', 'Unidad')->value('id'),
            TipoEstatus::where('nombre', 'Estructura')->value('id'),
        ];
    }
}
