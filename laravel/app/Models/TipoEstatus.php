<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoEstatus extends Model
{
    protected $table = 'tipos_estatus';

    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'estado_id',
    ];

    protected function casts(): array
    {
        return [
            'estado_id' => 'integer',
        ];
    }

    public function estatus()
    {
        return $this->hasMany(EstatusContenedor::class, 'tipo_estatus_id');
    }

    public function scopeActive($query)
    {
        return $query->where('estado_id', 1);
    }
}
