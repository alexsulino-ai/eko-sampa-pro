/**
 * Visual Render Contract — single layout source for print DOM, thumbnail export, and shared metrics.
 *
 * Loaded before {@see eko-canvas-renderer.js}. When absent, the renderer keeps inline fallbacks.
 *
 * @package Eko_Sampa
 */
(function (global) {
    'use strict';

    var SCHEMA_VERSION = 1;
    var MM_TO_CSS_PX = 96 / 25.4;
    var THUMBNAIL_MAX_WIDTH_PX = 520;

    function clampNum(n, lo, hi, fallback) {
        var x = Number(n);
        if (!Number.isFinite(x)) {
            return fallback;
        }
        return Math.max(lo, Math.min(hi, x));
    }

    function mmToCanvasPx(widthMm, heightMm) {
        var wMm = clampNum(widthMm, 10, 2000, 210);
        var hMm = clampNum(heightMm, 10, 2000, 297);
        return {
            widthMm: wMm,
            heightMm: hMm,
            canvasWidth: Math.max(1, Math.round(wMm * MM_TO_CSS_PX)),
            canvasHeight: Math.max(1, Math.round(hMm * MM_TO_CSS_PX)),
        };
    }

    function scaleElementsForThumbnail(elements, scale) {
        var s = Number(scale);
        if (!Number.isFinite(s) || s <= 0 || s >= 0.999) {
            return Array.isArray(elements) ? elements : [];
        }
        return (Array.isArray(elements) ? elements : []).map(function (el) {
            if (!el || typeof el !== 'object') {
                return el;
            }
            var copy;
            try {
                copy = JSON.parse(JSON.stringify(el));
            } catch (e2) {
                return el;
            }
            ['x', 'y', 'width', 'height'].forEach(function (key) {
                var n = Number(copy[key]);
                if (Number.isFinite(n)) {
                    copy[key] = Math.round(n * s * 100) / 100;
                }
            });
            if (copy.styles && typeof copy.styles === 'object' && !Array.isArray(copy.styles)) {
                var fs = Number(copy.styles.fontSize);
                if (Number.isFinite(fs)) {
                    copy.styles.fontSize = Math.max(6, Math.round(fs * s));
                }
                var ls = Number(copy.styles.letterSpacing);
                if (Number.isFinite(ls)) {
                    copy.styles.letterSpacing = Math.round(ls * s * 10) / 10;
                }
                var br = Number(copy.styles.borderRadius);
                if (Number.isFinite(br)) {
                    copy.styles.borderRadius = Math.max(0, Math.round(br * s));
                }
                var bw = Number(copy.styles.borderWidth);
                if (Number.isFinite(bw)) {
                    copy.styles.borderWidth = Math.max(0, Math.round(bw * s));
                }
                ['paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft'].forEach(function (pk) {
                    var pv = Number(copy.styles[pk]);
                    if (Number.isFinite(pv)) {
                        copy.styles[pk] = Math.max(0, Math.round(pv * s));
                    }
                });
            }
            return copy;
        });
    }

    /**
     * Inspect text/placeholder content for common UTF-8 / surrogate issues (storage + canvas pipeline).
     *
     * @param {object} payloadNormalized
     * @returns {{issues: Array<object>, issues_count: number}}
     */
    function detectUnicodeRenderIssues(payloadNormalized) {
        var issues = [];
        var els = Array.isArray(payloadNormalized.elements) ? payloadNormalized.elements : [];
        els.forEach(function (el, idx) {
            if (!el || typeof el !== 'object') {
                return;
            }
            var t = String(el.type || '');
            if (t !== 'text' && t !== 'placeholder') {
                return;
            }
            var c = el.content != null ? String(el.content) : '';
            if (/[\uD800-\uDFFF]/.test(c)) {
                issues.push({ index: idx, id: el.id, code: 'lone_surrogate_in_content' });
            }
            if (/\uFFFD/.test(c)) {
                issues.push({ index: idx, id: el.id, code: 'replacement_char_in_content' });
            }
            for (var i = 0; i < c.length; i++) {
                var code = c.charCodeAt(i);
                if (code === 0) {
                    issues.push({ index: idx, id: el.id, code: 'nul_byte_in_text' });
                    break;
                }
            }
        });
        return { issues: issues, issues_count: issues.length };
    }

    /**
     * @param {object} payloadNormalized output of EkoCanvasRenderer.normalizePayload
     * @param {'print'|'thumbnail'} target
     * @param {object} [opts]
     */
    function computeScene(payloadNormalized, target, opts) {
        var o = opts || {};
        var widthMm = clampNum(payloadNormalized.width_mm, 1, 2000, 210);
        var heightMm = clampNum(payloadNormalized.height_mm, 1, 2000, 297);
        var dims = mmToCanvasPx(widthMm, heightMm);
        var elements = Array.isArray(payloadNormalized.elements) ? payloadNormalized.elements : [];

        if (target === 'thumbnail') {
            var maxW = clampNum(o.maxWidth, 120, 1200, THUMBNAIL_MAX_WIDTH_PX);
            var scale = Math.min(1, maxW / dims.canvasWidth);
            var outW = Math.max(1, Math.round(dims.canvasWidth * scale));
            var outH = Math.max(1, Math.round(dims.canvasHeight * scale));
            var scaled = scaleElementsForThumbnail(elements, scale);
            return {
                widthMm: dims.widthMm,
                heightMm: dims.heightMm,
                designCanvasWidth: dims.canvasWidth,
                designCanvasHeight: dims.canvasHeight,
                canvasWidth: outW,
                canvasHeight: outH,
                scale: scale,
                elementsForRender: scaled,
                meta: {
                    schema_version: SCHEMA_VERSION,
                    target: target,
                    mmToCssPx: MM_TO_CSS_PX,
                    maxWidthPx: maxW,
                    scale: scale,
                },
            };
        }

        return {
            widthMm: dims.widthMm,
            heightMm: dims.heightMm,
            designCanvasWidth: dims.canvasWidth,
            designCanvasHeight: dims.canvasHeight,
            canvasWidth: dims.canvasWidth,
            canvasHeight: dims.canvasHeight,
            scale: 1,
            elementsForRender: elements,
            meta: {
                schema_version: SCHEMA_VERSION,
                target: 'print',
                mmToCssPx: MM_TO_CSS_PX,
                scale: 1,
            },
        };
    }

    global.EkoVisualRenderContract = {
        SCHEMA_VERSION: SCHEMA_VERSION,
        MM_TO_CSS_PX: MM_TO_CSS_PX,
        THUMBNAIL_MAX_WIDTH_PX: THUMBNAIL_MAX_WIDTH_PX,
        clampNum: clampNum,
        mmToCanvasPx: mmToCanvasPx,
        scaleElementsForThumbnail: scaleElementsForThumbnail,
        computeScene: computeScene,
        detect_unicode_render_issues: detectUnicodeRenderIssues,
    };
})(typeof window !== 'undefined' ? window : global);
