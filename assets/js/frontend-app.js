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
    return {
        state: {
            rows: [],
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
            if (this.isAdmin) {
                try {
                    this.state.users = await window.ekoSampaApi('users', { method: 'GET' });
                } catch (e) {
                    this.state.users = [];
                }
            }
            await this.load();
        },
        async load() {
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
        edit(row) {
            this.state.form = Object.assign({ user_id: '' }, row);
        },
        reset() {
            this.state.form = { id: 0, nome: '', email: '', telefone: '', documento: '', cidade: '', estado: '', user_id: '' };
        },
        async save() {
            this.error = null;
            const payload = { ...this.state.form };
            delete payload.id;
            try {
                if (this.state.form.id) {
                    await window.ekoSampaApi('clients/' + this.state.form.id, { method: 'PATCH', body: payload });
                } else {
                    if (this.isAdmin && this.state.form.user_id) {
                        payload.user_id = parseInt(String(this.state.form.user_id), 10);
                    }
                    await window.ekoSampaApi('clients', { method: 'POST', body: payload });
                }
                this.reset();
                await this.load();
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
                await this.load();
            } catch (e) {
                this.error = String(e.message || e);
            }
        },
    };
}

function ekoServicesFactory() {
    return {
        state: {
            rows: [],
            fields: [],
            page: 1,
            pageSize: 30,
            hasNext: false,
            q: '',
            filterUserId: '',
            users: [],
            form: { id: 0, nome: '', descricao: '', is_global: 0 },
            fieldForm: { id: 0, service_id: 0, label: '', slug: '', type: 'text', required: 0, options_json: null, sort_order: 0 },
        },
        loading: false,
        error: null,
        isAdmin: !!(window.ekoSampaRest && window.ekoSampaRest.isAdmin),
        async init() {
            if (this.isAdmin) {
                try {
                    this.state.users = await window.ekoSampaApi('users', { method: 'GET' });
                } catch (e) {
                    this.state.users = [];
                }
            }
            await this.load();
        },
        async load() {
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
        },
        edit(row) {
            this.state.form = Object.assign({ is_global: 0 }, row);
            this.loadFields(row.id);
            this.newField();
        },
        editField(f) {
            if (!f || !this.state.form.id) {
                return;
            }
            const req = f.required == null ? 0 : parseInt(String(f.required), 10) ? 1 : 0;
            let opt = f.options_json;
            if (opt != null && opt !== '' && typeof opt === 'object') {
                try {
                    opt = JSON.stringify(opt);
                } catch (e) {
                    opt = '';
                }
            } else if (opt != null && typeof opt !== 'string') {
                opt = String(opt);
            }
            this.state.fieldForm = {
                id: f.id,
                service_id: this.state.form.id,
                label: f.label != null ? String(f.label) : '',
                slug: f.slug != null ? String(f.slug) : '',
                type: f.type != null ? String(f.type) : 'text',
                required: req,
                options_json: opt != null && opt !== '' ? opt : f.type === 'select' ? '[]' : null,
                sort_order: f.sort_order != null ? Number(f.sort_order) : 0,
            };
        },
        reset() {
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
                if (this.state.form.id) {
                    await window.ekoSampaApi('services/' + this.state.form.id, { method: 'PATCH', body: payload });
                } else {
                    await window.ekoSampaApi('services', { method: 'POST', body: payload });
                }
                this.reset();
                await this.load();
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
                await this.load();
            } catch (e) {
                this.error = String(e.message || e);
            }
        },
        newField() {
            if (!this.state.form.id) {
                return;
            }
            this.state.fieldForm = {
                id: 0,
                service_id: this.state.form.id,
                label: '',
                slug: '',
                type: 'text',
                required: 0,
                options_json: null,
                sort_order: this.state.fields.length,
            };
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
        async saveField() {
            const sid = this.state.form.id;
            if (!sid) {
                return;
            }
            if (!this.state.fieldForm.label || !this.state.fieldForm.slug) {
                this.error = 'Label and slug are required.';
                return;
            }
            const cand = this.slugifyFieldSlug(this.state.fieldForm.slug);
            if (!cand) {
                this.error = 'Use a slug with letters or numbers (hyphens allowed).';
                return;
            }
            const fid = Number(this.state.fieldForm.id) || 0;
            const dup = (this.state.fields || []).some((f) => {
                if (!f || Number(f.id) === fid) {
                    return false;
                }
                const existing = String(f.slug || '').toLowerCase();
                return existing === cand || this.slugifyFieldSlug(f.slug) === cand;
            });
            if (dup) {
                this.error =
                    'This slug is already used for another field in this service. Pick a different slug or edit the existing field.';
                return;
            }
            this.error = null;
            let optionsPayload = this.state.fieldForm.options_json;
            if (this.state.fieldForm.type === 'select') {
                const raw = typeof optionsPayload === 'string' ? optionsPayload.trim() : '';
                if (raw === '') {
                    optionsPayload = [];
                } else {
                    try {
                        const parsed = JSON.parse(raw);
                        optionsPayload = Array.isArray(parsed) ? parsed : [String(parsed)];
                    } catch (e) {
                        this.error = 'Select options must be valid JSON (e.g. ["A","B"] or [{"value":"a","label":"A"}]).';
                        return;
                    }
                }
            } else {
                optionsPayload = null;
            }
            const body = JSON.parse(
                JSON.stringify({
                    label: this.state.fieldForm.label,
                    slug: cand,
                    type: this.state.fieldForm.type,
                    required: parseInt(String(this.state.fieldForm.required), 10) ? 1 : 0,
                    options_json: optionsPayload,
                    sort_order: Number(this.state.fieldForm.sort_order) || 0,
                })
            );
            try {
                if (this.state.fieldForm.id) {
                    await window.ekoSampaApi('services/' + sid + '/fields/' + this.state.fieldForm.id, { method: 'PATCH', body });
                } else {
                    await window.ekoSampaApi('services/' + sid + '/fields', { method: 'POST', body });
                }
                await this.loadFields(sid);
                this.newField();
            } catch (e) {
                this.error = String(e.message || e);
                await this.loadFields(sid, { silent: true });
            }
        },
        async deleteField(fid) {
            if (!this.state.form.id || !window.confirm('OK?')) {
                return;
            }
            try {
                await window.ekoSampaApi('services/' + this.state.form.id + '/fields/' + fid, { method: 'DELETE' });
                await this.loadFields(this.state.form.id);
            } catch (e) {
                this.error = String(e.message || e);
            }
        },
    };
}

