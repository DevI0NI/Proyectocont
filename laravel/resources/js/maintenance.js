import { ChevronLeft, ChevronRight, createElement, Eye, ImagePlus, Trash2, X } from 'lucide';
import { initializeRecordSelects } from './branch-geography';
import { initializeMaintenancePricing } from './maintenance-pricing';

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-maintenance-text]').forEach((text) => {
        const count = text.closest('.mw-field').querySelector('[data-maintenance-text-count]');
        text.addEventListener('input', () => { count.textContent = String(Array.from(text.value).length); });
    });
    const dialog = document.querySelector('[data-maintenance-summary]');
    if (dialog) {
        let request;
        let trigger;
        let openingFrame;
        let closing;
        const next = dialog.querySelector('[data-damage-continue]');
        const error = dialog.querySelector('[data-damage-error]');
        const details = dialog.querySelector('.mw-damage-details');
        const text = (key, value) => { dialog.querySelector(`[data-damage-${key}]`).textContent = value; };
        const openDialog = () => {
            document.querySelectorAll('.row-options.open').forEach((menu) => {
                menu.classList.remove('open');
                menu.querySelector('.options-trigger')?.setAttribute('aria-expanded', 'false');
            });
            dialog.classList.remove('is-visible');
            dialog.showModal();
            // Commit the initial style before starting the entrance transition.
            getComputedStyle(dialog).opacity;
            openingFrame = requestAnimationFrame(() => dialog.classList.add('is-visible'));
        };
        const closeDialog = () => {
            if (closing) return closing;
            if (!dialog.open) return Promise.resolve();
            cancelAnimationFrame(openingFrame);
            request?.abort();
            closing = new Promise((resolve) => {
                let timer;
                const finish = () => {
                    clearTimeout(timer);
                    dialog.removeEventListener('transitionend', onEnd);
                    dialog.close();
                    closing = null;
                    resolve();
                };
                const onEnd = (event) => { if (event.target === dialog && event.propertyName === 'opacity') finish(); };
                dialog.addEventListener('transitionend', onEnd);
                dialog.classList.remove('is-visible');
                timer = setTimeout(finish, 320);
            });
            return closing;
        };
        dialog.querySelector('[data-damage-cancel]').addEventListener('click', closeDialog);
        dialog.addEventListener('cancel', (event) => { event.preventDefault(); closeDialog(); });
        next.addEventListener('click', async (event) => {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0 || !next.href) return;
            event.preventDefault();
            const destination = next.href;
            await closeDialog();
            location.assign(destination);
        });
        dialog.addEventListener('close', () => { cancelAnimationFrame(openingFrame); dialog.classList.remove('is-visible'); request?.abort(); trigger?.focus(); });
        dialog.addEventListener('click', (event) => { if (event.target === dialog) {
            const bounds = dialog.getBoundingClientRect();
            if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) closeDialog();
        } });
        document.addEventListener('click', async (event) => {
            const link = event.target.closest('a[href]');
            if (!link || link.closest('[data-maintenance-summary]') || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
            const url = new URL(link.href);
            if (url.origin !== location.origin || url.pathname !== new URL(dialog.dataset.createUrl, location.href).pathname) return;
            event.preventDefault();
            if (link.getAttribute('aria-busy') === 'true') return;
            request?.abort();
            const controller = new AbortController();
            request = controller;
            trigger = link.closest('.row-options')?.querySelector('.options-trigger') || link;
            link.setAttribute('aria-busy', 'true');
            next.hidden = true;
            next.removeAttribute('href');
            error.hidden = true;
            details.hidden = true;
            text('location', '');
            try {
                const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: controller.signal, cache: 'no-store' });
                if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) throw new Error();
                const data = await response.json();
                if (controller.signal.aborted) return;
                text('name', data.name);
                text('location', data.location);
                text('unit-status', data.unit_status || 'Sin definir');
                text('box-status', data.box_status || 'Sin definir');
                text('unit-notes', data.unit_notes || 'Sin observaciones registradas.');
                text('box-notes', data.box_notes || 'Sin observaciones registradas.');
                details.hidden = false;
                next.href = data.evaluation_url;
                next.hidden = false;
                openDialog();
            } catch (failure) {
                if (failure.name === 'AbortError') return;
                text('name', 'No se pudo abrir la reparacion');
                error.textContent = 'El contenedor puede haber cambiado de estado o tener un trabajo abierto. Actualiza el inventario e intenta de nuevo.';
                error.hidden = false;
                openDialog();
            } finally {
                link.removeAttribute('aria-busy');
            }
        });
    }
    let viewerTrigger;
    let viewerClosing = false;
    document.querySelectorAll('[data-maintenance-assessment], [data-maintenance-work-photos]').forEach((scope) => {
        const form = scope.closest('form');
        const isAssessment = scope.hasAttribute('data-maintenance-assessment');
        const input = scope.querySelector('[data-initial-photos]');
        const previews = scope.querySelector('[data-initial-photo-previews]');
        const diagnosis = scope.querySelector('[name="diagnosis"]');
        const add = scope.querySelector('[data-initial-photo-add]');
        const error = scope.querySelector('[data-initial-photo-error]');
        const diagnosisError = scope.querySelector('[data-assessment-diagnosis-error]');
        const messageTimers = new Map();
        const hideMessage = (element) => {
            clearTimeout(messageTimers.get(element));
            element.hidden = true;
            element.classList.remove('is-leaving');
        };
        const dismissMessage = (element) => {
            clearTimeout(messageTimers.get(element));
            element.hidden = false;
            element.classList.remove('is-leaving');
            messageTimers.set(element, setTimeout(() => {
                element.classList.add('is-leaving');
                messageTimers.set(element, setTimeout(() => hideMessage(element), 160));
            }, 4000));
        };
        const showMessage = (element, message) => {
            element.textContent = message;
            if (message) dismissMessage(element);
            else hideMessage(element);
        };
        const serverError = form.closest('.mw-assessment-board')?.querySelector('[data-assessment-server-errors]');
        if (serverError) dismissMessage(serverError);
        const viewer = document.querySelector('[data-initial-photo-viewer]');
        const viewerImage = viewer.querySelector('[data-photo-viewer-image]');
        const viewerName = viewer.querySelector('[data-photo-viewer-name]');
        const photoIcons = { add: ImagePlus, close: X, view: Eye, previous: ChevronLeft, next: ChevronRight };
        document.querySelectorAll('[data-maintenance-photo-icon]').forEach((slot) => slot.replaceChildren(createElement(photoIcons[slot.dataset.maintenancePhotoIcon] || Eye, { width: 18, height: 18 })));
        const existingCount = Number(input.dataset.existingCount || 0);
        let photos = [];
        let checkingPhotos = false;
        const carousel = scope.querySelector('[data-photo-carousel]');
        const previousPhoto = carousel.querySelector('[data-photo-previous]');
        const nextPhoto = carousel.querySelector('[data-photo-next]');
        let activePhoto = 0;
        const refreshCarousel = (animate = false) => {
            const slides = [...carousel.querySelectorAll('figure')];
            activePhoto = Math.max(0, Math.min(activePhoto, slides.length - 1));
            slides.forEach((slide, index) => {
                slide.hidden = index !== activePhoto;
                slide.setAttribute('role', 'group');
                slide.setAttribute('aria-label', `Foto ${index + 1} de ${slides.length}`);
            });
            carousel.hidden = slides.length === 0 && !carousel.querySelector('.mw-attachment');
            carousel.querySelector('[data-photo-navigation]').hidden = slides.length < 2;
            carousel.querySelector('[data-photo-position]').textContent = slides.length ? `${activePhoto + 1} / ${slides.length}` : '';
            previousPhoto.disabled = activePhoto === 0;
            nextPhoto.disabled = activePhoto === slides.length - 1;
            if (animate && slides[activePhoto]) slides[activePhoto].animate([{ opacity: 0 }, { opacity: 1 }], { duration: 160 });
        };
        const movePhoto = (direction) => {
            const total = carousel.querySelectorAll('figure').length;
            const target = Math.max(0, Math.min(activePhoto + direction, total - 1));
            if (target === activePhoto) return;
            activePhoto = target;
            refreshCarousel(true);
        };
        previousPhoto.addEventListener('click', () => movePhoto(-1));
        nextPhoto.addEventListener('click', () => movePhoto(1));
        carousel.addEventListener('keydown', (event) => {
            if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
            event.preventDefault();
            movePhoto(event.key === 'ArrowLeft' ? -1 : 1);
        });
        refreshCarousel();
        const closeViewer = async () => {
            if (!viewer.open || viewerClosing) return;
            viewerClosing = true;
            const animation = viewer.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 160, fill: 'forwards' });
            await animation.finished.catch(() => {});
            viewer.close();
            animation.cancel();
            viewerClosing = false;
        };
        if (!viewer.dataset.initialized) {
        viewer.dataset.initialized = 'true';
        viewer.querySelector('[data-photo-viewer-close]').addEventListener('click', closeViewer);
        viewer.addEventListener('cancel', (event) => { event.preventDefault(); closeViewer(); });
        viewer.addEventListener('click', (event) => {
            if (event.target !== viewer) return;
            const rect = viewer.getBoundingClientRect();
            if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) closeViewer();
        });
        viewer.addEventListener('close', () => { viewerImage.removeAttribute('src'); viewerTrigger?.focus(); });
        }
        const openPhoto = (url, name, trigger) => {
            viewerTrigger = trigger;
            viewerImage.src = url;
            viewerImage.alt = name;
            viewerName.textContent = name;
            viewer.showModal();
            viewer.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 160 });
        };
        scope.querySelectorAll('[data-saved-photo-view]').forEach((button) => button.addEventListener('click', () => openPhoto(button.dataset.src, button.dataset.name, button)));
        const syncFiles = () => {
            const transfer = new DataTransfer();
            photos.forEach((photo) => transfer.items.add(photo.file));
            input.files = transfer.files;
            add.disabled = input.disabled || existingCount + photos.length >= 5;
            form.dispatchEvent(new Event('maintenance:changed'));
        };
        const showError = (message = '') => showMessage(error, message);
        const render = () => {
            previews.replaceChildren();
            photos.forEach((photo, index) => {
                const { file, url } = photo;
                const figure = document.createElement('figure');
                const image = document.createElement('img');
                const caption = document.createElement('figcaption');
                const actions = document.createElement('div');
                const view = document.createElement('button');
                const remove = document.createElement('button');
                actions.className = 'mw-photo-actions';
                image.src = url;
                image.alt = `Foto de evidencia ${index + 1}`;
                caption.textContent = file.name;
                caption.title = file.name;
                [[view, Eye, 'Ver'], [remove, Trash2, 'Eliminar']].forEach(([button, icon, label]) => {
                    button.type = 'button';
                    button.className = 'mw-button';
                    button.setAttribute('aria-label', `${label} ${file.name}`);
                    button.title = `${label} fotografía`;
                    button.append(createElement(icon, { width: 15, height: 15, 'aria-hidden': 'true' }), document.createTextNode(label));
                });
                view.addEventListener('click', () => openPhoto(url, file.name, view));
                remove.addEventListener('click', () => {
                    URL.revokeObjectURL(url);
                    photos = photos.filter((item) => item !== photo);
                    syncFiles();
                    showError();
                    render();
                    add.focus();
                });
                actions.append(view, remove);
                figure.append(image, caption, actions);
                previews.append(figure);
            });
            refreshCarousel();
        };
        add.addEventListener('click', () => input.click());
        input.addEventListener('invalid', (event) => {
            event.preventDefault();
            input.setAttribute('aria-invalid', 'true');
            showError('Adjunta al menos una foto de evidencia.');
        });
        input.addEventListener('change', async () => {
            if (checkingPhotos) return;
            const files = [...input.files];
            const invalid = files.some((file) => !['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024);
            if (invalid || files.length > 5) {
                showError(invalid ? 'Usa fotos JPG, PNG o WEBP de hasta 2 MB.' : 'Selecciona hasta 5 fotos a la vez.');
                syncFiles();
                return;
            }
            checkingPhotos = true;
            const controls = [add, ...form.querySelectorAll('[type="submit"]'), ...previews.querySelectorAll('button')];
            controls.forEach((control) => { control.disabled = true; });
            input.setAttribute('aria-busy', 'true');
            try {
                const additions = [];
                const fingerprints = new Set(photos.map((photo) => photo.fingerprint).filter(Boolean));
                let duplicates = false;
                for (const file of files) {
                    const fingerprint = globalThis.crypto?.subtle
                        ? [...new Uint8Array(await crypto.subtle.digest('SHA-256', await file.arrayBuffer()))].map((byte) => byte.toString(16).padStart(2, '0')).join('')
                        : null;
                    const repeated = fingerprint ? fingerprints.has(fingerprint)
                        : [...photos, ...additions].some((photo) => photo.file.name === file.name && photo.file.size === file.size && photo.file.lastModified === file.lastModified);
                    if (repeated) { duplicates = true; continue; }
                    if (fingerprint) fingerprints.add(fingerprint);
                    additions.push({ file, fingerprint });
                }
                if (existingCount + photos.length + additions.length > 5) {
                    showError('Puedes adjuntar hasta 5 fotos en esta etapa. Elimina una foto nueva para agregar otra.');
                    syncFiles();
                    return;
                }
                if (additions.length) activePhoto = carousel.querySelector('.mw-saved-photos').querySelectorAll('figure').length + photos.length;
                photos.push(...additions.map((photo) => ({ ...photo, url: URL.createObjectURL(photo.file) })));
                syncFiles();
                input.removeAttribute('aria-invalid');
                showError(duplicates ? 'Se omitieron fotos repetidas. Adjunta imágenes diferentes.' : '');
                render();
            } catch {
                syncFiles();
                showError('No se pudo comprobar una fotografía. Selecciónala nuevamente.');
            } finally {
                checkingPhotos = false;
                controls.forEach((control) => { control.disabled = false; });
                add.disabled = input.disabled || existingCount + photos.length >= 5;
                input.removeAttribute('aria-busy');
            }
        });
        const validateDiagnosis = () => diagnosis.setCustomValidity(Array.from(diagnosis.value.trim()).length < 30 ? 'Describe el diagnóstico con al menos 30 caracteres.' : '');
        diagnosis?.addEventListener('invalid', (event) => {
            event.preventDefault();
            diagnosis.setAttribute('aria-invalid', 'true');
            showMessage(diagnosisError, diagnosis.validationMessage);
        });
        diagnosis?.addEventListener('input', () => {
            validateDiagnosis();
            if (diagnosis.validity.valid) {
                diagnosis.removeAttribute('aria-invalid');
                hideMessage(diagnosisError);
            }
        });
        form.addEventListener('submit', (event) => {
            if (checkingPhotos) { event.preventDefault(); return; }
            if (!isAssessment) return;
            validateDiagnosis();
            if (!form.checkValidity()) {
                event.preventDefault();
                const invalid = form.querySelector(':invalid');
                (invalid === input ? add : invalid)?.focus();
                return;
            }
            form.querySelector('[type="submit"]').disabled = true;
        });
        window.addEventListener('pagehide', () => {
            photos.forEach((photo) => URL.revokeObjectURL(photo.url));
            messageTimers.forEach((timer) => clearTimeout(timer));
        });
    });
    document.querySelectorAll('[data-maintenance-form]').forEach((form) => {
        initializeMaintenancePricing(form);
        const tabs = [...form.querySelectorAll('[data-maintenance-tab]')];
        const panels = [...form.querySelectorAll('[data-maintenance-panel]')];
        const previous = form.querySelector('[data-maintenance-previous]');
        const next = form.querySelector('[data-maintenance-next]');
        const submit = form.querySelector('[data-maintenance-submit]');
        const type = form.querySelector('[name="repair_service_id"]');
        const work = form.querySelector('[name="work_performed"]');
        const photos = form.querySelector('[name="evidence_after[]"]');
        const diagnosis = form.querySelector('[name="diagnosis"]');
        const beforePhotos = form.querySelector('[name="evidence_before[]"]');
        const save = form.querySelector('[data-maintenance-save]');
        const exitDialog = document.querySelector('[data-maintenance-exit]');
        const leave = exitDialog.querySelector('[data-maintenance-leave]');
        const fingerprint = () => JSON.stringify([...new FormData(form)].filter(([name, value]) =>
            !['_token', '_method', 'version', 'active_step', 'action'].includes(name)
            && !(value instanceof File && !value.name)
        ).map(([name, value]) => [name, value instanceof File ? [value.name, value.size, value.lastModified] : value]));
        const baseline = fingerprint();
        let dirty = false;
        let submitting = false;
        let exitTrigger;
        let exitOpeningFrame;
        let exitClosing;
        const warnBeforeUnload = (event) => {
            if (!dirty || submitting) return;
            event.preventDefault();
            event.returnValue = true;
        };
        const openExitDialog = () => {
            if (exitDialog.open || exitClosing) return;
            exitDialog.classList.remove('is-visible');
            exitDialog.showModal();
            getComputedStyle(exitDialog).opacity;
            exitOpeningFrame = requestAnimationFrame(() => exitDialog.classList.add('is-visible'));
        };
        const closeExitDialog = () => {
            if (exitClosing) return exitClosing;
            if (!exitDialog.open) return Promise.resolve();
            cancelAnimationFrame(exitOpeningFrame);
            exitClosing = new Promise((resolve) => {
                let timer;
                const finish = () => {
                    clearTimeout(timer);
                    exitDialog.removeEventListener('transitionend', onEnd);
                    exitDialog.close();
                    exitClosing = null;
                    resolve();
                };
                const onEnd = (event) => {
                    if (event.target === exitDialog && event.propertyName === 'opacity') finish();
                };
                exitDialog.addEventListener('transitionend', onEnd);
                exitDialog.classList.remove('is-visible');
                timer = setTimeout(finish, 320);
            });
            return exitClosing;
        };
        const updateDirty = () => {
            dirty = Boolean(save) && (form.dataset.restoredChanges === 'true' || fingerprint() !== baseline);
            if (save) save.hidden = !dirty;
            window.removeEventListener('beforeunload', warnBeforeUnload);
            if (dirty) window.addEventListener('beforeunload', warnBeforeUnload);
        };
        form.addEventListener('input', updateDirty);
        form.addEventListener('change', updateDirty);
        form.addEventListener('maintenance:changed', updateDirty);
        form.addEventListener('technical:submission-failed', () => { submitting = false; updateDirty(); });
        form.addEventListener('unsaved:submit', () => { submitting = true; });
        document.addEventListener('click', (event) => {
            const link = event.target.closest('a[href]');
            if (!dirty || submitting || !link || link === leave || link.hasAttribute('download') || link.target === '_blank'
                || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
            const url = new URL(link.href, location.href);
            if (!['http:', 'https:'].includes(url.protocol)) return;
            event.preventDefault();
            leave.href = link.href;
            exitTrigger = link;
            openExitDialog();
        });
        exitDialog.querySelector('[data-maintenance-stay]').addEventListener('click', closeExitDialog);
        exitDialog.addEventListener('cancel', (event) => { event.preventDefault(); closeExitDialog(); });
        exitDialog.addEventListener('click', (event) => {
            if (event.target !== exitDialog) return;
            const rect = exitDialog.getBoundingClientRect();
            if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) closeExitDialog();
        });
        exitDialog.addEventListener('close', () => exitTrigger?.focus());
        leave.addEventListener('click', async (event) => {
            event.preventDefault();
            if (submitting) return;
            const destination = leave.href;
            await closeExitDialog();
            submitting = true;
            location.assign(destination);
        });
        window.addEventListener('pageshow', (event) => {
            if (!event.persisted) return;
            submitting = false;
            updateDirty();
        });
        let current = Number(form.dataset.initialStep || 1);
        const select = (index, focus = false) => {
            current = index;
            tabs.forEach((tab, i) => {
                tab.setAttribute('aria-selected', String(i === index));
                tab.tabIndex = i === index ? 0 : -1;
                tab.classList.toggle('active', i === index);
                tab.classList.toggle('completed', i < index);
            });
            panels.forEach((panel, i) => { panel.hidden = i !== index; });
            previous.hidden = index === 0;
            next.hidden = index === panels.length - 1;
            if (submit) submit.hidden = index !== panels.length - 1;
            form.querySelector('[name="active_step"]').value = index;
            if (focus) tabs[index].focus();
        };
        const validateRepair = (required) => {
            type.required = required;
            type.setCustomValidity(required && !type.value.trim() ? 'Indica el tipo de reparación.' : '');
        };
        previous.addEventListener('click', () => select(Math.max(0, current - 1), true));
        next.addEventListener('click', () => select(Math.min(panels.length - 1, current + 1), true));
        tabs.forEach((tab, index) => {
            tab.addEventListener('click', () => select(index));
            tab.addEventListener('keydown', (event) => {
                const next = event.key === 'ArrowRight' ? (index + 1) % tabs.length
                    : event.key === 'ArrowLeft' ? (index + tabs.length - 1) % tabs.length
                    : event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : null;
                if (next !== null) { event.preventDefault(); select(next, true); }
            });
        });
        form.addEventListener('invalid', (event) => {
            const panel = event.target.closest('[data-maintenance-panel]');
            if (panel) select(Number(panel.dataset.maintenancePanel));
        }, true);
        type.addEventListener('input', () => type.setCustomValidity(''));
        type.addEventListener('change', () => type.setCustomValidity(''));
        diagnosis.addEventListener('input', () => diagnosis.setCustomValidity(''));
        work.addEventListener('input', () => work.setCustomValidity(''));
        form.addEventListener('submit', (event) => {
            if (event.defaultPrevented) return;
            const final = event.submitter?.value === 'submit';
            if (!diagnosis.disabled) {
                diagnosis.required = final;
                diagnosis.setCustomValidity(final && Array.from(diagnosis.value.trim()).length < 30 ? 'Describe el diagnóstico con al menos 30 caracteres.' : '');
                beforePhotos.required = final && Number(beforePhotos.dataset.existingCount || 0) === 0;
            }
            validateRepair(final);
            work.required = final;
            work.setCustomValidity(final && Array.from(work.value.trim()).length < 30 ? 'Describe el trabajo realizado con al menos 30 caracteres.' : '');
            photos.required = final && Number(photos.dataset.existingCount || 0) === 0;
            if (!form.checkValidity()) {
                event.preventDefault();
                const invalid = form.querySelector(':invalid');
                const panel = invalid?.closest('[data-maintenance-panel]');
                if (panel) select(Number(panel.dataset.maintenancePanel));
                if (invalid?.matches('[data-initial-photos]')) invalid.closest('[data-maintenance-work-photos]').querySelector('[data-initial-photo-add]').focus();
                else if (invalid === type) type.parentElement.querySelector('.form-select-trigger')?.focus();
                else invalid?.reportValidity();
                return;
            }
            submitting = true;
        });
        const list = form.querySelector('[data-material-list]');
        const refresh = () => {
            form.querySelector('.mw-material-empty').hidden = Boolean(list.children.length);
            form.querySelector('[data-material-head]').hidden = !list.children.length;
            list.querySelectorAll('[data-maintenance-remove-icon]:empty').forEach((icon) => icon.append(createElement(Trash2, { width: 18, height: 18 })));
            initializeRecordSelects();
            form.dispatchEvent(new Event('maintenance:materials-changed'));
        };
        let nextIndex = Math.max(-1, ...[...list.querySelectorAll('select')].map((input) => Number(input.name.match(/\[(\d+)\]/)?.[1] ?? -1))) + 1;
        form.querySelector('[data-material-add]')?.addEventListener('click', () => {
            if (list.children.length >= 40) return;
            const html = form.querySelector('[data-material-template]').innerHTML.replaceAll('__INDEX__', String(nextIndex++));
            list.insertAdjacentHTML('beforeend', html);
            refresh();
            updateDirty();
            (list.lastElementChild.querySelector('.form-select-trigger') || list.lastElementChild.querySelector('select')).focus();
        });
        list.addEventListener('click', (event) => {
            const remove = event.target.closest('[data-material-remove]');
            if (remove) { remove.closest('[data-material-row]').remove(); refresh(); updateDirty(); }
        });
        refresh();
        select(current);
        updateDirty();
    });
});
