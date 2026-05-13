<?php
/**
 * Renders template JSON to HTML (preview / print). No raw editor HTML persistence.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Maps JSON layout to positioned markup; replaces {{tokens}} from context.
 */
final class Eko_Sampa_Template_Renderer {

    /** Logical px per mm (CSS 96dpi). Must match `MM_TO_PX` in `assets/js/editor-canvas.js`. */
    private const MM_TO_CSS_PX = 96.0 / 25.4;

    /**
     * @return array{0: float, 1: float} Design canvas width/height in px (same basis as editor).
     */
    private function design_canvas_px(int $width_mm, int $height_mm): array {
        $w = max(1, (int) round($width_mm * self::MM_TO_CSS_PX));
        $h = max(1, (int) round($height_mm * self::MM_TO_CSS_PX));

        return [ (float) $w, (float) $h ];
    }

    /**
     * @param array<string, mixed> $template_row Row from wp_eko_sampa_templates.
     * @param array<string, string> $context      Token => value (plain text).
     *
     * @return array{width_mm: int, height_mm: int, html: string}
     */
    public function render(array $template_row, array $context, bool $for_print = false): array {
        $width_mm  = max(1, (int) ($template_row['width_mm'] ?? 210));
        $height_mm = max(1, (int) ($template_row['height_mm'] ?? 297));

        $raw = $template_row['json_data'] ?? null;
        $doc = [];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (JSON_ERROR_NONE === json_last_error() && is_array($decoded)) {
                $doc = $decoded;
            }
        }

        $elements = [];
        if (isset($doc['elements']) && is_array($doc['elements'])) {
            $elements = $doc['elements'];
        } elseif (isset($doc[0]) && is_array($doc[0])) {
            $elements = $doc;
        }

        $frame_style = sprintf(
            'position:relative;width:%dmm;height:%dmm;background:#fff;overflow:hidden;box-sizing:border-box;%s',
            $width_mm,
            $height_mm,
            $for_print ? '-webkit-print-color-adjust:exact;print-color-adjust:exact;' : ''
        );

        [ $design_w, $design_h ] = $this->design_canvas_px($width_mm, $height_mm);

        $inner = '';
        foreach ($elements as $el) {
            if (! is_array($el)) {
                continue;
            }
            $inner .= $this->render_element($el, $context, $for_print, $design_w, $design_h);
        }

        $wrap = $for_print ? 'eko-sampa-print-root' : 'eko-sampa-preview-root';
        $html = '<div class="' . esc_attr($wrap) . '" style="' . esc_attr($frame_style) . '">' . $inner . '</div>';

