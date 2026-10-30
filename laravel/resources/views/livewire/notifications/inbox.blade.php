<div wire:poll.60s data-notification-surface x-data="proportsaNotificationSelection($wire)">
<section class="page-heading notification-heading">
    <div><span class="eyebrow">{{ \App\Models\Sucursal::find(session('sucursal_activa'))?->nombre }}</span><h1>Notificaciones</h1></div>
    <div class="notification-unread-total"><strong>{{ $unread }}</strong><span>sin leer</span></div>
</section>
<section class="workboard notification-board">
    <nav class="notification-tabs" aria-label="Apartados de notificaciones">
        <button type="button" wire:click="$set('category', '')" @if($category === '') aria-current="page" @endif>Todas<span>{{ $counts->sum() }}</span></button>
        @foreach(['repairs' => 'Reparaciones', 'audits' => 'Auditorías', 'account-recovery' => 'Recuperación de contraseñas'] as $key => $label)
            @if(isset($categories[$key]))<button type="button" wire:click="$set('category', '{{ $key }}')" @if($category === $key) aria-current="page" @endif>{{ $label }}<span>{{ $counts[$key] ?? 0 }}</span></button>@endif
        @endforeach
    </nav>
    <form class="notification-filters" wire:submit.prevent="$refresh">
        <label class="notification-search"><span class="notification-filter-label">Buscar</span><span class="notification-search-input"><span data-notification-icon="search" wire:ignore aria-hidden="true"></span><input type="search" wire:model.live.debounce.300ms="search" maxlength="100" placeholder="Contenedor o notificación">@if($search !== '' || $status !== '')<x-notification-action icon="x" label="Limpiar filtros" wire:click="clearFilters" />@endif</span></label>
        <x-notification-filter property="status" label="Lectura" :value="$status" :options="['' => 'Todas', 'unread' => 'Sin leer', 'read' => 'Leídas']" />
        <x-notification-filter property="folder" label="Carpeta" :value="$folder" :options="['inbox' => 'Entrada', 'archived' => 'Archivados']" />
    </form>
    <div class="notification-selection-bar">
        <label class="notification-select-page"><input class="notification-checkbox" type="checkbox" wire:ignore @change="toggleAll()" :checked="count > 0 && count === total" :disabled="total === 0" :indeterminate="count > 0 && count < total" wire:loading.attr="disabled" wire:target="act"><span>Seleccionar todo</span></label>
        <span class="notification-selection-count" x-show="count > 0" x-text="count + ' seleccionadas'" x-cloak wire:ignore></span>
        <div class="notification-selection-actions" aria-label="Acciones sobre la selección" x-show="count > 0" x-cloak wire:ignore.self>
            <x-notification-action icon="mail-open" label="Marcar selección como leída" wire:click="act('read')" wire:loading.attr="disabled" wire:target="act" />
            @if($folder === 'inbox')<x-notification-action icon="archive" label="Archivar selección" wire:click="act('archive')" wire:loading.attr="disabled" wire:target="act" />@else<x-notification-action icon="archive-restore" label="Devolver selección a Entrada" wire:click="act('unarchive')" wire:loading.attr="disabled" wire:target="act" />@endif
            <x-notification-action icon="x" label="Quitar selección" @click="clear()" />
        </div>
        <span class="notification-feedback" role="status" x-data="{ shown: false, timer: null }" x-show="shown" x-cloak wire:ignore.self @notification-feedback.window="shown = true; clearTimeout(timer); timer = setTimeout(() => shown = false, 3500)">{{ $feedback }}</span>
        <span class="notification-result-count">{{ $notifications->total() }} {{ $notifications->total() === 1 ? 'notificación' : 'notificaciones' }}</span>
    </div>
    <div class="notification-list">
        @forelse($notifications as $notification)
        @php($preview = $previews[$notification->id])
        <article class="notification-row @if(!$notification->read_at) is-unread @endif" :class="{ 'is-selected': isSelected({{ $notification->id }}) }" wire:key="notification-row-{{ $folder }}-{{ $notification->id }}">
            <input class="notification-checkbox" type="checkbox" wire:ignore @change="toggle({{ $notification->id }})" :checked="isSelected({{ $notification->id }})" wire:loading.attr="disabled" wire:target="act" aria-label="Seleccionar {{ $notification->title }}">
            <span class="notification-category-icon" data-notification-icon="{{ match($notification->category) { 'repairs' => 'wrench', 'audits' => 'shield-check', default => 'inbox' } }}" wire:ignore aria-hidden="true"></span>
            <form class="notification-open" @if($notification->category === 'account-recovery') @submit.prevent="$dispatch('account-review-open', { id: {{ $notification->id }} })" @else method="POST" data-notification-open action="{{ route('notifications.open', $notification->id) }}" @endif>@csrf<input type="hidden" name="return_to" value=""><input type="hidden" name="folder" value="{{ $folder }}">
                <button type="submit"><span class="notification-row-meta">{{ $categories[$notification->category] }}<span>{{ $notification->read_at ? 'Leída' : 'Sin leer' }}</span></span><strong>{{ $notification->title }}</strong><span class="notification-body">{{ $preview['description'] }}</span><span class="notification-author">{{ $preview['actor'] ? 'Por: '.$preview['actor'] : 'Autor no registrado' }}</span></button>
            </form>
            <div class="notification-row-end"><time datetime="{{ \Carbon\Carbon::parse($notification->created_at, 'UTC')->toIso8601String() }}">{{ \Carbon\Carbon::parse($notification->created_at, 'UTC')->timezone('America/Guatemala')->format('d/m/Y H:i') }}</time>@if($folder === 'archived')<small>Vence {{ \Carbon\Carbon::parse($notification->expires_at, 'UTC')->timezone('America/Guatemala')->format('d/m/Y') }}</small>@endif</div>
        </article>
        @empty
            <div class="notification-empty"><span data-notification-icon="inbox" wire:ignore aria-hidden="true"></span><h2>{{ $search !== '' || $status !== '' ? 'Sin coincidencias' : 'No hay notificaciones' }}</h2><p>{{ $search !== '' || $status !== '' ? 'No hay notificaciones con estos filtros.' : 'Este apartado no tiene notificaciones.' }}</p></div>
        @endforelse
    </div>
    <footer class="notification-pagination">
        <span>Página {{ $notifications->currentPage() }} de {{ $notifications->lastPage() }} · {{ $notifications->total() }} {{ $notifications->total() === 1 ? 'notificación' : 'notificaciones' }}</span>
        <nav aria-label="Páginas de notificaciones">
            <x-notification-action icon="chevrons-left" label="Primera página" wire:click="gotoPage(1)" :disabled="$notifications->onFirstPage()" />
            <x-notification-action icon="chevron-left" label="Página anterior" wire:click="previousPage" :disabled="$notifications->onFirstPage()" />
            <x-notification-action icon="chevron-right" label="Página siguiente" wire:click="nextPage" :disabled="!$notifications->hasMorePages()" />
            <x-notification-action icon="chevrons-right" label="Última página" wire:click="gotoPage({{ $notifications->lastPage() }})" :disabled="!$notifications->hasMorePages()" />
        </nav>
    </footer>
