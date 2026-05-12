<?php
/**
 * Register and conditionally enqueue scripts and styles.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Asset registration and conditional enqueue.
 */
final class Eko_Sampa_Assets {

    public const HANDLE_ADMIN_STYLE = 'eko-sampa-admin';

    public const HANDLE_ADMIN_SCRIPT = 'eko-sampa-admin';

    public const HANDLE_TAILWIND = 'eko-sampa-tailwind';

    public const HANDLE_ALPINE = 'eko-sampa-alpine';

    public const HANDLE_INTERACT = 'eko-sampa-interact';

    public const HANDLE_EDITOR_CANVAS = 'eko-sampa-editor-canvas';

    public const HANDLE_FRONTEND_APP = 'eko-sampa-frontend-app';

    public const HANDLE_SORTABLE = 'eko-sampa-sortable';

    public const HANDLE_FRONTEND_STYLE = 'eko-sampa-frontend';

    public function register_hooks(): void {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend'], 20);
        add_filter('body_class', [$this, 'filter_body_class']);
    }

    /**
     * Enqueue only on plugin admin screens or WooCommerce product editor when WC is active.
     *
     * @param string $hook_suffix Current admin page hook suffix.
     */
    public function enqueue_admin(string $hook_suffix): void {
        if (! $this->should_enqueue_admin($hook_suffix)) {
            return;
        }

        $this->register_assets();

        if ($this->is_plugin_admin_screen($hook_suffix)
            && wp_script_is(self::HANDLE_TAILWIND, 'registered')) {
            wp_enqueue_script(self::HANDLE_TAILWIND);
        }

        if ($this->is_editor_canvas_screen($hook_suffix)) {
            if (wp_script_is(self::HANDLE_ALPINE, 'registered')) {
                wp_enqueue_script(self::HANDLE_ALPINE);
            }
            if (wp_script_is(self::HANDLE_SORTABLE, 'registered')) {
                wp_enqueue_script(self::HANDLE_SORTABLE);
            }
            if (wp_script_is(self::HANDLE_INTERACT, 'registered')) {
                wp_enqueue_script(self::HANDLE_INTERACT);
            }
            if (wp_script_is(self::HANDLE_EDITOR_CANVAS, 'registered')) {
                wp_enqueue_script(self::HANDLE_EDITOR_CANVAS);
                wp_localize_script(
                    self::HANDLE_EDITOR_CANVAS,
                    'ekoSampaEditor',
                    [
                        'templateId' => 0,
                        'root'       => esc_url_raw(rest_url('eko-sampa/v1/')),
                        'nonce'      => wp_create_nonce('wp_rest'),
                    ]
                );
            }
        }

        if (wp_style_is(self::HANDLE_ADMIN_STYLE, 'registered')) {
            wp_enqueue_style(self::HANDLE_ADMIN_STYLE);
        }

        if (wp_script_is(self::HANDLE_ADMIN_SCRIPT, 'registered')) {
            wp_enqueue_script(self::HANDLE_ADMIN_SCRIPT);
        }
    }

