<?php
/**
 * Admin routes and WooCommerce integration hooks (foundation).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Registers minimal admin UI and prepares WooCommerce-facing hooks.
 */
final class Eko_Sampa_Router {

    public const MENU_SLUG = 'eko-sampa';

    public const EDITOR_SLUG = 'eko-sampa-editor';

    public function register_hooks(): void {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('init', [$this, 'register_rewrite_placeholder'], 0);

        if (did_action('woocommerce_loaded')) {
            $this->on_woocommerce_loaded();
        } else {
            add_action('woocommerce_loaded', [$this, 'on_woocommerce_loaded']);
        }

        add_action('rest_api_init', [$this, 'register_rest_placeholder']);
    }

    /**
     * Top-level menu so admin screens exist for conditional assets and future modules.
     */
    public function register_admin_menu(): void {
        add_menu_page(
            __('Eko Sampa', 'eko-sampa'),
            __('Eko Sampa', 'eko-sampa'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_admin_app_shell'],
            'dashicons-art',
            56
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Visual editor', 'eko-sampa'),
            __('Editor', 'eko-sampa'),
            'manage_options',
            self::EDITOR_SLUG,
            [$this, 'render_editor_canvas']
        );
    }

    /**
     * Dashboard shell: sidebar, header, content (layout only).
     */
    public function render_admin_app_shell(): void {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'eko-sampa'));
        }

        $view = EKO_SAMPA_PLUGIN_DIR . 'views/admin-dashboard-shell.php';
        if (is_readable($view)) {
            require $view;
        }
    }

    /**
     * Visual editor: canvas viewport, grid, zoom (layout only).
     */
    public function render_editor_canvas(): void {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'eko-sampa'));
        }

        $view = EKO_SAMPA_PLUGIN_DIR . 'views/editor-canvas.php';
        if (is_readable($view)) {
            require $view;
        }
    }

    /**
     * Reserved for future print/preview endpoints; no rules added yet.
     */
    public function register_rewrite_placeholder(): void {
        /**
         * Fires when the router registers base rewrite support (placeholder).
         */
        do_action('eko_sampa_register_rewrites', $this);
    }

    /**
     * WooCommerce-specific bootstrap: keep hooks isolated for product/template integration later.
     */
    public function on_woocommerce_loaded(): void {
        /**
         * Fires when WooCommerce is loaded and Eko Sampa may register integrations.
         */
        do_action('eko_sampa_woocommerce_loaded', $this);
    }

    /**
     * REST namespace placeholder for future AJAX replacement.
     */
    public function register_rest_placeholder(): void {
        /**
         * Fires before Eko Sampa registers REST routes (extend from modules).
         *
         * @param Eko_Sampa_Router $router Router instance.
         */
        do_action('eko_sampa_rest_api_init', $this);
    }

    /**
     * Whether WooCommerce is available.
     */
    public function is_woocommerce_active(): bool {
        return class_exists('WooCommerce', false);
    }
}
