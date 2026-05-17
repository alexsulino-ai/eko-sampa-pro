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
 *
 * ## Alpine load order (non-negotiable)
 *
 * Bundles that register components with `document.addEventListener('alpine:init', …)` and
 * `Alpine.data(…)` must **not** list {@see self::HANDLE_ALPINE} in `wp_register_script` deps.
 * WordPress prints dependencies first; if Alpine runs before those bundles, `alpine:init` has
 * already fired and components never register (dead UI, no REST calls).
 *
 * Rule: **register listeners first, Alpine last** — enqueue `HANDLE_FRONTEND_APP` and
 * `HANDLE_EDITOR_CANVAS` (and any future `alpine:init` modules) **before** `HANDLE_ALPINE`.
 * Never add Alpine as a dependency of those bundles; keep Alpine’s own deps empty unless
 * required otherwise.
 *
 * ## Asset URL audit (theme / broken tree)
 *
 * This class only registers **plugin** URLs (`EKO_SAMPA_PLUGIN_URL` + `*.js` / `*.css`) or
 * full CDN URLs. It never calls `get_stylesheet_directory_uri()` / `get_template_directory_uri()`.
 * A request such as `/wp-content/themes/storefront/?ver=…` (theme directory as script) comes
 * from the **active theme** or **another plugin**, not from here.
 *
 * Optional debug (off unless explicitly enabled in `wp-config.php`):
 *
 *     define('EKO_SAMPA_DEBUG', true);
 *     define('EKO_SAMPA_DEBUG_ENQUEUES', true); // requires EKO_SAMPA_DEBUG
 *     define('EKO_SAMPA_DEBUG_ENQUEUES_DEEP', true); // huge log; requires both above
 */
final class Eko_Sampa_Assets {

    /**
     * Public URL for a file under this plugin directory (robust vs filtered `plugin_dir_url`).
     */
    private function plugin_asset_url(string $relative_path): string {
        $relative_path = ltrim(str_replace('\\', '/', $relative_path), '/');

        return plugins_url($relative_path, EKO_SAMPA_PLUGIN_FILE);
    }

    /**
     * Cache-bust string for plugin files (mtime when readable, else plugin version).
     */
    private function plugin_asset_version(string $relative_path): string {
        $full = EKO_SAMPA_PLUGIN_DIR . ltrim(str_replace('\\', '/', $relative_path), '/');
        if (is_readable($full)) {
            $m = @filemtime($full);
            if (is_int($m) && $m > 0) {
                return (string) $m;
            }
        }

        return EKO_SAMPA_VERSION;
    }

    public const HANDLE_ADMIN_STYLE = 'eko-sampa-admin';

    public const HANDLE_ADMIN_SCRIPT = 'eko-sampa-admin';

    public const HANDLE_TAILWIND = 'eko-sampa-tailwind';

    public const HANDLE_ALPINE = 'eko-sampa-alpine';

    public const HANDLE_INTERACT = 'eko-sampa-interact';

    public const HANDLE_EDITOR_CANVAS = 'eko-sampa-editor-canvas';

    public const HANDLE_FRONTEND_APP = 'eko-sampa-frontend-app';

    public const HANDLE_EKO_UI = 'eko-sampa-ui';

    public const HANDLE_EKO_TOAST = 'eko-sampa-toast';

    public const HANDLE_EKO_STORE = 'eko-sampa-store';

    public const HANDLE_EKO_FIELD_REGISTRY = 'eko-sampa-field-registry';

    public const HANDLE_SORTABLE = 'eko-sampa-sortable';

    public const HANDLE_DESIGN_SYSTEM = 'eko-sampa-design-system';

    public const HANDLE_FRONTEND_STYLE = 'eko-sampa-frontend';

    public const HANDLE_EKO_VISUAL_RENDER_CONTRACT = 'eko-sampa-visual-render-contract';

    public const HANDLE_EKO_CANVAS_RENDERER = 'eko-sampa-canvas-renderer';

    public const HANDLE_EKO_PRINT_MOUNT = 'eko-sampa-print-mount';

    public const HANDLE_EKO_PRINT_CSS = 'eko-sampa-print';

    public const HANDLE_EKO_THUMBNAIL_CONFIG = 'eko-sampa-thumbnail-config';

