@extends('layouts.app')
@section('content')
<section class="page-heading notification-event-heading">
    <div><span class="eyebrow">Notificación · {{ $isIngress ? 'Ingreso con daños' : 'Contenedores' }}</span><h1>{{ $containerName ?? $notification->title }}</h1>@if($containerName)<p>{{ $notification->title }}</p>@endif</div>
    <a class="mw-button" href="{{ $returnUrl }}"><span data-notification-icon="chevron-left" aria-hidden="true"></span>Volver</a>
</section>
<section class="workboard notification-event">
    <dl class="notification-event-meta">
        @if($actorName)<div><dt>{{ $isContainerIngress ? 'Registrado por' : 'Modificado por' }}</dt><dd>{{ $actorName }}</dd></div>@endif
        <div><dt>Sucursal</dt><dd>{{ $sucursal->nombre }}</dd></div>
        <div><dt>Fecha y hora</dt><dd><time datetime="{{ \Carbon\Carbon::parse($notification->created_at)->toIso8601String() }}">{{ \Carbon\Carbon::parse($notification->created_at)->timezone('America/Guatemala')->format('d/m/Y · H:i') }}</time></dd></div>
    </dl>
    <div class="notification-event-section-head"><h2>{{ $isIngress ? 'Daños reportados al ingreso' : ($isContainerIngress ? 'Estados al ingresar' : 'Estados actualizados') }}</h2>@if(count($changes))<span>{{ count($changes) }} {{ count($changes) === 1 ? 'registro' : 'registros' }}</span>@endif</div>
    @if(count($changes))
        @if($isIngress || $isContainerIngress)
            <dl class="notification-event-damage">@foreach($changes as $change)<div><dt>{{ $change['part'] }}</dt><dd>{{ $change['after'] }}</dd></div>@endforeach</dl>
        @else
            <div class="notification-event-changes" role="table" aria-label="Comparación de estados">
                <div class="notification-event-change-head" role="row"><span role="columnheader">Parte del equipo</span><span role="columnheader">Antes</span><span class="mw-sr-only" role="columnheader">Cambio</span><span role="columnheader">Después</span></div>
                @foreach($changes as $change)
                    <div class="notification-event-change" role="row">
                        <strong role="cell">{{ $change['part'] }}</strong>
                        <div role="cell"><small aria-hidden="true">Antes</small><span class="notification-event-before">{{ $change['before'] ?? 'Sin estado registrado' }}</span></div>
                        <span class="notification-event-direction" role="cell" aria-label="cambió a"><span data-notification-icon="chevron-right" aria-hidden="true"></span></span>
                        <div role="cell"><small aria-hidden="true">Después</small><strong class="notification-event-after">{{ $change['after'] }}</strong></div>
                    </div>
                @endforeach
            </div>
        @endif
    @endif
    @if(count($notes))<div class="notification-event-notes"><p>{{ implode("\n", $notes) }}</p></div>@endif
    @if($isIngress)<footer class="notification-event-footer"><a class="mw-button" href="{{ route('maintenance.container', ['container' => $notification->container_id]) }}">Ver contenedor<span data-notification-icon="chevron-right" aria-hidden="true"></span></a></footer>@endif
    @if($isContainerIngress)<footer class="notification-event-footer"><a class="mw-button" href="{{ route('containers.show', ['container' => $notification->container_id]) }}">Ver contenedor<span data-notification-icon="chevron-right" aria-hidden="true"></span></a></footer>@endif
</section>
@endsection