</section>
@if(isset($categories['account-recovery']))
    <dialog class="account-review-dialog" wire:ignore.self x-data="proportsaAccountReview($wire)" x-effect="sync($wire.recoveryModalOpen)" @account-review-open.window="open($event.detail.id)" @cancel.prevent="close()" @pointerdown="outsideStarted = isOutside($event)" @click="if (outsideStarted && isOutside($event)) close(); outsideStarted = false" aria-labelledby="accountReviewTitle" :aria-busy="loading">
        <header><div class="account-review-heading"><span class="account-review-icon" data-notification-icon="shield-check" wire:ignore aria-hidden="true"></span><div><span class="eyebrow">Recuperación de contraseña</span><h2 id="accountReviewTitle">Solicitud de acceso</h2></div></div><button type="button" @click="close()" aria-label="Cerrar" title="Cerrar"><span data-notification-icon="x" wire:ignore></span></button></header>
        <p class="account-review-loading" x-show="loading" x-cloak role="status">Cargando solicitud…</p>
        <p class="account-review-failure" x-show="failure" x-text="failure" x-cloak role="alert"></p>
        <div x-show="!loading && !failure" x-cloak>
        @if($recoveryRequest)
        <div class="account-review-summary"><time datetime="{{ \Carbon\Carbon::parse($recoveryRequest->created_at, 'UTC')->toIso8601String() }}">{{ \Carbon\Carbon::parse($recoveryRequest->created_at, 'UTC')->timezone('America/Guatemala')->format('d/m/Y · H:i') }}</time><span class="account-review-status" data-state="{{ $recoveryRequest->status }}">{{ ['pending' => 'Pendiente', 'approved' => 'Aprobada', 'rejected' => 'Rechazada', 'obsolete' => 'Sin vigencia'][$recoveryRequest->status] ?? $recoveryRequest->status }}</span></div>
        <dl><div><dt>Usuario</dt><dd>{{ $recoveryRequest->username }}</dd></div><div><dt>Correo registrado</dt><dd>{{ $recoveryRequest->email }}</dd></div></dl>
        <section class="account-review-details"><h3>Motivo de la solicitud</h3><p class="account-review-reason">{{ $recoveryRequest->reason }}</p></section>
        @if($recoveryRequest->status === 'pending')
            <footer class="container-exit-dialog-actions"><button class="button container-exit-leave" type="button" wire:click="reviewRecovery('rejected')" wire:loading.attr="disabled" wire:target="reviewRecovery"><span data-notification-icon="x" wire:ignore aria-hidden="true"></span>Rechazar</button><button class="button container-exit-stay" type="button" wire:click="reviewRecovery('approved')" wire:loading.attr="disabled" wire:target="reviewRecovery"><span data-notification-icon="check-check" wire:ignore aria-hidden="true"></span>Aprobar solicitud</button></footer>
        @else
            <div class="account-review-resolution"><span data-notification-icon="{{ $recoveryRequest->status === 'approved' ? 'check-check' : 'inbox' }}" wire:ignore aria-hidden="true"></span><div><span>Revisada por <strong>{{ $recoveryRequest->reviewer ?? 'Sistema' }}</strong></span><time>{{ \Carbon\Carbon::parse($recoveryRequest->updated_at, 'UTC')->timezone('America/Guatemala')->format('d/m/Y · H:i') }}</time></div></div>
        @endif
        @endif
        </div>
    </dialog>
@endif
</div>