    public const HANDLE_EKO_THUMBNAIL_VISUAL = 'eko-sampa-thumbnail-visual';

    public const HANDLE_EKO_THUMBNAIL_HISTORY = 'eko-sampa-thumbnail-history';

    public const HANDLE_HTML_TO_IMAGE = 'html-to-image';

    public const HANDLE_EKO_THUMBNAIL_EXPORT = 'eko-sampa-thumbnail-export';

    public const HANDLE_EKO_TEMPLATES_GALLERY = 'eko-sampa-templates-gallery';

    public function register_hooks(): void {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend'], 20);
        add_filter('body_class', [$this, 'filter_body_class']);
        if ($this->debug_enqueue_audit_enabled()) {
            add_action('wp_print_scripts', [$this, 'debug_log_registered_scripts'], 99);
            add_action('wp_print_styles', [$this, 'debug_log_registered_styles'], 99);
            add_action('admin_print_scripts', [$this, 'debug_log_registered_scripts'], 99);
            add_action('admin_print_styles', [$this, 'debug_log_registered_styles'], 99);
        }
    }

    private function debug_enqueue_audit_enabled(): bool {
        return defined('EKO_SAMPA_DEBUG')
            && EKO_SAMPA_DEBUG
            && defined('EKO_SAMPA_DEBUG_ENQUEUES')
            && EKO_SAMPA_DEBUG_ENQUEUES;
    }

