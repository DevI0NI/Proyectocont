document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-container-assignment]').forEach((root) => {
        const line = root.querySelector('#shipping_line_id');
        const audit = root.querySelector('#audit_company_id');
        const reference = root.parentElement.querySelector('[data-assignment-reference]');
        const reason = root.parentElement.querySelector('[data-assignment-reason]');
        const options = JSON.parse(root.querySelector('[data-assignment-options]').textContent);
        const initialAudit = audit.selectedOptions[0];
        const retained = initialAudit?.value ? { id:initialAudit.value, name:initialAudit.textContent } : null;
        const canAssign = root.dataset.canAssign === 'true';
        function sync() {
            const hasAudit = Boolean(audit.value);
            reference.hidden = !hasAudit;
            if (reason) {
                const changed = line.value !== root.dataset.originalLine || audit.value !== root.dataset.originalAudit;
                reason.hidden = !changed;
                reason.querySelector('[name="assignment_reason"]').required = changed;
            }
        }
        function renderAuditors(keepValue) {
            const selected = keepValue ? audit.value : '';
            const items = [...(options[line.value] || [])];
            if (keepValue && retained && !items.some((item) => String(item.id) === retained.id)) items.push(retained);
            audit.replaceChildren(new Option('Pendiente de confirmacion', ''));
            items.forEach((item) => audit.add(new Option(item.name, String(item.id))));
            audit.value = selected;
            audit.disabled = !canAssign || !line.value;
            audit.dispatchEvent(new Event('options-updated'));
            sync();
        }
        line.addEventListener('change', () => {
            reference.querySelector('[name="audit_reference"]').value = '';
            renderAuditors(false);
        });
        audit.addEventListener('change', () => {
            reference.querySelector('[name="audit_reference"]').value = '';
            sync();
        });
        renderAuditors(true);
    });
});
