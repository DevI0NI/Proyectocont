<?php

namespace App\Services;

use App\Models\Bloque;
use App\Models\Contenedor;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DispatchService
{
    private const ACTIVE = 1;

    private const INACTIVE = 2;

    private const AVAILABLE_IPSA = 1;

    public static function isAuditApproved(?string $status): bool
    {
        return strtoupper(trim((string) $status)) === 'APROBADO';
    }

    public function dispatch(User $user, int $branchId, array $data): string
    {
        return DB::transaction(function () use ($user, $branchId, $data): string {
            $container = $this->dispatchableContainerQuery($branchId)
                ->where('c.id', $data['contenedor_id'])
                ->lockForUpdate()
                ->first();

            if (! $container) {
                throw new \RuntimeException('El contenedor no existe o no pertenece a esta sucursal.');
            }

            if ($container->current_ingress_cycle_id && MaintenanceWorkflow::hasOpenWork((int) $container->current_ingress_cycle_id)) {
                throw new \RuntimeException('El contenedor tiene mantenimiento o revision interna pendiente.');
            }

            if ((int) $container->estatus_ipsa_id !== self::AVAILABLE_IPSA) {
                throw new \RuntimeException("Error: El contenedor no tiene estatus IPSA 'Disponible'.");
            }

            if (! $container->current_ingress_cycle_id || ! $container->audit_company_id
                || (int) $container->audit_cycle_id !== (int) $container->current_ingress_cycle_id
                || (int) $container->audit_owner_id !== (int) $container->audit_company_id) {
                throw new \RuntimeException('Confirma la naviera y auditora del ingreso actual y solicita su aprobacion antes de despachar.');
            }

            if (! self::isAuditApproved($container->estatus_pyp)) {
                throw new \RuntimeException('La auditoria PYP debe tener estatus Aprobado para despachar el contenedor.');
            }

            $blockers = Contenedor::active()
                ->where('bloque_id', $container->bloque_id)
                ->where('posicion', '<', $container->posicion)
                ->count();

            if ($blockers > 0) {
                throw new \RuntimeException('Error Fisico: Hay contenedores delante de este equipo. Debe moverlos primero.');
            }

            $operationId = DB::table('tipos_operacion')->where('nombre', 'RETIRO')->value('id') ?: 5;
            DB::table('logs_eventos')->insert([
                'contenedor_id' => $container->id,
                'usuario_id' => $user->id,
                'tipo_operacion_id' => $operationId,
                'observaciones' => 'Despacho exitoso. Observaciones: '.trim((string) ($data['observaciones'] ?? '')),
                'estado_id' => self::INACTIVE,
            ]);

            DB::table('logs_eventos')
                ->where('contenedor_id', $container->id)
                ->update(['estado_id' => self::INACTIVE]);

            DB::table('pyp_auditoria')
                ->where('contenedor_id', $container->id)
                ->update(['estado_id' => self::INACTIVE]);
            DB::table('container_ingress_cycles')->where('id', $container->current_ingress_cycle_id)
                ->update(['closed_at' => now(), 'updated_at' => now()]);

            Bloque::where('id', $container->bloque_id)
                ->where('capacidad_actual', '>', 0)
                ->decrement('capacidad_actual');

            Contenedor::where('id', $container->id)->update(['estado_id' => self::INACTIVE]);

            return $container->nombre;
        });
    }

    private function dispatchableContainerQuery(int $branchId)
    {
        $query = DB::table('contenedores as c')
            ->join('bloques as b', 'c.bloque_id', '=', 'b.id')
            ->join('patios as p', 'b.patio_id', '=', 'p.id')
            ->leftJoin('estatus_ipsa as ei', 'c.estatus_ipsa_id', '=', 'ei.id')
            ->leftJoin('pyp_auditoria as pa', function ($join): void {
                $join->on('c.id', '=', 'pa.contenedor_id')->where('pa.estado_id', self::ACTIVE);
            })
            ->where('c.estado_id', self::ACTIVE)
            ->where('p.sucursal_id', $branchId)
            ->select([
                'c.id',
                'c.nombre',
                'c.posicion',
                'c.bloque_id',
                'c.estatus_ipsa_id',
                'ei.nombre as estatus_ipsa',
                'pa.estatus_pyp',
                'pa.ingress_cycle_id as audit_cycle_id',
                'pa.external_audit_company_id as audit_owner_id',
                'b.numero as bloque_numero',
                'p.nombre as patio_nombre',
            ]);

        return app(ContainerAuditAssignment::class)->joinCurrent($query);
    }
}
