<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class EstatusIpsa extends Model
{
    protected $table = 'estatus_ipsa';

    public $timestamps = false;

    protected $fillable = ['nombre', 'estado_id'];

    public function scopeActive($query)
    {
        return $query->where('estado_id', 1);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('estado_id', 1)->whereRaw("UPPER(TRIM(nombre)) = 'DISPONIBLE'");
    }
}
