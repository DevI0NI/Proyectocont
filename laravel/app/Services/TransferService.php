<?php

namespace App\Services;

use App\Models\Bloque;
use App\Models\Contenedor;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TransferService
{
    private const ACTIVE = 1;

    public function transfer(User $user, int $branchId, array $data): void
    {
        DB::transaction(function () use ($user, $branchId, $data): void {
            $current = DB::table('contenedores as c')
                ->join('bloques as b', 'c.bloque_id', '=', 'b.id')
                ->join('patios as p', 'b.patio_id', '=', 'p.id')
                ->where('c.id', $data['contenedor_id'])
                ->where('c.estado_id', self::ACTIVE)
                ->where('p.sucursal_id', $branchId)
                ->select([
                    'c.id',
                    'c.nombre',
                    'c.bloque_id',
                    'c.posicion',
                    'p.nombre as patio_nombre',
                    'b.numero as bloque_numero',
                ])
                ->lockForUpdate()
                ->first();

            if (! $current) {
                throw new \RuntimeException('El contenedor no existe o no esta activo.');
            }

            $destination = DB::table('bloques as b')
                ->join('patios as p', 'b.patio_id', '=', 'p.id')
                ->where('b.id', $data['nuevo_bloque_id'])
                ->where('b.estado_id', self::ACTIVE)
                ->where('p.estado_id', self::ACTIVE)
                ->where('p.sucursal_id', $branchId)
                ->select([
                    'b.id',
                    'b.capacidad_maxima',
                    'p.nombre as patio_nombre',
                    'b.numero as bloque_numero',
                ])
                ->lockForUpdate()
                ->first();

            if (! $destination) {
                throw new \RuntimeException('El bloque destino no existe.');
            }

            if ((int) $current->bloque_id === (int) $destination->id) {
                throw new \RuntimeException('Selecciona un bloque de destino diferente al bloque de origen.');
            }

            $blockers = Contenedor::active()
                ->where('bloque_id', $current->bloque_id)
                ->where('posicion', '<', $current->posicion)
                ->count();

            if ($blockers > 0) {
                throw new \RuntimeException('Movimiento bloqueado: Hay contenedores obstruyendo la salida.');
            }

            $destinationCount = Contenedor::active()
                ->where('bloque_id', $destination->id)
                ->when((int) $current->bloque_id === (int) $destination->id, fn ($query) => $query->where('id', '!=', $current->id))
                ->count();

            if ($destinationCount >= (int) $destination->capacidad_maxima) {
                throw new \RuntimeException('El bloque destino esta lleno.');
            }

            $positionBusy = Contenedor::active()
                ->where('bloque_id', $destination->id)
                ->where('posicion', $data['nueva_posicion'])
                ->where('id', '!=', $current->id)
                ->exists();

            if ($positionBusy) {
                throw new \RuntimeException("La posicion {$data['nueva_posicion']} ya esta ocupada.");
            }

            Contenedor::where('id', $current->id)->update([
                'bloque_id' => $destination->id,
                'posicion' => (int) $data['nueva_posicion'],
            ]);

            if ((int) $current->bloque_id !== (int) $destination->id) {
                Bloque::where('id', $current->bloque_id)->decrement('capacidad_actual');
                Bloque::where('id', $destination->id)->increment('capacidad_actual');
            }

            $operationId = DB::table('tipos_operacion')->where('nombre', 'TRASLADO')->value('id') ?: 2;
            $message = sprintf(
                'Traslado: De %s Bloque %s (Pos %s) -> A %s Bloque %s (Pos %s)',
                $current->patio_nombre,
                $current->bloque_numero,
                $current->posicion,
                $destination->patio_nombre,
                $destination->bloque_numero,
                $data['nueva_posicion'],
            );

            $eventId = DB::table('logs_eventos')->insertGetId([
                'contenedor_id' => $current->id,
                'usuario_id' => $user->id,
                'tipo_operacion_id' => $operationId,
                'observaciones' => $message,
                'estado_id' => self::ACTIVE,
            ]);

            DB::table('logs_traslados')->insert([
                'evento_id' => $eventId,
                'bloque_anterior_id' => $current->bloque_id,
                'posicion_anterior' => $current->posicion,
                'bloque_nuevo_id' => $destination->id,
                'posicion_nueva' => (int) $data['nueva_posicion'],
            ]);
        });
    }
}