    /**
     * Register handles once; files are optional until added under assets/.
     */
    private function register_assets(): void {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        $css_rel = 'assets/css/admin.css';
        $js_rel  = 'assets/js/admin.js';
        $css     = EKO_SAMPA_PLUGIN_DIR . $css_rel;
        $js      = EKO_SAMPA_PLUGIN_DIR . $js_rel;

        if (is_readable($css)) {
            wp_register_style(
                self::HANDLE_ADMIN_STYLE,
                EKO_SAMPA_PLUGIN_URL . $css_rel,
                [],
                EKO_SAMPA_VERSION
            );
        }

        if (is_readable($js)) {
            wp_register_script(
                self::HANDLE_ADMIN_SCRIPT,
                EKO_SAMPA_PLUGIN_URL . $js_rel,
                [],
                EKO_SAMPA_VERSION,
                true
            );
        }

        wp_register_script(
            self::HANDLE_TAILWIND,
            'https://cdn.tailwindcss.com',
            [],
            null,
            false
        );

        wp_register_script(
            self::HANDLE_ALPINE,
            'https://cdn.jsdelivr.net/npm/alpinejs@3.13.5/dist/cdn.min.js',
            [],
            '3.13.5',
            true
        );

        wp_register_script(
            self::HANDLE_INTERACT,
            'https://cdn.jsdelivr.net/npm/interactjs@1.10.27/dist/interact.min.js',
            [],
            '1.10.27',
            true
        );

        wp_register_script(
            self::HANDLE_SORTABLE,
            'https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js',
            [],
            '1.15.2',
            true
        );

        $editor_js_rel = 'assets/js/editor-canvas.js';
        $editor_js     = EKO_SAMPA_PLUGIN_DIR . $editor_js_rel;
        if (is_readable($editor_js)) {
            wp_register_script(
                self::HANDLE_EDITOR_CANVAS,
                EKO_SAMPA_PLUGIN_URL . $editor_js_rel,
                [self::HANDLE_ALPINE, self::HANDLE_INTERACT, self::HANDLE_SORTABLE],
                EKO_SAMPA_VERSION,
                true
            );
        }

        $fe_app_rel = 'assets/js/frontend-app.js';
        $fe_app     = EKO_SAMPA_PLUGIN_DIR . $fe_app_rel;
        if (is_readable($fe_app)) {
            wp_register_script(
                self::HANDLE_FRONTEND_APP,
                EKO_SAMPA_PLUGIN_URL . $fe_app_rel,
                [self::HANDLE_ALPINE],
                EKO_SAMPA_VERSION,
                true
            );
        }

        $fe_css_rel = 'assets/css/frontend.css';
        $fe_css     = EKO_SAMPA_PLUGIN_DIR . $fe_css_rel;
        if (is_readable($fe_css)) {
            wp_register_style(
                self::HANDLE_FRONTEND_STYLE,
                EKO_SAMPA_PLUGIN_URL . $fe_css_rel,
                [],
                EKO_SAMPA_VERSION
            );
        }
    }

    /**
     * Public frontend: virtual routes or pages that embed Eko shortcodes.
     */
    public function enqueue_frontend(): void {
        if (! $this->should_enqueue_frontend_assets()) {
            return;
        }

        $this->register_assets();

        if (wp_style_is(self::HANDLE_FRONTEND_STYLE, 'registered')) {
            wp_enqueue_style(self::HANDLE_FRONTEND_STYLE);
        }

        if (wp_script_is(self::HANDLE_TAILWIND, 'registered')) {
            wp_enqueue_script(self::HANDLE_TAILWIND);
        }

        if (wp_script_is(self::HANDLE_ALPINE, 'registered')) {
            wp_enqueue_script(self::HANDLE_ALPINE);
        }

        if ($this->should_enqueue_frontend_rest_bundle()) {
            if (wp_script_is(self::HANDLE_FRONTEND_APP, 'registered')) {
                wp_enqueue_script(self::HANDLE_FRONTEND_APP);
                wp_localize_script(
                    self::HANDLE_FRONTEND_APP,
                    'ekoSampaRest',
                    [
                        'root'    => esc_url_raw(rest_url('eko-sampa/v1/')),
                        'nonce'   => wp_create_nonce('wp_rest'),
                        'isAdmin' => current_user_can('manage_options'),
                        'urls'    => [
                            'editor' => Eko_Sampa_Frontend_Router::get_url('editor'),
                            'print'  => Eko_Sampa_Frontend_Router::get_url('print'),
                        ],
                    ]
                );
            }
        }

        if ($this->is_frontend_editor_view()) {
            if (wp_script_is(self::HANDLE_SORTABLE, 'registered')) {
                wp_enqueue_script(self::HANDLE_SORTABLE);
            }
            if (wp_script_is(self::HANDLE_INTERACT, 'registered')) {
                wp_enqueue_script(self::HANDLE_INTERACT);
            }
            if (wp_script_is(self::HANDLE_EDITOR_CANVAS, 'registered')) {
                wp_enqueue_script(self::HANDLE_EDITOR_CANVAS);
                $tid = isset($_GET['template_id']) ? absint((int) $_GET['template_id']) : 0;
                wp_localize_script(
                    self::HANDLE_EDITOR_CANVAS,
                    'ekoSampaEditor',
                    [
                        'templateId' => $tid,
                        'root'       => esc_url_raw(rest_url('eko-sampa/v1/')),
                        'nonce'      => wp_create_nonce('wp_rest'),
                    ]
                );
            }
        }
    }

