<?php

namespace App\Livewire\Shell;

use App\Services\SystemNotifications;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class NotificationBell extends Component
{
    #[On('notifications-changed')]
    public function refreshNotifications(): void {}

    public function act(string $action, int $notification): void
    {
        abort_unless(in_array($action, ['read', 'archive'], true), 403);
        app(SystemNotifications::class)->act(auth()->user(), (int) session('sucursal_activa'), $action, [$notification]);
        $this->dispatch('notifications-changed');
    }

    public function readAll(): void
    {
        app(SystemNotifications::class)->inbox(auth()->user(), (int) session('sucursal_activa'))->whereNull('n.read_at')->update(['read_at' => now()]);
        $this->dispatch('notifications-changed');
    }

    public function clearBell(): void
    {
        $query = app(SystemNotifications::class)->inbox(auth()->user(), (int) session('sucursal_activa'))
            ->whereNull('n.bell_dismissed_at');
        $maxId = (int) (clone $query)->max('n.id');
        $query->where('n.id', '<=', $maxId)->update(['bell_dismissed_at' => now()]);
        $this->dispatch('notifications-changed');
    }

    public function render(): View
    {
        $query = app(SystemNotifications::class)->inbox(auth()->user(), (int) session('sucursal_activa'))
            ->whereNull('n.bell_dismissed_at');

        $unread = (clone $query)->whereNull('n.read_at')->count();
        $notifications = $query->orderByDesc('n.created_at')->orderByDesc('n.id')->limit(10)->get();

        return view('livewire.shell.notification-bell', [
            'categories' => app(SystemNotifications::class)->categories(auth()->user()),
            'unread' => $unread,
            'notifications' => $notifications,
            'previews' => $notifications->mapWithKeys(fn ($notification): array => [
                $notification->id => app(SystemNotifications::class)->bellPreview($notification),
            ]),
        ]);
    }
}
