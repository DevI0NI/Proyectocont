<dialog class="container-exit-dialog" id="{{ $exitDialogId }}" role="alertdialog" aria-labelledby="{{ $exitDialogId }}Title" aria-describedby="{{ $exitDialogId }}Description">
    <span class="container-exit-dialog-kicker">{{ $exitDialogKicker ?? 'Cambios sin guardar' }}</span>
    <h2 id="{{ $exitDialogId }}Title">{{ $exitDialogTitle ?? '¿Salir sin guardar?' }}</h2>
    <p id="{{ $exitDialogId }}Description">{{ $exitDialogDescription ?? 'Los cambios que no hayas guardado se perderán.' }}</p>
    <div class="container-exit-dialog-actions">
        <button type="button" class="button container-exit-stay" data-unsaved-stay>Continuar</button>
        <button type="button" class="button container-exit-leave" data-unsaved-leave>Salir</button>
    </div>
</dialog>