    /**
     * @param array<int, string> $classes
     *
     * @return array<int, string>
     */
    public function filter_body_class(array $classes): array {
        if ($this->is_frontend_virtual_route()) {
            $classes[] = 'eko-sampa-route';
        }

        if ($this->singular_has_eko_shortcode()) {
            $classes[] = 'eko-sampa-shortcode';
        }

        return $classes;
    }

    private function should_enqueue_frontend_assets(): bool {
        return $this->is_frontend_virtual_route() || $this->singular_has_eko_shortcode();
    }

    private function should_enqueue_frontend_rest_bundle(): bool {
        if ($this->is_frontend_virtual_route()) {
            $view = sanitize_key((string) get_query_var(Eko_Sampa_Frontend_Router::QUERY_VIEW));

            return $view !== '' && ! in_array($view, ['login', 'print'], true);
        }

        return $this->singular_has_eko_shortcode() && is_user_logged_in();
    }

    private function is_frontend_virtual_route(): bool {
        return (string) get_query_var(Eko_Sampa_Frontend_Router::QUERY_FLAG) === '1';
    }

    private function singular_has_eko_shortcode(): bool {
        if (! is_singular()) {
            return false;
        }

        $post = get_post();
        if (! $post instanceof WP_Post) {
            return false;
        }

        return has_shortcode((string) $post->post_content, 'eko_sampa_shell')
            || has_shortcode((string) $post->post_content, 'eko_sampa_login');
    }

    private function is_frontend_editor_view(): bool {
        if ($this->is_frontend_virtual_route()) {
            return sanitize_key((string) get_query_var(Eko_Sampa_Frontend_Router::QUERY_VIEW)) === 'editor';
        }

        if (! is_singular()) {
            return false;
        }

        $post = get_post();
        if (! $post instanceof WP_Post) {
            return false;
        }

        return (bool) preg_match('/\\[eko_sampa_shell[^\\]]*view=[\\"\']?editor[\\"\']?/i', (string) $post->post_content);
    }

    private function should_enqueue_admin(string $hook_suffix): bool {
        if ($this->is_plugin_admin_screen($hook_suffix)) {
            return true;
        }

        return $this->is_woocommerce_product_screen();
    }

    private function is_plugin_admin_screen(string $hook_suffix): bool {
        if ($hook_suffix === '' || ! str_contains($hook_suffix, 'eko-sampa')) {
            return false;
        }

        return current_user_can('manage_options');
    }

    private function is_editor_canvas_screen(string $hook_suffix): bool {
        if ($hook_suffix === '') {
            return false;
        }

        return str_contains($hook_suffix, Eko_Sampa_Router::EDITOR_SLUG);
    }

    private function is_woocommerce_product_screen(): bool {
        if (! class_exists('WooCommerce', false)) {
            return false;
        }

        if (! function_exists('get_current_screen')) {
            return false;
        }

        $screen = get_current_screen();
        if ($screen === null) {
            return false;
        }

        if ($screen->post_type !== 'product') {
            return false;
        }

        return in_array($screen->base, ['post', 'post-new'], true)
            && current_user_can('edit_products');
    }
}
