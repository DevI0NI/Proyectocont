<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoUnidad extends Model
{
    protected $table = 'tipos_unidad';

    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'descripcion',
        'estado_id',
    ];

    protected function casts(): array
    {
        return [
            'estado_id' => 'integer',
        ];
    }

    public function modelos()
    {
        return $this->hasMany(Modelo::class, 'tipo_unidad_id');
    }

    public function modelosActivos()
    {
        return $this->hasMany(Modelo::class, 'tipo_unidad_id')->where('estado_id', 1);
    }

    public function contenedores()
    {
        return $this->hasMany(Contenedor::class, 'tipo_unidad_id');
    }

    public function scopeActive($query)
    {
        return $query->where('estado_id', 1);
    }
}
