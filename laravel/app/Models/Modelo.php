<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Modelo extends Model
{
    protected $table = 'modelos';

    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'tipo_unidad_id',
        'estado_id',
    ];

    protected function casts(): array
    {
        return [
            'tipo_unidad_id' => 'integer',
            'estado_id' => 'integer',
        ];
    }

    public function tipoUnidad()
    {
        return $this->belongsTo(TipoUnidad::class, 'tipo_unidad_id');
    }

    public function scopeActive($query)
    {
        return $query->where('estado_id', 1);
    }
}
