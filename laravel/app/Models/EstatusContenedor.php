<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class EstatusContenedor extends Model
{
    protected $table = 'estatus_contenedores';

    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'tipo_estatus_id',
        'estado_id',
    ];

    protected function casts(): array
    {
        return [
            'tipo_estatus_id' => 'integer',
            'estado_id' => 'integer',
        ];
    }

    public function tipo()
    {
        return $this->belongsTo(TipoEstatus::class, 'tipo_estatus_id');
    }

    public function scopeActive($query)
    {
        return $query->where('estado_id', 1);
    }

    public function requiresObservations(): bool
    {
        return Str::startsWith(Str::upper(Str::ascii(trim($this->nombre))), ['DMG', 'DANADO', 'DANADA']);
    }
}
