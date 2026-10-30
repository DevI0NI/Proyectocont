<div class="mw-photo-carousel" data-photo-carousel role="region" aria-roledescription="carrusel" aria-label="{{ $label }}" tabindex="0">
    @include('maintenance.saved-photos')
    <div class="mw-photo-previews" data-initial-photo-previews @isset($correctionField) data-correction-photo-previews @endisset></div>
    <div class="mw-photo-navigation" data-photo-navigation hidden>
        <button type="button" class="mw-button mw-icon" data-photo-previous aria-label="Foto anterior" title="Foto anterior"><span data-maintenance-photo-icon="previous" @isset($correctionField) data-notification-icon="chevron-left" @endisset aria-hidden="true"></span></button>
        <span data-photo-position aria-live="polite" aria-atomic="true"></span>
        <button type="button" class="mw-button mw-icon" data-photo-next aria-label="Foto siguiente" title="Foto siguiente"><span data-maintenance-photo-icon="next" @isset($correctionField) data-notification-icon="chevron-right" @endisset aria-hidden="true"></span></button>
    </div>
</div>
