<nav class="catalog-tabs" aria-label="Catalogos operativos">
@foreach(\App\Services\OperationalCatalog::DEFINITIONS as $key => $item)
@continue($key === 'iso')
<a href="{{ route('catalogs.index', $key) }}" @class(['active' => $catalog === $key])><span data-catalog-icon="{{ $item['icon'] }}" aria-hidden="true"></span>{{ $item['title'] }}</a>
@endforeach
</nav>
