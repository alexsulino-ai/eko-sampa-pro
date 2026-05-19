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
        /** DB `background_color` — passed to thumbnail/print renderer (same as servidor). */
        templateBackgroundColor: '#ffffff',
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
        quickPrint: {
            open: false,
            loading: false,
            error: '',
            job: null,
            editorPreview: null,
            quantityHint: '',
            options: { printers: [], presets: [] },
            quantity: 1,
            printerKey: '__system__',
            presetKey: 'default',
            previewStatus: '',
        },
        quickPrintUserMessage: '',
        quickPrintPhase: '',
        _quickPrintAfterPrintBound: null,
        _quickPrintAbortController: null,
        quickPrintJobCreating: false,
        /** Prevents overlapping system print / handoff while a dialog is in flight. */
        _quickPrintPrintInProgress: false,
        /** Restores modal preview HTML after multi-copy print layout. */
        _quickPrintMountHtmlBackup: null,
        /** True when canvas document (elements + mm) differs from last successful save */
        hasUnsavedChanges: false,
        showSessionRecoveryBanner: false,
        sessionRecoveryDismissed: false,
        _sessionAutosaveTimer: null,
        _lastSessionThumbAt: 0,
        _forceSessionThumb: false,
        /** Last completed save message (e.g. Gravado) or error text */
        saveResult: '',
        /** JSON fingerprint of last persisted payload (null until first load/save baseline) */
        lastOkFingerprint: null,
        layerSort: null,
        interactDebounceTimer: null,
        inlineOpen: false,
        inlineValue: '',
        inlineTargetId: null,
        /** UX: bring dragged element above siblings */
        draggingId: null,
        _persistRunning: false,
        /** Embedded orders live preview: same canvas, no REST save / no interact */
        previewOnly: false,
        guestConversionModal: false,
        _orderPreviewListener: null,
        /** Last JSON string of elements array that was saved successfully (for revert) */
        lastOkElementsJson: null,
        maxElements: 400,
        _snapFlashTimer: null,
        /** Brief visual feedback after grid snap */
        snapFlash: false,
        /** Angular snap (degrees): threshold and cardinal targets (360° ≡ 0°). */
        ROTATE_SNAP_THRESHOLD: 3,
        ROTATE_SNAP_POINTS: Object.freeze([0, 90, 180, 270, 360]),
        /** <1 lowers pointer angular gain (less “twitchy” than 1:1). */
        ROTATE_DRAG_SENSITIVITY: 0.52,
        /** Soft snap: fraction of shortest arc toward nearest cardinal per move (Figma-like, non-locking). */
        ROTATE_SOFT_SNAP_PULL: 0.22,
        /** Canvas rotate handle: pointer drag writes `styles.rotate` (same range as sidebar sliders). */
        _rotateFabDrag: null,
        _rotateFabMoveHandler: null,
        _rotateFabUpHandler: null,
        _rotateSnapPulseItemId: null,
        _rotateSnapPulseTimer: null,
        /** Snapshot of `content` when opening inline editor (for cancel) */
        inlineSnapshot: '',
        /** Right sidebar: layers + JSON — collapsed frees canvas width */
        editorSidebarCollapsed: false,
        /** Floating properties panel (px); positioned after mount */
        propsPanelLeft: 16,
        propsPanelTop: 88,
        _propsPanelResizeBound: null,
        /** Order live preview: scale canvas to fit viewport (no inner scroll). */
        orderPreviewFit: 1,
        _orderPreviewResizeObserver: null,

        get stageTransform() {
            const z = this.zoomPercent / 100;
            const fit = this.previewOnly ? this.orderPreviewFit : 1;
            const s = z * fit;
            return `transform: scale(${s}); transform-origin: top center;`;
        },

        propsPanelPositionStyle() {
            if (this.previewOnly) {
                return {};
            }
            return {
                left: `${Math.round(this.propsPanelLeft)}px`,
                top: `${Math.round(this.propsPanelTop)}px`,
            };
        },

        layoutPropsPanelDefault() {
            if (this.previewOnly) {
                return;
            }
            const panelW = 288;
            const margin = 16;
            const top = 88;
            const w = typeof window !== 'undefined' ? window.innerWidth : 1200;
            this.propsPanelLeft = Math.max(margin, w - panelW - margin);
            this.propsPanelTop = top;
            this.clampPropsPanelIntoViewport();
        },

        clampPropsPanelIntoViewport() {
            if (typeof window === 'undefined') {
                return;
            }
            const panelW = 288;
            const panelH = 420;
            const pad = 8;
            const maxL = Math.max(pad, window.innerWidth - panelW - pad);
            const maxT = Math.max(pad, window.innerHeight - panelH - pad);
            this.propsPanelLeft = Math.min(Math.max(pad, this.propsPanelLeft), maxL);
            this.propsPanelTop = Math.min(Math.max(pad, this.propsPanelTop), maxT);
        },

        startPropsPanelDrag(ev) {
            if (this.previewOnly) {
                return;
            }
            if (ev.button !== 0) {
                return;
            }
            ev.preventDefault();
            const startX = ev.clientX;
            const startY = ev.clientY;
            const origL = this.propsPanelLeft;
            const origT = this.propsPanelTop;
            const move = (e) => {
                this.propsPanelLeft = origL + (e.clientX - startX);
                this.propsPanelTop = origT + (e.clientY - startY);
                this.clampPropsPanelIntoViewport();
            };
            const up = () => {
                document.removeEventListener('mousemove', move);
                document.removeEventListener('mouseup', up);
            };
            document.addEventListener('mousemove', move);
            document.addEventListener('mouseup', up);
        },

        /** Toolbar line: saving / errors / dirty / last success (preview: hidden) */
        get saveLine() {
            if (this.previewOnly) {
                return '';
            }
            if (this._persistRunning) {
                return 'A gravar…';
            }
            if (this.saveResult) {
                return this.saveResult;
            }
            if (this.hasUnsavedChanges) {
                return 'Alterações por gravar';
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
            var V = typeof window !== 'undefined' ? window.EkoVisualRenderContract : null;
            if (!V || typeof V.mmToCanvasPx !== 'function') {
                throw new Error('[ekoEditorCanvas] EkoVisualRenderContract.mmToCanvasPx required — check script enqueue order.');
            }
            var dims = V.mmToCanvasPx(this.widthMm, this.heightMm);
            this.canvasWidth = dims.canvasWidth;
            this.canvasHeight = dims.canvasHeight;
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
            const snap = this.snapFlash
                ? 'outline:2px solid rgba(16,185,129,0.55);outline-offset:0;'
                : 'outline:none;';
            return (
                `width:${w}px;height:${h}px;box-sizing:border-box;border:1px solid rgb(226 232 240);box-shadow:none;` +
                snap +
                'background-image:linear-gradient(to right, rgb(226 232 240) 1px, transparent 1px),' +
                'linear-gradient(to bottom, rgb(226 232 240) 1px, transparent 1px);' +
                `background-size:${gs}px ${gs}px`
            );
        },

        updateOrderPreviewFit() {
            if (!this.previewOnly) {
                return;
            }
            const el = this.$refs.orderPreviewViewport;
            if (!el || el.clientWidth < 8 || el.clientHeight < 8) {
                this.orderPreviewFit = 1;
                return;
            }
            const z = (Number(this.zoomPercent) || 100) / 100;
            if (z <= 0) {
                this.orderPreviewFit = 1;
                return;
            }
            const dw = Math.max(1, Number(this.canvasWidth) || 1) * z;
            const dh = Math.max(1, Number(this.canvasHeight) || 1) * z;
            const pad = 12;
            const fw = Math.max(8, el.clientWidth - pad);
            const fh = Math.max(8, el.clientHeight - pad);
            const fit = Math.min(1, fw / dw, fh / dh);
            this.orderPreviewFit = Number.isFinite(fit) && fit > 0 ? fit : 1;
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
                    this.$nextTick(() => this.updateOrderPreviewFit());
                });
                this.$watch('canvasWidth', () => {
                    this.$nextTick(() => this.updateOrderPreviewFit());
                });
                this.$watch('canvasHeight', () => {
                    this.$nextTick(() => this.updateOrderPreviewFit());
                });
                this._orderPreviewResizeObserver =
                    typeof ResizeObserver !== 'undefined'
                        ? new ResizeObserver(() => {
                              this.updateOrderPreviewFit();
                          })
                        : null;
                this.$nextTick(() => {
                    this.$nextTick(() => {
                        const el = this.$refs.orderPreviewViewport;
                        if (el && this._orderPreviewResizeObserver) {
                            this._orderPreviewResizeObserver.observe(el);
                        }
                        this.updateOrderPreviewFit();
                    });
                });
                return;
            }
            this.syncLogicalCanvasSizeFromMm();
            this.layoutPropsPanelDefault();
            this.showSessionRecoveryBanner = !!this.cfg().showSessionRecoveryBanner;
            this._propsPanelResizeBound = () => {
                this.clampPropsPanelIntoViewport();
            };
            window.addEventListener('resize', this._propsPanelResizeBound);
            this.$nextTick(() => {
                this.layoutPropsPanelDefault();
            });
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
                            this.fitAllTextElementsHeightsToContent();
                        });
                    }, 120);
                    this.markUnsaved();
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
                this.markUnsaved();
            });
            this.$watch('heightMm', () => {
                this.syncLogicalCanvasSizeFromMm();
                this.normalizeElements();
                this.$nextTick(() => this.bindInteract());
                this.markUnsaved();
            });
            this.loadFromServer();
            if (this.cfg().guestEditor) {
                void this.emitPublicTelemetry('editor_boot');
            }
        },

        applyOrderLivePreview(detail) {
            if (!this.previewOnly) {
                return;
            }
            if (!detail || !Array.isArray(detail.elements)) {
                this.elements = [];
                this.$nextTick(() => this.updateOrderPreviewFit());
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
            this.$nextTick(() => this.updateOrderPreviewFit());
        },

        destroy() {
            try {
                this.closeQuickPrintManager();
            } catch (e) {
                void e;
            }
            if (this._orderPreviewResizeObserver) {
                try {
                    this._orderPreviewResizeObserver.disconnect();
                } catch (e) {
                    void e;
                }
                this._orderPreviewResizeObserver = null;
            }
            if (this._propsPanelResizeBound) {
                window.removeEventListener('resize', this._propsPanelResizeBound);
                this._propsPanelResizeBound = null;
            }
            if (this._orderPreviewListener) {
                window.removeEventListener('eko-sampa:order-preview', this._orderPreviewListener);
                this._orderPreviewListener = null;
            }
            clearTimeout(this.interactDebounceTimer);
            clearTimeout(this._sessionAutosaveTimer);
            clearTimeout(this._snapFlashTimer);
            this.interactDebounceTimer = null;
            this._sessionAutosaveTimer = null;
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
                    this.clearInteractHostStyles(node);
                });
            }
        },

        api(path, opts) {
            if (typeof window.ekoSampaApi !== 'function') {
                return Promise.reject(new Error('API unavailable'));
            }
            let p = String(path || '');
            const o = opts ? Object.assign({}, opts) : {};
            const cfg = this.cfg() || {};
            const tok = String(cfg.templateSessionToken || '').trim();
            if (tok) {
                if (/^\s*templates\//.test(p)) {
                    const sep = p.indexOf('?') >= 0 ? '&' : '?';
                    p = p + sep + 'session_token=' + encodeURIComponent(tok);
                } else if (/^\s*quick-print\//.test(p)) {
                    const method = String(o.method || 'GET').toUpperCase();
                    if (method === 'GET' || method === 'HEAD' || method === 'DELETE') {
                        const sep = p.indexOf('?') >= 0 ? '&' : '?';
                        p = p + sep + 'session_token=' + encodeURIComponent(tok);
                    } else if (o.body && typeof o.body === 'object' && !(o.body instanceof FormData)) {
                        o.body = Object.assign({}, o.body, { session_token: tok });
                    }
                }
            }
            return window.ekoSampaApi(p, o);
        },

        _quickPrintJobBlocksNewCreate() {
            const j = this.quickPrint.job;
            if (!j || !j.id) {
                return false;
            }
            const st = String(j.status || '').toLowerCase();
            return st === 'queued' || st === 'sent_to_browser';
        },

        maybeOpenQuickPrintFromQuery() {
            if (this.previewOnly || !this.quickPrintFeatureEnabled()) {
                return;
            }
            if (!this.cfg().guestEditor) {
                return;
            }
            try {
                const u = new URL(window.location.href);
                if (u.searchParams.get('eko_open_qp') !== '1') {
                    return;
                }
                u.searchParams.delete('eko_open_qp');
                window.history.replaceState({}, '', u.toString());
            } catch (e) {
                void e;
                return;
            }
            const self = this;
            setTimeout(function () {
                void self.openQuickPrintManager();
            }, 0);
        },

        discardGuestRecovery() {
            this.sessionRecoveryDismissed = true;
            try {
                const u = new URL(window.location.href);
                u.searchParams.delete('eko_recover_session');
                window.history.replaceState({}, '', u.toString());
            } catch (e) {
                void e;
            }
        },

        cfg() {
            return window.ekoSampaEditor || {};
        },

        async emitPublicTelemetry(event) {
            const ev = String(event || '').trim();
            if (!ev) {
                return;
            }
            try {
                await this.api('public/telemetry', {
                    method: 'POST',
                    body: { event: ev },
                });
            } catch (e) {
                void e;
            }
        },

        quickPrintPhaseLabel() {
            const m = {
                preparing: 'Preparando',
                rendering: 'A renderizar',
                ready: 'Pronto',
                printing: 'A imprimir',
                completed: 'Concluído',
                failed: 'Falhou',
            };
            return m[this.quickPrintPhase] || '';
        },

        sessionTokenPresent() {
            return String((this.cfg() || {}).templateSessionToken || '').trim() !== '';
        },

        scheduleSessionAutosave() {
            if (this.previewOnly || !this.sessionTokenPresent()) {
                return;
            }
            const ms = Number((this.cfg() || {}).sessionAutosaveDebounceMs) || 12000;
            clearTimeout(this._sessionAutosaveTimer);
            this._sessionAutosaveTimer = setTimeout(() => {
                void this.sessionAutosaveFlush();
            }, ms);
        },

        async sessionAutosaveFlush() {
            if (this.previewOnly || !this.sessionTokenPresent() || !this.hasUnsavedChanges || this._persistRunning) {
                return;
            }
            const id = Number(this.cfg().templateId || 0);
            if (!id) {
                return;
            }
            const v = this.validateElementsForSave(this.elements);
            if (!v.ok) {
                return;
            }
            this._persistRunning = true;
            try {
                await this.api('templates/' + id, {
                    method: 'PATCH',
                    body: { json_data: { elements: this.elements } },
                });
                this.saveResult = 'Rascunho da sessão guardado';
            } catch (e) {
                this.saveResult = String((e && e.message) || e || '');
            } finally {
                this._persistRunning = false;
            }
        },

        async persistToMyTemplates() {
            if (this.previewOnly) {
                return;
            }
            const id = Number(this.cfg().templateId || 0);
            if (!id || !this.sessionTokenPresent()) {
                return;
            }
            if (!this.cfg().isLoggedIn) {
                this.guestConversionModal = true;
                void this.emitPublicTelemetry('conversion_modal_open');
                return;
            }
            if (this._persistRunning) {
                return;
            }
            this._persistRunning = true;
            try {
                const created = await this.api('templates/' + id + '/persist-to-mine', {
                    method: 'POST',
                    body: {},
                });
                const nid = created && created.id ? parseInt(String(created.id), 10) : 0;
                if (nid) {
                    const u = new URL(window.location.href);
                    u.searchParams.set('template_id', String(nid));
                    u.searchParams.delete('session_token');
                    u.searchParams.delete('eko_recover_session');
                    window.location.assign(u.toString());
                }
            } catch (e) {
                this.saveResult = String((e && e.message) || e || '');
            } finally {
                this._persistRunning = false;
            }
        },

        quickPrintFeatureEnabled() {
            const q = this.cfg().quickPrint;
            return !!(q && q.enabled);
        },

        canCreateOrderFromEditor() {
            const tid = Number(this.cfg().templateId || 0);
            if (tid <= 0) {
                return false;
            }
            if (this.cfg().canOrderCreate === true) {
                return true;
            }
            if (typeof window.ekoSampaCan === 'function') {
                return window.ekoSampaCan('order.create');
            }
            return false;
        },

        async createOrderFromEditor() {
            const tid = Number(this.cfg().templateId || 0);
            if (!tid) {
                return;
            }
            if (this.cfg().guestEditor) {
                this.guestConversionModal = true;
                void this.emitPublicTelemetry('conversion_modal_open');
                return;
            }
            if (typeof window.ekoSampaEditorCreateOrderFromTemplate !== 'function') {
                const msg = 'Order flow unavailable (app bundle not loaded).';
                if (window.ekoSampaToast) {
                    window.ekoSampaToast.show({ type: 'error', message: msg, duration: 5000 });
                } else {
                    // eslint-disable-next-line no-alert
                    alert(msg);
                }
                return;
            }
            await window.ekoSampaEditorCreateOrderFromTemplate(tid);
        },

        _abortQuickPrintRequests() {
            if (this._quickPrintAbortController) {
                try {
                    this._quickPrintAbortController.abort();
                } catch (e) {
                    void e;
                }
                this._quickPrintAbortController = null;
            }
        },

        quickPrintToast(message, type) {
            const t = type || 'error';
            if (window.ekoSampaToast) {
                window.ekoSampaToast.show({ type: t, message: String(message || ''), duration: 5000 });
            }
        },

        quickPrintValidationMessage(code) {
            const c = String(code || '');
            const map = {
                empty_payload: 'Quick print: empty canvas snapshot.',
                no_elements: 'Quick print: add at least one element before printing.',
                invalid_size: 'Quick print: invalid page size.',
                too_many_elements: 'Quick print: too many elements.',
                image_missing_src: 'Quick print: an image element is missing a source URL.',
                no_canvas_root: 'Quick print: editor canvas is not ready.',
                canvas_not_laid_out: 'Quick print: canvas has no size yet — try again in a moment.',
            };
            return map[c] || 'Quick print: invalid snapshot.';
        },

        /**
         * Live editor surface for decode / layout checks. Prefer x-ref; fall back when Alpine has not bound the ref yet.
         *
         * @returns {Element|null}
         */
        _quickPrintResolveEditorCanvasEl() {
            const fromRef = this.$refs && this.$refs.editorCanvas;
            if (fromRef && fromRef.nodeType === 1 && typeof fromRef.querySelectorAll === 'function') {
                return fromRef;
            }
            if (this.$el && typeof this.$el.querySelector === 'function') {
                const q = this.$el.querySelector('.eko-sampa-editor__canvas');
                if (q && q.nodeType === 1) {
                    return q;
                }
            }
            if (typeof document !== 'undefined' && document.querySelector) {
                const g = document.querySelector('#eko-sampa-editor .eko-sampa-editor__canvas');
                if (g && g.nodeType === 1) {
                    return g;
                }
            }
            return null;
        },

        /**
         * @returns {string} error code or ''
         */
        validateQuickPrintEditorDomReady() {
            const root = this._quickPrintResolveEditorCanvasEl();
            if (root && typeof root.offsetWidth === 'number') {
                const w = root.offsetWidth;
                const h = root.offsetHeight;
                if (w > 0 && h > 0) {
                    return '';
                }
            }
            /* html.eko-modal-open uses overflow:hidden on body; the live canvas can measure 0 while the logical page is still valid. */
            const cw = Math.round(Number(this.canvasWidth) || 0);
            const ch = Math.round(Number(this.canvasHeight) || 0);
            const els = Array.isArray(this.elements) ? this.elements : [];
            if (cw > 1 && ch > 1 && els.length >= 1) {
                return '';
            }
            if (!root) {
                return 'no_canvas_root';
            }
            return 'canvas_not_laid_out';
        },

        /**
         * Estabilização antes de snapshot (Quick Print / payloads visuais): fonts.ready + decode de imgs + 2× rAF.
         * Não encurtar sem medir regressão — payloads gerados a meio do layout geram preview em branco ou tipografia errada.
         */
        async waitVisualRenderStable() {
            if (typeof document !== 'undefined' && document.fonts && document.fonts.ready) {
                try {
                    await document.fonts.ready;
                } catch (e) {
                    void e;
                }
            }
            await this.decodeEditorCanvasImages();
            await this.$nextTick();
            await new Promise(function (resolve) {
                requestAnimationFrame(function () {
                    requestAnimationFrame(resolve);
                });
            });
            let domErr = this.validateQuickPrintEditorDomReady();
            if (domErr) {
                await this.$nextTick();
                await new Promise(function (resolve) {
                    requestAnimationFrame(function () {
                        requestAnimationFrame(resolve);
                    });
                });
                domErr = this.validateQuickPrintEditorDomReady();
            }
            return domErr;
        },

        async decodeEditorCanvasImages() {
            const root = this._quickPrintResolveEditorCanvasEl();
            if (!root || typeof root.querySelectorAll !== 'function') {
                return;
            }
            const imgs = root.querySelectorAll('img');
            const tasks = [];
            imgs.forEach(function (img) {
                if (!img) {
                    return;
                }
                if (typeof img.decode === 'function') {
                    tasks.push(
                        img.decode().catch(function () {
                            return null;
                        })
                    );
                    return;
                }
                if (img.complete) {
                    return;
                }
                tasks.push(
                    new Promise(function (resolve) {
                        const done = function () {
                            img.removeEventListener('load', done);
                            img.removeEventListener('error', done);
                            resolve(null);
                        };
                        img.addEventListener('load', done);
                        img.addEventListener('error', done);
                    })
                );
            });
            if (tasks.length) {
                await Promise.all(tasks);
            }
        },

        buildLiveQuickPrintPayload() {
            const wm = Math.max(1, Math.round(Number(this.widthMm) || 210));
            const hm = Math.max(1, Math.round(Number(this.heightMm) || 297));
            let els;
            try {
                els = JSON.parse(JSON.stringify(this.elements));
            } catch (e) {
                void e;
                return null;
            }
            if (!Array.isArray(els)) {
                return null;
            }
            const bg = this._safeCssColor(this.templateBackgroundColor, '#ffffff');
            const raw = {
                width_mm: wm,
                height_mm: hm,
                background_color: bg,
                elements: els,
            };
            const R = window.EkoCanvasRenderer;
            if (R && typeof R.normalizePayload === 'function') {
                return R.normalizePayload(raw);
            }
            return raw;
        },

        validateQuickPrintLivePayload(payload) {
            if (!payload || typeof payload !== 'object') {
                return 'empty_payload';
            }
            const els = payload.elements;
            if (!Array.isArray(els) || els.length < 1) {
                return 'no_elements';
            }
            const wm = Number(payload.width_mm);
            const hm = Number(payload.height_mm);
            if (!Number.isFinite(wm) || wm < 1 || !Number.isFinite(hm) || hm < 1) {
                return 'invalid_size';
            }
            if (els.length > 400) {
                return 'too_many_elements';
            }
            const badImage = els.some(function (el) {
                if (!el || typeof el !== 'object') {
                    return false;
                }
                if (String(el.type || '') !== 'image') {
                    return false;
                }
                const src = el.src != null ? el.src : el.content;
                return src == null || String(src).trim() === '';
            });
            if (badImage) {
                return 'image_missing_src';
            }
            return '';
        },

        _removeQuickPrintPrintStyle() {
            const el = document.getElementById('eko-sampa-quick-print-page-style');
            if (el && el.parentNode) {
                el.parentNode.removeChild(el);
            }
        },

        /**
         * Quick print preview host lives inside x-teleport; Alpine may not expose x-ref immediately.
         *
         * @returns {HTMLElement|null}
         */
        _quickPrintResolveMountEl() {
            const fromRef = this.$refs && this.$refs.quickPrintMount;
            if (fromRef && fromRef.nodeType === 1) {
                return fromRef;
            }
            if (typeof document !== 'undefined' && document.getElementById) {
                const byId = document.getElementById('eko-sampa-quick-print-mount');
                if (byId && byId.nodeType === 1) {
                    return byId;
                }
            }
            return null;
        },

        _quickPrintResolvedQuantity() {
            const n = Number(this.quickPrint.quantity);
            const q = Number.isFinite(n) && n > 0 ? Math.floor(n) : 1;
            return Math.max(1, Math.min(500, q));
        },

        /**
         * CSS de isolamento para window.print(): só #eko-sampa-quick-print-layer fica visível em body.
         * NÃO colocar o mount dentro de um ancestral com .eko-quick-print-hide-print (impressão vazia).
         * Ver docs/contracts/DO-NOT-BREAK.md e docs/quick-print/README.md.
         */
        _injectQuickPrintPrintStyle(payload) {
            this._removeQuickPrintPrintStyle();
            const p = payload || this.quickPrint.editorPreview;
            if (!p || typeof document === 'undefined') {
                return;
            }
            const wm = Math.max(1, parseInt(String(p.width_mm || 210), 10) || 210);
            const hm = Math.max(1, parseInt(String(p.height_mm || 297), 10) || 297);
            const st = document.createElement('style');
            st.id = 'eko-sampa-quick-print-page-style';
            st.textContent = [
                '@media print {',
                '  @page { size: ' + wm + 'mm ' + hm + 'mm; margin: 0; }',
                '  html, body { margin: 0 !important; padding: 0 !important; background: #fff !important; }',
                '  body > *:not(#eko-sampa-quick-print-layer) { display: none !important; }',
                '  #eko-sampa-quick-print-layer {',
                '    position: static !important;',
                '    inset: auto !important;',
                '    display: block !important;',
                '    width: 100% !important;',
                '    max-width: none !important;',
                '    min-height: 0 !important;',
                '    height: auto !important;',
                '    padding: 0 !important;',
                '    margin: 0 !important;',
                '    background: #fff !important;',
                '    overflow: visible !important;',
                '  }',
                '  #eko-sampa-quick-print-layer .eko-quick-print-panel {',
                '    max-width: none !important;',
                '    width: 100% !important;',
                '    height: auto !important;',
                '    max-height: none !important;',
                '    overflow: visible !important;',
                '    box-shadow: none !important;',
                '    border: 0 !important;',
                '    border-radius: 0 !important;',
                '  }',
                '  .eko-quick-print-hide-print { display: none !important; }',
                '  #eko-sampa-quick-print-mount .eko-sampa-quick-print-sheet { display: block !important; width: 100% !important; }',
                '  #eko-sampa-quick-print-mount .eko-sampa-print-root {',
                '    overflow: visible !important;',
                '    break-inside: avoid;',
                '    page-break-inside: avoid;',
                '    box-shadow: none !important;',
                '  }',
                '  #eko-sampa-quick-print-mount .eko-sampa-canvas { overflow: visible !important; }',
                '  #eko-sampa-quick-print-mount .eko-sampa-print-root * {',
                '    -webkit-print-color-adjust: exact !important;',
                '    print-color-adjust: exact !important;',
                '  }',
                '  #eko-sampa-quick-print-mount .eko-sampa-canvas__img {',
                '    display: block !important;',
                '    max-width: 100% !important;',
                '    max-height: none !important;',
                '    height: auto !important;',
                '    -webkit-print-color-adjust: exact !important;',
                '    print-color-adjust: exact !important;',
                '    visibility: visible !important;',
                '    opacity: 1 !important;',
                '  }',
                '}',
            ].join('\n');
            document.head.appendChild(st);
        },

        /**
         * @param {Element|null} root
         * @returns {Promise<void>}
         */
        async _quickPrintWaitImagesInContainer(root) {
            if (!root || typeof root.querySelectorAll !== 'function') {
                return;
            }
            const imgs = root.querySelectorAll('img');
            const tasks = [];
            imgs.forEach(function (img) {
                if (!img) {
                    return;
                }
                if (typeof img.decode === 'function') {
                    tasks.push(
                        img.decode().catch(function () {
                            return null;
                        })
                    );
                    return;
                }
                if (img.complete) {
                    return;
                }
                tasks.push(
                    new Promise(function (resolve) {
                        const done = function () {
                            img.removeEventListener('load', done);
                            img.removeEventListener('error', done);
                            resolve(null);
                        };
                        img.addEventListener('load', done);
                        img.addEventListener('error', done);
                    })
                );
            });
            if (tasks.length) {
                await Promise.all(tasks);
            }
        },

        /**
         * Many browsers omit remote images in the physical print path; inlining as data URLs fixes same-origin media
         * and improves reliability before window.print().
         *
         * @param {Element|null} root
         * @returns {Promise<void>}
         */
        async _quickPrintMaterializeImagesForDevicePrint(root) {
            if (!root || typeof root.querySelectorAll !== 'function') {
                return;
            }
            const imgs = Array.prototype.slice.call(root.querySelectorAll('img[src]'));
            const self = this;
            await Promise.all(
                imgs.map(function (img) {
                    return self._quickPrintOneImageSrcToDataUrlIfNeeded(img);
                })
            );
        },

        /**
         * @param {HTMLImageElement} img
         * @returns {Promise<void>}
         */
        async _quickPrintOneImageSrcToDataUrlIfNeeded(img) {
            if (!img || img.nodeType !== 1) {
                return;
            }
            const src = String(img.getAttribute('src') || img.src || '').trim();
            if (!src || src.indexOf('data:') === 0) {
                return;
            }
            try {
                let blob;
                if (src.indexOf('blob:') === 0) {
                    const rb = await fetch(src);
                    if (!rb.ok) {
                        return;
                    }
                    blob = await rb.blob();
                } else {
                    let r = await fetch(src, { mode: 'cors', credentials: 'same-origin', cache: 'force-cache' });
                    if (!r.ok) {
                        r = await fetch(src, { mode: 'cors', credentials: 'include', cache: 'force-cache' });
                    }
                    if (!r.ok) {
                        return;
                    }
                    blob = await r.blob();
                }
                const dataUrl = await new Promise(function (resolve, reject) {
                    const reader = new FileReader();
                    reader.onload = function () {
                        resolve(reader.result);
                    };
                    reader.onerror = reject;
                    reader.readAsDataURL(blob);
                });
                img.setAttribute('src', dataUrl);
            } catch (e) {
                void e;
            }
        },

        disposeQuickPrintPreview() {
            const mount = this._quickPrintResolveMountEl();
            if (mount) {
                mount.innerHTML = '';
            }
            this.quickPrint.previewStatus = '';
            this.quickPrint.editorPreview = null;
        },

        /**
         * @param {object} payload Normalized print payload (live editor snapshot).
         * @returns {Promise<boolean>}
         */
        async mountQuickPrintPreview(payload) {
            const R = window.EkoCanvasRenderer;
            if (!R || !payload) {
                this.quickPrint.previewStatus = !R ? 'Renderer missing.' : 'Invalid preview payload.';
                return false;
            }
            let mount = this._quickPrintResolveMountEl();
            for (let attempt = 0; !mount && attempt < 8; attempt++) {
                await this.$nextTick();
                mount = this._quickPrintResolveMountEl();
            }
            if (!mount) {
                this.quickPrint.previewStatus = 'Preview mount not found.';
                return false;
            }
            mount.innerHTML = '';
            this.quickPrint.previewStatus = 'Rendering…';
            const norm = typeof R.normalizePayload === 'function' ? R.normalizePayload(payload) : payload;
            if (!Array.isArray(norm.elements) || norm.elements.length < 1) {
                this.quickPrint.previewStatus = 'Invalid preview payload.';
                return false;
            }
            try {
                const pipeline =
                    typeof R.runRenderPipeline === 'function'
                        ? R.runRenderPipeline(mount, norm, {
                              forPrint: true,
                              target: R.RenderTargets && R.RenderTargets.PRINT,
                          })
                        : R.mountInto(mount, norm, { forPrint: true });
                await pipeline;
                await new Promise(function (resolve) {
                    requestAnimationFrame(resolve);
                });
                const printRoot = mount.querySelector('.eko-sampa-print-root');
                const canvasEl = mount.querySelector('.eko-sampa-canvas');
                if (!printRoot || !canvasEl) {
                    this.quickPrint.previewStatus = 'Preview layout missing.';
                    return false;
                }
                let pw = 0;
                let ph = 0;
                try {
                    const br = printRoot.getBoundingClientRect();
                    pw = br.width;
                    ph = br.height;
                } catch (e) {
                    void e;
                }
                if (!pw || !ph) {
                    await new Promise(function (resolve) {
                        requestAnimationFrame(resolve);
                    });
                    try {
                        const br2 = printRoot.getBoundingClientRect();
                        pw = br2.width;
                        ph = br2.height;
                    } catch (e2) {
                        void e2;
                    }
                }
                if (!pw || !ph) {
                    pw = printRoot.offsetWidth || mount.offsetWidth;
                    ph = printRoot.offsetHeight || mount.offsetHeight;
                }
                if (!pw || !ph) {
                    const wm = Number(norm.width_mm) || 0;
                    const hm = Number(norm.height_mm) || 0;
                    if (wm >= 1 && hm >= 1) {
                        pw = 1;
                        ph = 1;
                    }
                }
                if (!pw || !ph) {
                    this.quickPrint.previewStatus = 'Preview has no dimensions yet.';
                    return false;
                }
                this.quickPrint.editorPreview = norm;
                this.quickPrint.previewStatus = '';
                return true;
            } catch (e) {
                this.quickPrint.previewStatus = String((e && e.message) || e || 'Render failed.');
                return false;
            }
        },

        /**
         * Quick Print: abre modal, GET options, hidrata preview ao vivo. Ao fechar, closeQuickPrintManager DEVE
         * remover afterprint, abortar fetch, estilo print e preview — omitir qualquer passo deixa estado partido ou jobs duplicados.
         * Fluxo oficial de produção continua a ser Orders (OS); isto é atalho com snapshot transacional.
         */
        async openQuickPrintManager() {
            if (!this.quickPrintFeatureEnabled() || this.previewOnly) {
                return;
            }
            if (this.quickPrint.open || this.quickPrint.loading) {
                return;
            }
            const tid = Number(this.cfg().templateId || 0);
            if (!tid) {
                this.quickPrintToast('Save the template first to enable quick print.', 'warning');
                return;
            }
            if (this.hasUnsavedChanges) {
                await this.saveNow();
                if (this.hasUnsavedChanges) {
                    this.quickPrintToast('Save your changes before quick print.', 'error');
                    return;
                }
            }
            this._abortQuickPrintRequests();
            this._quickPrintAbortController = typeof AbortController !== 'undefined' ? new AbortController() : null;
            this.quickPrint.open = true;
            this.quickPrint.loading = true;
            this.quickPrint.error = '';
            this.quickPrint.job = null;
            this.quickPrint.editorPreview = null;
            this.quickPrint.quantityHint = '';
            this.quickPrint.previewStatus = '';
            this.quickPrintUserMessage = 'A preparar impressão rápida…';
            this.quickPrintPhase = 'preparing';
            this.quickPrint.quantity = 1;
            this.quickPrintJobCreating = false;
            this._quickPrintPrintInProgress = false;
            this._quickPrintMountHtmlBackup = null;
            const sig = this._quickPrintAbortController ? this._quickPrintAbortController.signal : undefined;
            try {
                const opt = await this.api('quick-print/options', { method: 'GET', signal: sig });
                this.quickPrint.options = {
                    printers: Array.isArray(opt.printers) ? opt.printers : [],
                    presets: Array.isArray(opt.presets) ? opt.presets : [],
                };
                const p0 = this.quickPrint.options.printers[0];
                const s0 = this.quickPrint.options.presets[0];
                this.quickPrint.printerKey = String((p0 && p0.id) || '__system__');
                this.quickPrint.presetKey = String((s0 && s0.id) || 'default');
            } catch (e) {
                if (e && e.name === 'AbortError') {
                    return;
                }
                this.quickPrint.error = String((e && e.message) || e || 'Could not load print options.');
            } finally {
                this.quickPrint.loading = false;
            }
            await this.$nextTick();
            await this.$nextTick();
            if (!this.quickPrint.open) {
                return;
            }
            if (this._quickPrintAbortController && this._quickPrintAbortController.signal.aborted) {
                return;
            }
            this.quickPrintUserMessage = 'A gerar pré-visualização…';
            this.quickPrintPhase = 'rendering';
            try {
                await this._quickPrintHydrateLivePreviewSilent();
            } catch (e) {
                if (e && e.name !== 'AbortError') {
                    void e;
                }
            }
            if (this.quickPrint.open) {
                try {
                    document.documentElement.classList.add('eko-modal-open');
                } catch (e) {
                    void e;
                }
            }
            if (this.quickPrint.open && !this.quickPrint.error) {
                this.quickPrintUserMessage = 'Pré-visualização pronta — confirme as opções e toque em Imprimir.';
                this.quickPrintPhase = 'ready';
            } else if (this.quickPrint.error) {
                this.quickPrintUserMessage = '';
                this.quickPrintPhase = 'failed';
            }
        },

        closeQuickPrintManager() {
            if (this._quickPrintAfterPrintBound) {
                try {
                    window.removeEventListener('afterprint', this._quickPrintAfterPrintBound);
                } catch (e) {
                    void e;
                }
                this._quickPrintAfterPrintBound = null;
            }
            this._abortQuickPrintRequests();
            this.quickPrintJobCreating = false;
            this._quickPrintPrintInProgress = false;
            this._quickPrintMountHtmlBackup = null;
            this._removeQuickPrintPrintStyle();
            this.disposeQuickPrintPreview();
            this.quickPrint.open = false;
            this.quickPrint.loading = false;
            this.quickPrint.error = '';
            this.quickPrint.job = null;
            this.quickPrint.quantityHint = '';
            this.quickPrintUserMessage = '';
            this.quickPrintPhase = '';
            try {
                document.documentElement.classList.remove('eko-modal-open');
            } catch (e) {
                void e;
            }
        },

        /**
         * Live snapshot → modal preview only (no job). Caller controls quickPrint.loading.
         *
         * @returns {Promise<boolean>}
         */
        async _quickPrintHydrateLivePreviewSilent() {
            if (!this.quickPrint.open || this.previewOnly) {
                return false;
            }
            if (this.quickPrintJobCreating || this._quickPrintPrintInProgress) {
                return false;
            }
            const sig = this._quickPrintAbortController && this._quickPrintAbortController.signal;
            const domErr = await this.waitVisualRenderStable();
            if (sig && sig.aborted) {
                return false;
            }
            if (!this.quickPrint.open) {
                return false;
            }
            if (domErr) {
                this.quickPrintToast(this.quickPrintValidationMessage(domErr), 'error');
                return false;
            }
            const payload = this.buildLiveQuickPrintPayload();
            const verr = this.validateQuickPrintLivePayload(payload);
            if (verr) {
                this.quickPrintToast(this.quickPrintValidationMessage(verr), 'error');
                return false;
            }
            await this.$nextTick();
            if (sig && sig.aborted) {
                return false;
            }
            if (!this.quickPrint.open) {
                return false;
            }
            const ok = await this.mountQuickPrintPreview(payload);
            if (!ok) {
                this.quickPrintToast(
                    String(this.quickPrint.previewStatus || '').trim() || 'Could not load print preview.',
                    'error'
                );
                return false;
            }
            this._injectQuickPrintPrintStyle(this.quickPrint.editorPreview);
            return true;
        },

        /**
         * Remount preview from live canvas only (no REST job).
         */
        async quickPrintApplySettings() {
            if (this.quickPrintJobCreating || this.quickPrint.loading || this._quickPrintPrintInProgress) {
                return;
            }
            this.quickPrint.loading = true;
            this.quickPrint.error = '';
            try {
                await this._quickPrintHydrateLivePreviewSilent();
            } finally {
                this.quickPrint.loading = false;
            }
        },

        /**
         * Live snapshot → mount (EkoCanvasRenderer) → POST /quick-print/jobs. No system print.
         *
         * @returns {Promise<boolean>}
         */
        async _quickPrintExecuteSnapshotPipelineAndPost() {
            const tid = Number(this.cfg().templateId || 0);
            const sig = this._quickPrintAbortController ? this._quickPrintAbortController.signal : undefined;
            if (!tid) {
                return false;
            }
            if (this._quickPrintJobBlocksNewCreate()) {
                this.quickPrintToast(
                    'Já existe uma impressão em andamento. Conclua, cancele ou feche o diálogo antes de criar outra.',
                    'warning'
                );
                return false;
            }
            if (this.hasUnsavedChanges) {
                await this.saveNow();
                if (this.hasUnsavedChanges) {
                    this.quickPrintToast('Save your changes first.', 'error');
                    return false;
                }
            }
            const domErr = await this.waitVisualRenderStable();
            if (domErr) {
                this.quickPrintToast(this.quickPrintValidationMessage(domErr), 'error');
                return false;
            }
            const payload = this.buildLiveQuickPrintPayload();
            const verr = this.validateQuickPrintLivePayload(payload);
            if (verr) {
                this.quickPrintToast(this.quickPrintValidationMessage(verr), 'error');
                return false;
            }
            await this.$nextTick();
            const ok = await this.mountQuickPrintPreview(payload);
            if (!ok) {
                this.quickPrintToast('Preview render failed — job not created.', 'error');
                return false;
            }
            this._injectQuickPrintPrintStyle(this.quickPrint.editorPreview);
            const snap = this.quickPrint.editorPreview;
            const res = await this.api('quick-print/jobs', {
                method: 'POST',
                signal: sig,
                body: {
                    template_id: tid,
                    quantity: this._quickPrintResolvedQuantity(),
                    printer_key: String(this.quickPrint.printerKey || '__system__'),
                    preset_key: String(this.quickPrint.presetKey || 'default'),
                    editor_preview: snap,
                },
            });
            this.quickPrint.job = res.job || null;
            this.quickPrint.quantityHint = res.quantity_hint || '';
            return true;
        },

        /**
         * Primary: register live snapshot + open system print in one gesture when possible.
         * If job is already queued with a mounted preview, only runs the system print step.
         */
        async quickPrintFooterPrimaryClick() {
            if (this.quickPrintJobCreating || this._quickPrintPrintInProgress) {
                return;
            }
            const ps = String(this.quickPrint.previewStatus || '').trim();
            if (ps) {
                this.quickPrintToast('Aguarde até a pré-visualização ficar estável ou corrija o erro indicado.', 'warning');
                return;
            }
            const st0 = this.quickPrint.job ? String(this.quickPrint.job.status || '') : '';
            if (st0 === 'sent_to_browser') {
                this.quickPrintToast('Print dialog is still active or finishing.', 'warning');
                return;
            }
            const jid = this.quickPrint.job && this.quickPrint.job.id ? parseInt(String(this.quickPrint.job.id), 10) : 0;
            if (jid && this.quickPrint.job && this.quickPrint.job.status === 'queued' && this.quickPrint.editorPreview) {
                await this.quickPrintRunBrowser();
                return;
            }
            this.quickPrintJobCreating = true;
            this.quickPrint.loading = true;
            this.quickPrint.error = '';
            this.quickPrintUserMessage = 'A registar trabalho de impressão…';
            this.quickPrintPhase = 'preparing';
            try {
                const ok = await this._quickPrintExecuteSnapshotPipelineAndPost();
                if (!ok) {
                    return;
                }
                this.quickPrintUserMessage = 'Pronto — a abrir o diálogo de impressão do sistema.';
                this.quickPrintPhase = 'printing';
                await this.quickPrintRunBrowser();
            } catch (e) {
                if (e && e.name === 'AbortError') {
                    return;
                }
                this.quickPrint.error = String((e && e.message) || e || 'Could not register quick print.');
            } finally {
                this.quickPrint.loading = false;
                this.quickPrintJobCreating = false;
            }
        },

        async quickPrintCancelJob() {
            const jid = this.quickPrint.job && this.quickPrint.job.id ? parseInt(String(this.quickPrint.job.id), 10) : 0;
            if (!jid) {
                return;
            }
            try {
                const row = await this.api('quick-print/jobs/' + jid + '/cancel', { method: 'POST' });
                this.quickPrint.job = row;
            } catch (e) {
                this.quickPrint.error = String((e && e.message) || e || 'Cancel failed.');
            }
        },

        async quickPrintReprint() {
            if (this.quickPrintJobCreating || this.quickPrint.loading || this._quickPrintPrintInProgress) {
                return;
            }
            const prevId = this.quickPrint.job && this.quickPrint.job.id ? parseInt(String(this.quickPrint.job.id), 10) : 0;
            if (prevId && this.quickPrint.job && this.quickPrint.job.status === 'queued') {
                try {
                    await this.api('quick-print/jobs/' + prevId + '/cancel', { method: 'POST' });
                } catch (e) {
                    void e;
                }
            }
            this.quickPrintJobCreating = true;
            this.quickPrint.loading = true;
            this.quickPrint.error = '';
            try {
                await this._quickPrintExecuteSnapshotPipelineAndPost();
            } catch (e) {
                if (e && e.name !== 'AbortError') {
                    this.quickPrint.error = String((e && e.message) || e || 'Reprint failed.');
                }
            } finally {
                this.quickPrint.loading = false;
                this.quickPrintJobCreating = false;
            }
        },

        async quickPrintRunBrowser() {
            if (this._quickPrintPrintInProgress) {
                return;
            }
            const jid = this.quickPrint.job && this.quickPrint.job.id ? parseInt(String(this.quickPrint.job.id), 10) : 0;
            if (!jid || !this.quickPrint.editorPreview) {
                return;
            }
            this._quickPrintPrintInProgress = true;
            try {
                const row = await this.api('quick-print/jobs/' + jid + '/browser-handoff', { method: 'POST' });
                this.quickPrint.job = row;
            } catch (e) {
                this._quickPrintPrintInProgress = false;
                this.quickPrint.error = String((e && e.message) || e || 'Could not update job.');
                this.quickPrintPhase = 'failed';
                return;
            }
            this._injectQuickPrintPrintStyle(this.quickPrint.editorPreview);
            const self = this;
            const done = function () {
                window.removeEventListener('afterprint', done);
                self._quickPrintAfterPrintBound = null;
                const mountRestore = self._quickPrintResolveMountEl();
                if (mountRestore && self._quickPrintMountHtmlBackup != null) {
                    mountRestore.innerHTML = self._quickPrintMountHtmlBackup;
                    self._quickPrintMountHtmlBackup = null;
                }
                self._quickPrintPrintInProgress = false;
                const id = self.quickPrint.job && self.quickPrint.job.id ? parseInt(String(self.quickPrint.job.id), 10) : 0;
                if (!id) {
                    return;
                }
                self
                    .api('quick-print/jobs/' + id + '/complete', { method: 'POST' })
                    .then(function (row) {
                        self.quickPrint.job = row;
                        self.quickPrintPhase = 'completed';
                        self.quickPrintUserMessage = 'Impressão registada como concluída.';
                    })
                    .catch(function () {
                        void 0;
                    });
            };
            this._quickPrintAfterPrintBound = done;
            window.addEventListener('afterprint', done);
            try {
                const mount = this._quickPrintResolveMountEl();
                const qty = this._quickPrintResolvedQuantity();
                this._quickPrintMountHtmlBackup = mount ? mount.innerHTML : null;
                if (mount) {
                    const root = mount.querySelector('.eko-sampa-print-root');
                    if (root) {
                        const proto = root.cloneNode(true);
                        mount.innerHTML = '';
                        for (let i = 0; i < qty; i++) {
                            const sheet = document.createElement('div');
                            sheet.className = 'eko-sampa-quick-print-sheet';
                            if (i < qty - 1) {
                                sheet.style.pageBreakAfter = 'always';
                                sheet.style.breakAfter = 'page';
                            }
                            sheet.appendChild(proto.cloneNode(true));
                            mount.appendChild(sheet);
                        }
                    }
                    await this._quickPrintWaitImagesInContainer(mount);
                    await this._quickPrintMaterializeImagesForDevicePrint(mount);
                    await this._quickPrintWaitImagesInContainer(mount);
                    await new Promise(function (resolve) {
                        requestAnimationFrame(function () {
                            requestAnimationFrame(resolve);
                        });
                    });
                }
                window.print();
            } catch (e) {
                window.removeEventListener('afterprint', done);
                this._quickPrintAfterPrintBound = null;
                this._quickPrintPrintInProgress = false;
                const mountErr = this._quickPrintResolveMountEl();
                if (mountErr && this._quickPrintMountHtmlBackup != null) {
                    mountErr.innerHTML = this._quickPrintMountHtmlBackup;
                    this._quickPrintMountHtmlBackup = null;
                }
                this.quickPrint.error = String((e && e.message) || e || 'Print failed.');
            }
        },

        async quickPrintRefreshJob() {
            const jid = this.quickPrint.job && this.quickPrint.job.id ? parseInt(String(this.quickPrint.job.id), 10) : 0;
            if (!jid) {
                return;
            }
            try {
                const row = await this.api('quick-print/jobs/' + jid, { method: 'GET' });
                this.quickPrint.job = row;
            } catch (e) {
                this.quickPrint.error = String((e && e.message) || e || 'Refresh failed.');
            }
        },

        async loadFromServer() {
            const id = Number(this.cfg().templateId || 0);
            if (!id) {
                this.syncLogicalCanvasSizeFromMm();
                this.elements = this.defaultElements();
                this.normalizeElements();
                this.lastOkElementsJson = this.captureElementsJson();
                this.lastOkFingerprint = this.buildSaveFingerprint();
                this.hasUnsavedChanges = false;
                this.saveResult = '';
                this.$nextTick(() => {
                    this.$nextTick(() => this.fitAllTextElementsHeightsToContent());
                });
                return;
            }
            try {
                const row = await this.api('templates/' + id, { method: 'GET' });
                this.widthMm = Number(row.width_mm) || 210;
                this.heightMm = Number(row.height_mm) || 297;
                this.templateBackgroundColor = this._safeCssColor(row.background_color, '#ffffff');
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
                this.lastOkFingerprint = this.buildSaveFingerprint();
                this.hasUnsavedChanges = false;
                if (loaded.ok) {
                    this.saveResult = '';
                }
                this.$nextTick(() => {
                    this.$nextTick(() => this.fitAllTextElementsHeightsToContent());
                });
                void this.maybeOpenQuickPrintFromQuery();
            } catch (e) {
                this.syncLogicalCanvasSizeFromMm();
                this.elements = this.defaultElements();
                this.normalizeElements();
                this.lastOkElementsJson = this.captureElementsJson();
                this.lastOkFingerprint = this.buildSaveFingerprint();
                this.hasUnsavedChanges = false;
                this.saveResult = String(e.message || e);
                this.$nextTick(() => {
                    this.$nextTick(() => this.fitAllTextElementsHeightsToContent());
                });
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
            this.normalizeLayerOrder();
        },

        /**
         * Single source of truth for stack order: `elements` array (index 0 = back, last = front).
         * z-index for paint is derived from index only: EkoCanvasRenderer.stackZFromIndex(i) === BASE + i.
         */
        normalizeLayerOrder() {
            if (!Array.isArray(this.elements)) {
                return;
            }
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
                paddingTop: 4,
                paddingRight: 6,
                paddingBottom: 4,
                paddingLeft: 6,
                alignVertical: 'top',
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
                paddingTop: 0,
                paddingRight: 0,
                paddingBottom: 0,
                paddingLeft: 0,
            };
        },

        /** Frame-only styles for rectangles (rotation lives here, not on the host). */
        defaultRectangleStyles() {
            return {
                opacity: 1,
                borderRadius: 0,
                borderWidth: 0,
                borderStyle: 'solid',
                borderColor: '#cbd5e1',
                rotate: 0,
                boxShadow: 'none',
                paddingTop: 0,
                paddingRight: 0,
                paddingBottom: 0,
                paddingLeft: 0,
            };
        },

        _clampNum(n, lo, hi, fallback) {
            const x = Number(n);
            if (!Number.isFinite(x)) {
                return fallback;
            }
            return Math.max(lo, Math.min(hi, x));
        },

        /**
         * Canonical editor rotation in [0, 360) degrees (CSS `rotate(deg)` accepts equivalent values).
         *
         * @param {number} angle
         * @returns {number}
         */
        normalizeAngle360(angle) {
            const n = Number(angle);
            if (!Number.isFinite(n)) {
                return 0;
            }
            return ((n % 360) + 360) % 360;
        },

        /**
         * Shortest signed delta (degrees) to rotate from `fromDeg` toward `toDeg` on a circle.
         *
         * @param {number} fromDeg
         * @param {number} toDeg
         * @returns {number} in (-180, 180]
         */
        _rotateSignedShortestDeltaDeg(fromDeg, toDeg) {
            const a = this.normalizeAngle360(fromDeg);
            const b = this.normalizeAngle360(toDeg);
            let d = b - a;
            if (d > 180) {
                d -= 360;
            }
            if (d < -180) {
                d += 360;
            }
            return d;
        },

        /**
         * @param {number} aDeg
         * @param {number} bDeg
         * @returns {number}
         */
        circularAbsDeltaDeg(aDeg, bDeg) {
            return Math.abs(this._rotateSignedShortestDeltaDeg(aDeg, bDeg));
        },

        /**
         * Light magnetic pull toward the nearest cardinal when inside threshold (does not hard-lock).
         *
         * @param {number} deg
         * @returns {number}
         */
        applyRotateSoftSnapDeg(deg) {
            const a = this.normalizeAngle360(deg);
            const th = this.ROTATE_SNAP_THRESHOLD;
            const pull = this.ROTATE_SOFT_SNAP_PULL;
            const snaps = this.ROTATE_SNAP_POINTS;
            let bestSnap = null;
            let bestDist = Infinity;
            for (let i = 0; i < snaps.length; i++) {
                const s = this.normalizeAngle360(snaps[i]);
                const dist = this.circularAbsDeltaDeg(a, s);
                if (dist < bestDist) {
                    bestDist = dist;
                    bestSnap = s;
                }
            }
            if (bestSnap === null || bestDist > th) {
                return a;
            }
            const signed = this._rotateSignedShortestDeltaDeg(a, bestSnap);
            return this.normalizeAngle360(a + signed * pull);
        },

        /**
         * Hard snap used on pointer release when within threshold of a cardinal.
         *
         * @param {number} deg
         * @returns {number}
         */
        hardSnapRotateIfCloseDeg(deg) {
            const a = this.normalizeAngle360(deg);
            const th = this.ROTATE_SNAP_THRESHOLD;
            const snaps = this.ROTATE_SNAP_POINTS;
            let out = a;
            let bestD = Infinity;
            for (let i = 0; i < snaps.length; i++) {
                const s = this.normalizeAngle360(snaps[i]);
                const dist = this.circularAbsDeltaDeg(a, s);
                if (dist <= th && dist < bestD) {
                    bestD = dist;
                    out = s;
                }
            }
            return this.normalizeAngle360(out);
        },

        _triggerRotateSnapVisual(itemId) {
            const self = this;
            this._rotateSnapPulseItemId = itemId;
            if (this._rotateSnapPulseTimer) {
                clearTimeout(this._rotateSnapPulseTimer);
            }
            this._rotateSnapPulseTimer = setTimeout(function () {
                self._rotateSnapPulseItemId = null;
                self._rotateSnapPulseTimer = null;
            }, 240);
        },

        /**
         * @param {number|null|undefined} deg
         * @returns {string}
         */
        formatRotateDisplayDeg(deg) {
            return String(Math.round(this.normalizeAngle360(deg))) + '°';
        },

        /**
         * Sidebar rotate slider: normalize to [0,360) and hard-snap near cardinals on release.
         */
        editorRotateSidebarCommit() {
            const el = this.selectedElement;
            if (!el || !el.styles || typeof el.styles !== 'object') {
                return;
            }
            const before = this.normalizeAngle360(Number(el.styles.rotate) || 0);
            const after = this.hardSnapRotateIfCloseDeg(before);
            el.styles.rotate = after;
            if (this.circularAbsDeltaDeg(before, after) > 0.05) {
                this._triggerRotateSnapVisual(el.id);
            }
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
                const rv = Number(r.rotate);
                d.rotate = Number.isFinite(rv)
                    ? this.normalizeAngle360(rv)
                    : this.normalizeAngle360(d.rotate);
                d.boxShadow = this._safeBoxShadow(r.boxShadow != null ? r.boxShadow : d.boxShadow);
                d.paddingTop = Math.round(this._clampNum(r.paddingTop, 0, 120, d.paddingTop));
                d.paddingRight = Math.round(this._clampNum(r.paddingRight, 0, 120, d.paddingRight));
                d.paddingBottom = Math.round(this._clampNum(r.paddingBottom, 0, 120, d.paddingBottom));
                d.paddingLeft = Math.round(this._clampNum(r.paddingLeft, 0, 120, d.paddingLeft));
                return d;
            }
            if (type === 'rectangle') {
                const d = this.defaultRectangleStyles();
                d.opacity = this._clampNum(r.opacity, 0, 1, d.opacity);
                d.borderRadius = this._clampNum(r.borderRadius, 0, 400, d.borderRadius);
                d.borderWidth = this._clampNum(r.borderWidth, 0, 40, d.borderWidth);
                const bs = String(r.borderStyle || d.borderStyle).toLowerCase();
                d.borderStyle = ['solid', 'dashed', 'dotted', 'none'].includes(bs) ? bs : 'solid';
                d.borderColor = this._safeCssColor(r.borderColor, d.borderColor);
                const rv = Number(r.rotate);
                d.rotate = Number.isFinite(rv)
                    ? this.normalizeAngle360(rv)
                    : this.normalizeAngle360(d.rotate);
                d.boxShadow = this._safeBoxShadow(r.boxShadow != null ? r.boxShadow : d.boxShadow);
                d.paddingTop = Math.round(this._clampNum(r.paddingTop, 0, 120, d.paddingTop));
                d.paddingRight = Math.round(this._clampNum(r.paddingRight, 0, 120, d.paddingRight));
                d.paddingBottom = Math.round(this._clampNum(r.paddingBottom, 0, 120, d.paddingBottom));
                d.paddingLeft = Math.round(this._clampNum(r.paddingLeft, 0, 120, d.paddingLeft));
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
                const rv = Number(r.rotate);
                d.rotate = Number.isFinite(rv)
                    ? this.normalizeAngle360(rv)
                    : this.normalizeAngle360(d.rotate);
                d.paddingTop = Math.round(this._clampNum(r.paddingTop, 0, 120, d.paddingTop));
                d.paddingRight = Math.round(this._clampNum(r.paddingRight, 0, 120, d.paddingRight));
                d.paddingBottom = Math.round(this._clampNum(r.paddingBottom, 0, 120, d.paddingBottom));
                d.paddingLeft = Math.round(this._clampNum(r.paddingLeft, 0, 120, d.paddingLeft));
                const av = String(r.alignVertical != null ? r.alignVertical : d.alignVertical).toLowerCase();
                d.alignVertical = ['top', 'center', 'bottom'].includes(av) ? av : 'top';
                return d;
            }
            return r && typeof r === 'object' ? Object.assign({}, r) : {};
        },

        /**
         * Resolve the canvas element host for Interact/pointer events (handles, image, etc. hit children).
         *
         * @param {Event|Element|null} evOrEl
         * @returns {Element|null}
         */
        _editorInteractHostFromPointer(evOrEl) {
            const t = evOrEl && evOrEl.target != null ? evOrEl.target : evOrEl;
            if (!t || typeof t.closest !== 'function') {
                return null;
            }
            return t.closest('.eko-sampa-editor__element');
        },

        /**
         * Strip Interact-only residue from the host. Do NOT remove left/top/width/height — those are
         * owned by Alpine :style (elementPositionStyle); removeProperty fights the binding and leaves
         * hosts with only position/z-index (invisible stack).
         *
         * @param {Element} node
         */
        clearInteractHostStyles(node) {
            if (!node || !node.style) {
                return;
            }
            const props = [
                'transform',
                '-webkit-transform',
                '-moz-transform',
                '-ms-transform',
                '-o-transform',
                'translate',
                'scale',
                'rotate',
                'touch-action',
                'filter',
                'backdrop-filter',
                'perspective',
                'will-change',
            ];
            for (let i = 0; i < props.length; i++) {
                try {
                    node.style.removeProperty(props[i]);
                } catch (e) {
                    void e;
                }
            }
            try {
                node.removeAttribute('data-x');
                node.removeAttribute('data-y');
            } catch (e) {
                void e;
            }
        },

        /**
         * Editor-only selection/hover/drag chrome on the inner frame (inset box-shadow so `overflow:hidden` does not clip).
         *
         * @param {object} item
         * @returns {string} CSS fragment like `inset 0 0 0 2px rgb(...)` or empty
         */
        _editorFrameInsetChrome(item) {
            if (this.previewOnly || !item) {
                return '';
            }
            const id = item.id;
            if (this.draggingId != null && String(this.draggingId) === String(id)) {
                return 'inset 0 0 0 2px rgb(79 70 229)';
            }
            if (this.selectedId != null && String(this.selectedId) === String(id)) {
                if (this.inlineOpen && this.inlineTargetId != null && String(this.inlineTargetId) === String(id)) {
                    return 'inset 0 0 0 2px rgb(165 180 252)';
                }
                return 'inset 0 0 0 2px rgb(79 70 229)';
            }
            if (
                this.hoveredElementId != null &&
                String(this.hoveredElementId) === String(id) &&
                String(this.selectedId || '') !== String(id) &&
                String(this.draggingId || '') !== String(id)
            ) {
                return 'inset 0 0 0 1px rgb(148 163 184)';
            }
            return '';
        },

        /**
         * @param {string} frameCss
         * @param {string} insetExtra from {@see _editorFrameInsetChrome}
         * @returns {string}
         */
        _mergeFrameBoxShadow(frameCss, insetExtra) {
            const extra = String(insetExtra || '').trim();
            if (!extra) {
                return frameCss;
            }
            const s = String(frameCss || '');
            const replaced = s.replace(/box-shadow:([^;]*);/i, (_, cur) => {
                const c = String(cur || '').trim();
                if (!c || c.toLowerCase() === 'none') {
                    return 'box-shadow:' + extra + ';';
                }
                return 'box-shadow:' + extra + ', ' + c + ';';
            });
            if (replaced !== s) {
                return replaced;
            }
            const sep = s.endsWith(';') || s === '' ? '' : ';';
            return s + sep + 'box-shadow:' + extra + ';';
        },

        /**
         * @param {object} item
         * @param {number} [idxFromFor] index from `x-for="(item, idx) in elements"`
         */
        elementPositionStyle(item, idxFromFor) {
            const n = Array.isArray(this.elements) ? this.elements.length : 0;
            let idx;
            if (idxFromFor != null && Number.isFinite(Number(idxFromFor))) {
                idx = Math.max(0, Math.min(n > 0 ? n - 1 : 0, Math.floor(Number(idxFromFor))));
            } else {
                const idxRaw = this.elements.indexOf(item);
                idx = idxRaw < 0 ? 0 : idxRaw;
            }
            const R = typeof window !== 'undefined' ? window.EkoCanvasRenderer : null;
            let pos;
            if (R && typeof R.elementPositionStyle === 'function') {
                pos = R.elementPositionStyle(item, idx);
            } else if (!item || typeof item !== 'object') {
                pos = 'position:absolute;left:0;top:0;width:100px;height:40px';
            } else {
                const x = Number(item.x);
                const y = Number(item.y);
                const w = Number(item.width);
                const h = Number(item.height);
                const left = Number.isFinite(x) ? x : 0;
                const top = Number.isFinite(y) ? y : 0;
                const width = Number.isFinite(w) ? w : this.minElementWidth;
                const height = Number.isFinite(h) ? h : this.minElementHeight;
                pos = `position:absolute;left:${left}px;top:${top}px;width:${width}px;height:${height}px;box-sizing:border-box;`;
            }
            if (this.previewOnly) {
                return pos;
            }
            const z =
                R && typeof R.stackZFromIndex === 'function'
                    ? R.stackZFromIndex(idx)
                    : 10 + idx;
            const trimmed = pos.replace(/\s*z-index:\s*\d+\s*;?/gi, '').replace(/;+$/g, '');
            const sep = trimmed.endsWith(';') || trimmed === '' ? '' : ';';
            return trimmed + sep + 'z-index:' + z + ';';
        },

        /**
         * Inner frame CSS (border, fill, shadow). In the live editor, rotation + selection inset
         * chrome are applied on `.eko-sampa-editor__rotate-wrap`; inner frames use `omitRotate`.
         *
         * @param {object} item
         * @returns {string}
         */
        editorElementFrameStyle(item) {
            const R = typeof window !== 'undefined' ? window.EkoCanvasRenderer : null;
            if (this.previewOnly || !item) {
                if (R && typeof R.elementFrameCss === 'function') {
                    return R.elementFrameCss(item);
                }
                return this.elementFrameCss(item);
            }
            if (R && typeof R.elementFrameCss === 'function') {
                return R.elementFrameCss(item, { omitRotate: true });
            }
            return this.elementFrameCss(item, { omitRotate: true });
        },

        /**
         * Editor-only: rotation + selection/hover inset on this wrapper. Interact binds to the
         * outer `.eko-sampa-editor__element`, which stays free of `transform` so drag/resize stay stable.
         * For `getRotatedBoundingBox` / local coords see `window.EkoEditorTransformMath`.
         *
         * @param {object} item
         * @returns {string}
         */
        editorRotateWrapStyle(item) {
            const base =
                'position:absolute;left:0;top:0;width:100%;height:100%;box-sizing:border-box;min-width:0;min-height:0;';
            if (this.previewOnly || !item) {
                return base;
            }
            const st = item.styles && typeof item.styles === 'object' ? item.styles : {};
            const rot = this.normalizeAngle360(st.rotate);
            let out = base;
            const inset = this._editorFrameInsetChrome(item);
            if (inset) {
                const seed = out + 'box-shadow:none;';
                out = this._mergeFrameBoxShadow(seed, inset);
            }
            if (rot !== 0) {
                const sep = out.endsWith(';') ? '' : ';';
                out += sep + `transform:rotate(${rot}deg);transform-origin:center center;`;
            }
            return out;
        },

        /**
         * Branch wrappers inside `x-for`: if a stray DOM node survives (e.g. legacy `div x-if`),
         * hide overlays that do not match `item.type` so selection chrome / text do not duplicate.
         */
        editorImageFrameStyle(item) {
            if (!item || item.type !== 'image') {
                return 'display:none !important;pointer-events:none;';
            }
            return this.editorElementFrameStyle(item);
        },

        editorTextFrameStyle(item) {
            if (!item || (item.type !== 'text' && item.type !== 'placeholder')) {
                return 'display:none !important;pointer-events:none;';
            }
            return this.editorElementFrameStyle(item);
        },

        editorRectangleFrameStyle(item) {
            if (!item || item.type !== 'rectangle') {
                return 'display:none !important;pointer-events:none;';
            }
            return this.editorElementFrameStyle(item);
        },

        _framePaddingCss(type, st) {
            const r = st && typeof st === 'object' ? st : {};
            const d =
                type === 'text' || type === 'placeholder'
                    ? { top: 4, right: 6, bottom: 4, left: 6 }
                    : { top: 0, right: 0, bottom: 0, left: 0 };
            const pt = Math.round(this._clampNum(r.paddingTop, 0, 120, d.top));
            const pr = Math.round(this._clampNum(r.paddingRight, 0, 120, d.right));
            const pb = Math.round(this._clampNum(r.paddingBottom, 0, 120, d.bottom));
            const pl = Math.round(this._clampNum(r.paddingLeft, 0, 120, d.left));
            return `padding:${pt}px ${pr}px ${pb}px ${pl}px`;
        },

        /**
         * @param {object} item
         * @returns {string}
         */
        textVerticalWrapCss(item) {
            const R = typeof window !== 'undefined' ? window.EkoCanvasRenderer : null;
            if (R && typeof R.textVerticalWrapCss === 'function') {
                return R.textVerticalWrapCss(item);
            }
            const st = item && item.styles && typeof item.styles === 'object' ? item.styles : {};
            const av = String(st.alignVertical != null ? st.alignVertical : 'top').toLowerCase();
            const jc = av === 'center' ? 'center' : av === 'bottom' ? 'flex-end' : 'flex-start';
            return [
                'flex:1',
                'min-width:0',
                'min-height:0',
                'width:100%',
                'display:flex',
                'flex-direction:column',
                `justify-content:${jc}`,
            ].join(';');
        },

        elementFrameCss(item, options) {
            const R = typeof window !== 'undefined' ? window.EkoCanvasRenderer : null;
            if (R && typeof R.elementFrameCss === 'function') {
                return R.elementFrameCss(item, options);
            }
            const opts = options && typeof options === 'object' ? options : {};
            const omitRotate = !!opts.omitRotate;
            const forPrint = !!opts.forPrint;
            const forThumbnail = !!opts.forThumbnail;
            const clipLikePrint = forPrint && !forThumbnail;
            const t = item && item.type;
            const st = item.styles || {};
            const op = this._clampNum(st.opacity, 0, 1, 1);
            const br = Math.max(0, Number(st.borderRadius) || 0);
            const bw = Math.max(0, Number(st.borderWidth) || 0);
            const bs = String(st.borderStyle || 'solid');
            const bc = this._safeCssColor(st.borderColor, '#cbd5e1');
            const sh = this._safeBoxShadow(st.boxShadow != null ? st.boxShadow : 'none');
            const rot = this.normalizeAngle360(st.rotate);
            let border = 'none';
            if (bw > 0 && bs !== 'none') {
                border = `${bw}px ${bs} ${bc}`;
            }
            const isTextish = t === 'text' || t === 'placeholder';
            const overflowMode = isTextish && !clipLikePrint ? 'visible' : 'hidden';
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
                `overflow:${overflowMode}`,
            ];
            if (!omitRotate) {
                parts.push(`transform:rotate(${rot}deg)`, 'transform-origin:center center');
            }
            if (t === 'rectangle') {
                parts.push('background:#f1f5f9');
            }
            if (t === 'text' || t === 'placeholder') {
                const bg = this._safeCssColor(st.backgroundColor, 'transparent');
                parts.push(`background-color:${bg}`);
                parts.push('display:flex');
                parts.push('flex-direction:column');
                parts.push('min-height:0');
            }
            if (t === 'text' || t === 'placeholder' || t === 'image' || t === 'rectangle') {
                parts.push(this._framePaddingCss(t, st));
            }
            return parts.join(';');
        },

        /**
         * HTML textarea control resets only. Typography and layout must come from
         * `textContentCss(item)` on the same node (concat in the template).
         *
         * @returns {string}
         */
        inlineEditorTextareaCss() {
            return (
                'resize:none;-webkit-appearance:none;appearance:none;pointer-events:auto' +
                ';border:none;border-width:0;outline:none;outline-offset:0' +
                ';background:transparent;background-color:transparent' +
                ';caret-color:currentColor;-webkit-tap-highlight-color:transparent'
            );
        },

        textContentCss(item) {
            if (!item || (item.type !== 'text' && item.type !== 'placeholder')) {
                return 'display:none !important';
            }
            const R = typeof window !== 'undefined' ? window.EkoCanvasRenderer : null;
            if (R && typeof R.textContentCss === 'function') {
                return R.textContentCss(item, { forPrint: false, forThumbnail: false });
            }
            const st = item.styles || {};
            const d = this.defaultTextStyles();
            const ff = this._safeFontFamily(st.fontFamily || d.fontFamily);
            const fs = Math.round(this._clampNum(st.fontSize, 6, 200, d.fontSize));
            const fw = String(st.fontWeight || d.fontWeight);
            const fst = String(st.fontStyle || d.fontStyle);
            const td = String(st.textDecoration || d.textDecoration);
            const ta = String(st.textAlign || d.textAlign);
            const col = this._safeCssColor(st.color, d.color);
            const lh = Number(st.lineHeight);
            const lineH = Number.isFinite(lh) && lh > 0 && lh <= 4 ? String(lh) : String(d.lineHeight);
            const ls = this._clampNum(st.letterSpacing, -20, 40, 0);
            const tt = String(st.textTransform || d.textTransform);
            /** Never `auto` here — inner scrollbar inside the text element (matches EkoCanvasRenderer). */
            const overflow = 'visible';
            return [
                'flex:0 1 auto',
                'max-height:none',
                'min-width:0',
                'min-height:0',
                'width:100%',
                'margin:0',
                'padding:0',
                'box-sizing:border-box',
                `font-family:${ff.replace(/"/g, "'")}`,
                `font-size:${fs}px`,
                `font-weight:${fw}`,
                `font-style:${fst}`,
                `text-decoration:${td}`,
                `text-align:${ta}`,
                `color:${col}`,
                `line-height:${lineH}`,
                `letter-spacing:${ls}px`,
                `text-transform:${tt}`,
                'white-space:pre-wrap',
                'overflow-wrap:break-word',
                'word-wrap:break-word',
                'word-break:break-word',
                `overflow:${overflow}`,
                'vertical-align:top',
            ].join(';');
        },

        imageImgCss(item) {
            const R = typeof window !== 'undefined' ? window.EkoCanvasRenderer : null;
            if (R && typeof R.imageImgCss === 'function') {
                return R.imageImgCss(item);
            }
            const st = item.styles || {};
            const fit = String(st.objectFit || 'cover').toLowerCase();
            const f = ['contain', 'cover', 'fill', 'none', 'scale-down'].includes(fit) ? fit : 'cover';
            return `width:100%;height:100%;display:block;object-fit:${f}`;
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
            if (el && (el.type === 'text' || el.type === 'placeholder')) {
                const id = el.id;
                this.$nextTick(() => {
                    this.$nextTick(() => this.fitTextElementHeightToContent(id));
                });
            }
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
            if (el.type === 'text' || el.type === 'placeholder' || el.type === 'image' || el.type === 'rectangle') {
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
         * If the rendered text/textarea extends above or below the element host, grow `item.height`
         * so the frame no longer clips (line breaks, large font, letter-spacing). Only increases height.
         *
         * @param {string|null|undefined} elementId
         */
        fitTextElementHeightToContent(elementId) {
            if (this.previewOnly || elementId == null || String(elementId) === '') {
                return;
            }
            const item = this.elements.find((x) => x && String(x.id) === String(elementId));
            if (!item || (item.type !== 'text' && item.type !== 'placeholder')) {
                return;
            }
            const root = this.$refs && this.$refs.editorCanvas;
            if (!root || !root.querySelector) {
                return;
            }
            const esc =
                typeof CSS !== 'undefined' && typeof CSS.escape === 'function'
                    ? CSS.escape(String(elementId))
                    : String(elementId).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
            const host = root.querySelector('[data-element-id="' + esc + '"]');
            if (!host || host.nodeType !== 1) {
                return;
            }
            const hit = host.querySelector('.eko-sampa-editor__inline-hit');
            if (!hit) {
                return;
            }
            const wrap = hit.querySelector(':scope > div');
            const span = wrap ? wrap.querySelector('span') : null;
            const ta = hit.querySelector('textarea.eko-sampa-editor__inline-field');
            let node = null;
            if (span && span.nodeType === 1) {
                try {
                    if (String(window.getComputedStyle(span).display || '').toLowerCase() !== 'none') {
                        node = span;
                    }
                } catch (e) {
                    void e;
                }
            }
            if (!node && ta && ta.nodeType === 1) {
                node = ta;
            }
            if (!node) {
                return;
            }
            void host.offsetHeight;
            const hr = host.getBoundingClientRect();
            const sr = node.getBoundingClientRect();
            const topOver = Math.max(0, Math.ceil(hr.top - sr.top));
            const bottomOver = Math.max(0, Math.ceil(sr.bottom - hr.bottom));
            if (topOver === 0 && bottomOver === 0) {
                return;
            }
            const nh = Math.ceil(hr.height + topOver + bottomOver);
            const prev = Number(item.height);
            if (!Number.isFinite(prev)) {
                return;
            }
            item.height = Math.max(prev, nh);
            this.clampElementInCanvas(item);
        },

        /** @see fitTextElementHeightToContent */
        fitAllTextElementsHeightsToContent() {
            if (this.previewOnly || !Array.isArray(this.elements)) {
                return;
            }
            for (let i = 0; i < this.elements.length; i++) {
                const el = this.elements[i];
                if (el && (el.type === 'text' || el.type === 'placeholder')) {
                    this.fitTextElementHeightToContent(el.id);
                }
            }
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
                    type === 'text' || type === 'placeholder' || type === 'image' || type === 'rectangle'
                        ? this.sanitizedElementStyles(type, raw.styles)
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

        /**
         * Stable fingerprint for dirty detection (elements + page size).
         *
         * @returns {string}
         */
        buildSaveFingerprint() {
            try {
                return JSON.stringify({
                    width_mm: this.widthMm,
                    height_mm: this.heightMm,
                    elements: this.elements,
                });
            } catch (e) {
                return '';
            }
        },

        markUnsaved() {
            if (this.previewOnly) {
                return;
            }
            const id = Number(this.cfg().templateId || 0);
            if (!id) {
                this.hasUnsavedChanges = false;
                return;
            }
            if (this.lastOkFingerprint == null) {
                this.hasUnsavedChanges = false;
                return;
            }
            const fp = this.buildSaveFingerprint();
            const next = fp !== String(this.lastOkFingerprint);
            if (next) {
                this.saveResult = '';
            }
            this.hasUnsavedChanges = next;
            if (next) {
                this.scheduleSessionAutosave();
            }
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

        _editorCanvasClientScale() {
            const fit = this.previewOnly ? this.orderPreviewFit : 1;
            return this.zoomFactor() * fit;
        },

        /**
         * Pointer angle (radians) from element box center in screen space (matches stage scale).
         *
         * @param {PointerEvent|MouseEvent} ev
         * @param {object} item
         * @returns {number}
         */
        _editorRotatePointerAngleRad(ev, item) {
            const canvas = this.$refs.editorCanvas;
            if (!canvas || !item || !ev) {
                return 0;
            }
            const rect = canvas.getBoundingClientRect();
            const s = this._editorCanvasClientScale();
            const cx = rect.left + (Number(item.x) + Number(item.width) / 2) * s;
            const cy = rect.top + (Number(item.y) + Number(item.height) / 2) * s;
            return Math.atan2(ev.clientY - cy, ev.clientX - cx);
        },

        /**
         * On-canvas rotate handle: drag updates `item.styles.rotate` in [0, 360)°, same field as sidebar sliders.
         *
         * @param {PointerEvent} ev
         * @param {object} item
         */
        editorRotateFabPointerDown(ev, item) {
            if (this.previewOnly || !item) {
                return;
            }
            if (typeof ev.button === 'number' && ev.button !== 0) {
                return;
            }
            ev.preventDefault();
            if (!item.styles || typeof item.styles !== 'object') {
                item.styles = {};
            }
            item.styles.rotate = this.normalizeAngle360(Number(item.styles.rotate) || 0);
            const startAngle = this._editorRotatePointerAngleRad(ev, item);
            this._rotateFabDrag = { itemId: item.id, lastAngle: startAngle };
            const self = this;
            const move = function (e) {
                const d = self._rotateFabDrag;
                if (!d) {
                    return;
                }
                const el = self.elements.find((x) => String(x.id) === String(d.itemId));
                if (!el) {
                    return;
                }
                if (!el.styles || typeof el.styles !== 'object') {
                    el.styles = {};
                }
                const cur = self._editorRotatePointerAngleRad(e, el);
                let deltaRad = cur - d.lastAngle;
                while (deltaRad > Math.PI) {
                    deltaRad -= 2 * Math.PI;
                }
                while (deltaRad < -Math.PI) {
                    deltaRad += 2 * Math.PI;
                }
                d.lastAngle = cur;
                const deltaDeg =
                    (deltaRad * 180) / Math.PI * self.ROTATE_DRAG_SENSITIVITY;
                const curRot = self.normalizeAngle360(Number(el.styles.rotate) || 0);
                const next = self.normalizeAngle360(curRot + deltaDeg);
                el.styles.rotate = self.applyRotateSoftSnapDeg(next);
            };
            const up = function () {
                const dragId = self._rotateFabDrag ? self._rotateFabDrag.itemId : null;
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', up);
                window.removeEventListener('pointercancel', up);
                self._rotateFabDrag = null;
                self._rotateFabMoveHandler = null;
                self._rotateFabUpHandler = null;
                if (dragId != null) {
                    const el2 = self.elements.find((x) => String(x.id) === String(dragId));
                    if (el2 && el2.styles && typeof el2.styles === 'object') {
                        const before = self.normalizeAngle360(Number(el2.styles.rotate) || 0);
                        const after = self.hardSnapRotateIfCloseDeg(before);
                        el2.styles.rotate = after;
                        if (self.circularAbsDeltaDeg(before, after) > 0.05) {
                            self._triggerRotateSnapVisual(dragId);
                        }
                    }
                }
            };
            this._rotateFabMoveHandler = move;
            this._rotateFabUpHandler = up;
            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
            window.addEventListener('pointercancel', up);
            try {
                if (ev.currentTarget && typeof ev.currentTarget.setPointerCapture === 'function') {
                    ev.currentTarget.setPointerCapture(ev.pointerId);
                }
            } catch (err) {
                void err;
            }
        },

        clearSelectionIfCanvas(ev) {
            if (this.galleryOpen || this.quickPrint.open) {
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
            if (ev.key === 'Escape' && this.inlineOpen && !this.galleryOpen && !this.quickPrint.open) {
                ev.preventDefault();
                this.cancelInlineEdit();
                return;
            }
            if (ev.key === 'Escape' && this.quickPrint.open) {
                ev.preventDefault();
                this.closeQuickPrintManager();
                return;
            }
            if (ev.key === 'Delete' && !this.inlineOpen && !this.galleryOpen && !this.quickPrint.open) {
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
                styles: Object.assign({}, this.defaultRectangleStyles()),
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

        bringForward() {
            if (this.previewOnly || !this.selectedId) {
                return;
            }
            const i = this.elements.findIndex((e) => e.id === this.selectedId);
            if (i < 0 || i >= this.elements.length - 1) {
                return;
            }
            const el = this.elements.splice(i, 1)[0];
            this.elements.splice(i + 1, 0, el);
            this.elements = this.elements.slice();
            this.normalizeLayerOrder();
        },

        sendBackward() {
            if (this.previewOnly || !this.selectedId) {
                return;
            }
            const i = this.elements.findIndex((e) => e.id === this.selectedId);
            if (i <= 0) {
                return;
            }
            const el = this.elements.splice(i, 1)[0];
            this.elements.splice(i - 1, 0, el);
            this.elements = this.elements.slice();
            this.normalizeLayerOrder();
        },

        _alignableSelected() {
            if (this.previewOnly || !this.selectedId) {
                return null;
            }
            return this.elements.find((e) => e.id === this.selectedId) || null;
        },

        alignElementLeft() {
            const el = this._alignableSelected();
            if (!el) {
                return;
            }
            el.x = 0;
            this.clampElementInCanvas(el);
        },

        alignElementRight() {
            const el = this._alignableSelected();
            if (!el) {
                return;
            }
            el.x = Math.max(0, Math.round(this.canvasWidth - el.width));
            this.clampElementInCanvas(el);
        },

        alignElementCenterHorizontal() {
            const el = this._alignableSelected();
            if (!el) {
                return;
            }
            el.x = Math.max(0, Math.round((this.canvasWidth - el.width) / 2));
            this.clampElementInCanvas(el);
        },

        alignElementTop() {
            const el = this._alignableSelected();
            if (!el) {
                return;
            }
            el.y = 0;
            this.clampElementInCanvas(el);
        },

        alignElementBottom() {
            const el = this._alignableSelected();
            if (!el) {
                return;
            }
            el.y = Math.max(0, Math.round(this.canvasHeight - el.height));
            this.clampElementInCanvas(el);
        },

        alignElementCenterVertical() {
            const el = this._alignableSelected();
            if (!el) {
                return;
            }
            el.y = Math.max(0, Math.round((this.canvasHeight - el.height) / 2));
            this.clampElementInCanvas(el);
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
                const ta = document.getElementById('eko-inline-edit-' + String(item.id));
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

        /**
         * Persist template to server (manual save only).
         */
        async saveNow() {
            if (this.previewOnly) {
                return;
            }
            this._forceSessionThumb = true;
            const id = Number(this.cfg().templateId || 0);
            if (!id) {
                return;
            }
            await this.persist();
        },

        async persist() {
            const id = Number(this.cfg().templateId || 0);
            if (!id) {
                return;
            }
            if (this._persistRunning) {
                return;
            }
            this._persistRunning = true;
            const templateIdAtStart = id;
            try {
                const v = this.validateElementsForSave(this.elements);
                if (!v.ok) {
                    this.hasUnsavedChanges = true;
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
                    this.hasUnsavedChanges = true;
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
                    this.saveResult = '';
                    return;
                }

                this.lastOkElementsJson = this.captureElementsJson();
                this.lastOkFingerprint = this.buildSaveFingerprint();
                this.hasUnsavedChanges = false;
                this.saveResult = 'Gravado';
                this.scheduleThumbnail(templateIdAtStart);
                if (this.captureElementsJson() !== sentJson) {
                    this.markUnsaved();
                }
            } catch (e) {
                if (Number(this.cfg().templateId || 0) === templateIdAtStart) {
                    this.hasUnsavedChanges = true;
                    this.saveResult = String(e.message || e);
                    if (this.lastOkElementsJson) {
                        this.restoreElementsFromJson(this.lastOkElementsJson);
                    }
                }
            } finally {
                this._persistRunning = false;
            }
        },

        scheduleThumbnail(templateId) {
            const tok = String((this.cfg() || {}).templateSessionToken || '').trim();
            if (tok) {
                const now = Date.now();
                const minGap = 45000;
                if (!this._forceSessionThumb && now - (this._lastSessionThumbAt || 0) < minGap) {
                    return;
                }
                this._lastSessionThumbAt = now;
                this._forceSessionThumb = false;
            }
            this.generateThumbnail(templateId);
        },

        async generateThumbnail(templateId) {
            const id = parseInt(String(templateId), 10);
            const R = window.EkoCanvasRenderer;
            const Ex = window.EkoThumbnailExport;
            if (!id || !R || !Ex || typeof Ex.captureAndUpload !== 'function') {
                return;
            }
            if (Number(this.cfg().templateId || 0) !== id) {
                return;
            }
            try {
                const payload = R.normalizePayload({
                    width_mm: this.widthMm,
                    height_mm: this.heightMm,
                    background_color: this.templateBackgroundColor || '#ffffff',
                    elements: this.elements,
                });
                const V = window.EkoThumbnailVisual;
                if (V && typeof V.visualChecksum === 'function') {
                    payload.visual_hash = V.visualChecksum(payload);
                }
                await this.$nextTick();
                const canvasEl = this.$refs.editorCanvas;
                try {
                    await Ex.captureAndUpload(id, payload, {
                        source: 'editor_save',
                        maxWidth: 520,
                        quality: 0.85,
                        storedVisualHash: this._lastThumbVisualHash || '',
                        hasThumbnail: !!this._hasThumbnail,
                        liveCanvasRoot: canvasEl && canvasEl.nodeType === 1 ? canvasEl : null,
                        pageBackgroundSolid:
                            R && typeof R.canvasPageBackgroundSolid === 'function'
                                ? R.canvasPageBackgroundSolid(payload)
                                : this.templateBackgroundColor || '#ffffff',
                    });
                } catch (clientErr) {
                    await this.api('templates/' + id + '/thumbnail/generate', {
                        method: 'POST',
                        body: { source: 'editor_save_fallback', force: true },
                    });
                    void clientErr;
                }
            } catch (e) {
                try {
                    await this.api('templates/' + id + '/thumbnail/generate', {
                        method: 'POST',
                        body: { source: 'editor_save_fallback', force: true },
                    });
                } catch (e2) {
                    if (window.EKO_RENDER_DEBUG || (R && R.isRenderDebug && R.isRenderDebug())) {
                        // eslint-disable-next-line no-console
                        console.warn('[EkoThumbnail] editor save', e2);
                    }
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
                    const n = arr.length;
                    const fromArr = n - 1 - oldIndex;
                    const toArr = n - 1 - newIndex;
                    const moved = arr.splice(fromArr, 1)[0];
                    arr.splice(toArr, 0, moved);
                    self.$nextTick(() => {
                        self.elements = self.elements.slice();
                        self.normalizeLayerOrder();
                    });
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

            const self = this;
            const selector = '.eko-sampa-editor__canvas .eko-sampa-editor__element';

            document.querySelectorAll(selector).forEach((node) => {
                try {
                    interact(node).unset();
                } catch (e) {
                    void e;
                }
                self.clearInteractHostStyles(node);
            });

            const nodes = document.querySelectorAll(selector);
            if (nodes.length === 0) {
                return;
            }

            nodes.forEach((node) => {
                self.clearInteractHostStyles(node);
                interact(node)
                    .draggable({
                        ignoreFrom: '.eko-sampa-editor__resize-handle, .eko-sampa-editor__inline-field, .eko-sampa-editor__rotate-fab',
                        inertia: false,
                        /** Sem restrict: o canvas está dentro de um stage com transform:scale; o restrict do Interact calculava mal e prendia tudo no canto. O clamp em JS mantém o layout dentro do canvas. */
                        listeners: {
                            start(event) {
                                const host = self._editorInteractHostFromPointer(event);
                                const id = host ? host.getAttribute('data-element-id') : null;
                                self.draggingId = id;
                            },
                            move(event) {
                                const scale = self.zoomFactor();
                                const host = self._editorInteractHostFromPointer(event);
                                const id = host ? host.getAttribute('data-element-id') : null;
                                const item = id ? self.elements.find((e) => e.id === id) : null;
                                if (!item) {
                                    return;
                                }
                                item.x += event.dx / scale;
                                item.y += event.dy / scale;
                                self.clampElementInCanvas(item);
                            },
                            end(event) {
                                const host = self._editorInteractHostFromPointer(event);
                                if (host) {
                                    self.clearInteractHostStyles(host);
                                }
                                const id = host ? host.getAttribute('data-element-id') : null;
                                self.draggingId = null;
                                const item = id ? self.elements.find((e) => e.id === id) : null;
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
                                const host = self._editorInteractHostFromPointer(event);
                                const id = host ? host.getAttribute('data-element-id') : null;
                                self.draggingId = id;
                            },
                            move(event) {
                                const scale = self.zoomFactor();
                                const host = self._editorInteractHostFromPointer(event);
                                const id = host ? host.getAttribute('data-element-id') : null;
                                const item = id ? self.elements.find((e) => e.id === id) : null;
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
                                const host = self._editorInteractHostFromPointer(event);
                                if (host) {
                                    self.clearInteractHostStyles(host);
                                }
                                const id = host ? host.getAttribute('data-element-id') : null;
                                self.draggingId = null;
                                const item = id ? self.elements.find((e) => e.id === id) : null;
                                if (!item) {
                                    return;
                                }
                                self.snapBox(item);
                                self.clampElementInCanvas(item);
                                self.triggerSnapFlash();
                                if (item.type === 'text' || item.type === 'placeholder') {
                                    self.$nextTick(() => {
                                        self.$nextTick(() => self.fitTextElementHeightToContent(id));
                                    });
                                }
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
            if (this.selectedId == null || this.selectedId === '') {
                return;
            }
            const src = this.elements.find((e) => String(e.id) === String(this.selectedId));
            if (!src) {
                return;
            }
            let raw;
            try {
                // Alpine reactive proxies are not structuredClone-safe; JSON round-trip is a reliable deep clone here.
                raw = JSON.parse(JSON.stringify(src));
            } catch (e) {
                return;
            }
            raw.id = this.uid(String(raw.type || 'el'));
            raw.x = this.sanitizeNumber(raw.x, 0) + 15;
            raw.y = this.sanitizeNumber(raw.y, 0) + 15;
            this.elements.push(raw);
            this.elements = this.elements.slice();
            this.normalizeElements();
            this.select(String(raw.id));
        },
    };
}

window.ekoEditorCanvasFactory = ekoEditorCanvasFactory;


(function installEkoEditorDiagnosticsNoOp() {
    window.ekoLayerDiagnostics = {
        collect() {
            return { elements: [], duplicated_z_index: [] };
        },
        validate() {
            return { ok: true, warnings: [], elements: [] };
        },
    };
    window.ekoVisualBoxDiagnostics = {
        collect() {
            return { clipped_elements: [] };
        },
    };
    window.ekoTextEditDiagnostics = {
        lastSession: null,
        recordOpen() {},
        recordClose() {},
        compareMetrics() {
            return { active: false };
        },
    };
    window.__ekoVisualRegressionDebug = {
        enabled: false,
        dump() {
            return { enabled: false };
        },
    };
})();
// Listener must exist before Alpine starts — enforced in PHP enqueue order, not script deps on Alpine.
document.addEventListener('alpine:init', () => {
    Alpine.data('ekoEditorCanvas', ekoEditorCanvasFactory);
});
