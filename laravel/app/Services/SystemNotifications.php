<?php

namespace App\Services;

use App\Models\Contenedor;
use App\Models\EstatusContenedor;
use App\Models\EstatusIpsa;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SystemNotifications
{
    private const ACTIVITY_MODULES = [
        'containers' => ['Contenedores', 'containers.view'], 'transfers' => ['Traslados', 'transfers.view'],
        'dispatches' => ['Despachos', 'dispatches.view'], 'users' => ['Usuarios', 'users.view'],
        'navieras' => ['Navieras', 'navieras.view'], 'auditoras' => ['Catálogo de auditoras', 'auditoras.view'],
        'repuestos' => ['Repuestos', 'repuestos.view'], 'reparaciones' => ['Catálogo de reparaciones', 'reparaciones.view'],
        'unit-types' => ['Tipos y modelos', 'unit-types.view'], 'statuses' => ['Catálogo de estados', 'statuses.view'],
        'yard-layout' => ['Patios y bloques', 'yard-layout.view'], 'branches' => ['Sucursales', 'superadmin'],
        'parking' => ['Parqueo', 'superadmin'], 'audits' => ['Auditorías', 'pyp.view'],
    ];

    public function activity(string $module, string $action, string $subject, array $changes = [], ?array $branchIds = null, ?User $actor = null): void
    {
        $actor ??= auth()->user();
        if (! $actor || (int) $actor->estado_id !== 1 || ! isset(self::ACTIVITY_MODULES[$module])) {
            return;
        }
        [$label, $permission] = self::ACTIVITY_MODULES[$module];
        $branches = $branchIds === null ? DB::table('sucursales')->where('estado_id', 1)->pluck('id')->all() : array_unique(array_map('intval', $branchIds));
        $key = 'activity:'.Str::uuid();
        $details = json_encode(['module' => $label, 'subject' => $subject, 'actor' => $actor->username, 'action' => $action, 'changes' => $changes], JSON_THROW_ON_ERROR);
        foreach ($branches as $branchId) {
            if ($branchId < 1) {
                continue;
            }
            foreach ($this->internalUsers($branchId, $permission) as $user) {
                $this->deliver($user, $branchId, $module, 'internal', $key.':'.$branchId, $action,
                    $subject.' · '.$actor->username, ['required_permission' => $permission, 'details' => $details]);
            }
        }
    }

    public function changes(array $before, array $after, array $fields): array
    {
        $result = [];
        foreach ($fields as $field => $label) {
            $old = $before[$field] ?? null;
            $new = $after[$field] ?? null;
            $format = fn ($value): ?string => $value === null ? null : (is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : (string) $value);
            if ($format($old) !== $format($new)) {
                $result[] = ['field' => $label, 'before' => $format($old), 'after' => $format($new)];
            }
        }

        return $result;
    }

    public function userChanged(User $actor, User $target, array $before, string $action, bool $passwordChanged = false): void
    {
        $after = $target->toArray();
        $after['external_branches'] = $target->authorizedExternalBranches()->orderBy('sucursales.id')->pluck('sucursales.id')->all();
        $changes = $this->changes($before, $after, ['username' => 'Nombre de usuario', 'email' => 'Correo', 'rol_id' => 'Rol',
            'sucursal_id' => 'Sucursal', 'estado_id' => 'Estado (1 activo / 2 inactivo)', 'permissions' => 'Permisos',
            'external_audit_company_id' => 'Empresa auditora', 'external_access_level' => 'Nivel de acceso externo']);
        if (($before['external_branches'] ?? []) !== $after['external_branches']) {
            $changes[] = ['field' => 'Acceso a sucursales externas', 'before' => count($before['external_branches'] ?? []).' sucursales', 'after' => count($after['external_branches']).' sucursales (autorizaciones actualizadas)'];
        }
        if ($passwordChanged) {
            $changes[] = ['field' => 'Contraseña', 'before' => null, 'after' => 'Restablecida; valor protegido'];
        }
        if ($changes !== []) {
            $branches = array_filter(array_unique(array_merge([$before['sucursal_id'] ?? null, $target->sucursal_id], $before['external_branches'] ?? [], $after['external_branches'])));
            $this->activity('users', $action, $target->username, $changes, $branches ?: null, $actor);
        }
    }

    public function bellPreview(object $notification): array
    {
        $details = json_decode($notification->details ?? 'null', true) ?? [];
        $actor = $details['actor'] ?? null;
        $description = $details['subject'] ?? $notification->body;
        if (Str::startsWith($notification->event_key, ['container-status:', 'container-ingress:'])) {
            $lines = preg_split('/\R/u', $description);
            $content = [];
            foreach ($lines as $line) {
                if (preg_match('/^(?:Modificado|Registrado) por:\s*(.+)$/u', $line, $match)) {
                    $actor ??= trim($match[1]);
                } else {
                    $content[] = $line;
                }
            }
            $description = implode("\n", $content);
        }
        if (! $actor && str_starts_with($notification->event_key, 'review:')) {
            $actor = DB::table('maintenance_review_requests as r')->join('users as u', 'u.id', '=', 'r.requested_by')
                ->where('r.id', (int) Str::after($notification->event_key, 'review:'))->value('u.username');
        }
        if (! $actor && Str::startsWith($notification->event_key, ['container-ingress:', 'damaged-ingress:'])) {
            $actor = DB::table('container_ingress_cycles as c')->join('users as u', 'u.id', '=', 'c.created_by')
                ->where('c.id', $notification->ingress_cycle_id)->value('u.username');
        }

        $subject = $details['subject'] ?? trim(Str::before(Str::before($description, "\n"), ' · '));
        $summary = match ($notification->title ?? '') {
            'Solicitud de recuperacion de contrasena', 'Solicitud de recuperación de contraseña' => $notification->body,
            'Contenedor trasladado' => 'Se trasladó el contenedor '.$subject.'.',
            'Contenedor despachado' => 'Se despachó el contenedor '.$subject.'.',
            'Información del contenedor actualizada' => 'Se modificó el contenedor '.$subject.'.',
            'Estado del contenedor actualizado' => 'Se actualizó el estado del contenedor '.$subject.'.',
            'Nuevo contenedor ingresado' => 'Se ingresó el contenedor '.$subject.'.',
            'Contenedor reingresado' => 'Se registró el reingreso del contenedor '.$subject.'.',
            'Ingresó un contenedor con daños' => 'Se ingresó el contenedor '.$subject.' con daños.',
            'Reparación pendiente de revisión' => 'Se solicitó revisar la reparación del contenedor '.$subject.'.',
            'Reparación confirmada' => 'Se confirmó la reparación del contenedor '.$subject.'.',
            'La auditora solicitó una corrección' => 'Se solicitó corregir la reparación del contenedor '.$subject.'.',
            'Contenedor asignado a tu auditora', 'Contenedor asignado a revisión externa', 'Asignación del ingreso regularizada' => 'Se asignó el contenedor '.$subject.' a revisión externa.',
            'Reparación lista para auditoría' => 'La reparación del contenedor '.$subject.' está lista para auditoría.',
            'Contenedor disponible' => 'El contenedor '.$subject.' pasó de OOS a Disponible.',
            'Corrección lista para revisión' => 'Se envió una corrección del contenedor '.$subject.' para revisión.',
            'Auditoría externa actualizada' => 'Se actualizó la auditoría del contenedor '.$subject.'.',
            'Asignación de auditoría modificada', 'Instrucción de auditoría modificada' => 'Se modificó la asignación de auditoría del contenedor '.$subject.'.',
            'Usuario creado' => 'Se creó el usuario '.$subject.'.',
            'Usuario actualizado' => 'Se modificó el usuario '.$subject.'.',
            'Usuario reactivado' => 'Se reactivó el usuario '.$subject.'.',
            'Usuario desactivado' => 'Se desactivó el usuario '.$subject.'.',
            'Registro creado', 'Creacion de catálogo' => 'Se creó el registro '.$subject.'.',
            'Registro actualizado', 'Actualizacion de catálogo' => 'Se modificó el registro '.$subject.'.',
            'Registro reactivado', 'Reactivacion de catálogo' => 'Se reactivó el registro '.$subject.'.',
            'Registro desactivado', 'Desactivacion de catálogo' => 'Se desactivó el registro '.$subject.'.',
            default => 'Se registró una actualización de '.$subject.'.',
        };

        return ['actor' => $actor, 'description' => $summary];
    }

    public function categories(User $user): array
    {
        if ((int) $user->rol_id === 4) {
            return ['repairs' => 'Mis reparaciones', 'containers' => 'Ingresos con daños'];
        }
        if ((int) $user->rol_id === 5) {
            return ['audits' => 'Auditorías'];
        }

        $categories = array_filter([
            'containers' => $user->hasPermission('containers.view') ? 'Contenedores' : null,
            'repairs' => $user->hasPermission('maintenance.view') ? 'Reparaciones' : null,
            'audits' => $user->hasPermission('pyp.view') ? 'Auditorías' : null,
        ]);
        foreach (self::ACTIVITY_MODULES as $module => [$label, $permission]) {
            if ($user->hasPermission($permission)) {
                $categories[$module] ??= $label;
            }
        }

        if ((int) $user->rol_id === 1 && config('account-recovery.enabled')) {
            $categories['account-recovery'] = 'Recuperación de contraseñas';
        }

        return $categories;
    }

    public function accountRecoveryRequested(User $target, int $requestId): void
    {
        foreach (User::active()->where('rol_id', 1)->get() as $supervisor) {
            $branch = $supervisor->sucursal_id ?: Sucursal::activas()->value('id');
            if ($branch) {
                $this->deliver($supervisor, (int) $branch, 'account-recovery', 'internal', 'account-recovery:'.$requestId,
                    'Solicitud de recuperación de contraseña', $target->username.' solicitó recuperar su acceso.',
                    ['details' => json_encode(['actor' => $target->username, 'subject' => $target->username], JSON_THROW_ON_ERROR)]);
            }
        }
    }

    public function inbox(User $user, int $branchId, string $folder = 'inbox'): Builder
    {
        abort_unless(in_array($folder, ['inbox', 'archived'], true), 422);
        abort_unless((int) $user->estado_id === 1, 403);
        app(ExternalAuditAccess::class)->authorizeBranch($user, $branchId);
        if (Cache::add('notifications.retention.cleanup', true, now()->addMinutes(5))) {
            try {
                $this->pruneExpired();
            } catch (\Throwable $exception) {
                Cache::forget('notifications.retention.cleanup');
                throw $exception;
            }
        }
        $query = DB::table('system_notifications as n')->where('n.user_id', $user->id)
            ->where(function (Builder $scope) use ($user, $branchId): void {
                $scope->where('n.branch_id', $branchId);
                if ((int) $user->rol_id === 1 && config('account-recovery.enabled')) {
                    $scope->orWhere('n.category', 'account-recovery');
                }
            })->whereIn('n.category', array_keys($this->categories($user)));
        if ($folder === 'archived') {
            $query->whereNotNull('n.archived_at')->where('n.expires_at', '>', now());
        } else {
            $query->whereNull('n.archived_at');
        }
        if ((int) $user->rol_id === 4) {
            $query->where('n.audience', 'mechanic')->where(function (Builder $events) use ($user, $branchId): void {
                $events->where(function (Builder $personal) use ($user): void {
                    $personal->where(function (Builder $types): void {
                        $types->where('n.event_key', 'like', 'repair-confirmed:%')->orWhere('n.event_key', 'like', 'correction-requested:%')->orWhere('n.event_key', 'like', 'audit-approved:%')
                            ->orWhere(function (Builder $legacy): void {
                                $legacy->where('n.event_key', 'like', 'maintenance-result:%')->whereIn('n.title', ['Reparación confirmada', 'La auditora solicitó una corrección']);
                            });
                    })->whereExists(function (Builder $jobs) use ($user): void {
                        $jobs->selectRaw('1')->from('maintenance_jobs as nj')->whereColumn('nj.id', 'n.job_id')
                            ->whereColumn('nj.branch_id', 'n.branch_id')->where('nj.mechanic_id', $user->id);
                    });
                })->orWhere(function (Builder $requests) use ($branchId): void {
                    $requests->where('n.event_key', 'like', 'audit-repair-requested:%')
                        ->whereIn('n.container_id', app(MaintenanceWorkflow::class)->auditRequestedContainers($branchId)->select('c.id'))
                        ->whereExists(function (Builder $events): void {
                            $events->selectRaw('1')->from('container_assignment_events as request_notice')
                                ->whereColumn('request_notice.container_id', 'n.container_id')->whereColumn('request_notice.ingress_cycle_id', 'n.ingress_cycle_id')
                                ->whereRaw("CONCAT('audit-repair-requested:', request_notice.id) = n.event_key")
                                ->whereNotExists(function (Builder $newer): void {
                                    $newer->selectRaw('1')->from('container_assignment_events as newer_notice')
                                        ->whereColumn('newer_notice.container_id', 'request_notice.container_id')
                                        ->whereColumn('newer_notice.ingress_cycle_id', 'request_notice.ingress_cycle_id')
                                        ->where('newer_notice.event_type', 'external_audit_status')->whereColumn('newer_notice.id', '>', 'request_notice.id');
                                });
                        });
                })->orWhere(function (Builder $ingress): void {
                    $ingress->where('n.event_key', 'like', 'damaged-ingress:%')->whereExists(function (Builder $containers): void {
                        $containers->selectRaw('1')->from('contenedores as nc')->join('bloques as nb', 'nb.id', '=', 'nc.bloque_id')
                            ->join('patios as np', 'np.id', '=', 'nb.patio_id')->join('container_ingress_cycles as ni', 'ni.id', '=', 'nc.current_ingress_cycle_id')
                            ->whereColumn('nc.id', 'n.container_id')->whereColumn('ni.id', 'n.ingress_cycle_id')
                            ->whereColumn('np.sucursal_id', 'n.branch_id')->whereColumn('ni.branch_id', 'n.branch_id')
                            ->where('nc.estado_id', 1)->whereNull('ni.closed_at');
                    });
                });
            });
        } elseif ((int) $user->rol_id === 5) {
            $visible = app(ExternalAuditAccess::class)->scope(
                DB::table('contenedores as c')->join('pyp_auditoria as pa', 'pa.contenedor_id', '=', 'c.id')
                    ->join('bloques as b', 'b.id', '=', 'c.bloque_id')->join('patios as p', 'p.id', '=', 'b.patio_id')
                    ->where('c.estado_id', 1)->where('pa.estado_id', 1)->where('p.sucursal_id', $branchId), $user, $branchId
            )->select('c.id');
            $query->where('n.audience', 'auditor')->where('n.audit_company_id', $user->external_audit_company_id)
                ->where(function (Builder $events): void {
                    $events->where('n.event_key', 'like', 'audit-available:%')->orWhere('n.event_key', 'like', 'audit-correction:%')
                        ->orWhere(function (Builder $legacy): void {
                            $legacy->where('n.event_key', 'like', 'audit-assigned:%')->where('n.title', 'Corrección lista para revisión');
                        });
                })
                ->whereIn('n.container_id', $visible)->whereExists(function (Builder $cycles): void {
                    $cycles->selectRaw('1')->from('contenedores as nc')->whereColumn('nc.id', 'n.container_id')
                        ->whereColumn('nc.current_ingress_cycle_id', 'n.ingress_cycle_id');
                });
        } else {
            $query->whereIn('n.audience', ['reviewer', 'internal']);
            if ((int) $user->rol_id !== 1) {
                $query->where(function (Builder $permissions) use ($user): void {
                    $permissions->whereNull('n.required_permission')->orWhereIn('n.required_permission', AccessPermissions::effective($user));
                });
            }
        }

        return $query;
    }

    public function act(User $user, int $branchId, string $action, array $ids, string $folder = 'inbox'): int
    {
        $allowed = match ($folder) {
            'inbox' => ['read', 'archive'],
            'archived' => ['read', 'unarchive'],
            default => [],
        };
        abort_unless(in_array($action, $allowed, true), 422);
        $ids = Validator::make(['ids' => $ids], [
            'ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ])->validate()['ids'];

        return DB::transaction(function () use ($user, $branchId, $action, $ids, $folder): int {
            $query = $this->inbox($user, $branchId, $folder)->whereIn('n.id', $ids);
            $visible = (clone $query)->lockForUpdate()->pluck('n.id');
            abort_unless($visible->count() === count($ids), 404);
            $changes = match ($action) {
                'read' => ['read_at' => now()],
                'archive' => ['archived_at' => now(), 'expires_at' => now()->addMonthNoOverflow()],
                'unarchive' => ['archived_at' => null, 'expires_at' => null],
            };
            if ($action === 'read') {
                $query->whereNull('n.read_at');
            }

            return $query->update($changes);
        });
    }

    public function pruneExpired(): int
    {
        return DB::table('system_notifications')->whereNotNull('archived_at')->where('expires_at', '<=', now())->delete();
    }

    public function reviewRequested(int $reviewId): void
    {
        $review = DB::table('maintenance_review_requests as r')->join('maintenance_jobs as j', 'j.id', '=', 'r.job_id')
            ->join('contenedores as c', 'c.id', '=', 'j.container_id')->where('r.id', $reviewId)
            ->select('r.*', 'j.container_id', 'j.ingress_cycle_id', 'c.nombre')->first();
        if (! $review || $review->status !== 'pending') {
            return;
        }
        foreach ($this->internalUsers((int) $review->branch_id, 'maintenance.view') as $user) {
            $this->deliver($user, (int) $review->branch_id, 'repairs', 'reviewer', 'review:'.$reviewId,
                'Reparación pendiente de revisión', $review->nombre.' · Solicitud '.str_pad((string) $review->folio, 3, '0', STR_PAD_LEFT),
                ['job_id' => $review->job_id, 'container_id' => $review->container_id, 'ingress_cycle_id' => $review->ingress_cycle_id,
                    'details' => json_encode(['actor' => User::find($review->requested_by)?->username], JSON_THROW_ON_ERROR)], $review->created_at);
        }
    }

    public function reviewConfirmed(int $jobId, User $actor): void
    {
        $review = DB::table('maintenance_review_requests as r')->join('maintenance_jobs as j', 'j.id', '=', 'r.job_id')
            ->join('contenedores as c', 'c.id', '=', 'j.container_id')->where('r.job_id', $jobId)->where('r.status', 'confirmed')
            ->select('r.*', 'j.container_id', 'j.ingress_cycle_id', 'c.nombre')->first();
        if (! $review) {
            return;
        }
        foreach ($this->internalUsers((int) $review->branch_id, 'maintenance.view') as $user) {
            $this->deliver($user, (int) $review->branch_id, 'repairs', 'reviewer', 'review-confirmed:'.$review->id,
                'Reparación confirmada', $review->nombre.' · Solicitud '.str_pad((string) $review->folio, 3, '0', STR_PAD_LEFT),
                ['job_id' => $review->job_id, 'container_id' => $review->container_id, 'ingress_cycle_id' => $review->ingress_cycle_id,
                    'details' => json_encode(['actor' => $actor->username], JSON_THROW_ON_ERROR)], $review->updated_at);
        }
    }

    public function containerStatuses(Contenedor $container, int $branchId, object $before, array $after, User $actor): void
    {
        $this->auditAvailable($container, $branchId, $before, $after, $actor);
        $changes = [];
        foreach (['estatus_unidad_id' => ['Unidad', 'estatus_contenedores'], 'estatus_box_id' => ['Estructura', 'estatus_contenedores'], 'estatus_ipsa_id' => ['IPSA', 'estatus_ipsa']] as $column => [$label, $table]) {
            if ((int) $before->{$column} !== (int) $after[$column]) {
                $oldName = DB::table($table)->where('id', $before->{$column})->value('nombre') ?? 'Sin estado';
                $newName = DB::table($table)->where('id', $after[$column])->value('nombre') ?? 'Sin estado';
                $changes[] = $label.': '.$oldName.' → '.$newName;
            }
        }
        if ($changes !== []) {
            $key = 'container-status:'.Str::uuid();
            $body = 'Modificado por: '.$actor->username."\n".implode("\n", $changes);
            foreach ($this->internalUsers($branchId, 'containers.view') as $user) {
                $this->deliver($user, $branchId, 'containers', 'internal', $key, 'Estado del contenedor actualizado',
                    ($after['nombre'] ?? $container->nombre)."\n".$body,
                    ['container_id' => $container->id, 'ingress_cycle_id' => $container->current_ingress_cycle_id,
                        'details' => json_encode(['actor' => $actor->username], JSON_THROW_ON_ERROR)]);
            }
        }
    }

    public function auditChanged(Contenedor $container, int $branchId, string $status): void
    {
        $key = 'audit-status:'.Str::uuid();
        foreach ($this->internalUsers($branchId, 'pyp.view') as $user) {
            $this->deliver($user, $branchId, 'audits', 'internal', $key, 'Auditoría externa actualizada', $container->nombre.' · '.$status,
                ['container_id' => $container->id, 'ingress_cycle_id' => $container->current_ingress_cycle_id]);
        }
    }

    public function maintenanceResult(int $jobId, string $title, string $body, string $event = 'repair-confirmed', ?int $eventId = null, array $details = []): void
    {
        abort_unless(in_array($event, ['repair-confirmed', 'correction-requested', 'audit-approved'], true), 422);
        $job = DB::table('maintenance_jobs as j')->join('contenedores as c', 'c.id', '=', 'j.container_id')->where('j.id', $jobId)
            ->select('j.*', 'c.nombre')->firstOrFail();
        $user = User::active()->where('rol_id', 4)->where('sucursal_id', $job->branch_id)->find($job->mechanic_id);
        if ($user) {
            $reference = ['job_id' => $job->id, 'container_id' => $job->container_id, 'ingress_cycle_id' => $job->ingress_cycle_id];
            if ($details) {
                $reference['details'] = json_encode($details, JSON_THROW_ON_ERROR);
            }
            $this->deliver($user, (int) $job->branch_id, 'repairs', 'mechanic', $event.':'.($eventId ?? Str::uuid()), $title, $job->nombre.' · '.$body, $reference);
        }
    }

    public function auditAvailable(Contenedor $container, int $branchId, object $before, array $after, User $actor): void
    {
        if ((int) $before->estatus_ipsa_id === (int) $after['estatus_ipsa_id']) {
            return;
        }
        $wasDamaged = DB::table('estatus_ipsa')->where('id', $before->estatus_ipsa_id)->whereRaw("UPPER(TRIM(nombre)) = 'OOS'")->exists();
        $available = DB::table('estatus_ipsa')->where('id', $after['estatus_ipsa_id'])->where('estado_id', 1)
            ->whereRaw("UPPER(TRIM(nombre)) = 'DISPONIBLE'")->exists();
        if ($wasDamaged && $available) {
            $this->auditorNotice($container, $branchId, 'audit-available:'.Str::uuid(), 'Contenedor disponible', $actor,
                $after['nombre'] ?? $container->nombre);
        }
    }

    public function correctionSubmitted(Contenedor $container, int $branchId, int $roundId, User $actor): void
    {
        $this->auditorNotice($container, $branchId, 'audit-correction:'.$roundId, 'Corrección lista para revisión', $actor, $container->nombre);
    }

    public function requestedRepairConfirmed(Contenedor $container, int $branchId, int $jobId, User $actor): void
    {
        if (EstatusIpsa::available()->whereKey($container->estatus_ipsa_id)->exists()) {
            $this->auditorNotice($container, $branchId, 'audit-correction:job:'.$jobId, 'Solicitud técnica atendida', $actor, $container->nombre);
        }
    }

    private function auditorNotice(Contenedor $container, int $branchId, string $key, string $title, User $actor, string $name): void
    {
        $cycle = DB::table('container_ingress_cycles')->where('id', $container->current_ingress_cycle_id)
            ->where('container_id', $container->id)->where('branch_id', $branchId)->whereNull('closed_at')->first();
        if (! $cycle?->audit_company_id) {
            return;
        }
        $users = User::active()->where('rol_id', 5)->where('external_audit_company_id', $cycle->audit_company_id)
            ->whereIn('id', DB::table('external_audit_user_branch')->where('branch_id', $branchId)->select('user_id'))->get();
        foreach ($users as $user) {
            $access = app(ExternalAuditAccess::class);
            if ($access->configured($user) && $access->scope(
                DB::table('contenedores as c')->join('pyp_auditoria as pa', 'pa.contenedor_id', '=', 'c.id')
                    ->join('bloques as b', 'b.id', '=', 'c.bloque_id')->join('patios as p', 'p.id', '=', 'b.patio_id')
                    ->where('c.id', $container->id)->where('c.estado_id', 1)->where('pa.estado_id', 1)->where('p.sucursal_id', $branchId), $user, $branchId
            )->exists()) {
                $this->deliver($user, $branchId, 'audits', 'auditor', $key, $title, $name,
                    ['container_id' => $container->id, 'ingress_cycle_id' => $cycle->id, 'audit_company_id' => $cycle->audit_company_id,
                        'details' => json_encode(['actor' => $actor->username], JSON_THROW_ON_ERROR)]);
            }
        }
    }

    public function containerIngress(Contenedor $container, int $branchId, User $actor, bool $reentry = false): void
    {
        $cycle = DB::table('container_ingress_cycles')->where('id', $container->current_ingress_cycle_id)->where('container_id', $container->id)
            ->where('branch_id', $branchId)->whereNull('closed_at')->first();
        if (! $cycle) {
            return;
        }
        $body = [$container->nombre, 'Registrado por: '.$actor->username];
        foreach (['estatus_unidad_id' => ['Unidad', 'estatus_contenedores'], 'estatus_box_id' => ['Estructura', 'estatus_contenedores'], 'estatus_ipsa_id' => ['IPSA', 'estatus_ipsa']] as $field => [$label, $table]) {
            $body[] = $label.': '.(DB::table($table)->where('id', $container->{$field})->value('nombre') ?? 'Sin estado registrado');
        }
        $location = DB::table('bloques as b')->join('patios as p', 'p.id', '=', 'b.patio_id')->where('b.id', $container->bloque_id)
            ->where('p.sucursal_id', $branchId)->select('p.nombre', 'b.numero')->first();
        if ($location) {
            $body[] = 'Ubicación: '.$location->nombre.' / Bloque '.$location->numero.' / Pos. '.$container->posicion;
        }
        foreach ($this->internalUsers($branchId, 'containers.view') as $user) {
            $this->deliver($user, $branchId, 'containers', 'internal', 'container-ingress:'.$cycle->id,
                $reentry ? 'Contenedor reingresado' : 'Nuevo contenedor ingresado', implode("\n", $body),
                ['container_id' => $container->id, 'ingress_cycle_id' => $cycle->id,
                    'details' => json_encode(['actor' => $actor->username], JSON_THROW_ON_ERROR)]);
        }
    }

    public function damagedIngress(Contenedor $container, int $branchId): void
    {
        $cycle = DB::table('container_ingress_cycles')->where('id', $container->current_ingress_cycle_id)->where('container_id', $container->id)
            ->where('branch_id', $branchId)->whereNull('closed_at')->first();
        if (! $cycle) {
            return;
        }
        $damage = [];
        foreach (['estatus_unidad_id' => ['Unidad', 'observaciones_unidad'], 'estatus_box_id' => ['Estructura', 'observaciones_box']] as $field => [$label, $notes]) {
            $status = EstatusContenedor::find($container->{$field});
            if ($status?->requiresObservations()) {
                $damage[] = $label.': '.$status->nombre.($container->{$notes} ? ' · '.$container->{$notes} : '');
            }
        }
        if ($damage === []) {
            return;
        }
        foreach (User::active()->where('rol_id', 4)->where('sucursal_id', $branchId)->get() as $user) {
            $this->deliver($user, $branchId, 'containers', 'mechanic', 'damaged-ingress:'.$cycle->id,
                'Ingresó un contenedor con daños', $container->nombre."\n".implode("\n", $damage),
                ['container_id' => $container->id, 'ingress_cycle_id' => $cycle->id,
                    'details' => json_encode(['actor' => User::find($cycle->created_by)?->username], JSON_THROW_ON_ERROR)]);
        }
    }

    public function auditRepairRequested(Contenedor $container, int $branchId): void
    {
        $request = app(MaintenanceWorkflow::class)->pendingAuditRequest($container, $branchId);
        if (! $request) {
            return;
        }
        $audit = json_decode($request->after_data, true)['audit'];
        foreach (User::active()->where('rol_id', 4)->where('sucursal_id', $branchId)->get() as $user) {
            $this->deliver($user, $branchId, 'repairs', 'mechanic', 'audit-repair-requested:'.$request->id,
                'La auditora solicitó una reparación técnica', $container->nombre.' · '.$audit['informacion_adicional'],
                ['container_id' => $container->id, 'ingress_cycle_id' => $container->current_ingress_cycle_id,
                    'details' => json_encode(['actor' => $audit['inspector_name'] ?? User::find($request->user_id)?->username, 'audit_event_id' => $request->id], JSON_THROW_ON_ERROR)]);
        }
    }

    private function internalUsers(int $branchId, string $permission): Collection
    {
        return User::active()->whereIn('rol_id', [1, 2, 3])->where(function ($query) use ($branchId): void {
            $query->where('rol_id', 1)->orWhere('sucursal_id', $branchId);
        })->get()->filter(fn (User $user): bool => $user->hasPermission($permission));
    }

    private function deliver(User $user, int $branchId, string $category, string $audience, string $key, string $title, string $body, array $reference, mixed $createdAt = null): void
    {
        if ((int) $user->rol_id === 4 && ($audience !== 'mechanic' || ! Str::startsWith($key, ['repair-confirmed:', 'correction-requested:', 'audit-approved:', 'damaged-ingress:', 'audit-repair-requested:']))) {
            return;
        }
        $reference['details'] ??= json_encode(['actor' => auth()->user()?->username], JSON_THROW_ON_ERROR);
        DB::table('system_notifications')->upsert([array_merge($reference, [
            'user_id' => $user->id, 'branch_id' => $branchId, 'category' => $category, 'audience' => $audience,
            'event_key' => $key, 'title' => $title, 'body' => $body, 'created_at' => $createdAt ?? now(),
        ])], ['user_id', 'event_key'], ['event_key']);
    }
}
