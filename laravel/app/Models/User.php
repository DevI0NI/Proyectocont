<?php

namespace App\Models;

use App\Services\AccessPermissions;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    protected $table = 'users';

    protected $fillable = [
        'username',
        'email',
        'password',
        'rol_id',
        'sucursal_id',
        'estado_id',
        'permissions',
        'external_audit_company_id',
        'external_access_level',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'rol_id' => 'integer',
            'sucursal_id' => 'integer',
            'last_active_branch_id' => 'integer',
            'estado_id' => 'integer',
            'permissions' => 'array',
            'external_audit_company_id' => 'integer',
        ];
    }

    public function getNameAttribute(): string
    {
        return (string) $this->username;
    }

    public function rol()
    {
        return $this->belongsTo(Rol::class, 'rol_id');
    }

    public function hasPermission(string $permission): bool
    {
        return (int) $this->rol_id === 1 || in_array($permission, AccessPermissions::effective($this), true);
    }

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }

    public function authorizedExternalBranches()
    {
        return $this->belongsToMany(Sucursal::class, 'external_audit_user_branch', 'user_id', 'branch_id');
    }

    public function scopeActive($query)
    {
        return $query->where('estado_id', 1);
    }

    public function scopeInactive($query)
    {
        return $query->where('estado_id', 2);
    }
}
