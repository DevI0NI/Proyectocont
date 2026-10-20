<div class="mw-photo-previews mw-saved-photos">
@foreach($photos as $photo)
    @if(in_array($photo->mime, ['image/jpeg', 'image/png', 'image/webp'], true))
    @php($photoUrl = route('maintenance.evidence', ['job' => $job->id, 'evidence' => $photo->id, 'inline' => 1]))
    <figure>
        <img src="{{ $photoUrl }}" alt="{{ $photo->original_name }}" loading="lazy">
        <figcaption title="{{ $photo->original_name }}">{{ $photo->original_name }}</figcaption>
        <button type="button" class="mw-button mw-saved-photo-view" data-saved-photo-view data-src="{{ $photoUrl }}" data-name="{{ $photo->original_name }}" aria-label="Ver {{ $photo->original_name }}"><span data-maintenance-photo-icon="view" aria-hidden="true"></span>Ver</button>
    </figure>
    @else
    <a class="mw-attachment" href="{{ route('maintenance.evidence', [$job->id, $photo->id]) }}">{{ $photo->original_name }}</a>
    @endif
@endforeach
</div>
