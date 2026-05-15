/**
 * Thumbnail queue + capture via EkoCanvasRenderer (clean THUMBNAIL target) + html-to-image.
 */
(function (global) {
    'use strict';

    const CFG = global.EkoThumbnailConfig || {
        MAX_WIDTH_PX: 520,
        JPEG_QUALITY: 0.85,
        MAX_FILE_BYTES: 512000,
        GENERATION_TIMEOUT_MS: 20000,
        DEBOUNCE_MS: 1200,
        MAX_RETRIES: 1,
    };

    const log = {
        debug() {
            if (!global.EKO_RENDER_DEBUG && !global.EkoCanvasRenderer?.isRenderDebug?.()) {
                return;
            }
            // eslint-disable-next-line no-console
            console.log.apply(console, ['[EkoThumbnail]'].concat(Array.prototype.slice.call(arguments)));
        },
    };

    function waitFrame() {
        return new Promise((resolve) => requestAnimationFrame(() => resolve()));
    }

    function getHtmlToImageLib() {
        if (global.htmlToImage && typeof global.htmlToImage.toJpeg === 'function') {
            return global.htmlToImage;
        }
        if (global.htmlToImage && global.htmlToImage.default && typeof global.htmlToImage.default.toJpeg === 'function') {
            return global.htmlToImage.default;
        }
        return null;
    }

    function payloadFromTemplateRow(row) {
        const r = row && typeof row === 'object' ? row : {};
        let jd = r.json_data;
        if (typeof jd === 'string') {
            try {
                jd = JSON.parse(jd);
            } catch (e) {
                jd = {};
            }
        }
        if (!jd || typeof jd !== 'object') {
            jd = {};
        }
        const elements = Array.isArray(jd.elements) ? jd.elements : [];
        return {
            width_mm: r.width_mm != null ? Number(r.width_mm) : 210,
            height_mm: r.height_mm != null ? Number(r.height_mm) : 297,
            elements: elements,
        };
    }

    function fingerprintPayload(payload) {
        try {
            return JSON.stringify(payload);
        } catch (e) {
            return String(Date.now());
        }
    }

    async function captureElementToJpeg(rootEl, options) {
        const opts = options || {};
        const quality = opts.quality != null ? opts.quality : CFG.JPEG_QUALITY;
        const pixelRatio = 1;
        const node = rootEl.querySelector('.eko-sampa-thumbnail-root') || rootEl;
        const lib = getHtmlToImageLib();
        if (!lib) {
            throw new Error('html-to-image library not loaded');
        }

        const w = Math.max(1, node.offsetWidth || parseInt(node.style.width, 10) || 1);
        const h = Math.max(1, node.offsetHeight || parseInt(node.style.height, 10) || 1);
        const captureOpts = {
            quality: quality,
            pixelRatio: pixelRatio,
            width: w,
            height: h,
            cacheBust: true,
            backgroundColor: '#ffffff',
            skipAutoScale: true,
        };

        try {
            return await lib.toJpeg(node, captureOpts);
        } catch (err) {
            return lib.toJpeg(node, Object.assign({}, captureOpts, { skipAutoScale: false }));
        }
    }

    async function capturePayloadToJpeg(payload, options) {
        const R = global.EkoCanvasRenderer;
        if (!R || typeof R.runRenderPipeline !== 'function') {
            throw new Error('EkoCanvasRenderer unavailable');
        }

        const opts = options || {};
        const maxWidth = opts.maxWidth || CFG.MAX_WIDTH_PX;
        const timeoutMs = opts.timeoutMs || CFG.GENERATION_TIMEOUT_MS;

        const host = document.createElement('div');
        host.setAttribute('aria-hidden', 'true');
        host.setAttribute('data-eko-thumbnail-capture', '1');
        host.style.cssText =
            'position:fixed;left:0;top:0;overflow:hidden;pointer-events:none;z-index:-1;opacity:0.01;';
        document.body.appendChild(host);

        const timeout = new Promise((_, reject) => {
            setTimeout(() => reject(new Error('thumbnail timeout')), timeoutMs);
        });

        try {
            const pipeline = R.runRenderPipeline(host, payload, {
                forPrint: true,
                forThumbnail: true,
                target: R.RenderTargets.THUMBNAIL,
                maxWidth: maxWidth,
                assetTimeoutMs: Math.min(15000, timeoutMs - 2000),
                assetRetries: CFG.MAX_RETRIES,
            });

            await Promise.race([pipeline, timeout]);
            await waitFrame();
            await waitFrame();

            const root = host.querySelector('.eko-sampa-thumbnail-root');
            if (!root) {
                throw new Error('thumbnail mount empty');
            }

            return await captureElementToJpeg(root, { quality: opts.quality });
        } finally {
            host.remove();
        }
    }

    const queue = {
        _pending: new Map(),
        _timers: new Map(),
        _inflight: null,

        enqueue(templateId, payload, meta) {
            const id = parseInt(String(templateId), 10);
            if (!id) {
                return Promise.resolve(null);
            }

            const fp = fingerprintPayload(payload);
            const existing = this._pending.get(id);
            if (existing && existing.fp === fp && existing.promise) {
                return existing.promise;
            }

            if (this._timers.has(id)) {
                clearTimeout(this._timers.get(id));
            }

            const promise = new Promise((resolve, reject) => {
                const timer = setTimeout(() => {
                    this._timers.delete(id);
                    this._run(id, payload, meta).then(resolve).catch(reject);
                }, CFG.DEBOUNCE_MS);
                this._timers.set(id, timer);
            });

            this._pending.set(id, { fp: fp, promise: promise, meta: meta || {} });
            return promise;
        },

        async _run(templateId, payload, meta) {
            const id = parseInt(String(templateId), 10);
            if (this._inflight && this._inflight.id === id) {
                return this._inflight.promise;
            }

            const started = performance.now();
            const source = (meta && meta.source) || 'unknown';
            log.debug('generate start', { id: id, source: source });

            const run = (async () => {
                const R = global.EkoCanvasRenderer;
                const normalized =
                    R && typeof R.normalizePayload === 'function' ? R.normalizePayload(payload) : payload;

                const dataUrl = await capturePayloadToJpeg(normalized, {
                    maxWidth: CFG.MAX_WIDTH_PX,
                    quality: CFG.JPEG_QUALITY,
                });

                const byteLen = dataUrl ? Math.max(0, Math.round((dataUrl.length - 22) * 0.75)) : 0;
                if (byteLen > CFG.MAX_FILE_BYTES) {
                    throw new Error('thumbnail exceeds max file size');
                }

                const res = await global.ekoSampaApi('templates/' + id + '/thumbnail', {
                    method: 'POST',
                    body: {
                        image: dataUrl,
                        source: source,
                        duration_ms: Math.round(performance.now() - started),
                    },
                });

                const elapsed = Math.round(performance.now() - started);
                log.debug('generate ok', { id: id, ms: elapsed, bytes: byteLen, source: source });

                if (global.EKO_RENDER_DEBUG || global.EkoCanvasRenderer?.isRenderDebug?.()) {
                    global.EkoThumbnailDebug = {
                        templateId: id,
                        state: 'ready',
                        version: res && res.thumbnail_version ? res.thumbnail_version : null,
                        durationMs: elapsed,
                        bytes: byteLen,
                        source: source,
                        at: new Date().toISOString(),
                    };
                }

                try {
                    document.dispatchEvent(
                        new CustomEvent('eko-sampa:thumbnail-ready', {
                            detail: { templateId: id, response: res },
                        })
                    );
                } catch (e2) {
                    void e2;
                }

                this._pending.delete(id);
                return res;
            })().catch((err) => {
                log.debug('generate fail', { id: id, err: String(err), source: source });
                if (global.EKO_RENDER_DEBUG || global.EkoCanvasRenderer?.isRenderDebug?.()) {
                    global.EkoThumbnailDebug = {
                        templateId: id,
                        state: 'failed',
                        error: String(err),
                        source: source,
                        at: new Date().toISOString(),
                    };
                }
                this._pending.delete(id);
                throw err;
            });

            this._inflight = { id: id, promise: run };
            try {
                return await run;
            } finally {
                if (this._inflight && this._inflight.id === id) {
                    this._inflight = null;
                }
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
        queue: queue,
        payloadFromTemplateRow: payloadFromTemplateRow,
        capturePayloadToJpeg: capturePayloadToJpeg,
        captureAndUpload: captureAndUpload,
    };
})(typeof window !== 'undefined' ? window : global);
