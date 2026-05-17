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
     * Payload for EkoCanvasRenderer / order live preview (px canvas, tokens applied).
     *
     * @param array<string, mixed> $template_row
     * @param array<string, string> $context
     *
     * @return array{width_mm: int, height_mm: int, elements: array<int, array<string, mixed>>}
     */
    public function build_editor_preview_payload(array $template_row, array $context): array {
        $width_mm  = max(1, (int) ($template_row['width_mm'] ?? 210));
        $height_mm = max(1, (int) ($template_row['height_mm'] ?? 297));

        return Eko_Sampa_Render_Schema::envelope(
            [
                'width_mm'  => $width_mm,
                'height_mm' => $height_mm,
                'elements'  => $this->apply_context_to_elements(
                    $this->parse_elements_from_template_row($template_row),
                    $context
                ),
            ]
        );
    }

    /**
     * @param array<string, mixed> $template_row Row from wp_eko_sampa_templates.
     * @param array<string, string> $context      Token => value (plain text).
     *
     * @return array{width_mm: int, height_mm: int, html: string}
     */
    public function render(array $template_row, array $context, bool $for_print = false): array {
        $preview   = $this->build_editor_preview_payload($template_row, $context);
        $width_mm  = (int) $preview['width_mm'];
        $height_mm = (int) $preview['height_mm'];
        [ $canvas_w, $canvas_h ] = $this->design_canvas_px($width_mm, $height_mm);

        $print_adjust = '-webkit-print-color-adjust:exact;print-color-adjust:exact;color-adjust:exact;';
        $root_style   = sprintf(
            'position:relative;width:%dmm;height:%dmm;%soverflow:hidden;box-sizing:border-box;background:#fff;',
            $width_mm,
            $height_mm,
            $for_print ? $print_adjust : ''
        );
        $surface_style = sprintf(
            'width:%dpx;height:%dpx;position:relative;box-sizing:border-box;background:#fff;overflow:hidden;%s',
            (int) $canvas_w,
            (int) $canvas_h,
            $for_print ? $print_adjust : ''
        );

        $inner = '';
        $stack_i = 0;
        foreach ($preview['elements'] as $el) {
            if (! is_array($el)) {
                continue;
            }
            $inner .= $this->render_element($el, $for_print, $stack_i);
            ++$stack_i;
        }

        $wrap = $for_print ? 'eko-sampa-print-root' : 'eko-sampa-preview-root';
        $html = '<div class="' . esc_attr($wrap) . '" style="' . esc_attr($root_style) . '">'
            . '<div class="eko-sampa-canvas" style="' . esc_attr($surface_style) . '">' . $inner . '</div>'
            . '</div>';

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
                $copy['content'] = Eko_Sampa_Placeholder_Tokens::replace_in_text($raw, $context);
            }
            $out[] = $copy;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $el
     * @param int                  $stack_index Sibling paint order (0 = back). Must match `EkoCanvasRenderer.stackZFromIndex` (10 + index).
     */
    private function render_element(array $el, bool $for_print, int $stack_index = 0): string {
        $x      = (float) ($el['x'] ?? 0);
        $y      = (float) ($el['y'] ?? 0);
        $w      = (float) ($el['width'] ?? 10);
        $h      = (float) ($el['height'] ?? 10);
        $type   = sanitize_key((string) ($el['type'] ?? 'text'));
        $styles = isset($el['styles']) && is_array($el['styles']) ? $el['styles'] : [];

        $zi = 10 + max(0, $stack_index);

        $pos = sprintf(
            'position:absolute;left:%Fpx;top:%Fpx;width:%Fpx;height:%Fpx;box-sizing:border-box;z-index:%d;',
            $x,
            $y,
            $w,
            $h,
            $zi
        );

        $frame_css = $this->build_frame_css($styles, $type);
        $text_css  = $this->build_text_inner_css($styles, $for_print);

        switch ($type) {
            case 'image':
                $raw_src = isset($el['src']) ? (string) $el['src'] : '';
                if ($raw_src === '') {
                    $raw_src = isset($el['content']) ? (string) $el['content'] : '';
                }
                $src = $this->resolve_public_url($raw_src);
                if ($src === '') {
                    return '<div class="eko-sampa-canvas__element" style="' . esc_attr($pos) . '">'
                        . '<div class="eko-sampa-canvas__frame" style="' . esc_attr($frame_css . ';background:#e5e7eb;border:1px dashed #94a3b8;') . '"></div>'
                        . '</div>';
                }
                $img_css = $this->build_image_img_css($styles);

                return '<div class="eko-sampa-canvas__element" style="' . esc_attr($pos) . '">'
                    . '<div class="eko-sampa-canvas__frame" style="' . esc_attr($frame_css) . '">'
                    . '<img class="eko-sampa-canvas__img" alt="" src="' . $src . '" style="' . esc_attr($img_css) . '" loading="eager" decoding="sync" />'
                    . '</div></div>';

            case 'rectangle':
                return '<div class="eko-sampa-canvas__element" style="' . esc_attr($pos) . '">'
                    . '<div class="eko-sampa-canvas__frame" style="' . esc_attr($frame_css) . '"></div>'
                    . '</div>';

            case 'placeholder':
            case 'text':
            default:
                $text = (string) ($el['content'] ?? '');

                return '<div class="eko-sampa-canvas__element" style="' . esc_attr($pos) . '">'
                    . '<div class="eko-sampa-canvas__frame" style="' . esc_attr($frame_css) . '">'
                    . '<span class="eko-sampa-canvas__text" style="' . esc_attr($text_css) . '">' . esc_html($text) . '</span>'
                    . '</div></div>';
        }
    }

    /**
     * Outer frame: opacity, border, radius, shadow, rotation (matches editor `elementFrameCss`).
     *
     * @param array<string, mixed> $styles
     */
    private function build_frame_css(array $styles, string $type = ''): string {
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

        $overflow = 'hidden';

        $css = sprintf(
            'position:absolute;left:0;top:0;width:100%%;height:100%%;box-sizing:border-box;opacity:%F;border-radius:%dpx;border:%s;box-shadow:%s;transform:rotate(%Fdeg);transform-origin:center center;overflow:%s;',
            $opacity,
            $br,
            $border,
            $sh,
            $rot,
            $overflow
        );

        if ($type === 'rectangle') {
            $css .= 'background:#f1f5f9;';
        }

        if ($type === 'text' || $type === 'placeholder') {
            $bgf = $this->sanitize_css_color($styles['backgroundColor'] ?? 'transparent', 'transparent');
            $css .= sprintf('background-color:%s;padding:4px 6px;display:flex;flex-direction:column;min-height:0;', $bgf);
        }

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
            'flex:1;min-width:0;min-height:0;width:100%%;margin:0;padding:0;box-sizing:border-box;font-family:%s;font-size:%dpx;font-weight:%s;font-style:%s;text-decoration:%s;text-align:%s;color:%s;line-height:%F;letter-spacing:%Fpx;text-transform:%s;white-space:pre-wrap;word-break:break-word;overflow:%s;display:block;',
            $ff,
            $fs,
            $fw,
            $fst,
            $td,
            $ta,
            $color,
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

        return sprintf(
            'width:100%%;height:100%%;max-width:100%%;max-height:100%%;display:block;object-fit:%s;-webkit-print-color-adjust:exact;print-color-adjust:exact;',
            $fit
        );
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

}
