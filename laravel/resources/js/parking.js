import * as THREE from 'three';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import { documentaryTextError } from './documentary-validation.js';
import { initializePlateInput } from './transport-plate-input.js';
import { addContainerDetails, corrugatedTexture, createContainerDetailsGeometry, createContainerGeometry } from './container-model.js';
import { createElement, ArrowLeft, Settings, Sun, Truck, RotateCcw, Search, Printer, Monitor, List, Plus, ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight, Trash2 } from 'lucide';

const icons = { 'arrow-left': ArrowLeft, settings: Settings, sun: Sun, truck: Truck, 'rotate-ccw': RotateCcw, search: Search, printer: Printer, monitor: Monitor, list: List, plus: Plus, 'chevron-left': ChevronLeft, 'chevron-right': ChevronRight, 'chevrons-left': ChevronsLeft, 'chevrons-right': ChevronsRight, trash: Trash2 };
let parkingInitialized = false;

function initParking() {
    if (!document.body.classList.contains('parking-body')) return;
    document.querySelectorAll('[data-parking-icon]').forEach((node) => {
        const icon = icons[node.dataset.parkingIcon];
        if (icon) node.replaceChildren(createElement(icon, { 'aria-hidden': 'true', width: 18, height: 18 }));
    });
    if (!document.querySelector('.parking-desktop-content') || window.matchMedia('(max-width: 980px)').matches) return;
    if (parkingInitialized) return;
    parkingInitialized = true;
    initParkingSelects();
    document.querySelector('[data-parking-location]')?.addEventListener('change', (event) => event.target.form.submit());
    document.querySelector('[data-parking-print]')?.addEventListener('click', () => window.print());
    const paymentMethod = document.querySelector('[data-payment-method]');
    const paymentReference = document.querySelector('[data-payment-reference]');
    paymentMethod?.addEventListener('change', () => { paymentReference.required = paymentMethod.value === 'transfer'; });
    const settlementForm = document.querySelector('[data-parking-settle]');
    const dialog = document.querySelector('[data-settlement-dialog]');
    settlementForm?.addEventListener('submit', (event) => { event.preventDefault(); dialog.showModal(); });
    document.querySelectorAll('[data-dialog-close]').forEach((button) => button.addEventListener('click', () => button.closest('dialog')?.close()));
    document.querySelectorAll('[data-dialog-open]').forEach((button) => button.addEventListener('click', () => {
        const target = document.getElementById(button.dataset.dialogOpen);
        target?.showModal();
        target?.querySelector('input:not([type=hidden])')?.focus();
    }));
    document.querySelectorAll('dialog[data-open-on-load]').forEach((target) => target.showModal());
    const removeDialog = document.getElementById('parking-remove-dialog');
    document.querySelectorAll('[data-space-remove]').forEach((button) => button.addEventListener('click', () => {
        removeDialog.querySelector('[data-remove-form]').action = button.dataset.url;
        removeDialog.querySelector('[data-remove-code]').textContent = button.dataset.code;
        removeDialog.showModal();
    }));
    document.querySelectorAll('.parking-form-dialog').forEach((target) => target.addEventListener('click', (event) => { if (event.target === target) target.close(); }));
    document.querySelector('[data-settlement-confirm]')?.addEventListener('click', (event) => {
        event.currentTarget.disabled = true;
        settlementForm.submit();
    });
    const select = document.querySelector('#parking-space-select');
    const selection = document.querySelector('#parking-selection');
    const submit = document.querySelector('[data-parking-submit]');
    const submitEnabled = submit && !submit.disabled;
    function syncSelection() {
        const code = select.value ? select.selectedOptions[0].textContent : '';
        selection.textContent = code || selection.dataset.emptyText;
        selection.classList.toggle('is-empty', !code);
        if (submit) {
            submit.textContent = code ? `Registrar entrada en ${code}` : 'Registrar entrada';
            submit.disabled = !submitEnabled || !code;
        }
        document.querySelectorAll('.parking-space-option').forEach((button) => button.classList.toggle('selected', button.dataset.spaceId === select.value));
        document.querySelector('[data-parking-scene]')?.dispatchEvent(new Event('selectionchange'));
    }
    if (selection && !selection.classList.contains('is-empty')) selection.classList.add('pulse');
    document.querySelectorAll('[data-parking-view]').forEach((tab) => tab.addEventListener('click', () => {
        document.querySelectorAll('[data-parking-view]').forEach((other) => {
            const active = other === tab;
            other.classList.toggle('active', active);
            other.setAttribute('aria-selected', String(active));
        });
        document.querySelectorAll('[data-parking-view-panel]').forEach((panel) => { panel.hidden = panel.dataset.parkingViewPanel !== tab.dataset.parkingView; });
    }));
    document.querySelectorAll('.parking-space-option').forEach((button) => button.addEventListener('click', () => {
        if (button.dataset.stayUrl) { window.location.href = button.dataset.stayUrl; return; }
        select.value = select.value === button.dataset.spaceId ? '' : button.dataset.spaceId;
        select.dispatchEvent(new Event('change', { bubbles: true }));
        if (select.value) {
            selection.classList.remove('pulse');
            void selection.offsetWidth;
            selection.classList.add('pulse');
            document.querySelector('#parking-entry-form input[name="plate"]')?.focus({ preventScroll: true });
        }
    }));
    const PLAN_PAGE_SIZE = 24;
    const tiles = [...document.querySelectorAll('.parking-tile')];
    const filters = [...document.querySelectorAll('[data-parking-filter]')];
    const pager = document.querySelector('[data-plan-pages]');
    let planFilter = '';
    let planPage = 0;
    function renderPlan() {
        const matching = tiles.filter((tile) => !planFilter || tile.classList.contains(planFilter));
        const pages = Math.max(1, Math.ceil(matching.length / PLAN_PAGE_SIZE));
        planPage = Math.min(Math.max(planPage, 0), pages - 1);
        const visible = new Set(matching.slice(planPage * PLAN_PAGE_SIZE, (planPage + 1) * PLAN_PAGE_SIZE));
        tiles.forEach((tile) => { tile.hidden = !visible.has(tile); });
        const empty = document.querySelector('.parking-filter-empty');
        if (empty) empty.hidden = matching.length > 0 || !tiles.length;
        if (!pager) return;
        pager.hidden = pages <= 1;
        pager.querySelector('[data-plan-pages-status]').textContent = `Pagina ${planPage + 1} de ${pages} · ${matching.length} espacios`;
        pager.querySelectorAll('[data-plan-page]').forEach((button) => {
            const back = ['first', 'previous'].includes(button.dataset.planPage);
            button.disabled = back ? planPage === 0 : planPage >= pages - 1;
        });
    }
    pager?.querySelectorAll('[data-plan-page]').forEach((button) => button.addEventListener('click', () => {
        const last = Math.ceil(tiles.filter((tile) => !planFilter || tile.classList.contains(planFilter)).length / PLAN_PAGE_SIZE) - 1;
        planPage = { first: 0, previous: planPage - 1, next: planPage + 1, last }[button.dataset.planPage];
        renderPlan();
    }));
    filters.forEach((button) => button.addEventListener('click', () => {
        planFilter = button.getAttribute('aria-pressed') === 'true' ? '' : button.dataset.parkingFilter;
        filters.forEach((other) => other.setAttribute('aria-pressed', String(other.dataset.parkingFilter === planFilter)));
        planPage = 0;
        renderPlan();
    }));
    const chosenTile = tiles.findIndex((tile) => tile.dataset.spaceId === select?.value && select.value !== '');
    planPage = chosenTile > 0 ? Math.floor(chosenTile / PLAN_PAGE_SIZE) : 0;
    renderPlan();
    select?.addEventListener('change', () => {
        const matching = tiles.filter((tile) => !planFilter || tile.classList.contains(planFilter));
        const index = matching.findIndex((tile) => tile.dataset.spaceId === select.value);
        if (index >= 0 && Math.floor(index / PLAN_PAGE_SIZE) !== planPage) { planPage = Math.floor(index / PLAN_PAGE_SIZE); renderPlan(); }
    });
    select?.addEventListener('change', syncSelection);
    if (select) syncSelection();
    document.querySelectorAll('.parking-main form').forEach((form) => { if (form.querySelector('[data-parking-check], [data-parking-counter]')) initEntryChecks(form); });
    initLiveTimers();
    const stage = document.querySelector('[data-parking-scene]');
    if (stage) {
        try { initScene(stage, select); }
        catch { stage.querySelector('.parking-map-error').hidden = false; }
    }
}