        return [
            'width_mm'  => $width_mm,
            'height_mm' => $height_mm,
            'html'      => $html,
        ];
    }

    /**
     * @param array<string, mixed> $template_row
     *
     * @return array<int, array<string, mixed>>
     */
    public function parse_elements_from_template_row(array $template_row): array {
        $raw = $template_row['json_data'] ?? null;
        $doc = [];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (JSON_ERROR_NONE === json_last_error() && is_array($decoded)) {
                $doc = $decoded;
            }
        }

        if (isset($doc['elements']) && is_array($doc['elements'])) {
            return $doc['elements'];
        }
        if (isset($doc[0]) && is_array($doc[0])) {
            return $doc;
        }

        return [];
    }

    /**
     * Deep-merge placeholder tokens into text/placeholder elements (same rules as {@see render()}).
     *
     * @param array<int, array<string, mixed>> $elements
     * @param array<string, string>            $context
     *
     * @return array<int, array<string, mixed>>
     */
    public function apply_context_to_elements(array $elements, array $context): array {
        $out = [];
        foreach ($elements as $el) {
            if (! is_array($el)) {
                continue;
            }
            $json = wp_json_encode($el);
            if (! is_string($json)) {
                continue;
            }
            $copy = json_decode($json, true);
            if (! is_array($copy)) {
                continue;
            }
            $type = sanitize_key((string) ($copy['type'] ?? ''));
            if ($type === 'text' || $type === 'placeholder') {
                $raw            = (string) ($copy['content'] ?? '');
                $copy['content'] = $this->replace_tokens($raw, $context);
            }
            $out[] = $copy;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $el
     * @param array<string, string> $context
     */
    private function render_element(array $el, array $context, bool $for_print, float $design_w, float $design_h): string {
        $x      = (float) ($el['x'] ?? 0);
        $y      = (float) ($el['y'] ?? 0);
        $w      = (float) ($el['width'] ?? 1);
        $h      = (float) ($el['height'] ?? 1);
        $type   = sanitize_key((string) ($el['type'] ?? 'text'));
        $styles = isset($el['styles']) && is_array($el['styles']) ? $el['styles'] : [];

        $dw     = $design_w > 0.0 ? $design_w : 1.0;
        $dh     = $design_h > 0.0 ? $design_h : 1.0;
        $left   = max(0.0, min(100.0, ($x / $dw) * 100.0));
        $top    = max(0.0, min(100.0, ($y / $dh) * 100.0));
        $width  = max(0.0, min(100.0, ($w / $dw) * 100.0));
        $height = max(0.0, min(100.0, ($h / $dh) * 100.0));

        $base = sprintf(
            'position:absolute;left:%F%%;top:%F%%;width:%F%%;height:%F%%;box-sizing:border-box;',
            $left,
            $top,
            $width,
            $height
        );

        $frame_css = $this->build_frame_css($styles);
        $inner_css = $this->build_text_inner_css($styles, $for_print);

        switch ($type) {
            case 'image':
                $raw_src = isset($el['src']) ? (string) $el['src'] : '';
                if ($raw_src === '') {
                    $raw_src = isset($el['content']) ? (string) $el['content'] : '';
                }
                $src = $this->resolve_public_url($raw_src);
                if ($src === '') {
                    return '<div style="' . esc_attr($base . 'background:#e5e7eb;border:1px dashed #94a3b8;') . '"></div>';
                }

                $img_css = $this->build_image_img_css($styles);

                return '<div style="' . esc_attr($base . $frame_css) . '">'
                    . '<img alt="" src="' . $src . '" style="' . esc_attr($img_css) . '" />'
                    . '</div>';

            case 'rectangle':
                $rect = $base . $frame_css . 'background:#f1f5f9;';

                return '<div style="' . esc_attr($rect) . '"></div>';

            case 'placeholder':
            case 'text':
            default:
                $raw_content = (string) ($el['content'] ?? '');
                $text        = $this->replace_tokens($raw_content, $context);
                $inner       = $base . $frame_css . 'box-sizing:border-box;';

                return '<div style="' . esc_attr($inner) . '">'
                    . '<div style="' . esc_attr($inner_css) . '">' . esc_html($text) . '</div>'
                    . '</div>';
        }
    }

    /**
     * Outer frame: opacity, border, radius, shadow, rotation (matches editor `elementFrameCss`).
     *
     * @param array<string, mixed> $styles
     */
    private function build_frame_css(array $styles): string {
        $opacity = isset($styles['opacity']) ? (float) $styles['opacity'] : 1.0;
        if ($opacity < 0.0) {
            $opacity = 0.0;
        }
        if ($opacity > 1.0) {
            $opacity = 1.0;
        }

        $br = isset($styles['borderRadius']) ? max(0, (int) $styles['borderRadius']) : 0;
        $bw = isset($styles['borderWidth']) ? max(0, (int) $styles['borderWidth']) : 0;
        $bs = isset($styles['borderStyle']) ? strtolower((string) $styles['borderStyle']) : 'solid';
        if (! in_array($bs, [ 'solid', 'dashed', 'dotted', 'none' ], true)) {
            $bs = 'solid';
        }
        $bc = $this->sanitize_css_color($styles['borderColor'] ?? '#cbd5e1', '#cbd5e1');
        $sh = $this->sanitize_box_shadow($styles['boxShadow'] ?? 'none');
        $rot = isset($styles['rotate']) ? (float) $styles['rotate'] : 0.0;
        if ($rot < -360.0) {
            $rot = -360.0;
        }
        if ($rot > 360.0) {
            $rot = 360.0;
        }

        $border = 'none';
        if ($bw > 0 && $bs !== 'none') {
            $border = sprintf('%dpx %s %s', $bw, $bs, $bc);
        }

        $css = sprintf(
            'position:absolute;left:0;top:0;width:100%%;height:100%%;box-sizing:border-box;opacity:%F;border-radius:%dpx;border:%s;box-shadow:%s;transform:rotate(%Fdeg);transform-origin:center center;overflow:hidden;',
            $opacity,
            $br,
            $border,
            $sh,
            $rot
        );

        return $css;
    }

    /**
     * @param array<string, mixed> $styles
     */
    private function build_text_inner_css(array $styles, bool $for_print = false): string {
        $ff = $this->sanitize_font_family($styles['fontFamily'] ?? 'system-ui, sans-serif');
        $fs = isset($styles['fontSize']) ? max(6, min(200, (int) $styles['fontSize'])) : 16;
        $fw = isset($styles['fontWeight']) ? (string) $styles['fontWeight'] : '400';
        $n  = (int) round((float) $fw);
        $fw = ($n >= 100 && $n <= 900) ? (string) $n : '400';
        $fst = isset($styles['fontStyle']) && strtolower((string) $styles['fontStyle']) === 'italic' ? 'italic' : 'normal';
        $td  = isset($styles['textDecoration']) ? strtolower((string) $styles['textDecoration']) : 'none';
        if (! in_array($td, [ 'none', 'underline', 'line-through', 'underline line-through' ], true)) {
            $td = 'none';
        }
        $ta = isset($styles['textAlign']) ? strtolower((string) $styles['textAlign']) : 'left';
        if (! in_array($ta, [ 'left', 'center', 'right', 'justify' ], true)) {
            $ta = 'left';
        }
        $color = $this->sanitize_css_color($styles['color'] ?? '#111827', '#111827');
        $bg    = $this->sanitize_css_color($styles['backgroundColor'] ?? 'transparent', 'transparent');
        $lh    = isset($styles['lineHeight']) ? (float) $styles['lineHeight'] : 1.35;
        if ($lh < 0.8 || $lh > 4.0) {
            $lh = 1.35;
        }
        $ls = isset($styles['letterSpacing']) ? max(-20.0, min(40.0, (float) $styles['letterSpacing'])) : 0.0;
        $tt = isset($styles['textTransform']) ? strtolower((string) $styles['textTransform']) : 'none';
        if (! in_array($tt, [ 'none', 'uppercase', 'lowercase', 'capitalize' ], true)) {
            $tt = 'none';
        }

        $overflow = $for_print ? 'hidden' : 'auto';

        return sprintf(
            'width:100%%;height:100%%;box-sizing:border-box;font-family:%s;font-size:%dpx;font-weight:%s;font-style:%s;text-decoration:%s;text-align:%s;color:%s;background-color:%s;line-height:%F;letter-spacing:%Fpx;text-transform:%s;white-space:pre-wrap;word-break:break-word;overflow:%s;padding:4px 6px;display:block;',
            $ff,
            $fs,
            $fw,
            $fst,
            $td,
            $ta,
            $color,
            $bg,
            $lh,
            $ls,
            $tt,
            $overflow
        );
    }

    /**
     * @param array<string, mixed> $styles
     */
    private function build_image_img_css(array $styles): string {
        $fit = isset($styles['objectFit']) ? strtolower((string) $styles['objectFit']) : 'cover';
        if (! in_array($fit, [ 'contain', 'cover', 'fill', 'none', 'scale-down' ], true)) {
            $fit = 'cover';
        }

        return sprintf('width:100%%;height:100%%;display:block;object-fit:%s;', $fit);
    }

    /**
     * Turn editor-stored paths into absolute URLs so print/preview pass esc_url + wp_kses and browsers load images.
     */
    private function resolve_public_url(string $raw): string {
        $s = trim($raw);
        if ($s === '') {
            return '';
        }
        if (preg_match('#^(blob:|data:|javascript:)#i', $s)) {
            return '';
        }
        if (preg_match('#^https?://#i', $s)) {
            return esc_url($s);
        }
        if (str_starts_with($s, '//')) {
            $prefix = is_ssl() ? 'https:' : 'http:';

            return esc_url($prefix . $s);
        }
        if (str_starts_with($s, '/')) {
            return esc_url(home_url($s));
        }

        return esc_url(home_url('/' . ltrim($s, '/')));
    }

    private function sanitize_font_family(string $raw): string {
        $t = trim($raw);
        if ($t === '' || strlen($t) > 220 || preg_match('/[<>{}"\'`;]/', $t)) {
            return 'system-ui, sans-serif';
        }

        return $t;
    }

    private function sanitize_css_color(mixed $raw, string $fallback): string {
        $s = trim((string) $raw);
        if ($s === '' || strtolower($s) === 'transparent') {
            return 'transparent';
        }
        $hex = sanitize_hex_color($s);
        if ($hex) {
            return $hex;
        }
        if (preg_match('/^rgba?\(\s*[\d.]+\s*,\s*[\d.]+\s*,\s*[\d.]+\s*(,\s*[\d.]+\s*)?\)$/i', $s)) {
            return $s;
        }

        return $fallback;
    }

    private function sanitize_box_shadow(mixed $raw): string {
        $t = trim((string) $raw);
        if ($t === '' || strtolower($t) === 'none') {
            return 'none';
        }
        if (strlen($t) > 180 || preg_match('/[<>;{}]|url\s*\(/i', $t)) {
            return 'none';
        }

        return $t;
    }

    /**
     * @param array<string, string> $context
     */
    private function replace_tokens(string $text, array $context): string {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_-]+)\s*\}\}/',
            static function (array $m) use ($context): string {
                $raw = trim((string) ($m[1] ?? ''));
                if ($raw === '') {
                    return '';
                }
                $a = strtolower($raw);
                $b = strtolower(sanitize_title($raw));
                if (isset($context[ $a ])) {
                    return $context[ $a ];
                }
                if ($b !== '' && isset($context[ $b ])) {
                    return $context[ $b ];
                }

                return $m[0];
            },
            $text
        );
    }
}
