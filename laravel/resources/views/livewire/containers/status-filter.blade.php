<div class="containers-status-filter">
    <span id="{{ $filter }}-label">Filtrar estado</span>
    <div class="form-select-dropdown container-dropdown">
        <button type="button" class="form-select-trigger" data-container-dropdown-trigger aria-haspopup="listbox" aria-expanded="false" aria-labelledby="{{ $filter }}-label {{ $filter }}-value" aria-controls="{{ $filter }}-options">
            <span id="{{ $filter }}-value">{{ $selected ?: 'Todos' }}</span>
            <svg class="dropdown-caret" viewBox="0 0 20 20" aria-hidden="true"><path d="M5.75 7.5 10 11.75 14.25 7.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
        <div id="{{ $filter }}-options" class="form-select-menu container-dropdown-menu" role="listbox" aria-labelledby="{{ $filter }}-label">
            @foreach(array_merge([''], $options) as $option)
                <button type="button" class="topbar-dropdown-option @if($selected === $option) selected @endif" role="option" aria-selected="{{ $selected === $option ? 'true' : 'false' }}" wire:click="$set('{{ $filter }}', @js($option))">{{ $option ?: 'Todos' }}</button>
            @endforeach
        </div>
    </div>
</div>
