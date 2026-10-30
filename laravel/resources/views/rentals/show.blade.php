@extends('layouts.app')
@section('content')
    @php
        $money = fn($cents) => $stay->currency.' '.number_format($cents / 100, 2);
        $local = fn($time) => \Carbon\CarbonImmutable::parse($time, 'UTC')->setTimezone('America/Guatemala')->format('d/m/Y H:i');
    @endphp
    <div class="parking-heading"><div><span class="eyebrow">{{ $stay->exited_at ? 'Comprobante de liquidacion' : 'Estadia activa' }} · {{ $stay->location_name }}</span><h1>{{ $stay->plate }}</h1><span class="table-muted">Estadia #{{ str_pad($stay->id, 6, '0', STR_PAD_LEFT) }} · Espacio {{ $stay->space_code }}</span></div><div class="parking-actions print-hidden"><a class="button secondary" href="{{ route('rentals.index', ['location' => $stay->location_id]) }}">Volver</a>@if($stay->exited_at)<button class="button" type="button" data-parking-print><span data-parking-icon="printer"></span>Imprimir</button>@endif</div></div>
    <section class="parking-stay-layout">
        <div>
            <dl class="parking-detail-grid"><div><dt>Conductor</dt><dd>{{ $stay->driver }}</dd></div><div><dt>Cliente / empresa</dt><dd>{{ $stay->customer ?: 'No registrado' }}</dd></div><div><dt>Entrada</dt><dd>{{ $local($stay->entered_at) }}</dd></div><div><dt>Salida</dt><dd>{{ $stay->exited_at ? $local($stay->exited_at) : 'Pendiente de liquidacion' }}</dd></div><div><dt>Modalidad</dt><dd>{{ $stay->billing_unit === 'hour' ? 'Hora iniciada' : 'Dia iniciado (24 h)' }}</dd></div><div><dt>Tarifa de entrada</dt><dd>{{ $money($stay->rate_cents) }}</dd></div></dl>
            @if($stay->notes)<p class="parking-notes">{{ $stay->notes }}</p>@endif
            <h2>Historial</h2>
            <ol class="parking-timeline">@foreach($events as $event)<li><strong>{{ $event->action }}</strong><span>{{ $local($event->created_at) }} · Usuario #{{ $event->user_id }}</span></li>@endforeach</ol>
        </div>
        <div class="parking-settlement">
            <span class="eyebrow">{{ $stay->exited_at ? 'Total liquidado' : 'Liquidacion estimada' }}</span>
            <strong class="parking-total">{{ $money($stay->exited_at ? $stay->total_cents : $quote['total_cents']) }}</strong>
            <dl><div><dt>Permanencia</dt><dd>{{ intdiv($quote['seconds'], 3600) }} h {{ intdiv($quote['seconds'] % 3600, 60) }} min</dd></div><div><dt>Periodos cobrables</dt><dd>{{ $stay->exited_at ? $stay->billable_units : $quote['units'] }} {{ $stay->billing_unit === 'hour' ? 'hora(s)' : 'dia(s)' }}</dd></div></dl>
            @if($stay->exited_at)
                <span class="parking-state available">Liquidada · Espacio liberado</span><p>{{ $stay->payment_method === 'cash' ? 'Efectivo' : 'Transferencia' }} @if($stay->payment_reference)· {{ $stay->payment_reference }}@endif</p><small>Constancia operativa de cobro. No sustituye una factura fiscal.</small>
            @else
                <form method="POST" action="{{ route('rentals.settle', $stay->id) }}" class="parking-form" data-parking-settle>@csrf
                    <input type="hidden" name="expected_total_cents" value="{{ $quote['total_cents'] }}">
                    <label>Medio de pago<select name="payment_method" data-payment-method><option value="cash">Efectivo</option><option value="transfer">Transferencia</option></select></label>
                    <label>Referencia<input name="payment_reference" maxlength="100" value="{{ old('payment_reference') }}" data-payment-reference></label>
                    <label class="parking-check"><input type="checkbox" name="payment_confirmed" value="1" required> Confirmo que el pago fue recibido</label>
                    <button type="submit" class="button">Liquidar y registrar salida</button>
                    <a class="parking-refresh" href="{{ route('rentals.show', $stay->id) }}">Actualizar calculo</a>
                </form>
            @endif
        </div>
    </section>
    <dialog class="parking-dialog" data-settlement-dialog aria-labelledby="settlement-title"><span class="eyebrow">Confirmar liquidacion</span><h2 id="settlement-title">Registrar salida de {{ $stay->plate }}?</h2><p>Se registrara el pago de {{ $money($quote['total_cents']) }} y se liberara el espacio {{ $stay->space_code }}.</p><div class="parking-actions"><button type="button" class="button secondary" data-dialog-close>Volver</button><button type="button" class="button" data-settlement-confirm>Confirmar salida</button></div></dialog>
@endsection
