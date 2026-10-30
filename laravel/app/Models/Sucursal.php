<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sucursal extends Model
{
    protected $table = 'sucursales';

    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'ubicacion',
        'departamento_codigo',
        'municipio_codigo',
        'direccion',
        'estado_id',
    ];

    protected function casts(): array
    {
        return [
            'estado_id' => 'integer',
        ];
    }

    public function scopeActivas($query)
    {
        return $query->where('estado_id', 1);
    }

    public function patios()
    {
        return $this->hasMany(Patio::class, 'sucursal_id');
    }

    public function users()
    {
        return $this->hasMany(User::class, 'sucursal_id');
    }
}
