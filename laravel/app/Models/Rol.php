<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Rol extends Model
{
    protected $table = 'roles';

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

    public function scopeActive($query)
    {
        return $query->where('estado_id', 1);
    }
}
