/**
 * Thumbnail pipeline: queue, lock, abort, visual checksum, raster adapters, history.
 *
 * Client JPEG export uses html2canvas (foreignObjectRendering: false) → real bitmap →
 * high-quality downscale → JPEG flatten. Live editor path clones the canvas into an offscreen
 * “presentation” subtree (no grid, selection chrome, zoom parent, or inline textarea) before raster.
 */
(function (global) {
    'use strict';

    const CFG = global.EkoThumbnailConfig || {};
    const LC = global.EkoThumbnailLifecycle || {};
    const History = global.EkoThumbnailHistoryStore || { push() {}, setLifecycle() {} };
    const Visual = global.EkoThumbnailVisual || {
        visualChecksum() {
            return '';
        },
        payloadFromTemplateRow(r) {
            return r;
        },
    };

    function waitFrame() {
        return new Promise((resolve) => requestAnimationFrame(() => resolve()));
    }

    function logDebug() {
        if (!global.EKO_RENDER_DEBUG && !global.EkoCanvasRenderer?.isRenderDebug?.()) {
            return;
        }
        // eslint-disable-next-line no-console
        console.log.apply(console, ['[EkoThumbnail]'].concat(Array.prototype.slice.call(arguments)));
    }

    function classifyError(err) {
        const msg = String((err && err.message) || err || '').toLowerCase();
        if (err && err.name === 'AbortError') {
            return { retryable: false, kind: 'aborted' };
        }
        if (msg.indexOf('aborted') !== -1 || msg.indexOf('abort') !== -1) {
            return { retryable: false, kind: 'aborted' };
        }
        if (msg.indexOf('timeout') !== -1 || msg.indexOf('network') !== -1 || msg.indexOf('fetch') !== -1) {
            return { retryable: true, kind: 'network' };
        }
        if (msg.indexOf('asset') !== -1 || (msg.indexOf('image') !== -1 && msg.indexOf('load') !== -1)) {
            return { retryable: true, kind: 'asset' };
        }
        if (
            msg.indexOf('empty') !== -1 ||
            msg.indexOf('invalid') !== -1 ||
            msg.indexOf('unavailable') !== -1 ||
            msg.indexOf('mount') !== -1 ||
            msg.indexOf('exceeds') !== -1 ||
            msg.indexOf('canvas') !== -1 ||
            msg.indexOf('taint') !== -1
        ) {
            return { retryable: false, kind: 'fatal' };
        }
        return { retryable: true, kind: 'unknown' };
    }

    /** Per-template generation slot (lock + abort). */
    const slots = new Map();

    function getSlot(templateId) {
        const id = parseInt(String(templateId), 10);
        if (!slots.has(id)) {
            slots.set(id, {
                id: id,
                lifecycle: LC.IDLE || 'idle',
                runId: 0,
                abortController: null,
                lock: false,
            });
        }
        return slots.get(id);
    }

    function setSlotLifecycle(templateId, state, extra) {
        const slot = getSlot(templateId);
        slot.lifecycle = state;
        History.setLifecycle(templateId, state, extra);
        try {
            document.dispatchEvent(
                new CustomEvent('eko-sampa:thumbnail-lifecycle', {
                    detail: { templateId: templateId, state: state, extra: extra || {} },
                })
            );
        } catch (e) {
            void e;
        }
    }

    function abortSlot(templateId, reason) {
        const slot = getSlot(templateId);
        if (slot.abortController) {
            try {
                slot.abortController.abort(reason || 'superseded');
            } catch (e) {
                void e;
            }
            slot.abortController = null;
        }
        if (slot.lifecycle === (LC.GENERATING || 'generating') || slot.lifecycle === (LC.QUEUED || 'queued')) {
            setSlotLifecycle(templateId, LC.ABORTED || 'aborted', { reason: reason || 'superseded' });
        }
    }

    function acquireSlot(templateId) {
        const id = parseInt(String(templateId), 10);
        abortSlot(id, 'new-run');
        const slot = getSlot(id);
        slot.runId += 1;
        const runId = slot.runId;
        slot.abortController = typeof AbortController !== 'undefined' ? new AbortController() : null;
        slot.lock = true;
        return { id: id, runId: runId, signal: slot.abortController ? slot.abortController.signal : null };
    }

    function releaseSlot(templateId, runId) {
        const slot = getSlot(templateId);
        if (slot.runId === runId) {
            slot.lock = false;
            slot.abortController = null;
        }
    }

    function isRunCurrent(templateId, runId) {
        return getSlot(templateId).runId === runId;
    }

    function throwIfAborted(signal) {
        if (signal && signal.aborted) {
            const err = new Error('thumbnail aborted');
            err.name = 'AbortError';
            throw err;
        }
    }

    function getHtml2Canvas() {
        if (typeof global.html2canvas === 'function') {
            return global.html2canvas;
        }
        return null;
    }

    /**
     * Exclude editor-only chrome (same intent as previous html-to-image filter).
     *
     * @param {Node} node
     * @returns {boolean} true = keep node
     */
    function liveEditorThumbnailDomFilter(node) {
        if (!node || node.nodeType !== 1) {
            return true;
        }
        const el = /** @type {Element} */ (node);
        if (typeof el.classList === 'undefined' || !el.classList) {
            return true;
        }
        if (el.classList.contains('eko-sampa-editor__resize-handle')) {
            return false;
        }
        if (el.classList.contains('eko-sampa-editor__rotate-fab')) {
            return false;
        }
        if (el.classList.contains('eko-sampa-editor__inline-field')) {
            return false;
        }
        if (el.tagName === 'TEMPLATE') {
            return false;
        }
        return true;
    }

    /**
     * html2canvas ignoreElements: return true to skip rendering that node.
     *
     * @param {object} ctx
     * @returns {(el: Element) => boolean}|undefined
     */
    function buildHtml2CanvasIgnoreElements(ctx) {
        const user = ctx && typeof ctx.liveDomFilter === 'function' ? ctx.liveDomFilter : null;
        return function (element) {
            const userKeep = !user || user(element) !== false;
            const liveKeep = liveEditorThumbnailDomFilter(element);
            return !(userKeep && liveKeep);
        };
    }

    function isConnectedLiveCanvas(el) {
        return !!(el && el.nodeType === 1 && el.ownerDocument === document && document.body && document.body.contains(el));
    }

    function resolveThumbnailCaptureSourceForUpload(meta) {
        const m = meta && typeof meta === 'object' ? meta : {};
        if (m.thumbnail_capture_source != null && String(m.thumbnail_capture_source).trim() !== '') {
            return String(m.thumbnail_capture_source).trim();
        }
        if (m.liveCanvasRoot && isConnectedLiveCanvas(m.liveCanvasRoot)) {
            return 'live_editor';
        }
        return 'client_dom';
    }

    /**
     * Remove Alpine / Livewire-ish bindings so cloned DOM does not keep x-show display:none, etc.
     *
     * @param {HTMLElement} root
     */
    function stripReactiveBindingsFromSubtree(root) {
        if (!root || root.nodeType !== 1) {
            return;
        }
        const stripOne = function (el) {
            if (!el || el.nodeType !== 1 || !el.attributes) {
                return;
            }
            const remove = [];
            for (let i = 0; i < el.attributes.length; i++) {
                const n = el.attributes[i].name;
                if (n.startsWith('x-') || n.startsWith('@') || n.startsWith(':') || n.startsWith('wire:')) {
                    remove.push(n);
                }
            }
            remove.forEach(function (n) {
                try {
                    el.removeAttribute(n);
                } catch (e) {
                    void e;
                }
            });
        };
        stripOne(root);
        const all = root.querySelectorAll ? root.querySelectorAll('*') : [];
        for (let j = 0; j < all.length; j++) {
            stripOne(/** @type {HTMLElement} */ (all[j]));
        }
    }

    /**
     * Strip editor-only inset selection / hover / drag ring from inline style (keeps user box-shadow).
     *
     * @param {HTMLElement} el
     */
    function stripEditorInsetBoxShadowAttr(el) {
        const raw = el.getAttribute('style');
        if (!raw || raw.indexOf('box-shadow') === -1) {
            return;
        }
        let v = raw;
        const patterns = [
            /\binset\s+0\s+0\s+0\s+2px\s+rgba?\(\s*79\s*,\s*70\s*,\s*229[^)]*\)\s*,?\s*/gi,
            /\binset\s+0\s+0\s+0\s+2px\s+rgba?\(\s*79\s+70\s+229[^)]*\)\s*,?\s*/gi,
            /\binset\s+0\s+0\s+0\s+2px\s+rgba?\(\s*165\s*,\s*180\s*,\s*252[^)]*\)\s*,?\s*/gi,
            /\binset\s+0\s+0\s+0\s+2px\s+rgba?\(\s*165\s+180\s+252[^)]*\)\s*,?\s*/gi,
            /\binset\s+0\s+0\s+0\s+1px\s+rgba?\(\s*148\s*,\s*163\s*,\s*184[^)]*\)\s*,?\s*/gi,
            /\binset\s+0\s+0\s+0\s+1px\s+rgba?\(\s*148\s+163\s+184[^)]*\)\s*,?\s*/gi,
            /\binset\s+0\s+0\s+0\s+2px\s+rgba?\(\s*16\s*,\s*185\s*,\s*129[^)]*\)\s*,?\s*/gi,
            /\binset\s+0\s+0\s+0\s+2px\s+rgba?\(\s*16\s+185\s+129[^)]*\)\s*,?\s*/gi,
        ];
        for (let i = 0; i < patterns.length; i++) {
            v = v.replace(patterns[i], '');
        }
        v = v.replace(/box-shadow\s*:\s*,/gi, 'box-shadow:');
        v = v.replace(/box-shadow\s*:\s*;/gi, '');
        v = v.replace(/;\s*;/g, ';').replace(/^;+|;+$/g, '').trim();
        if (v !== raw) {
            el.setAttribute('style', v);
        }
    }

    const PRESENTATION_STRIP_CLASSES = [
        'ring-4',
        'ring-emerald-400',
        'ring-offset-2',
        'ring-offset-white',
        'shadow-lg',
        'is-selected',
        'is-active',
        'is-editing',
        'draggable-active',
        'resizing',
        'rotating',
    ];

    /**
     * Strip capture-only noise on the text hit target (filters). Typography is synced from the live
     * editor via {@link syncPresentationTextTypographyFromLive} — do not change overflow on the frame here.
     *
     * @param {HTMLElement} clone
     */
    function presentationPatchTextStacksInClone(clone) {
        clone.querySelectorAll('.eko-sampa-editor__inline-hit').forEach(function (node) {
            const hit = /** @type {HTMLElement} */ (node);
            try {
                hit.style.removeProperty('filter');
                hit.style.removeProperty('backdrop-filter');
            } catch (e) {
                void e;
            }
        });
    }

    /** @type {string[]} */
    const THUMB_TEXT_LEAF_COMPUTED_PROPS = [
        'box-sizing',
        'display',
        'min-height',
        'min-width',
        'font-family',
        'font-size',
        'font-weight',
        'font-style',
        'line-height',
        'letter-spacing',
        'text-transform',
        'text-rendering',
        'font-kerning',
        '-webkit-font-smoothing',
        '-moz-osx-font-smoothing',
        'white-space',
        'word-break',
        'overflow-wrap',
        'word-wrap',
        'writing-mode',
        'text-size-adjust',
        '-webkit-text-size-adjust',
        'color',
        'text-align',
        'text-decoration',
        'text-decoration-line',
        'text-decoration-style',
        'text-decoration-color',
        'font-feature-settings',
        'font-variant-ligatures',
        'margin-top',
        'margin-right',
        'margin-bottom',
        'margin-left',
        'padding-top',
        'padding-right',
        'padding-bottom',
        'padding-left',
        'flex',
        'flex-grow',
        'flex-shrink',
        'flex-basis',
        'align-self',
        'order',
        'vertical-align',
        'visibility',
    ];

    /** @type {string[]} */
    const THUMB_TEXT_LAYOUT_CONTAINER_PROPS = [
        'display',
        'flex-direction',
        'flex-wrap',
        'align-items',
        'justify-content',
        'align-content',
        'align-self',
        'flex',
        'flex-grow',
        'flex-shrink',
        'flex-basis',
        'gap',
        'row-gap',
        'column-gap',
        'box-sizing',
        'min-height',
        'min-width',
        'max-height',
        'max-width',
        'overflow-x',
        'overflow-y',
        'contain',
        'isolation',
    ];

    /**
     * @param {HTMLElement} liveEl
     * @param {HTMLElement} cloneEl
     * @param {string[]} propNames
     * @param {Window} win
     */
    function copyComputedStylePropsFromLive(liveEl, cloneEl, propNames, win) {
        if (!liveEl || !cloneEl || !win || typeof win.getComputedStyle !== 'function') {
            return;
        }
        let cs;
        try {
            cs = win.getComputedStyle(liveEl);
        } catch (e) {
            void e;
            return;
        }
        for (let i = 0; i < propNames.length; i++) {
            const name = propNames[i];
            try {
                const val = cs.getPropertyValue(name);
                if (val != null && String(val).trim() !== '') {
                    cloneEl.style.setProperty(name, val.trim());
                }
            } catch (e2) {
                void e2;
            }
        }
    }

    /**
     * @param {string} id
     * @returns {string}
     */
    function escapeDataElementIdForSelector(id) {
        return String(id).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
    }

    /**
     * @param {HTMLElement} liveWrap
     * @returns {HTMLElement|null}
     */
    function resolveLiveTextLeafNode(liveWrap) {
        if (!liveWrap || !liveWrap.querySelector) {
            return null;
        }
        const span =
            liveWrap.querySelector('span.eko-thumb-presentation-text') || liveWrap.querySelector('span');
        if (span && span.nodeType === 1) {
            const win = span.ownerDocument && span.ownerDocument.defaultView;
            if (win && typeof win.getComputedStyle === 'function') {
                try {
                    if (String(win.getComputedStyle(/** @type {Element} */ (span)).display || '').toLowerCase() !== 'none') {
                        return /** @type {HTMLElement} */ (span);
                    }
                } catch (e) {
                    void e;
                }
            }
        }
        const ta = liveWrap.querySelector('textarea.eko-sampa-editor__inline-field');
        if (ta && ta.nodeType === 1) {
            return /** @type {HTMLElement} */ (ta);
        }
        return span ? /** @type {HTMLElement} */ (span) : null;
    }

    /**
     * @param {HTMLElement} cloneWrap
     * @returns {HTMLElement|null}
     */
    function resolveCloneTextLeafNode(cloneWrap) {
        if (!cloneWrap || !cloneWrap.querySelector) {
            return null;
        }
        const span =
            cloneWrap.querySelector('span.eko-thumb-presentation-text') || cloneWrap.querySelector('span');
        return span && span.nodeType === 1 ? /** @type {HTMLElement} */ (span) : null;
    }

    /**
     * Thumbnail clone only: leaf typography from live computed styles (same data-element-id).
     * Parent flex/grid layout is handled by {@link syncPresentationTextLayoutContainersFromLive}.
     *
     * @param {HTMLElement} liveRoot
     * @param {HTMLElement} presentationClone
     */
    function syncPresentationTextTypographyFromLive(liveRoot, presentationClone) {
        if (!liveRoot || !presentationClone || !liveRoot.querySelector || !presentationClone.querySelectorAll) {
            return;
        }
        const win =
            (liveRoot.ownerDocument && liveRoot.ownerDocument.defaultView) ||
            (typeof global !== 'undefined' ? global : null);
        if (!win || typeof win.getComputedStyle !== 'function') {
            return;
        }

        const cloneElements = presentationClone.querySelectorAll('.eko-sampa-editor__element');
        for (let i = 0; i < cloneElements.length; i++) {
            const cloneHost = /** @type {HTMLElement} */ (cloneElements[i]);
            const id = cloneHost.getAttribute('data-element-id');
            if (!id) {
                continue;
            }
            const liveHost = liveRoot.querySelector('[data-element-id="' + escapeDataElementIdForSelector(id) + '"]');
            if (!liveHost || liveHost.nodeType !== 1) {
                continue;
            }
            const liveHit = liveHost.querySelector('.eko-sampa-editor__inline-hit');
            const cloneHit = cloneHost.querySelector('.eko-sampa-editor__inline-hit');
            if (!liveHit || !cloneHit) {
                continue;
            }
            const liveWrap = liveHit.querySelector(':scope > div');
            const cloneWrap = cloneHit.querySelector(':scope > div');
            if (!liveWrap || !cloneWrap) {
                continue;
            }
            const liveLeaf = resolveLiveTextLeafNode(/** @type {HTMLElement} */ (liveWrap));
            const cloneLeaf = resolveCloneTextLeafNode(/** @type {HTMLElement} */ (cloneWrap));
            if (!liveLeaf || !cloneLeaf) {
                continue;
            }

            copyComputedStylePropsFromLive(liveLeaf, cloneLeaf, THUMB_TEXT_LEAF_COMPUTED_PROPS, win);
            try {
                cloneLeaf.style.setProperty('overflow', 'visible', 'important');
                cloneLeaf.style.setProperty('overflow-x', 'visible', 'important');
                cloneLeaf.style.setProperty('overflow-y', 'visible', 'important');
            } catch (e) {
                void e;
            }
        }
    }

    /**
     * Thumbnail clone only: parent layout stack for text/placeholder — computed from live DOM, then
     * mandatory flex min sizes and natural-height wrappers. Does not change user width/height on the
     * host element, top/left, transform, or the visual frame’s overflow mask (border-radius / fill).
     *
     * @param {HTMLElement} liveRoot
     * @param {HTMLElement} presentationClone
     */
    function syncPresentationTextLayoutContainersFromLive(liveRoot, presentationClone) {
        if (!liveRoot || !presentationClone || !liveRoot.querySelector || !presentationClone.querySelectorAll) {
            return;
        }
        const win =
            (liveRoot.ownerDocument && liveRoot.ownerDocument.defaultView) ||
            (typeof global !== 'undefined' ? global : null);
        if (!win || typeof win.getComputedStyle !== 'function') {
            return;
        }

        /**
         * @param {HTMLElement} el
         */
        function applyMandatoryFlexMinSize(el) {
            if (!el || el.nodeType !== 1) {
                return;
            }
            try {
                el.style.setProperty('min-height', '0', 'important');
                el.style.setProperty('min-width', '0', 'important');
            } catch (e) {
                void e;
            }
        }

        /**
         * @param {HTMLElement} liveFrame
         * @param {HTMLElement} cloneFrame
         */
        function enforceVisualFrameOverflowFromLive(liveFrame, cloneFrame) {
            let cs;
            try {
                cs = win.getComputedStyle(liveFrame);
            } catch (e) {
                void e;
                return;
            }
            try {
                const ox = cs.getPropertyValue('overflow-x');
                const oy = cs.getPropertyValue('overflow-y');
                if (ox && String(ox).trim() !== '') {
                    cloneFrame.style.setProperty('overflow-x', String(ox).trim(), 'important');
                }
                if (oy && String(oy).trim() !== '') {
                    cloneFrame.style.setProperty('overflow-y', String(oy).trim(), 'important');
                }
            } catch (e2) {
                void e2;
            }
        }

        const cloneElements = presentationClone.querySelectorAll('.eko-sampa-editor__element');
        for (let i = 0; i < cloneElements.length; i++) {
            const cloneHost = /** @type {HTMLElement} */ (cloneElements[i]);
            const id = cloneHost.getAttribute('data-element-id');
            if (!id) {
                continue;
            }
            const liveHost = liveRoot.querySelector('[data-element-id="' + escapeDataElementIdForSelector(id) + '"]');
            if (!liveHost || liveHost.nodeType !== 1) {
                continue;
            }
            const liveRotate = liveHost.querySelector(':scope > .eko-sampa-editor__rotate-wrap');
            const cloneRotate = cloneHost.querySelector(':scope > .eko-sampa-editor__rotate-wrap');
            const liveHit = liveHost.querySelector('.eko-sampa-editor__inline-hit');
            const cloneHit = cloneHost.querySelector('.eko-sampa-editor__inline-hit');
            if (!liveHit || !cloneHit) {
                continue;
            }
            const liveFrame = liveHit.parentElement;
            const cloneFrame = cloneHit.parentElement;
            if (!liveFrame || !cloneFrame || liveFrame.querySelector(':scope > img')) {
                continue;
            }
            const liveWrap = liveHit.querySelector(':scope > div');
            const cloneWrap = cloneHit.querySelector(':scope > div');
            if (!liveWrap || !cloneWrap) {
                continue;
            }
            const cloneLeaf = resolveCloneTextLeafNode(/** @type {HTMLElement} */ (cloneWrap));

            /** @type {{ live: HTMLElement, clone: HTMLElement, frame: boolean }[]} */
            const rows = [
                { live: /** @type {HTMLElement} */ (liveHost), clone: cloneHost, frame: false },
                { live: /** @type {HTMLElement} */ (liveRotate), clone: /** @type {HTMLElement} */ (cloneRotate), frame: false },
                { live: /** @type {HTMLElement} */ (liveFrame), clone: /** @type {HTMLElement} */ (cloneFrame), frame: true },
                { live: /** @type {HTMLElement} */ (liveHit), clone: cloneHit, frame: false },
                { live: /** @type {HTMLElement} */ (liveWrap), clone: /** @type {HTMLElement} */ (cloneWrap), frame: false },
            ];

            for (let r = 0; r < rows.length; r++) {
                const row = rows[r];
                if (!row.live || !row.clone || row.live.nodeType !== 1 || row.clone.nodeType !== 1) {
                    continue;
                }
                copyComputedStylePropsFromLive(row.live, row.clone, THUMB_TEXT_LAYOUT_CONTAINER_PROPS, win);
                if (row.frame) {
                    enforceVisualFrameOverflowFromLive(row.live, row.clone);
                }
                applyMandatoryFlexMinSize(row.clone);
            }

            if (cloneLeaf) {
                applyMandatoryFlexMinSize(cloneLeaf);
            }

            try {
                cloneHit.style.setProperty('height', 'auto', 'important');
                cloneHit.style.setProperty('max-height', 'none', 'important');
                cloneWrap.style.setProperty('height', 'auto', 'important');
                cloneWrap.style.setProperty('max-height', 'none', 'important');
            } catch (e3) {
                void e3;
            }
        }
    }

    /**
     * Mounted presentation clone only: grow text host height when the clone still clips vertically.
     *
     * `scrollHeight - clientHeight` is often **0** while text is still clipped: parent `overflow:hidden`
     * on the frame clips paint without creating a scrollable overflow on the span (`max-height:100%`
     * + flex). We therefore also measure **geometric** overflow (leaf bottom vs frame content bottom)
     * on the clone and subtract the same on the live editor when paired — only the **extra** clip in
     * the thumbnail path is corrected. A few passes absorb reflow.
     *
     * @param {HTMLElement} presentationRoot
     * @param {HTMLElement} liveRoot
     * @param {AbortSignal|null} signal
     * @param {number} maxPasses
     */
    function presentationBumpTextHostsForLeafVerticalOverflow(presentationRoot, liveRoot, signal, maxPasses) {
        if (!presentationRoot || !presentationRoot.querySelectorAll) {
            return;
        }
        const cap = Math.max(1, Math.min(10, maxPasses != null ? Number(maxPasses) : 6));
        const win =
            (presentationRoot.ownerDocument && presentationRoot.ownerDocument.defaultView) ||
            (typeof global !== 'undefined' ? global : null);
        if (!win || typeof win.getComputedStyle !== 'function') {
            return;
        }

        /**
         * @param {string|null|undefined} raw
         * @returns {number}
         */
        function cssPxLen(raw) {
            const n = parseFloat(String(raw == null ? '' : raw).replace(/px/gi, ''));
            return Number.isFinite(n) ? n : 0;
        }

        /**
         * Pixels of leaf extending below the frame’s **padding edge** (inside border), in viewport coords.
         *
         * @param {HTMLElement} frameEl
         * @param {HTMLElement} leafEl
         * @returns {number}
         */
        function geometricBottomOverflowPx(frameEl, leafEl) {
            if (!frameEl || !leafEl || frameEl.nodeType !== 1 || leafEl.nodeType !== 1) {
                return 0;
            }
            let cs;
            try {
                cs = win.getComputedStyle(frameEl);
            } catch (e) {
                void e;
                return 0;
            }
            const fr = frameEl.getBoundingClientRect();
            const borderBottom = cssPxLen(cs.getPropertyValue('border-bottom-width'));
            const padBottom = cssPxLen(cs.getPropertyValue('padding-bottom'));
            const innerBottom = fr.bottom - borderBottom - padBottom;
            let lr;
            try {
                lr = leafEl.getBoundingClientRect();
            } catch (e2) {
                void e2;
                return 0;
            }
            return Math.max(0, Math.ceil(lr.bottom - innerBottom));
        }

        /**
         * @param {HTMLElement} el
         * @returns {number}
         */
        function verticalScrollOverflowPx(el) {
            if (!el || el.nodeType !== 1) {
                return 0;
            }
            try {
                return Math.max(0, Math.ceil(el.scrollHeight - el.clientHeight));
            } catch (e) {
                void e;
                return 0;
            }
        }

        /**
         * @param {HTMLElement} host
         * @param {number} addPx
         */
        function bumpHostHeightPx(host, addPx) {
            if (!host || addPx <= 0) {
                return;
            }
            let st = host.getAttribute('style') || '';
            const hMatch = st.match(/height\s*:\s*([\d.]+)\s*px/i);
            let h = hMatch ? parseFloat(hMatch[1]) : host.offsetHeight;
            if (!Number.isFinite(h) || h <= 0) {
                h = Math.max(1, Math.round(host.offsetHeight || 1));
            }
            const newH = Math.max(1, Math.ceil(h + addPx));
            if (/height\s*:\s*[\d.]+\s*px/i.test(st)) {
                st = st.replace(/height\s*:\s*[\d.]+\s*px/i, 'height:' + newH + 'px');
            } else {
                st = (st ? st.replace(/;+$/g, '') + ';' : '') + 'height:' + newH + 'px';
            }
            host.setAttribute('style', st);
        }

        for (let pass = 0; pass < cap; pass++) {
            throwIfAborted(signal);
            let grew = false;
            const hits = presentationRoot.querySelectorAll('.eko-sampa-editor__inline-hit');
            for (let i = 0; i < hits.length; i++) {
                const hit = /** @type {HTMLElement} */ (hits[i]);
                const host = hit.closest('.eko-sampa-editor__element');
                const frame = hit.parentElement;
                if (!host || !frame || frame.querySelector(':scope > img')) {
                    continue;
                }
                const wrap = hit.querySelector(':scope > div');
                const leaf = wrap ? resolveCloneTextLeafNode(/** @type {HTMLElement} */ (wrap)) : null;
                if (!leaf) {
                    continue;
                }

                let dScroll = Math.max(
                    verticalScrollOverflowPx(leaf),
                    wrap ? verticalScrollOverflowPx(/** @type {HTMLElement} */ (wrap)) : 0,
                    verticalScrollOverflowPx(hit)
                );

                const geoClone = geometricBottomOverflowPx(/** @type {HTMLElement} */ (frame), leaf);
                let geoLive = 0;
                if (liveRoot && liveRoot.querySelector) {
                    const id = host.getAttribute('data-element-id');
                    if (id) {
                        const liveHost = liveRoot.querySelector('[data-element-id="' + escapeDataElementIdForSelector(id) + '"]');
                        if (liveHost && liveHost.nodeType === 1) {
                            const liveHit = liveHost.querySelector('.eko-sampa-editor__inline-hit');
                            const liveFrame = liveHit ? liveHit.parentElement : null;
                            const liveWrap = liveHit ? liveHit.querySelector(':scope > div') : null;
                            const liveLeaf = liveWrap ? resolveLiveTextLeafNode(/** @type {HTMLElement} */ (liveWrap)) : null;
                            if (liveFrame && liveLeaf) {
                                geoLive = geometricBottomOverflowPx(/** @type {HTMLElement} */ (liveFrame), liveLeaf);
                            }
                        }
                    }
                }

                let dGeo = Math.max(0, geoClone - geoLive);
                if (dScroll <= 0 && dGeo > 0) {
                    try {
                        leaf.style.setProperty('max-height', 'none', 'important');
                    } catch (e3) {
                        void e3;
                    }
                    void presentationRoot.offsetHeight;
                    dScroll = Math.max(
                        verticalScrollOverflowPx(leaf),
                        wrap ? verticalScrollOverflowPx(/** @type {HTMLElement} */ (wrap)) : 0,
                        verticalScrollOverflowPx(hit)
                    );
                    const geoClone2 = geometricBottomOverflowPx(/** @type {HTMLElement} */ (frame), leaf);
                    dGeo = Math.max(dGeo, Math.max(0, geoClone2 - geoLive));
                }

                const d = Math.max(dScroll, dGeo);
                if (d <= 0) {
                    continue;
                }
                bumpHostHeightPx(/** @type {HTMLElement} */ (host), d);
                grew = true;
            }
            void presentationRoot.offsetHeight;
            if (!grew) {
                break;
            }
        }

        throwIfAborted(signal);
        const cr = presentationRoot.getBoundingClientRect();
        const baseH = Math.round(presentationRoot.offsetHeight || cr.height || 0);
        let maxBottom = baseH;
        presentationRoot.querySelectorAll('.eko-sampa-editor__element').forEach(function (node) {
            const r = node.getBoundingClientRect();
            maxBottom = Math.max(maxBottom, Math.ceil(r.bottom - cr.top));
        });
        const slack = 4;
        const wantH = Math.max(baseH, maxBottom + slack);
        if (wantH > baseH) {
            presentationRoot.style.height = wantH + 'px';
        }
    }

    /**
     * Presentation subtree (mounted): fonts, decoded images, two frames — no layout hacks.
     *
     * @param {HTMLElement} presentationRoot
     * @param {AbortSignal|null} signal
     */
    async function presentationAwaitTypographyReady(presentationRoot, signal) {
        if (!presentationRoot || presentationRoot.nodeType !== 1) {
            return;
        }
        const doc = presentationRoot.ownerDocument || (typeof document !== 'undefined' ? document : null);
        throwIfAborted(signal);
        if (doc && doc.fonts && typeof doc.fonts.ready !== 'undefined') {
            try {
                await doc.fonts.ready;
            } catch (e) {
                void e;
            }
        }
        throwIfAborted(signal);
        const imgs = presentationRoot.querySelectorAll ? presentationRoot.querySelectorAll('img[src]') : [];
        await Promise.all(
            Array.from(imgs).map(function (img) {
                return new Promise(function (resolve) {
                    const done = function () {
                        resolve();
                    };
                    if (!(img instanceof HTMLImageElement)) {
                        done();
                        return;
                    }
                    if (img.complete && img.naturalWidth > 0) {
                        if (typeof img.decode === 'function') {
                            img.decode().then(done).catch(done);
                        } else {
                            done();
                        }
                        return;
                    }
                    img.addEventListener('load', done, { once: true });
                    img.addEventListener('error', done, { once: true });
                    setTimeout(done, 4000);
                });
            })
        );
        throwIfAborted(signal);
        await waitFrame();
        await waitFrame();
        throwIfAborted(signal);
    }

    /**
     * Deep clone of `.eko-sampa-editor__canvas` for raster: no grid, no zoom parent, no selection UI.
     *
     * @param {HTMLElement} liveRoot
     * @param {string|null|undefined} pageBackgroundSolid
     * @returns {HTMLElement}
     */
    function buildPresentationEditorCanvasClone(liveRoot, pageBackgroundSolid) {
        const clone = /** @type {HTMLElement} */ (liveRoot.cloneNode(true));
        const w = Math.max(1, Math.round(liveRoot.offsetWidth || liveRoot.clientWidth || 1));
        const h = Math.max(1, Math.round(liveRoot.offsetHeight || liveRoot.clientHeight || 1));
        const bg =
            pageBackgroundSolid != null && String(pageBackgroundSolid).trim() !== ''
                ? String(pageBackgroundSolid).trim()
                : '#ffffff';

        clone.removeAttribute('x-ref');
        clone.removeAttribute('role');

        clone.style.cssText = [
            'position:relative',
            'box-sizing:border-box',
            'width:' + w + 'px',
            'height:' + h + 'px',
            'margin:0',
            'padding:0',
            'transform:none',
            'transform-origin:top left',
            'zoom:1',
            'overflow:visible',
            'background-color:' + bg,
            'background-image:none',
            'background-size:auto',
            'background-repeat:no-repeat',
            'outline:none',
            'box-shadow:none',
            'border:1px solid rgb(226 232 240)',
        ].join(';');

        clone.querySelectorAll('.eko-sampa-editor__resize-handle, .eko-sampa-editor__rotate-fab').forEach(function (n) {
            try {
                n.parentNode && n.parentNode.removeChild(n);
            } catch (e) {
                void e;
            }
        });

        clone.querySelectorAll('textarea.eko-sampa-editor__inline-field').forEach(function (ta) {
            const span = document.createElement('span');
            span.setAttribute(
                'class',
                'pointer-events-none box-border block min-h-0 min-w-0 whitespace-pre-wrap break-words eko-thumb-presentation-text'
            );
            const ts = ta.getAttribute('style') || '';
            span.style.cssText = ts;
            span.textContent = ta.value != null ? String(ta.value) : '';
            try {
                if (ta.parentNode) {
                    ta.parentNode.replaceChild(span, ta);
                }
            } catch (e) {
                void e;
            }
        });

        clone.querySelectorAll('[data-x], [data-y]').forEach(function (el) {
            el.removeAttribute('data-x');
            el.removeAttribute('data-y');
        });

        presentationPatchTextStacksInClone(clone);

        stripReactiveBindingsFromSubtree(clone);

        clone.querySelectorAll('.eko-sampa-editor__inline-hit span').forEach(function (node) {
            const el = /** @type {HTMLElement} */ (node);
            const st = el.getAttribute('style') || '';
            if (/display\s*:\s*none/i.test(st)) {
                el.setAttribute(
                    'style',
                    st.replace(/display\s*:\s*none\s*;?/gi, '').replace(/;+$/g, '') + ';display:block'
                );
            }
        });

        clone.querySelectorAll('[style*="box-shadow"]').forEach(function (node) {
            stripEditorInsetBoxShadowAttr(/** @type {HTMLElement} */ (node));
        });

        clone.querySelectorAll('*').forEach(function (node) {
            if (!node.classList) {
                return;
            }
            for (let k = 0; k < PRESENTATION_STRIP_CLASSES.length; k++) {
                node.classList.remove(PRESENTATION_STRIP_CLASSES[k]);
            }
        });

        syncPresentationTextTypographyFromLive(liveRoot, clone);
        syncPresentationTextLayoutContainersFromLive(liveRoot, clone);

        return clone;
    }

    /**
     * @returns {HTMLElement} host (must be removed after capture)
     */
    function mountPresentationCloneForCapture(clone) {
        const host = document.createElement('div');
        host.setAttribute('data-eko-thumb-presentation-host', '1');
        host.setAttribute('aria-hidden', 'true');
        host.style.cssText = [
            'position:fixed',
            'left:-32600px',
            'top:0',
            'margin:0',
            'padding:0',
            'overflow:visible',
            'z-index:-2147483648',
            'visibility:visible',
            'opacity:1',
            'pointer-events:none',
            'transform:none',
            'width:auto',
            'height:auto',
            'line-height:normal',
        ].join(';');
        host.appendChild(clone);
        document.body.appendChild(host);
        return host;
    }

    /**
     * @param {HTMLElement} root
     * @param {number} fontTimeoutMs
     * @param {number} imgTimeoutMs
     * @param {AbortSignal|null} signal
     */
    async function waitFontsAndImages(root, fontTimeoutMs, imgTimeoutMs, signal) {
        throwIfAborted(signal);
        if (typeof document !== 'undefined' && document.fonts && typeof document.fonts.ready !== 'undefined') {
            await Promise.race([
                document.fonts.ready,
                new Promise((resolve) => setTimeout(resolve, fontTimeoutMs)),
            ]);
        }
        const imgs = root.querySelectorAll ? root.querySelectorAll('img[src]') : [];
        await Promise.all(
            Array.from(imgs).map(function (img) {
                return new Promise(function (resolve) {
                    const done = function () {
                        resolve();
                    };
                    if (img.complete && img.naturalWidth > 0) {
                        if (typeof img.decode === 'function') {
                            img.decode().then(done).catch(done);
                        } else {
                            done();
                        }
                        return;
                    }
                    img.addEventListener('load', done, { once: true });
                    img.addEventListener('error', done, { once: true });
                    setTimeout(done, imgTimeoutMs);
                });
            })
        );
        await waitFrame();
        await waitFrame();
        throwIfAborted(signal);
    }

    /**
     * html2canvas (foreignObjectRendering: false) often ignores CSS object-fit on img elements.
     * Replace each decoded image with a same-size canvas drawn using object-fit semantics.
     *
     * @param {HTMLElement} root Presentation subtree (mounted, laid out).
     */
    function flattenImageElementsForHtml2Canvas(root) {
        if (!root || !root.querySelectorAll) {
            return;
        }
        const imgs = root.querySelectorAll('img');
        for (let i = 0; i < imgs.length; i++) {
            const img = imgs[i];
            if (!(img instanceof HTMLImageElement)) {
                continue;
            }
            if (img.getAttribute('data-eko-thumb-img-flat') === '1') {
                continue;
            }
            const iw = img.naturalWidth;
            const ih = img.naturalHeight;
            if (!iw || !ih) {
                continue;
            }
            const parent = img.parentElement;
            if (!parent) {
                continue;
            }
            const cw = Math.max(1, Math.round(parent.clientWidth || 0));
            const ch = Math.max(1, Math.round(parent.clientHeight || 0));
            if (cw < 2 || ch < 2) {
                continue;
            }
            const st = img.getAttribute('style') || '';
            let mode = 'cover';
            const fitM = st.match(/object-fit\s*:\s*([^;]+)/i);
            if (fitM && fitM[1]) {
                const m = String(fitM[1]).trim().toLowerCase();
                if (['contain', 'cover', 'fill', 'none', 'scale-down'].indexOf(m) !== -1) {
                    mode = m;
                }
            }
            const cvs = document.createElement('canvas');
            cvs.width = cw;
            cvs.height = ch;
            const ctx = cvs.getContext('2d');
            if (!ctx) {
                continue;
            }
            try {
                if (mode === 'fill') {
                    ctx.drawImage(img, 0, 0, iw, ih, 0, 0, cw, ch);
                } else if (mode === 'contain' || mode === 'scale-down') {
                    let sc = Math.min(cw / iw, ch / ih);
                    if (mode === 'scale-down') {
                        sc = Math.min(sc, 1);
                    }
                    const dw = iw * sc;
                    const dh = ih * sc;
                    const dx = (cw - dw) / 2;
                    const dy = (ch - dh) / 2;
                    ctx.drawImage(img, 0, 0, iw, ih, dx, dy, dw, dh);
                } else if (mode === 'none') {
                    ctx.save();
                    ctx.beginPath();
                    ctx.rect(0, 0, cw, ch);
                    ctx.clip();
                    ctx.drawImage(img, 0, 0);
                    ctx.restore();
                } else {
                    const sc = Math.max(cw / iw, ch / ih);
                    const sw = cw / sc;
                    const sh = ch / sc;
                    const sx = (iw - sw) / 2;
                    const sy = (ih - sh) / 2;
                    ctx.drawImage(img, sx, sy, sw, sh, 0, 0, cw, ch);
                }
            } catch (e) {
                logDebug('flattenImage skip (taint or draw)', { err: String(e && e.message ? e.message : e) });
                continue;
            }
            cvs.setAttribute('data-eko-thumb-img-flat', '1');
            cvs.setAttribute('aria-hidden', 'true');
            cvs.className = img.className;
            cvs.style.cssText = [
                'display:block',
                'width:100%',
                'height:100%',
                'max-width:100%',
                'max-height:100%',
                'margin:0',
                'padding:0',
                'vertical-align:top',
                '-webkit-print-color-adjust:exact',
                'print-color-adjust:exact',
            ].join(';');
            try {
                img.parentNode.replaceChild(cvs, img);
            } catch (e2) {
                void e2;
            }
        }
    }

    /**
     * html2canvas → bitmap → high-quality downscale → JPEG (flatten with pageBackgroundSolid).
     *
     * @param {HTMLElement} element
     * @param {object} opts
     * @param {AbortSignal|null} signal
     * @returns {Promise<string>} data URL
     */
    async function rasterDomToJpegDataUrl(element, opts, signal) {
        throwIfAborted(signal);
        const h2c = getHtml2Canvas();
        if (!h2c) {
            throw new Error('html2canvas library not loaded');
        }

        const natW = Math.max(1, Math.round(element.offsetWidth || 1));
        const natH = Math.max(1, Math.round(element.offsetHeight || 1));
        const maxW = opts.maxOutputWidth != null ? opts.maxOutputWidth : CFG.MAX_WIDTH_PX;
        const destW = Math.max(1, Math.round(natW * Math.min(1, maxW / natW)));
        const destH = Math.max(1, Math.round(natH * Math.min(1, maxW / natW)));

        const cap = typeof CFG.CAPTURE_PIXEL_RATIO_CAP === 'number' ? CFG.CAPTURE_PIXEL_RATIO_CAP : 3;
        const dpr =
            typeof global !== 'undefined' && global.devicePixelRatio
                ? Math.min(cap, Math.max(1, global.devicePixelRatio))
                : 1;
        let scaleMul =
            typeof CFG.THUMBNAIL_CAPTURE_SCALE_MUL === 'number' ? CFG.THUMBNAIL_CAPTURE_SCALE_MUL : 2;
        if (opts.scaleMul != null && Number.isFinite(Number(opts.scaleMul))) {
            scaleMul = Math.max(1, Number(opts.scaleMul));
        }
        const h2cScale = scaleMul * dpr;

        const jpegBg =
            opts.pageBackgroundSolid != null && String(opts.pageBackgroundSolid).trim() !== ''
                ? String(opts.pageBackgroundSolid).trim()
                : '#ffffff';
        const quality = opts.quality != null ? opts.quality : CFG.JPEG_QUALITY;

        const baseOpt = {
            foreignObjectRendering: false,
            useCORS: true,
            allowTaint: false,
            backgroundColor: jpegBg,
            scale: h2cScale,
            logging: false,
            removeContainer: true,
            imageTimeout: opts.imageTimeout != null ? opts.imageTimeout : 15000,
            ignoreElements: opts.ignoreElements,
        };

        let sourceCanvas;
        try {
            sourceCanvas = await h2c(element, baseOpt);
        } catch (firstErr) {
            throwIfAborted(signal);
            const c = classifyError(firstErr);
            if (!c.retryable) {
                throw firstErr;
            }
            sourceCanvas = await h2c(element, Object.assign({}, baseOpt, { scale: Math.max(1, dpr) }));
        }

        const srcW = sourceCanvas.width;
        const srcH = sourceCanvas.height;
        if (srcW < 2 || srcH < 2) {
            throw new Error('thumbnail capture canvas too small');
        }

        const out = document.createElement('canvas');
        out.width = destW;
        out.height = destH;
        const ctx2d = out.getContext('2d');
        if (!ctx2d) {
            throw new Error('CanvasRenderingContext2D unavailable');
        }
        ctx2d.imageSmoothingEnabled = true;
        ctx2d.imageSmoothingQuality = 'high';
        ctx2d.fillStyle = jpegBg;
        ctx2d.fillRect(0, 0, destW, destH);

        const scaleUniform = Math.min(destW / srcW, destH / srcH);
        const dw = Math.max(1, Math.floor(srcW * scaleUniform));
        const dh = Math.max(1, Math.floor(srcH * scaleUniform));
        const ox = Math.floor((destW - dw) / 2);
        const oy = Math.floor((destH - dh) / 2);
        ctx2d.drawImage(sourceCanvas, 0, 0, srcW, srcH, ox, oy, dw, dh);

        return out.toDataURL('image/jpeg', quality);
    }

    /**
     * Live editor: rasterize a presentation-only clone (offscreen) — no grid, selection, zoom parent, or inline textarea.
     *
     * @param {HTMLElement} liveRoot
     * @param {object} ctx
     * @returns {Promise<string>}
     */
    async function captureLiveEditorCanvasToJpeg(liveRoot, ctx) {
        const opts = ctx || {};
        const timeoutMs = opts.timeoutMs || CFG.GENERATION_TIMEOUT_MS;
        const signal = opts.signal;
        const fontMs =
            opts.fontReadyTimeoutMs != null
                ? opts.fontReadyTimeoutMs
                : typeof CFG.FONT_READY_TIMEOUT_MS === 'number'
                  ? CFG.FONT_READY_TIMEOUT_MS
                  : 8000;

        let timeoutId = null;
        const timeout = new Promise((_, reject) => {
            timeoutId = setTimeout(() => reject(new Error('thumbnail timeout')), timeoutMs);
        });

        const onAbort = () => {
            if (timeoutId) {
                clearTimeout(timeoutId);
            }
        };
        if (signal) {
            signal.addEventListener('abort', onAbort, { once: true });
        }

        const runCapture = async function () {
            await waitFontsAndImages(liveRoot, fontMs, Math.min(12000, timeoutMs - 500), signal);
            const ignoreElements = buildHtml2CanvasIgnoreElements(ctx);
            let host = null;
            try {
                const presentation = buildPresentationEditorCanvasClone(liveRoot, opts.pageBackgroundSolid);
                host = mountPresentationCloneForCapture(presentation);
                logDebug('presentation clone mounted', {
                    w: presentation.offsetWidth,
                    h: presentation.offsetHeight,
                });
                await waitFrame();
                await waitFrame();
                throwIfAborted(signal);
                await presentationAwaitTypographyReady(presentation, signal);
                syncPresentationTextTypographyFromLive(liveRoot, presentation);
                syncPresentationTextLayoutContainersFromLive(liveRoot, presentation);
                void presentation.offsetHeight;
                await waitFrame();
                presentationBumpTextHostsForLeafVerticalOverflow(presentation, liveRoot, signal, 6);
                void presentation.offsetHeight;
                await waitFrame();
                flattenImageElementsForHtml2Canvas(presentation);
                await waitFrame();
                return await rasterDomToJpegDataUrl(
                    presentation,
                    {
                        maxOutputWidth: opts.maxWidth || CFG.MAX_WIDTH_PX,
                        quality: opts.quality != null ? opts.quality : CFG.JPEG_QUALITY,
                        pageBackgroundSolid: opts.pageBackgroundSolid,
                        ignoreElements: ignoreElements,
                        imageTimeout: Math.min(15000, timeoutMs - 2000),
                    },
                    signal
                );
            } finally {
                if (host && host.parentNode) {
                    try {
                        host.parentNode.removeChild(host);
                    } catch (e) {
                        void e;
                    }
                }
            }
        };

        try {
            return await Promise.race([runCapture(), timeout]);
        } finally {
            if (signal) {
                signal.removeEventListener('abort', onAbort);
            }
            if (timeoutId) {
                clearTimeout(timeoutId);
            }
        }
    }

    const RasterAdapters = {
        html2canvas: {
            id: 'html2canvas',
            async capture(payload, ctx) {
                throwIfAborted(ctx.signal);
                if (ctx.liveCanvasRoot && isConnectedLiveCanvas(ctx.liveCanvasRoot)) {
                    return captureLiveEditorCanvasToJpeg(ctx.liveCanvasRoot, ctx);
                }
                return capturePayloadToJpegDom(payload, ctx);
            },
        },
        server: {
            id: 'server-gd',
            async capture(payload, ctx) {
                throwIfAborted(ctx.signal);
                const id = ctx.templateId;
                const res = await global.ekoSampaApi('templates/' + id + '/thumbnail/generate', {
                    method: 'POST',
                    body: {
                        source: ctx.source || 'server_adapter',
                        visual_hash: payload.visual_hash || Visual.visualChecksum(payload),
                    },
                    signal: ctx.signal,
                });
                return { server: true, response: res };
            },
        },
    };

    function cleanupCaptureHost(host, objectUrls) {
        if (host && host.parentNode) {
            host.remove();
        }
        (objectUrls || []).forEach((u) => {
            try {
                if (u && typeof URL !== 'undefined' && URL.revokeObjectURL) {
                    URL.revokeObjectURL(u);
                }
            } catch (e) {
                void e;
            }
        });
    }

    async function captureElementToJpeg(rootEl, options, signal) {
        const opts = options || {};
        const node = rootEl.querySelector('.eko-sampa-thumbnail-root') || rootEl;
        const fontMs =
            opts.fontReadyTimeoutMs != null
                ? opts.fontReadyTimeoutMs
                : typeof CFG.FONT_READY_TIMEOUT_MS === 'number'
                  ? CFG.FONT_READY_TIMEOUT_MS
                  : 8000;
        await waitFontsAndImages(node, fontMs, 12000, signal);
        return await rasterDomToJpegDataUrl(
            node,
            {
                maxOutputWidth: opts.maxOutputWidth != null ? opts.maxOutputWidth : CFG.MAX_WIDTH_PX,
                quality: opts.quality != null ? opts.quality : CFG.JPEG_QUALITY,
                pageBackgroundSolid: opts.pageBackgroundSolid,
                ignoreElements: undefined,
                imageTimeout: opts.imageTimeout != null ? opts.imageTimeout : 15000,
            },
            signal
        );
    }

    async function capturePayloadToJpegDom(payload, ctx) {
        const R = global.EkoCanvasRenderer;
        if (!R || typeof R.runRenderPipeline !== 'function') {
            throw new Error('EkoCanvasRenderer unavailable');
        }

        const opts = ctx || {};
        const maxWidth = opts.maxWidth || CFG.MAX_WIDTH_PX;
        const timeoutMs = opts.timeoutMs || CFG.GENERATION_TIMEOUT_MS;
        const signal = opts.signal;

        const host = document.createElement('div');
        host.setAttribute('aria-hidden', 'true');
        host.setAttribute('data-eko-thumbnail-capture', '1');
        host.style.cssText =
            'position:fixed;left:0;top:0;overflow:hidden;pointer-events:none;z-index:-1;opacity:0.01;';
        document.body.appendChild(host);

        let timeoutId = null;
        const timeout = new Promise((_, reject) => {
            timeoutId = setTimeout(() => reject(new Error('thumbnail timeout')), timeoutMs);
        });

        const onAbort = () => {
            if (timeoutId) {
                clearTimeout(timeoutId);
            }
        };
        if (signal) {
            signal.addEventListener('abort', onAbort, { once: true });
        }

        try {
            throwIfAborted(signal);
            const pipeline = R.runRenderPipeline(host, payload, {
                forPrint: true,
                forThumbnail: true,
                target: R.RenderTargets.THUMBNAIL,
                maxWidth: maxWidth,
                assetTimeoutMs: Math.min(15000, timeoutMs - 2000),
                assetRetries: typeof CFG.ASSET_RETRIES === 'number' ? CFG.ASSET_RETRIES : 1,
                fontReadyTimeoutMs: typeof CFG.FONT_READY_TIMEOUT_MS === 'number' ? CFG.FONT_READY_TIMEOUT_MS : 8000,
            });

            await Promise.race([pipeline, timeout]);
            throwIfAborted(signal);
            await waitFrame();
            await waitFrame();

            const root = host.querySelector('.eko-sampa-thumbnail-root');
            if (!root) {
                throw new Error('thumbnail mount empty');
            }

            const norm = typeof R.normalizePayload === 'function' ? R.normalizePayload(payload) : payload;
            const pageBgSolid =
                typeof R.canvasPageBackgroundSolid === 'function'
                    ? R.canvasPageBackgroundSolid(norm)
                    : '#ffffff';

            return await captureElementToJpeg(
                root,
                {
                    quality: opts.quality,
                    pageBackgroundSolid: pageBgSolid,
                    maxOutputWidth: maxWidth,
                    fontReadyTimeoutMs:
                        typeof CFG.FONT_READY_TIMEOUT_MS === 'number' ? CFG.FONT_READY_TIMEOUT_MS : 8000,
                    imageTimeout: Math.min(15000, timeoutMs - 2000),
                },
                signal
            );
        } finally {
            if (signal) {
                signal.removeEventListener('abort', onAbort);
            }
            if (timeoutId) {
                clearTimeout(timeoutId);
            }
            cleanupCaptureHost(host, []);
        }
    }

    async function captureWithRetry(adapter, payload, ctx) {
        const maxAttempts = 1 + (CFG.MAX_RETRIES || 0);
        let lastErr = null;
        for (let attempt = 0; attempt < maxAttempts; attempt++) {
            throwIfAborted(ctx.signal);
            try {
                return await adapter.capture(payload, ctx);
            } catch (err) {
                lastErr = err;
                const c = classifyError(err);
                logDebug('capture attempt fail', { adapter: adapter.id, attempt: attempt, kind: c.kind });
                if (!c.retryable || attempt >= maxAttempts - 1) {
                    throw err;
                }
            }
        }
        throw lastErr || new Error('capture failed');
    }

    const queue = {
        _pending: new Map(),
        _timers: new Map(),

        enqueue(templateId, payload, meta) {
            const id = parseInt(String(templateId), 10);
            if (!id) {
                return Promise.resolve(null);
            }

            const normalized =
                global.EkoCanvasRenderer && typeof global.EkoCanvasRenderer.normalizePayload === 'function'
                    ? global.EkoCanvasRenderer.normalizePayload(payload)
                    : payload;
            normalized.visual_hash = normalized.visual_hash || Visual.visualChecksum(normalized);

            const storedHash = meta && meta.storedVisualHash ? String(meta.storedVisualHash) : '';
            if (storedHash && storedHash === normalized.visual_hash && meta.hasThumbnail) {
                logDebug('skip unchanged visual', { id: id, hash: storedHash });
                History.push({
                    templateId: id,
                    lifecycle: LC.READY || 'ready',
                    skipped: true,
                    visual_hash: storedHash,
                    source: (meta && meta.source) || 'skip',
                });
                return Promise.resolve(meta.row || { skipped: true, thumbnail_visual_hash: storedHash });
            }

            const fp = normalized.visual_hash;
            const existing = this._pending.get(id);
            if (existing && existing.fp === fp && existing.promise) {
                return existing.promise;
            }

            if (this._timers.has(id)) {
                clearTimeout(this._timers.get(id));
            }

            setSlotLifecycle(id, LC.QUEUED || 'queued', {
                source: (meta && meta.source) || '',
                visual_hash: fp,
            });

            const promise = new Promise((resolve, reject) => {
                const timer = setTimeout(() => {
                    this._timers.delete(id);
                    this._run(id, normalized, meta).then(resolve).catch(reject);
                }, CFG.DEBOUNCE_MS || 1200);
                this._timers.set(id, timer);
            });

            this._pending.set(id, { fp: fp, promise: promise, meta: meta || {} });
            return promise;
        },

        async _run(templateId, payload, meta) {
            const id = parseInt(String(templateId), 10);
            const runCtx = acquireSlot(id);
            const started = performance.now();
            const source = (meta && meta.source) || 'unknown';

            setSlotLifecycle(id, LC.GENERATING || 'generating', {
                source: source,
                runId: runCtx.runId,
                visual_hash: payload.visual_hash,
            });
            logDebug('generate start', {
                id: id,
                source: source,
                hash: payload.visual_hash,
                capture_tier: resolveThumbnailCaptureSourceForUpload(meta),
            });

            try {
                let uploadResult = null;
                let adapterUsed = '';

                try {
                    const domResult = await captureWithRetry(RasterAdapters.html2canvas, payload, {
                        templateId: id,
                        source: source,
                        signal: runCtx.signal,
                        maxWidth: meta.maxWidth != null ? meta.maxWidth : CFG.MAX_WIDTH_PX,
                        quality: meta.quality != null ? meta.quality : CFG.JPEG_QUALITY,
                        timeoutMs: CFG.GENERATION_TIMEOUT_MS,
                        fontReadyTimeoutMs:
                            typeof CFG.FONT_READY_TIMEOUT_MS === 'number' ? CFG.FONT_READY_TIMEOUT_MS : 8000,
                        liveCanvasRoot: meta.liveCanvasRoot || null,
                        pageBackgroundSolid:
                            meta.pageBackgroundSolid != null && String(meta.pageBackgroundSolid).trim() !== ''
                                ? String(meta.pageBackgroundSolid).trim()
                                : null,
                        liveDomFilter: typeof meta.liveDomFilter === 'function' ? meta.liveDomFilter : null,
                    });
                    if (!isRunCurrent(id, runCtx.runId)) {
                        throw Object.assign(new Error('thumbnail aborted'), { name: 'AbortError' });
                    }

                    const dataUrl = domResult;
                    const byteLen = dataUrl ? Math.max(0, Math.round((dataUrl.length - 22) * 0.75)) : 0;
                    if (byteLen < (CFG.MIN_FILE_BYTES || 512)) {
                        throw new Error('thumbnail empty blob');
                    }
                    if (byteLen > CFG.MAX_FILE_BYTES) {
                        throw new Error('thumbnail exceeds max file size');
                    }

                    adapterUsed = RasterAdapters.html2canvas.id;
                    const thumbCaptureSource = resolveThumbnailCaptureSourceForUpload(meta);
                    logDebug('thumbnail upload', {
                        id: id,
                        pipeline_source: source,
                        thumbnail_capture_source: thumbCaptureSource,
                        visual_hash: payload.visual_hash,
                    });
                    uploadResult = await global.ekoSampaApi('templates/' + id + '/thumbnail', {
                        method: 'POST',
                        body: {
                            image: dataUrl,
                            source: source,
                            thumbnail_capture_source: thumbCaptureSource,
                            visual_hash: payload.visual_hash,
                            duration_ms: Math.round(performance.now() - started),
                        },
                        signal: runCtx.signal,
                    });
                } catch (clientErr) {
                    const cc = classifyError(clientErr);
                    if (cc.kind === 'aborted' || !isRunCurrent(id, runCtx.runId)) {
                        throw clientErr;
                    }
                    logDebug('client capture failed, server fallback', { id: id, err: String(clientErr) });
                    const serverResult = await captureWithRetry(RasterAdapters.server, payload, {
                        templateId: id,
                        source: source + '_server',
                        signal: runCtx.signal,
                    });
                    adapterUsed = RasterAdapters.server.id;
                    uploadResult = serverResult.response;
                }

                if (!isRunCurrent(id, runCtx.runId)) {
                    throw Object.assign(new Error('thumbnail aborted'), { name: 'AbortError' });
                }

                const elapsed = Math.round(performance.now() - started);
                History.push({
                    templateId: id,
                    lifecycle: LC.READY || 'ready',
                    ms: elapsed,
                    adapter: adapterUsed,
                    visual_hash: payload.visual_hash,
                    source: source,
                    thumbnail_capture_source: resolveThumbnailCaptureSourceForUpload(meta),
                });
                setSlotLifecycle(id, LC.READY || 'ready', { ms: elapsed });

                try {
                    document.dispatchEvent(
                        new CustomEvent('eko-sampa:thumbnail-ready', {
                            detail: { templateId: id, response: uploadResult },
                        })
                    );
                } catch (e2) {
                    void e2;
                }

                this._pending.delete(id);
                return uploadResult;
            } catch (err) {
                const cc = classifyError(err);
                if (cc.kind !== 'aborted') {
                    setSlotLifecycle(id, LC.FAILED || 'failed', { error: String(err) });
                }
                History.push({
                    templateId: id,
                    lifecycle: cc.kind === 'aborted' ? LC.ABORTED || 'aborted' : LC.FAILED || 'failed',
                    error: String(err),
                    source: source,
                });
                this._pending.delete(id);
                throw err;
            } finally {
                releaseSlot(id, runCtx.runId);
            }
        },
    };

    async function captureAndUpload(templateId, payload, options) {
        const meta = options && typeof options === 'object' ? options : {};
        if (!meta.source) {
            meta.source = 'direct';
        }
        return queue.enqueue(templateId, payload, meta);
    }

    global.EkoThumbnailExport = {
        config: CFG,
        Lifecycle: LC,
        RasterAdapters: RasterAdapters,
        queue: queue,
        payloadFromTemplateRow: Visual.payloadFromTemplateRow,
        visualChecksum: Visual.visualChecksum,
        capturePayloadToJpeg: capturePayloadToJpegDom,
        captureAndUpload: captureAndUpload,
        captureLiveEditorCanvasToJpeg: captureLiveEditorCanvasToJpeg,
        liveEditorThumbnailDomFilter: liveEditorThumbnailDomFilter,
        buildPresentationEditorCanvasClone: buildPresentationEditorCanvasClone,
        rasterDomToJpegDataUrl: rasterDomToJpegDataUrl,
        classifyError: classifyError,
        getSlot: getSlot,
    };
})(typeof window !== 'undefined' ? window : global);
