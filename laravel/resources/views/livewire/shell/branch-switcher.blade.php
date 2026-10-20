<div class="topbar-sucursal-form livewire-branch-switcher" id="branchSwitcher">
    <span class="topbar-sucursal-label">Sucursal</span>

    <div class="topbar-dropdown branch-dropdown" id="branchDropdown">
        <button type="button" class="topbar-dropdown-trigger" id="branchTrigger" aria-haspopup="listbox" aria-expanded="false">
            <span id="branchValue">{{ $current->nombre ?? 'Sin sucursal' }}</span>
            <svg class="dropdown-caret" viewBox="0 0 20 20" aria-hidden="true">
                <path d="M5.75 7.5 10 11.75 14.25 7.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </button>

        <div class="topbar-dropdown-menu branch-menu" id="branchMenu" role="listbox">
            @foreach ($branches as $item)
                <button
                    type="button"
                    class="topbar-dropdown-option @if((int) $branchId === (int) $item->id) selected @endif"
                    data-value="{{ $item->id }}"
                    data-livewire-branch="true"
                    wire:click="selectBranch({{ (int) $item->id }})"
                    wire:key="branch-option-{{ $item->id }}"
                    role="option"
                    aria-selected="{{ (int) $branchId === (int) $item->id ? 'true' : 'false' }}"
                >
                    {{ $item->nombre }}
                </button>
            @endforeach
        </div>
    </div>
</div>