function ekoTemplatesFactory() {
    return {
        state: {
            rows: [],
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
        async init() {
            if (this.isAdmin) {
                try {
                    this.state.users = await window.ekoSampaApi('users', { method: 'GET' });
                } catch (e) {
                    this.state.users = [];
                }
            }
            await this.load();
        },
        async load() {
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
                await this.load();
            } catch (e) {
                this.error = String(e.message || e);
            }
        },
        async preview(id) {
            window.open(this.editorUrl(id), '_blank');
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
                if (this.state.form.id) {
                    await window.ekoSampaApi('templates/' + this.state.form.id, { method: 'PATCH', body: payload });
                } else {
                    if (this.isAdmin && this.state.form.user_id) {
                        payload.user_id = parseInt(String(this.state.form.user_id), 10);
                    }
                    await window.ekoSampaApi('templates', { method: 'POST', body: Object.assign({ json_data: { elements: [] } }, payload) });
                }
                this.reset();
                await this.load();
            } catch (e) {
                this.error = String(e.message || e);
            }
        },
        edit(row) {
            const r = Object.assign({ user_id: '' }, row);
            r.product_id = r.product_id != null ? parseInt(String(r.product_id), 10) : 0;
            r.preview_image = r.preview_image != null ? String(r.preview_image) : '';
            r.width_mm = r.width_mm != null ? Number(r.width_mm) : 210;
            r.height_mm = r.height_mm != null ? Number(r.height_mm) : 297;
            r.service_id = r.service_id != null ? parseInt(String(r.service_id), 10) : 0;
            this.state.form = r;
        },
        async remove(id) {
            if (!window.confirm('OK?')) {
                return;
            }
            try {
                await window.ekoSampaApi('templates/' + id, { method: 'DELETE' });
                await this.load();
            } catch (e) {
                this.error = String(e.message || e);
            }
        },
    };
}

function ekoOrdersFactory() {
    return {
        state: {
            rows: [],
            page: 1,
            pageSize: 30,
            hasNext: false,
            clients: [],
            services: [],
            templates: [],
            serviceFields: [],
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
            if (this.isAdmin) {
                try {
                    this.state.users = await window.ekoSampaApi('users', { method: 'GET' });
                } catch (e) {
                    this.state.users = [];
                }
            }
            await this.loadLookups();
            await this.load();
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
            try {
                window.dispatchEvent(new CustomEvent('eko-sampa:order-preview', { detail: null }));
            } catch (e) {
                void e;
            }
        },
        async edit(row) {
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
                    if (!(sk in d)) {
                        d[sk] = '';
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
                if (data && typeof data === 'object' && data.editorPreview && Array.isArray(data.editorPreview.elements)) {
                    try {
                        window.dispatchEvent(
                            new CustomEvent('eko-sampa:order-preview', { detail: data.editorPreview })
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
                if (this.state.form.id) {
                    await window.ekoSampaApi('orders/' + this.state.form.id, { method: 'PATCH', body: payload });
                } else {
                    await window.ekoSampaApi('orders', { method: 'POST', body: payload });
                }
                this.reset();
                await this.load();
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
                await this.load();
            } catch (e) {
                this.error = String(e.message || e);
            }
        },
        async dup(id) {
            try {
                await window.ekoSampaApi('orders/' + id + '/duplicate', { method: 'POST' });
                await this.load();
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
                if (data && typeof data === 'object' && data.editorPreview && Array.isArray(data.editorPreview.elements)) {
                    try {
                        window.dispatchEvent(
                            new CustomEvent('eko-sampa:order-preview', { detail: data.editorPreview })
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
    };
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
