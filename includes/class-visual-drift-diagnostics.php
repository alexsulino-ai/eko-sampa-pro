<?php
/**
 * Server-side visual drift heuristics: canonical render contract vs stored assets / JSON.
 *
 * True DOM-vs-thumbnail pixel comparison requires the browser; this class reports structural drift
 * and file dimension mismatches that usually explain editor vs thumbnail divergence.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * @package Eko_Sampa
 */
final class Eko_Sampa_Visual_Drift_Diagnostics {

    /**
     * @param array<string, mixed> $row Template row (width_mm, height_mm, preview_image, json_data).
     *
     * @return array<string, mixed>
     */
    public static function analyze_template_row(array $row): array {
        $width_mm  = self::clamp_mm((float) ( $row['width_mm'] ?? 210 ));
        $height_mm = self::clamp_mm((float) ( $row['height_mm'] ?? 297 ));

        $design_w = max(1, (int) round($width_mm * Eko_Sampa_Render_Schema::CSS_PX_PER_MM));
        $design_h = max(1, (int) round($height_mm * Eko_Sampa_Render_Schema::CSS_PX_PER_MM));

        $max_w = Eko_Sampa_Template_Thumbnail_Config::MAX_WIDTH_PX;
        $scale = min(1.0, $max_w / max(1, $design_w));
        $exp_w = max(1, (int) round($design_w * $scale));
        $exp_h = max(1, (int) round($design_h * $scale));

        $drift_elements = [];
        $warnings       = [];
        $causes         = [];

        $json_raw = $row['json_data'] ?? null;
        $parsed   = null;
        if (is_string($json_raw) && $json_raw !== '') {
            $parsed = json_decode($json_raw, true);
            if (JSON_ERROR_NONE !== json_last_error()) {
                $warnings[] = 'json_data_invalid_for_layout_compare';
                $causes[]   = 'invalid_json_breaks_single_payload_contract';
            }
        }

        if (is_array($parsed)) {
            $elements = isset($parsed['elements']) && is_array($parsed['elements']) ? $parsed['elements'] : [];
            foreach ($elements as $idx => $el) {
                if (! is_array($el)) {
                    continue;
                }
                $type = strtolower((string) ( $el['type'] ?? '' ));
                if ($type === 'image') {
                    $src = trim((string) ( $el['src'] ?? $el['content'] ?? '' ));
                    if ($src !== '' && ! preg_match('#^(https?:)?//#i', $src)) {
                        $abs = self::resolve_upload_relative($src);
                        if ($abs !== '' && is_readable($abs)) {
                            $info = @getimagesize($abs);
                            if (is_array($info) && isset($info[0], $info[1])) {
                                $bw = (float) ( $el['width'] ?? 0 );
                                $bh = (float) ( $el['height'] ?? 0 );
                                if ($bw > 1 && $bh > 1) {
                                    $ar_el = $bw / $bh;
                                    $ar_im = $info[0] / max(1, $info[1]);
                                    if (abs($ar_el - $ar_im) > 0.08) {
                                        $drift_elements[] = [
                                            'index'           => $idx,
                                            'id'              => $el['id'] ?? null,
                                            'type'            => 'image',
                                            'issue'           => 'intrinsic_aspect_mismatch',
                                            'element_ratio'   => round($ar_el, 4),
                                            'file_ratio'      => round($ar_im, 4),
                                            'natural_w'       => $info[0],
                                            'natural_h'       => $info[1],
                                        ];
                                        $causes[] = 'image_box_aspect_ratio_differs_from_file_intrinsic';
                                    }
                                }
                            } else {
                                $warnings[] = 'image_unreadable_or_corrupt:' . (string) ( $el['id'] ?? $idx );
                            }
                        }
                    }
                }
            }
        }

        $preview = trim((string) ( $row['preview_image'] ?? '' ));
        $file_w  = null;
        $file_h  = null;
        if ($preview !== '') {
            $abs = self::resolve_upload_relative($preview);
            if ($abs !== '' && is_readable($abs)) {
                $info = @getimagesize($abs);
                if (is_array($info) && isset($info[0], $info[1])) {
                    $file_w = (int) $info[0];
                    $file_h = (int) $info[1];
                }
            }
        }

        $dim_drift = null;
        if ($file_w !== null && $file_h !== null) {
            $dx        = abs($file_w - $exp_w);
            $dy        = abs($file_h - $exp_h);
            $dim_drift = max($dx, $dy);
            if ($dx > 2 || $dy > 2) {
                $drift_elements[] = [
                    'index' => -1,
                    'type'  => 'thumbnail_jpeg',
                    'issue' => 'jpeg_dimensions_differ_from_contract',
                    'expected' => ['w' => $exp_w, 'h' => $exp_h],
                    'actual'   => ['w' => $file_w, 'h' => $file_h],
                ];
                $causes[] = 'thumbnail_file_not_regenerated_after_layout_change_or_parallel_mm_math';
            }
        }

        $drift_score = self::score_from_drifts($drift_elements, $warnings);
        $severity    = $drift_score >= 60 ? 'high' : ( $drift_score >= 25 ? 'medium' : 'low' );

        return [
            'drift_score'           => $drift_score,
            'drift_elements'        => $drift_elements,
            'severity'              => $severity,
            'suspected_root_causes' => array_values(array_unique($causes)),
            'canonical'             => [
                'mm_to_css_px'       => Eko_Sampa_Render_Schema::CSS_PX_PER_MM,
                'thumbnail_max_w_px' => $max_w,
                'design_canvas_px'   => ['w' => $design_w, 'h' => $design_h],
                'expected_thumb_px'  => ['w' => $exp_w, 'h' => $exp_h],
                'scale'              => round($scale, 6),
            ],
            'file_thumbnail_px'     => $file_w === null ? null : ['w' => $file_w, 'h' => $file_h],
            'thumbnail_dimension_delta_max_px' => $dim_drift,
            'warnings'              => $warnings,
            'screenshot_metadata'   => [
                'source' => 'server_file_probe',
                'note'   => 'DOM-vs-canvas metrics require client `visual_debug=1` + EkoCanvasRenderer.',
            ],
        ];
    }

    private static function clamp_mm(float $mm): float {
        if (! is_finite($mm)) {
            return 210.0;
        }

        return max(10.0, min(2000.0, $mm));
    }

    /**
     * @param list<array<string, mixed>> $drift_elements
     * @param list<string>               $warnings
     */
    private static function score_from_drifts(array $drift_elements, array $warnings): int {
        $s = count($warnings) * 12 + count($drift_elements) * 22;

        return (int) max(0, min(100, $s));
    }

    private static function resolve_upload_relative(string $rel): string {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        if ($rel === '' || str_contains($rel, '..')) {
            return '';
        }
        $upload = wp_upload_dir();
        if (! empty($upload['error']) || empty($upload['basedir'])) {
            return '';
        }

        return $upload['basedir'] . '/' . $rel;
    }
}
