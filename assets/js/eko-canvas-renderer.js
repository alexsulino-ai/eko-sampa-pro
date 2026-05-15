/**
 * Shared canvas render engine — single source of truth for editor preview, order preview, and print.
 * Keep in sync with {@see Eko_Sampa_Template_Renderer} (PHP mirrors this contract).
 */
(function (global) {
    'use strict';

    const MM_TO_CSS_PX = 96 / 25.4;

    /** Keep in sync with {@see Eko_Sampa_Render_Schema::VERSION}. */
    const RENDER_SCHEMA_VERSION = 1;

    const RenderTargets = {
        DOM: 'dom',
        PRINT: 'print',
        THUMBNAIL: 'thumbnail',
        PDF: 'pdf',
        PNG: 'png',
    };

    const THUMBNAIL_MAX_WIDTH_PX = 520;

    const RenderLifecycle = {
        INIT: 'eko-sampa:render:init',
        ASSETS_LOADING: 'eko-sampa:render:assets-loading',
        ASSETS_READY: 'eko-sampa:render:assets-ready',
        LAYOUT_READY: 'eko-sampa:render:layout-ready',
        PAINT_READY: 'eko-sampa:render:paint-ready',
        ERROR: 'eko-sampa:render:error',
    };

    const FONT_REGISTRY = {
        system: 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
        serif: 'Georgia, "Times New Roman", Times, serif',
        mono: 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace',
    };

    const CanvasUnitSystem = {
        MM_TO_CSS_PX: MM_TO_CSS_PX,
        SURFACE_UNIT: 'mm',
        LAYOUT_UNIT: 'px',
        mmToLayoutPx(mm) {
            return Math.max(1, Math.round(Number(mm) * MM_TO_CSS_PX));
        },
        layoutPxToMm(px) {
            return Number(px) / MM_TO_CSS_PX;
        },
        surfaceSize(widthMm, heightMm) {
            return mmToCanvasPx(widthMm, heightMm);
        },
        unitsMeta() {
            return {
                surface: CanvasUnitSystem.SURFACE_UNIT,
                layout: CanvasUnitSystem.LAYOUT_UNIT,
                css_px_per_mm: MM_TO_CSS_PX,
            };
        },
    };

    function isRenderDebug() {
        if (typeof global === 'undefined') {
            return false;
        }
        if (global.EKO_RENDER_DEBUG === true) {
            return true;
        }
        try {
            if (typeof URLSearchParams !== 'undefined' && global.location && global.location.search) {
                return new URLSearchParams(global.location.search).get('eko_render_debug') === '1';
            }
        } catch (e) {
            void e;
        }
        return false;
    }

    function dispatchRenderEvent(name, detail) {
        if (typeof document === 'undefined' || !document.dispatchEvent) {
            return;
        }
        try {
            document.dispatchEvent(new CustomEvent(name, { detail: detail || {} }));
        } catch (e) {
            void e;
        }
    }

    function normalizePayload(raw) {
        const body = raw && typeof raw === 'object' ? raw : {};
        const version = Number(body.schema_version);
        const normalized = Object.assign(
            {
                schema_version: RENDER_SCHEMA_VERSION,
                units: CanvasUnitSystem.unitsMeta(),
            },
            body
        );
        if (!Array.isArray(normalized.elements)) {
            normalized.elements = [];
        }
        if (!normalized.units || typeof normalized.units !== 'object') {
            normalized.units = CanvasUnitSystem.unitsMeta();
        }
        if (Number.isFinite(version) && version > RENDER_SCHEMA_VERSION) {
            normalized._schemaWarning = 'newer_than_engine';
            if (isRenderDebug()) {
                // eslint-disable-next-line no-console
                console.warn('[EkoCanvasRenderer] payload schema_version', version, '> engine', RENDER_SCHEMA_VERSION);
            }
        }
        return normalized;
    }

    /**
     * Read-only lenses over stored element JSON (storage shape unchanged until migration).
     */
    function splitElementLayers(el) {
        if (!el || typeof el !== 'object') {
            return { geometry: {}, appearance: {}, typography: {}, transforms: {}, metadata: {} };
        }
        const st = el.styles && typeof el.styles === 'object' ? el.styles : {};
        return {
            geometry: {
                x: el.x,
                y: el.y,
                width: el.width,
                height: el.height,
                type: el.type,
                zIndex: el.zIndex,
            },
            appearance: {
                opacity: st.opacity,
                borderWidth: st.borderWidth,
                borderStyle: st.borderStyle,
                borderColor: st.borderColor,
                borderRadius: st.borderRadius,
                backgroundColor: st.backgroundColor,
                boxShadow: st.boxShadow,
                objectFit: st.objectFit,
            },
            typography: {
                fontFamily: st.fontFamily,
                fontSize: st.fontSize,
                fontWeight: st.fontWeight,
                fontStyle: st.fontStyle,
                textDecoration: st.textDecoration,
                textAlign: st.textAlign,
                color: st.color,
                lineHeight: st.lineHeight,
                letterSpacing: st.letterSpacing,
                textTransform: st.textTransform,
            },
            transforms: {
                rotate: st.rotate,
            },
            metadata: {
                id: el.id,
                locked: el.locked,
                groupId: el.groupId,
                layer: el.layer,
                clipPath: el.clipPath,
            },
        };
    }

    function resolveFontFamily(requested) {
        const key = String(requested || '')
            .trim()
            .toLowerCase();
        if (key && FONT_REGISTRY[key]) {
            return FONT_REGISTRY[key];
        }
        return safeFontFamily(requested || FONT_REGISTRY.system);
    }

    function clampNum(n, lo, hi, fallback) {
        const x = Number(n);
        if (!Number.isFinite(x)) {
            return fallback;
        }
        return Math.max(lo, Math.min(hi, x));
    }

    function safeCssColor(input, fallback) {
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
    }

    function safeBoxShadow(s) {
        const t = String(s == null ? '' : s).trim();
        if (t === '' || t.toLowerCase() === 'none') {
            return 'none';
        }
        if (t.length > 180 || /[<>;{}]|url\s*\(/i.test(t)) {
            return 'none';
        }
        return t;
    }

    function safeFontFamily(s) {
        const t = String(s == null ? '' : s).trim().slice(0, 220);
        if (t === '') {
            return defaultTextStyles().fontFamily;
        }
        if (/[<>{}"'`;]/.test(t)) {
            return defaultTextStyles().fontFamily;
        }
        return t;
    }

    function defaultTextStyles() {
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
    }

    function defaultImageStyles() {
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
    }

    function mmToCanvasPx(widthMm, heightMm) {
        const wMm = clampNum(widthMm, 10, 2000, 210);
        const hMm = clampNum(heightMm, 10, 2000, 297);
        return {
            widthMm: wMm,
            heightMm: hMm,
            canvasWidth: Math.max(1, Math.round(wMm * MM_TO_CSS_PX)),
            canvasHeight: Math.max(1, Math.round(hMm * MM_TO_CSS_PX)),
        };
    }

    function canvasSurfaceStyle(widthPx, heightPx, options) {
        const w = Math.max(1, Math.round(Number(widthPx) || 1));
        const h = Math.max(1, Math.round(Number(heightPx) || 1));
        const showGrid = options && options.showGrid;
        let s =
            `width:${w}px;height:${h}px;position:relative;box-sizing:border-box;background:#fff;overflow:hidden;`;
        if (showGrid) {
            const gs = clampNum(options.gridSize, 1, 80, 5);
            s +=
                'background-image:linear-gradient(to right, rgb(226 232 240) 1px, transparent 1px),' +
                'linear-gradient(to bottom, rgb(226 232 240) 1px, transparent 1px);' +
                `background-size:${gs}px ${gs}px;`;
        }
        return s;
    }

    function elementPositionStyle(item) {
        if (!item || typeof item !== 'object') {
            return 'position:absolute;left:0;top:0;width:100px;height:40px;box-sizing:border-box;';
        }
        const x = Number(item.x);
        const y = Number(item.y);
        const w = Number(item.width);
        const h = Number(item.height);
        const left = Number.isFinite(x) ? x : 0;
        const top = Number.isFinite(y) ? y : 0;
        const width = Number.isFinite(w) ? w : 10;
        const height = Number.isFinite(h) ? h : 10;
        return `position:absolute;left:${left}px;top:${top}px;width:${width}px;height:${height}px;box-sizing:border-box;`;
    }

    function elementFrameCss(item) {
        const t = item && item.type;
        const st = (item && item.styles) || {};
        const op = clampNum(st.opacity, 0, 1, 1);
        const br = Math.max(0, Number(st.borderRadius) || 0);
        const bw = Math.max(0, Number(st.borderWidth) || 0);
        const bs = String(st.borderStyle || 'solid');
        const bc = safeCssColor(st.borderColor, '#cbd5e1');
        const sh = safeBoxShadow(st.boxShadow != null ? st.boxShadow : 'none');
        const rot = clampNum(st.rotate, -360, 360, 0);
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
            '-webkit-print-color-adjust:exact',
            'print-color-adjust:exact',
        ];
        if (t === 'rectangle') {
            parts.push('background:#f1f5f9');
        }
        return parts.join(';');
    }

    function textContentCss(item, options) {
        const st = (item && item.styles) || {};
        const d = defaultTextStyles();
        const forPrint = options && options.forPrint;
        const ff = resolveFontFamily(st.fontFamily || d.fontFamily);
        const fs = Math.round(clampNum(st.fontSize, 6, 200, d.fontSize));
        const fw = String(st.fontWeight || d.fontWeight);
        const fst = String(st.fontStyle || d.fontStyle);
        const td = String(st.textDecoration || d.textDecoration);
        const ta = String(st.textAlign || d.textAlign);
        const col = safeCssColor(st.color, d.color);
        const bg = safeCssColor(st.backgroundColor, 'transparent');
        const lh = Number(st.lineHeight);
        const lineH = Number.isFinite(lh) && lh > 0 && lh <= 4 ? String(lh) : String(d.lineHeight);
        const ls = clampNum(st.letterSpacing, -20, 40, 0);
        const tt = String(st.textTransform || d.textTransform);
        const overflow = forPrint ? 'hidden' : 'auto';
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
            `overflow:${overflow}`,
            'padding:4px 6px',
            'display:block',
            '-webkit-print-color-adjust:exact',
            'print-color-adjust:exact',
        ].join(';');
    }

    function imageImgCss(item) {
        const st = (item && item.styles) || {};
        const fit = String(st.objectFit || 'cover').toLowerCase();
        const f = ['contain', 'cover', 'fill', 'none', 'scale-down'].includes(fit) ? fit : 'cover';
        return [
            'width:100%',
            'height:100%',
            'max-width:100%',
            'max-height:100%',
            'display:block',
            `object-fit:${f}`,
            '-webkit-print-color-adjust:exact',
            'print-color-adjust:exact',
        ].join(';');
    }

    function escapeHtml(text) {
        return String(text == null ? '' : text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function replaceTokensInText(raw, context) {
        let s = String(raw == null ? '' : raw);
        const ctx = context && typeof context === 'object' ? context : {};
        Object.keys(ctx).forEach((key) => {
            const k = String(key).trim();
            if (!k) {
                return;
            }
            const val = ctx[k] == null ? '' : String(ctx[k]);
            const re = new RegExp('\\{\\{\\s*' + k.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*\\}\\}', 'gi');
            s = s.replace(re, val);
        });
        return s;
    }

    function applyContextToElements(elements, context) {
        const list = Array.isArray(elements) ? elements : [];
        return list
            .filter((el) => el && typeof el === 'object')
            .map((el) => {
                let copy;
                try {
                    copy = JSON.parse(JSON.stringify(el));
                } catch (e) {
                    return null;
                }
                const type = String(copy.type || '');
                if (type === 'text' || type === 'placeholder') {
                    copy.content = replaceTokensInText(copy.content, context);
                }
                return copy;
            })
            .filter(Boolean);
    }

    function resolveImageSrc(item) {
        const raw = item && item.src != null && String(item.src) !== '' ? String(item.src) : String((item && item.content) || '');
        const s = raw.trim();
        if (s === '' || /^(blob:|data:|javascript:)/i.test(s)) {
            return '';
        }
        return s;
    }

    function isCrossOriginImageUrl(src) {
        const s = String(src || '').trim();
        if (s === '' || s.startsWith('data:') || s.startsWith('blob:')) {
            return false;
        }
        try {
            const base = typeof location !== 'undefined' && location.href ? location.href : undefined;
            const u = new URL(s, base);
            const o =
                typeof location !== 'undefined' && location.origin ? location.origin : '';
            return !!o && u.origin !== o;
        } catch (e) {
            return false;
        }
    }

    function scaleElementsForThumbnail(elements, scale) {
        const s = Number(scale);
        if (!Number.isFinite(s) || s <= 0 || s >= 0.999) {
            return Array.isArray(elements) ? elements : [];
        }
        return (Array.isArray(elements) ? elements : []).map((el) => {
            if (!el || typeof el !== 'object') {
                return el;
            }
            let copy;
            try {
                copy = JSON.parse(JSON.stringify(el));
            } catch (e2) {
                return el;
            }
            ['x', 'y', 'width', 'height'].forEach((key) => {
                const n = Number(copy[key]);
                if (Number.isFinite(n)) {
                    copy[key] = Math.round(n * s * 100) / 100;
                }
            });
            if (copy.styles && typeof copy.styles === 'object') {
                const fs = Number(copy.styles.fontSize);
                if (Number.isFinite(fs)) {
                    copy.styles.fontSize = Math.max(6, Math.round(fs * s));
                }
                const ls = Number(copy.styles.letterSpacing);
                if (Number.isFinite(ls)) {
                    copy.styles.letterSpacing = Math.round(ls * s * 10) / 10;
                }
                const br = Number(copy.styles.borderRadius);
                if (Number.isFinite(br)) {
                    copy.styles.borderRadius = Math.max(0, Math.round(br * s));
                }
                const bw = Number(copy.styles.borderWidth);
                if (Number.isFinite(bw)) {
                    copy.styles.borderWidth = Math.max(0, Math.round(bw * s));
                }
            }
            return copy;
        });
    }

    function buildElementHtml(item, options) {
        if (!item || typeof item !== 'object') {
            return '';
        }
        const type = String(item.type || 'text');
        const pos = elementPositionStyle(item);
        const frame = elementFrameCss(item);
        const forPrint = options && options.forPrint;

        if (type === 'image') {
            const src = resolveImageSrc(item);
            if (!src) {
                return `<div class="eko-sampa-canvas__element" style="${pos}"><div class="eko-sampa-canvas__frame" style="${frame};background:#e5e7eb;border:1px dashed #94a3b8;"></div></div>`;
            }
            const img = imageImgCss(item);
            return (
                `<div class="eko-sampa-canvas__element" style="${pos}">` +
                `<div class="eko-sampa-canvas__frame" style="${frame}">` +
                `<img class="eko-sampa-canvas__img" style="${img}" src="${escapeHtml(src)}" alt="" loading="eager" decoding="sync"${options && options.forThumbnail && isCrossOriginImageUrl(src) ? ' crossorigin="anonymous"' : ''} />` +
                `</div></div>`
            );
        }

        if (type === 'rectangle') {
            return `<div class="eko-sampa-canvas__element" style="${pos}"><div class="eko-sampa-canvas__frame" style="${frame}"></div></div>`;
        }

        const text = escapeHtml(item.content != null ? item.content : '');
        const inner = textContentCss(item, { forPrint: forPrint });
        return (
            `<div class="eko-sampa-canvas__element" style="${pos}">` +
            `<div class="eko-sampa-canvas__frame" style="${frame}">` +
            `<span class="eko-sampa-canvas__text" style="${inner}">${text}</span>` +
            `</div></div>`
        );
    }

    function buildCanvasInnerHtml(elements, widthPx, heightPx, options) {
        const showGrid = options && options.showGrid;
        const forPrint = options && options.forPrint;
        const surface = canvasSurfaceStyle(widthPx, heightPx, { showGrid: showGrid, gridSize: options && options.gridSize });
        const list = Array.isArray(elements) ? elements : [];
        let inner = '';
        list.forEach((el) => {
            inner += buildElementHtml(el, {
                forPrint: forPrint,
                forThumbnail: options && options.forThumbnail,
            });
        });
        return `<div class="eko-sampa-canvas" style="${surface}">${inner}</div>`;
    }

    function buildPrintRootHtml(preview, options) {
        const payload = normalizePayload(preview);
        const widthMm = clampNum(payload.width_mm, 1, 2000, 210);
        const heightMm = clampNum(payload.height_mm, 1, 2000, 297);
        const dims = mmToCanvasPx(widthMm, heightMm);
        const elements = payload.elements || [];
        const forPrint = !options || options.forPrint !== false;
        const canvasHtml = buildCanvasInnerHtml(elements, dims.canvasWidth, dims.canvasHeight, {
            showGrid: options && options.showGrid,
            forPrint: forPrint,
            forThumbnail: options && options.forThumbnail,
            gridSize: options && options.gridSize,
        });
        const printAdjust =
            '-webkit-print-color-adjust:exact;print-color-adjust:exact;color-adjust:exact;';
        return {
            widthMm: dims.widthMm,
            heightMm: dims.heightMm,
            canvasWidth: dims.canvasWidth,
            canvasHeight: dims.canvasHeight,
            html:
                `<div class="eko-sampa-print-root" style="position:relative;width:${dims.widthMm}mm;height:${dims.heightMm}mm;${printAdjust}overflow:hidden;box-sizing:border-box;background:#fff;">` +
                canvasHtml +
                '</div>',
        };
    }

    function buildThumbnailRootHtml(preview, options) {
        const opts = options || {};
        const maxW = clampNum(opts.maxWidth, 120, 1200, THUMBNAIL_MAX_WIDTH_PX);
        const payload = normalizePayload(preview);
        const widthMm = clampNum(payload.width_mm, 1, 2000, 210);
        const heightMm = clampNum(payload.height_mm, 1, 2000, 297);
        const dims = mmToCanvasPx(widthMm, heightMm);
        const scale = Math.min(1, maxW / dims.canvasWidth);
        const outW = Math.max(1, Math.round(dims.canvasWidth * scale));
        const outH = Math.max(1, Math.round(dims.canvasHeight * scale));
        const elements = scaleElementsForThumbnail(payload.elements || [], scale);
        const canvasHtml = buildCanvasInnerHtml(elements, outW, outH, {
            showGrid: false,
            forPrint: true,
            forThumbnail: true,
        });
        const printAdjust =
            '-webkit-print-color-adjust:exact;print-color-adjust:exact;color-adjust:exact;';
        return {
            widthMm: dims.widthMm,
            heightMm: dims.heightMm,
            canvasWidth: outW,
            canvasHeight: outH,
            designCanvasWidth: dims.canvasWidth,
            designCanvasHeight: dims.canvasHeight,
            html:
                `<div class="eko-sampa-thumbnail-root" data-eko-render-target="thumbnail" style="width:${outW}px;height:${outH}px;position:relative;overflow:hidden;box-sizing:border-box;background:#fff;${printAdjust}">` +
                canvasHtml +
                '</div>',
        };
    }

    function applyDebugOverlay(root) {
        if (!isRenderDebug() || !root || !root.querySelectorAll) {
            return;
        }
        root.classList.add('eko-render-debug');
        root.querySelectorAll('.eko-sampa-canvas__element').forEach((el, i) => {
            el.setAttribute('data-eko-debug-index', String(i));
            const z = el.style.zIndex || window.getComputedStyle(el).zIndex;
            if (z && z !== 'auto') {
                el.setAttribute('data-eko-debug-z', z);
            }
        });
    }

    function preloadAsset(img, opts) {
        const timeoutMs = (opts && opts.timeoutMs) || 12000;
        const retries = (opts && opts.retries) != null ? opts.retries : 1;
        const src = img.getAttribute('src') || '';

        function attempt(left) {
            return new Promise((resolve) => {
                const result = { src: src, state: 'pending', element: img };

                const finish = (state) => {
                    result.state = state;
                    img.setAttribute('data-eko-asset-state', state);
                    resolve(result);
                };

                if (!src) {
                    finish('empty');
                    return;
                }

                if (img.complete && img.naturalWidth > 0) {
                    finish('ready');
                    return;
                }

                let settled = false;
                const timer = setTimeout(() => {
                    if (settled) {
                        return;
                    }
                    settled = true;
                    if (left > 0) {
                        const retrySrc = img.src;
                        img.removeAttribute('src');
                        img.setAttribute('src', retrySrc);
                        attempt(left - 1).then(resolve);
                        return;
                    }
                    finish('timeout');
                }, timeoutMs);

                const onSuccess = () => {
                    if (settled) {
                        return;
                    }
                    settled = true;
                    clearTimeout(timer);
                    if (typeof img.decode === 'function') {
                        img.decode()
                            .then(() => finish('ready'))
                            .catch(() => finish('ready'));
                    } else {
                        finish('ready');
                    }
                };

                const onError = () => {
                    if (settled) {
                        return;
                    }
                    settled = true;
                    clearTimeout(timer);
                    img.classList.add('eko-sampa-canvas__img--failed');
                    if (left > 0) {
                        const retrySrc = img.src;
                        img.removeAttribute('src');
                        img.setAttribute('src', retrySrc);
                        attempt(left - 1).then(resolve);
                        return;
                    }
                    finish('error');
                };

                img.addEventListener('load', onSuccess, { once: true });
                img.addEventListener('error', onError, { once: true });
            });
        }

        return attempt(retries);
    }

    function preloadAssets(root, options) {
        const scope = root && root.querySelectorAll ? root : document;
        const imgs = scope.querySelectorAll('img[src]');
        if (!imgs.length) {
            return Promise.resolve({ ready: [], failed: [], timedOut: [] });
        }
        return Promise.all(Array.from(imgs).map((img) => preloadAsset(img, options))).then((results) => {
            const bucket = { ready: [], failed: [], timedOut: [] };
            results.forEach((r) => {
                if (r.state === 'ready' || r.state === 'empty') {
                    bucket.ready.push(r);
                } else if (r.state === 'timeout') {
                    bucket.timedOut.push(r);
                } else {
                    bucket.failed.push(r);
                }
            });
            return bucket;
        });
    }

    function preloadImages(root) {
        return preloadAssets(root, {}).then(() => undefined);
    }

    function mountToTarget(target, container, preview, options) {
        const opts = Object.assign({}, options || {}, { target: target || RenderTargets.PRINT });
        if (target === RenderTargets.PDF || target === RenderTargets.PNG) {
            return Promise.reject(new Error('EkoCanvasRenderer: target not implemented yet — use DOM/PRINT'));
        }
        return runRenderPipeline(container, preview, opts);
    }

    function runRenderPipeline(container, preview, options) {
        const opts = options || {};
        const payload = normalizePayload(preview);
        const runId = 'r' + Date.now().toString(36);

        dispatchRenderEvent(RenderLifecycle.INIT, { runId: runId, target: opts.target, payload: payload });

        if (!container) {
            dispatchRenderEvent(RenderLifecycle.ERROR, { runId: runId, reason: 'no_container' });
            return Promise.reject(new Error('EkoCanvasRenderer: container required'));
        }

        const isThumbnail = opts.target === RenderTargets.THUMBNAIL;
        try {
            const built = isThumbnail ? buildThumbnailRootHtml(payload, opts) : buildPrintRootHtml(payload, opts);
            container.innerHTML = built.html;
            dispatchRenderEvent(RenderLifecycle.LAYOUT_READY, {
                runId: runId,
                widthMm: built.widthMm,
                heightMm: built.heightMm,
                canvasWidth: built.canvasWidth,
                canvasHeight: built.canvasHeight,
            });
        } catch (err) {
            dispatchRenderEvent(RenderLifecycle.ERROR, { runId: runId, reason: 'layout', error: String(err) });
            return Promise.reject(err);
        }

        dispatchRenderEvent(RenderLifecycle.ASSETS_LOADING, { runId: runId });

        return preloadAssets(container, {
            timeoutMs: opts.assetTimeoutMs || 12000,
            retries: opts.assetRetries != null ? opts.assetRetries : 1,
        })
            .then((report) => {
                dispatchRenderEvent(RenderLifecycle.ASSETS_READY, { runId: runId, report: report });
                if (!isThumbnail) {
                    applyDebugOverlay(container);
                }
                dispatchRenderEvent(RenderLifecycle.PAINT_READY, { runId: runId, report: report });
                try {
                    document.dispatchEvent(new CustomEvent('eko-sampa-print-ready', { detail: { runId: runId } }));
                } catch (e2) {
                    void e2;
                }
                return report;
            })
            .catch((err) => {
                dispatchRenderEvent(RenderLifecycle.ERROR, { runId: runId, reason: 'assets', error: String(err) });
                throw err;
            });
    }

    function mountInto(container, preview, options) {
        return runRenderPipeline(container, preview, Object.assign({ forPrint: true, target: RenderTargets.PRINT }, options || {}));
    }

    const api = {
        RENDER_SCHEMA_VERSION: RENDER_SCHEMA_VERSION,
        RenderTargets: RenderTargets,
        RenderLifecycle: RenderLifecycle,
        CanvasUnitSystem: CanvasUnitSystem,
        FONT_REGISTRY: FONT_REGISTRY,
        MM_TO_CSS_PX: MM_TO_CSS_PX,
        mmToCanvasPx: mmToCanvasPx,
        normalizePayload: normalizePayload,
        splitElementLayers: splitElementLayers,
        resolveFontFamily: resolveFontFamily,
        isRenderDebug: isRenderDebug,
        canvasSurfaceStyle: canvasSurfaceStyle,
        elementPositionStyle: elementPositionStyle,
        elementFrameCss: elementFrameCss,
        textContentCss: textContentCss,
        imageImgCss: imageImgCss,
        replaceTokensInText: replaceTokensInText,
        applyContextToElements: applyContextToElements,
        buildElementHtml: buildElementHtml,
        buildCanvasInnerHtml: buildCanvasInnerHtml,
        buildPrintRootHtml: buildPrintRootHtml,
        buildThumbnailRootHtml: buildThumbnailRootHtml,
        THUMBNAIL_MAX_WIDTH_PX: THUMBNAIL_MAX_WIDTH_PX,
        mountInto: mountInto,
        mountToTarget: mountToTarget,
        runRenderPipeline: runRenderPipeline,
        preloadAssets: preloadAssets,
        preloadImages: preloadImages,
        clampNum: clampNum,
        safeCssColor: safeCssColor,
        safeBoxShadow: safeBoxShadow,
        safeFontFamily: safeFontFamily,
        defaultTextStyles: defaultTextStyles,
        defaultImageStyles: defaultImageStyles,
    };

    global.EkoCanvasRenderer = api;
})(typeof window !== 'undefined' ? window : global);
