/**
 * Thumbnail pipeline contract — sync with Eko_Sampa_Template_Thumbnail_Config (PHP).
 */
(function (global) {
    'use strict';

    const ThumbnailConfig = {
        MAX_WIDTH_PX: 520,
        JPEG_QUALITY: 0.85,
        MAX_FILE_BYTES: 512000,
        MIN_FILE_BYTES: 512,
        MIN_WIDTH_PX: 8,
        MIN_HEIGHT_PX: 8,
        GENERATION_TIMEOUT_MS: 20000,
        DEBOUNCE_MS: 1200,
        MAX_RETRIES: 1,
        HISTORY_MAX_ENTRIES: 20,
        /** Wait for {@link document.fonts.ready} before rasterizing thumbnails (ms). */
        FONT_READY_TIMEOUT_MS: 8000,
        /** Retries per image asset during thumbnail preload (see {@link EkoCanvasRenderer.preloadAssets}). */
        ASSET_RETRIES: 1,
        /** Cap for devicePixelRatio when calling html-to-image (fidelity vs file size). */
        CAPTURE_PIXEL_RATIO_CAP: 2,
    };

    /** Formal client pipeline lifecycle. */
    const Lifecycle = {
        IDLE: 'idle',
        QUEUED: 'queued',
        GENERATING: 'generating',
        READY: 'ready',
        STALE: 'stale',
        FAILED: 'failed',
        ABORTED: 'aborted',
        MISSING: 'missing',
    };

    /** Persisted / API thumbnail states (subset of lifecycle). */
    const ThumbnailState = {
        MISSING: 'missing',
        GENERATING: 'generating',
        READY: 'ready',
        FAILED: 'failed',
        STALE: 'stale',
    };

    global.EkoThumbnailConfig = ThumbnailConfig;
    global.EkoThumbnailLifecycle = Lifecycle;
    global.EkoThumbnailState = ThumbnailState;
})(typeof window !== 'undefined' ? window : global);
