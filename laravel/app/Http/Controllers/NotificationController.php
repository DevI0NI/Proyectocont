<?php

namespace App\Http\Controllers;

use App\Models\Sucursal;
use App\Services\ExternalAuditAccess;
use App\Services\MaintenanceCorrections;
use App\Services\SystemNotifications;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class NotificationController extends Controller
{
    private function context(Request $request): array
    {
        $branchId = (int) $request->session()->get('sucursal_activa');
        app(ExternalAuditAccess::class)->authorizeBranch($request->user(), $branchId);

        return ['sucursal' => Sucursal::activas()->findOrFail($branchId),
            'sucursales' => app(ExternalAuditAccess::class)->branches($request->user())->orderBy('nombre')->get()];
    }

    public function index(Request $request, SystemNotifications $notifications): View
    {
        $context = $this->context($request);
        $categories = $notifications->categories($request->user());
        $request->validate([
            'category' => ['nullable', Rule::in(array_keys($categories))],
            'status' => ['nullable', Rule::in(['unread', 'read'])],
            'folder' => ['nullable', Rule::in(['inbox', 'archived'])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return view('notifications.index', $context);
    }

    public function read(Request $request, int $notification, SystemNotifications $notifications): RedirectResponse
    {
        $query = $notifications->inbox($request->user(), (int) session('sucursal_activa'))->where('n.id', $notification);
        abort_unless((clone $query)->exists(), 404);
        $query->whereNull('n.read_at')->update(['read_at' => now()]);

        return back();
    }

    public function readAll(Request $request, SystemNotifications $notifications): RedirectResponse
    {
        $notifications->inbox($request->user(), (int) session('sucursal_activa'))->whereNull('n.read_at')->update(['read_at' => now()]);

        return back()->with('status', 'Notificaciones marcadas como leídas.');
    }

    public function open(Request $request, int $notification, SystemNotifications $notifications): RedirectResponse
    {
        $request->validate(['folder' => ['nullable', Rule::in(['inbox', 'archived'])]]);
        $query = $notifications->inbox($request->user(), (int) session('sucursal_activa'), $request->input('folder') ?: 'inbox')->where('n.id', $notification);
        $record = (clone $query)->first();
        abort_unless($record, 404);
        $query->whereNull('n.read_at')->update(['read_at' => now()]);
        $returnParams = array_filter(['return_to' => $this->localReturnUrl($request)]);
        if (str_starts_with($record->event_key, 'activity:') || str_starts_with($record->event_key, 'container-status:') || str_starts_with($record->event_key, 'damaged-ingress:') || str_starts_with($record->event_key, 'container-ingress:')) {
            return redirect()->route('notifications.detail', ['notification' => $notification, 'folder' => $request->input('folder') ?: 'inbox'] + $returnParams);
        }
        if ((int) $request->user()->rol_id === 4) {
            if (DB::table('maintenance_jobs')->where('id', $record->job_id)->where('status', 'returned_for_repair')->exists()
                || DB::table('maintenance_corrections')->where('job_id', $record->job_id)->exists()) {
                return redirect()->route('corrections.show', ['job' => $record->job_id] + $returnParams);
            }

            return redirect()->route('maintenance.show', ['job' => $record->job_id, 'step' => 2] + $returnParams);
        }
        if ($record->audience === 'reviewer') {
            return redirect()->route('notifications.repair', ['job' => $record->job_id] + $returnParams);
        }
        if ((int) $request->user()->rol_id === 5) {
            return redirect()->route('pyp-audits.edit', ['container' => $record->container_id] + $returnParams);
        }
        $name = DB::table('contenedores')->where('id', $record->container_id)->value('nombre');

        return redirect()->route('pyp-audits.index', ['nombre' => $name] + $returnParams);
    }

    private function reviewJob(Request $request, int $job): object
    {
        $this->context($request);
        abort_unless(in_array((int) $request->user()->rol_id, [1, 2, 3], true) && $request->user()->hasPermission('maintenance.view'), 403);

        return DB::table('maintenance_jobs as j')->join('contenedores as c', 'c.id', '=', 'j.container_id')
            ->join('users as u', 'u.id', '=', 'j.mechanic_id')->where('j.id', $job)
            ->where('j.branch_id', (int) session('sucursal_activa'))->whereExists(function ($query): void {
                $query->selectRaw('1')->from('maintenance_review_requests as r')->whereColumn('r.job_id', 'j.id');
            })->select('j.*', 'c.nombre as container_name', 'u.username as mechanic_name')->firstOrFail();
    }

    public function repair(Request $request, int $job): View
    {
        $record = $this->reviewJob($request, $job);
        $actions = app(MaintenanceCorrections::class)->reviewActions($request->user(), (int) session('sucursal_activa'), $record);

        return view('notifications.repair', $this->context($request) + $actions + ['job' => $record,
            'hasInterventions' => DB::table('maintenance_corrections')->where('job_id', $job)->where('status', '!=', 'draft')->exists(),
            'returnUrl' => $this->localReturnUrl($request) ?? route('notifications.index', ['category' => 'repairs']),
            'review' => DB::table('maintenance_review_requests')->where('job_id', $job)->latest('id')->first(),
            'confirmation' => DB::table('maintenance_events as e')->leftJoin('users as u', 'u.id', '=', 'e.user_id')
                ->where('e.job_id', $job)->where('e.action', 'repair_confirmed')->orderByDesc('e.id')
                ->select('e.created_at', 'u.username')->first(),
            'materials' => DB::table('maintenance_materials')->where('job_id', $job)->get(),
            'evidence' => DB::table('maintenance_evidence')->where('job_id', $job)->get()]);
    }

    public function detail(Request $request, int $notification, SystemNotifications $notifications): View
    {
        $request->validate(['folder' => ['nullable', Rule::in(['inbox', 'archived'])]]);
        $record = $notifications->inbox($request->user(), (int) session('sucursal_activa'), $request->input('folder') ?: 'inbox')
            ->where('n.id', $notification)->firstOrFail();
        if (str_starts_with($record->event_key, 'activity:')) {
            return view('notifications.activity', $this->context($request) + ['notification' => $record,
                'details' => json_decode($record->details, true, flags: JSON_THROW_ON_ERROR),
                'returnUrl' => $this->localReturnUrl($request) ?? route('notifications.index')]);
        }
        abort_unless(str_starts_with($record->event_key, 'container-status:') || str_starts_with($record->event_key, 'damaged-ingress:') || str_starts_with($record->event_key, 'container-ingress:'), 404);
        $isIngress = str_starts_with($record->event_key, 'damaged-ingress:');
        $isContainerIngress = str_starts_with($record->event_key, 'container-ingress:');
        $lines = preg_split('/\R/u', trim($record->body));
        $containerName = preg_match('/^[A-Z0-9]{11}$/', $lines[0] ?? '') ? array_shift($lines) : null;
        $actorName = null;
        $changes = [];
        $notes = [];
        // Existing notices store a text snapshot; retain any unrecognized content.
        foreach ($lines as $line) {
            if (preg_match('/^(Modificado|Registrado) por: (.*)$/u', $line, $actor)) {
                $actorName = $actor[2];

                continue;
            }
            if (preg_match('/^(Unidad|Estructura|IPSA): (.+)$/u', $line, $match)) {
                $values = explode(' → ', $match[2], 2);
                $changes[] = ['part' => $match[1], 'before' => count($values) === 2 ? $values[0] : null,
                    'after' => count($values) === 2 ? $values[1] : $values[0]];
            } elseif (trim($line) !== '') {
                $notes[] = $line;
            }
        }

        return view('notifications.detail', $this->context($request) + ['notification' => $record,
            'isIngress' => $isIngress, 'isContainerIngress' => $isContainerIngress, 'containerName' => $containerName, 'actorName' => $actorName, 'changes' => $changes, 'notes' => $notes,
            'returnUrl' => $this->localReturnUrl($request) ?? route('notifications.index')]);
    }

    public function confirm(Request $request, int $job, MaintenanceCorrections $corrections): RedirectResponse
    {
        $request->validate(['version' => ['required', 'integer']]);
        $corrections->confirm($request->user(), (int) session('sucursal_activa'), $job, $request->integer('version'));

        return redirect()->route('notifications.repair', array_filter(['job' => $job, 'step' => 2, 'return_to' => $this->localReturnUrl($request)]))
            ->with('status', 'Trabajo confirmado. Los estados técnicos y la auditoría externa no se modificaron automáticamente.');
    }

    public function evidence(Request $request, int $job, int $evidence): BinaryFileResponse
    {
        $this->reviewJob($request, $job);
        $photo = DB::table('maintenance_evidence')->where('job_id', $job)->where('id', $evidence)->first();
        abort_unless($photo && in_array($photo->mime, ['image/jpeg', 'image/png', 'image/webp'], true) && Storage::disk('local')->exists($photo->path), 404);

        return response()->file(Storage::disk('local')->path($photo->path), ['Content-Type' => $photo->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
