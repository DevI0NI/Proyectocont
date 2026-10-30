@props(['property', 'label', 'value', 'options'])
<div class="notification-filter form-select-dropdown" data-notification-filter wire:key="notification-filter-{{ $property }}">
    <span id="notification-{{ $property }}-label" class="notification-filter-label">{{ $label }}</span>
    <button type="button" class="form-select-trigger notification-filter-trigger" data-notification-toggle wire:ignore.self aria-haspopup="listbox" aria-expanded="false" aria-controls="notification-{{ $property }}-options" aria-labelledby="notification-{{ $property }}-label notification-{{ $property }}-value">
        <span id="notification-{{ $property }}-value">{{ $options[$value] ?? '' }}</span><span data-notification-icon="chevron-down" wire:ignore aria-hidden="true"></span>
    </button>
    <div class="form-select-menu notification-filter-menu" id="notification-{{ $property }}-options" data-notification-popup wire:ignore.self role="listbox" aria-labelledby="notification-{{ $property }}-label" hidden>
        @foreach($options as $key => $text)<button type="button" class="topbar-dropdown-option @if((string)$key === $value) selected @endif" role="option" tabindex="-1" aria-selected="{{ (string)$key === $value ? 'true' : 'false' }}" data-notification-filter-option wire:click="$set('{{ $property }}', '{{ $key }}')">{{ $text }}</button>@endforeach
    </div>
</div>
