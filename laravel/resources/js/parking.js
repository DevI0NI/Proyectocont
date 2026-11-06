import * as THREE from 'three';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import { documentaryTextError } from './documentary-validation.js';
import { initializePlateInput } from './transport-plate-input.js';
import { createElement, ArrowLeft, Settings, Sun, Truck, RotateCcw, Search, Printer, Monitor } from 'lucide';

const icons = { 'arrow-left': ArrowLeft, settings: Settings, sun: Sun, truck: Truck, 'rotate-ccw': RotateCcw, search: Search, printer: Printer, monitor: Monitor };
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
    document.querySelector('[data-dialog-close]')?.addEventListener('click', () => dialog.close());
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
    select?.addEventListener('change', syncSelection);
    if (select) syncSelection();
    initEntryChecks(document.querySelector('#parking-entry-form'));
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
            const message = kind === 'plate' ? plateError(field.value, field.required) : documentaryTextError(field.value, kind, field.required);
            field.setCustomValidity(message);
            if (!show) return;
            const state = message ? 'invalid' : field.value.trim() ? 'valid' : '';
            field.dataset.state = state;
            field.setAttribute('aria-invalid', String(Boolean(message)));
            hint.dataset.state = state;
            hint.textContent = message || (state ? 'Dato valido.' : '');
            hint.classList.toggle('is-visible', Boolean(state));
        };
        field.addEventListener('input', () => validate());
        field.addEventListener('blur', () => validate());
        field.addEventListener('invalid', (event) => { event.preventDefault(); validate(); });
        validate(field.value.trim() !== '');
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
    const columns = Math.min(8, Math.max(1, data.length));
    const rows = Math.max(1, Math.ceil(data.length / columns));
    const width = columns * 2.5 + 4;
    const depth = rows * 5.8 + 4;
    const target = new THREE.Vector3(0, 0, 0);
    const light = new THREE.DirectionalLight(0xffffff, 2.4);
    light.position.set(-8, 15, 10);
    scene.add(light, new THREE.AmbientLight(0xffffff, 2));
    const groundMaterial = new THREE.MeshStandardMaterial({ color: 0xd9e4e8, roughness: 1 });
    const ground = new THREE.Mesh(new THREE.BoxGeometry(width, .15, depth), groundMaterial);
    ground.position.y = -.15;
    scene.add(ground);
    const slots = [];
    const hits = [];
    const color = { available: 0x159c87, occupied: 0x367caf, maintenance: 0xbf8a34 };
    function box(group, w, h, d, x, y, z, material) {
        const mesh = new THREE.Mesh(new THREE.BoxGeometry(w, h, d), material);
        mesh.position.set(x, y, z);
        group.add(mesh);
        return mesh;
    }
    data.forEach((space, index) => {
        const x = (index % columns - (columns - 1) / 2) * 2.5;
        const z = (Math.floor(index / columns) - (rows - 1) / 2) * 5.8;
        const group = new THREE.Group();
        group.position.set(x, 0, z);
        const material = new THREE.MeshStandardMaterial({ color: color[space.state], roughness: .85 });
        const pad = box(group, 2.15, .06, 4.6, 0, 0, 0, material);
        const edges = new THREE.LineSegments(new THREE.EdgesGeometry(new THREE.BoxGeometry(2.15, .07, 4.6)), new THREE.LineBasicMaterial({ color: 0xffffff }));
        edges.position.y = .03;
        group.add(edges);
        if (space.state === 'occupied') {
            const trailer = new THREE.MeshStandardMaterial({ color: 0xe8edf0, roughness: .65 });
            const cab = new THREE.MeshStandardMaterial({ color: 0x347faf, roughness: .65 });
            const dark = new THREE.MeshStandardMaterial({ color: 0x29333c });
            box(group, 1.4, 1.25, 2.65, 0, .95, -.6, trailer);
            box(group, 1.35, 1.05, 1.1, 0, .82, 1.32, cab);
            box(group, 1.1, .4, .04, 0, 1.05, 1.89, dark);
            [-1.4, .25, 1.4].forEach((wheelZ) => [-.72, .72].forEach((wheelX) => {
                const wheel = new THREE.Mesh(new THREE.CylinderGeometry(.26, .26, .16, 10), dark);
                wheel.rotation.z = Math.PI / 2;
                wheel.position.set(wheelX, .3, wheelZ);
                group.add(wheel);
            }));
        } else if (space.state === 'maintenance') {
            box(group, 1.8, .16, .25, 0, .3, 0, new THREE.MeshStandardMaterial({ color: 0xf1bf59 }));
        }
        const labelCanvas = document.createElement('canvas');
        labelCanvas.width = 256; labelCanvas.height = 96;
        const ctx = labelCanvas.getContext('2d');
        ctx.fillStyle = '#172b34'; ctx.fillRect(0, 0, 256, 96);
        ctx.fillStyle = '#ffffff'; ctx.font = 'bold 40px Arial'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
        ctx.fillText(space.code, 128, 48);
        const label = new THREE.Sprite(new THREE.SpriteMaterial({ map: new THREE.CanvasTexture(labelCanvas), depthTest: false }));
        label.position.set(0, space.state === 'occupied' ? 2.1 : .55, 0);
        label.scale.set(1.25, .47, 1);
        group.add(label);
        group.traverse((node) => { if (node.isMesh || node.isSprite) { node.userData.space = space; hits.push(node); } });
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
        let distance = extent * 1.35;
        const corners = [-width / 2, width / 2].flatMap((x) => [-depth / 2, depth / 2].flatMap((z) => [0, 2.5].map((y) => new THREE.Vector3(x, y, z))));
        for (let attempt = 0; attempt < 30; attempt++) {
            camera.position.set(distance * .48, distance * .82, distance * .85);
            camera.lookAt(target);
            camera.updateMatrixWorld();
            if (corners.every((corner) => { const p = corner.clone().project(camera); return Math.abs(p.x) < .92 && Math.abs(p.y) < .92; })) break;
            distance *= 1.08;
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
        groundMaterial.color.set(dark ? 0x2c424c : 0xd9e4e8);
        render();
    }
    controls.addEventListener('change', render);
    stage.addEventListener('selectionchange', () => {
        slots.forEach(({ material, space }) => { material.color.set(Number(select.value) === space.id ? 0xffc347 : color[space.state]); });
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
