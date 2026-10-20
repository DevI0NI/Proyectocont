<div class="container-form-timeline mw-timeline" role="tablist" aria-label="Etapas de la reparación">
@foreach(['Evaluación inicial', 'Reparación', 'Resultado'] as $tab)
    <button type="button" class="timeline-step {{ $loop->index === $initialStep ? 'active' : '' }} {{ $loop->index < $initialStep ? 'completed' : '' }}" role="tab" id="mw-tab-{{ $loop->index }}" aria-controls="mw-panel-{{ $loop->index }}" aria-selected="{{ $loop->index === $initialStep ? 'true' : 'false' }}" tabindex="{{ $loop->index === $initialStep ? '0' : '-1' }}" data-maintenance-tab="{{ $loop->index }}"><span>{{ sprintf('%02d', $loop->iteration) }}</span><strong>{{ $tab }}</strong></button>
@endforeach
</div>
