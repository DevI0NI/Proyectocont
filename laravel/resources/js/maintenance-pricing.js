export function lineCost(price, quantity) {
    if (!/^\d+(?:\.\d{1,2})?$/.test(price ?? '') || !/^\d+(?:\.\d{1,3})?$/.test(quantity ?? '')) return null;
    const [whole, fraction = ''] = price.split('.');
    const [units, decimals = ''] = quantity.split('.');
    const cents = BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'));
    const milliunits = BigInt(units) * 1000n + BigInt(decimals.padEnd(3, '0'));
    if (milliunits === 0n || milliunits > 100000000n) return null;
    return (cents * milliunits + 500n) / 1000n;
}

export function formatCost(cents, currency) {
    const symbol = currency === 'GTQ' ? 'Q' : currency;
    return `${symbol} ${(cents / 100n).toLocaleString('es-GT')}.${String(cents % 100n).padStart(2, '0')}`;
}

export function convertToQuetzales(cents, rate) {
    if (!/^\d{1,3}\.\d{6}$/.test(rate ?? '')) return null;
    const scaled = BigInt(rate.replace('.', ''));
    return scaled > 0n ? (cents * scaled + 500000n) / 1000000n : null;
}

export function initializeMaintenancePricing(form) {
    const service = form.querySelector('[name="repair_service_id"]');
    const update = () => {
        const labor = new Map();
        const materials = new Map();
        let missing = !service.value;
        const add = (map, currency, cost) => map.set(currency, (map.get(currency) ?? 0n) + cost);
        const selected = service.selectedOptions[0];
        const laborCost = service.value ? lineCost(selected.dataset.cost, '1') : null;
        if (laborCost === null) missing = true;
        else add(labor, selected.dataset.currency, laborCost);
        form.querySelectorAll('[data-material-row]').forEach((row) => {
            const select = row.querySelector('select');
            const part = select.selectedOptions[0];
            const amount = row.querySelector('input[type="number"]').value;
            const cost = select.value ? lineCost(part.dataset.cost, amount) : null;
            row.querySelector('[data-material-cost]').textContent = select.value && part.dataset.cost !== ''
                ? formatCost(lineCost(part.dataset.cost, '1'), part.dataset.currency) : 'Sin valor referencial';
            row.querySelector('[data-material-subtotal]').textContent = cost === null ? 'Pendiente' : formatCost(cost, part.dataset.currency);
            if (cost === null) missing = true;
            else add(materials, part.dataset.currency, cost);
        });
        let pendingConversion = false;
        const toBase = (map) => {
            let cents = 0n;
            let known = false;
            map.forEach((cost, currency) => {
                const converted = currency === 'GTQ' ? cost : currency === 'USD' ? convertToQuetzales(cost, form.dataset.exchangeRate) : null;
                if (converted === null) pendingConversion = true;
                else { cents += converted; known = true; }
            });
            return {cents, known};
        };
        const baseLabor = toBase(labor);
        const baseMaterials = toBase(materials);
        if (form.querySelectorAll('[data-material-row]').length === 0) baseMaterials.known = true;
        form.querySelector('[data-estimate-labor]').textContent = baseLabor.known ? formatCost(baseLabor.cents, 'GTQ') : '—';
        form.querySelector('[data-estimate-materials]').textContent = baseMaterials.known ? formatCost(baseMaterials.cents, 'GTQ') : '—';
        missing ||= pendingConversion;
        form.querySelector('[data-estimate-total]').textContent = (missing ? 'Parcial: ' : '')
            + (baseLabor.known || baseMaterials.known ? formatCost(baseLabor.cents + baseMaterials.cents, 'GTQ') : '—');
        const dollars = selected.dataset.currency === 'USD' && Boolean(service.value)
            || [...form.querySelectorAll('[data-material-row] select')].some((select) => select.value && select.selectedOptions[0].dataset.currency === 'USD');
        const original = form.querySelector('[data-service-original]');
        original.hidden = !service.value || selected.dataset.currency !== 'USD' || laborCost === null;
        original.textContent = laborCost === null ? '' : 'Servicio original: ' + formatCost(laborCost, selected.dataset.currency);
        const exchange = form.querySelector('[data-exchange-notice]');
        exchange.hidden = !dollars;
        const date = (form.dataset.exchangeDate || '').split('-').reverse().join('/');
        exchange.textContent = form.dataset.exchangeRate
            ? `Banguat · 1 USD = Q ${form.dataset.exchangeRate} · ${date}`
            : 'Conversión USD pendiente: tipo de cambio no disponible.';
        const pending = form.querySelector('[data-estimate-pending]');
        pending.hidden = !missing;
        pending.textContent = pendingConversion ? 'Estimado incompleto: hay importes en dólares sin convertir.'
            : 'Estimado incompleto: falta seleccionar una reparación, completar cantidades o asignar valores referenciales.';
        form.querySelectorAll('[data-calculated-labor]').forEach((output) => { output.textContent = form.querySelector('[data-estimate-labor]').textContent; });
        form.querySelectorAll('[data-calculated-materials]').forEach((output) => { output.textContent = form.querySelector('[data-estimate-materials]').textContent; });
        form.querySelectorAll('[data-calculated-total]').forEach((output) => { output.textContent = form.querySelector('[data-estimate-total]').textContent; });
        form.querySelectorAll('[data-calculated-pending]').forEach((output) => { output.hidden = !missing; });
    };
    form.addEventListener('input', update);
    form.addEventListener('change', update);
    form.addEventListener('maintenance:materials-changed', update);
    update();
}
