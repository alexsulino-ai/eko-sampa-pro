<?php
/**
 * Official thumbnail export contract (keep in sync with assets/js/eko-thumbnail-config.js).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Eko_Sampa_Template_Thumbnail_Config {

    /** Max layout width in px before JPEG encode. */
    public const MAX_WIDTH_PX = 520;

    /** JPEG quality 0–1 (encoded as int percent in API). */
    public const JPEG_QUALITY = 0.85;

    /** Reject uploads larger than this (bytes). */
    public const MAX_FILE_BYTES = 512000;

    /** Minimum valid JPEG size (bytes). */
    public const MIN_FILE_BYTES = 512;

    /** Minimum decoded width/height (px). */
    public const MIN_WIDTH_PX = 8;

    public const MIN_HEIGHT_PX = 8;

    /** Client generation timeout guidance (ms). */
    public const GENERATION_TIMEOUT_MS = 20000;

    public const DEBOUNCE_MS = 1200;

    public const MAX_RETRIES = 1;

    /** Server/client generation lock TTL (seconds). */
    public const LOCK_TTL_SECONDS = 90;

    /** Ring buffer size for client debug history. */
    public const HISTORY_MAX_ENTRIES = 20;

    public const SUBDIR = 'eko-sampa/templates';

    public const FILENAME_PATTERN = '%d.jpg';

    /**
     * @return array<string, int|float|string>
     */
    public static function client_config(): array {
        return [
            'maxWidthPx'        => self::MAX_WIDTH_PX,
            'jpegQuality'       => self::JPEG_QUALITY,
            'maxFileBytes'      => self::MAX_FILE_BYTES,
            'generationTimeout' => self::GENERATION_TIMEOUT_MS,
            'debounceMs'        => self::DEBOUNCE_MS,
            'maxRetries'        => self::MAX_RETRIES,
        ];
    }
}
