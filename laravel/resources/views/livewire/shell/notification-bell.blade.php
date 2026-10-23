<div class="notification-bell" wire:poll.5s data-notification-surface data-notification-bell x-data="{ reading: '' }">
    <button class="notification-bell-trigger" wire:ignore.self data-notification-toggle type="button" aria-expanded="false" aria-controls="notificationDropdown" aria-label="Notificaciones" title="Notificaciones">
        <span data-catalog-icon="bell" wire:ignore aria-hidden="true"></span>
        @if($unread)<span class="notification-badge">{{ $unread > 99 ? '99+' : $unread }}</span>@endif
    </button>
    <section class="notification-dropdown" id="notificationDropdown" aria-label="Notificaciones recientes" data-notification-popup wire:ignore.self hidden>
        <header class="notification-bell-heading">
            <div class="notification-bell-title"><h2>Notificaciones</h2><span class="notification-bell-unread">{{ $unread }} sin leer</span></div>
            <span class="notification-bell-limit">Últimos 10 avisos</span>
            <div class="notification-bell-toolbar">
            <nav class="notification-bell-filters" aria-label="Lectura de los avisos recientes">
                <button type="button" @click="reading = ''" :aria-pressed="reading === ''">Todas<span>{{ $notifications->count() }}</span></button>
                <button type="button" @click="reading = 'unread'" :aria-pressed="reading === 'unread'">Sin leer<span>{{ $notifications->whereNull('read_at')->count() }}</span></button>
                <button type="button" @click="reading = 'read'" :aria-pressed="reading === 'read'">Leídas<span>{{ $notifications->whereNotNull('read_at')->count() }}</span></button>
            </nav>
            @if($notifications->isNotEmpty())
                <div class="notification-bell-commands">
                    <x-notification-action icon="list-x" label="Limpiar campanita" :show-label="true" wire:click="clearBell" wire:loading.attr="disabled" wire:target="clearBell,readAll" />
                    @if($unread)<x-notification-action icon="check-check" label="Marcar todo como leído" :show-label="true" wire:click="readAll" wire:loading.attr="disabled" wire:target="clearBell,readAll" />@endif
                </div>
            @endif
            </div>
        </header>
        <div class="notification-recent-list">
            @forelse($notifications as $notification)
                @php
                    $preview = $previews[$notification->id];
                    $date = \Carbon\Carbon::parse($notification->created_at, 'UTC')->timezone('America/Guatemala');
                @endphp
                <article class="notification-recent-item @if(!$notification->read_at) is-unread @endif" wire:key="notification-{{ $notification->id }}" x-show="reading === '' || reading === '{{ $notification->read_at ? 'read' : 'unread' }}'">
                <form method="POST" data-notification-open action="{{ route('notifications.open', $notification->id) }}">@csrf<input type="hidden" name="return_to" value="">
                    <button class="notification-recent @if(!$notification->read_at) is-unread @endif" type="submit">
                        <span class="notification-recent-copy">
                            <span class="notification-recent-title"><span class="notification-recent-kind">{{ $categories[$notification->category] ?? 'Sistema' }}</span><strong>{{ $notification->title }}</strong></span>
                            <span class="notification-recent-description">{{ $preview['description'] }}</span>
                            <span class="notification-recent-metadata"><span>{{ $preview['actor'] ? 'Por: '.$preview['actor'] : 'Autor no registrado' }}</span>@if(!$notification->read_at)<span class="notification-recent-unread">Sin leer</span>@endif<time datetime="{{ $date->toIso8601String() }}" title="{{ $date->diffForHumans() }}">{{ $date->format('d/m/Y · H:i') }}</time></span>
                        </span>
                    </button>
                </form>
                <div class="notification-recent-actions">
                    @if(!$notification->read_at)<x-notification-action icon="mail-open" label="Marcar como leída" wire:click="act('read', {{ $notification->id }})" wire:loading.attr="disabled" />@endif
                    <x-notification-action icon="archive" label="Archivar notificación" wire:click="act('archive', {{ $notification->id }})" wire:loading.attr="disabled" />
                </div>
                </article>
            @empty
                <p class="notification-empty-small">No hay avisos en la campanita. Puedes consultar los anteriores en el panel de notificaciones.</p>
            @endforelse
            @if($notifications->isNotEmpty())<p class="notification-empty-small" x-cloak x-show="(reading === 'unread' && {{ $notifications->whereNull('read_at')->count() }} === 0) || (reading === 'read' && {{ $notifications->whereNotNull('read_at')->count() }} === 0)">No hay avisos <span x-text="reading === 'unread' ? 'sin leer' : 'leídos'"></span> entre los últimos 10.</p>@endif
        </div>
        <a class="notification-all" href="{{ route('notifications.index') }}">Ver todas las notificaciones<span data-catalog-icon="arrow-right" wire:ignore aria-hidden="true"></span></a>
    </section>
</div>
