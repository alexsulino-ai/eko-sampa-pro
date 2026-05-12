/**
 * Visual editor: Alpine state + Interact drag/resize + Sortable layers + REST persistence.
 */
(function () {
    if (typeof window.ekoSampaApi === 'function') {
        return;
    }
    window.ekoSampaApi = async function (path, opts) {
        const cfg = window.ekoSampaEditor || {};
        const root = String(cfg.root || '').replace(/\/?$/, '/');
        const url = root + String(path || '').replace(/^\//, '');
        const headers = Object.assign(
            { 'X-WP-Nonce': cfg.nonce || '' },
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
    };
})();

document.addEventListener('alpine:init', () => {
    Alpine.data('ekoEditorCanvas', () => ({
        zoomPercent: 100,
        minZoom: 30,
        maxZoom: 200,
        gridSize: 5,
        canvasWidth: 800,
        canvasHeight: 1131,
        widthMm: 210,
        heightMm: 297,

        minElementWidth: 10,
        minElementHeight: 10,

        elements: [],
        selectedId: null,
        galleryOpen: false,
        galleryItems: [],
        galleryLoading: false,
        saveState: '',
        saveTimer: null,
        layerSort: null,
        interactDebounceTimer: null,
        inlineOpen: false,
        inlineValue: '',
        inlineTargetId: null,

        get stageTransform() {
            const s = this.zoomPercent / 100;
            return `transform: scale(${s}); transform-origin: center center;`;
        },

        get elementsJson() {
            return JSON.stringify({ elements: this.elements }, null, 2);
        },

        init() {
            this.$nextTick(() => {
                this.$nextTick(() => this.bindInteract());
            });
            this.$watch('zoomPercent', () => {
                this.$nextTick(() => this.bindInteract());
            });
            this.$watch(
                'elements',
                () => {
                    clearTimeout(this.interactDebounceTimer);
                    this.interactDebounceTimer = setTimeout(() => {
                        this.$nextTick(() => {
                            this.bindInteract();
                            this.bindLayersSort();
                        });
                    }, 120);
                    this.scheduleSave();
                },
                { deep: true }
            );
            this.$watch('selectedId', () => {
                this.$nextTick(() => this.bindInteract());
            });
            this.loadFromServer();
        },

        destroy() {
            clearTimeout(this.saveTimer);
            clearTimeout(this.interactDebounceTimer);
            this.saveTimer = null;
            this.interactDebounceTimer = null;
            if (this.layerSort) {
                try {
                    this.layerSort.destroy();
                } catch (e) {
                    void e;
                }
                this.layerSort = null;
            }
            if (typeof interact === 'function') {
                document.querySelectorAll('.eko-sampa-editor__canvas .eko-sampa-editor__element').forEach((node) => {
                    try {
                        interact(node).unset();
                    } catch (e) {
                        void e;
                    }
                });
            }
        },

        api(path, opts) {
            if (typeof window.ekoSampaApi !== 'function') {
                return Promise.reject(new Error('API unavailable'));
            }
            return window.ekoSampaApi(path, opts);
        },

        cfg() {
            return window.ekoSampaEditor || {};
        },

        async loadFromServer() {
            const id = Number(this.cfg().templateId || 0);
            if (!id) {
                this.elements = this.defaultElements();
                return;
            }
            try {
                const row = await this.api('templates/' + id, { method: 'GET' });
                this.widthMm = Number(row.width_mm) || 210;
                this.heightMm = Number(row.height_mm) || 297;
                let doc = {};
                if (row.json_data) {
                    if (typeof row.json_data === 'string') {
                        try {
                            doc = JSON.parse(row.json_data) || {};
                        } catch (e) {
                            doc = {};
                        }
                    } else if (typeof row.json_data === 'object') {
                        doc = row.json_data;
                    }
                }
                if (Array.isArray(doc)) {
                    this.elements = doc;
                } else if (doc.elements && Array.isArray(doc.elements)) {
                    this.elements = doc.elements;
                } else {
                    this.elements = this.defaultElements();
                }
                this.normalizeElements();
            } catch (e) {
                this.elements = this.defaultElements();
                this.saveState = String(e.message || e);
            }
        },

        defaultElements() {
            return [
                { id: 'text_1', type: 'text', x: 40, y: 40, width: 240, height: 48, content: 'Texto', styles: { fontSize: 16, color: '#111827' } },
            ];
        },

        normalizeElements() {
            this.elements.forEach((el) => {
                if (!el.styles || typeof el.styles !== 'object') {
                    el.styles = { fontSize: 16, color: '#111827' };
                }
                if (el.type === 'image' && !el.src && el.content) {
                    el.src = el.content;
                }
            });
        },

        uid(prefix) {
            return prefix + '_' + Math.random().toString(36).slice(2, 9);
        },

        select(id) {
            this.selectedId = id;
        },

        addText() {
            this.elements.push({
                id: this.uid('text'),
                type: 'text',
                x: 60,
                y: 60,
                width: 220,
                height: 44,
                content: 'Novo texto',
                styles: { fontSize: 16, color: '#111827' },
            });
        },

        addPlaceholder() {
            const slug = window.prompt('Slug do placeholder (ex: nome_cliente)', 'campo');
            if (!slug) {
                return;
            }
            const token = '{{' + String(slug).trim() + '}}';
            this.elements.push({
                id: this.uid('ph'),
                type: 'placeholder',
                x: 80,
                y: 120,
                width: 260,
                height: 40,
                content: token,
                styles: { fontSize: 14, color: '#4f46e5' },
            });
        },

        addRectangle() {
            this.elements.push({
                id: this.uid('rect'),
                type: 'rectangle',
                x: 80,
                y: 180,
                width: 160,
                height: 100,
                content: '',
                styles: {},
            });
        },

        openGallery() {
            this.galleryOpen = true;
            this.refreshGallery();
        },

        async refreshGallery() {
            this.galleryLoading = true;
            try {
                this.galleryItems = await this.api('gallery', { method: 'GET' });
            } catch (e) {
                this.galleryItems = [];
                this.saveState = String(e.message || e);
            } finally {
                this.galleryLoading = false;
            }
        },

        pickGallery(url) {
            if (!url) {
                return;
            }
            if (this.selectedId) {
                const el = this.elements.find((x) => x.id === this.selectedId);
                if (el && (el.type === 'image' || el.type === 'text')) {
                    el.type = 'image';
                    el.src = url;
                    el.content = url;
                    this.galleryOpen = false;
                    return;
                }
            }
            this.elements.push({
                id: this.uid('img'),
                type: 'image',
                x: 100,
                y: 200,
                width: 200,
                height: 160,
                src: url,
                content: url,
                styles: {},
            });
            this.galleryOpen = false;
        },

        async uploadGallery(ev) {
            const input = ev.target;
            const f = input.files && input.files[0];
            if (!f) {
                return;
            }
            const fd = new FormData();
            fd.append('file', f);
            try {
                const res = await fetch(String(this.cfg().root || '').replace(/\/?$/, '/') + 'gallery', {
                    method: 'POST',
                    headers: { 'X-WP-Nonce': this.cfg().nonce || '' },
                    body: fd,
                    credentials: 'same-origin',
                });
                let data = {};
                try {
                    data = await res.json();
                } catch (e) {
                    data = {};
                }
                if (!res.ok) {
                    const msg =
                        (data && typeof data.message === 'string' && data.message) ||
                        (data && data.code && String(data.code)) ||
                        'HTTP ' + res.status;
                    throw new Error(msg);
                }
                await this.refreshGallery();
                this.saveState = '';
            } catch (e) {
                this.saveState = String(e.message || e);
            }
            input.value = '';
        },

        deleteSelected() {
            if (!this.selectedId) {
                return;
            }
            this.elements = this.elements.filter((e) => e.id !== this.selectedId);
            this.selectedId = null;
        },

        openInlineEdit(item) {
            if (item.type !== 'text' && item.type !== 'placeholder') {
                return;
            }
            this.inlineTargetId = item.id;
            this.inlineValue = item.content || '';
            this.inlineOpen = true;
        },

        applyInlineEdit() {
            const el = this.elements.find((x) => x.id === this.inlineTargetId);
            if (el) {
                el.content = this.inlineValue;
            }
            this.inlineOpen = false;
        },

        scheduleSave() {
            const id = Number(this.cfg().templateId || 0);
            if (!id) {
                return;
            }
            clearTimeout(this.saveTimer);
            this.saveTimer = setTimeout(() => this.persist(), 600);
        },

        async persist() {
            const id = Number(this.cfg().templateId || 0);
            if (!id) {
                return;
            }
            const runId = id;
            this.saveState = '…';
            try {
                const body = {
                    json_data: { elements: this.elements },
                    width_mm: this.widthMm,
                    height_mm: this.heightMm,
                };
                const raw = JSON.stringify(body);
                if (raw.length > 380000) {
                    this.saveState = 'JSON too large to save';
                    return;
                }
                await this.api('templates/' + id, {
                    method: 'PATCH',
                    body,
                });
                if (Number(this.cfg().templateId || 0) !== runId) {
                    return;
                }
                this.saveState = 'OK';
            } catch (e) {
                if (Number(this.cfg().templateId || 0) === runId) {
                    this.saveState = String(e.message || e);
                }
            }
        },

        bindLayersSort() {
            if (typeof Sortable === 'undefined') {
                return;
            }
            const el = this.$refs.layerList;
            if (!el) {
                return;
            }
            if (this.layerSort) {
                this.layerSort.destroy();
                this.layerSort = null;
            }
            const self = this;
            this.layerSort = Sortable.create(el, {
                animation: 150,
                draggable: '[data-layer-row]',
                onEnd(evt) {
                    const oldIndex = evt.oldIndex;
                    const newIndex = evt.newIndex;
                    if (oldIndex === newIndex || oldIndex == null || newIndex == null) {
                        return;
                    }
                    const arr = self.elements;
                    const moved = arr.splice(oldIndex, 1)[0];
                    arr.splice(newIndex, 0, moved);
                },
            });
        },

        bindInteract() {
            if (typeof interact !== 'function') {
                return;
            }

            const selector = '.eko-sampa-editor__canvas .eko-sampa-editor__element';

            document.querySelectorAll(selector).forEach((node) => {
                interact(node).unset();
            });

            const self = this;

            const nodes = document.querySelectorAll(selector);
            if (nodes.length === 0) {
                return;
            }

            nodes.forEach((node) => {
                interact(node)
                    .draggable({
                        ignoreFrom: '.eko-sampa-editor__resize-handle, .eko-sampa-editor__inline-hit',
                        inertia: false,
                        modifiers: [
                            interact.modifiers.restrict({
                                restriction: 'parent',
                                elementRect: { top: 0, left: 0, bottom: 1, right: 1 },
                            }),
                        ],
                        listeners: {
                            move(event) {
                                event.target.style.transform = '';
                                const scale = self.zoomPercent / 100 || 1;
                                const id = event.target.getAttribute('data-element-id');
                                const item = self.elements.find((e) => e.id === id);
                                if (!item) {
                                    return;
                                }
                                item.x += event.dx / scale;
                                item.y += event.dy / scale;
                                self.clampPosition(item);
                            },
                            end(event) {
                                event.target.style.transform = '';
                                const id = event.target.getAttribute('data-element-id');
                                const item = self.elements.find((e) => e.id === id);
                                if (!item) {
                                    return;
                                }
                                self.snapTranslate(item);
                                self.clampPosition(item);
                            },
                        },
                    })
                    .resizable({
                        edges: { left: '.eko-resize-l', right: '.eko-resize-r', top: '.eko-resize-t', bottom: '.eko-resize-b' },
                        inertia: false,
                        modifiers: [
                            interact.modifiers.restrictSize({
                                min: { width: self.minElementWidth, height: self.minElementHeight },
                            }),
                        ],
                        listeners: {
                            move(event) {
                                event.target.style.transform = '';
                                const scale = self.zoomPercent / 100 || 1;
                                const id = event.target.getAttribute('data-element-id');
                                const item = self.elements.find((e) => e.id === id);
                                if (!item) {
                                    return;
                                }
                                const dl = event.deltaRect.left / scale;
                                const dt = event.deltaRect.top / scale;
                                const dr = event.deltaRect.right / scale;
                                const db = event.deltaRect.bottom / scale;
                                item.x += dl;
                                item.y += dt;
                                item.width += dr - dl;
                                item.height += db - dt;
                                item.width = Math.max(self.minElementWidth, item.width);
                                item.height = Math.max(self.minElementHeight, item.height);
                                self.clampPosition(item);
                                self.clampSize(item);
                            },
                            end(event) {
                                event.target.style.transform = '';
                                const id = event.target.getAttribute('data-element-id');
                                const item = self.elements.find((e) => e.id === id);
                                if (!item) {
                                    return;
                                }
                                self.snapBox(item);
                                self.clampPosition(item);
                                self.clampSize(item);
                            },
                        },
                    });
            });
        },

        clampPosition(item) {
            const maxX = this.canvasWidth - item.width;
            const maxY = this.canvasHeight - item.height;
            item.x = Math.max(0, Math.min(maxX, item.x));
            item.y = Math.max(0, Math.min(maxY, item.y));
        },

        clampSize(item) {
            item.width = Math.min(item.width, this.canvasWidth - item.x);
            item.height = Math.min(item.height, this.canvasHeight - item.y);
            item.width = Math.max(this.minElementWidth, item.width);
            item.height = Math.max(this.minElementHeight, item.height);
        },

        snapTranslate(item) {
            const g = this.gridSize;
            item.x = Math.round(item.x / g) * g;
            item.y = Math.round(item.y / g) * g;
        },

        snapBox(item) {
            const g = this.gridSize;
            item.x = Math.round(item.x / g) * g;
            item.y = Math.round(item.y / g) * g;
            item.width = Math.round(item.width / g) * g;
            item.height = Math.round(item.height / g) * g;
            item.width = Math.max(this.minElementWidth, item.width);
            item.height = Math.max(this.minElementHeight, item.height);
        },
    }));
});
