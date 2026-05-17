/**
 * Shared canvas render engine — single source of truth for editor preview, order preview, and print.
 * Requires {@see assets/js/eko-visual-render-contract.js} (`EkoVisualRenderContract.computeScene`) — no parallel mm/scale path.
 *
 * @package Eko_Sampa
 */
(function (global) {
    'use strict';

    /** Keep in sync with {@see Eko_Sampa_Render_Schema::VERSION}. */
    const RENDER_SCHEMA_VERSION = 1;

    function requireVisualContract() {
        const V = global.EkoVisualRenderContract;
        if (!V || typeof V.computeScene !== 'function') {
            throw new Error(
                '[EkoCanvasRenderer] EkoVisualRenderContract is required — load `eko-sampa-visual-render-contract` before this script.'
            );
        }
        // Some hosts/CDNs serve a stale contract without mmToCanvasPx while canvas-renderer is newer.
        // Synthesize mmToCanvasPx from MM_TO_CSS_PX so the renderer can still boot (same numeric basis).
        if (typeof V.mmToCanvasPx !== 'function') {
            const k = Number(V.MM_TO_CSS_PX);
            const pxPerMm = Number.isFinite(k) && k > 0 ? k : 96 / 25.4;
            V.mmToCanvasPx = function (widthMm, heightMm) {
                const wMm = Math.max(10, Math.min(2000, Number(widthMm) || 210));
                const hMm = Math.max(10, Math.min(2000, Number(heightMm) || 297));
                return {
                    widthMm: wMm,
                    heightMm: hMm,
                    canvasWidth: Math.max(1, Math.round(wMm * pxPerMm)),
                    canvasHeight: Math.max(1, Math.round(hMm * pxPerMm)),
                };
            };
        }
        return V;
    }

    const RenderTargets = {
        DOM: 'dom',
        PRINT: 'print',
        THUMBNAIL: 'thumbnail',
        PDF: 'pdf',
        PNG: 'png',
    };

    const RenderLifecycle = {
        INIT: 'eko-sampa:render:init',
        ASSETS_LOADING: 'eko-sampa:render:assets-loading',
        ASSETS_READY: 'eko-sampa:render:assets-ready',
        LAYOUT_READY: 'eko-sampa:render:layout-ready',
        FONTS_WAITING: 'eko-sampa:render:fonts-waiting',
        FONTS_READY: 'eko-sampa:render:fonts-ready',
        FONTS_TIMEOUT: 'eko-sampa:render:fonts-timeout',
        PAINT_READY: 'eko-sampa:render:paint-ready',
        ERROR: 'eko-sampa:render:error',
    };

    const FONT_REGISTRY = {
        system: 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
        serif: 'Georgia, "Times New Roman", Times, serif',
        mono: 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace',
    };

    const CanvasUnitSystem = {
        get MM_TO_CSS_PX() {
            return requireVisualContract().MM_TO_CSS_PX;
        },
        SURFACE_UNIT: 'mm',
        LAYOUT_UNIT: 'px',
        mmToLayoutPx(mm) {
            return Math.max(1, Math.round(Number(mm) * requireVisualContract().MM_TO_CSS_PX));
        },
        layoutPxToMm(px) {
            return Number(px) / requireVisualContract().MM_TO_CSS_PX;
        },
        surfaceSize(widthMm, heightMm) {
            return requireVisualContract().mmToCanvasPx(widthMm, heightMm);
        },
        unitsMeta() {
            return {
                surface: CanvasUnitSystem.SURFACE_UNIT,
                layout: CanvasUnitSystem.LAYOUT_UNIT,
                css_px_per_mm: requireVisualContract().MM_TO_CSS_PX,
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
                var sp = new URLSearchParams(global.location.search);
                if (
                    sp.get('eko_render_debug') === '1' ||
                    sp.get('render_debug') === '1' ||
                    sp.get('visual_debug') === '1'
                ) {
                    return true;
                }
            }
        } catch (e) {
            void e;
        }
        return false;
    }

    function waitForFonts(timeoutMs) {
        const ms = Math.max(400, Math.min(30000, timeoutMs || 8000));
        if (typeof document === 'undefined' || !document.fonts || typeof document.fonts.ready === 'undefined') {
            return Promise.resolve({ ok: true, skipped: true });
        }
        return Promise.race([
            document.fonts.ready.then(function () {
                return { ok: true, skipped: false };
            }),
            new Promise(function (resolve) {
                setTimeout(function () {
                    resolve({ ok: false, skipped: false, reason: 'font_load_timeout' });
                }, ms);
            }),
        ]);
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
        // Do not call CanvasUnitSystem.unitsMeta() inside the default object: Object.assign evaluates
        // the full default before merging `body`, so a missing VRC would throw even when `body.units` exists (e.g. print page).
        const normalized = Object.assign(
            {
                schema_version: RENDER_SCHEMA_VERSION,
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
        const st =
            el.styles && typeof el.styles === 'object' && !Array.isArray(el.styles) ? el.styles : {};
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

    /**
     * Solid CSS background for the template page surface (print + thumbnail).
     * JPEG thumbnails cannot preserve transparency; map transparent → #ffffff.
     *
     * @param {object} payload normalized preview payload
     * @returns {string}
     */
    function canvasPageBackgroundSolid(payload) {
        const p = payload && typeof payload === 'object' ? payload : {};
        const raw = p.background_color != null ? p.background_color : '#ffffff';
        const c = safeCssColor(raw, '#ffffff');
        return c === 'transparent' ? '#ffffff' : c;
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
            paddingTop: 4,
            paddingRight: 6,
            paddingBottom: 4,
            paddingLeft: 6,
            alignVertical: 'top',
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
            paddingTop: 0,
            paddingRight: 0,
            paddingBottom: 0,
            paddingLeft: 0,
        };
    }

    function defaultRectangleStyles() {
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
    }

    /**
     * Frame-only padding (never on the canvas host). Defaults match legacy text inset (4px 6px).
     *
     * @param {object} st
     * @param {string} type
     */
    function framePaddingCss(st, type) {
        const t = String(type || '');
        const d =
            t === 'text' || t === 'placeholder'
                ? { top: 4, right: 6, bottom: 4, left: 6 }
                : { top: 0, right: 0, bottom: 0, left: 0 };
        const pt = clampNum(st.paddingTop, 0, 120, d.top);
        const pr = clampNum(st.paddingRight, 0, 120, d.right);
        const pb = clampNum(st.paddingBottom, 0, 120, d.bottom);
        const pl = clampNum(st.paddingLeft, 0, 120, d.left);
        return `padding:${pt}px ${pr}px ${pb}px ${pl}px`;
    }

    /**
     * Inner column wrapper for text: vertical distribution of the text block (top / center / bottom).
     *
     * @param {object} item
     */
    function textVerticalWrapCss(item) {
        const rawSt = item && item.styles;
        const st = rawSt && typeof rawSt === 'object' && !Array.isArray(rawSt) ? rawSt : {};
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
    }

    /**
     * Stacking order for sibling canvas elements. Must stay aligned with PHP
     * {@see Eko_Sampa_Template_Renderer::render_element}.
     * Rule: z = STACK_Z_BASE + stackIndex (index 0 = back, last = front). Not persisted in json_data.
     */
    const STACK_Z_BASE = 10;
    /** Kept for callers/tests; value 1 so z = BASE + index. */
    const STACK_Z_STRIDE = 1;

    function stackZFromIndex(stackIndex) {
        const i = Math.max(0, Math.floor(Number(stackIndex) || 0));
        return STACK_Z_BASE + i * STACK_Z_STRIDE;
    }

    function canvasSurfaceStyle(widthPx, heightPx, options) {
        const w = Math.max(1, Math.round(Number(widthPx) || 1));
        const h = Math.max(1, Math.round(Number(heightPx) || 1));
        const showGrid = options && options.showGrid;
        const pageBg =
            options && options.pageBackground != null && String(options.pageBackground).trim() !== ''
                ? String(options.pageBackground)
                : '#ffffff';
        let s =
            `width:${w}px;height:${h}px;position:relative;box-sizing:border-box;background:${pageBg};overflow:hidden;`;
        if (showGrid) {
            const gs = clampNum(options.gridSize, 1, 80, 5);
            s +=
                'background-image:linear-gradient(to right, rgb(226 232 240) 1px, transparent 1px),' +
                'linear-gradient(to bottom, rgb(226 232 240) 1px, transparent 1px);' +
                `background-size:${gs}px ${gs}px;`;
        }
        return s;
    }

    function elementPositionStyle(item, stackIndex) {
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
        let out = `position:absolute;left:${left}px;top:${top}px;width:${width}px;height:${height}px;box-sizing:border-box;`;
        if (stackIndex !== undefined && stackIndex !== null && Number.isFinite(Number(stackIndex))) {
            out += `z-index:${stackZFromIndex(stackIndex)};`;
        }
        return out;
    }

    /**
     * Inner frame: border, opacity, shadow, and (by default) rotation for every element type.
     *
     * @param {object} item
     * @param {{omitRotate?: boolean}} [options] When `omitRotate` is true, skip `transform` so the
     *   visual editor can apply rotation on `.eko-sampa-editor__rotate-wrap` (Interact stays on an
     *   axis-aligned host without fighting `transform`).
     */
    function elementFrameCss(item, options) {
        const opts = options && typeof options === 'object' ? options : {};
        const omitRotate = !!opts.omitRotate;
        const t = item && item.type;
        const rawSt = item && item.styles;
        const st = rawSt && typeof rawSt === 'object' && !Array.isArray(rawSt) ? rawSt : {};
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
        const overflowMode = 'hidden';
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
            '-webkit-print-color-adjust:exact',
            'print-color-adjust:exact',
        ];
        if (!omitRotate) {
            parts.splice(9, 0, `transform:rotate(${rot}deg)`, 'transform-origin:center center');
        }
        if (t === 'rectangle') {
            parts.push('background:#f1f5f9');
        }
        if (t === 'text' || t === 'placeholder') {
            const bg = safeCssColor(st.backgroundColor, 'transparent');
            parts.push(`background-color:${bg}`);
            parts.push('display:flex');
            parts.push('flex-direction:column');
            parts.push('min-height:0');
        }
        if (t === 'image') {
            parts.push('background-color:transparent');
        }
        if (t === 'text' || t === 'placeholder' || t === 'image' || t === 'rectangle') {
            parts.push(framePaddingCss(st, t));
        }
        return parts.join(';');
    }

    function textContentCss(item, options) {
        const t = item && item.type;
        if (t !== 'text' && t !== 'placeholder') {
            return 'display:none !important';
        }
        const rawSt = item && item.styles;
        const st = rawSt && typeof rawSt === 'object' && !Array.isArray(rawSt) ? rawSt : {};
        const d = defaultTextStyles();
        const forPrint = options && options.forPrint;
        const ff = resolveFontFamily(st.fontFamily || d.fontFamily);
        const fs = Math.round(clampNum(st.fontSize, 6, 200, d.fontSize));
        const fw = String(st.fontWeight || d.fontWeight);
        const fst = String(st.fontStyle || d.fontStyle);
        const td = String(st.textDecoration || d.textDecoration);
        const ta = String(st.textAlign || d.textAlign);
        const col = safeCssColor(st.color, d.color);
        const lh = Number(st.lineHeight);
        const lineH = Number.isFinite(lh) && lh > 0 && lh <= 4 ? String(lh) : String(d.lineHeight);
        const ls = clampNum(st.letterSpacing, -20, 40, 0);
        const tt = String(st.textTransform || d.textTransform);
        const overflow = forPrint ? 'hidden' : 'auto';
        return [
            'flex:0 1 auto',
            'max-height:100%',
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
            '-webkit-print-color-adjust:exact',
            'print-color-adjust:exact',
        ].join(';');
    }

    function imageImgCss(item) {
        const rawSt = item && item.styles;
        const st = rawSt && typeof rawSt === 'object' && !Array.isArray(rawSt) ? rawSt : {};
        const fit = String(st.objectFit || 'cover').toLowerCase();
        const f = ['contain', 'cover', 'fill', 'none', 'scale-down'].includes(fit) ? fit : 'cover';
        const op = String(st.objectPosition || 'center center').trim() || 'center center';
        return [
            'width:100%',
            'height:100%',
            'max-width:100%',
            'max-height:100%',
            'display:block',
            `object-fit:${f}`,
            `object-position:${op}`,
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

    function buildElementHtml(item, options, stackIndex) {
        if (!item || typeof item !== 'object') {
            return '';
        }
        const type = String(item.type || 'text');
        const pos = elementPositionStyle(item, stackIndex);
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
        const wrap = textVerticalWrapCss(item);
        return (
            `<div class="eko-sampa-canvas__element" style="${pos}">` +
            `<div class="eko-sampa-canvas__frame" style="${frame}">` +
            `<div class="eko-sampa-canvas__text-wrap" style="${wrap}">` +
            `<span class="eko-sampa-canvas__text" style="display:block;${inner}">${text}</span>` +
            `</div></div></div>`
        );
    }

    function buildCanvasInnerHtml(elements, widthPx, heightPx, options) {
        const showGrid = options && options.showGrid;
        const forPrint = options && options.forPrint;
        const pageBg = options && options.pageBackground;
        const surface = canvasSurfaceStyle(widthPx, heightPx, {
            showGrid: showGrid,
            gridSize: options && options.gridSize,
            pageBackground: pageBg,
        });
        const list = Array.isArray(elements) ? elements : [];
        let inner = '';
        list.forEach((el, i) => {
            inner += buildElementHtml(
                el,
                {
                    forPrint: forPrint,
                    forThumbnail: options && options.forThumbnail,
                },
                i
            );
        });
        return `<div class="eko-sampa-canvas" style="${surface}">${inner}</div>`;
    }

    function buildPrintRootHtml(preview, options) {
        const payload = normalizePayload(preview);
        const scene = requireVisualContract().computeScene(payload, 'print', options || {});
        const dims = {
            widthMm: scene.widthMm,
            heightMm: scene.heightMm,
            canvasWidth: scene.canvasWidth,
            canvasHeight: scene.canvasHeight,
        };
        const elements = scene.elementsForRender;
        const forPrint = !options || options.forPrint !== false;
        const pageBg = canvasPageBackgroundSolid(payload);
        const canvasHtml = buildCanvasInnerHtml(elements, dims.canvasWidth, dims.canvasHeight, {
            showGrid: options && options.showGrid,
            forPrint: forPrint,
            forThumbnail: options && options.forThumbnail,
            gridSize: options && options.gridSize,
            pageBackground: pageBg,
        });
        const printAdjust =
            '-webkit-print-color-adjust:exact;print-color-adjust:exact;color-adjust:exact;';
        return {
            widthMm: dims.widthMm,
            heightMm: dims.heightMm,
            canvasWidth: dims.canvasWidth,
            canvasHeight: dims.canvasHeight,
            renderLayoutMeta: scene.meta || null,
            html:
                `<div class="eko-sampa-print-root" style="position:relative;width:${dims.widthMm}mm;height:${dims.heightMm}mm;${printAdjust}overflow:hidden;box-sizing:border-box;background:${pageBg};">` +
                canvasHtml +
                '</div>',
        };
    }

    function buildThumbnailRootHtml(preview, options) {
        const opts = options || {};
        const payload = normalizePayload(preview);
        const scene = requireVisualContract().computeScene(payload, 'thumbnail', opts);
        const dims = {
            widthMm: scene.widthMm,
            heightMm: scene.heightMm,
            canvasWidth: scene.designCanvasWidth,
            canvasHeight: scene.designCanvasHeight,
        };
        const outW = scene.canvasWidth;
        const outH = scene.canvasHeight;
        const elements = scene.elementsForRender;
        const pageBg = canvasPageBackgroundSolid(payload);
        const canvasHtml = buildCanvasInnerHtml(elements, outW, outH, {
            showGrid: false,
            forPrint: true,
            forThumbnail: true,
            pageBackground: pageBg,
        });
        const printAdjust =
            '-webkit-print-color-adjust:exact;print-color-adjust:exact;color-adjust:exact;';
        return {
            widthMm: dims.widthMm,
            heightMm: dims.heightMm,
            canvasWidth: outW,
            canvasHeight: outH,
            designCanvasWidth: scene.designCanvasWidth,
            designCanvasHeight: scene.designCanvasHeight,
            renderLayoutMeta: scene.meta || null,
            html:
                `<div class="eko-sampa-thumbnail-root" data-eko-render-target="thumbnail" style="width:${outW}px;height:${outH}px;position:relative;overflow:hidden;box-sizing:border-box;background:${pageBg};${printAdjust}">` +
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
                            .catch(() => finish('decode_failed'));
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

    function measureLayoutSurfaceDrift(container, expectedW, expectedH) {
        if (!container || !container.querySelector) {
            return null;
        }
        const canvas = container.querySelector('.eko-sampa-canvas');
        if (!canvas) {
            return { error: 'canvas_dom_missing' };
        }
        const r = canvas.getBoundingClientRect();
        const dw = Math.abs(Math.round(r.width) - Math.round(expectedW));
        const dh = Math.abs(Math.round(r.height) - Math.round(expectedH));
        return {
            surface_rect_css_px: { w: r.width, h: r.height },
            expected_px: { w: expectedW, h: expectedH },
            delta_rounded_px: { w: dw, h: dh },
        };
    }

    function snapshotUnicodeAudit(payloadNormalized) {
        try {
            const V = requireVisualContract();
            if (V && typeof V.detect_unicode_render_issues === 'function') {
                return V.detect_unicode_render_issues(payloadNormalized);
            }
        } catch (e) {
            return { issues: [], issues_count: 0, error: String(e) };
        }
        return { issues: [], issues_count: 0 };
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
        let built;
        try {
            built = isThumbnail ? buildThumbnailRootHtml(payload, opts) : buildPrintRootHtml(payload, opts);
            container.innerHTML = built.html;
            dispatchRenderEvent(RenderLifecycle.LAYOUT_READY, {
                runId: runId,
                widthMm: built.widthMm,
                heightMm: built.heightMm,
                canvasWidth: built.canvasWidth,
                canvasHeight: built.canvasHeight,
                renderLayoutMeta: built.renderLayoutMeta || null,
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
            .then(function (report) {
                if (!isThumbnail) {
                    return { report: report, fonts: { ok: true, skipped: true } };
                }
                dispatchRenderEvent(RenderLifecycle.FONTS_WAITING, { runId: runId });
                const fontMs = opts.fontReadyTimeoutMs != null ? opts.fontReadyTimeoutMs : 8000;
                return waitForFonts(fontMs).then(function (fonts) {
                    return { report: report, fonts: fonts };
                });
            })
            .then(function (bundle) {
                const report = bundle.report;
                const fonts = bundle.fonts || { ok: true, skipped: true };
                if (isThumbnail) {
                    if (fonts.skipped) {
                        /* no document.fonts */
                    } else if (fonts.ok) {
                        dispatchRenderEvent(RenderLifecycle.FONTS_READY, { runId: runId });
                    } else {
                        dispatchRenderEvent(RenderLifecycle.FONTS_TIMEOUT, { runId: runId, fonts: fonts });
                        if (isRenderDebug()) {
                            // eslint-disable-next-line no-console
                            console.warn('[EkoCanvasRenderer] font_load_timeout', fonts);
                        }
                    }
                }
                const diagnosis = {
                    runId: runId,
                    target: opts.target,
                    layout: {
                        widthMm: built.widthMm,
                        heightMm: built.heightMm,
                        canvasWidth: built.canvasWidth,
                        canvasHeight: built.canvasHeight,
                    },
                    contract: built.renderLayoutMeta || null,
                    fonts: fonts,
                    assets: report,
                    visual_debug: isRenderDebug(),
                    pixel_ratio:
                        typeof window !== 'undefined' && window.devicePixelRatio ? window.devicePixelRatio : 1,
                    unicode_audit: snapshotUnicodeAudit(payload),
                };
                if (isThumbnail) {
                    diagnosis.canonical_design_px = {
                        w: built.designCanvasWidth,
                        h: built.designCanvasHeight,
                    };
                    diagnosis.layout_surface_drift = measureLayoutSurfaceDrift(
                        container,
                        built.canvasWidth,
                        built.canvasHeight
                    );
                }
                if (isRenderDebug() && container) {
                    container._ekoRenderDiagnosis = diagnosis;
                }
                dispatchRenderEvent(RenderLifecycle.ASSETS_READY, {
                    runId: runId,
                    report: report,
                    fonts: fonts,
                    diagnosis: isRenderDebug() ? diagnosis : undefined,
                });
                if (!isThumbnail) {
                    applyDebugOverlay(container);
                }
                dispatchRenderEvent(RenderLifecycle.PAINT_READY, {
                    runId: runId,
                    report: report,
                    fonts: fonts,
                    diagnosis: isRenderDebug() ? diagnosis : undefined,
                });
                try {
                    document.dispatchEvent(new CustomEvent('eko-sampa-print-ready', { detail: { runId: runId } }));
                } catch (e2) {
                    void e2;
                }
                return report;
            })
            .catch(function (err) {
                dispatchRenderEvent(RenderLifecycle.ERROR, { runId: runId, reason: 'assets', error: String(err) });
                throw err;
            });
    }

    function mountInto(container, preview, options) {
        return runRenderPipeline(container, preview, Object.assign({ forPrint: true, target: RenderTargets.PRINT }, options || {}));
    }

    const _ekoVisualContract = requireVisualContract();

    const api = {
        RENDER_SCHEMA_VERSION: RENDER_SCHEMA_VERSION,
        STACK_Z_BASE: STACK_Z_BASE,
        STACK_Z_STRIDE: STACK_Z_STRIDE,
        stackZFromIndex: stackZFromIndex,
        RenderTargets: RenderTargets,
        RenderLifecycle: RenderLifecycle,
        CanvasUnitSystem: CanvasUnitSystem,
        FONT_REGISTRY: FONT_REGISTRY,
        MM_TO_CSS_PX: _ekoVisualContract.MM_TO_CSS_PX,
        mmToCanvasPx: _ekoVisualContract.mmToCanvasPx,
        normalizePayload: normalizePayload,
        splitElementLayers: splitElementLayers,
        resolveFontFamily: resolveFontFamily,
        isRenderDebug: isRenderDebug,
        canvasSurfaceStyle: canvasSurfaceStyle,
        elementPositionStyle: elementPositionStyle,
        elementFrameCss: elementFrameCss,
        textContentCss: textContentCss,
        textVerticalWrapCss: textVerticalWrapCss,
        framePaddingCss: framePaddingCss,
        imageImgCss: imageImgCss,
        replaceTokensInText: replaceTokensInText,
        applyContextToElements: applyContextToElements,
        buildElementHtml: buildElementHtml,
        buildCanvasInnerHtml: buildCanvasInnerHtml,
        buildPrintRootHtml: buildPrintRootHtml,
        buildThumbnailRootHtml: buildThumbnailRootHtml,
        THUMBNAIL_MAX_WIDTH_PX: _ekoVisualContract.THUMBNAIL_MAX_WIDTH_PX,
        mountInto: mountInto,
        mountToTarget: mountToTarget,
        runRenderPipeline: runRenderPipeline,
        preloadAssets: preloadAssets,
        preloadImages: preloadImages,
        clampNum: clampNum,
        getVisualRenderContract: function () {
            return global.EkoVisualRenderContract || null;
        },
        safeCssColor: safeCssColor,
        canvasPageBackgroundSolid: canvasPageBackgroundSolid,
        safeBoxShadow: safeBoxShadow,
        safeFontFamily: safeFontFamily,
        defaultTextStyles: defaultTextStyles,
        defaultImageStyles: defaultImageStyles,
        defaultRectangleStyles: defaultRectangleStyles,
    };

    global.EkoCanvasRenderer = api;
})(typeof window !== 'undefined' ? window : global);
