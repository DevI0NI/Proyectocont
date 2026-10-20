<nav class="admin-pagination @if($liveTable ?? false) containers-pagination @endif" aria-label="Paginacion de registros">
    <span role="status">Pagina {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }} · {{ $paginator->total() }} registros</span>
    <div class="admin-page-buttons">
        @foreach ([['page' => 1, 'label' => 'Primera pagina', 'icon' => 'chevrons-left', 'disabled' => $paginator->onFirstPage()], ['page' => max(1, $paginator->currentPage() - 1), 'label' => 'Pagina anterior', 'icon' => 'chevron-left', 'disabled' => $paginator->onFirstPage()], ['page' => min($paginator->lastPage(), $paginator->currentPage() + 1), 'label' => 'Pagina siguiente', 'icon' => 'chevron-right', 'disabled' => !$paginator->hasMorePages()], ['page' => $paginator->lastPage(), 'label' => 'Ultima pagina', 'icon' => 'chevrons-right', 'disabled' => !$paginator->hasMorePages()]] as $control)
            @if($liveTable ?? false)
                <button type="button" @disabled($control['disabled']) @unless($control['disabled']) data-admin-page data-url="{{ $paginator->url($control['page']) }}" @endunless title="{{ $control['label'] }}" aria-label="{{ $control['label'] }}">{{ ['chevrons-left' => '<<', 'chevron-left' => '<', 'chevron-right' => '>', 'chevrons-right' => '>>'][$control['icon']] }}</button>
            @elseif ($control['disabled'])
                <span class="admin-action disabled" aria-disabled="true" aria-label="{{ $control['label'] }}"><span data-admin-icon="{{ $control['icon'] }}" aria-hidden="true"></span></span>
            @else
                <a class="admin-action" data-admin-page href="{{ $paginator->url($control['page']) }}" title="{{ $control['label'] }}" aria-label="{{ $control['label'] }}"><span data-admin-icon="{{ $control['icon'] }}" aria-hidden="true"></span></a>
            @endif
        @endforeach
    </div>
</nav>
