/**
 * REST helper for Eko Sampa frontend (cookie auth + wp_rest nonce).
 *
 * Contract: every module exposes `window.eko*Factory()` returning
 * `{ state, loading, error, init, ...methods }`. Domain data lives in `state` only.
 * Assignments to `window.*Factory` run before `alpine:init` so Alpine never runs first.
 */
(function () {
    /**
     * @param {string} path Relative to eko-sampa/v1/
     * @param {RequestInit & { body?: object }} opts
     */
    async function api(path, opts) {
        const root = (window.ekoSampaRest && window.ekoSampaRest.root) || '';
        const url = String(root).replace(/\/?$/, '/') + String(path || '').replace(/^\//, '');
        const headers = Object.assign(
            { 'X-WP-Nonce': (window.ekoSampaRest && window.ekoSampaRest.nonce) || '' },
            (opts && opts.headers) || {}
        );
        let body = opts && opts.body;
        if (body && typeof body === 'object' && !(body instanceof FormData)) {
            headers['Content-Type'] = 'application/json; charset=UTF-8';
            body = JSON.stringify(body);
        }
        if (typeof window !== 'undefined' && window.ekoSampaRest && window.ekoSampaRest.debugRest) {
            console.log('[eko REST]', url, (opts && opts.method) || 'GET', opts && opts.body);
        }
        const res = await fetch(url, Object.assign({}, opts || {}, { headers, credentials: 'same-origin', body }));
        if (!res.ok) {
            let msg = res.statusText;
            try {
                const j = await res.json();
                if (j && j.message) {
                    msg = j.message;
                }
                if (j && j.data && j.data.failure_reason) {
                    msg += ' [' + String(j.data.failure_reason) + ']';
                }
                if (j && j.data && j.data.db_last_error) {
                    msg += ' — ' + String(j.data.db_last_error);
                }
            } catch (e) {
                void e;
            }
            throw new Error(msg);
        }
        const ct = res.headers.get('content-type') || '';
        if (ct.indexOf('application/json') !== -1) {
            return res.json();
        }
        return res.text();
    }

    window.ekoSampaApi = api;
})();

/** @returns {{ mode: string, recordId: number, view: string, urls: Record<string, string> }} */
function ekoCrudPage() {
    const c = window.ekoSampaRest && window.ekoSampaRest.crud;
    if (c && typeof c === 'object') {
        return c;
    }
    return { mode: 'list', recordId: 0, view: '', urls: {} };
}

function ekoCrudMixin() {
    return {
        mode: 'list',
        recordId: 0,
        crudUrls: {},
        initCrud() {
            const p = ekoCrudPage();
            this.mode = p.mode || 'list';
            this.recordId = parseInt(String(p.recordId || 0), 10) || 0;
            this.crudUrls = p.urls && typeof p.urls === 'object' ? p.urls : {};
        },
        resourceUrl(action, id) {
            const u = this.crudUrls || {};
            if (action === 'list' && u.list) {
                return u.list;
            }
            if (action === 'new' && u.new) {
                return u.new;
            }
            const nid = parseInt(String(id || 0), 10) || 0;
            if (action === 'view' && nid > 0) {
                if (u.view) {
                    return u.view;
                }
                const base = (u.list || '').replace(/\/?$/, '/');
                return base + nid + '/';
            }
            if (action === 'edit' && nid > 0) {
                if (u.edit) {
                    return u.edit;
                }
                const base = (u.list || '').replace(/\/?$/, '/');
                return base + nid + '/edit/';
            }
            return u.list || '';
        },
        viewUrl(id) {
            return this.resourceUrl('view', id);
        },
        editUrl(id) {
            return this.resourceUrl('edit', id);
        },
        goToList() {
            const url = this.resourceUrl('list');
            if (url) {
                window.location.href = url;
            }
        },
        goToView(id) {
            const url = this.viewUrl(id);
            if (url) {
                window.location.href = url;
            }
        },
    };
}

/**
 * Aligns with PHP {@see sanitize_title()} + lowercase for dynamic_data / template placeholder keys.
 *
 * @param {string} raw
 * @returns {string}
 */
function ekoSampaNormalizeDynamicKey(raw) {
    let s = String(raw == null ? '' : raw).trim();
    try {
        s = s.normalize('NFD').replace(/\p{M}+/gu, '');
    } catch (e) {
        s = s.replace(/[\u0300-\u036f]/g, '');
    }
    return s
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

/**
 * Declarative validation UI ↔ validation_rules_json (REST/DB).
 * State uses fieldForm.validation (object); serialize only in saveField.
 * Legacy non-object JSON is kept in _validationLegacyRaw until overwritten.
 */
window.ekoSampaFieldValidation = (function () {
    var KNOWN = ['minLength', 'maxLength', 'pattern', 'minimum', 'maximum', 'format'];

    function emptyValidationState() {
        return {
            minLength: '',
            maxLength: '',
            pattern: '',
            minimum: '',
            maximum: '',
            format: '',
            __extras: {},
        };
    }

    function parseFromApiObject(obj) {
        var out = emptyValidationState();
        if (!obj || typeof obj !== 'object' || Array.isArray(obj)) {
            return out;
        }
        var k;
        for (k in obj) {
            if (!Object.prototype.hasOwnProperty.call(obj, k)) {
                continue;
            }
            if (KNOWN.indexOf(k) !== -1) {
                var v = obj[k];
                if (v == null) {
                    continue;
                }
                out[k] = String(v);
            } else {
                out.__extras[k] = obj[k];
            }
        }
        return out;
    }

    function cloneValidationState(src) {
        var e = src && src.__extras && typeof src.__extras === 'object' ? src.__extras : {};
        var nx = {};
        var k;
        for (k in e) {
            if (Object.prototype.hasOwnProperty.call(e, k)) {
                nx[k] = e[k];
            }
        }
        return {
            minLength: src && src.minLength != null ? String(src.minLength) : '',
            maxLength: src && src.maxLength != null ? String(src.maxLength) : '',
            pattern: src && src.pattern != null ? String(src.pattern) : '',
            minimum: src && src.minimum != null ? String(src.minimum) : '',
            maximum: src && src.maximum != null ? String(src.maximum) : '',
            format: src && src.format != null ? String(src.format) : '',
            __extras: nx,
        };
    }

    function parseFieldRow(f) {
        var raw = f && f.validation_rules_json != null ? f.validation_rules_json : null;
        if (raw == null || raw === '') {
            return { validation: emptyValidationState(), _validationLegacyRaw: null };
        }
        if (typeof raw === 'object' && !Array.isArray(raw)) {
            return { validation: cloneValidationState(parseFromApiObject(raw)), _validationLegacyRaw: null };
        }
        if (typeof raw === 'object' && Array.isArray(raw)) {
            try {
                return { validation: emptyValidationState(), _validationLegacyRaw: JSON.stringify(raw) };
            } catch (e) {
                return { validation: emptyValidationState(), _validationLegacyRaw: null };
            }
        }
        if (typeof raw === 'string') {
            var t = raw.trim();
            if (t === '') {
                return { validation: emptyValidationState(), _validationLegacyRaw: null };
            }
            try {
                var p = JSON.parse(t);
                if (p !== null && typeof p === 'object' && !Array.isArray(p)) {
                    return { validation: cloneValidationState(parseFromApiObject(p)), _validationLegacyRaw: null };
                }
                return { validation: emptyValidationState(), _validationLegacyRaw: t };
            } catch (e2) {
                return { validation: emptyValidationState(), _validationLegacyRaw: t };
            }
        }
        return { validation: emptyValidationState(), _validationLegacyRaw: null };
    }

    function serializeForApi(validation) {
        if (!validation || typeof validation !== 'object') {
            return null;
        }
        var o = {};
        var ml = String(validation.minLength != null ? validation.minLength : '').trim();
        if (ml !== '') {
            var nml = parseInt(ml, 10);
            if (!Number.isNaN(nml) && nml >= 0) {
                o.minLength = nml;
            }
        }
        var xl = String(validation.maxLength != null ? validation.maxLength : '').trim();
        if (xl !== '') {
            var nxl = parseInt(xl, 10);
            if (!Number.isNaN(nxl) && nxl >= 0) {
                o.maxLength = nxl;
            }
        }
        var pat = String(validation.pattern != null ? validation.pattern : '').trim();
        if (pat !== '') {
            o.pattern = pat;
        }
        var fmt = String(validation.format != null ? validation.format : '').trim();
        if (fmt !== '') {
            o.format = fmt;
        }
        var mn = String(validation.minimum != null ? validation.minimum : '').trim();
        if (mn !== '') {
            var fn = Number(mn);
            if (!Number.isNaN(fn)) {
                o.minimum = fn;
            }
        }
        var mx = String(validation.maximum != null ? validation.maximum : '').trim();
        if (mx !== '') {
            var fx = Number(mx);
            if (!Number.isNaN(fx)) {
                o.maximum = fx;
            }
        }
        var ext = validation.__extras && typeof validation.__extras === 'object' ? validation.__extras : {};
        var ek;
        for (ek in ext) {
            if (!Object.prototype.hasOwnProperty.call(ext, ek)) {
                continue;
            }
            if (ek === '__proto__') {
                continue;
            }
            o[ek] = ext[ek];
        }
        if (Object.keys(o).length === 0) {
            return null;
        }
        return o;
    }

    /**
     * @returns {unknown|null} payload for REST, or `false` if legacy raw is invalid JSON
     */
    function buildRestValidationPayload(fieldForm) {
        var ui = serializeForApi(fieldForm.validation);
        var hasUi = ui != null && Object.keys(ui).length > 0;
        if (hasUi) {
            return ui;
        }
        if (fieldForm._validationLegacyRaw) {
            try {
                var t = String(fieldForm._validationLegacyRaw).trim();
                if (t === '') {
                    return null;
                }
                return JSON.parse(t);
            } catch (e) {
                return false;
            }
        }
        return null;
    }

    return {
        emptyValidationState: emptyValidationState,
        parseFieldRow: parseFieldRow,
        serializeForApi: serializeForApi,
        buildRestValidationPayload: buildRestValidationPayload,
        cloneValidationState: cloneValidationState,
    };
})();

/** Runtime probe: if this never appears, frontend-app.js did not execute (404, blocked, or parse error). */
window.EkoModules = Object.assign(window.EkoModules || {}, {
    frontendAppJsStart: true,
    startAtMs: Date.now(),
});

function ekoShellFactory() {
    return {
        state: { navOpen: false, sidebarCollapsed: false },
        loading: false,
        error: null,
        init() {
            try {
                if (localStorage.getItem('eko_sampa_sidebar_collapsed') === '1') {
                    this.state.sidebarCollapsed = true;
                }
            } catch (e) {
                void e;
            }
        },
        toggleLeftSidebar() {
            this.state.sidebarCollapsed = !this.state.sidebarCollapsed;
            try {
                localStorage.setItem('eko_sampa_sidebar_collapsed', this.state.sidebarCollapsed ? '1' : '0');
            } catch (e) {
                void e;
            }
        },
    };
}

function ekoClientsFactory() {
    return Object.assign({}, ekoCrudMixin(), {
        state: {
            rows: [],
            record: {},
            page: 1,
            pageSize: 30,
            hasNext: false,
            q: '',
            filterUserId: '',
            users: [],
            form: { id: 0, nome: '', email: '', telefone: '', documento: '', cidade: '', estado: '', user_id: '' },
        },
        loading: false,
        error: null,
        isAdmin: !!(window.ekoSampaRest && window.ekoSampaRest.isAdmin),
        async init() {
            this.initCrud();
            if (this.isAdmin) {
                try {
                    this.state.users = await window.ekoSampaApi('users', { method: 'GET' });
                } catch (e) {
                    this.state.users = [];
                }
            }
            if (this.mode === 'list') {
                await this.load();
                return;
            }
            if (this.mode === 'new') {
                this.reset();
                return;
            }
            await this.loadRecord();
        },
        async loadRecord() {
            if (!this.recordId) {
                this.error = 'Invalid record.';
                return;
            }
            this.loading = true;
            this.error = null;
            try {
                const row = await window.ekoSampaApi('clients/' + this.recordId, { method: 'GET' });
                if (this.mode === 'view') {
                    this.state.record = row && typeof row === 'object' ? row : {};
                } else {
                    this.state.form = Object.assign({ user_id: '' }, row);
                }
            } catch (e) {
                this.error = String(e.message || e);
            } finally {
                this.loading = false;
            }
        },
        async load() {
            if (this.mode !== 'list') {
                return;
            }
            this.loading = true;
            this.error = null;
            const ps = this.state.pageSize;
            const qs = new URLSearchParams({
                limit: String(ps + 1),
                offset: String((this.state.page - 1) * ps),
            });
            if (this.state.q) {
                qs.set('s', this.state.q);
            }
            if (this.isAdmin && this.state.filterUserId) {
                qs.set('filter_user_id', this.state.filterUserId);
            }
            try {
                const arr = await window.ekoSampaApi('clients?' + qs.toString(), { method: 'GET' });
                const list = Array.isArray(arr) ? arr : [];
                this.state.hasNext = list.length > ps;
                this.state.rows = list.slice(0, ps);
            } catch (e) {
                this.error = String(e.message || e);
            } finally {
                this.loading = false;
            }
        },
        prevPage() {
            if (this.state.page <= 1) {
                return;
            }
            this.state.page--;
            this.load();
        },
        nextPage() {
            if (!this.state.hasNext) {
                return;
            }
            this.state.page++;
            this.load();
        },
        reset() {
            this.state.form = { id: 0, nome: '', email: '', telefone: '', documento: '', cidade: '', estado: '', user_id: '' };
        },
        async save() {
            this.error = null;
            const payload = { ...this.state.form };
            delete payload.id;
            try {
                let id = parseInt(String(this.state.form.id || 0), 10);
                if (id) {
                    await window.ekoSampaApi('clients/' + id, { method: 'PATCH', body: payload });
                } else {
                    if (this.isAdmin && this.state.form.user_id) {
                        payload.user_id = parseInt(String(this.state.form.user_id), 10);
                    }
                    const created = await window.ekoSampaApi('clients', { method: 'POST', body: payload });
                    id = created && created.id ? parseInt(String(created.id), 10) : 0;
                }
                if (id) {
                    this.goToView(id);
                } else {
                    this.goToList();
                }
            } catch (e) {
                this.error = String(e.message || e);
            }
        },
        async remove(id) {
            if (!window.confirm('OK?')) {
                return;
            }
            try {
                await window.ekoSampaApi('clients/' + id, { method: 'DELETE' });
                this.goToList();
            } catch (e) {
                this.error = String(e.message || e);
            }
        },
    });
}

function ekoServicesFactory() {
    var fieldSchema = window.ekoSampaFieldSchema;
    return Object.assign(
        {},
        ekoCrudMixin(),
        typeof window.ekoModalMixin === 'function' ? window.ekoModalMixin() : {},
        typeof window.ekoUiStatesMixin === 'function' ? window.ekoUiStatesMixin() : {},
        {
        state: {
            rows: [],
            record: {},
            fields: [],
            page: 1,
            pageSize: 30,
            hasNext: false,
            q: '',
            filterUserId: '',
            users: [],
            form: { id: 0, nome: '', descricao: '', is_global: 0 },
            fieldSearch: '',
            fieldsPanelOpen: true,
            expandedFieldIds: {},
            fieldDraft: fieldSchema ? fieldSchema.emptyDraft(0, 0) : { meta: {}, definition: {} },
            _fieldSortable: null,
        },
        _fieldDraftBaseline: '',
        loading: false,
        error: null,
        isAdmin: !!(window.ekoSampaRest && window.ekoSampaRest.isAdmin),
        async init() {
            this.initCrud();
            if (typeof this.initEkoModalLayer === 'function') {
                this.initEkoModalLayer();
            }
            if (this.isAdmin) {
                try {
                    this.state.users = await window.ekoSampaApi('users', { method: 'GET' });
                } catch (e) {
                    this.state.users = [];
                }
            }
            if (this.mode === 'list') {
                await this.load();
                return;
            }
            if (this.mode === 'new') {
                this.reset();
                return;
            }
            if (this.mode === 'view') {
                await this.loadRecord();
                if (this.recordId) {
                    await this.loadFields(this.recordId, { silent: true });
                }
                return;
            }
            if (this.mode === 'edit') {
                await this.loadRecord();
            }
        },
        async loadRecord() {
            if (!this.recordId) {
                this.error = 'Invalid record.';
                return;
            }
            this.loading = true;
            this.error = null;
            try {
                const row = await window.ekoSampaApi('services/' + this.recordId, { method: 'GET' });
                if (this.mode === 'view') {
                    this.state.record = row && typeof row === 'object' ? row : {};
                } else {
                    this.state.form = Object.assign({ is_global: 0 }, row);
                    this.state.form.id = parseInt(String(this.state.form.id || row.id || 0), 10) || 0;
                    await this.loadFields(this.state.form.id);
                }
            } catch (e) {
                this.error = String(e.message || e);
            } finally {
                this.loading = false;
            }
        },
        async load() {
            if (this.mode !== 'list') {
                return;
            }
            this.loading = true;
            this.error = null;
            const ps = this.state.pageSize;
            const qs = new URLSearchParams({
                limit: String(ps + 1),
                offset: String((this.state.page - 1) * ps),
            });
            if (this.state.q) {
                qs.set('s', this.state.q);
            }
            if (this.isAdmin && this.state.filterUserId) {
                qs.set('filter_user_id', this.state.filterUserId);
            }
            try {
                const arr = await window.ekoSampaApi('services?' + qs.toString(), { method: 'GET' });
                const list = Array.isArray(arr) ? arr : [];
                this.state.hasNext = list.length > ps;
                this.state.rows = list.slice(0, ps);
            } catch (e) {
                this.error = String(e.message || e);
            } finally {
                this.loading = false;
            }
        },
        prevPage() {
            if (this.state.page <= 1) {
                return;
            }
            this.state.page--;
            this.load();
        },
        nextPage() {
            if (!this.state.hasNext) {
                return;
            }
            this.state.page++;
            this.load();
        },
        async loadFields(sid, opts) {
            const silent = opts && opts.silent;
            try {
                this.state.fields = await window.ekoSampaApi('services/' + sid + '/fields', { method: 'GET' });
            } catch (e) {
                this.state.fields = [];
                if (!silent) {
                    this.error = String(e.message || e);
                }
            }
            this.state.fields = Array.isArray(this.state.fields)
                ? this.state.fields.slice().sort((a, b) => Number(a.sort_order || 0) - Number(b.sort_order || 0))
                : [];
            this._cacheFieldsStore(sid);
            this.$nextTick(() => this.mountFieldSortable());
        },
        _fieldsStoreKey(sid) {
            return 'service:' + String(sid) + ':fields';
        },
        _cacheFieldsStore(sid) {
            if (window.ekoSampaStore && sid) {
                window.ekoSampaStore.set(this._fieldsStoreKey(sid), this.state.fields);
            }
        },
        _fieldsSnapshot() {
            return JSON.parse(JSON.stringify(this.state.fields || []));
        },
        _fieldsRequestId(prefix) {
            return String(prefix || 'fld') + '_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);
        },
        _fieldsToastStart(requestId, message) {
            if (window.ekoSampaToast && typeof window.ekoSampaToast.show === 'function') {
                window.ekoSampaToast.show({ id: requestId, type: 'loading', message: message, duration: 0 });
            }
        },
        _fieldsToastSuccess(requestId, message) {
            if (window.ekoSampaToast) {
                window.ekoSampaToast.dismiss(requestId);
                window.ekoSampaToast.show({ type: 'success', message: message, duration: 3000 });
            }
        },
        _fieldsToastError(requestId, message) {
            if (window.ekoSampaToast) {
                window.ekoSampaToast.dismiss(requestId);
                window.ekoSampaToast.show({ type: 'error', message: message, duration: 6000 });
            }
            if (window.ekoSampaEventBus) {
                window.ekoSampaEventBus.emit('eko:api:error', { message: message, requestId: requestId });
            }
        },
        _fieldsRollback(snapshot) {
            this.state.fields = snapshot;
            this.$nextTick(() => this.mountFieldSortable());
        },
        _emitFieldsChanged(sid, extra) {
            if (window.ekoSampaEventBus) {
                window.ekoSampaEventBus.emit(
                    'eko:service:fields-changed',
                    Object.assign({ serviceId: sid }, extra || {})
                );
            }
            this._cacheFieldsStore(sid);
        },
        fieldVisible(f) {
            if (!f) {
                return false;
            }
            if (!String(this.state.fieldSearch || '').trim()) {
                return true;
            }
            return this.filteredFieldsList().some(function (x) {
                return Number(x.id) === Number(f.id);
            });
        },
        fieldRowClasses(f) {
            let cls = this.isFieldExpanded(f.id) ? 'eko-field-row py-2' : 'eko-field-row py-2 is-collapsed';
            if (f && f._ekoSync === 'pending') {
                cls += ' is-syncing';
            }
            if (f && f._ekoSync === 'error') {
                cls += ' is-sync-error';
            }
            if (!this.fieldVisible(f)) {
                cls += ' hidden';
            }
            return cls;
        },
        destroyFieldSortable() {
            if (this._fieldSortable && typeof this._fieldSortable.destroy === 'function') {
                try {
                    this._fieldSortable.destroy();
                } catch (e) {
                    void e;
                }
            }
            this._fieldSortable = null;
        },
        mountFieldSortable() {
            this.destroyFieldSortable();
            if (typeof Sortable === 'undefined' || !this.state.form.id) {
                return;
            }
            const root = this.$refs.fieldSortRoot;
            if (!root) {
                return;
            }
            const self = this;
            this._fieldSortable = Sortable.create(root, {
                handle: '[data-eko-field-drag]',
                animation: 150,
                async onEnd() {
                    await self.reorderFieldsFromDom(root);
                },
            });
        },
        async reorderFieldsFromDom(root) {
            const sid = parseInt(String(this.state.form.id || 0), 10);
            if (!sid || !root) {
                return;
            }
            if (String(this.state.fieldSearch || '').trim()) {
                if (window.ekoSampaToast) {
                    window.ekoSampaToast.show({
                        type: 'warning',
                        message: 'Clear the filter before reordering fields.',
                        duration: 4000,
                    });
                }
                return;
            }
            const ids = Array.from(root.querySelectorAll('[data-field-id]'))
                .map((el) => parseInt(String(el.getAttribute('data-field-id') || '0'), 10))
                .filter((n) => n > 0);
            if (!ids.length) {
                return;
            }
            const snapshot = this._fieldsSnapshot();
            const requestId = this._fieldsRequestId('reorder');
            const byId = {};
            (this.state.fields || []).forEach(function (f) {
                if (f && f.id) {
                    byId[Number(f.id)] = f;
                }
            });
            const reordered = ids
                .map(function (id, index) {
                    const row = byId[id];
                    if (!row) {
                        return null;
                    }
                    return Object.assign({}, row, {
                        sort_order: index,
                        _ekoSync: 'pending',
                        _ekoRequestId: requestId,
                    });
                })
                .filter(Boolean);
            if (reordered.length !== ids.length) {
                return;
            }
            this.state.fields = reordered;
            this._fieldsToastStart(requestId, 'Saving order…');
            try {
                await window.ekoSampaApi('services/' + sid + '/fields/reorder', {
                    method: 'POST',
                    body: { order: ids },
                });
                this._fieldsToastSuccess(requestId, 'Field order saved.');
                await this.loadFields(sid, { silent: true });
                this._emitFieldsChanged(sid, { requestId: requestId, action: 'reorder' });
            } catch (e) {
                this._fieldsRollback(snapshot);
                this._fieldsToastError(requestId, String(e.message || e));
                this.error = String(e.message || e);
            }
        },
        filteredFieldsList() {
            const q = String(this.state.fieldSearch || '')
                .trim()
                .toLowerCase();
            const list = Array.isArray(this.state.fields) ? this.state.fields : [];
            if (!q) {
                return list;
            }
            return list.filter(function (f) {
                if (!f) {
                    return false;
                }
                const label = String(f.label || '').toLowerCase();
                const slug = String(f.slug || '').toLowerCase();
                const type = String(f.type || '').toLowerCase();
                return label.indexOf(q) >= 0 || slug.indexOf(q) >= 0 || type.indexOf(q) >= 0;
            });
        },
        toggleFieldsPanel() {
            this.state.fieldsPanelOpen = !this.state.fieldsPanelOpen;
        },
        toggleFieldExpand(id) {
            const k = String(id);
            this.state.expandedFieldIds[k] = !this.state.expandedFieldIds[k];
        },
        isFieldExpanded(id) {
            return !!this.state.expandedFieldIds[String(id)];
        },
        resetFieldDraft() {
            if (!fieldSchema || !this.state.form.id) {
                return;
            }
            this.state.fieldDraft = fieldSchema.emptyDraft(this.state.form.id, this.state.fields.length);
            this._fieldDraftBaseline = fieldSchema.snapshot(this.state.fieldDraft);
        },
        loadFieldDraftFromRow(f) {
            if (!fieldSchema || !f || !this.state.form.id) {
                return;
            }
            this.state.fieldDraft = fieldSchema.fromApiRow(f, this.state.form.id);
            this._fieldDraftBaseline = fieldSchema.snapshot(this.state.fieldDraft);
        },
        openFieldModal(title, saveLabel) {
            var self = this;
            if (!fieldSchema) {
                return;
            }
            this._fieldDraftBaseline = fieldSchema.snapshot(this.state.fieldDraft);
            this.openEkoModal({
                title: title,
                saveLabel: saveLabel,
                cancelLabel: 'Cancel',
                isDirty: function () {
                    return fieldSchema.isDirty(self._fieldDraftBaseline, self.state.fieldDraft);
                },
                onSave: function () {
                    return self.saveField(true);
                },
                onCancel: function () {
                    self.resetFieldDraft();
                },
            });
        },
        editField(f) {
            this.loadFieldDraftFromRow(f);
            this.openFieldModal('Edit dynamic field', 'Save field');
        },
        openAddFieldModal() {
            if (!this.state.form.id) {
                this.error = 'Save the service first before adding dynamic fields.';
                return;
            }
            this.resetFieldDraft();
            this.openFieldModal('Add dynamic field', 'Add field');
        },
        failField(message, fromModal) {
            const msg = String(message || '');
            if (fromModal) {
                throw new Error(msg);
            }
            this.error = msg;
            return false;
        },
        reset() {
            this.destroyFieldSortable();
            this.state.form = { id: 0, nome: '', descricao: '', is_global: 0 };
            this.state.fields = [];
        },
        async save() {
            this.error = null;
            const payload = { nome: this.state.form.nome, descricao: this.state.form.descricao };
            if (this.isAdmin) {
                payload.is_global = parseInt(String(this.state.form.is_global), 10) ? 1 : 0;
            }
            try {
                let id = parseInt(String(this.state.form.id || 0), 10);
                if (id) {
                    await window.ekoSampaApi('services/' + id, { method: 'PATCH', body: payload });
                } else {
                    const created = await window.ekoSampaApi('services', { method: 'POST', body: payload });
                    id = created && created.id ? parseInt(String(created.id), 10) : 0;
                }
                if (id) {
                    if (this.mode === 'new') {
                        window.location.href = this.editUrl(id);
                    } else {
                        this.goToView(id);
                    }
                } else {
                    this.goToList();
                }
            } catch (e) {
                this.error = String(e.message || e);
            }
        },
        async remove(id) {
            if (!window.confirm('OK?')) {
                return;
            }
            try {
                await window.ekoSampaApi('services/' + id, { method: 'DELETE' });
                this.goToList();
            } catch (e) {
                this.error = String(e.message || e);
            }
        },
        newField() {
            this.resetFieldDraft();
        },
        fieldDraftFlat() {
            return fieldSchema ? fieldSchema.toFlat(this.state.fieldDraft) : {};
        },
        /**
         * Approximates WordPress {@see sanitize_title()} for duplicate checks (ASCII + Latin-1).
         */
        slugifyFieldSlug(raw) {
            let s = String(raw == null ? '' : raw).trim();
            try {
                s = s.normalize('NFD').replace(/\p{M}+/gu, '');
            } catch (e) {
                s = s.replace(/[\u0300-\u036f]/g, '');
            }
            return s
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
        },
        async saveField(fromModal) {
            const modal = !!fromModal;
            const sid = parseInt(String(this.state.form.id || 0), 10);
            const def = this.state.fieldDraft && this.state.fieldDraft.definition ? this.state.fieldDraft.definition : {};
            const meta = this.state.fieldDraft && this.state.fieldDraft.meta ? this.state.fieldDraft.meta : {};
            if (!sid) {
                return this.failField('Save the service first before adding dynamic fields.', modal);
            }
            if (!def.label || !def.slug) {
                return this.failField('Label and slug are required.', modal);
            }
            const cand = this.slugifyFieldSlug(def.slug);
            if (!cand) {
                return this.failField('Use a slug with letters or numbers (hyphens allowed).', modal);
            }
            const fid = Number(meta.id) || 0;
            const dup = (this.state.fields || []).some((f) => {
                if (!f || Number(f.id) === fid) {
                    return false;
                }
                const existing = String(f.slug || '').toLowerCase();
                return existing === cand || this.slugifyFieldSlug(f.slug) === cand;
            });
            if (dup) {
                return this.failField(
                    'This slug is already used for another field in this service. Pick a different slug or edit the existing field.',
                    modal
                );
            }
            if (!modal) {
                this.error = null;
            }
            let optionsPayload = def.options_json;
            if (def.type === 'select') {
                const raw = typeof optionsPayload === 'string' ? optionsPayload.trim() : '';
                if (raw === '') {
                    optionsPayload = [];
                } else {
                    try {
                        const parsed = JSON.parse(raw);
                        optionsPayload = Array.isArray(parsed) ? parsed : [String(parsed)];
                    } catch (e) {
                        return this.failField(
                            'Select options must be valid JSON (e.g. ["A","B"] or [{"value":"a","label":"A"}]).',
                            modal
                        );
                    }
                }
            } else {
                optionsPayload = null;
            }
            const body = JSON.parse(
                JSON.stringify({
                    label: def.label,
                    slug: cand,
                    type: def.type,
                    required: parseInt(String(def.required), 10) ? 1 : 0,
                    options_json: optionsPayload,
                    sort_order: Number(meta.sort_order) || 0,
                    default_value: def.default_value != null ? String(def.default_value) : '',
                    placeholder: def.placeholder != null ? String(def.placeholder) : '',
                    show_in_template: parseInt(String(def.show_in_template), 10) ? 1 : 0,
                })
            );
            const flat = fieldSchema.toFlat(this.state.fieldDraft);
            const engine = window.ekoSampaValidationEngine;
            if (engine && typeof engine.validateAsync === 'function') {
                const check = await engine.validateAsync(flat, {
                    serviceId: sid,
                    fieldId: meta.id || 0,
                    fields: this.state.fields,
                });
                if (!check.valid) {
                    return this.failField(check.errors[0] || 'Validation failed.', modal);
                }
            } else if (engine && typeof engine.validate === 'function') {
                const checkSync = engine.validate(flat);
                if (!checkSync.valid) {
                    return this.failField(checkSync.errors[0] || 'Validation failed.', modal);
                }
            }
            const vr =
                engine && typeof engine.buildRestValidationPayload === 'function'
                    ? engine.buildRestValidationPayload(flat)
                    : window.ekoSampaFieldValidation.buildRestValidationPayload(flat);
            if (vr === false) {
                return this.failField(
                    'This field has stored validation rules that are not valid JSON. Fix or clear them in the database, then reload.',
                    modal
                );
            }
            if (meta.id) {
                body.validation_rules_json = vr;
            } else if (vr !== null) {
                body.validation_rules_json = vr;
            }

            const requestId = this._fieldsRequestId('save');
            const listSnapshot = this._fieldsSnapshot();
            const optimisticRow = Object.assign({}, body, {
                id: meta.id || -Math.abs(Date.now() % 1000000),
                service_id: sid,
                _ekoSync: 'pending',
                _ekoRequestId: requestId,
            });
            if (meta.id) {
                this.state.fields = (this.state.fields || []).map(function (f) {
                    return Number(f.id) === Number(meta.id) ? Object.assign({}, f, optimisticRow) : f;
                });
            } else {
                this.state.fields = (this.state.fields || []).concat([optimisticRow]);
            }

            this._fieldsToastStart(requestId, meta.id ? 'Saving field…' : 'Adding field…');

            try {
                let saved;
                if (meta.id) {
                    saved = await window.ekoSampaApi('services/' + sid + '/fields/' + meta.id, { method: 'PATCH', body });
                } else {
                    saved = await window.ekoSampaApi('services/' + sid + '/fields', { method: 'POST', body });
                }
                this._fieldsToastSuccess(requestId, meta.id ? 'Field updated.' : 'Field added.');
                await this.loadFields(sid, { silent: true });
                this.resetFieldDraft();
                this._emitFieldsChanged(sid, {
                    fieldId: saved && saved.id ? saved.id : meta.id,
                    requestId: requestId,
                    action: meta.id ? 'update' : 'create',
                });
            } catch (e) {
                this._fieldsRollback(listSnapshot);
                this._fieldsToastError(requestId, String(e.message || e));
                if (modal) {
                    throw e;
                }
                this.error = String(e.message || e);
                await this.loadFields(sid, { silent: true });
            }
        },
        async deleteField(fid) {
            const sid = parseInt(String(this.state.form.id || 0), 10);
            const fieldId = parseInt(String(fid || 0), 10);
            if (!sid || !fieldId || !window.confirm('Remove this field?')) {
                return;
            }
            const snapshot = this._fieldsSnapshot();
            const requestId = this._fieldsRequestId('delete');
            this.state.fields = (this.state.fields || []).filter(function (f) {
                return Number(f.id) !== fieldId;
            });
            this._fieldsToastStart(requestId, 'Removing field…');
            try {
                await window.ekoSampaApi('services/' + sid + '/fields/' + fieldId, { method: 'DELETE' });
                this._fieldsToastSuccess(requestId, 'Field removed.');
                await this.loadFields(sid, { silent: true });
                this._emitFieldsChanged(sid, { fieldId: fieldId, requestId: requestId, action: 'delete' });
            } catch (e) {
                this._fieldsRollback(snapshot);
                this._fieldsToastError(requestId, String(e.message || e));
                this.error = String(e.message || e);
            }
        },
    }
    );
}

const EKO_TEMPLATES_VIEW_KEY = 'eko_sampa_templates_view';

function ekoTemplatesFactory() {
    return Object.assign({}, ekoCrudMixin(), {
        listView: 'grid',
        zoom: { open: false, src: '', title: '' },
        thumbState: {},
        _thumbReadyBound: false,
        _thumbBackfillRunning: false,
        state: {
            rows: [],
            record: {},
            placeholders: [],
            elementCount: 0,
            page: 1,
            pageSize: 30,
            hasNext: false,
            q: '',
            cat: '',
            filterUserId: '',
            users: [],
            form: {
                id: 0,
                nome: '',
                categoria: '',
                descricao: '',
                width_mm: 210,
                height_mm: 297,
                service_id: 0,
                product_id: 0,
                preview_image: '',
                user_id: '',
            },
        },
        loading: false,
        error: null,
        isAdmin: !!(window.ekoSampaRest && window.ekoSampaRest.isAdmin),
        onThumbnailReady(ev) {
            const detail = ev && ev.detail ? ev.detail : {};
            const tid = parseInt(String(detail.templateId || 0), 10);
            const row = detail.response;
            if (!tid || !row || typeof row !== 'object') {
                return;
            }
            const idx = this.state.rows.findIndex((r) => parseInt(String(r.id), 10) === tid);
            if (idx >= 0) {
                this.state.rows[idx] = Object.assign({}, this.state.rows[idx], row);
                delete this.thumbState['f' + tid];
                delete this.thumbState['l' + tid];
            }
        },
        async init() {
            this.initCrud();
            if (this.mode === 'list' && !this._thumbReadyBound) {
                this._thumbReadyBound = true;
                document.addEventListener('eko-sampa:thumbnail-ready', (ev) => this.onThumbnailReady(ev));
            }
            if (this.isAdmin) {
                try {
                    this.state.users = await window.ekoSampaApi('users', { method: 'GET' });
                } catch (e) {
                    this.state.users = [];
                }
            }
            if (this.mode === 'list') {
                this.initListView();
                await this.load();
                return;
            }
            if (this.mode === 'new') {
                this.reset();
                return;
            }
            await this.loadRecord();
        },
        initListView() {
            try {
                const v = localStorage.getItem(EKO_TEMPLATES_VIEW_KEY);
                if (v === 'grid' || v === 'list') {
                    this.listView = v;
                }
            } catch (e) {
                void e;
            }
        },
        setListView(mode) {
            this.listView = mode === 'list' ? 'list' : 'grid';
            try {
                localStorage.setItem(EKO_TEMPLATES_VIEW_KEY, this.listView);
            } catch (e) {
                void e;
            }
        },
        thumbnailSrc(r) {
            if (!r || !r.thumbnail_url) {
                return '';
            }
            return String(r.thumbnail_url);
        },
        thumbLoaded(id) {
            return !!this.thumbState['l' + id];
        },
        thumbFailed(id) {
            return !!this.thumbState['f' + id];
        },
        needsThumbnailBackfill(r) {
            if (!r || !r.id) {
                return false;
            }
            const st = r.thumbnail_state || (r.has_thumbnail ? 'ready' : 'missing');
            return st === 'missing' || st === 'stale' || st === 'failed';
        },
        async backfillMissingThumbnails() {
            const Ex = window.EkoThumbnailExport;
            if (!Ex || typeof Ex.captureAndUpload !== 'function' || this._thumbBackfillRunning) {
                return;
            }
            const missing = this.state.rows.filter((r) => this.needsThumbnailBackfill(r));
            if (!missing.length) {
                return;
            }
            this._thumbBackfillRunning = true;
            const limit = 4;
            try {
                for (let i = 0; i < Math.min(limit, missing.length); i++) {
                    const r = missing[i];
                    const tid = parseInt(String(r.id), 10);
                    if (!tid) {
                        continue;
                    }
                    try {
                        const full = await window.ekoSampaApi('templates/' + tid, { method: 'GET' });
                        const payload =
                            typeof Ex.payloadFromTemplateRow === 'function'
                                ? Ex.payloadFromTemplateRow(full)
                                : { width_mm: full.width_mm, height_mm: full.height_mm, elements: [] };
                        if (!payload.elements || !payload.elements.length) {
                            continue;
                        }
                        const res = await Ex.captureAndUpload(tid, payload, { source: 'catalog_backfill' });
                        const idx = this.state.rows.findIndex((row) => parseInt(String(row.id), 10) === tid);
                        if (idx >= 0 && res && typeof res === 'object') {
                            this.state.rows[idx] = Object.assign({}, this.state.rows[idx], res);
                            delete this.thumbState['f' + tid];
                            delete this.thumbState['l' + tid];
                        }
                    } catch (e) {
                        if (window.EKO_RENDER_DEBUG) {
                            // eslint-disable-next-line no-console
                            console.warn('[EkoThumbnail] backfill', tid, e);
                        }
                    }
                }
            } finally {
                this._thumbBackfillRunning = false;
            }
        },
        markThumbLoaded(id) {
            this.thumbState['l' + id] = true;
        },
        markThumbFailed(id) {
            this.thumbState['f' + id] = true;
        },
        formatDimensions(r) {
            const w = r && r.width_mm != null ? r.width_mm : 210;
            const h = r && r.height_mm != null ? r.height_mm : 297;
            return w + ' × ' + h + ' mm';
        },
        formatUpdated(r) {
            const raw = r && (r.updated_at || r.created_at);
            if (!raw) {
                return '';
            }
            try {
                const d = new Date(String(raw).replace(' ', 'T'));
                if (Number.isNaN(d.getTime())) {
                    return String(raw);
                }
                return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
            } catch (e) {
                return String(raw);
            }
        },
        openZoom(r) {
            const src = this.thumbnailSrc(r);
            if (!src) {
                return;
            }
            this.zoom = { open: true, src: src, title: r && r.nome ? String(r.nome) : '' };
            try {
                document.documentElement.classList.add('eko-modal-open');
            } catch (e) {
                void e;
            }
        },
        closeZoom() {
            this.zoom = { open: false, src: '', title: '' };
            try {
                document.documentElement.classList.remove('eko-modal-open');
            } catch (e) {
                void e;
            }
        },
        parseElementCount(jsonData) {
            if (!jsonData || typeof jsonData !== 'object') {
                return 0;
            }
            const els = jsonData.elements;
            return Array.isArray(els) ? els.length : 0;
        },
        async loadRecord() {
            if (!this.recordId) {
                this.error = 'Invalid record.';
                return;
            }
            this.loading = true;
            this.error = null;
            try {
                const row = await window.ekoSampaApi('templates/' + this.recordId, { method: 'GET' });
                if (this.mode === 'view') {
                    this.state.record = row && typeof row === 'object' ? row : {};
                    this.state.elementCount = this.parseElementCount(this.state.record.json_data);
                    try {
                        const ph = await window.ekoSampaApi('templates/' + this.recordId + '/placeholders', { method: 'GET' });
                        this.state.placeholders =
                            ph && typeof ph === 'object' && Array.isArray(ph.placeholders) ? ph.placeholders : [];
                    } catch (e) {
                        this.state.placeholders = [];
                    }
                } else {
                    this.applyFormRow(row);
                }
            } catch (e) {
                this.error = String(e.message || e);
            } finally {
                this.loading = false;
            }
        },
        applyFormRow(row) {
            const r = Object.assign({ user_id: '' }, row);
            r.product_id = r.product_id != null ? parseInt(String(r.product_id), 10) : 0;
            r.preview_image = r.preview_image != null ? String(r.preview_image) : '';
            r.width_mm = r.width_mm != null ? Number(r.width_mm) : 210;
            r.height_mm = r.height_mm != null ? Number(r.height_mm) : 297;
            r.service_id = r.service_id != null ? parseInt(String(r.service_id), 10) : 0;
            this.state.form = r;
        },
        async load() {
            if (this.mode !== 'list') {
                return;
            }
            this.loading = true;
            this.error = null;
            this.thumbState = {};
            const ps = this.state.pageSize;
            const qs = new URLSearchParams({
                limit: String(ps + 1),
                offset: String((this.state.page - 1) * ps),
            });
            if (this.state.q) {
                qs.set('s', this.state.q);
            }
            if (this.state.cat) {
                qs.set('categoria', this.state.cat);
            }
            if (this.isAdmin && this.state.filterUserId) {
                qs.set('filter_user_id', this.state.filterUserId);
            }
            try {
                const arr = await window.ekoSampaApi('templates?' + qs.toString(), { method: 'GET' });
                const list = Array.isArray(arr) ? arr : [];
                this.state.hasNext = list.length > ps;
                this.state.rows = list.slice(0, ps);
                this.$nextTick(() => this.backfillMissingThumbnails());
            } catch (e) {
                this.error = String(e.message || e);
            } finally {
                this.loading = false;
            }
        },
        prevPage() {
            if (this.state.page <= 1) {
                return;
            }
            this.state.page--;
            this.load();
        },
        nextPage() {
            if (!this.state.hasNext) {
                return;
            }
            this.state.page++;
            this.load();
        },
        editorUrl(id) {
            const idStr = String(id);
            let base = (window.ekoSampaRest && window.ekoSampaRest.urls && window.ekoSampaRest.urls.editor) || '';
            if (typeof base !== 'string') {
                base = '';
            }
            if (!base) {
                return '/eko-sampa_editor/?template_id=' + encodeURIComponent(idStr);
            }
            try {
                const u = new URL(base, window.location.origin);
                u.searchParams.set('template_id', idStr);
                return u.toString();
            } catch (e) {
                const sep = base.indexOf('?') >= 0 ? '&' : '?';
                return base + sep + 'template_id=' + encodeURIComponent(idStr);
            }
        },
        async duplicate(id) {
            try {
                await window.ekoSampaApi('templates/' + id + '/duplicate', { method: 'POST' });
                if (this.mode === 'list') {
                    await this.load();
                } else {
                    this.goToList();
                }
            } catch (e) {
                this.error = String(e.message || e);
            }
        },
        reset() {
            this.state.form = {
                id: 0,
                nome: '',
                categoria: '',
                descricao: '',
                width_mm: 210,
                height_mm: 297,
                service_id: 0,
                product_id: 0,
                preview_image: '',
                user_id: '',
            };
        },
        async save() {
            this.error = null;
            const payload = { ...this.state.form };
            delete payload.id;
            delete payload.json_data;
            delete payload.created_at;
            delete payload.updated_at;
            payload.product_id = parseInt(String(payload.product_id || 0), 10);
            payload.preview_image = payload.preview_image != null ? String(payload.preview_image) : '';
            try {
                let id = parseInt(String(this.state.form.id || 0), 10);
                if (id) {
                    await window.ekoSampaApi('templates/' + id, { method: 'PATCH', body: payload });
                } else {
                    if (this.isAdmin && this.state.form.user_id) {
                        payload.user_id = parseInt(String(this.state.form.user_id), 10);
                    }
                    const created = await window.ekoSampaApi('templates', {
                        method: 'POST',
                        body: Object.assign({ json_data: { elements: [] } }, payload),
                    });
                    id = created && created.id ? parseInt(String(created.id), 10) : 0;
                }
                if (id) {
                    this.goToView(id);
                } else {
                    this.goToList();
                }
            } catch (e) {
                this.error = String(e.message || e);
            }
        },
        async remove(id) {
            if (!window.confirm('OK?')) {
                return;
            }
            try {
                await window.ekoSampaApi('templates/' + id, { method: 'DELETE' });
                this.goToList();
            } catch (e) {
                this.error = String(e.message || e);
            }
        },
    });
}

function ekoOrdersFactory() {
    return Object.assign({}, ekoCrudMixin(), {
        state: {
            rows: [],
            record: {},
            page: 1,
            pageSize: 30,
            hasNext: false,
            clients: [],
            services: [],
            templates: [],
            serviceFields: [],
            templatePlaceholderSet: {},
            q: '',
            status: '',
            filterUserId: '',
            users: [],
            previewSrcdoc: '',
            previewDraftTimer: null,
            form: {
                id: 0,
                client_id: 0,
                service_id: '',
                template_id: '',
                status: 'pending',
                dynamic_data_json: {},
                user_id: '',
                woo_order_id: 0,
                print_ready: 0,
            },
        },
        loading: false,
        error: null,
        isAdmin: !!(window.ekoSampaRest && window.ekoSampaRest.isAdmin),
        wrapPreviewSrcdoc(inner) {
            const body = String(inner || '');
            return (
                '<!DOCTYPE html><html><head><meta charset="utf-8"><style>html,body{margin:0;background:#f8fafc}</style></head><body>' +
                body +
                '</body></html>'
            );
        },
        fieldAffectsPreview(f) {
            const slug = f && f.slug != null ? String(f.slug) : '';
            const k = ekoSampaNormalizeDynamicKey(slug);
            if (!k) {
                return false;
            }
            const set = this.state.templatePlaceholderSet || {};
            return !!set[k];
        },
        printServiceFields() {
            const list = Array.isArray(this.state.serviceFields) ? this.state.serviceFields : [];
            return list.filter((f) => parseInt(String(f && f.show_in_template != null ? f.show_in_template : 1), 10) !== 0);
        },
        operationalServiceFields() {
            const list = Array.isArray(this.state.serviceFields) ? this.state.serviceFields : [];
            return list.filter((f) => parseInt(String(f && f.show_in_template != null ? f.show_in_template : 1), 10) === 0);
        },
        applyTemplatePlaceholdersFromResponse(data) {
            if (data && typeof data === 'object' && Array.isArray(data.template_placeholders)) {
                const o = {};
                data.template_placeholders.forEach((k) => {
                    const key = String(k || '').trim();
                    if (key) {
                        o[key] = true;
                    }
                });
                this.state.templatePlaceholderSet = o;
            } else if (data && typeof data === 'object') {
                this.state.templatePlaceholderSet = {};
            }
        },
        fieldSelectOptions(f) {
            try {
                const raw = f && f.options_json;
                if (raw == null || raw === '') {
                    return [];
                }
                const arr = typeof raw === 'string' ? JSON.parse(raw) : raw;
                if (!Array.isArray(arr)) {
                    return [];
                }
                return arr.map((x) => {
                    if (typeof x === 'string') {
                        return { value: x, label: x };
                    }
                    const v = x.value != null ? String(x.value) : String(x.v != null ? x.v : '');
                    const lab = x.label != null ? String(x.label) : String(x.l != null ? x.l : v);
                    return { value: v, label: lab };
                });
            } catch (e) {
                return [];
            }
        },
        async init() {
            this.initCrud();
            if (this.isAdmin) {
                try {
                    this.state.users = await window.ekoSampaApi('users', { method: 'GET' });
                } catch (e) {
                    this.state.users = [];
                }
            }
            if (this.mode === 'list') {
                await this.load();
                return;
            }
            if (this.mode === 'new') {
                await this.loadLookups();
                this.reset();
                this.bindFormWatchers();
                return;
            }
            if (this.mode === 'view') {
                await this.loadRecord();
                if (this.recordId) {
                    await this.fetchPreview(this.recordId);
                }
                return;
            }
            if (this.mode === 'edit') {
                await this.loadLookups();
                await this.loadRecord();
                this.bindFormWatchers();
            }
        },
        bindFormWatchers() {
            this.$watch('state.form.service_id', () => {
                this.onServiceChange();
            });
            this.$watch('state.form.template_id', () => {
                this.schedulePreviewDraft();
            });
            this.$watch('state.form.client_id', () => {
                this.schedulePreviewDraft();
            });
            this.$watch(
                'state.form.dynamic_data_json',
                () => {
                    this.schedulePreviewDraft();
                },
                { deep: true }
            );
        },
        async loadRecord() {
            if (!this.recordId) {
                this.error = 'Invalid record.';
                return;
            }
            this.loading = true;
            this.error = null;
            try {
                const row = await window.ekoSampaApi('orders/' + this.recordId, { method: 'GET' });
                if (this.mode === 'view') {
                    this.state.record = row && typeof row === 'object' ? row : {};
                    if (typeof this.state.record.dynamic_data_json === 'string' && this.state.record.dynamic_data_json) {
                        try {
                            this.state.record.dynamic_data_json = JSON.parse(this.state.record.dynamic_data_json);
                        } catch (e) {
                            this.state.record.dynamic_data_json = {};
                        }
                    } else if (
                        !this.state.record.dynamic_data_json ||
                        typeof this.state.record.dynamic_data_json !== 'object'
                    ) {
                        this.state.record.dynamic_data_json = {};
                    }
                    const sid = parseInt(String(this.state.record.service_id || 0), 10);
                    if (sid) {
                        try {
                            const fields = await window.ekoSampaApi('services/' + sid + '/fields', { method: 'GET' });
                            this.state.serviceFields = Array.isArray(fields) ? fields : [];
                        } catch (e) {
                            this.state.serviceFields = [];
                        }
                    }
                } else {
                    await this.applyOrderRow(row);
                }
            } catch (e) {
                this.error = String(e.message || e);
            } finally {
                this.loading = false;
            }
        },
        async applyOrderRow(row) {
            const r = Object.assign({}, row);
            r.woo_order_id = r.woo_order_id != null ? parseInt(String(r.woo_order_id), 10) : 0;
            r.print_ready = parseInt(String(r.print_ready != null ? r.print_ready : 0), 10) ? 1 : 0;
            r.client_id = r.client_id != null && String(r.client_id) !== '' ? parseInt(String(r.client_id), 10) : 0;
            this.state.form = r;
            let d = {};
            if (typeof this.state.form.dynamic_data_json === 'string' && this.state.form.dynamic_data_json) {
                try {
                    d = JSON.parse(this.state.form.dynamic_data_json);
                } catch (e) {
                    d = {};
                }
            } else if (typeof this.state.form.dynamic_data_json === 'object') {
                d = this.state.form.dynamic_data_json || {};
            }
            this.state.form.dynamic_data_json = d;
            await this.$nextTick();
            await this.onServiceChange();
            this.schedulePreviewDraft();
        },
        async loadLookups() {
            try {
                const qs = new URLSearchParams({ limit: '500' });
                if (this.isAdmin && this.state.filterUserId) {
                    qs.set('filter_user_id', this.state.filterUserId);
                }
                const b = await window.ekoSampaApi('lookups/order-form?' + qs.toString(), { method: 'GET' });
                this.state.clients = Array.isArray(b.clients) ? b.clients : [];
                this.state.services = Array.isArray(b.services) ? b.services : [];
                this.state.templates = Array.isArray(b.templates) ? b.templates : [];
            } catch (e) {
                this.state.clients = [];
                this.state.services = [];
                this.state.templates = [];
            }
        },
        async load() {
            if (this.mode !== 'list') {
                return;
            }
            this.loading = true;
            this.error = null;
            const ps = this.state.pageSize;
            const qs = new URLSearchParams({
                limit: String(ps + 1),
                offset: String((this.state.page - 1) * ps),
            });
            if (this.state.q) {
                qs.set('s', this.state.q);
            }
            if (this.state.status) {
                qs.set('status', this.state.status);
            }
            if (this.isAdmin && this.state.filterUserId) {
                qs.set('filter_user_id', this.state.filterUserId);
            }
            try {
                const arr = await window.ekoSampaApi('orders?' + qs.toString(), { method: 'GET' });
                const list = Array.isArray(arr) ? arr : [];
                this.state.hasNext = list.length > ps;
                this.state.rows = list.slice(0, ps);
            } catch (e) {
                this.error = String(e.message || e);
            } finally {
                this.loading = false;
            }
        },
        prevPage() {
            if (this.state.page <= 1) {
                return;
            }
            this.state.page--;
            this.load();
        },
        nextPage() {
            if (!this.state.hasNext) {
                return;
            }
            this.state.page++;
            this.load();
        },
        reset() {
            this.state.form = {
                id: 0,
                client_id: 0,
                service_id: '',
                template_id: '',
                status: 'pending',
                dynamic_data_json: {},
                user_id: '',
                woo_order_id: 0,
                print_ready: 0,
            };
            this.state.serviceFields = [];
            this.state.previewSrcdoc = '';
            this.state.templatePlaceholderSet = {};
            try {
                window.dispatchEvent(new CustomEvent('eko-sampa:order-preview', { detail: null }));
            } catch (e) {
                void e;
            }
        },
        async onServiceChange() {
            const sid = parseInt(String(this.state.form.service_id || 0), 10);
            this.state.serviceFields = [];
            if (!sid) {
                this.schedulePreviewDraft();
                return;
            }
            try {
                const fields = await window.ekoSampaApi('services/' + sid + '/fields', { method: 'GET' });
                const list = Array.isArray(fields) ? fields.slice() : [];
                list.sort((a, b) => Number(a.sort_order || 0) - Number(b.sort_order || 0));
                this.state.serviceFields = list;
                const d = Object.assign({}, this.state.form.dynamic_data_json || {});
                list.forEach((f) => {
                    const sk = String(f.slug || '').trim();
                    if (!sk) {
                        return;
                    }
                    const def = f.default_value != null ? String(f.default_value) : '';
                    if (!(sk in d)) {
                        d[sk] = def !== '' ? def : '';
                    } else if ((d[sk] === '' || d[sk] == null) && def !== '') {
                        d[sk] = def;
                    }
                });
                this.state.form.dynamic_data_json = d;
            } catch (e) {
                this.state.serviceFields = [];
            }
            this.schedulePreviewDraft();
        },
        schedulePreviewDraft() {
            clearTimeout(this.state.previewDraftTimer);
            this.state.previewDraftTimer = setTimeout(() => {
                this.refreshPreviewDraft();
            }, 220);
        },
        async refreshPreviewDraft() {
            const tid = parseInt(String(this.state.form.template_id || 0), 10);
            if (!tid) {
                this.state.previewSrcdoc = '';
                this.state.templatePlaceholderSet = {};
                try {
                    window.dispatchEvent(new CustomEvent('eko-sampa:order-preview', { detail: null }));
                } catch (e) {
                    void e;
                }
                return;
            }
            const payload = {
                template_id: tid,
                client_id: parseInt(String(this.state.form.client_id || 0), 10),
                id: parseInt(String(this.state.form.id || 0), 10),
                dynamic_data_json: JSON.parse(
                    JSON.stringify(
                        this.state.form.dynamic_data_json && typeof this.state.form.dynamic_data_json === 'object'
                            ? this.state.form.dynamic_data_json
                            : {}
                    )
                ),
            };
            let dbg = false;
            try {
                dbg =
                    (typeof window !== 'undefined' &&
                        window.ekoSampaRest &&
                        window.ekoSampaRest.debugRest) ||
                    (typeof sessionStorage !== 'undefined' && sessionStorage.getItem('ekoSampaPreviewDebug') === '1');
            } catch (e) {
                void e;
            }
            try {
                if (dbg) {
                    console.warn('[eko-sampa preview] render-draft request', payload);
                }
                const data = await window.ekoSampaApi('orders/render-draft', {
                    method: 'POST',
                    body: payload,
                });
                this.applyTemplatePlaceholdersFromResponse(data);
                if (data && typeof data === 'object' && data.editorPreview && Array.isArray(data.editorPreview.elements)) {
                    const preview =
                        window.EkoCanvasRenderer && typeof window.EkoCanvasRenderer.normalizePayload === 'function'
                            ? window.EkoCanvasRenderer.normalizePayload(data.editorPreview)
                            : data.editorPreview;
                    try {
                        window.dispatchEvent(
                            new CustomEvent('eko-sampa:order-preview', { detail: preview })
                        );
                    } catch (e) {
                        void e;
                    }
                    this.state.previewSrcdoc = '';
                    return;
                }
                const inner =
                    typeof data === 'object' && data && data.html
                        ? String(data.html)
                        : '<p>Invalid preview.</p>';
                if (dbg) {
                    console.warn('[eko-sampa preview] render-draft html length', inner.length);
                }
                this.state.previewSrcdoc = this.wrapPreviewSrcdoc(inner);
            } catch (e) {
                if (dbg) {
                    console.warn('[eko-sampa preview] render-draft error', e);
                }
                const msg = String(e.message || e)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;');
                this.state.previewSrcdoc = this.wrapPreviewSrcdoc('<p>' + msg + '</p>');
            }
        },
        async save() {
            this.error = null;
            const dyn = JSON.parse(JSON.stringify(this.state.form.dynamic_data_json && typeof this.state.form.dynamic_data_json === 'object' ? this.state.form.dynamic_data_json : {}));
            const payload = {
                client_id: parseInt(String(this.state.form.client_id != null && this.state.form.client_id !== '' ? this.state.form.client_id : 0), 10),
                service_id: parseInt(String(this.state.form.service_id || 0), 10),
                template_id: parseInt(String(this.state.form.template_id || 0), 10),
                status: this.state.form.status,
                dynamic_data_json: dyn,
                woo_order_id: parseInt(String(this.state.form.woo_order_id || 0), 10),
                print_ready: parseInt(String(this.state.form.print_ready != null ? this.state.form.print_ready : 0), 10) ? 1 : 0,
            };
            if (this.isAdmin && this.state.form.user_id) {
                payload.user_id = parseInt(String(this.state.form.user_id), 10);
            }
            try {
                let id = parseInt(String(this.state.form.id || 0), 10);
                if (id) {
                    await window.ekoSampaApi('orders/' + id, { method: 'PATCH', body: payload });
                } else {
                    const created = await window.ekoSampaApi('orders', { method: 'POST', body: payload });
                    id = created && created.id ? parseInt(String(created.id), 10) : 0;
                }
                if (id) {
                    this.goToView(id);
                } else {
                    this.goToList();
                }
            } catch (e) {
                this.error = String(e.message || e);
            }
        },
        async remove(id) {
            if (!window.confirm('OK?')) {
                return;
            }
            try {
                await window.ekoSampaApi('orders/' + id, { method: 'DELETE' });
                this.goToList();
            } catch (e) {
                this.error = String(e.message || e);
            }
        },
        async dup(id) {
            try {
                const created = await window.ekoSampaApi('orders/' + id + '/duplicate', { method: 'POST' });
                const nid = created && created.id ? parseInt(String(created.id), 10) : 0;
                if (nid) {
                    window.location.href = this.viewUrl(nid);
                } else if (this.mode === 'list') {
                    await this.load();
                }
            } catch (e) {
                this.error = String(e.message || e);
            }
        },
        printUrl(id) {
            const sid = String(id);
            let p = (window.ekoSampaRest && window.ekoSampaRest.urls && window.ekoSampaRest.urls.print) || '';
            if (typeof p !== 'string') {
                p = '';
            }
            if (!p) {
                return '/eko-sampa_print/' + sid + '/';
            }
            return p.replace(/\/?$/, '/') + sid + '/';
        },
        async fetchPreview(id) {
            this.state.previewSrcdoc = '';
            try {
                const data = await window.ekoSampaApi('orders/' + id + '/render', { method: 'GET' });
                this.applyTemplatePlaceholdersFromResponse(data);
                if (data && typeof data === 'object' && data.editorPreview && Array.isArray(data.editorPreview.elements)) {
                    const preview =
                        window.EkoCanvasRenderer && typeof window.EkoCanvasRenderer.normalizePayload === 'function'
                            ? window.EkoCanvasRenderer.normalizePayload(data.editorPreview)
                            : data.editorPreview;
                    try {
                        window.dispatchEvent(
                            new CustomEvent('eko-sampa:order-preview', { detail: preview })
                        );
                    } catch (e) {
                        void e;
                    }
                    this.state.previewSrcdoc = '';
                    return;
                }
                const inner =
                    typeof data === 'object' && data && data.html
                        ? String(data.html)
                        : '<p>Invalid preview.</p>';
                this.state.previewSrcdoc = this.wrapPreviewSrcdoc(inner);
            } catch (e) {
                const msg = String(e.message || e)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;');
                this.state.previewSrcdoc = this.wrapPreviewSrcdoc('<p>' + msg + '</p>');
            }
        },
    });
}

window.ekoShellFactory = ekoShellFactory;
window.ekoClientsFactory = ekoClientsFactory;
window.ekoServicesFactory = ekoServicesFactory;
window.ekoTemplatesFactory = ekoTemplatesFactory;
window.ekoOrdersFactory = ekoOrdersFactory;

(function registerEkoModulesProbe() {
    var keys = ['ekoShellFactory', 'ekoClientsFactory', 'ekoServicesFactory', 'ekoTemplatesFactory', 'ekoOrdersFactory'];
    window.EkoModules = Object.assign(window.EkoModules || {}, {
        frontendAppJsFinished: true,
        finishedAtMs: Date.now(),
        factories: keys.filter(function (k) {
            return typeof window[k] === 'function';
        }),
        factoriesMissing: keys.filter(function (k) {
            return typeof window[k] !== 'function';
        }),
    });
})();

document.addEventListener('alpine:init', () => {
    window.EkoModules = Object.assign(window.EkoModules || {}, { alpineInitFired: true });
    Alpine.data('ekoShell', ekoShellFactory);
    Alpine.data('ekoClients', ekoClientsFactory);
    Alpine.data('ekoServices', ekoServicesFactory);
    Alpine.data('ekoTemplates', ekoTemplatesFactory);
    Alpine.data('ekoOrders', ekoOrdersFactory);
});

window.addEventListener('load', function () {
    window.EkoModules = Object.assign(window.EkoModules || {}, {
        alpineReady: typeof window.Alpine !== 'undefined',
    });
});
