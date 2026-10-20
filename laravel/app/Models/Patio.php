<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Patio extends Model
{
    protected $table = 'patios';

    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'sucursal_id',
        'estado_id',
    ];

    protected function casts(): array
    {
        return [
            'sucursal_id' => 'integer',
            'estado_id' => 'integer',
        ];
    }

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }

    public function bloques()
    {
        return $this->hasMany(Bloque::class, 'patio_id');
    }

    public function bloquesActivos()
    {
        return $this->hasMany(Bloque::class, 'patio_id')->where('estado_id', 1);
    }

    public function scopeActive($query)
    {
        return $query->where('estado_id', 1);
    }
}
