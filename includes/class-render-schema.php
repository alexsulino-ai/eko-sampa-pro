<?php
/**
 * Versioned contract for EkoCanvasRenderer payloads (PHP ↔ JS).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Single source of schema version and unit metadata for preview/print/export payloads.
 */
final class Eko_Sampa_Render_Schema {

    /** Bump when element shape, token buckets, or units change (migrate in JS + PHP). */
    public const VERSION = 1;

    public const UNITS_SURFACE = 'mm';

    public const UNITS_LAYOUT = 'px';

    /** CSS reference px per mm (96dpi). Must match `EkoVisualRenderContract` / `eko-visual-render-contract.js`. */
    public const CSS_PX_PER_MM = 96.0 / 25.4;

    /**
     * @param array<string, mixed> $body width_mm, height_mm, elements, …
     *
     * @return array<string, mixed>
     */
    public static function envelope(array $body): array {
        return array_merge(
            [
                'schema_version' => self::VERSION,
                'units'          => self::units_meta(),
            ],
            $body
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function units_meta(): array {
        return [
            'surface'      => self::UNITS_SURFACE,
            'layout'       => self::UNITS_LAYOUT,
            'css_px_per_mm' => self::CSS_PX_PER_MM,
        ];
    }
}
