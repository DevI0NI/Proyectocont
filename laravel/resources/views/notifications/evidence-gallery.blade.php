@if($photos->isNotEmpty())
    <div class="notification-review-gallery" data-review-gallery role="region" aria-roledescription="carrusel" aria-label="{{ $label }}" tabindex="0">
        @foreach($photos as $photo)
            @php($photoUrl = $photo->url ?? (($readOnlyDetail ?? false) ? route('corrections.original-photo', ['job' => $job->id, 'photo' => $photo->id]) : route('notifications.evidence', ['job' => $job->id, 'evidence' => $photo->id])))
            <figure data-review-slide @if(!$loop->first) hidden @endif role="group" aria-roledescription="diapositiva" aria-label="{{ $loop->iteration }} de {{ $photos->count() }}">
                <img src="{{ $photoUrl }}" alt="{{ $photo->original_name }}" @if(!$loop->first) loading="lazy" @endif>
                <figcaption>{{ $photo->original_name }}</figcaption>
                <a class="mw-button" href="{{ $photoUrl }}" target="_blank" rel="noopener" data-review-photo data-name="{{ $photo->original_name }}"><span data-notification-icon="eye" aria-hidden="true"></span>Ver</a>
            </figure>
        @endforeach
        <div class="mw-photo-navigation" @if($photos->count() < 2) hidden @endif>
            <button type="button" class="mw-button mw-icon" data-review-photo-previous aria-label="Foto anterior" title="Foto anterior" disabled><span data-notification-icon="chevron-left" aria-hidden="true"></span></button>
            <span data-review-photo-position aria-live="polite" aria-atomic="true">1 / {{ $photos->count() }}</span>
            <button type="button" class="mw-button mw-icon" data-review-photo-next aria-label="Foto siguiente" title="Foto siguiente"><span data-notification-icon="chevron-right" aria-hidden="true"></span></button>
        </div>
    </div>
@else
    <div class="notification-review-photo-empty"><span data-notification-icon="image" aria-hidden="true"></span><p>{{ $emptyMessage ?? 'Sin fotos registradas.' }}</p></div>
@endif