const pad = (value) => String(value).padStart(2, '0');
const duration = (seconds, withSeconds = false) => {
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const text = `${days ? `${days} d ` : ''}${days ? pad(hours) : hours} h ${pad(minutes)} min`;
    return withSeconds ? `${text} ${pad(Math.floor(seconds % 60))} s` : text;
};

function initLiveTimers() {
    const started = performance.now();
    const elapsedSince = (base) => Number(base) + Math.floor((performance.now() - started) / 1000);
    const tiles = [...document.querySelectorAll('[data-live-since]')];
    const live = document.querySelector('[data-parking-live]');
    if (!tiles.length && !live) return;
    const money = live ? new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : null;
    const expected = document.querySelector('input[name="expected_total_cents"]');
    const unitLabel = live && Number(live.dataset.period) === 3600 ? 'hora(s)' : 'dia(s)';
    function tick() {
        tiles.forEach((tile) => { tile.textContent = `${duration(elapsedSince(tile.dataset.liveSince))} dentro`; });
        if (!live) return;
        const seconds = elapsedSince(live.dataset.seconds);
        const period = Number(live.dataset.period);
        const units = Math.max(1, Math.ceil(seconds / period));
        const total = units * Number(live.dataset.rate);
        live.querySelector('[data-live-elapsed]').textContent = duration(seconds, true);
        live.querySelector('[data-live-units]').textContent = `${units} ${unitLabel}`;
        live.querySelector('[data-live-next]').textContent = duration(period - (seconds % period) || period);
        document.querySelectorAll('[data-live-total]').forEach((node) => { node.textContent = `${live.dataset.currency} ${money.format(total / 100)}`; });
        if (expected) expected.value = String(total);
    }
    tick();
    setInterval(tick, 1000);
}

