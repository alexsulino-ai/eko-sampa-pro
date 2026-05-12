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

    private const DESIGN_WIDTH = 800.0;

    private const DESIGN_HEIGHT = 1131.0;

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
            'position:relative;width:%dmm;height:%dmm;background:#fff;overflow:hidden;box-sizing:border-box;',
            $width_mm,
            $height_mm
        );

        $inner = '';
        foreach ($elements as $el) {
            if (! is_array($el)) {
                continue;
            }
            $inner .= $this->render_element($el, $context, $for_print);
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
     * @param array<string, mixed> $el
     * @param array<string, string> $context
     */
    private function render_element(array $el, array $context, bool $for_print): string {
        $x      = (float) ($el['x'] ?? 0);
        $y      = (float) ($el['y'] ?? 0);
        $w      = (float) ($el['width'] ?? 1);
        $h      = (float) ($el['height'] ?? 1);
        $type   = sanitize_key((string) ($el['type'] ?? 'text'));
        $styles = isset($el['styles']) && is_array($el['styles']) ? $el['styles'] : [];

        $left   = max(0.0, min(100.0, ($x / self::DESIGN_WIDTH) * 100.0));
        $top    = max(0.0, min(100.0, ($y / self::DESIGN_HEIGHT) * 100.0));
        $width  = max(0.0, min(100.0, ($w / self::DESIGN_WIDTH) * 100.0));
        $height = max(0.0, min(100.0, ($h / self::DESIGN_HEIGHT) * 100.0));

        $base = sprintf(
            'position:absolute;left:%F%%;top:%F%%;width:%F%%;height:%F%%;box-sizing:border-box;',
            $left,
            $top,
            $width,
            $height
        );

        $font_size = isset($styles['fontSize']) ? (int) $styles['fontSize'] : 14;
        $color     = isset($styles['color']) ? sanitize_hex_color((string) $styles['color']) : '#111827';
        if (! $color) {
            $color = '#111827';
        }

        switch ($type) {
            case 'image':
                $src = isset($el['src']) ? esc_url((string) $el['src']) : '';
                if ($src === '') {
                    $src = isset($el['content']) ? esc_url((string) $el['content']) : '';
                }
                if ($src === '') {
                    return '<div style="' . esc_attr($base . 'background:#e5e7eb;border:1px dashed #94a3b8;') . '"></div>';
                }

                return '<div style="' . esc_attr($base) . '">'
                    . '<img alt="" src="' . $src . '" style="width:100%;height:100%;object-fit:contain;display:block;" />'
                    . '</div>';

            case 'rectangle':
                return '<div style="' . esc_attr($base . 'background:#f1f5f9;border:1px solid #cbd5e1;') . '"></div>';

            case 'placeholder':
            case 'text':
            default:
                $raw_content = (string) ($el['content'] ?? '');
                $text        = $this->replace_tokens($raw_content, $context);
                $style       = $base . 'font-size:' . max(8, min(120, $font_size)) . 'px;color:' . $color . ';'
                    . 'display:flex;align-items:flex-start;justify-content:flex-start;padding:2px;overflow:hidden;word-break:break-word;';

                return '<div style="' . esc_attr($style) . '">' . esc_html($text) . '</div>';
        }
    }

    /**
     * @param array<string, string> $context
     */
    private function replace_tokens(string $text, array $context): string {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_-]+)\s*\}\}/',
            static function (array $m) use ($context): string {
                $key = strtolower((string) ($m[1] ?? ''));
                if ($key === '') {
                    return '';
                }

                return $context[ $key ] ?? $m[0];
            },
            $text
        );
    }
}
