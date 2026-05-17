/**
 * Thumbnail pipeline: queue, lock, abort, visual checksum, raster adapters, history.
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
        if (msg.indexOf('asset') !== -1 || msg.indexOf('image') !== -1 && msg.indexOf('load') !== -1) {
            return { retryable: true, kind: 'asset' };
        }
        if (
            msg.indexOf('empty') !== -1 ||
            msg.indexOf('invalid') !== -1 ||
            msg.indexOf('unavailable') !== -1 ||
            msg.indexOf('mount') !== -1 ||
            msg.indexOf('exceeds') !== -1
        ) {
            return { retryable: false, kind: 'fatal' };
        }
        return { retryable: false, kind: 'unknown' };
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

    function getHtmlToImageLib() {
        if (global.htmlToImage && typeof global.htmlToImage.toJpeg === 'function') {
            return global.htmlToImage;
        }
        if (global.htmlToImage?.default && typeof global.htmlToImage.default.toJpeg === 'function') {
            return global.htmlToImage.default;
        }
        return null;
    }

    const RasterAdapters = {
        htmlToImage: {
            id: 'html-to-image',
            async capture(payload, ctx) {
                throwIfAborted(ctx.signal);
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
        const lib = getHtmlToImageLib();
        if (!lib) {
            throw new Error('html-to-image library not loaded');
        }
        const node = rootEl.querySelector('.eko-sampa-thumbnail-root') || rootEl;
        const w = Math.max(1, node.offsetWidth || parseInt(node.style.width, 10) || 1);
        const h = Math.max(1, node.offsetHeight || parseInt(node.style.height, 10) || 1);
        throwIfAborted(signal);
        const cap = typeof CFG.CAPTURE_PIXEL_RATIO_CAP === 'number' ? CFG.CAPTURE_PIXEL_RATIO_CAP : 2;
        const dpr =
            typeof global !== 'undefined' && global.devicePixelRatio
                ? Math.min(cap, Math.max(1, global.devicePixelRatio))
                : 1;
        const captureOpts = {
            quality: opts.quality != null ? opts.quality : CFG.JPEG_QUALITY,
            pixelRatio: opts.pixelRatio != null ? opts.pixelRatio : dpr,
            width: w,
            height: h,
            cacheBust: true,
            backgroundColor: '#ffffff',
            skipAutoScale: true,
        };
        try {
            return await lib.toJpeg(node, captureOpts);
        } catch (err) {
            const c = classifyError(err);
            if (!c.retryable) {
                throw err;
            }
            throwIfAborted(signal);
            return lib.toJpeg(node, Object.assign({}, captureOpts, { skipAutoScale: false }));
        }
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

            return await captureElementToJpeg(root, { quality: opts.quality }, signal);
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

            setSlotLifecycle(id, LC.QUEUED || 'queued', { source: (meta && meta.source) || '' });

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

            setSlotLifecycle(id, LC.GENERATING || 'generating', { source: source, runId: runCtx.runId });
            logDebug('generate start', { id: id, source: source, hash: payload.visual_hash });

            try {
                let uploadResult = null;
                let adapterUsed = '';

                try {
                    const domResult = await captureWithRetry(RasterAdapters.htmlToImage, payload, {
                        templateId: id,
                        source: source,
                        signal: runCtx.signal,
                        maxWidth: CFG.MAX_WIDTH_PX,
                        quality: CFG.JPEG_QUALITY,
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

                    adapterUsed = RasterAdapters.htmlToImage.id;
                    uploadResult = await global.ekoSampaApi('templates/' + id + '/thumbnail', {
                        method: 'POST',
                        body: {
                            image: dataUrl,
                            source: source,
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
        classifyError: classifyError,
        getSlot: getSlot,
    };
})(typeof window !== 'undefined' ? window : global);
