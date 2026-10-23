@extends('layouts.app')
@section('content')
<section class="page-heading notification-review-heading correction-heading">
    <div><span class="eyebrow">Historial de correcciones</span><h1>{{ $job->container_name }}</h1><p>Mecánico: {{ $job->mechanic_name }} <span aria-hidden="true">·</span> {{ $rounds->count() }} {{ $rounds->count() === 1 ? 'corrección' : 'correcciones' }}</p></div>
    <div class="repair-history-heading-actions">@unless(str_ends_with(parse_url($returnUrl, PHP_URL_PATH) ?? '', '/historial'))<a class="mw-button" href="{{ route('corrections.history', ['job' => $job->id, 'return_to' => $returnUrl]) }}"><span data-notification-icon="wrench" aria-hidden="true"></span>Reparaciones</a>@endunless<a class="mw-button" href="{{ $returnUrl }}"><span data-notification-icon="chevron-left" aria-hidden="true"></span>Volver</a></div>
</section>
@if($errors->any())<div class="form-error page-error" role="alert">{{ $errors->first() }} Las fotos nuevas deben seleccionarse de nuevo.</div>@endif
<section class="workboard mw-board correction-board" data-correction-history aria-label="Cronología de la reparación">
    <ol class="correction-timeline">
        <li><span class="correction-node" aria-hidden="true">01</span><div class="correction-content">
            <header><h2>Reparación original</h2><span class="correction-status correction-preserved"><span data-notification-icon="archive" aria-hidden="true"></span>Entrega conservada</span></header>
            <details class="correction-original" open><summary><span data-notification-icon="wrench" aria-hidden="true"></span>Diagnóstico, trabajo y evidencias<span data-notification-icon="chevron-down" class="correction-chevron" aria-hidden="true"></span></summary>
                <div class="correction-original-body">
                    <div class="correction-assessment">
                        <section><h3>Diagnóstico inicial</h3><p class="notification-prose" tabindex="0">{{ $job->diagnosis ?: 'Sin diagnóstico registrado.' }}</p></section>
                        <section><h3>Trabajo realizado</h3><p class="notification-prose" tabindex="0">{{ $job->work_performed ?: 'Sin trabajo registrado.' }}</p></section>
                    </div>
                    <section class="correction-material-section">
                        <h3>Reparación y materiales originales</h3><p class="correction-repair-type">{{ $job->repair_type ?: 'Sin reparación registrada.' }}</p>
                        @if($originalMaterials->isNotEmpty())
                            <div class="notification-table-wrap" tabindex="0" role="region" aria-label="Materiales originales"><table><thead><tr><th>Material</th><th>Cantidad</th><th>Unidad</th></tr></thead><tbody>
                                @foreach($originalMaterials as $material)<tr><td>{{ $material->name }}</td><td>{{ rtrim(rtrim(number_format($material->quantity, 3, '.', ''), '0'), '.') }}</td><td>{{ $material->unit }}</td></tr>@endforeach
                            </tbody></table></div>
                        @else<p class="notification-body">Sin materiales registrados.</p>@endif
                    </section>
                    <div class="correction-evidence-pair">
                        @foreach(['before' => 'Evidencia inicial', 'after' => 'Evidencia final'] as $stage => $label)
                            <section><h3>{{ $label }} <small>{{ $originalPhotos->where('stage', $stage)->count() }} fotos</small></h3>
                                <div class="notification-evidence">@forelse($originalPhotos->where('stage', $stage) as $photo)<a data-correction-photo href="{{ route('corrections.original-photo', ['job' => $job->id, 'photo' => $photo->id]) }}" target="_blank" rel="noopener"><img loading="lazy" src="{{ route('corrections.original-photo', ['job' => $job->id, 'photo' => $photo->id]) }}" alt="{{ $photo->original_name }}"><span title="{{ $photo->original_name }}">{{ $photo->original_name }}</span></a>@empty<p class="notification-body">Sin fotos registradas.</p>@endforelse</div>
                            </section>
                        @endforeach
                    </div>
                </div>
            </details>
        </div></li>
        @foreach($rounds as $round)
            <li><span class="correction-node" aria-hidden="true">{{ sprintf('%02d', $round->number + 1) }}</span><section class="correction-content">
                <header><h2>Corrección {{ $round->number }}</h2><span class="correction-status" data-state="{{ $round->status }}">{{ match($round->status) { 'draft' => 'Por corregir', 'submitted' => 'Enviada a auditoría', 'approved' => 'Aprobada', default => 'Requiere otra corrección' } }}</span><time datetime="{{ \Carbon\Carbon::parse($round->created_at, 'UTC')->toIso8601String() }}">{{ \Carbon\Carbon::parse($round->created_at, 'UTC')->timezone('America/Guatemala')->format('d/m/Y H:i') }}</time></header>
                <div class="correction-audit-reason"><h3><span data-notification-icon="shield-check" aria-hidden="true"></span>Observación de la auditora</h3><p class="notification-prose" tabindex="0">{{ $round->reason }}</p></div>
                @if($draft && $draft->id === $round->id)
                    <form class="mw-form correction-form record-select-fields" method="POST" action="{{ route('corrections.update', $job->id) }}" enctype="multipart/form-data" data-correction-form data-external-audit-form>@csrf @method('PATCH')
                        <input type="hidden" name="round_id" value="{{ $round->id }}"><input type="hidden" name="version" value="{{ $round->version }}"><input type="hidden" name="return_to" value="{{ $returnUrl }}">
                        <label class="mw-field"><span>Trabajo de corrección realizado</span><textarea name="work_performed" rows="5" maxlength="1000">{{ old('work_performed', $round->work_performed) }}</textarea></label>
                        <div class="mw-section-title"><h3>Materiales adicionales</h3><button type="button" class="mw-button" data-correction-add><span data-notification-icon="plus" aria-hidden="true"></span>Agregar material</button></div>
                        <div data-correction-materials>
                            @foreach(old('materials', json_decode($round->materials ?? '[]', true)) as $index => $material)
                                @include('maintenance.correction-material', ['index' => $index, 'material' => $material])
                            @endforeach
                        </div>
                        <template data-correction-template>@include('maintenance.correction-material', ['index' => '__INDEX__', 'material' => []])</template>
                        <label class="mw-field"><span>Fotos de la corrección</span><input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple><small>Al menos una foto nueva. Hasta 5 fotos JPG, PNG o WEBP, de máximo 2 MB cada una.</small></label>
                        <div class="notification-evidence">
                            @foreach(json_decode($round->photos ?? '[]', true) as $index => $photo)
                                <div><a href="{{ route('corrections.photo', ['job' => $job->id, 'round' => $round->id, 'photo' => $index]) }}" target="_blank" rel="noopener"><img src="{{ route('corrections.photo', ['job' => $job->id, 'round' => $round->id, 'photo' => $index]) }}" alt="{{ $photo['name'] }}"><span>{{ $photo['name'] }}</span></a><label class="correction-remove-photo"><input type="checkbox" name="remove_photos[]" value="{{ $index }}">Quitar del borrador</label></div>
                            @endforeach
                        </div>
                        <footer class="mw-actions"><button class="mw-button" name="action" value="save" type="submit">Guardar borrador</button><button class="mw-button mw-primary" name="action" value="submit" type="submit"><span data-notification-icon="send" aria-hidden="true"></span>Enviar corrección a auditoría</button></footer>
                    </form>
                @else
                    <h3>Corrección realizada</h3><p class="notification-prose">{{ $round->work_performed }}</p>
                    @if(json_decode($round->materials ?? '[]', true))<h3>Materiales adicionales</h3><ul class="correction-material-history">@foreach(json_decode($round->materials, true) as $material)<li>{{ $material['name'] }} · {{ $material['quantity'] }} {{ $material['unit'] }} · {{ $material['unit_cost'] === null ? 'Precio pendiente' : $material['currency'].' '.number_format($material['unit_cost'], 2) }}</li>@endforeach</ul>@endif
                    <div class="notification-evidence">@foreach(json_decode($round->photos ?? '[]', true) as $index => $photo)<a href="{{ route('corrections.photo', ['job' => $job->id, 'round' => $round->id, 'photo' => $index]) }}" target="_blank" rel="noopener"><img loading="lazy" src="{{ route('corrections.photo', ['job' => $job->id, 'round' => $round->id, 'photo' => $index]) }}" alt="{{ $photo['name'] }}"><span>{{ $photo['name'] }}</span></a>@endforeach</div>
                @endif
            </section></li>
        @endforeach
    </ol>
    @if($rounds->isEmpty())<div class="correction-empty"><span data-notification-icon="check-check" aria-hidden="true"></span><div><h2>Sin correcciones registradas</h2><p>Esta reparación conserva su entrega original.</p></div></div>@endif
    <dialog class="mw-photo-dialog notification-review-viewer" data-correction-viewer aria-labelledby="correctionPhotoTitle">
        <header><h2 id="correctionPhotoTitle"></h2><button type="button" class="mw-button mw-icon" data-correction-viewer-close aria-label="Cerrar imagen" title="Cerrar imagen"><span data-notification-icon="x" aria-hidden="true"></span></button></header>
        <div class="mw-photo-viewer-image"><img alt=""></div>
    </dialog>
</section>
@endsection