const platePattern = /^(?=(?:(?: |-)*[A-Z0-9]){6,8}$)(?=.*[0-9])[A-Z0-9]+(?:(?: |-)[A-Z0-9]+)*$/;

function plateError(value, required) {
    const plate = value.trim();
    if (!plate) return required ? 'Ingresa la placa del vehiculo.' : '';
    return platePattern.test(plate) ? '' : '6 a 8 letras o numeros, con al menos un numero.';
}

function moneyError(value, required) {
    const amount = value.trim();
    if (!amount) return required ? 'Ingresa el monto.' : '';
    if (!/^\d{1,6}(\.\d{1,2})?$/.test(amount)) return 'Monto de hasta 999,999.99 con maximo 2 decimales.';
    return Number(amount) > 0 ? '' : 'El monto debe ser mayor a cero.';
}

function quantityError(value, required, max) {
    const amount = value.trim();
    if (!amount) return required ? 'Escribe cuantos espacios quieres sumar.' : '';
    const number = Number(amount);
    if (!Number.isInteger(number) || number < 1) return 'Escribe un numero entero desde 1.';
    if (number > max) return max < 1 ? 'Este predio ya llego al maximo de 500 espacios.' : `Puedes sumar maximo ${max} espacios.`;
    return '';
}

function addressError(value) {
    const text = value.trim();
    if (!text) return '';
    if (Array.from(text).length < 5) return 'Escribe al menos 5 caracteres.';
    if (!/\p{L}/u.test(text)) return 'La direccion debe incluir letras.';
    return /^[\p{L}\p{M}\p{N}\s.,#&()'\/-]+$/u.test(text) ? '' : 'Solo letras, numeros y . , # - / ( ).';
}

function sanitizeField(field, kind) {
    const before = field.value;
    let next = before;
    if (kind === 'money') {
        next = next.replace(/,/g, '.').replace(/[^\d.]/g, '');
        const [whole = '', ...rest] = next.split('.');
        next = rest.length ? `${whole.slice(0, 6)}.${rest.join('').slice(0, 2)}` : whole.slice(0, 6);
    } else if (kind === 'quantity') {
        next = next.replace(/\D/g, '').slice(0, 3);
    } else if (kind === 'prefix') {
        next = next.toUpperCase().replace(/[^A-Z]/g, '').slice(0, 2);
    }
    if (next !== before) {
        const position = Math.max(0, (field.selectionStart ?? next.length) - (before.length - next.length));
        field.value = next;
        field.setSelectionRange(position, position);
    }
}

function initEntryChecks(form) {
    if (!form) return;
    form.querySelectorAll('[data-parking-check]').forEach((field) => {
        const hint = field.parentElement.querySelector('.parking-field-check');
        const kind = field.dataset.parkingCheck;
        if (kind === 'plate') initializePlateInput(field);
        const validate = (show = true) => {
            if (kind === 'plate' && field.value !== field.value.toUpperCase()) {
                const [start, end] = [field.selectionStart, field.selectionEnd];
                field.value = field.value.toUpperCase();
                field.setSelectionRange(start, end);
            }
            sanitizeField(field, kind);
            let message;
            if (kind === 'plate') message = plateError(field.value, field.required);
            else if (kind === 'money') {
                message = moneyError(field.value, field.required);
                const other = field.dataset.atLeast && form.querySelector(`[name="${field.dataset.atLeast}"]`);
                if (!message && other && field.value.trim() && Number(field.value) < Number(other.value || 0)) message = 'No puede ser menor que la tarifa por hora.';
            } else if (kind === 'quantity') message = quantityError(field.value, field.required, Number(field.dataset.max ?? 100));
            else if (kind === 'prefix') message = field.value ? '' : 'Escribe una o dos letras.';
            else if (kind === 'address') message = addressError(field.value);
            else message = documentaryTextError(field.value, kind, field.required);
            field.setCustomValidity(message);
            if (!show) return;
            const state = message ? 'invalid' : field.value.trim() ? 'valid' : '';
            field.dataset.state = state;
            field.setAttribute('aria-invalid', String(Boolean(message)));
            hint.dataset.state = state;
            hint.textContent = message || (state ? 'Dato valido.' : '');
            hint.classList.toggle('is-visible', Boolean(state));
        };
        field.addEventListener('input', () => {
            validate();
            form.querySelectorAll(`[data-at-least="${field.name}"]`).forEach((other) => other.dispatchEvent(new Event('recheck')));
        });
        field.addEventListener('recheck', () => validate());
        field.addEventListener('blur', () => validate());
        field.addEventListener('invalid', (event) => { event.preventDefault(); validate(); });
        if (hint?.textContent.trim()) {
            field.dataset.state = 'invalid';
            field.setAttribute('aria-invalid', 'true');
            hint.dataset.state = 'invalid';
            hint.classList.add('is-visible');
        } else validate(field.value.trim() !== '');
    });
    const notes = form.querySelector('[data-parking-counter]');
    const counter = notes?.parentElement.querySelector('.parking-field-counter');
    notes?.addEventListener('input', () => { counter.textContent = `${Array.from(notes.value).length} / ${notes.maxLength}`; });
}

function initParkingSelects() {
    function closeMenus(except = null) {
        document.querySelectorAll('.parking-main .form-select-dropdown.open').forEach((dropdown) => {
            if (dropdown === except) return;
            dropdown.classList.remove('open');
            dropdown.querySelector('.form-select-trigger').setAttribute('aria-expanded', 'false');
        });
    }
    document.querySelectorAll('.parking-main select:not([data-plain-select])').forEach((select, index) => {
        select.classList.add('enhanced-native-select');
        select.setAttribute('aria-hidden', 'true');
        select.tabIndex = -1;
        const dropdown = document.createElement('div');
        dropdown.className = 'form-select-dropdown';
        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'form-select-trigger';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        trigger.setAttribute('aria-label', select.closest('label')?.firstChild.textContent.trim() || 'Seleccionar');
        trigger.innerHTML = '<span></span><svg class="dropdown-caret" viewBox="0 0 20 20" aria-hidden="true"><path d="M5 7.5L10 12.5L15 7.5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        const menu = document.createElement('div');
        menu.className = 'form-select-menu';
        menu.id = `parking-select-menu-${index}`;
        menu.setAttribute('role', 'listbox');
        trigger.setAttribute('aria-controls', menu.id);
        dropdown.append(trigger, menu);
        select.insertAdjacentElement('afterend', dropdown);
        function renderOptions() {
            trigger.querySelector('span').textContent = select.selectedOptions[0]?.textContent || 'Selecciona';
            trigger.disabled = select.disabled;
            menu.replaceChildren();
            Array.from(select.options).forEach((option) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'topbar-dropdown-option';
                button.textContent = option.textContent;
                button.setAttribute('role', 'option');
                button.setAttribute('aria-selected', String(option.selected));
                button.classList.toggle('selected', option.selected);
                button.disabled = option.disabled;
                button.addEventListener('click', () => {
                    select.value = option.value;
                    closeMenus();
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                    trigger.classList.remove('invalid');
                    trigger.focus();
                });
                menu.append(button);
            });
        }
        trigger.addEventListener('click', () => {
            const open = !dropdown.classList.contains('open');
            closeMenus(dropdown);
            dropdown.classList.toggle('open', open);
            trigger.setAttribute('aria-expanded', String(open));
        });
        dropdown.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') { closeMenus(); trigger.focus(); }
            if (!['ArrowDown', 'ArrowUp'].includes(event.key)) return;
            event.preventDefault();
            closeMenus(dropdown);
            dropdown.classList.add('open');
            trigger.setAttribute('aria-expanded', 'true');
            const options = Array.from(menu.querySelectorAll('button:not(:disabled)'));
            const current = options.indexOf(document.activeElement);
            options[(current + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length]?.focus();
        });
        select.addEventListener('invalid', (event) => { event.preventDefault(); trigger.classList.add('invalid'); trigger.focus(); });
        select.addEventListener('change', renderOptions);
        renderOptions();
    });
    document.addEventListener('click', (event) => {
        if (!event.target.closest('.parking-main .form-select-dropdown')) closeMenus();
    });
}

