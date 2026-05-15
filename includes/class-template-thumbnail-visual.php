<?php
/**
 * Visual checksum for template thumbnails (layout + elements only, not metadata).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Eko_Sampa_Template_Thumbnail_Visual {

    /**
     * @param array<string, mixed> $row
     */
    public static function hash_from_row(array $row): string {
        $renderer = new Eko_Sampa_Template_Renderer();
        $elements = $renderer->parse_elements_from_template_row($row);

        return self::hash_from_visual(
            (int) ( $row['width_mm'] ?? 210 ),
            (int) ( $row['height_mm'] ?? 297 ),
            $elements
        );
    }

    /**
     * @param array<int, array<string, mixed>> $elements
     */
    public static function hash_from_visual(int $width_mm, int $height_mm, array $elements): string {
        $payload = [
            'width_mm'  => max(1, $width_mm),
            'height_mm' => max(1, $height_mm),
            'elements'  => self::normalize_elements($elements),
        ];

        $json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $json = is_string($json) ? $json : '';

        return self::fnv_pair_hex($json);
    }

    private static function fnv1a_hex(string $text): string {
        $h   = 2166136261;
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $h ^= ord($text[ $i ]);
            $h  = (int) ( ( $h * 16777619 ) & 0xffffffff );
        }

        return sprintf('%08x', $h);
    }

    private static function fnv_pair_hex(string $json): string {
        return substr(self::fnv1a_hex($json) . self::fnv1a_hex($json . ':eko'), 0, 16);
    }

    /**
     * @param array<int, array<string, mixed>> $elements
     *
     * @return array<int, array<string, mixed>>
     */
    private static function normalize_elements(array $elements): array {
        $out = [];
        foreach ($elements as $el) {
            if (! is_array($el)) {
                continue;
            }
            $type = sanitize_key((string) ( $el['type'] ?? 'text' ));
            $item = [
                'type'    => $type,
                'x'       => round((float) ( $el['x'] ?? 0 ), 2),
                'y'       => round((float) ( $el['y'] ?? 0 ), 2),
                'width'   => round((float) ( $el['width'] ?? 0 ), 2),
                'height'  => round((float) ( $el['height'] ?? 0 ), 2),
                'content' => (string) ( $el['content'] ?? '' ),
            ];
            if ($type === 'image') {
                $src = isset($el['src']) ? (string) $el['src'] : (string) ( $el['content'] ?? '' );
                $item['src'] = self::normalize_asset_ref($src);
            }
            if (isset($el['styles']) && is_array($el['styles'])) {
                $item['styles'] = self::normalize_styles($el['styles']);
            }
            $out[] = $item;
        }

        usort(
            $out,
            static function (array $a, array $b): int {
                $ay = (float) ( $a['y'] ?? 0 );
                $by = (float) ( $b['y'] ?? 0 );
                if ($ay !== $by) {
                    return $ay <=> $by;
                }

                return (float) ( $a['x'] ?? 0 ) <=> (float) ( $b['x'] ?? 0 );
            }
        );

        return $out;
    }

    /**
     * @param array<string, mixed> $styles
     *
     * @return array<string, mixed>
     */
    private static function normalize_styles(array $styles): array {
        $keys = [
            'fontSize',
            'fontFamily',
            'fontWeight',
            'fontStyle',
            'color',
            'backgroundColor',
            'textAlign',
            'opacity',
            'borderRadius',
            'borderWidth',
            'borderColor',
            'borderStyle',
            'rotate',
            'objectFit',
        ];
        $out  = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $styles)) {
                $out[ $key ] = $styles[ $key ];
            }
        }

        return $out;
    }

    private static function normalize_asset_ref(string $src): string {
        $src = trim($src);
        if ($src === '') {
            return '';
        }

        $upload = wp_upload_dir();
        $baseurl = trailingslashit((string) ( $upload['baseurl'] ?? '' ) );
        $basedir = trailingslashit((string) ( $upload['basedir'] ?? '' ) );
        if ($baseurl !== '' && $basedir !== '' && str_starts_with($src, $baseurl)) {
            return 'upload:' . ltrim(substr($src, strlen($baseurl)), '/');
        }

        if (str_starts_with($src, 'data:image')) {
            return 'data:' . substr(hash('sha256', $src), 0, 12);
        }

        return $src;
    }
}
