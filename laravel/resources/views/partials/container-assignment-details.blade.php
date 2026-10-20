@php
    $canAssignAudit = auth()->user()->hasPermission('pyp.assign');
    $hasAudit = (string) old('audit_company_id', $assignmentCycle?->audit_company_id ?? '') !== '';
@endphp
<div class="field-wide assignment-notes">
<div class="assignment-detail plate-field" data-assignment-reference @if(!$hasAudit) hidden @endif>
    <div class="plate-field-title">
        <label for="audit_reference">Observaciones de la asignacion <small>(opcional)</small></label>
        <span class="plate-help">
            <button type="button" class="plate-help-trigger" aria-label="Ayuda sobre las observaciones de la asignacion" aria-describedby="assignmentNotesHelp" aria-controls="assignmentNotesHelp" aria-expanded="false" data-field-help data-help-touch></button>
            <span class="plate-help-tooltip" id="assignmentNotesHelp" role="tooltip" hidden>Puedes anotar como se confirmo la auditora: un correo, numero de orden o el nombre de quien dio la indicacion. Es opcional; dejalo vacio si no tienes una observacion que agregar. No es la aprobacion de la inspeccion.</span>
        </span>
    </div>
    <textarea id="audit_reference" name="audit_reference" rows="3" maxlength="255" @readonly(!$canAssignAudit)>{{ old('audit_reference', $assignmentCycle?->audit_reference) }}</textarea>
</div>
@if(isset($container) && $canAssignAudit)
    <div class="assignment-detail plate-field" data-assignment-reason hidden>
        <div class="plate-field-title">
            <label for="assignment_reason">Motivo del cambio de asignacion</label>
        </div>
        <textarea id="assignment_reason" name="assignment_reason" rows="3" maxlength="255">{{ old('assignment_reason') }}</textarea>
    </div>
@endif
</div>
