<?php
/**
 * Frontend shortcode entry points (docs/architecture.md).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Registers [eko_sampa_shell] and [eko_sampa_login].
 */
final class Eko_Sampa_Shortcodes {

    public function register_hooks(): void {
        add_shortcode('eko_sampa_shell', [$this, 'shortcode_shell']);
        add_shortcode('eko_sampa_login', [$this, 'shortcode_login']);
    }

    /**
     * @param array<string, string> $atts
     */
    public function shortcode_shell(array $atts): string {
        $atts = shortcode_atts(
            [
                'view' => 'dashboard',
            ],
            $atts,
            'eko_sampa_shell'
        );

        $view = sanitize_key((string) $atts['view']);
        ob_start();
        Eko_Sampa_Frontend_Router::render_app_view($view);

        return (string) ob_get_clean();
    }

    /**
     * @param array<string, string> $_atts
     */
    public function shortcode_login(array $_atts): string {
        ob_start();
        Eko_Sampa_Frontend_Router::render_app_view('login');

        return (string) ob_get_clean();
    }
}
