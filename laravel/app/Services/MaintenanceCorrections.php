<?php

namespace App\Services;

use App\Models\Contenedor;
use App\Models\EstatusContenedor;
use App\Models\EstatusIpsa;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MaintenanceCorrections
{
    public function visibleJob(User $user, int $branchId, int $jobId, bool $allowContainerRead = false): object
    {
        app(ExternalAuditAccess::class)->authorizeBranch($user, $branchId);
        $job = app(MaintenanceWorkflow::class)->jobs($branchId)->where('j.id', $jobId)->first();
        abort_unless($job && (int) $user->estado_id === 1, 404);
        if ((int) $user->rol_id === 4) {
            abort_unless((int) $job->mechanic_id === (int) $user->id, 403);
        } elseif ((int) $user->rol_id === 5) {
            abort_if($job->status === 'in_progress', 404);
            $visible = app(ExternalAuditAccess::class)->scope(
                DB::table('contenedores as c')->join('pyp_auditoria as pa', 'pa.contenedor_id', '=', 'c.id')
                    ->join('bloques as b', 'b.id', '=', 'c.bloque_id')->join('patios as p', 'p.id', '=', 'b.patio_id')
                    ->where('c.estado_id', 1)->where('pa.estado_id', 1)->where('p.sucursal_id', $branchId), $user, $branchId
            )->where('c.id', $job->container_id)->where('c.current_ingress_cycle_id', $job->ingress_cycle_id)->exists();
            abort_unless($visible, 404);
        } else {
            abort_unless(in_array((int) $user->rol_id, [1, 2, 3], true)
                && ($user->hasPermission('maintenance.view') || ($allowContainerRead && $user->hasPermission('containers.view'))), 403);
        }

        return $job;
    }

    public function reviewActions(User $user, int $branchId, object $job): array
    {
        $none = ['canConfirmWork' => false, 'canUpdateTechnicalStates' => false];
        if ((int) $user->estado_id !== 1 || ! in_array((int) $user->rol_id, [1, 2, 3], true)
            || ! in_array($job->status, ['pending_review', 'completed'], true)) {
            return $none;
        }
        $container = Contenedor::find($job->container_id);
        $workflow = app(MaintenanceWorkflow::class);
        if (! $container || (int) $container->estado_id !== 1
            || ! $workflow->containers($branchId)->where('c.id', $container->id)->exists()
            || (int) $workflow->currentJobs($container)->latest('id')->value('id') !== (int) $job->id
            || ($job->ingress_cycle_id && ! DB::table('container_ingress_cycles')->where('id', $job->ingress_cycle_id)->whereNull('closed_at')->exists())) {
            return $none;
        }
        $needsTechnicalUpdate = $user->hasPermission('containers.technical')
            && EstatusContenedor::whereIn('id', [$container->estatus_unidad_id, $container->estatus_box_id])->get()
                ->contains(fn (EstatusContenedor $status): bool => $status->requiresObservations());
        $needsIpsaReview = $user->hasPermission('containers.ipsa')
            && ! EstatusIpsa::active()->whereKey($container->estatus_ipsa_id)->whereRaw("UPPER(TRIM(nombre)) = 'DISPONIBLE'")->exists();

        return [
            'canConfirmWork' => $job->status === 'pending_review' && $user->hasPermission('maintenance.confirm')
                && DB::table('maintenance_review_requests')->where('job_id', $job->id)->where('status', 'pending')->exists(),
            'canUpdateTechnicalStates' => $needsTechnicalUpdate || $needsIpsaReview,
        ];
    }

    public function confirm(User $user, int $branchId, int $jobId, int $version): void
    {
        abort_unless(in_array((int) $user->rol_id, [1, 2, 3], true) && $user->hasPermission('maintenance.confirm'), 403);
        $this->visibleJob($user, $branchId, $jobId);
        DB::transaction(function () use ($user, $branchId, $jobId, $version): void {
            $reference = DB::table('maintenance_jobs')->find($jobId);
            $container = Contenedor::whereKey($reference->container_id)->lockForUpdate()->firstOrFail();
            $job = DB::table('maintenance_jobs')->where('id', $jobId)->lockForUpdate()->first();
            abort_unless((int) $container->estado_id === 1 && app(MaintenanceWorkflow::class)->containers($branchId)->where('c.id', $container->id)->exists()
                && (int) app(MaintenanceWorkflow::class)->currentJobs($container)->latest('id')->value('id') === $jobId
                && (! $job->ingress_cycle_id || DB::table('container_ingress_cycles')->where('id', $job->ingress_cycle_id)->whereNull('closed_at')->exists()), 409);
            if ($job->status !== 'pending_review' || (int) $job->version !== $version) {
                throw ValidationException::withMessages(['work' => 'La solicitud cambió o ya fue confirmada. Recarga la página.']);
            }
            DB::table('maintenance_jobs')->where('id', $jobId)->update(['status' => 'completed', 'version' => $version + 1, 'updated_at' => now()]);
            DB::table('maintenance_review_requests')->where('job_id', $jobId)->where('status', 'pending')->update(['status' => 'confirmed', 'updated_at' => now()]);
            $this->event($jobId, $user, 'repair_confirmed', ['version' => $version]);
            app(SystemNotifications::class)->reviewConfirmed($jobId, $user);
            app(SystemNotifications::class)->maintenanceResult($jobId, 'Reparación confirmada', 'El trabajo fue confirmado; la auditoría externa se resuelve por separado.');
            app(SystemNotifications::class)->auditAssigned($container, $branchId, 'Reparación lista para auditoría');
        });
    }

    public function request(Contenedor $container, User $user, string $reason): void
    {
        $job = app(MaintenanceWorkflow::class)->currentJobs($container)->latest('id')->lockForUpdate()->first();
        if (! $job || $job->status !== 'completed') {
            return;
        }
        $latest = DB::table('maintenance_corrections')->where('job_id', $job->id)->latest('number')->lockForUpdate()->first();
        if ($latest?->status === 'submitted') {
            DB::table('maintenance_corrections')->where('id', $latest->id)->update(['status' => 'rejected', 'updated_at' => now()]);
        }
        DB::table('maintenance_corrections')->insert([
            'job_id' => $job->id, 'number' => ($latest->number ?? 0) + 1, 'requested_by' => $user->id,
            'reason' => $reason, 'status' => 'draft', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('maintenance_jobs')->where('id', $job->id)->update(['status' => 'returned_for_repair', 'version' => $job->version + 1, 'updated_at' => now()]);
        $this->event($job->id, $user, 'correction_requested', ['reason' => $reason, 'round' => ($latest->number ?? 0) + 1]);
        app(SystemNotifications::class)->maintenanceResult($job->id, 'La auditora solicitó una corrección', $reason, 'correction-requested');
    }

    public function approve(Contenedor $container): void
    {
        $job = app(MaintenanceWorkflow::class)->currentJobs($container)->latest('id')->first();
        if ($job) {
            DB::table('maintenance_corrections')->where('job_id', $job->id)->where('status', 'submitted')->update(['status' => 'approved', 'updated_at' => now()]);
        }
    }

    public function save(User $user, int $branchId, int $jobId, array $input, array $uploads): void
    {
        app(MaintenanceWorkflow::class)->authorize($user, $branchId);
        $this->visibleJob($user, $branchId, $jobId);
        $data = Validator::make(array_replace($input, ['photos' => $uploads]), [
            'round_id' => ['required', 'integer'], 'version' => ['required', 'integer'],
            'action' => ['required', Rule::in(['save', 'submit'])], 'work_performed' => ['nullable', 'string', 'max:1000'],
            'materials' => ['nullable', 'array', 'max:40'], 'materials.*.part_id' => ['required', 'integer', 'distinct'],
            'materials.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:99999'],
            'photos' => ['array', 'max:5'], 'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:2048'],
            'remove_photos' => ['nullable', 'array', 'max:5'], 'remove_photos.*' => ['integer', 'min:0', 'max:4', 'distinct'],
        ])->validate();
        $stored = [];
        $removed = [];
        try {
            DB::transaction(function () use ($user, $branchId, $jobId, $data, $uploads, &$stored, &$removed): void {
                $reference = DB::table('maintenance_jobs')->find($jobId);
                $container = Contenedor::whereKey($reference->container_id)->lockForUpdate()->firstOrFail();
                $job = DB::table('maintenance_jobs')->where('id', $jobId)->lockForUpdate()->first();
                abort_unless((int) $job->mechanic_id === (int) $user->id && $job->status === 'returned_for_repair', 409);
                abort_unless((int) $container->estado_id === 1 && app(MaintenanceWorkflow::class)->containers($branchId)->where('c.id', $container->id)->exists()
                    && app(MaintenanceWorkflow::class)->currentJobs($container)->where('id', $jobId)->exists()
                    && (! $job->ingress_cycle_id || DB::table('container_ingress_cycles')->where('id', $job->ingress_cycle_id)->whereNull('closed_at')->exists()), 409);
                $round = DB::table('maintenance_corrections')->where('job_id', $jobId)->where('id', $data['round_id'])->lockForUpdate()->first();
                if (! $round || $round->status !== 'draft' || (int) $round->version !== (int) $data['version']) {
                    throw ValidationException::withMessages(['work' => 'La corrección cambió o ya fue enviada. Recarga la página.']);
                }
                $previous = collect(json_decode($round->materials ?? '[]', true));
                $materials = [];
                foreach ($data['materials'] ?? [] as $item) {
                    $part = DB::table('repair_parts')->where('id', $item['part_id'])->first();
                    $snapshot = $previous->firstWhere('part_id', $item['part_id']);
                    if (! $part || ((int) $part->status !== 1 && ! $snapshot)) {
                        throw ValidationException::withMessages(['materials' => 'Selecciona materiales activos del catálogo.']);
                    }
                    $materials[] = ['part_id' => $part->id, 'name' => $snapshot['name'] ?? $part->name, 'unit' => $snapshot['unit'] ?? $part->unit,
                        'unit_cost' => $snapshot ? $snapshot['unit_cost'] : $part->reference_cost, 'currency' => $snapshot['currency'] ?? $part->currency, 'quantity' => $item['quantity']];
                }
                $photos = [];
                foreach (json_decode($round->photos ?? '[]', true) as $index => $photo) {
                    if (in_array($index, array_map('intval', $data['remove_photos'] ?? []), true)) {
                        $removed[] = $photo['path'];
                    } else {
                        $photos[] = $photo;
                    }
                }
                if (count($photos) + count($uploads) > 5) {
                    throw ValidationException::withMessages(['photos' => 'Se permiten hasta 5 fotos por corrección.']);
                }
                foreach ($uploads as $file) {
                    $hash = hash_file('sha256', $file->getRealPath());
                    foreach ($photos as $photo) {
                        if (($photo['hash'] ?? null) === $hash) {
                            throw ValidationException::withMessages(['photos' => 'Esta fotografía ya está adjunta a la corrección.']);
                        }
                    }
                    $path = $file->store('maintenance/'.$jobId.'/corrections/'.$round->id, 'local');
                    if (! $path) {
                        throw new \RuntimeException('No se pudo guardar la fotografía.');
                    }
                    $stored[] = $path;
                    $photos[] = ['path' => $path, 'name' => mb_substr(basename($file->getClientOriginalName()), 0, 255), 'mime' => $file->getMimeType(), 'hash' => $hash];
                }
                $text = trim($data['work_performed'] ?? '');
                $submit = $data['action'] === 'submit';
                if ($submit && (mb_strlen($text) < 10 || count($photos) < 1)) {
                    throw ValidationException::withMessages(['work' => 'Explica la corrección con al menos 10 caracteres y adjunta al menos una foto nueva.']);
                }
                DB::table('maintenance_corrections')->where('id', $round->id)->update([
                    'work_performed' => $text, 'materials' => json_encode($materials, JSON_THROW_ON_ERROR), 'photos' => json_encode($photos, JSON_THROW_ON_ERROR),
                    'status' => $submit ? 'submitted' : 'draft', 'version' => $round->version + 1, 'submitted_at' => $submit ? now() : null, 'updated_at' => now(),
                ]);
                $this->event($jobId, $user, $submit ? 'correction_submitted' : 'correction_saved', ['round' => $round->number, 'version' => $round->version + 1]);
                if ($submit) {
                    DB::table('maintenance_jobs')->where('id', $jobId)->update(['status' => 'completed', 'version' => $job->version + 1, 'updated_at' => now()]);
                    DB::table('pyp_auditoria')->where('contenedor_id', $container->id)->where('estado_id', 1)->where('ingress_cycle_id', $job->ingress_cycle_id)
                        ->update(['estatus_pyp' => 'Pendiente de revision', 'informacion_adicional' => null, 'fecha_registro' => now(), 'usuario_id' => $user->id]);
                    app(SystemNotifications::class)->auditAssigned($container, $branchId, 'Corrección lista para revisión');
                }
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($stored);
            throw $exception;
        }
        Storage::disk('local')->delete($removed);
    }

    private function event(int $jobId, User $user, string $action, array $data): void
    {
        DB::table('maintenance_events')->insert(['job_id' => $jobId, 'user_id' => $user->id, 'action' => $action,
            'after_data' => json_encode($data, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}
