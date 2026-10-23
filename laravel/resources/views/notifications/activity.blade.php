@extends('layouts.app')
@section('content')
<section class="page-heading notification-event-heading"><div><span class="eyebrow">Notificación · {{ $details['module'] }}</span><h1>{{ $details['subject'] }}</h1><p>{{ $details['action'] }}</p></div><a class="mw-button" href="{{ $returnUrl }}"><span data-notification-icon="chevron-left" aria-hidden="true"></span>Volver</a></section>
<section class="workboard notification-event">
    <dl class="notification-event-meta"><div><dt>Realizado por</dt><dd>{{ $details['actor'] }}</dd></div><div><dt>Sucursal</dt><dd>{{ $sucursal->nombre }}</dd></div><div><dt>Fecha y hora</dt><dd>{{ \Carbon\Carbon::parse($notification->created_at)->timezone('America/Guatemala')->format('d/m/Y · H:i') }}</dd></div></dl>
    <div class="notification-event-section-head"><h2>{{ $details['action'] }}</h2></div>
    <div class="notification-event-changes" role="table" aria-label="Detalle de la acción">
        <div class="notification-event-change-head" role="row"><span role="columnheader">Dato</span><span role="columnheader">Antes</span><span class="mw-sr-only" role="columnheader">Cambio</span><span role="columnheader">Después</span></div>
        @foreach($details['changes'] as $change)<div class="notification-event-change" role="row"><strong role="cell">{{ $change['field'] }}</strong><div role="cell"><small>Antes</small><span class="notification-event-before">{{ $change['before'] ?? '—' }}</span></div><span class="notification-event-direction" role="cell" aria-label="cambió a"><span data-notification-icon="chevron-right" aria-hidden="true"></span></span><div role="cell"><small>Después</small><strong>{{ $change['after'] ?? '—' }}</strong></div></div>@endforeach
    </div>
</section>
@endsection
