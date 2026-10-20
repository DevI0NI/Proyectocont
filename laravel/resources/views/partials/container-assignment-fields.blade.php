@php
    $editingAssignment = isset($container);
    $canAssignAudit = auth()->user()->hasPermission('pyp.assign');
    $lineValue = (string) old('shipping_line_id', $assignmentCycle?->shipping_line_id ?? '');
    $auditValue = (string) old('audit_company_id', $assignmentCycle?->audit_company_id ?? '');
@endphp
<div class="container-assignment-fields" data-container-assignment
     data-original-line="{{ $assignmentCycle?->shipping_line_id ?? '' }}" data-original-audit="{{ $assignmentCycle?->audit_company_id ?? '' }}"
     data-editing="{{ $editingAssignment ? 'true' : 'false' }}" data-can-assign="{{ $canAssignAudit ? 'true' : 'false' }}">
    <script type="application/json" data-assignment-options>@json($assignmentAuditors)</script>
    <label>
        <span>Naviera</span>
        <select id="shipping_line_id" name="shipping_line_id" data-searchable-select data-search-label="Naviera" data-search-placeholder="Buscar naviera" data-search-empty="{{ $assignmentLines->isEmpty() ? 'No hay navieras activas autorizadas para esta sucursal.' : 'No hay navieras que coincidan con la busqueda.' }}"
                @required(!$editingAssignment || $assignmentCycle) @disabled($editingAssignment && !$canAssignAudit)>
            <option value="">{{ $editingAssignment && !$assignmentCycle ? (trim((string) $container->naviera) !== '' ? $container->naviera . ' (sin vincular al catalogo)' : 'Sin naviera registrada') : 'Selecciona una naviera' }}</option>
            @if($assignmentCycle && !$assignmentLines->contains('id', $assignmentCycle->shipping_line_id))
                <option value="{{ $assignmentCycle->shipping_line_id }}" @selected($lineValue === (string) $assignmentCycle->shipping_line_id)>{{ $assignmentCycle->shipping_line_name }} (asignacion anterior)</option>
            @endif
            @foreach($assignmentLines as $line)
                <option value="{{ $line->id }}" @selected($lineValue === (string) $line->id)>{{ $line->name }}</option>
            @endforeach
        </select>
        @if($editingAssignment && !$canAssignAudit)<input type="hidden" name="shipping_line_id" value="{{ $assignmentCycle?->shipping_line_id }}">@endif
    </label>
    <label>
        <span>Auditora indicada por la naviera</span>
        <select id="audit_company_id" name="audit_company_id" data-searchable-select data-allow-empty data-search-label="Auditora" data-search-placeholder="Buscar auditora" data-search-empty="No hay auditoras autorizadas."
                @disabled(!$canAssignAudit)>
            <option value="">Pendiente de confirmacion</option>
            @foreach($assignmentAuditors->get((int) $lineValue, collect()) as $auditor)
                <option value="{{ $auditor->id }}" @selected($auditValue === (string) $auditor->id)>{{ $auditor->name }}</option>
            @endforeach
            @if($assignmentCycle?->audit_company_id && !$assignmentAuditors->get((int) $lineValue, collect())->contains('id', $assignmentCycle->audit_company_id))
                <option value="{{ $assignmentCycle->audit_company_id }}" @selected($auditValue === (string) $assignmentCycle->audit_company_id)>{{ $assignmentCycle->audit_company_name }} (asignacion anterior)</option>
            @endif
        </select>
        @if(!$canAssignAudit)<input type="hidden" name="audit_company_id" value="{{ $assignmentCycle?->audit_company_id }}">@endif
    </label>
</div>
