import { createElement, ChevronDown } from 'lucide';

const normalize = (text) => text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

function initialize() {
    document.querySelectorAll('[data-iso-search]:not([data-enhanced]), [data-searchable-select]:not([data-enhanced])').forEach((select) => {
        const isIso = select.hasAttribute('data-iso-search');
        const fieldLabel = select.dataset.searchLabel || 'ISO Code';
        select.dataset.enhanced = 'true';
        select.classList.add('enhanced-native-select');
        select.tabIndex = -1;
        select.setAttribute('aria-hidden', 'true');
        const wrapper = document.createElement('div');
        wrapper.className = 'form-select-dropdown iso-search-dropdown';
        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'form-select-trigger';
        trigger.setAttribute('aria-label', fieldLabel);
        trigger.setAttribute('aria-expanded', 'false');
        trigger.setAttribute('aria-haspopup', 'dialog');
        const label = document.createElement('span');
        trigger.append(label, createElement(ChevronDown, { class:'dropdown-caret', 'aria-hidden':'true' }));
        const menu = document.createElement('div');
        menu.className = 'form-select-menu iso-search-menu';
        menu.id = select.id + '-search-menu';
        menu.setAttribute('popover', 'manual');
        menu.setAttribute('role', 'dialog');
        menu.setAttribute('aria-label', 'Seleccionar ' + fieldLabel);
        trigger.setAttribute('aria-controls', menu.id);
        const search = document.createElement('input');
        search.type = 'search';
        search.autocomplete = 'off';
        search.maxLength = 120;
        search.placeholder = select.dataset.searchPlaceholder || 'Buscar codigo, tipo o tamano';
        search.setAttribute('aria-label', 'Buscar ' + fieldLabel);
        search.setAttribute('role', 'combobox');
        search.setAttribute('aria-autocomplete', 'list');
        search.setAttribute('aria-expanded', 'false');
        const results = document.createElement('div');
        results.className = 'iso-search-results';
        results.id = select.id + '-search-results';
        results.setAttribute('role', 'listbox');
        results.setAttribute('aria-label', isIso ? 'Codigos ISO' : fieldLabel);
        search.setAttribute('aria-controls', results.id);
        const empty = document.createElement('div');
        empty.className = 'iso-search-empty';
        empty.setAttribute('role', 'status');
        empty.textContent = select.dataset.searchEmpty || 'No hay codigos que coincidan.';
        menu.append(search, results, empty);
        wrapper.append(trigger);
        select.after(wrapper);
        document.body.append(menu);

        let opensUp = null;
        let closingAnimation = null;

        function position() {
            if (!menu.matches(':popover-open')) return;
            const bounds = trigger.getBoundingClientRect();
            const width = Math.min(bounds.width, innerWidth - 24);
            menu.style.width = width + 'px';
            menu.style.left = Math.max(12, Math.min(bounds.left, innerWidth - width - 12)) + 'px';
            menu.style.maxHeight = 'none';
            const height = Math.min(menu.offsetHeight, 320);
            const below = innerHeight - bounds.bottom - 12;
            const above = bounds.top - 12;
            if (opensUp === null) opensUp = below < height && above > below;
            menu.style.maxHeight = Math.max(100, opensUp ? above - 6 : below - 6) + 'px';
            menu.style.top = (opensUp ? Math.max(12, bounds.top - Math.min(height, above - 6) - 6) : bounds.bottom + 6) + 'px';
        }
        function close(focus = false) {
            wrapper.classList.remove('open');
            trigger.setAttribute('aria-expanded', 'false');
            search.setAttribute('aria-expanded', 'false');
            if (focus) trigger.focus();
            if (!menu.matches(':popover-open') || closingAnimation) return;
            menu.classList.add('is-closing');
            const animation = menu.animate([
                { opacity:1, transform:'translateY(0) scale(1)' },
                { opacity:0, transform:'translateY(-8px) scale(.98)' },
            ], { duration:matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 180, easing:'ease' });
            closingAnimation = animation;
            animation.finished.then(() => {
                if (closingAnimation !== animation) return;
                menu.hidePopover();
                opensUp = null;
                closingAnimation = null;
                menu.classList.remove('is-closing');
            }).catch(() => {});
        }
        function choose(option) {
            select.value = option.value;
            select.dispatchEvent(new Event('change', { bubbles:true }));
            trigger.classList.remove('invalid');
            close(true);
        }
        function render() {
            results.replaceChildren();
            const query = normalize(search.value.trim());
            const tokens = query.split(/\s+/).filter(Boolean);
            const codeQuery = query.replace(/[^a-z0-9]/g, '');
            const options = Array.from(select.options).filter((option) => !option.disabled && (option.value || select.hasAttribute('data-allow-empty'))).map((option, index) => {
                if (!isIso) {
                    const text = normalize(option.textContent);
                    return { option, index, matches:tokens.every((token) => text.includes(token)), rank:query && text.startsWith(query) ? 0 : 1 };
                }
                const type = option.value[2];
                const aliases = type === 'U' ? (option.value.endsWith('U6') ? 'hardtop techo rigido' : 'open top techo abierto') : ({ G:'dry seco gp', R:'reefer refrigerado', P:'flat rack plataforma', T:'tank tanque', V:'ventilado ventilated' }[type] || '');
                const feet = { '2':'20', '4':'40', L:'45' }[option.value[0]] || '';
                const highCube = option.value[1] === '5' ? ' hc highcube ' + feet + 'hc' : '';
                const text = normalize(option.value + ' ' + option.textContent + ' ' + aliases + ' ' + highCube);
                const code = normalize(option.value);
                return { option, index, matches:tokens.every((token) => text.includes(token)), rank:codeQuery && code === codeQuery ? 0 : codeQuery && code.startsWith(codeQuery) ? 1 : 2 };
            }).filter((item) => item.matches).sort((a, b) => a.rank - b.rank || a.index - b.index);
            options.forEach(({ option }) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'topbar-dropdown-option';
                button.textContent = option.textContent;
                button.setAttribute('role', 'option');
                button.setAttribute('aria-selected', String(option.selected));
                button.classList.toggle('selected', option.selected);
                button.addEventListener('click', () => choose(option));
                results.append(button);
            });
            empty.hidden = options.length > 0;
            position();
        }
        function open() {
            document.dispatchEvent(new Event('iso-select:close'));
            closingAnimation?.cancel();
            closingAnimation = null;
            menu.classList.remove('is-closing');
            opensUp = null;
            search.value = '';
            render();
            menu.showPopover();
            wrapper.classList.add('open');
            trigger.setAttribute('aria-expanded', 'true');
            search.setAttribute('aria-expanded', 'true');
            position();
            if (!matchMedia('(prefers-reduced-motion: reduce)').matches) menu.animate([{ opacity:0, transform:'translateY(-8px) scale(.98)' }, { opacity:1, transform:'translateY(0) scale(1)' }], { duration:180, easing:'ease' });
            search.focus({ preventScroll:true });
        }
        function selectedLabel() {
            label.textContent = select.selectedOptions[0]?.textContent || 'Selecciona un ISO Code';
            trigger.disabled = select.disabled;
            trigger.title = label.textContent;
        }
        trigger.addEventListener('click', () => menu.matches(':popover-open') && !closingAnimation ? close() : open());
        search.addEventListener('input', render);
        menu.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(true); return; }
            const buttons = Array.from(results.querySelectorAll('button'));
            if (event.key === 'Enter' && event.target === search) { event.preventDefault(); buttons[0]?.click(); return; }
            if (!['ArrowDown', 'ArrowUp'].includes(event.key)) return;
            event.preventDefault();
            const index = buttons.indexOf(document.activeElement);
            const next = index < 0 ? (event.key === 'ArrowDown' ? 0 : buttons.length - 1) : (index + (event.key === 'ArrowDown' ? 1 : -1) + buttons.length) % buttons.length;
            buttons[next]?.focus();
        });
        trigger.addEventListener('keydown', (event) => { if (['ArrowDown', 'ArrowUp'].includes(event.key)) { event.preventDefault(); open(); } });
        select.addEventListener('change', () => { select.setCustomValidity(''); selectedLabel(); });
        select.addEventListener('options-updated', () => { selectedLabel(); render(); });
        select.addEventListener('invalid', (event) => { event.preventDefault(); trigger.classList.add('invalid'); trigger.focus(); });
        document.addEventListener('iso-select:close', () => close());
        document.addEventListener('click', (event) => { if (!wrapper.contains(event.target) && !menu.contains(event.target)) close(); });
        document.addEventListener('focusin', (event) => { if (!wrapper.contains(event.target) && !menu.contains(event.target)) close(); });
        window.addEventListener('resize', position);
        document.addEventListener('scroll', (event) => { if (!(event.target instanceof Element) || !menu.contains(event.target)) position(); }, true);
        selectedLabel();
    });
}
document.addEventListener('DOMContentLoaded', initialize);
