/**
 * REST helper for Eko Sampa frontend (cookie auth + wp_rest nonce).
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
            headers['Content-Type'] = 'application/json';
            body = JSON.stringify(body);
        }
        const res = await fetch(url, Object.assign({}, opts || {}, { headers, credentials: 'same-origin', body }));
        if (!res.ok) {
            let msg = res.statusText;
            try {
                const j = await res.json();
                if (j && j.message) {
                    msg = j.message;
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

document.addEventListener('alpine:init', () => {
    Alpine.data('ekoClients', () => ({
        rows: [],
        page: 1,
        pageSize: 30,
        hasNext: false,
        q: '',
        filterUserId: '',
        users: [],
        form: { id: 0, nome: '', email: '', telefone: '', documento: '', cidade: '', estado: '', user_id: '' },
        err: '',
        loading: false,
        isAdmin: !!(window.ekoSampaRest && window.ekoSampaRest.isAdmin),
        async init() {
            if (this.isAdmin) {
                try {
                    this.users = await window.ekoSampaApi('users', { method: 'GET' });
                } catch (e) {
                    this.users = [];
                }
            }
            await this.load();
        },
        async load() {
            this.loading = true;
            this.err = '';
            const ps = this.pageSize;
            const qs = new URLSearchParams({
                limit: String(ps + 1),
                offset: String((this.page - 1) * ps),
            });
            if (this.q) {
                qs.set('s', this.q);
            }
            if (this.isAdmin && this.filterUserId) {
                qs.set('filter_user_id', this.filterUserId);
            }
            try {
                const arr = await window.ekoSampaApi('clients?' + qs.toString(), { method: 'GET' });
                const list = Array.isArray(arr) ? arr : [];
                this.hasNext = list.length > ps;
                this.rows = list.slice(0, ps);
            } catch (e) {
                this.err = String(e.message || e);
            } finally {
                this.loading = false;
            }
        },
        prevPage() {
            if (this.page <= 1) {
                return;
            }
            this.page--;
            this.load();
        },
        nextPage() {
            if (!this.hasNext) {
                return;
            }
            this.page++;
            this.load();
        },
        edit(row) {
            this.form = Object.assign({ user_id: '' }, row);
        },
        reset() {
            this.form = { id: 0, nome: '', email: '', telefone: '', documento: '', cidade: '', estado: '', user_id: '' };
        },
        async save() {
            this.err = '';
            const payload = { ...this.form };
            delete payload.id;
            try {
                if (this.form.id) {
                    await window.ekoSampaApi('clients/' + this.form.id, { method: 'PATCH', body: payload });
                } else {
                    if (this.isAdmin && this.form.user_id) {
                        payload.user_id = parseInt(String(this.form.user_id), 10);
                    }
                    await window.ekoSampaApi('clients', { method: 'POST', body: payload });
                }
                this.reset();
                await this.load();
            } catch (e) {
                this.err = String(e.message || e);
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
                this.err = String(e.message || e);
            }
        },
    }));

    Alpine.data('ekoServices', () => ({
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
        err: '',
        loading: false,
        isAdmin: !!(window.ekoSampaRest && window.ekoSampaRest.isAdmin),
        async init() {
            if (this.isAdmin) {
                try {
                    this.users = await window.ekoSampaApi('users', { method: 'GET' });
                } catch (e) {
                    this.users = [];
                }
            }
            await this.load();
        },
        async load() {
            this.loading = true;
            this.err = '';
            const ps = this.pageSize;
            const qs = new URLSearchParams({
                limit: String(ps + 1),
                offset: String((this.page - 1) * ps),
            });
            if (this.q) {
                qs.set('s', this.q);
            }
            if (this.isAdmin && this.filterUserId) {
                qs.set('filter_user_id', this.filterUserId);
            }
            try {
                const arr = await window.ekoSampaApi('services?' + qs.toString(), { method: 'GET' });
                const list = Array.isArray(arr) ? arr : [];
                this.hasNext = list.length > ps;
                this.rows = list.slice(0, ps);
            } catch (e) {
                this.err = String(e.message || e);
            } finally {
                this.loading = false;
            }
        },
        prevPage() {
            if (this.page <= 1) {
                return;
            }
            this.page--;
            this.load();
        },
        nextPage() {
            if (!this.hasNext) {
                return;
            }
            this.page++;
            this.load();
        },
        async loadFields(sid) {
            try {
                this.fields = await window.ekoSampaApi('services/' + sid + '/fields', { method: 'GET' });
            } catch (e) {
                this.fields = [];
            }
        },
        edit(row) {
            this.form = Object.assign({ is_global: 0 }, row);
            this.loadFields(row.id);
            this.newField();
        },
        editField(f) {
            if (!f || !this.form.id) {
                return;
            }
            const req = f.required == null ? 0 : parseInt(String(f.required), 10) ? 1 : 0;
            this.fieldForm = {
                id: f.id,
                service_id: this.form.id,
                label: f.label != null ? String(f.label) : '',
                slug: f.slug != null ? String(f.slug) : '',
                type: f.type != null ? String(f.type) : 'text',
                required: req,
                options_json: f.options_json != null ? f.options_json : null,
                sort_order: f.sort_order != null ? Number(f.sort_order) : 0,
            };
        },
        reset() {
            this.form = { id: 0, nome: '', descricao: '', is_global: 0 };
            this.fields = [];
        },
        async save() {
            this.err = '';
            const payload = { nome: this.form.nome, descricao: this.form.descricao };
            if (this.isAdmin) {
                payload.is_global = parseInt(String(this.form.is_global), 10) ? 1 : 0;
            }
            try {
                if (this.form.id) {
                    await window.ekoSampaApi('services/' + this.form.id, { method: 'PATCH', body: payload });
                } else {
                    await window.ekoSampaApi('services', { method: 'POST', body: payload });
                }
                this.reset();
                await this.load();
            } catch (e) {
                this.err = String(e.message || e);
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
                this.err = String(e.message || e);
            }
        },
        newField() {
            if (!this.form.id) {
                return;
            }
            this.fieldForm = { id: 0, service_id: this.form.id, label: '', slug: '', type: 'text', required: 0, options_json: null, sort_order: this.fields.length };
        },
        async saveField() {
            const sid = this.form.id;
            if (!sid) {
                return;
            }
            if (!this.fieldForm.label || !this.fieldForm.slug) {
                this.err = 'Label and slug are required.';
                return;
            }
            this.err = '';
            const body = { ...this.fieldForm };
            delete body.id;
            delete body.service_id;
            try {
                if (this.fieldForm.id) {
                    await window.ekoSampaApi('services/' + sid + '/fields/' + this.fieldForm.id, { method: 'PATCH', body });
                } else {
                    await window.ekoSampaApi('services/' + sid + '/fields', { method: 'POST', body });
                }
                await this.loadFields(sid);
                this.newField();
            } catch (e) {
                this.err = String(e.message || e);
            }
        },
        async deleteField(fid) {
            if (!this.form.id || !window.confirm('OK?')) {
                return;
            }
            try {
                await window.ekoSampaApi('services/' + this.form.id + '/fields/' + fid, { method: 'DELETE' });
                await this.loadFields(this.form.id);
            } catch (e) {
                this.err = String(e.message || e);
            }
        },
    }));

    Alpine.data('ekoTemplates', () => ({
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
        err: '',
        loading: false,
        isAdmin: !!(window.ekoSampaRest && window.ekoSampaRest.isAdmin),
        async init() {
            if (this.isAdmin) {
                try {
                    this.users = await window.ekoSampaApi('users', { method: 'GET' });
                } catch (e) {
                    this.users = [];
                }
            }
            await this.load();
        },
        async load() {
            this.loading = true;
            this.err = '';
            const ps = this.pageSize;
            const qs = new URLSearchParams({
                limit: String(ps + 1),
                offset: String((this.page - 1) * ps),
            });
            if (this.q) {
                qs.set('s', this.q);
            }
            if (this.cat) {
                qs.set('categoria', this.cat);
            }
            if (this.isAdmin && this.filterUserId) {
                qs.set('filter_user_id', this.filterUserId);
            }
            try {
                const arr = await window.ekoSampaApi('templates?' + qs.toString(), { method: 'GET' });
                const list = Array.isArray(arr) ? arr : [];
                this.hasNext = list.length > ps;
                this.rows = list.slice(0, ps);
            } catch (e) {
                this.err = String(e.message || e);
            } finally {
                this.loading = false;
            }
        },
        prevPage() {
            if (this.page <= 1) {
                return;
            }
            this.page--;
            this.load();
        },
        nextPage() {
            if (!this.hasNext) {
                return;
            }
            this.page++;
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
                this.err = String(e.message || e);
            }
        },
        async preview(id) {
            window.open(this.editorUrl(id), '_blank');
        },
        reset() {
            this.form = {
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
            this.err = '';
            const payload = { ...this.form };
            delete payload.id;
            delete payload.json_data;
            delete payload.created_at;
            delete payload.updated_at;
            payload.product_id = parseInt(String(payload.product_id || 0), 10);
            payload.preview_image = payload.preview_image != null ? String(payload.preview_image) : '';
            try {
                if (this.form.id) {
                    await window.ekoSampaApi('templates/' + this.form.id, { method: 'PATCH', body: payload });
                } else {
                    if (this.isAdmin && this.form.user_id) {
                        payload.user_id = parseInt(String(this.form.user_id), 10);
                    }
                    await window.ekoSampaApi('templates', { method: 'POST', body: Object.assign({ json_data: { elements: [] } }, payload) });
                }
                this.reset();
                await this.load();
            } catch (e) {
                this.err = String(e.message || e);
            }
        },
        edit(row) {
            const r = Object.assign({ user_id: '' }, row);
            r.product_id = r.product_id != null ? parseInt(String(r.product_id), 10) : 0;
            r.preview_image = r.preview_image != null ? String(r.preview_image) : '';
            r.width_mm = r.width_mm != null ? Number(r.width_mm) : 210;
            r.height_mm = r.height_mm != null ? Number(r.height_mm) : 297;
            r.service_id = r.service_id != null ? parseInt(String(r.service_id), 10) : 0;
            this.form = r;
        },
        async remove(id) {
            if (!window.confirm('OK?')) {
                return;
            }
            try {
                await window.ekoSampaApi('templates/' + id, { method: 'DELETE' });
                await this.load();
            } catch (e) {
                this.err = String(e.message || e);
            }
        },
    }));

    Alpine.data('ekoOrders', () => ({
        rows: [],
        page: 1,
        pageSize: 30,
        hasNext: false,
        clients: [],
        services: [],
        templates: [],
        q: '',
        status: '',
        filterUserId: '',
        users: [],
        previewFrameSrc: 'about:blank',
        form: {
            id: 0,
            client_id: '',
            service_id: '',
            template_id: '',
            status: 'pending',
            dynamic_data_json: {},
            user_id: '',
            woo_order_id: 0,
            print_ready: 0,
        },
        dynKeys: '',
        dynVal: '',
        err: '',
        loading: false,
        isAdmin: !!(window.ekoSampaRest && window.ekoSampaRest.isAdmin),
        async init() {
            if (this.isAdmin) {
                try {
                    this.users = await window.ekoSampaApi('users', { method: 'GET' });
                } catch (e) {
                    this.users = [];
                }
            }
            await this.loadLookups();
            await this.load();
        },
        async loadLookups() {
            try {
                const qs = new URLSearchParams({ limit: '500' });
                if (this.isAdmin && this.filterUserId) {
                    qs.set('filter_user_id', this.filterUserId);
                }
                const b = await window.ekoSampaApi('lookups/order-form?' + qs.toString(), { method: 'GET' });
                this.clients = Array.isArray(b.clients) ? b.clients : [];
                this.services = Array.isArray(b.services) ? b.services : [];
                this.templates = Array.isArray(b.templates) ? b.templates : [];
            } catch (e) {
                this.clients = [];
                this.services = [];
                this.templates = [];
            }
        },
        async load() {
            this.loading = true;
            this.err = '';
            const ps = this.pageSize;
            const qs = new URLSearchParams({
                limit: String(ps + 1),
                offset: String((this.page - 1) * ps),
            });
            if (this.q) {
                qs.set('s', this.q);
            }
            if (this.status) {
                qs.set('status', this.status);
            }
            if (this.isAdmin && this.filterUserId) {
                qs.set('filter_user_id', this.filterUserId);
            }
            try {
                const arr = await window.ekoSampaApi('orders?' + qs.toString(), { method: 'GET' });
                const list = Array.isArray(arr) ? arr : [];
                this.hasNext = list.length > ps;
                this.rows = list.slice(0, ps);
            } catch (e) {
                this.err = String(e.message || e);
            } finally {
                this.loading = false;
            }
        },
        prevPage() {
            if (this.page <= 1) {
                return;
            }
            this.page--;
            this.load();
        },
        nextPage() {
            if (!this.hasNext) {
                return;
            }
            this.page++;
            this.load();
        },
        reset() {
            this.form = {
                id: 0,
                client_id: '',
                service_id: '',
                template_id: '',
                status: 'pending',
                dynamic_data_json: {},
                user_id: '',
                woo_order_id: 0,
                print_ready: 0,
            };
            this.dynKeys = '';
            this.dynVal = '';
        },
        edit(row) {
            const r = Object.assign({}, row);
            r.woo_order_id = r.woo_order_id != null ? parseInt(String(r.woo_order_id), 10) : 0;
            r.print_ready = parseInt(String(r.print_ready != null ? r.print_ready : 0), 10) ? 1 : 0;
            this.form = r;
            let d = {};
            if (typeof this.form.dynamic_data_json === 'string' && this.form.dynamic_data_json) {
                try {
                    d = JSON.parse(this.form.dynamic_data_json);
                } catch (e) {
                    d = {};
                }
            } else if (typeof this.form.dynamic_data_json === 'object') {
                d = this.form.dynamic_data_json || {};
            }
            this.form.dynamic_data_json = d;
        },
        setDyn() {
            if (!this.dynKeys) {
                return;
            }
            this.form.dynamic_data_json = Object.assign({}, this.form.dynamic_data_json || {}, { [this.dynKeys]: this.dynVal });
            this.dynKeys = '';
            this.dynVal = '';
        },
        async save() {
            this.err = '';
            const payload = {
                client_id: parseInt(String(this.form.client_id || 0), 10),
                service_id: parseInt(String(this.form.service_id || 0), 10),
                template_id: parseInt(String(this.form.template_id || 0), 10),
                status: this.form.status,
                dynamic_data_json: this.form.dynamic_data_json,
                woo_order_id: parseInt(String(this.form.woo_order_id || 0), 10),
                print_ready: parseInt(String(this.form.print_ready != null ? this.form.print_ready : 0), 10) ? 1 : 0,
            };
            if (this.isAdmin && this.form.user_id) {
                payload.user_id = parseInt(String(this.form.user_id), 10);
            }
            try {
                if (this.form.id) {
                    await window.ekoSampaApi('orders/' + this.form.id, { method: 'PATCH', body: payload });
                } else {
                    await window.ekoSampaApi('orders', { method: 'POST', body: payload });
                }
                this.reset();
                await this.load();
            } catch (e) {
                this.err = String(e.message || e);
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
                this.err = String(e.message || e);
            }
        },
        async dup(id) {
            try {
                await window.ekoSampaApi('orders/' + id + '/duplicate', { method: 'POST' });
                await this.load();
            } catch (e) {
                this.err = String(e.message || e);
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
            this.previewFrameSrc = 'about:blank';
            try {
                const data = await window.ekoSampaApi('orders/' + id + '/render', { method: 'GET' });
                const inner =
                    typeof data === 'object' && data && data.html
                        ? String(data.html)
                        : '<p>Invalid preview.</p>';
                const doc =
                    '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0;background:#f8fafc">' +
                    inner +
                    '</body></html>';
                this.previewFrameSrc = 'data:text/html;charset=utf-8,' + encodeURIComponent(doc);
            } catch (e) {
                const msg = String(e.message || e)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;');
                const doc =
                    '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:8px;font-family:sans-serif">' +
                    msg +
                    '</body></html>';
                this.previewFrameSrc = 'data:text/html;charset=utf-8,' + encodeURIComponent(doc);
            }
        },
    }));
});
