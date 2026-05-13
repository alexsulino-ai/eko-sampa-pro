/**
 * Visual editor: Alpine state + Interact drag/resize + Sortable layers + REST persistence.
 *
 * Load order: this file must run before Alpine (see `Eko_Sampa_Assets`). It assigns
 * `window.ekoEditorCanvasFactory` synchronously after parse, then registers `Alpine.data`
 * on `alpine:init`. Do not add Alpine as a wp_enqueue_script dependency.
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
            headers['Content-Type'] = 'application/json; charset=UTF-8';
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

/**
 * Alpine component factory. Hoisted and assigned to `window` immediately so
 * `x-data="window.ekoEditorCanvasFactory()"` works even if `Alpine.data` fails later.
 */
function ekoEditorCanvasFactory() {
    return {
        zoomPercent: 100,
        minZoom: 30,
        maxZoom: 200,
        gridSize: 5,
        widthMm: 210,
        heightMm: 297,
        /** Logical canvas px (synced from mm; not zoom). */
        canvasWidth: 794,
        canvasHeight: 1123,

        minElementWidth: 10,
        minElementHeight: 10,

        elements: [],
        selectedId: null,
        galleryOpen: false,
        galleryItems: [],
        galleryLoading: false,
        /** True after edits until a persist completes for the current revision */
        savePending: false,
        /** Last completed save message (e.g. Gravado) or error text */
        saveResult: '',
        saveTimer: null,
        layerSort: null,
        interactDebounceTimer: null,
        inlineOpen: false,
        inlineValue: '',
        inlineTargetId: null,
        /** UX: bring dragged element above siblings */
        draggingId: null,
        /** Monotonic token so only the latest scheduled persist updates UI */
        persistToken: 0,
        _persistRunning: false,
        _persistQueued: false,
        /** Embedded orders live preview: same canvas, no REST save / no interact */
        previewOnly: false,
        _orderPreviewListener: null,
        /** Last JSON string of elements array that was saved successfully (for revert) */
        lastOkElementsJson: null,
        maxElements: 400,
        _snapFlashTimer: null,
        /** Brief visual feedback after grid snap */
        snapFlash: false,
        /** Snapshot of `content` when opening inline editor (for cancel) */
        inlineSnapshot: '',

        get stageTransform() {
            const s = this.zoomPercent / 100;
            return `transform: scale(${s}); transform-origin: center center;`;
        },

        /** Toolbar line: pending / saving / result (preview: hidden to avoid layout flicker) */
        get saveLine() {
            if (this.previewOnly) {
                return '';
            }
            if (this._persistRunning) {
                return 'A gravar…';
            }
            if (this.savePending) {
                return 'Alterações por gravar';
            }
            if (this.saveResult) {
                return this.saveResult;
            }
            return '';
        },

        get selectedElement() {
            return this.elements.find((e) => e.id === this.selectedId) || null;
        },

        get elementsJson() {
            return JSON.stringify({ elements: this.elements }, null, 2);
        },

        get editorVersionLabel() {
            const v = this.cfg().pluginVersion;
            return v ? 'Eko Sampa v' + String(v) : 'Eko Sampa';
        },

        /**
         * Updates canvasWidth / canvasHeight from widthMm / heightMm.
         * Uses a local clamp so this never depends on other prototype methods during Alpine init.
         */
        syncLogicalCanvasSizeFromMm() {
            const pxPerMm = 96 / 25.4;
            const clamp = (n, lo, hi, fb) => {
                const x = Number(n);
                return !Number.isFinite(x) ? fb : Math.max(lo, Math.min(hi, x));
            };
            const wMm = clamp(this.widthMm, 10, 2000, 210);
            const hMm = clamp(this.heightMm, 10, 2000, 297);
            this.canvasWidth = Math.max(1, Math.round(wMm * pxPerMm));
            this.canvasHeight = Math.max(1, Math.round(hMm * pxPerMm));
        },

        zoomFactor() {
            const z = Number(this.zoomPercent) / 100;
            return Number.isFinite(z) && z > 0 ? z : 1;
        },

        canvasSurfaceStyle() {
            const g = Number(this.gridSize);
            const gs = Number.isFinite(g) && g > 0 ? g : 5;
            const w = Math.max(1, Math.round(Number(this.canvasWidth)));
            const h = Math.max(1, Math.round(Number(this.canvasHeight)));
            return (
                `width:${w}px;height:${h}px;` +
                'background-image:linear-gradient(to right, rgb(226 232 240) 1px, transparent 1px),' +
                'linear-gradient(to bottom, rgb(226 232 240) 1px, transparent 1px);' +
                `background-size:${gs}px ${gs}px`
            );
        },

        init() {
            this.previewOnly =
                !!(this.$el && this.$el.getAttribute && this.$el.getAttribute('data-eko-order-preview') === '1');
            if (this.previewOnly) {
                this.syncLogicalCanvasSizeFromMm();
                this.elements = [];
                this._orderPreviewListener = (ev) => {
                    this.applyOrderLivePreview(ev && ev.detail ? ev.detail : null);
                };
                window.addEventListener('eko-sampa:order-preview', this._orderPreviewListener);
                this.$watch('zoomPercent', () => {
                    this.$nextTick(() => {});
                });
                return;
            }
            this.syncLogicalCanvasSizeFromMm();
            this.$nextTick(() => {
                this.$nextTick(() => this.bindInteract());
            });
            this.$watch('zoomPercent', () => {
                this.$nextTick(() => this.bindInteract());
            });
            this.$watch(
                'elements',
                () => {
                    if (this.previewOnly) {
                        return;
                    }
                    clearTimeout(this.interactDebounceTimer);
                    this.interactDebounceTimer = setTimeout(() => {
                        this.$nextTick(() => {
                            if (this.draggingId) {
                                return;
                            }
                            this.bindInteract();
                            this.bindLayersSort();
                        });
                    }, 120);
                    this.scheduleSave();
                },
                { deep: true }
            );
            this.$watch('selectedId', (newId) => {
                if (this.inlineOpen && newId !== this.inlineTargetId) {
                    this.confirmInlineEdit();
                }
                this.$nextTick(() => this.bindInteract());
            });
            this.$watch('widthMm', () => {
                this.syncLogicalCanvasSizeFromMm();
                this.normalizeElements();
                this.$nextTick(() => this.bindInteract());
                this.scheduleSave();
            });
            this.$watch('heightMm', () => {
                this.syncLogicalCanvasSizeFromMm();
                this.normalizeElements();
                this.$nextTick(() => this.bindInteract());
                this.scheduleSave();
            });
            this.loadFromServer();
        },

        applyOrderLivePreview(detail) {
            if (!this.previewOnly) {
                return;
            }
            if (!detail || !Array.isArray(detail.elements)) {
                this.elements = [];
                return;
            }
            this.widthMm = Number(detail.width_mm) || 210;
            this.heightMm = Number(detail.height_mm) || 297;
            this.syncLogicalCanvasSizeFromMm();
            try {
                this.elements = JSON.parse(JSON.stringify(detail.elements));
            } catch (e) {
                this.elements = [];
                return;
            }
            this.normalizeElements();
            this.selectedId = null;
        },

        destroy() {
            if (this._orderPreviewListener) {
                window.removeEventListener('eko-sampa:order-preview', this._orderPreviewListener);
                this._orderPreviewListener = null;
            }
            clearTimeout(this.saveTimer);
            clearTimeout(this.interactDebounceTimer);
            clearTimeout(this._snapFlashTimer);
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
                this.syncLogicalCanvasSizeFromMm();
                this.elements = this.defaultElements();
                this.normalizeElements();
                this.lastOkElementsJson = this.captureElementsJson();
                this.savePending = false;
                this.saveResult = '';
                return;
            }
            try {
                const row = await this.api('templates/' + id, { method: 'GET' });
                this.widthMm = Number(row.width_mm) || 210;
                this.heightMm = Number(row.height_mm) || 297;
                this.syncLogicalCanvasSizeFromMm();
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
                const loaded = this.validateElementsForSave(this.elements);
                if (loaded.ok) {
                    this.elements = loaded.elements;
                } else {
                    this.elements = this.defaultElements();
                    this.saveResult = 'Layout em disco inválido — restaurado o modelo por omissão.';
                }
                this.normalizeElements();
                this.lastOkElementsJson = this.captureElementsJson();
                this.savePending = false;
                if (loaded.ok) {
                    this.saveResult = '';
                }
            } catch (e) {
                this.syncLogicalCanvasSizeFromMm();
                this.elements = this.defaultElements();
                this.normalizeElements();
                this.lastOkElementsJson = this.captureElementsJson();
                this.savePending = false;
                this.saveResult = String(e.message || e);
            }
        },

        defaultElements() {
            const w = this.canvasWidth;
            const h = this.canvasHeight;
            const tw = Math.min(240, Math.max(this.minElementWidth, w - 80));
            const th = 48;
            const tx = Math.max(0, Math.min(40, w - tw - 8));
            const ty = Math.max(0, Math.min(40, h - th - 8));
            return [
                { id: 'text_1', type: 'text', x: tx, y: ty, width: tw, height: th, content: 'Texto', styles: { fontSize: 16, color: '#111827' } },
            ];
        },

        normalizeElements() {
            this.elements = this.elements.filter((e) => e && typeof e === 'object');
            const seen = new Set();
            this.elements.forEach((el, idx) => {
                this.normalizeOneElement(el, idx, seen);
            });
        },

        defaultTextStyles() {
            return {
                fontFamily: 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
                fontSize: 16,
                fontWeight: '400',
                fontStyle: 'normal',
                textDecoration: 'none',
                textAlign: 'left',
                color: '#111827',
                backgroundColor: 'transparent',
                opacity: 1,
                borderWidth: 0,
                borderStyle: 'solid',
                borderColor: '#cbd5e1',
                borderRadius: 0,
                lineHeight: 1.35,
                letterSpacing: 0,
                textTransform: 'none',
                boxShadow: 'none',
                rotate: 0,
            };
        },

        defaultImageStyles() {
            return {
                opacity: 1,
                borderRadius: 0,
                borderWidth: 0,
                borderStyle: 'solid',
                borderColor: '#cbd5e1',
                rotate: 0,
                boxShadow: 'none',
                objectFit: 'cover',
            };
        },

        _clampNum(n, lo, hi, fallback) {
            const x = Number(n);
            if (!Number.isFinite(x)) {
                return fallback;
            }
            return Math.max(lo, Math.min(hi, x));
        },

        _safeCssColor(input, fallback) {
            const s = String(input == null ? '' : input).trim();
            if (s === '' || s.toLowerCase() === 'transparent') {
                return 'transparent';
            }
            if (/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(s)) {
                return s;
            }
            if (/^rgba?\(\s*[\d.]+\s*,\s*[\d.]+\s*,\s*[\d.]+\s*(,\s*[\d.]+\s*)?\)$/i.test(s)) {
                return s;
            }
            return fallback;
        },

        _safeBoxShadow(s) {
            const t = String(s == null ? '' : s).trim();
            if (t === '' || t.toLowerCase() === 'none') {
                return 'none';
            }
            if (t.length > 180 || /[<>;{}]|url\s*\(/i.test(t)) {
                return 'none';
            }
            return t;
        },

        _safeFontFamily(s) {
            const t = String(s == null ? '' : s).trim().slice(0, 220);
            if (t === '') {
                return this.defaultTextStyles().fontFamily;
            }
            if (/[<>{}"'`;]/.test(t)) {
                return this.defaultTextStyles().fontFamily;
            }
            return t;
        },

        /**
         * Defaults + sanitized user keys for supported types.
         *
         * @param {string} type
         * @param {object} raw
         * @returns {object}
         */
        sanitizedElementStyles(type, raw) {
            const r = raw && typeof raw === 'object' ? raw : {};
            if (type === 'image') {
                const d = this.defaultImageStyles();
                const fit = String(r.objectFit || d.objectFit).toLowerCase();
                d.objectFit = ['contain', 'cover', 'fill', 'none', 'scale-down'].includes(fit) ? fit : 'cover';
                d.opacity = this._clampNum(r.opacity, 0, 1, d.opacity);
                d.borderRadius = this._clampNum(r.borderRadius, 0, 400, d.borderRadius);
                d.borderWidth = this._clampNum(r.borderWidth, 0, 40, d.borderWidth);
                const bs = String(r.borderStyle || d.borderStyle).toLowerCase();
                d.borderStyle = ['solid', 'dashed', 'dotted', 'none'].includes(bs) ? bs : 'solid';
                d.borderColor = this._safeCssColor(r.borderColor, d.borderColor);
                d.rotate = this._clampNum(r.rotate, -360, 360, d.rotate);
                d.boxShadow = this._safeBoxShadow(r.boxShadow != null ? r.boxShadow : d.boxShadow);
                return d;
            }
            if (type === 'text' || type === 'placeholder') {
                const d = this.defaultTextStyles();
                d.fontFamily = this._safeFontFamily(r.fontFamily != null ? r.fontFamily : d.fontFamily);
                d.fontSize = Math.round(this._clampNum(r.fontSize, 6, 200, d.fontSize));
                const fw = String(r.fontWeight || d.fontWeight);
                d.fontWeight = (() => {
                    const n = Math.round(Number(fw));
                    if (Number.isFinite(n) && n >= 100 && n <= 900) {
                        return String(n);
                    }
                    return '400';
                })();
                const fst = String(r.fontStyle || d.fontStyle).toLowerCase();
                d.fontStyle = fst === 'italic' ? 'italic' : 'normal';
                const td = String(r.textDecoration || d.textDecoration).toLowerCase();
                d.textDecoration = ['none', 'underline', 'line-through', 'underline line-through'].includes(td) ? td : 'none';
                const ta = String(r.textAlign || d.textAlign).toLowerCase();
                d.textAlign = ['left', 'center', 'right', 'justify'].includes(ta) ? ta : 'left';
                d.color = this._safeCssColor(r.color, d.color);
                d.backgroundColor = this._safeCssColor(r.backgroundColor, d.backgroundColor);
                d.opacity = this._clampNum(r.opacity, 0, 1, d.opacity);
                d.borderWidth = this._clampNum(r.borderWidth, 0, 40, d.borderWidth);
                const bst = String(r.borderStyle || d.borderStyle).toLowerCase();
                d.borderStyle = ['solid', 'dashed', 'dotted', 'none'].includes(bst) ? bst : 'solid';
                d.borderColor = this._safeCssColor(r.borderColor, d.borderColor);
                d.borderRadius = this._clampNum(r.borderRadius, 0, 400, d.borderRadius);
                const lh = Number(r.lineHeight);
                d.lineHeight = Number.isFinite(lh) && lh > 0 && lh <= 4 ? lh : d.lineHeight;
                d.letterSpacing = this._clampNum(r.letterSpacing, -20, 40, d.letterSpacing);
                const tt = String(r.textTransform || d.textTransform).toLowerCase();
                d.textTransform = ['none', 'uppercase', 'lowercase', 'capitalize'].includes(tt) ? tt : 'none';
                d.boxShadow = this._safeBoxShadow(r.boxShadow != null ? r.boxShadow : d.boxShadow);
                d.rotate = this._clampNum(r.rotate, -360, 360, d.rotate);
                return d;
            }
            return r && typeof r === 'object' ? Object.assign({}, r) : {};
        },

        elementPositionStyle(item) {
            if (!item || typeof item !== 'object') {
                return 'position:absolute;left:0;top:0;width:100px;height:40px';
            }
            const x = Number(item.x);
            const y = Number(item.y);
            const w = Number(item.width);
            const h = Number(item.height);
            const left = Number.isFinite(x) ? x : 0;
            const top = Number.isFinite(y) ? y : 0;
            const width = Number.isFinite(w) ? w : this.minElementWidth;
            const height = Number.isFinite(h) ? h : this.minElementHeight;
            return `position:absolute;left:${left}px;top:${top}px;width:${width}px;height:${height}px`;
        },

        elementFrameCss(item) {
            const t = item.type;
            const st = item.styles || {};
            const op = this._clampNum(st.opacity, 0, 1, 1);
            const br = Math.max(0, Number(st.borderRadius) || 0);
            const bw = Math.max(0, Number(st.borderWidth) || 0);
            const bs = String(st.borderStyle || 'solid');
            const bc = this._safeCssColor(st.borderColor, '#cbd5e1');
            const sh = this._safeBoxShadow(st.boxShadow != null ? st.boxShadow : 'none');
            const rot = this._clampNum(st.rotate, -360, 360, 0);
            let border = 'none';
            if (bw > 0 && bs !== 'none') {
                border = `${bw}px ${bs} ${bc}`;
            }
            const parts = [
                'position:absolute',
                'left:0',
                'top:0',
                'width:100%',
                'height:100%',
                'box-sizing:border-box',
                `opacity:${op}`,
                `border-radius:${br}px`,
                `border:${border}`,
                `box-shadow:${sh}`,
                `transform:rotate(${rot}deg)`,
                'transform-origin:center center',
                'overflow:hidden',
            ];
            if (t === 'rectangle') {
                parts.push('background:#f1f5f9');
            }
            return parts.join(';');
        },

        textContentCss(item) {
            const st = item.styles || {};
            const d = this.defaultTextStyles();
            const ff = this._safeFontFamily(st.fontFamily || d.fontFamily);
            const fs = Math.round(this._clampNum(st.fontSize, 6, 200, d.fontSize));
            const fw = String(st.fontWeight || d.fontWeight);
            const fst = String(st.fontStyle || d.fontStyle);
            const td = String(st.textDecoration || d.textDecoration);
            const ta = String(st.textAlign || d.textAlign);
            const col = this._safeCssColor(st.color, d.color);
            const bg = this._safeCssColor(st.backgroundColor, 'transparent');
            const lh = Number(st.lineHeight);
            const lineH = Number.isFinite(lh) && lh > 0 && lh <= 4 ? String(lh) : String(d.lineHeight);
            const ls = this._clampNum(st.letterSpacing, -20, 40, 0);
            const tt = String(st.textTransform || d.textTransform);
            return [
                'width:100%',
                'height:100%',
                'box-sizing:border-box',
                `font-family:${ff.replace(/"/g, "'")}`,
                `font-size:${fs}px`,
                `font-weight:${fw}`,
                `font-style:${fst}`,
                `text-decoration:${td}`,
                `text-align:${ta}`,
                `color:${col}`,
                `background-color:${bg}`,
                `line-height:${lineH}`,
                `letter-spacing:${ls}px`,
                `text-transform:${tt}`,
                'white-space:pre-wrap',
                'word-break:break-word',
                'overflow:auto',
                'padding:4px 6px',
                'display:block',
                `text-align:${ta}`,
            ].join(';');
        },

        imageImgCss(item) {
            const st = item.styles || {};
            const fit = String(st.objectFit || 'cover').toLowerCase();
            const f = ['contain', 'cover', 'fill', 'none', 'scale-down'].includes(fit) ? fit : 'cover';
            return `width:100%;height:100%;display:block;object-fit:${f}`;
        },

        /** Toggle cover (default) ↔ contain on image click */
        toggleImageFit(item) {
            if (this.previewOnly) {
                return;
            }
            if (!item || item.type !== 'image') {
                return;
            }
            if (!item.styles || typeof item.styles !== 'object') {
                item.styles = this.sanitizedElementStyles('image', {});
            }
            const cur = String(item.styles.objectFit || 'cover').toLowerCase();
            item.styles.objectFit = cur === 'cover' ? 'contain' : 'cover';
        },

        triggerSnapFlash() {
            this.snapFlash = true;
            clearTimeout(this._snapFlashTimer);
            this._snapFlashTimer = setTimeout(() => {
                this.snapFlash = false;
            }, 200);
        },

        cancelInlineEdit() {
            if (!this.inlineOpen) {
                return;
            }
            const el = this.elements.find((x) => x.id === this.inlineTargetId);
            if (el) {
                el.content = this.inlineSnapshot;
            }
            this.inlineOpen = false;
            this.inlineTargetId = null;
            this.inlineValue = '';
            this.inlineSnapshot = '';
        },

        confirmInlineEdit() {
            const el = this.elements.find((x) => x.id === this.inlineTargetId);
            if (el) {
                el.content = this.inlineValue;
            }
            this.inlineOpen = false;
            this.inlineTargetId = null;
            this.inlineValue = '';
            this.inlineSnapshot = '';
        },

        sanitizeNumber(v, fallback) {
            const n = Number(v);
            return Number.isFinite(n) ? n : fallback;
        },

        /**
         * Coerce one element to a safe in-canvas shape (mutates el).
         *
         * @param {object} el
         * @param {number} idx
         * @param {Set<string>} seen
         */
        normalizeOneElement(el, idx, seen) {
            if (!el || typeof el !== 'object') {
                return;
            }
            const types = ['text', 'placeholder', 'rectangle', 'image'];
            let id = el.id != null ? String(el.id) : '';
            if (!id || seen.has(id)) {
                id = this.uid('el');
            }
            seen.add(id);
            el.id = id;
            el.type = types.includes(el.type) ? el.type : 'text';
            el.x = this.sanitizeNumber(el.x, 0);
            el.y = this.sanitizeNumber(el.y, 0);
            el.width = this.sanitizeNumber(el.width, this.minElementWidth);
            el.height = this.sanitizeNumber(el.height, this.minElementHeight);
            if (el.type === 'text' || el.type === 'placeholder' || el.type === 'image') {
                el.styles = this.sanitizedElementStyles(el.type, el.styles);
            } else {
                el.styles = el.styles && typeof el.styles === 'object' ? el.styles : {};
            }
            if (el.type === 'image' && !el.src && el.content) {
                el.src = el.content;
            }
            if (el.content == null) {
                el.content = '';
            } else {
                el.content = String(el.content);
            }
            this.clampElementInCanvas(el);
        },

        /** Clamp x,y,width,height together so the box stays inside the canvas. */
        clampElementInCanvas(item) {
            if (!item || typeof item !== 'object') {
                return;
            }
            item.width = Math.max(this.minElementWidth, this.sanitizeNumber(item.width, this.minElementWidth));
            item.height = Math.max(this.minElementHeight, this.sanitizeNumber(item.height, this.minElementHeight));
            item.width = Math.min(item.width, this.canvasWidth);
            item.height = Math.min(item.height, this.canvasHeight);
            item.x = this.sanitizeNumber(item.x, 0);
            item.y = this.sanitizeNumber(item.y, 0);
            item.x = Math.max(0, Math.min(this.canvasWidth - item.width, item.x));
            item.y = Math.max(0, Math.min(this.canvasHeight - item.height, item.y));
            item.width = Math.min(item.width, this.canvasWidth - item.x);
            item.height = Math.min(item.height, this.canvasHeight - item.y);
            item.width = Math.max(this.minElementWidth, item.width);
            item.height = Math.max(this.minElementHeight, item.height);
        },

        /**
         * @returns {{ ok: true, elements: object[] } | { ok: false, error: string }}
         */
        validateElementsForSave(elements) {
            if (!Array.isArray(elements)) {
                return { ok: false, error: 'Layout inválido.' };
            }
            if (elements.length > this.maxElements) {
                return { ok: false, error: 'Demasiados elementos (' + this.maxElements + ' máx.).' };
            }
            const types = ['text', 'placeholder', 'rectangle', 'image'];
            const seen = new Set();
            const out = [];
            for (let i = 0; i < elements.length; i++) {
                const raw = elements[i];
                if (!raw || typeof raw !== 'object') {
                    continue;
                }
                const type = types.includes(raw.type) ? raw.type : 'text';
                let id = raw.id != null ? String(raw.id) : '';
                if (!id || seen.has(id)) {
                    id = 'el_' + i + '_' + Math.random().toString(36).slice(2, 8);
                }
                seen.add(id);
                let x = this.sanitizeNumber(raw.x, 0);
                let y = this.sanitizeNumber(raw.y, 0);
                let w = this.sanitizeNumber(raw.width, this.minElementWidth);
                let h = this.sanitizeNumber(raw.height, this.minElementHeight);
                w = Math.max(this.minElementWidth, Math.min(w, this.canvasWidth));
                h = Math.max(this.minElementHeight, Math.min(h, this.canvasHeight));
                x = Math.max(0, Math.min(this.canvasWidth - w, x));
                y = Math.max(0, Math.min(this.canvasHeight - h, y));
                w = Math.min(w, this.canvasWidth - x);
                h = Math.min(h, this.canvasHeight - y);
                w = Math.max(this.minElementWidth, w);
                h = Math.max(this.minElementHeight, h);
                const styles =
                    type === 'text' || type === 'placeholder'
                        ? this.sanitizedElementStyles(type, raw.styles)
                        : type === 'image'
                          ? this.sanitizedElementStyles('image', raw.styles)
                          : raw.styles && typeof raw.styles === 'object'
                            ? Object.assign({}, raw.styles)
                            : {};
                let content = raw.content != null ? String(raw.content) : '';
                if (content.length > 50000) {
                    content = content.slice(0, 50000);
                }
                const el = {
                    id,
                    type,
                    x,
                    y,
                    width: w,
                    height: h,
                    content,
                    styles,
                };
                if (type === 'image') {
                    const src = raw.src != null ? String(raw.src) : content;
                    el.src = src.length > 2000 ? src.slice(0, 2000) : src;
                    el.content = el.src;
                }
                out.push(el);
            }
            if (out.length === 0) {
                return { ok: false, error: 'Nenhum elemento válido para gravar.' };
            }
            return { ok: true, elements: out };
        },

        captureElementsJson() {
            return JSON.stringify(this.elements);
        },

        restoreElementsFromJson(json) {
            try {
                const arr = JSON.parse(json);
                if (!Array.isArray(arr)) {
                    return false;
                }
                const v = this.validateElementsForSave(arr);
                if (!v.ok) {
                    return false;
                }
                this.elements = v.elements;
                this.normalizeElements();
                return true;
            } catch (e) {
                return false;
            }
        },

        uid(prefix) {
            return prefix + '_' + Math.random().toString(36).slice(2, 9);
        },

        select(id) {
            this.selectedId = id;
        },

        clearSelectionIfCanvas(ev) {
            if (this.galleryOpen) {
                return;
            }
            if (ev && typeof ev.button === 'number' && ev.button !== 0) {
                return;
            }
            if (this.inlineOpen) {
                this.confirmInlineEdit();
            }
            this.selectedId = null;
        },

        editorWindowKeydown(ev) {
            if (this.previewOnly) {
                return;
            }
            if (ev.key === 'Escape' && this.inlineOpen && !this.galleryOpen) {
                ev.preventDefault();
                this.cancelInlineEdit();
                return;
            }
            if (ev.key === 'Delete' && !this.inlineOpen && !this.galleryOpen) {
                this.deleteSelected();
            }
        },

        addText() {
            const bw = 220;
            const bh = 44;
            const cx = Math.max(0, Math.min(60, this.canvasWidth - bw));
            const cy = Math.max(0, Math.min(60, this.canvasHeight - bh));
            this.elements.push({
                id: this.uid('text'),
                type: 'text',
                x: cx,
                y: cy,
                width: bw,
                height: bh,
                content: 'Novo texto',
                styles: Object.assign({}, this.defaultTextStyles()),
            });
        },

        addPlaceholder() {
            const slug = window.prompt('Slug do placeholder (ex: nome_cliente)', 'campo');
            if (!slug) {
                return;
            }
            const token = '{{' + String(slug).trim() + '}}';
            const bw = Math.min(260, Math.max(this.minElementWidth, this.canvasWidth - 40));
            const bh = 40;
            const cx = Math.max(0, Math.min(80, this.canvasWidth - bw));
            const cy = Math.max(0, Math.min(120, this.canvasHeight - bh));
            this.elements.push({
                id: this.uid('ph'),
                type: 'placeholder',
                x: cx,
                y: cy,
                width: bw,
                height: bh,
                content: token,
                styles: Object.assign({}, this.defaultTextStyles(), { fontSize: 14, color: '#4f46e5' }),
            });
        },

        addRectangle() {
            const bw = Math.min(160, Math.max(this.minElementWidth, this.canvasWidth - 40));
            const bh = Math.min(100, Math.max(this.minElementHeight, this.canvasHeight - 40));
            const cx = Math.max(0, Math.min(80, this.canvasWidth - bw));
            const cy = Math.max(0, Math.min(180, this.canvasHeight - bh));
            this.elements.push({
                id: this.uid('rect'),
                type: 'rectangle',
                x: cx,
                y: cy,
                width: bw,
                height: bh,
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
                this.saveResult = String(e.message || e);
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
                    el.styles = this.sanitizedElementStyles('image', el.styles);
                    this.galleryOpen = false;
                    return;
                }
            }
            const bw = Math.min(200, Math.max(this.minElementWidth, Math.floor(this.canvasWidth * 0.35)));
            const bh = Math.min(160, Math.max(this.minElementHeight, Math.floor(this.canvasHeight * 0.2)));
            const cx = Math.max(0, Math.min(100, this.canvasWidth - bw));
            const cy = Math.max(0, Math.min(200, this.canvasHeight - bh));
            this.elements.push({
                id: this.uid('img'),
                type: 'image',
                x: cx,
                y: cy,
                width: bw,
                height: bh,
                src: url,
                content: url,
                styles: Object.assign({}, this.defaultImageStyles()),
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
                this.saveResult = '';
            } catch (e) {
                this.saveResult = String(e.message || e);
            }
            input.value = '';
        },

        deleteSelected() {
            if (this.previewOnly) {
                return;
            }
            if (!this.selectedId) {
                return;
            }
            this.elements = this.elements.filter((e) => e.id !== this.selectedId);
            this.selectedId = null;
        },

        openInlineEdit(item) {
            if (this.previewOnly) {
                return;
            }
            if (item.type !== 'text' && item.type !== 'placeholder') {
                return;
            }
            this.inlineTargetId = item.id;
            this.inlineSnapshot = item.content != null ? String(item.content) : '';
            this.inlineValue = this.inlineSnapshot;
            this.inlineOpen = true;
            this.$nextTick(() => {
                const ta = document.getElementById('eko-inline-edit');
                if (ta) {
                    ta.focus();
                    const len = ta.value.length;
                    ta.setSelectionRange(len, len);
                }
            });
        },

        applyInlineEdit() {
            this.confirmInlineEdit();
        },

        scheduleSave() {
            if (this.previewOnly) {
                return;
            }
            const id = Number(this.cfg().templateId || 0);
            if (!id) {
                return;
            }
            this.savePending = true;
            this.persistToken += 1;
            const token = this.persistToken;
            clearTimeout(this.saveTimer);
            this.saveTimer = setTimeout(() => {
                this.persist(token);
            }, 600);
        },

        /**
         * @param {number} token Value of persistToken when this run was scheduled
         */
        async persist(token) {
            const id = Number(this.cfg().templateId || 0);
            if (!id) {
                return;
            }
            if (token !== this.persistToken) {
                return;
            }
            if (this._persistRunning) {
                this._persistQueued = true;
                return;
            }
            this._persistRunning = true;
            const templateIdAtStart = id;
            try {
                const v = this.validateElementsForSave(this.elements);
                if (!v.ok) {
                    this.savePending = false;
                    this.saveResult = v.error;
                    if (this.lastOkElementsJson) {
                        this.restoreElementsFromJson(this.lastOkElementsJson);
                    } else {
                        this.normalizeElements();
                    }
                    return;
                }
                this.elements = v.elements;
                this.normalizeElements();
                const sentJson = this.captureElementsJson();

                const body = {
                    json_data: { elements: this.elements },
                    width_mm: this.widthMm,
                    height_mm: this.heightMm,
                };
                const raw = JSON.stringify(body);
                if (raw.length > 380000) {
                    this.savePending = false;
                    this.saveResult = 'JSON demasiado grande para gravar.';
                    if (this.lastOkElementsJson) {
                        this.restoreElementsFromJson(this.lastOkElementsJson);
                    }
                    return;
                }

                await this.api('templates/' + templateIdAtStart, {
                    method: 'PATCH',
                    body,
                });

                if (Number(this.cfg().templateId || 0) !== templateIdAtStart) {
                    this.savePending = false;
                    this.saveResult = '';
                    return;
                }
                if (token !== this.persistToken) {
                    return;
                }

                this.lastOkElementsJson = this.captureElementsJson();
                this.savePending = false;
                this.saveResult = 'Gravado';
                if (this.captureElementsJson() !== sentJson) {
                    this.scheduleSave();
                }
            } catch (e) {
                if (Number(this.cfg().templateId || 0) === templateIdAtStart && token === this.persistToken) {
                    this.savePending = false;
                    this.saveResult = String(e.message || e);
                    if (this.lastOkElementsJson) {
                        this.restoreElementsFromJson(this.lastOkElementsJson);
                    }
                }
            } finally {
                this._persistRunning = false;
                if (this._persistQueued) {
                    this._persistQueued = false;
                    const t = this.persistToken;
                    this.$nextTick(() => this.persist(t));
                }
            }
        },

        bindLayersSort() {
            if (this.previewOnly) {
                return;
            }
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
            if (this.previewOnly) {
                return;
            }
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
                        ignoreFrom: '.eko-sampa-editor__resize-handle, .eko-sampa-editor__inline-field',
                        inertia: false,
                        /** Sem restrict: o canvas está dentro de um stage com transform:scale; o restrict do Interact calculava mal e prendia tudo no canto. O clamp em JS mantém o layout dentro do canvas. */
                        listeners: {
                            start(event) {
                                const id = event.target.getAttribute('data-element-id');
                                self.draggingId = id;
                            },
                            move(event) {
                                event.target.style.transform = '';
                                const scale = self.zoomFactor();
                                const id = event.target.getAttribute('data-element-id');
                                const item = self.elements.find((e) => e.id === id);
                                if (!item) {
                                    return;
                                }
                                item.x += event.dx / scale;
                                item.y += event.dy / scale;
                                self.clampElementInCanvas(item);
                            },
                            end(event) {
                                event.target.style.transform = '';
                                const id = event.target.getAttribute('data-element-id');
                                self.draggingId = null;
                                const item = self.elements.find((e) => e.id === id);
                                if (!item) {
                                    return;
                                }
                                self.snapTranslate(item);
                                self.clampElementInCanvas(item);
                                self.triggerSnapFlash();
                                self.$nextTick(() => {
                                    self.bindInteract();
                                    self.bindLayersSort();
                                });
                            },
                        },
                    })
                    .resizable({
                        edges: { left: '.eko-resize-l', right: '.eko-resize-r', top: '.eko-resize-t', bottom: '.eko-resize-b' },
                        inertia: false,
                        modifiers: [
                            interact.modifiers.restrictSize({
                                min: { width: self.minElementWidth, height: self.minElementHeight },
                                max: { width: self.canvasWidth, height: self.canvasHeight },
                            }),
                        ],
                        listeners: {
                            start(event) {
                                const id = event.target.getAttribute('data-element-id');
                                self.draggingId = id;
                            },
                            move(event) {
                                event.target.style.transform = '';
                                const scale = self.zoomFactor();
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
                                self.clampElementInCanvas(item);
                            },
                            end(event) {
                                event.target.style.transform = '';
                                const id = event.target.getAttribute('data-element-id');
                                self.draggingId = null;
                                const item = self.elements.find((e) => e.id === id);
                                if (!item) {
                                    return;
                                }
                                self.snapBox(item);
                                self.clampElementInCanvas(item);
                                self.triggerSnapFlash();
                                self.$nextTick(() => {
                                    self.bindInteract();
                                    self.bindLayersSort();
                                });
                            },
                        },
                    });
            });
        },

        snapTranslate(item) {
            const g = this.gridSize;
            item.x = Math.round(item.x / g) * g;
            item.y = Math.round(item.y / g) * g;
            this.clampElementInCanvas(item);
        },

        snapBox(item) {
            const g = this.gridSize;
            item.x = Math.round(item.x / g) * g;
            item.y = Math.round(item.y / g) * g;
            item.width = Math.round(item.width / g) * g;
            item.height = Math.round(item.height / g) * g;
            item.width = Math.max(this.minElementWidth, item.width);
            item.height = Math.max(this.minElementHeight, item.height);
            this.clampElementInCanvas(item);
        },

        /** Aliases for templates / older markup */
        addImage() {
            this.openGallery();
        },
        removeElement() {
            this.deleteSelected();
        },
        duplicateElement() {
            if (this.previewOnly) {
                return;
            }
            if (!this.selectedId) {
                return;
            }
            const src = this.elements.find((e) => e.id === this.selectedId);
            if (!src) {
                return;
            }
            let raw;
            try {
                raw = JSON.parse(JSON.stringify(src));
            } catch (e) {
                return;
            }
            raw.id = this.uid(String(raw.type || 'el'));
            raw.x = this.sanitizeNumber(raw.x, 0) + 12;
            raw.y = this.sanitizeNumber(raw.y, 0) + 12;
            this.elements.push(raw);
            this.normalizeElements();
            this.select(raw.id);
        },
    };
}

window.ekoEditorCanvasFactory = ekoEditorCanvasFactory;

// Listener must exist before Alpine starts — enforced in PHP enqueue order, not script deps on Alpine.
document.addEventListener('alpine:init', () => {
    Alpine.data('ekoEditorCanvas', ekoEditorCanvasFactory);
});
