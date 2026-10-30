<?php

namespace App\Services;

use App\Models\Bloque;
use App\Models\Contenedor;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class IngresoService
{
    private const ACTIVE = 1;

    public function register(User $user, array $data, string $containerCode, Bloque $block): array
    {
        return DB::transaction(function () use ($user, $data, $containerCode, $block): array {
            $block = Bloque::active()->whereKey($block->id)->lockForUpdate()->firstOrFail();
            $assignmentService = app(ContainerAuditAssignment::class);
            $assignment = $assignmentService->resolve($user, (int) $block->patio->sucursal_id, $data);
            $activeCount = Contenedor::active()->where('bloque_id', $block->id)->count();
            if ($activeCount >= (int) $block->capacidad_maxima) {
                throw new \RuntimeException('El bloque seleccionado esta lleno.');
            }

            $positionBusy = Contenedor::active()
                ->where('bloque_id', $block->id)
                ->where('posicion', $data['posicion'])
                ->exists();

            if ($positionBusy) {
                throw new \RuntimeException("La posicion {$data['posicion']} ya esta ocupada por otro contenedor activo.");
            }

            $container = Contenedor::where('nombre', $containerCode)->lockForUpdate()->first();
            $previousShippingLine = $container?->naviera;
            $isReentry = false;

            if ($container && (int) $container->estado_id === self::ACTIVE) {
                throw new \RuntimeException('El numero de equipo ya existe y esta activo en el patio.');
            }

            $payload = [
                'nombre' => $containerCode,
                'bloque_id' => $block->id,
                'posicion' => (int) $data['posicion'],
                'tipo_unidad_id' => (int) $data['tipo_unidad'],
                'modelo_id' => (int) $data['modelo'],
                'iso_code' => strtoupper(trim((string) $data['iso_code'])),
                'marca' => trim((string) $data['marca']),
                'estatus_unidad_id' => (int) $data['estado_unidad'],
                'observaciones_unidad' => trim((string) ($data['observaciones_estado'] ?? '')),
                'estatus_box_id' => (int) $data['status_box'],
                'observaciones_box' => trim((string) ($data['observaciones_box'] ?? '')),
                'estatus_ipsa_id' => (int) $data['estatus_ipsa'],
                'fecha_ingreso' => $data['fecha_ingreso'].' '.now()->format('H:i:s'),
                'naviera' => $assignment['shipping_line_name'],
                'transportista' => trim((string) $data['transportista']),
                'conductor' => trim((string) ($data['conductor'] ?? '')),
                'placa_cabezal' => strtoupper(trim((string) $data['placa_cabezal'])),
                'placa_chasis' => strtoupper(trim((string) ($data['placa_chasis'] ?? ''))),
                'estado_id' => self::ACTIVE,
            ];

            if ($container) {
                $isReentry = true;
                $container->update($payload);
            } else {
                $container = Contenedor::create($payload);
            }
            $assignmentService->start($user, $container, (int) $block->patio->sucursal_id, $assignment, $isReentry, $previousShippingLine);
            app(SystemNotifications::class)->containerIngress($container, (int) $block->patio->sucursal_id, $user, $isReentry);
            app(SystemNotifications::class)->damagedIngress($container, (int) $block->patio->sucursal_id);

            $operationId = DB::table('tipos_operacion')->where('nombre', 'INGRESO')->value('id') ?: 1;
            $eventId = DB::table('logs_eventos')->insertGetId([
                'contenedor_id' => $container->id,
                'usuario_id' => $user->id,
                'tipo_operacion_id' => $operationId,
                'observaciones' => $isReentry ? 'Reingreso de equipo (Historial previo archivado).' : 'Ingreso inicial (Equipo nuevo).',
                'estado_id' => self::ACTIVE,
            ]);

            DB::table('logs_traslados')->insert([
                'evento_id' => $eventId,
                'bloque_nuevo_id' => $block->id,
                'posicion_nueva' => (int) $data['posicion'],
            ]);

            $block->increment('capacidad_actual');

            return [
                'container_id' => $container->id,
                'was_reentry' => $isReentry,
            ];
        });
    }
}
