<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Bloque extends Model
{
    protected $table = 'bloques';

    public $timestamps = false;

    protected $fillable = [
        'patio_id',
        'numero',
        'capacidad_maxima',
        'capacidad_actual',
        'estado_id',
    ];

    protected function casts(): array
    {
        return [
            'patio_id' => 'integer',
            'numero' => 'integer',
            'capacidad_maxima' => 'integer',
            'capacidad_actual' => 'integer',
            'estado_id' => 'integer',
        ];
    }

    public function patio()
    {
        return $this->belongsTo(Patio::class, 'patio_id');
    }

    public function contenedores()
    {
        return $this->hasMany(Contenedor::class, 'bloque_id');
    }

    public function contenedoresActivos()
    {
        return $this->hasMany(Contenedor::class, 'bloque_id')->where('estado_id', 1);
    }

    public function scopeActive($query)
    {
        return $query->where('estado_id', 1);
    }
}
