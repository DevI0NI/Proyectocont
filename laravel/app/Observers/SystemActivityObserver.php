<?php

namespace App\Observers;

use App\Models\Bloque;
use App\Models\EstatusContenedor;
use App\Models\Modelo;
use App\Models\Patio;
use App\Models\Sucursal;
use App\Models\TipoUnidad;
use App\Services\SystemNotifications;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class SystemActivityObserver
{
    public function created(Model $model): void
    {
        $this->publish($model, true);
    }

    public function updated(Model $model): void
    {
        $this->publish($model, false);
    }

    private function publish(Model $model, bool $created): void
    {
        if (! auth()->check() || ! request()->route()) {
            return;
        }
        [$module, $kind, $fields, $branches] = match (true) {
            $model instanceof Sucursal => ['branches', 'Sucursal', ['nombre' => 'Nombre', 'ubicacion' => 'Ubicación', 'direccion' => 'Dirección', 'departamento_codigo' => 'Departamento', 'municipio_codigo' => 'Municipio', 'estado_id' => 'Estado'], null],
            $model instanceof Patio => ['yard-layout', 'Patio', ['nombre' => 'Nombre', 'sucursal_id' => 'Sucursal', 'estado_id' => 'Estado'], [(int) $model->sucursal_id]],
            $model instanceof Bloque => ['yard-layout', 'Bloque', ['numero' => 'Número', 'patio_id' => 'Patio', 'capacidad_maxima' => 'Capacidad', 'estado_id' => 'Estado'], [(int) DB::table('patios')->where('id', $model->patio_id)->value('sucursal_id')]],
            $model instanceof TipoUnidad => ['unit-types', 'Tipo de unidad', ['nombre' => 'Nombre', 'descripcion' => 'Descripción', 'estado_id' => 'Estado'], null],
            $model instanceof Modelo => ['unit-types', 'Modelo', ['nombre' => 'Nombre', 'tipo_unidad_id' => 'Tipo de unidad', 'estado_id' => 'Estado'], null],
            $model instanceof EstatusContenedor => ['statuses', 'Estado técnico', ['nombre' => 'Nombre', 'tipo_estatus_id' => 'Tipo de estado', 'estado_id' => 'Estado'], null],
            default => [null, null, [], null],
        };
        if (! $module) {
            return;
        }
        $before = $created ? [] : $model->getRawOriginal();
        $after = $model->getAttributes();
        foreach (['before', 'after'] as $side) {
            if (isset(${$side}['estado_id'])) {
                ${$side}['estado_id'] = (int) ${$side}['estado_id'] === 1 ? 'Activo' : 'Inactivo';
            }
        }
        $notifications = app(SystemNotifications::class);
        $changes = $notifications->changes($before, $after, $fields);
        if ($changes === []) {
            return;
        }
        $action = $created ? 'Registro creado' : ($model->wasChanged('estado_id') ? ((int) $model->estado_id === 1 ? 'Registro reactivado' : 'Registro desactivado') : 'Registro actualizado');
        $notifications->activity($module, $action, $kind.': '.($model->nombre ?? $model->numero ?? $model->getKey()), $changes, $branches);
    }
}
