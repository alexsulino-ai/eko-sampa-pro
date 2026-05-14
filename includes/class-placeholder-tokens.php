<?php
/**
 * Single source of truth for {{token}} syntax: extraction + substitution.
 *
 * Template JSON, preview, and print must use the same rules as {@see Eko_Sampa_Order::template_render_context()}
 * key normalization (slug keys in dynamic_data_json).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Placeholder catalog for layout JSON (no coupling to Orders or Services).
 */
final class Eko_Sampa_Placeholder_Tokens {

    /**
     * Must stay aligned with historical renderer behaviour and client-side expectations.
     */
    private const TOKEN_REGEX = '/\{\{\s*([a-zA-Z0-9_-]+)\s*\}\}/';

    /**
     * Normalize a token name to the same scalar key used in {@see Eko_Sampa_Order::template_render_context()}.
     */
    public static function normalize_context_key(string $raw): string {
        return strtolower(sanitize_title($raw));
    }

    /**
     * @return array<int, string> Unique placeholder keys in first-seen order (normalized).
     */
    public static function collect_from_text(string $text): array {
        if ($text === '') {
            return [];
        }

        if (preg_match_all(self::TOKEN_REGEX, $text, $m) === false || ! isset($m[1]) || ! is_array($m[1])) {
            return [];
        }

        $out   = [];
        $seen  = [];
        foreach ($m[1] as $raw) {
            $piece = trim((string) $raw);
            if ($piece === '') {
                continue;
            }
            $key = self::normalize_context_key($piece);
            if ($key === '') {
                continue;
            }
            if (isset($seen[ $key ])) {
                continue;
            }
            $seen[ $key ] = true;
            $out[] = $key;
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $elements
     *
     * @return array<int, string>
     */
    public static function collect_from_elements(array $elements): array {
        $buf = '';
        foreach ($elements as $el) {
            if (! is_array($el)) {
                continue;
            }
            $type = sanitize_key((string) ($el['type'] ?? ''));
            if ($type === 'text' || $type === 'placeholder') {
                $buf .= "\n" . (string) ($el['content'] ?? '');
            }
        }

        return self::collect_from_text($buf);
    }

    /**
     * @param array<string, mixed> $template_row Row from wp_eko_sampa_templates.
     *
     * @return array<int, string>
     */
    public static function collect_from_template_row(array $template_row): array {
        $rnd = new Eko_Sampa_Template_Renderer();

        return self::collect_from_elements($rnd->parse_elements_from_template_row($template_row));
    }

    /**
     * @param array<string, string> $context Token => plain text (keys should be normalized for stable lookups).
     */
    public static function replace_in_text(string $text, array $context): string {
        return (string) preg_replace_callback(
            self::TOKEN_REGEX,
            static function (array $m) use ($context): string {
                $raw = trim((string) ($m[1] ?? ''));
                if ($raw === '') {
                    return '';
                }
                $a = strtolower($raw);
                $b = self::normalize_context_key($raw);
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
