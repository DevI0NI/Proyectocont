<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contenedor extends Model
{
    protected $table = 'contenedores';

    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'tipo_unidad_id',
        'modelo_id',
        'iso_code',
        'marca',
        'estatus_unidad_id',
        'observaciones_unidad',
        'estatus_box_id',
        'observaciones_box',
        'estatus_ipsa_id',
        'bloque_id',
        'posicion',
        'fecha_ingreso',
        'naviera',
        'transportista',
        'conductor',
        'placa_anio',
        'placa_cabezal',
        'placa_chasis',
        'estado_id',
    ];

    protected function casts(): array
    {
        return [
            'tipo_unidad_id' => 'integer',
            'modelo_id' => 'integer',
            'estatus_unidad_id' => 'integer',
            'estatus_box_id' => 'integer',
            'estatus_ipsa_id' => 'integer',
            'bloque_id' => 'integer',
            'posicion' => 'integer',
            'placa_anio' => 'integer',
            'estado_id' => 'integer',
            'fecha_ingreso' => 'datetime',
        ];
    }

    public function bloque()
    {
        return $this->belongsTo(Bloque::class, 'bloque_id');
    }

    public function scopeActive($query)
    {
        return $query->where('estado_id', 1);
    }
}