    /**
     * Enqueue only on plugin admin screens or WooCommerce product editor when WC is active.
     *
     * On the editor canvas screen: Sortable → Interact → editor-canvas → **Alpine last**
     * (same contract as {@see self::enqueue_frontend()}).
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
            if (wp_script_is(self::HANDLE_SORTABLE, 'registered')) {
                wp_enqueue_script(self::HANDLE_SORTABLE);
            }
            if (wp_script_is(self::HANDLE_INTERACT, 'registered')) {
                wp_enqueue_script(self::HANDLE_INTERACT);
            }
            if (wp_script_is(self::HANDLE_EKO_CANVAS_RENDERER, 'registered')) {
                wp_enqueue_script(self::HANDLE_EKO_CANVAS_RENDERER);
            }
            if (wp_script_is(self::HANDLE_EKO_THUMBNAIL_EXPORT, 'registered')) {
                wp_enqueue_script(self::HANDLE_EKO_THUMBNAIL_EXPORT);
            }
            if (wp_script_is(self::HANDLE_EDITOR_CANVAS, 'registered')) {
                wp_enqueue_script(self::HANDLE_EDITOR_CANVAS);
                wp_localize_script(
                    self::HANDLE_EDITOR_CANVAS,
                    'ekoSampaEditor',
                    [
                        'templateId'    => isset($_GET['template_id']) ? absint((int) $_GET['template_id']) : 0,
                        'root'          => esc_url_raw(rest_url('eko-sampa/v1/')),
                        'nonce'         => wp_create_nonce('wp_rest'),
                        'pluginVersion' => EKO_SAMPA_VERSION,
                    ]
                );
            }
            // Alpine last: editor-canvas registers alpine:init before Alpine boots (class docblock).
            if (wp_script_is(self::HANDLE_ALPINE, 'registered')) {
                wp_enqueue_script(self::HANDLE_ALPINE);
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
                $this->plugin_asset_url($css_rel),
                [],
                $this->plugin_asset_version($css_rel)
            );
        }

        if (is_readable($js)) {
            wp_register_script(
                self::HANDLE_ADMIN_SCRIPT,
                $this->plugin_asset_url($js_rel),
                [],
                $this->plugin_asset_version($js_rel),
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

        $ui_rel = 'assets/js/eko-ui.js';
        $ui     = EKO_SAMPA_PLUGIN_DIR . $ui_rel;
        if (is_readable($ui)) {
            wp_register_script(
                self::HANDLE_EKO_UI,
                $this->plugin_asset_url($ui_rel),
                [],
                $this->plugin_asset_version($ui_rel),
                true
            );
        }

        $store_rel = 'assets/js/eko-store.js';
        if (is_readable(EKO_SAMPA_PLUGIN_DIR . $store_rel)) {
            wp_register_script(
                self::HANDLE_EKO_STORE,
                $this->plugin_asset_url($store_rel),
                [],
                $this->plugin_asset_version($store_rel),
                true
            );
        }

        $toast_rel = 'assets/js/eko-toast.js';
        if (is_readable(EKO_SAMPA_PLUGIN_DIR . $toast_rel)) {
            $toast_deps = [];
            if (wp_script_is(self::HANDLE_EKO_UI, 'registered')) {
                $toast_deps[] = self::HANDLE_EKO_UI;
            }
            if (wp_script_is(self::HANDLE_EKO_STORE, 'registered')) {
                $toast_deps[] = self::HANDLE_EKO_STORE;
            }
            wp_register_script(
                self::HANDLE_EKO_TOAST,
                $this->plugin_asset_url($toast_rel),
                $toast_deps,
                $this->plugin_asset_version($toast_rel),
                true
            );
        }

        $fe_app_rel = 'assets/js/frontend-app.js';
        $fe_app     = EKO_SAMPA_PLUGIN_DIR . $fe_app_rel;
        if (is_readable($fe_app)) {
            /**
             * Must load before Alpine: the bundle registers `alpine:init` listeners that call
             * `Alpine.data(...)`. If Alpine runs first, `alpine:init` has already fired and CRUD
             * components never register (UI looks static; saves never run).
             */
            $fe_deps = [];
            if (wp_script_is(self::HANDLE_EKO_UI, 'registered')) {
                $fe_deps[] = self::HANDLE_EKO_UI;
            }
            if (wp_script_is(self::HANDLE_EKO_TOAST, 'registered')) {
                $fe_deps[] = self::HANDLE_EKO_TOAST;
            }
            wp_register_script(
                self::HANDLE_FRONTEND_APP,
                $this->plugin_asset_url($fe_app_rel),
                $fe_deps,
                $this->plugin_asset_version($fe_app_rel),
                true
            );
        }

        $registry_rel = 'assets/js/eko-field-registry.js';
        if (is_readable(EKO_SAMPA_PLUGIN_DIR . $registry_rel)) {
            wp_register_script(
                self::HANDLE_EKO_FIELD_REGISTRY,
                $this->plugin_asset_url($registry_rel),
                [self::HANDLE_FRONTEND_APP],
                $this->plugin_asset_version($registry_rel),
                true
            );
        }

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

        $html_to_image_rel = 'assets/vendor/html-to-image/html-to-image.js';
        if (is_readable(EKO_SAMPA_PLUGIN_DIR . $html_to_image_rel)) {
            wp_register_script(
                self::HANDLE_HTML_TO_IMAGE,
                $this->plugin_asset_url($html_to_image_rel),
                [],
                $this->plugin_asset_version($html_to_image_rel),
                true
            );
        }

        $thumb_config_rel = 'assets/js/eko-thumbnail-config.js';
        if (is_readable(EKO_SAMPA_PLUGIN_DIR . $thumb_config_rel)) {
            wp_register_script(
                self::HANDLE_EKO_THUMBNAIL_CONFIG,
                $this->plugin_asset_url($thumb_config_rel),
                [],
                $this->plugin_asset_version($thumb_config_rel),
                true
            );
        }

        $thumb_visual_rel = 'assets/js/eko-thumbnail-visual.js';
        if (is_readable(EKO_SAMPA_PLUGIN_DIR . $thumb_visual_rel)) {
            wp_register_script(
                self::HANDLE_EKO_THUMBNAIL_VISUAL,
                $this->plugin_asset_url($thumb_visual_rel),
                [self::HANDLE_EKO_THUMBNAIL_CONFIG],
                $this->plugin_asset_version($thumb_visual_rel),
                true
            );
        }

        $thumb_history_rel = 'assets/js/eko-thumbnail-history.js';
        if (is_readable(EKO_SAMPA_PLUGIN_DIR . $thumb_history_rel)) {
            wp_register_script(
                self::HANDLE_EKO_THUMBNAIL_HISTORY,
                $this->plugin_asset_url($thumb_history_rel),
                [self::HANDLE_EKO_THUMBNAIL_CONFIG],
                $this->plugin_asset_version($thumb_history_rel),
                true
            );
        }

        $vrc_rel = 'assets/js/eko-visual-render-contract.js';
        if (is_readable(EKO_SAMPA_PLUGIN_DIR . $vrc_rel)) {
            wp_register_script(
                self::HANDLE_EKO_VISUAL_RENDER_CONTRACT,
                $this->plugin_asset_url($vrc_rel),
                [],
                $this->plugin_asset_version($vrc_rel),
                true
            );
        }

        $renderer_rel = 'assets/js/eko-canvas-renderer.js';
        if (is_readable(EKO_SAMPA_PLUGIN_DIR . $renderer_rel)) {
            $renderer_deps = [];
            if (wp_script_is(self::HANDLE_EKO_VISUAL_RENDER_CONTRACT, 'registered')) {
                $renderer_deps[] = self::HANDLE_EKO_VISUAL_RENDER_CONTRACT;
            }
            wp_register_script(
                self::HANDLE_EKO_CANVAS_RENDERER,
                $this->plugin_asset_url($renderer_rel),
                $renderer_deps,
                $this->plugin_asset_version($renderer_rel),
                true
            );
        }

        $print_mount_rel = 'assets/js/eko-print-mount.js';
        if (is_readable(EKO_SAMPA_PLUGIN_DIR . $print_mount_rel)) {
            $print_mount_deps = wp_script_is(self::HANDLE_EKO_CANVAS_RENDERER, 'registered')
                ? [self::HANDLE_EKO_CANVAS_RENDERER]
                : [];
            wp_register_script(
                self::HANDLE_EKO_PRINT_MOUNT,
                $this->plugin_asset_url($print_mount_rel),
                $print_mount_deps,
                $this->plugin_asset_version($print_mount_rel),
                true
            );
        }

        $print_css_rel = 'assets/css/eko-print.css';
        if (is_readable(EKO_SAMPA_PLUGIN_DIR . $print_css_rel)) {
            $print_css_deps = wp_style_is(self::HANDLE_DESIGN_SYSTEM, 'registered')
                ? [self::HANDLE_DESIGN_SYSTEM]
                : [];
            wp_register_style(
                self::HANDLE_EKO_PRINT_CSS,
                $this->plugin_asset_url($print_css_rel),
                $print_css_deps,
                $this->plugin_asset_version($print_css_rel)
            );
        }

        $thumb_export_rel = 'assets/js/eko-thumbnail-export.js';
        if (is_readable(EKO_SAMPA_PLUGIN_DIR . $thumb_export_rel)) {
            $thumb_deps = [];
            if (wp_script_is(self::HANDLE_EKO_THUMBNAIL_HISTORY, 'registered')) {
                $thumb_deps[] = self::HANDLE_EKO_THUMBNAIL_HISTORY;
            }
            if (wp_script_is(self::HANDLE_EKO_THUMBNAIL_VISUAL, 'registered')) {
                $thumb_deps[] = self::HANDLE_EKO_THUMBNAIL_VISUAL;
            }
            if (wp_script_is(self::HANDLE_EKO_THUMBNAIL_CONFIG, 'registered')) {
                $thumb_deps[] = self::HANDLE_EKO_THUMBNAIL_CONFIG;
            }
            if (wp_script_is(self::HANDLE_EKO_CANVAS_RENDERER, 'registered')) {
                $thumb_deps[] = self::HANDLE_EKO_CANVAS_RENDERER;
            }
            if (wp_script_is(self::HANDLE_HTML_TO_IMAGE, 'registered')) {
                $thumb_deps[] = self::HANDLE_HTML_TO_IMAGE;
            }
            wp_register_script(
                self::HANDLE_EKO_THUMBNAIL_EXPORT,
                $this->plugin_asset_url($thumb_export_rel),
                $thumb_deps,
                $this->plugin_asset_version($thumb_export_rel),
                true
            );
        }

        $gallery_css_rel = 'assets/css/eko-templates-gallery.css';
        if (is_readable(EKO_SAMPA_PLUGIN_DIR . $gallery_css_rel)) {
            wp_register_style(
                self::HANDLE_EKO_TEMPLATES_GALLERY,
                $this->plugin_asset_url($gallery_css_rel),
                [],
                $this->plugin_asset_version($gallery_css_rel)
            );
        }

        $editor_js_rel = 'assets/js/editor-canvas.js';
        $editor_js     = EKO_SAMPA_PLUGIN_DIR . $editor_js_rel;
        if (is_readable($editor_js)) {
            /**
             * No Alpine handle in deps: this file must execute before Alpine so `alpine:init`
             * listeners are registered (same race as frontend-app.js).
             */
            $editor_deps = [self::HANDLE_INTERACT, self::HANDLE_SORTABLE];
            if (wp_script_is(self::HANDLE_EKO_VISUAL_RENDER_CONTRACT, 'registered')) {
                $editor_deps[] = self::HANDLE_EKO_VISUAL_RENDER_CONTRACT;
            }
            if (wp_script_is(self::HANDLE_EKO_CANVAS_RENDERER, 'registered')) {
                $editor_deps[] = self::HANDLE_EKO_CANVAS_RENDERER;
            }
            if (wp_script_is(self::HANDLE_EKO_THUMBNAIL_EXPORT, 'registered')) {
                $editor_deps[] = self::HANDLE_EKO_THUMBNAIL_EXPORT;
            }
            wp_register_script(
                self::HANDLE_EDITOR_CANVAS,
                $this->plugin_asset_url($editor_js_rel),
                $editor_deps,
                $this->plugin_asset_version($editor_js_rel),
                true
            );
        }

        $ds_css_rel = 'assets/css/eko-design-system.css';
        $ds_css     = EKO_SAMPA_PLUGIN_DIR . $ds_css_rel;
        if (is_readable($ds_css)) {
            wp_register_style(
                self::HANDLE_DESIGN_SYSTEM,
                $this->plugin_asset_url($ds_css_rel),
                [],
                $this->plugin_asset_version($ds_css_rel)
            );
        }

        $fe_css_rel = 'assets/css/frontend.css';
        $fe_css     = EKO_SAMPA_PLUGIN_DIR . $fe_css_rel;
        $fe_deps    = wp_style_is(self::HANDLE_DESIGN_SYSTEM, 'registered') ? [self::HANDLE_DESIGN_SYSTEM] : [];
        if (is_readable($fe_css)) {
            wp_register_style(
                self::HANDLE_FRONTEND_STYLE,
                $this->plugin_asset_url($fe_css_rel),
                $fe_deps,
                $this->plugin_asset_version($fe_css_rel)
            );
        }
    }

    /**
     * Public frontend: virtual routes or pages that embed Eko shortcodes.
     *
     * Script order: Tailwind → REST/localized bundles (`HANDLE_FRONTEND_APP`) → editor stack
     * (Sortable, Interact, `HANDLE_EDITOR_CANVAS` when on editor) → **Alpine last** (see class doc).
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
            $boot = $this->eko_modules_boot_payload_for_inline();
            wp_add_inline_script(
                self::HANDLE_TAILWIND,
                'window.EkoModules=Object.assign(window.EkoModules||{},'
                    . wp_json_encode($boot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    . ');',
                'after'
            );
        }

        if ($this->should_enqueue_frontend_rest_bundle()) {
            if (wp_script_is(self::HANDLE_EKO_UI, 'registered')) {
                wp_enqueue_script(self::HANDLE_EKO_UI);
            }
            if (wp_script_is(self::HANDLE_EKO_STORE, 'registered')) {
                wp_enqueue_script(self::HANDLE_EKO_STORE);
            }
            if (wp_script_is(self::HANDLE_EKO_TOAST, 'registered')) {
                wp_enqueue_script(self::HANDLE_EKO_TOAST);
            }
            $fe_app_path = EKO_SAMPA_PLUGIN_DIR . 'assets/js/frontend-app.js';
            if (wp_script_is(self::HANDLE_FRONTEND_APP, 'registered') && is_readable($fe_app_path)) {
                wp_enqueue_script(self::HANDLE_FRONTEND_APP);
                wp_localize_script(
                    self::HANDLE_FRONTEND_APP,
                    'ekoSampaRest',
                    [
                        'root'           => esc_url_raw(rest_url('eko-sampa/v1/')),
                        'nonce'          => wp_create_nonce('wp_rest'),
                        'isAdmin'        => current_user_can('manage_options'),
                        'capabilities'   => function_exists('eko_sampa_frontend_capabilities')
                            ? eko_sampa_frontend_capabilities()
                            : [],
                        'pluginVersion'  => EKO_SAMPA_VERSION,
                        'debugRest'      => (defined('EKO_SAMPA_DEBUG') && EKO_SAMPA_DEBUG),
                        'urls'           => [
                            'editor' => Eko_Sampa_Frontend_Router::get_url('editor'),
                            'print'  => Eko_Sampa_Frontend_Router::get_url('print'),
                            'orders' => Eko_Sampa_Frontend_Router::get_resource_url('orders', 'list'),
                        ],
                        'strings'        => [
                            'orderTemplateRequired' => __('Select a template for this order.', 'eko-sampa'),
                        ],
                        'crud'           => Eko_Sampa_Frontend_Router::current_crud_context(),
                    ]
                );
            }
            if (wp_script_is(self::HANDLE_EKO_FIELD_REGISTRY, 'registered')) {
                wp_enqueue_script(self::HANDLE_EKO_FIELD_REGISTRY);
            }
        }

        if ($this->is_frontend_templates_crud_view()) {
            if (wp_style_is(self::HANDLE_EKO_TEMPLATES_GALLERY, 'registered')) {
                wp_enqueue_style(self::HANDLE_EKO_TEMPLATES_GALLERY);
            }
        }
        if ($this->is_frontend_templates_list_view()) {
            if (wp_script_is(self::HANDLE_EKO_CANVAS_RENDERER, 'registered')) {
                wp_enqueue_script(self::HANDLE_EKO_CANVAS_RENDERER);
            }
            if (wp_script_is(self::HANDLE_HTML_TO_IMAGE, 'registered')) {
                wp_enqueue_script(self::HANDLE_HTML_TO_IMAGE);
            }
            if (wp_script_is(self::HANDLE_EKO_THUMBNAIL_CONFIG, 'registered')) {
                wp_enqueue_script(self::HANDLE_EKO_THUMBNAIL_CONFIG);
            }
            if (wp_script_is(self::HANDLE_EKO_THUMBNAIL_VISUAL, 'registered')) {
                wp_enqueue_script(self::HANDLE_EKO_THUMBNAIL_VISUAL);
            }
            if (wp_script_is(self::HANDLE_EKO_THUMBNAIL_HISTORY, 'registered')) {
                wp_enqueue_script(self::HANDLE_EKO_THUMBNAIL_HISTORY);
            }
            if (wp_script_is(self::HANDLE_EKO_THUMBNAIL_EXPORT, 'registered')) {
                wp_enqueue_script(self::HANDLE_EKO_THUMBNAIL_EXPORT);
            }
        }

        if ($this->is_frontend_editor_view()) {
            if (wp_script_is(self::HANDLE_EKO_CANVAS_RENDERER, 'registered')) {
                wp_enqueue_script(self::HANDLE_EKO_CANVAS_RENDERER);
            }
            if (wp_script_is(self::HANDLE_HTML_TO_IMAGE, 'registered')) {
                wp_enqueue_script(self::HANDLE_HTML_TO_IMAGE);
            }
            if (wp_script_is(self::HANDLE_EKO_THUMBNAIL_CONFIG, 'registered')) {
                wp_enqueue_script(self::HANDLE_EKO_THUMBNAIL_CONFIG);
            }
            if (wp_script_is(self::HANDLE_EKO_THUMBNAIL_VISUAL, 'registered')) {
                wp_enqueue_script(self::HANDLE_EKO_THUMBNAIL_VISUAL);
            }
            if (wp_script_is(self::HANDLE_EKO_THUMBNAIL_HISTORY, 'registered')) {
                wp_enqueue_script(self::HANDLE_EKO_THUMBNAIL_HISTORY);
            }
            if (wp_script_is(self::HANDLE_EKO_THUMBNAIL_EXPORT, 'registered')) {
                wp_enqueue_script(self::HANDLE_EKO_THUMBNAIL_EXPORT);
            }
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
                        'templateId'     => $tid,
                        'root'           => esc_url_raw(rest_url('eko-sampa/v1/')),
                        'nonce'          => wp_create_nonce('wp_rest'),
                        'pluginVersion'  => EKO_SAMPA_VERSION,
                    ]
                );
            }
        } elseif ($this->is_frontend_orders_view()) {
            if (wp_script_is(self::HANDLE_SORTABLE, 'registered')) {
                wp_enqueue_script(self::HANDLE_SORTABLE);
            }
            if (wp_script_is(self::HANDLE_INTERACT, 'registered')) {
                wp_enqueue_script(self::HANDLE_INTERACT);
            }
            if (wp_script_is(self::HANDLE_EDITOR_CANVAS, 'registered')) {
                wp_enqueue_script(self::HANDLE_EDITOR_CANVAS);
            }
        } elseif ($this->is_frontend_services_view()) {
            if (wp_script_is(self::HANDLE_SORTABLE, 'registered')) {
                wp_enqueue_script(self::HANDLE_SORTABLE);
            }
        } elseif ($this->is_frontend_print_view()) {
            if (wp_style_is(self::HANDLE_DESIGN_SYSTEM, 'registered')) {
                wp_enqueue_style(self::HANDLE_DESIGN_SYSTEM);
            }
            if (wp_style_is(self::HANDLE_EKO_PRINT_CSS, 'registered')) {
                wp_enqueue_style(self::HANDLE_EKO_PRINT_CSS);
            }
            if (wp_script_is(self::HANDLE_EKO_VISUAL_RENDER_CONTRACT, 'registered')) {
                wp_enqueue_script(self::HANDLE_EKO_VISUAL_RENDER_CONTRACT);
            }
            if (wp_script_is(self::HANDLE_EKO_CANVAS_RENDERER, 'registered')) {
                wp_enqueue_script(self::HANDLE_EKO_CANVAS_RENDERER);
            }
            if (wp_script_is(self::HANDLE_EKO_PRINT_MOUNT, 'registered')) {
                wp_enqueue_script(self::HANDLE_EKO_PRINT_MOUNT);
                wp_localize_script(
                    self::HANDLE_EKO_PRINT_MOUNT,
                    'ekoSampaRender',
                    [
                        'schemaVersion' => Eko_Sampa_Render_Schema::VERSION,
                        'units'         => Eko_Sampa_Render_Schema::units_meta(),
                        'debug'         => (defined('EKO_SAMPA_DEBUG') && EKO_SAMPA_DEBUG)
                            || (isset($_GET['eko_render_debug']) && (string) $_GET['eko_render_debug'] === '1'),
                    ]
                );
            }
        }

        // Alpine last: alpine:init listeners must already be attached (class docblock).
        // Print view uses vanilla mount script only (no Alpine components).
        if (! $this->is_frontend_print_view() && wp_script_is(self::HANDLE_ALPINE, 'registered')) {
            wp_enqueue_script(self::HANDLE_ALPINE);
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

        if ($this->is_frontend_print_view()) {
            $classes[] = 'eko-sampa-print-page';
        }

        if ($this->singular_has_eko_shortcode()) {
            $classes[] = 'eko-sampa-shortcode';
        }

        return $classes;
    }

    private function should_enqueue_frontend_assets(): bool {
        return $this->is_frontend_virtual_route() || $this->singular_has_eko_shortcode();
    }

    /**
     * JSON payload for inline boot script: explains whether WordPress will enqueue frontend-app.js.
     * If the browser never merges this into window.EkoModules, this enqueue path did not run.
     *
     * @return array<string, mixed>
     */
    private function eko_modules_boot_payload_for_inline(): array {
        $fe_rel = 'assets/js/frontend-app.js';
        $fe_full = EKO_SAMPA_PLUGIN_DIR . $fe_rel;
        $readable = is_readable($fe_full);
        $registered = wp_script_is(self::HANDLE_FRONTEND_APP, 'registered');
        $want = $this->should_enqueue_frontend_rest_bundle();
        $will = $want && $readable && $registered;
        $src = ($readable && $registered) ? $this->plugin_asset_url($fe_rel) : '';

        $skip = '';
        if (! $want) {
            if ($this->is_frontend_virtual_route()) {
                $v = sanitize_key((string) get_query_var(Eko_Sampa_Frontend_Router::QUERY_VIEW));
                if ($v === 'login' || $v === 'print') {
                    $skip = 'virtual_route_' . $v . '_skips_rest_bundle';
                } elseif ($v === '') {
                    $skip = 'virtual_route_empty_view';
                } else {
                    $skip = 'virtual_route_rest_bundle_false';
                }
            } elseif ($this->singular_has_eko_shortcode() && ! is_user_logged_in()) {
                $skip = 'shortcode_requires_login_for_rest_bundle';
            } else {
                $skip = 'no_virtual_route_and_no_logged_shortcode';
            }
        } elseif (! $readable) {
            $skip = 'frontend_app_js_not_readable_on_server';
        } elseif (! $registered) {
            $skip = 'frontend_app_handle_not_registered';
        }

        $view = '';
        if ($this->is_frontend_virtual_route()) {
            $view = sanitize_key((string) get_query_var(Eko_Sampa_Frontend_Router::QUERY_VIEW));
        }

        return [
            'fromPhp' => true,
            'expectFrontendAppEnqueue' => $will,
            'frontendAppReadable' => $readable,
            'frontendAppRegistered' => $registered,
            'wantRestBundle' => $want,
            'frontendAppSrc' => $src,
            'skipReason' => $skip,
            'view' => $view,
            'ts' => (int) round(microtime(true) * 1000),
        ];
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

    private function is_frontend_orders_view(): bool {
        if (! $this->is_frontend_virtual_route()) {
            return false;
        }

        return sanitize_key((string) get_query_var(Eko_Sampa_Frontend_Router::QUERY_VIEW)) === 'orders';
    }

    private function is_frontend_services_view(): bool {
        if (! $this->is_frontend_virtual_route()) {
            return false;
        }

        if (sanitize_key((string) get_query_var(Eko_Sampa_Frontend_Router::QUERY_VIEW)) !== 'services') {
            return false;
        }

        return Eko_Sampa_Frontend_Router::current_action() === 'edit';
    }

    private function is_frontend_templates_crud_view(): bool {
        if (! $this->is_frontend_virtual_route()) {
            return false;
        }

        return sanitize_key((string) get_query_var(Eko_Sampa_Frontend_Router::QUERY_VIEW)) === 'templates';
    }

    private function is_frontend_templates_list_view(): bool {
        if (! $this->is_frontend_templates_crud_view()) {
            return false;
        }

        return Eko_Sampa_Frontend_Router::current_action() === 'list';
    }

    private function is_frontend_print_view(): bool {
        if (! $this->is_frontend_virtual_route()) {
            return false;
        }

        return sanitize_key((string) get_query_var(Eko_Sampa_Frontend_Router::QUERY_VIEW)) === 'print';
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

    /**
     * Detects theme-directory URLs used as a script `src` (e.g. …/themes/storefront/?ver=6.9.4).
     */
    private static function dependency_src_looks_like_theme_directory_without_file(string $src): bool {
        $src = trim($src);
        if ($src === '') {
            return false;
        }

        return preg_match('#/wp-content/themes/[^/]+/\?#', $src) === 1;
    }

    /**
     * @param \WP_Scripts|\WP_Styles $registry
     */
    private function debug_log_dependency_registry(object $registry, string $label): void {
        if (! $this->debug_enqueue_audit_enabled()) {
            return;
        }

        if (defined('EKO_SAMPA_DEBUG_ENQUEUES_DEEP') && EKO_SAMPA_DEBUG_ENQUEUES_DEEP) {
            error_log('eko_sampa: ' . $label . ' registered dump (DEEP) follows');
            error_log(print_r($registry->registered, true));

            return;
        }

        foreach ($registry->registered as $handle => $obj) {
            if (! is_object($obj) || ! isset($obj->src)) {
                continue;
            }
            $src = (string) $obj->src;
            if ($src === '') {
                continue;
            }
            if (self::dependency_src_looks_like_theme_directory_without_file($src)) {
                error_log(sprintf('eko_sampa: [%s] suspicious src handle=%s src=%s', $label, (string) $handle, $src));
            }
        }
    }

    public function debug_log_registered_scripts(): void {
        global $wp_scripts;
        if (! $wp_scripts instanceof WP_Scripts) {
            return;
        }
        $this->debug_log_dependency_registry($wp_scripts, 'scripts');
    }

    public function debug_log_registered_styles(): void {
        global $wp_styles;
        if (! $wp_styles instanceof WP_Styles) {
            return;
        }
        $this->debug_log_dependency_registry($wp_styles, 'styles');
    }
}
