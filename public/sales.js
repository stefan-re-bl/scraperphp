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
    document.querySelectorAll('[data-drawer-open]').forEach(button => button.addEventListener('click', () => openDrawer(button.dataset.drawerOpen)));
    document.querySelectorAll('[data-drawer]').forEach(dialog => {
        dialog.addEventListener('close', updateBodyLock);
        dialog.querySelectorAll('[data-drawer-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
        dialog.addEventListener('click', event => {
            const box = dialog.getBoundingClientRect();
            if (event.target === dialog && (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom)) dialog.close();
        });
    });
    if (new URLSearchParams(location.search).get('create') === '1') {
        const createDialog = document.querySelector('dialog:has([data-create-form])');
        if (createDialog) openDrawer(createDialog.id);
    }
    document.querySelectorAll('[data-notice-dismiss]').forEach(button => button.addEventListener('click', () => button.closest('.app-notice').remove()));
    const actionMenus = [...document.querySelectorAll('.action-menu')];
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
    actionMenus.forEach(menu => menu.addEventListener('toggle', () => {
        if (!menu.open) return;
        actionMenus.forEach(other => { if (other !== menu) other.open = false; });
        positionMenu(menu);
    }));
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
    document.querySelectorAll('table:not([data-static-table])').forEach(table => {
        table.classList.add('responsive-table');
        const headings = [...table.querySelectorAll('tr:first-child th')].map(cell => cell.textContent.trim());
        table.querySelectorAll('tr').forEach(row => [...row.children].forEach((cell, index) => { if (cell.tagName === 'TD' && !cell.hasAttribute('data-label')) cell.dataset.label = headings[index] || ''; }));
    });
    const bulkForm = document.querySelector('[data-bulk-form]');
    if (bulkForm) {
        const query = new URLSearchParams(location.search); query.delete('page');
        const storageKey = 'sales-selection:' + location.pathname + '?' + query.toString();
        const boxes = [...bulkForm.querySelectorAll('[name="selected_ids[]"]')];
        let selected = new Set();
        try { const stored = JSON.parse(sessionStorage.getItem(storageKey) || '[]'); if (Array.isArray(stored)) selected = new Set(stored.filter(id => /^\d+$/.test(String(id))).map(String)); } catch {}
        const update = () => {
            boxes.forEach(box => { box.checked = selected.has(box.value); box.closest('tr')?.classList.toggle('is-selected', box.checked); });
            bulkForm.querySelector('[data-selection-count]').textContent = selected.size + ' seleccionados';
            const all = bulkForm.querySelector('[data-select-page]'); all.checked = boxes.length > 0 && boxes.every(box => box.checked); all.indeterminate = boxes.some(box => box.checked) && !all.checked;
            bulkForm.querySelector('.bulk-toolbar')?.classList.toggle('has-selection', selected.size > 0);
            const submit = bulkForm.querySelector('[data-selection-submit]'); if (submit) submit.disabled = !selected.size;
            try { sessionStorage.setItem(storageKey, JSON.stringify([...selected])); } catch {}
        };
        boxes.forEach(box => box.addEventListener('change', () => { if (box.checked) selected.add(box.value); else selected.delete(box.value); update(); }));
        bulkForm.querySelector('[data-select-page]').addEventListener('change', event => { boxes.forEach(box => { if (event.target.checked) selected.add(box.value); else selected.delete(box.value); }); update(); });
        bulkForm.querySelector('[data-selection-clear]').addEventListener('click', () => { selected.clear(); update(); });
        const action = bulkForm.querySelector('[data-bulk-action]');
        const fields = () => bulkForm.querySelectorAll('[data-action-field]').forEach(field => { field.hidden = field.dataset.actionField !== action.value; field.disabled = field.hidden; field.required = !field.hidden; });
        action.addEventListener('change', fields); fields(); update();
        bulkForm.addEventListener('submit', event => {
            if (!selected.size) { event.preventDefault(); toast('Seleccioná al menos un prospecto.'); return; }
            if (['exclude', 'change_status'].includes(action.value) && !confirm('Aplicar esta acción a ' + selected.size + ' prospectos?')) { event.preventDefault(); return; }
            bulkForm.querySelectorAll('[data-extra-selection]').forEach(input => input.remove());
            const visible = new Set(boxes.map(box => box.value));
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
    const forms = [...document.forms];
    forms.forEach((form, index) => { if (!form.id) form.id = 'sales-form-' + index; });
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
    document.querySelectorAll('input[type="datetime-local"]').forEach(field => { if (!field.value && field.getAttribute('value')) field.value = dateValue(field.getAttribute('value')); });
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
})();
