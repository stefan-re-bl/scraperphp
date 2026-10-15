(() => {
    const toast = message => {
        const target = document.querySelector('[data-toast]');
        if (!target) return;
        target.textContent = message;
        target.hidden = false;
        clearTimeout(target.hideTimer);
        target.hideTimer = setTimeout(() => { target.hidden = true; }, 4000);
    };
    const sidebar = document.querySelector('#app-sidebar');
    const navToggle = document.querySelector('[data-nav-toggle]');
    const navBackdrop = document.querySelector('.nav-backdrop');
    const appBody = document.querySelector('.app-body');
    const updateBodyLock = () => { document.body.style.overflow = sidebar?.classList.contains('is-open') || document.querySelector('dialog[open]') ? 'hidden' : ''; };
    const updateSidebarVisibility = () => { const hidden = window.innerWidth <= 900 && !sidebar?.classList.contains('is-open'); if (sidebar) { sidebar.inert = hidden; sidebar.setAttribute('aria-hidden', String(hidden)); } };
    const closeNavigation = (restoreFocus = true) => {
        sidebar?.classList.remove('is-open');
        navToggle?.setAttribute('aria-expanded', 'false');
        if (navBackdrop) navBackdrop.hidden = true;
        if (appBody) appBody.inert = false;
        updateSidebarVisibility(); updateBodyLock();
        if (restoreFocus) navToggle?.focus();
    };
    navToggle?.addEventListener('click', () => {
        const opening = !sidebar.classList.contains('is-open');
        if (!opening) { closeNavigation(); return; }
        sidebar.classList.add('is-open'); sidebar.inert = false; sidebar.setAttribute('aria-hidden','false'); navToggle.setAttribute('aria-expanded', 'true');
        navBackdrop.hidden = false; appBody.inert = true; document.body.style.overflow = 'hidden';
        sidebar.querySelector('a')?.focus();
    });
    document.querySelectorAll('[data-nav-close]').forEach(button => button.addEventListener('click', () => closeNavigation()));
    sidebar?.querySelectorAll('a').forEach(link => link.addEventListener('click', () => closeNavigation(false)));
    window.addEventListener('resize', () => { if (window.innerWidth > 900) closeNavigation(false); else updateSidebarVisibility(); });
    updateSidebarVisibility();
    const openDrawer = id => {
        const dialog = document.getElementById(id);
        if (!dialog?.matches('dialog') || dialog.open) return;
        dialog.showModal(); updateBodyLock();
        dialog.querySelector('[autofocus], form input:not([type="hidden"]):not([readonly]):not([disabled]), form select:not([disabled]), form textarea:not([disabled])')?.focus({preventScroll:true});
    };
    document.addEventListener('close', event => { if (event.target.matches('[data-drawer]')) updateBodyLock(); }, true);
    document.addEventListener('click', event => {
        const opener = event.target.closest('[data-drawer-open]');
        if (opener) openDrawer(opener.dataset.drawerOpen);
        const closer = event.target.closest('[data-drawer-close]');
        if (closer) closer.closest('dialog')?.close();
        const dialog = event.target.closest('[data-drawer]');
        if (event.target === dialog) {
            const box = dialog.getBoundingClientRect();
            if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) dialog.close();
        }
    });
    if (new URLSearchParams(location.search).get('create') === '1') {
        const createDialog = document.querySelector('dialog:has([data-create-form])');
        if (createDialog) openDrawer(createDialog.id);
    }
    document.querySelectorAll('[data-notice-dismiss]').forEach(button => button.addEventListener('click', () => button.closest('.app-notice').remove()));
    const actionMenus = [];
    const positionMenu = menu => {
        const list = menu.querySelector('.action-menu-list');
        if (!list || !menu.open) return;
        const anchor = menu.querySelector('summary').getBoundingClientRect();
        const width = Math.max(165, list.getBoundingClientRect().width);
        const height = list.getBoundingClientRect().height;
        list.style.right = 'auto';
        list.style.left = Math.max(12, Math.min(anchor.right - width, window.innerWidth - width - 12)) + 'px';
        list.style.top = Math.max(12, anchor.bottom + height + 8 > window.innerHeight ? anchor.top - height - 6 : anchor.bottom + 6) + 'px';
    };
    const initializeMenus = root => root.querySelectorAll('.action-menu').forEach(menu => {
        if (actionMenus.includes(menu)) return;
        actionMenus.push(menu);
        menu.addEventListener('toggle', () => {
            if (!menu.open) return;
            actionMenus.forEach(other => { if (other !== menu) other.open = false; });
            positionMenu(menu);
        });
    });
    initializeMenus(document);
    document.addEventListener('click', event => actionMenus.forEach(menu => { if (!menu.contains(event.target)) menu.open = false; }));
    document.addEventListener('scroll', () => actionMenus.forEach(positionMenu), true);
    window.addEventListener('resize', () => actionMenus.forEach(positionMenu));
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            const activeMenu = actionMenus.find(menu => menu.open);
            if (activeMenu) { event.preventDefault(); activeMenu.open = false; activeMenu.querySelector('summary')?.focus(); return; }
            if (sidebar?.classList.contains('is-open')) closeNavigation();
        }
        if (event.key === 'Tab' && sidebar?.classList.contains('is-open')) {
            const elements = [...sidebar.querySelectorAll('a,button')].filter(element => element.getClientRects().length);
            const first = elements[0], last = elements.at(-1);
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
    });
    document.addEventListener('click', async event => {
        const button = event.target.closest('[data-copy], [data-copy-target]');
        if (!button) return;
        const scope = button.closest('form') || button.closest('[data-work-card]') || document;
        const target = button.dataset.copyTarget ? scope.querySelector(button.dataset.copyTarget) : null;
        const value = button.dataset.copy ?? target?.value ?? '';
        try {
            if (navigator.clipboard?.writeText) await navigator.clipboard.writeText(value);
            else {
                const input = document.createElement('textarea'); input.value = value; input.style.position = 'fixed'; input.style.opacity = '0'; document.body.append(input); input.select();
                const copied = document.execCommand('copy'); input.remove(); if (!copied) throw new Error('clipboard');
            }
            toast('Copiado.');
        } catch { toast('No se pudo copiar. Seleccioná el texto para copiarlo manualmente.'); }
    });
    const initializeTables = root => root.querySelectorAll('table:not([data-static-table])').forEach(table => {
        table.classList.add('responsive-table');
        const headings = [...table.querySelectorAll('tr:first-child th')].map(cell => cell.textContent.trim());
        table.querySelectorAll('tr').forEach(row => [...row.children].forEach((cell, index) => { if (cell.tagName === 'TD' && !cell.hasAttribute('data-label')) cell.dataset.label = headings[index] || ''; }));
    });
    initializeTables(document);
    const bulkForm = document.querySelector('[data-bulk-form]');
    let refreshBulkSelection = () => {};
    if (bulkForm) {
        const query = new URLSearchParams(location.search); query.delete('page');
        const storageKey = 'sales-selection:' + location.pathname + '?' + query.toString();
        const boxes = () => [...bulkForm.querySelectorAll('input[type="checkbox"][name="selected_ids[]"]')];
        let selected = new Set();
        try { const stored = JSON.parse(sessionStorage.getItem(storageKey) || '[]'); if (Array.isArray(stored)) selected = new Set(stored.filter(id => /^\d+$/.test(String(id))).map(String)); } catch {}
        const update = () => {
            const loadedBoxes = boxes();
            loadedBoxes.forEach(box => { box.checked = selected.has(box.value); box.closest('tr')?.classList.toggle('is-selected', box.checked); });
            bulkForm.querySelector('[data-selection-count]').textContent = selected.size + ' seleccionados';
            const all = bulkForm.querySelector('[data-select-page]'); all.checked = loadedBoxes.length > 0 && loadedBoxes.every(box => box.checked); all.indeterminate = loadedBoxes.some(box => box.checked) && !all.checked;
            bulkForm.querySelector('.bulk-toolbar')?.classList.toggle('has-selection', selected.size > 0);
            const submit = bulkForm.querySelector('[data-selection-submit]'); if (submit) submit.disabled = !selected.size;
            try { sessionStorage.setItem(storageKey, JSON.stringify([...selected])); } catch {}
        };
        refreshBulkSelection = update;
        bulkForm.addEventListener('change', event => {
            const box = event.target;
            if (box.matches('input[type="checkbox"][name="selected_ids[]"]')) {
                if (box.checked) selected.add(box.value); else selected.delete(box.value);
                update();
            }
        });
        bulkForm.querySelector('[data-select-page]').addEventListener('change', event => { boxes().forEach(box => { if (event.target.checked) selected.add(box.value); else selected.delete(box.value); }); update(); });
        bulkForm.querySelector('[data-selection-clear]').addEventListener('click', () => { selected.clear(); update(); });
        const action = bulkForm.querySelector('[data-bulk-action]');
        const fields = () => bulkForm.querySelectorAll('[data-action-field]').forEach(field => { field.hidden = field.dataset.actionField !== action.value; field.disabled = field.hidden; field.required = !field.hidden; });
        action.addEventListener('change', fields); fields(); update();
        bulkForm.addEventListener('submit', event => {
            if (!selected.size) { event.preventDefault(); toast('Seleccioná al menos un prospecto.'); return; }
            if (['exclude', 'change_status'].includes(action.value) && !confirm('Aplicar esta acción a ' + selected.size + ' prospectos?')) { event.preventDefault(); return; }
            bulkForm.querySelectorAll('[data-extra-selection]').forEach(input => input.remove());
            const visible = new Set(boxes().map(box => box.value));
            selected.forEach(id => { if (!visible.has(id)) { const input = document.createElement('input'); input.type = 'hidden'; input.name = 'selected_ids[]'; input.value = id; input.dataset.extraSelection = ''; bulkForm.append(input); } });
        });
        if (document.querySelector('[data-operation-success]')) { selected.clear(); update(); }
    }
    const expandField = field => {
        let parent = field.parentElement;
        while (parent) { if (parent.tagName === 'DETAILS') parent.open = true; parent = parent.parentElement; }
    };
    const showFieldError = (field, message) => {
        if (field.type === 'hidden') return;
        expandField(field);
        field.setAttribute('aria-invalid', 'true');
        const label = field.closest('label');
        let group = label?.parentElement;
        if (label && !group.classList.contains('field-group')) {
            group = document.createElement('div'); group.className = 'field-group';
            label.before(group); group.append(label);
        }
        group ||= field.parentElement;
        let error = group.querySelector('.field-error');
        if (!error) { error = document.createElement('div'); error.className = 'field-error'; error.id = 'error-' + Math.random().toString(36).slice(2); group.append(error); }
        error.textContent = message; field.setAttribute('aria-describedby', error.id);
    };
    const dateValue = raw => {
        if (!raw || /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(raw)) return raw;
        const date = new Date(raw);
        if (Number.isNaN(date.getTime())) return '';
        const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', {timeZone: document.body.dataset.timezone, year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).formatToParts(date).map(part => [part.type,part.value]));
        return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
    };
    const initializeForms = (root, pageUrl) => {
        const page = new URL(pageUrl, location.href).searchParams.get('page') || '1';
        [...root.querySelectorAll('form')].forEach((form, index) => {
            if (!form.id) form.id = `sales-form-${page}-${index}`;
            form.dataset.infinitePageUrl = pageUrl;
        });
    };
    initializeForms(document, location.href);
    let lastForm = null;
    try { lastForm = JSON.parse(sessionStorage.getItem('sales-last-form') || 'null'); } catch {}
    const errors = JSON.parse(document.getElementById('field-errors')?.textContent || '{}');
    if (Object.keys(errors).length && lastForm) {
        const form = document.getElementById(lastForm.id);
        if (form && form.action === lastForm.action) {
            const oldValues = JSON.parse(document.getElementById('form-old-input')?.textContent || '{}');
            [...form.elements].forEach(field => {
                if (!field.name || ['hidden','file','password','submit'].includes(field.type) || !(field.name in oldValues)) return;
                if (field.type === 'checkbox' || field.type === 'radio') field.checked = Array.isArray(oldValues[field.name]) ? oldValues[field.name].map(String).includes(field.value) : String(oldValues[field.name]) === field.value;
                else field.value = field.type === 'datetime-local' ? dateValue(oldValues[field.name]) : oldValues[field.name] ?? '';
            });
            Object.entries(errors).forEach(([name,messages]) => [...form.elements].filter(field => field.name === name).forEach(field => showFieldError(field, messages[0])));
            const dialog = form.closest('dialog');
            if (dialog) {
                const summary = document.querySelector('[data-error-summary]')?.cloneNode(true);
                if (summary) form.prepend(summary);
                openDrawer(dialog.id);
            }
            form.querySelector('[aria-invalid="true"]')?.focus();
        }
    }
    if (document.querySelector('[data-operation-success]')) { try { sessionStorage.removeItem('sales-last-form'); } catch {} }
    const initializeDates = root => root.querySelectorAll('input[type="datetime-local"]').forEach(field => { if (!field.value && field.getAttribute('value')) field.value = dateValue(field.getAttribute('value')); });
    initializeDates(document);
    const initializeProposalFields = root => root.querySelectorAll('[data-proposal-fields]').forEach(fields => {
        if (fields.dataset.proposalInitialized) return;
        fields.dataset.proposalInitialized = '1';
        const offer = fields.querySelector('[data-proposal-offer]');
        const service = fields.querySelector('[data-proposal-service]');
        const lead = fields.querySelector('[name="lead_id"]');
        const campaign = fields.querySelector('[name="campaign_id"]');
        if (!offer || !service || !lead) return;
        fields.dataset.autoService = offer.selectedOptions[0]?.dataset.service || '';
        const updateService = () => {
            const name = offer.selectedOptions[0]?.dataset.service || '';
            if (!service.value.trim() || service.value === fields.dataset.autoService) service.value = name;
            fields.dataset.autoService = name;
        };
        const updateContext = (leadChanged = false) => {
            const source = lead.matches('select') ? lead.selectedOptions[0] : lead;
            let context = {};
            try { context = JSON.parse(source?.dataset.offerContext || '{}'); } catch {}
            const campaigns = Object.keys(context).filter(key => key !== 'default');
            if (campaign?.matches('select') && (!campaign.value || leadChanged && !campaigns.includes(campaign.value))) campaign.value = campaigns.length === 1 ? campaigns[0] : '';
            const contextualOffer = campaign?.value && !(campaign.value in context) ? campaign.selectedOptions?.[0]?.dataset.offerId : context[campaign?.value];
            offer.value = String(contextualOffer ?? context.default ?? '');
            updateService();
        };
        lead.addEventListener('change', () => updateContext(true));
        campaign?.addEventListener('change', () => updateContext());
        offer.addEventListener('change', updateService);
        const restoring = Object.keys(errors).length && fields.closest('form')?.id === lastForm?.id;
        if (fields.dataset.existingProposal !== '1' && !restoring) updateContext();
    });
    initializeProposalFields(document);
    const updateWhatsAppLink = scope => {
        const link = scope.querySelector('[data-whatsapp-open]');
        if (!link) return;
        const message = scope.querySelector('[data-message-body]')?.value || '';
        const phone = link.dataset.whatsappNumber;
        const ready = /^\d{8,15}$/.test(phone || '') && message.trim() && !/\{\{[\s\S]*?\}\}/.test(message) && !scope.hasAttribute('aria-busy');
        if (ready) {
            link.href = 'https://wa.me/' + phone + '?text=' + encodeURIComponent(message);
            link.removeAttribute('aria-disabled'); link.removeAttribute('tabindex');
        } else {
            link.removeAttribute('href'); link.setAttribute('aria-disabled','true'); link.setAttribute('tabindex','-1');
        }
        const missing = scope.querySelector('[data-message-missing]');
        if (missing) missing.hidden = Boolean(message.trim() && !/\{\{[\s\S]*?\}\}/.test(message));
    };
    document.querySelectorAll('[data-work-card]').forEach(updateWhatsAppLink);
    const prepareWhatsApp = async scope => {
        scope.prepareController?.abort();
        const controller = new AbortController(); scope.prepareController = controller;
        const campaign = scope.querySelector('[data-whatsapp-campaign]');
        const link = scope.querySelector('[data-whatsapp-open]');
        const status = scope.querySelector('[data-whatsapp-status]');
        const editor = scope.querySelector('[data-message-body]');
        link.dataset.whatsappNumber = '';
        updateWhatsAppLink(scope);
        if (!campaign.value && campaign.options.length > 2) {
            scope.removeAttribute('aria-busy'); editor.disabled = false;
            scope.querySelector('[data-whatsapp-offer]').textContent = 'Seleccioná una campaña';
            status.textContent = 'Seleccioná una campaña para preparar el mensaje.'; return;
        }
        scope.setAttribute('aria-busy','true');
        editor.disabled = true;
        status.textContent = 'Preparando el mensaje…';
        const url = new URL(scope.dataset.prepareUrl, location.href);
        if (campaign.value) url.searchParams.set('campaign_id', campaign.value);
        const timeout = setTimeout(() => controller.abort(), 20000);
        let failureMessage = 'No se pudo preparar el mensaje. Podés reintentar.';
        try {
            const response = await fetch(url.href, {headers:{Accept:'application/json'}, signal:controller.signal});
            const data = await response.json();
            if (!response.ok) { failureMessage = Object.values(data.errors || {}).flat()[0] || failureMessage; throw new Error('response'); }
            editor.value = data.body;
            scope.querySelector('[data-whatsapp-offer]').textContent = data.offer_name || 'Sin oferta vinculada';
            campaign.value = String(data.campaign_id || '');
            link.dataset.whatsappNumber = data.phone || '';
            scope.dataset.whatsappLoaded = '1';
            scope.dataset.templateId = data.template_id || '';
            scope.dataset.contactAttemptId = data.contact_attempt_id || '';
            scope.dataset.step = data.step;
            status.textContent = !data.phone ? 'Revisá el teléfono en los datos del prospecto.' : data.missing.length ? 'Completá el mensaje: ' + data.missing.join(', ') + '.' : 'Podés revisar y editar el mensaje antes de abrir WhatsApp.';
        } catch (error) {
            if (scope.prepareController !== controller) return;
            status.textContent = error.name === 'AbortError' ? 'La preparación tardó demasiado. Podés reintentar.' : failureMessage;
        } finally {
            clearTimeout(timeout);
            if (scope.prepareController === controller) { scope.removeAttribute('aria-busy'); editor.disabled = false; updateWhatsAppLink(scope); }
        }
    };
    document.addEventListener('change', event => {
        if (event.target.matches('[data-whatsapp-campaign]')) prepareWhatsApp(event.target.closest('[data-whatsapp-composer]'));
    });
    document.addEventListener('input', event => {
        if (event.target.matches('[data-message-body]')) {
            const scope = event.target.closest('[data-whatsapp-composer], [data-work-card]');
            if (scope) {
                updateWhatsAppLink(scope);
                const status = scope.querySelector('[data-whatsapp-status]');
                if (status && scope.querySelector('[data-whatsapp-open]')?.hasAttribute('href')) status.textContent = 'El enlace usará este mensaje, con tus últimas ediciones.';
            }
        }
    });
    document.addEventListener('click', event => {
        const opener = event.target.closest('[data-drawer-open="whatsapp-compose"]');
        if (opener) {
            const scope = document.getElementById('whatsapp-compose')?.querySelector('[data-whatsapp-composer]');
            if (scope && !scope.dataset.whatsappLoaded) prepareWhatsApp(scope);
        }
        const prepare = event.target.closest('[data-whatsapp-prepare]');
        if (prepare) prepareWhatsApp(prepare.closest('[data-whatsapp-composer]'));
        const link = event.target.closest('[data-whatsapp-open]');
        if (!link) return;
        const scope = link.closest('[data-whatsapp-composer], [data-work-card]');
        updateWhatsAppLink(scope);
        if (link.getAttribute('aria-disabled') === 'true') { event.preventDefault(); return; }
        if (scope.matches('[data-whatsapp-composer]')) {
            const form = document.querySelector('[data-contact-record]');
            if (form) {
                form.elements.channel.value = 'whatsapp';
                form.elements.message_snapshot.value = scope.querySelector('[data-message-body]').value;
                form.elements.campaign_id.value = scope.querySelector('[data-whatsapp-campaign]').value;
                form.elements.message_template_id.value = scope.dataset.templateId;
                form.elements.contact_attempt_id.value = scope.dataset.contactAttemptId;
                form.elements.step.value = scope.dataset.step;
            }
        }
    });
    document.addEventListener('invalid', event => {
        const field = event.target;
        showFieldError(field, field.validity.valueMissing ? 'Completá este campo para continuar.' : field.validity.typeMismatch && field.type === 'email' ? 'Ingresá un email válido.' : field.validationMessage);
    }, true);
    document.addEventListener('input', event => {
        const field = event.target;
        if (field.matches('[aria-invalid="true"]') && field.validity.valid) { field.removeAttribute('aria-invalid'); field.removeAttribute('aria-describedby'); field.closest('.field-group')?.querySelector('.field-error')?.remove(); }
    });
    document.addEventListener('submit', event => {
        const form = event.target;
        if (event.defaultPrevented || !form.checkValidity()) return;
        if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) { event.preventDefault(); return; }
        if (form.method === 'post' && form.dataset.infinitePageUrl) history.replaceState(history.state, '', form.dataset.infinitePageUrl);
        try { sessionStorage.setItem('sales-last-form', JSON.stringify({id:form.id, action:form.action})); } catch {}
        if (form.matches('[data-bulk-form]') && form.querySelector('[data-bulk-action]').value === 'export') return;
        form.querySelectorAll('button:not([type="button"]):not([data-drawer-close])').forEach(button => { if (button.type !== 'submit') return; button.dataset.wasDisabled = String(button.disabled); button.disabled = true; button.classList.add('is-loading'); });
        form.setAttribute('aria-busy','true');
    });
    window.addEventListener('pageshow', () => {
        document.querySelectorAll('button.is-loading').forEach(button => { button.disabled = button.dataset.wasDisabled === 'true'; button.classList.remove('is-loading'); });
        document.querySelectorAll('form[aria-busy]').forEach(form => form.removeAttribute('aria-busy'));
    });
    const phaseLabels = {queued:'En cola',running:'En curso',searching:'Buscando',importing:'Importando',processing:'Importando',completed:'Completada',failed:'Error',cancelled:'Cancelada',cancelling:'Cancelando',dry_run:'Simulación',simulation:'Simulación'};
    let polling = false;
    const poll = async () => {
        if (polling || document.hidden) return;
        polling = true;
        for (const row of document.querySelectorAll('[data-run-poll]')) {
            try {
                const response = await fetch(row.dataset.runPoll,{headers:{Accept:'application/json'}});
                if (!response.ok) continue;
                const data = await response.json();
                row.querySelectorAll('[data-run-field]').forEach(cell => { const field = cell.dataset.runField; cell.textContent = ['status','phase'].includes(field) ? phaseLabels[data[field]] || data[field] : data[field] ?? ''; });
                if (['completed','failed','cancelled'].includes(data.status)) {
                    row.removeAttribute('data-run-poll'); row.querySelector('.progress-line')?.remove();
                    if (!document.querySelector('form:focus-within')) { location.reload(); return; }
                    toast('La tarea terminó. Actualizá la página para ver las acciones disponibles.');
                }
            } catch { toast('No se pudo actualizar el progreso. Se reintentará.'); }
        }
        polling = false;
    };
    document.querySelectorAll('[data-run-poll]').forEach(row => { const target = row.tagName === 'TR' ? row.lastElementChild : row; const progress = document.createElement('div'); progress.className = 'progress-line'; progress.setAttribute('aria-hidden','true'); target.append(progress); });
    if (document.querySelector('[data-run-poll]')) setInterval(poll,4000);
    document.querySelectorAll('[data-infinite-list]').forEach(list => {
        const items = list.querySelector('[data-infinite-items]');
        const footer = list.querySelector('[data-infinite-pagination]');
        const status = footer?.querySelector('[data-infinite-status]');
        const link = footer?.querySelector('[data-infinite-next]');
        if (!items || !footer || !status || !link) return;
        const loadedIds = new Set([...items.children].map(item => item.dataset.infiniteItem).filter(Boolean));
        const loadedPages = new Set();
        let loading = false;
        let observer;
        const loadMore = async () => {
            if (loading || !link.isConnected) return;
            const pageUrl = new URL(link.href, location.href);
            if (pageUrl.origin !== location.origin || loadedPages.has(pageUrl.href)) return;
            loading = true;
            observer?.unobserve(footer);
            list.setAttribute('aria-busy', 'true');
            footer.classList.add('is-loading');
            link.setAttribute('aria-disabled', 'true');
            status.textContent = 'Cargando más registros…';
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 20000);
            let succeeded = false;
            try {
                const response = await fetch(pageUrl.href, {credentials:'same-origin', headers:{Accept:'text/html'}, signal:controller.signal});
                if (!response.ok || new URL(response.url).pathname !== pageUrl.pathname) throw new Error('response');
                const page = new DOMParser().parseFromString(await response.text(), 'text/html');
                const nextList = [...page.querySelectorAll('[data-infinite-list]')].find(candidate => candidate.dataset.infiniteList === list.dataset.infiniteList);
                const nextItems = nextList?.querySelector('[data-infinite-items]');
                if (!nextList) throw new Error('list');
                initializeForms(page, pageUrl.href);
                const nextFooter = nextList.querySelector('[data-infinite-pagination]');
                const nextLink = nextFooter?.querySelector('[data-infinite-next]');
                const fragment = document.createDocumentFragment();
                [...(nextItems?.children ?? [])].forEach(item => {
                    const id = item.dataset.infiniteItem;
                    if (!id || loadedIds.has(id)) return;
                    loadedIds.add(id);
                    fragment.append(document.importNode(item, true));
                });
                items.append(fragment);
                initializeTables(list);
                initializeMenus(list);
                initializeDates(list);
                initializeProposalFields(list);
                list.querySelectorAll('[data-work-card]').forEach(updateWhatsAppLink);
                refreshBulkSelection();
                loadedPages.add(pageUrl.href);
                const total = nextFooter?.dataset.total ?? loadedIds.size;
                status.textContent = `${loadedIds.size} de ${total} registros cargados${nextLink ? '' : ' · Fin de la lista'}`;
                if (nextLink) { link.href = nextLink.href; link.textContent = 'Cargar más'; }
                else { link.remove(); observer?.disconnect(); }
                succeeded = true;
            } catch {
                status.textContent = 'No se pudieron cargar más registros. Podés reintentar sin perder los que ya tenés.';
                link.textContent = 'Reintentar';
            } finally {
                clearTimeout(timeout);
                loading = false;
                list.removeAttribute('aria-busy');
                footer.classList.remove('is-loading');
                link.removeAttribute('aria-disabled');
                if (succeeded && link.isConnected) observer?.observe(footer);
            }
        };
        link.addEventListener('click', event => {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            loadMore();
        });
        if ('IntersectionObserver' in window) {
            observer = new IntersectionObserver(entries => { if (entries.some(entry => entry.isIntersecting)) loadMore(); }, {rootMargin:'250px 0px'});
            observer.observe(footer);
        }
    });
})();