function initScene(stage, select) {
    const data = JSON.parse(stage.dataset.spaces);
    const canvas = stage.querySelector('canvas');
    const tooltip = stage.querySelector('.parking-map-tooltip');
    const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: false });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 1.5));
    renderer.outputColorSpace = THREE.SRGBColorSpace;
    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(40, 1, .1, 1500);
    const controls = new OrbitControls(camera, canvas);
    controls.enablePan = true;
    controls.minPolarAngle = .15;
    controls.maxPolarAngle = Math.PI / 2.2;
    const STALL_W = 4.2;
    const STALL_L = 18;
    const AISLE = 14;
    const CURB = 2.5;
    const bayTotal = Math.max(1, Math.ceil(data.length / 50));
    const perRow = Math.max(4, Math.ceil(data.length / (bayTotal * 2)));
    const rowCount = Math.max(1, Math.ceil(data.length / perRow));
    const bayCount = Math.ceil(rowCount / 2);
    const bayDepth = STALL_L * 2 + AISLE;
    const lotWidth = perRow * STALL_W;
    const lotDepth = bayCount * bayDepth + (bayCount - 1) * CURB;
    const entrance = 16;
    const width = lotWidth + entrance + 8;
    const depth = lotDepth + 8;
    const target = new THREE.Vector3(entrance / 2, 0, 0);
    const light = new THREE.DirectionalLight(0xffffff, 2.2);
    light.position.set(-30, 60, 40);
    scene.add(light, new THREE.HemisphereLight(0xffffff, 0x334148, 1.6));
    const groundMaterial = new THREE.MeshStandardMaterial({ color: 0x9aa6ac, roughness: 1 });
    const ground = new THREE.Mesh(new THREE.BoxGeometry(width, .3, depth), groundMaterial);
    ground.position.set(entrance / 2, -.15, 0);
    scene.add(ground);
    const slots = [];
    const hits = [];
    const color = { available: 0x159c87, occupied: 0x367caf, maintenance: 0xbf8a34 };
    const paint = new THREE.MeshBasicMaterial({ color: 0xf4f7f8 });
    const yellowPaint = new THREE.MeshBasicMaterial({ color: 0xf2c94c });
    const concrete = new THREE.MeshStandardMaterial({ color: 0xc9d2d6, roughness: .9 });
    const dark = new THREE.MeshStandardMaterial({ color: 0x232b31, roughness: .8 });
    function box(parent, w, h, d, x, y, z, material) {
        const mesh = new THREE.Mesh(new THREE.BoxGeometry(w, h, d), material);
        mesh.position.set(x, y, z);
        parent.add(mesh);
        return mesh;
    }
    function textPlane(text, w, h, foreground) {
        const canvasText = document.createElement('canvas');
        canvasText.width = 512; canvasText.height = 256;
        const ctx = canvasText.getContext('2d');
        let size = 120;
        ctx.font = `bold ${size}px Arial`;
        while (ctx.measureText(text).width > 480 && size > 40) { size -= 6; ctx.font = `bold ${size}px Arial`; }
        ctx.fillStyle = foreground; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
        ctx.fillText(text, 256, 132);
        const texture = new THREE.CanvasTexture(canvasText);
        texture.colorSpace = THREE.SRGBColorSpace;
        const mesh = new THREE.Mesh(new THREE.PlaneGeometry(w, h), new THREE.MeshBasicMaterial({ map: texture, transparent: true }));
        mesh.rotation.x = -Math.PI / 2;
        return mesh;
    }
    const hazardCanvas = document.createElement('canvas');
    hazardCanvas.width = 64; hazardCanvas.height = 64;
    const hazardCtx = hazardCanvas.getContext('2d');
    hazardCtx.fillStyle = '#2b2b2b'; hazardCtx.fillRect(0, 0, 64, 64);
    hazardCtx.strokeStyle = '#f2b636'; hazardCtx.lineWidth = 14;
    [-32, 0, 32, 64].forEach((offset) => { hazardCtx.beginPath(); hazardCtx.moveTo(offset, 64); hazardCtx.lineTo(offset + 64, 0); hazardCtx.stroke(); });
    const hazardTexture = new THREE.CanvasTexture(hazardCanvas);
    hazardTexture.wrapS = THREE.RepeatWrapping;
    hazardTexture.wrapT = THREE.RepeatWrapping;
    hazardTexture.repeat.set(2, 8);
    hazardTexture.colorSpace = THREE.SRGBColorSpace;
    const stallLine = new THREE.BoxGeometry(.18, .02, STALL_L);
    const aisleOf = (bay) => -lotDepth / 2 + bay * (bayDepth + CURB) + STALL_L + AISLE / 2;
    for (let bay = 0; bay < bayCount; bay++) {
        const aisleZ = aisleOf(bay);
        for (let x = -lotWidth / 2 - 2; x < lotWidth / 2 + entrance - 2; x += 5) box(scene, 2.4, .02, .22, x + 1.2, .01, aisleZ, yellowPaint);
        const entry = textPlane('ENTRADA ›', 9, 4.5, '#f4f7f8');
        entry.position.set(lotWidth / 2 + entrance / 2 - 1, .02, aisleZ + AISLE / 4);
        scene.add(entry);
        const exit = textPlane('‹ SALIDA', 9, 4.5, '#f4f7f8');
        exit.position.set(lotWidth / 2 + entrance / 2 - 1, .02, aisleZ - AISLE / 4);
        scene.add(exit);
        if (bay > 0) box(scene, lotWidth + 2, .35, CURB, 0, .17, aisleZ - AISLE / 2 - STALL_L - CURB / 2, concrete);
    }
    const boothWall = new THREE.MeshStandardMaterial({ color: 0xeef2f3, roughness: .7 });
    const boothRoof = new THREE.MeshStandardMaterial({ color: 0x0f7f72, roughness: .6 });
    const boothGlass = new THREE.MeshStandardMaterial({ color: 0x5c8ca3, roughness: .2, metalness: .3 });
    const barrierPost = new THREE.MeshStandardMaterial({ color: 0xd94b3d });
    for (let bay = 0; bay < bayCount; bay++) {
        const booth = new THREE.Group();
        booth.position.set(lotWidth / 2 + entrance - 3, 0, aisleOf(bay) - AISLE / 2 - 3);
        box(booth, 4, 3, 4, 0, 1.5, 0, boothWall);
        box(booth, 4.8, .35, 4.8, 0, 3.2, 0, boothRoof);
        box(booth, 3.2, 1.1, .05, 0, 1.9, 2.02, boothGlass);
        box(booth, .5, 1.1, .5, 0, .55, 3.2, barrierPost);
        const barrier = new THREE.Group();
        barrier.position.set(0, 1.2, 3.2);
        for (let i = 0; i < 6; i++) box(barrier, .22, .22, 1.8, 0, 0, .9 + i * 1.8, new THREE.MeshBasicMaterial({ color: i % 2 ? 0xffffff : 0xd94b3d }));
        booth.add(barrier);
        const boothLabel = textPlane(bayCount > 1 ? `CASETA ${bay + 1}` : 'CASETA', 4.4, 2.2, '#f4f7f8');
        boothLabel.position.set(0, .02, -3.3);
        booth.add(boothLabel);
        scene.add(booth);
    }
    const trailerGeometry = createContainerGeometry(2.5, 2.7, 12.4);
    const trailerDetails = createContainerDetailsGeometry(2.5, 2.7, 12.4);
    const wheelGeometry = new THREE.CylinderGeometry(.55, .55, .45, 14);
    function truck(parent, cabColor) {
        const group = new THREE.Group();
        const trailer = new THREE.Mesh(trailerGeometry, new THREE.MeshStandardMaterial({ color: 0xe9eef1, map: corrugatedTexture(), roughness: .6 }));
        trailer.position.set(0, 2.55, -1.9);
        addContainerDetails(trailer, trailerDetails, new THREE.LineBasicMaterial({ color: 0x5b6b73 }));
        group.add(trailer);
        box(group, 1.3, .45, 15.6, 0, .9, -.4, dark);
        const cab = new THREE.MeshStandardMaterial({ color: cabColor, roughness: .45, metalness: .15 });
        box(group, 2.5, 2.3, 2.5, 0, 2.05, 6.15, cab);
        box(group, 2.5, .9, 1.2, 0, 1.25, 7.85, cab);
        box(group, 2.2, .9, .05, 0, 2.6, 7.42, new THREE.MeshStandardMaterial({ color: 0x1b2a33, roughness: .15, metalness: .4 }));
        [-6.9, -5.7, 3.4, 4.5, 7.6].forEach((wheelZ) => [-1.1, 1.1].forEach((wheelX) => {
            const wheel = new THREE.Mesh(wheelGeometry, dark);
            wheel.rotation.z = Math.PI / 2;
            wheel.position.set(wheelX, .55, wheelZ);
            group.add(wheel);
        }));
        parent.add(group);
        return group;
    }
    data.forEach((space, index) => {
        const row = Math.floor(index / perRow);
        const column = index % perRow;
        const bay = Math.floor(row / 2);
        const north = row % 2 === 0;
        const aisleZ = aisleOf(bay);
        const group = new THREE.Group();
        group.position.set(-lotWidth / 2 + (column + .5) * STALL_W, 0, north ? aisleZ - AISLE / 2 - STALL_L / 2 : aisleZ + AISLE / 2 + STALL_L / 2);
        if (!north) group.rotation.y = Math.PI;
        const material = space.state === 'maintenance'
            ? new THREE.MeshBasicMaterial({ map: hazardTexture, color: 0xffffff, transparent: true, opacity: .75 })
            : new THREE.MeshBasicMaterial({ color: color[space.state], transparent: true, opacity: .28 });
        const pad = new THREE.Mesh(new THREE.PlaneGeometry(STALL_W - .5, STALL_L - .5), material);
        pad.rotation.x = -Math.PI / 2;
        pad.position.y = .015;
        group.add(pad);
        box(group, STALL_W - .5, .03, .5, 0, .02, STALL_L / 2 - .5, new THREE.MeshBasicMaterial({ color: color[space.state] }));
        [-STALL_W / 2, STALL_W / 2].forEach((lineX) => { const line = new THREE.Mesh(stallLine, paint); line.position.set(lineX, .02, 0); group.add(line); });
        box(group, STALL_W, .02, .18, 0, .02, -STALL_L / 2, paint);
        box(group, STALL_W - 1.2, .25, .5, 0, .12, -STALL_L / 2 + .9, concrete);
        const label = textPlane(space.code, 4, 2, '#ffffff');
        label.position.set(0, .03, STALL_L / 2 - 2.2);
        label.rotation.z = Math.PI;
        group.add(label);
        if (space.state === 'occupied') truck(group, 0x367caf);
        else if (space.state === 'maintenance') {
            const coneMaterial = new THREE.MeshStandardMaterial({ color: 0xf26b1d, roughness: .6 });
            [-1.2, 0, 1.2].forEach((coneX) => {
                const cone = new THREE.Mesh(new THREE.ConeGeometry(.38, 1.1, 14), coneMaterial);
                cone.position.set(coneX, .55, STALL_L / 2 - 3.8);
                group.add(cone);
                box(group, .7, .08, .7, coneX, .04, STALL_L / 2 - 3.8, coneMaterial);
            });
        }
        group.traverse((node) => { if (node.isMesh) { node.userData.space = space; hits.push(node); } });
        slots.push({ pad, material, space });
        scene.add(group);
    });
    let queued = false;
    function render() {
        if (queued) return;
        queued = true;
        requestAnimationFrame(() => { queued = false; renderer.render(scene, camera); });
    }
    function reset() {
        const extent = Math.max(depth, width / camera.aspect);
        let distance = extent * .7;
        const corners = [-width / 2, width / 2].flatMap((x) => [-depth / 2, depth / 2].flatMap((z) => [0, 4].map((y) => new THREE.Vector3(x + target.x, y, z))));
        for (let attempt = 0; attempt < 30; attempt++) {
            camera.position.set(target.x + distance * .18, distance * .78, distance * .78);
            camera.lookAt(target);
            camera.updateMatrixWorld();
            if (corners.every((corner) => { const p = corner.clone().project(camera); return Math.abs(p.x) < .94 && Math.abs(p.y) < .94; })) break;
            distance *= 1.05;
        }
        controls.target.copy(target);
        controls.maxDistance = distance * 2;
        controls.minDistance = Math.max(4, distance * .2);
        controls.update();
        render();
    }
    function resize() {
        const w = stage.clientWidth, h = stage.clientHeight;
        if (!w || !h) return;
        renderer.setSize(w, h, false);
        camera.aspect = w / h;
        camera.updateProjectionMatrix();
        reset();
    }
    function theme() {
        const dark = document.documentElement.dataset.theme === 'dark';
        scene.background = new THREE.Color(dark ? 0x101c22 : 0xf0f6f8);
        groundMaterial.color.set(dark ? 0x37434a : 0x8f9aa0);
        render();
    }
    controls.addEventListener('change', render);
    stage.addEventListener('selectionchange', () => {
        slots.forEach(({ material, space }) => {
            if (space.state === 'maintenance') return;
            const chosen = Number(select.value) === space.id;
            material.color.set(chosen ? 0xffc347 : color[space.state]);
            material.opacity = chosen ? .85 : .28;
        });
        render();
    });
    const raycaster = new THREE.Raycaster();
    function hit(event) {
        const rect = canvas.getBoundingClientRect();
        raycaster.setFromCamera(new THREE.Vector2((event.clientX - rect.left) / rect.width * 2 - 1, -(event.clientY - rect.top) / rect.height * 2 + 1), camera);
        return raycaster.intersectObjects(hits, false)[0]?.object.userData.space;
    }
    canvas.addEventListener('pointermove', (event) => {
        const space = hit(event);
        tooltip.hidden = !space;
        canvas.style.cursor = space && space.state !== 'maintenance' ? 'pointer' : 'grab';
        if (!space) return;
        tooltip.textContent = `${space.code} · ${space.plate || (space.state === 'maintenance' ? 'Bloqueado' : 'Disponible')}`;
        const rect = stage.getBoundingClientRect();
        tooltip.style.left = `${Math.min(Math.max(8, event.clientX - rect.left + 12), stage.clientWidth - 180)}px`;
        tooltip.style.top = `${Math.min(event.clientY - rect.top + 12, stage.clientHeight - 40)}px`;
    });
    canvas.addEventListener('pointerleave', () => { tooltip.hidden = true; });
    let pointerStart;
    canvas.addEventListener('pointerdown', (event) => { pointerStart = [event.clientX, event.clientY]; });
    canvas.addEventListener('pointerup', (event) => {
        if (!pointerStart || Math.hypot(event.clientX - pointerStart[0], event.clientY - pointerStart[1]) > 6) return;
        const space = hit(event);
        if (space?.url) window.location.href = space.url;
        else if (space?.state === 'available') { select.value = space.id; select.dispatchEvent(new Event('change')); }
    });
    document.querySelector('[data-parking-reset]')?.addEventListener('click', reset);
    const observer = new ResizeObserver(resize); observer.observe(stage);
    const themeObserver = new MutationObserver(theme);
    themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    resize(); theme();
    stage.dispatchEvent(new Event('selectionchange'));
    window.addEventListener('pagehide', () => {
        observer.disconnect(); themeObserver.disconnect(); controls.dispose(); renderer.dispose();
        scene.traverse((node) => {
            node.geometry?.dispose();
            if (node.material) { node.material.map?.dispose(); node.material.dispose(); }
        });
    }, { once: true });
}

document.addEventListener('DOMContentLoaded', () => {
    initParking();
    if (!document.body.classList.contains('parking-body')) return;
    const smallScreen = window.matchMedia('(max-width: 980px)');
    smallScreen.addEventListener('change', initParking);
});
