<?php

namespace App\Livewire\Notifications;

use App\Livewire\Shell\NotificationBell;
use App\Services\AccountRecovery;
use App\Services\SystemNotifications;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Inbox extends Component
{
    use WithPagination;

    #[Url(history: true)]
    public string $category = '';

    #[Url(history: true)]
    public string $status = '';

    #[Url(history: true)]
    public string $folder = 'inbox';

    #[Url]
    public string $search = '';

    public array $selected = [];

    public bool $allSelected = false;

    public array $excluded = [];

    #[Locked]
    public int $selectionMaxId = 0;

    #[Locked]
    public string $selectionScope = '';

    #[Locked]
    public int $notificationTotal = 0;

    #[Locked]
    public int $selectionTotal = 0;

    public string $feedback = '';

    #[Locked]
    public ?int $recoveryRequestId = null;

    public bool $recoveryModalOpen = false;

    public function mount(): void
    {
        if (request()->integer('recovery_notification') > 0) {
            $this->openRecovery(request()->integer('recovery_notification'));
        }
    }

    public function openRecovery(int $notification): void
    {
        abort_unless((int) auth()->user()->rol_id === 1 && app(AccountRecovery::class)->enabled(), 403);
        $query = app(SystemNotifications::class)->inbox(auth()->user(), (int) session('sucursal_activa'), $this->folder)
            ->where('n.id', $notification)->where('n.category', 'account-recovery');
        $notice = (clone $query)->first();
        abort_unless($notice && preg_match('/^account-recovery:(\d+)$/', $notice->event_key, $matches), 404);
        abort_unless(DB::table('account_recovery_requests')->where('id', $matches[1])->exists(), 404);
        $this->recoveryRequestId = (int) $matches[1];
        $this->recoveryModalOpen = true;
        $query->whereNull('n.read_at')->update(['read_at' => now()]);
        $this->dispatch('notifications-changed')->to(NotificationBell::class);
    }

    public function closeRecovery(): void
    {
        $this->recoveryModalOpen = false;
    }

    public function reviewRecovery(string $decision): void
    {
        abort_unless($this->recoveryModalOpen && $this->recoveryRequestId !== null, 404);
        abort_unless(app(AccountRecovery::class)->enabled(), 403);
        app(AccountRecovery::class)->review($this->recoveryRequestId, auth()->user(), $decision);
        $this->recoveryModalOpen = false;
        $this->dispatch('notifications-changed');
        $this->dispatch('account-review-feedback', message: $decision === 'approved' ? 'Solicitud aprobada. El envío de la clave quedó en cola.' : 'Solicitud rechazada.');
    }

    private function recoveryRequest(): ?object
    {
        if ($this->recoveryRequestId === null) {
            return null;
        }
        abort_unless((int) auth()->user()->rol_id === 1 && (int) auth()->user()->estado_id === 1, 403);

        return DB::table('account_recovery_requests as r')->join('users as u', 'u.id', '=', 'r.user_id')
            ->leftJoin('users as reviewer', 'reviewer.id', '=', 'r.reviewed_by')
            ->where('r.id', $this->recoveryRequestId)
            ->select('r.*', 'u.username', 'u.email', 'reviewer.username as reviewer')->first();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['category', 'status', 'folder', 'search'], true)) {
            $this->resetPage();
            $this->clearSelection();
            $this->feedback = '';
        }
    }

    #[On('notifications-changed')]
    public function refreshNotifications(): void {}

    private function query(): Builder
    {
        $service = app(SystemNotifications::class);
        Validator::make(['category' => $this->category, 'status' => $this->status, 'folder' => $this->folder, 'search' => $this->search], [
            'category' => ['nullable', Rule::in(array_keys($service->categories(auth()->user())))],
            'status' => ['nullable', Rule::in(['read', 'unread'])],
            'folder' => ['required', Rule::in(['inbox', 'archived'])],
            'search' => ['string', 'max:100'],
        ])->validate();
        $query = $service->inbox(auth()->user(), (int) session('sucursal_activa'), $this->folder);
        if ($this->category !== '') {
            $query->where('n.category', $this->category);
        }
        if ($this->status !== '') {
            $this->status === 'unread' ? $query->whereNull('n.read_at') : $query->whereNotNull('n.read_at');
        }
        if (trim($this->search) !== '') {
            $query->where(function (Builder $search): void {
                $search->where('n.title', 'like', '%'.trim($this->search).'%')->orWhere('n.body', 'like', '%'.trim($this->search).'%');
            });
        }

        return $query;
    }

    private function scopeKey(): string
    {
        return hash('sha256', json_encode([auth()->id(), session('sucursal_activa'), $this->folder, $this->category, $this->status, trim($this->search)], JSON_THROW_ON_ERROR));
    }

    public function clearSelection(): void
    {
        $this->selected = $this->excluded = [];
        $this->allSelected = false;
        $this->selectionMaxId = 0;
        $this->selectionScope = '';
    }

    public function toggleAll(bool $force = false): void
    {
        $query = $this->query();
        if (! $force && $this->allSelected && $this->excluded === []) {
            $this->clearSelection();

            return;
        }
        $this->clearSelection();
        $this->selectionMaxId = (int) $query->max('n.id');
        $this->selectionScope = $this->scopeKey();
        $this->allSelected = $this->selectionMaxId > 0;
    }

    public function toggleNotification(int $id): void
    {
        $query = $this->query()->where('n.id', $id);
        if ($this->allSelected) {
            abort_unless($this->selectionScope === $this->scopeKey(), 422);
        }
        abort_unless($query->exists(), 404);
        $property = $this->allSelected && $id <= $this->selectionMaxId ? 'excluded' : 'selected';
        $ids = array_map('strval', $this->{$property});
        $this->{$property} = in_array((string) $id, $ids, true)
            ? array_values(array_diff($ids, [(string) $id])) : [...$ids, (string) $id];
    }

    private function selectedQuery(): Builder
    {
        Validator::make(['selected' => $this->selected, 'excluded' => $this->excluded], [
            'selected' => ['array', 'max:500'], 'selected.*' => ['integer', 'min:1', 'distinct'],
            'excluded' => ['array', 'max:500'], 'excluded.*' => ['integer', 'min:1', 'distinct'],
        ])->validate();
        $query = $this->query();
        if ($this->allSelected) {
            abort_unless($this->selectionMaxId > 0 && $this->selectionScope === $this->scopeKey(), 422);

            return $query->where(function (Builder $selection): void {
                $selection->where(function (Builder $snapshot): void {
                    $snapshot->where('n.id', '<=', $this->selectionMaxId)->whereNotIn('n.id', $this->excluded);
                })->orWhereIn('n.id', $this->selected);
            });
        }

        return $query->whereIn('n.id', $this->selected);
    }

    public function clearFilters(): void
    {
        $this->search = $this->status = '';
        $this->clearSelection();
        $this->resetPage();
    }

    public function act(string $action): void
    {
        $query = $this->selectedQuery();
        abort_unless((clone $query)->exists(), 422);
        DB::transaction(function () use ($query, $action): void {
            $query->select('n.id')->chunkById(100, function ($notifications) use ($action): void {
                app(SystemNotifications::class)->act(auth()->user(), (int) session('sucursal_activa'), $action, $notifications->pluck('id')->all(), $this->folder);
            }, 'n.id', 'id');
        });
        $this->clearSelection();
        $this->resetPage();
        $this->feedback = match ($action) {
            'read' => 'Marcadas como leídas.',
            'archive' => 'Notificaciones archivadas.', 'unarchive' => 'Notificaciones movidas a la entrada.',
        };
        $this->dispatch('notifications-changed');
        $this->dispatch('notification-feedback');
    }

    public function readAll(): void
    {
        app(SystemNotifications::class)->inbox(auth()->user(), (int) session('sucursal_activa'))->whereNull('n.read_at')->update(['read_at' => now()]);
        $this->feedback = 'Entrada marcada como leída.';
        $this->clearSelection();
        $this->dispatch('notifications-changed');
    }

    public function render(): View
    {
        $query = $this->query();
        if ($this->allSelected && $this->selectionScope !== $this->scopeKey()) {
            $this->clearSelection();
        }
        $service = app(SystemNotifications::class);
        $base = $service->inbox(auth()->user(), (int) session('sucursal_activa'), $this->folder);
        $unread = $service->inbox(auth()->user(), (int) session('sucursal_activa'))->whereNull('n.read_at')->count();
        $counts = $base->whereNull('n.read_at')->select('n.category')->selectRaw('COUNT(*) as total')->groupBy('n.category')->pluck('total', 'category');
        $this->notificationTotal = (clone $query)->count();
        if (! $this->allSelected && ($this->selectionScope !== $this->scopeKey() || $this->selectionMaxId === 0)) {
            $this->selectionMaxId = (int) (clone $query)->max('n.id');
            $this->selectionScope = $this->scopeKey();
        }
        $this->selectionTotal = (clone $query)->where('n.id', '<=', $this->selectionMaxId)->count();
        $lastPage = max(1, (int) ceil($this->notificationTotal / 15));
        if ($this->getPage() > $lastPage) {
            $this->setPage($lastPage);
        }
        $notifications = $query->orderByDesc('n.created_at')->orderByDesc('n.id')->paginate(15);
        if (! $this->allSelected) {
            $this->selected = $this->selectedQuery()->pluck('n.id')->map(fn ($id): string => (string) $id)->all();
        }
        $selectedCount = $this->allSelected ? $this->selectedQuery()->count() : count($this->selected);
        $selectedOnPage = $notifications->filter(fn ($notification): bool => $this->allSelected
            ? ((int) $notification->id <= $this->selectionMaxId && ! in_array((string) $notification->id, array_map('strval', $this->excluded), true))
                || in_array((string) $notification->id, array_map('strval', $this->selected), true)
            : in_array((string) $notification->id, $this->selected, true))->pluck('id')->map(fn ($id): string => (string) $id)->all();

        return view('livewire.notifications.inbox', ['categories' => $service->categories(auth()->user()),
            'counts' => $counts, 'unread' => $unread, 'notifications' => $notifications, 'recoveryRequest' => $this->recoveryRequest(),
            'previews' => $notifications->getCollection()->mapWithKeys(fn ($notification): array => [
                $notification->id => $service->bellPreview($notification),
            ]),
            'selectedCount' => $selectedCount, 'selectedOnPage' => $selectedOnPage]);
    }
}
