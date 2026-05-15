/**
 * Thumbnail contract — sync with Eko_Sampa_Template_Thumbnail_Config (PHP).
 */
(function (global) {
    'use strict';

    const ThumbnailConfig = {
        MAX_WIDTH_PX: 520,
        JPEG_QUALITY: 0.85,
        MAX_FILE_BYTES: 512000,
        GENERATION_TIMEOUT_MS: 20000,
        DEBOUNCE_MS: 1200,
        MAX_RETRIES: 1,
    };

    const ThumbnailState = {
        MISSING: 'missing',
        GENERATING: 'generating',
        READY: 'ready',
        FAILED: 'failed',
        STALE: 'stale',
    };

    global.EkoThumbnailConfig = ThumbnailConfig;
    global.EkoThumbnailState = ThumbnailState;
})(typeof window !== 'undefined' ? window : global);
