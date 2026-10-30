<section class="mw-assessment-section" aria-labelledby="{{ $field }}Title" data-correction-photo-group>
    <div class="mw-photo-heading"><h3 id="{{ $field }}Title" class="mw-photo-section-label">{{ $title }}</h3><span class="plate-help"><button type="button" class="plate-help-trigger" aria-label="Requisitos de las fotos" aria-describedby="{{ $field }}Help" data-field-help data-help-touch></button><span class="plate-help-tooltip" id="{{ $field }}Help" role="tooltip" hidden>De 1 a 5 fotos. JPG, PNG o WEBP; máximo 2 MB por foto.</span></span></div>
    <div class="mw-photo-upload"><button type="button" class="mw-button" data-correction-photo-add><span data-notification-icon="plus" aria-hidden="true"></span>Adjuntar fotos</button><input class="mw-photo-native" type="file" name="{{ $field }}[]" accept="image/jpeg,image/png,image/webp" multiple data-correction-photos aria-label="{{ $title }}" aria-describedby="{{ $field }}Help"></div>
    <p class="mw-error" data-correction-photo-error role="alert" hidden></p>
    @php
        $photos = collect(json_decode($round->{$field} ?? '[]', true))->map(fn ($photo, $index) => (object) [
            'id' => $index, 'mime' => $photo['mime'], 'original_name' => $photo['name'],
            'url' => route('corrections.photo', ['job' => $job->id, 'round' => $round->id, 'photo' => $index, 'stage' => $stage]),
        ]);
    @endphp
    @include('maintenance.photo-carousel', ['photos' => $photos, 'label' => $title, 'correctionField' => $field])
</section>
