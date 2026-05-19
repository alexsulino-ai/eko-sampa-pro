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
            Eko_Sampa_Roles::CAP_MANAGE_EKO_PLATFORM,
            self::MENU_SLUG,
            [$this, 'render_admin_app_shell'],
            'dashicons-art',
            56
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Visual editor', 'eko-sampa'),
            __('Editor', 'eko-sampa'),
            Eko_Sampa_Roles::CAP_MANAGE_TEMPLATES,
            self::EDITOR_SLUG,
            [$this, 'render_editor_canvas']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Users', 'eko-sampa'),
            __('Users', 'eko-sampa'),
            Eko_Sampa_Roles::CAP_MANAGE_EKO_USERS,
            Eko_Sampa_User_Admin::MENU_SLUG,
            [$this, 'render_users_admin_page']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Diagnostics', 'eko-sampa'),
            __('Diagnostics', 'eko-sampa'),
            'manage_options',
            self::MENU_SLUG . '-diagnostics',
            [$this, 'render_diagnostics_page']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Public home & guests', 'eko-sampa'),
            __('Public & guests', 'eko-sampa'),
            Eko_Sampa_Roles::CAP_MANAGE_EKO_PLATFORM,
            self::MENU_SLUG . '-public-experience',
            [$this, 'render_public_experience_page']
        );
    }

    /**
     * Dashboard shell: sidebar, header, content (layout only).
     */
    public function render_admin_app_shell(): void {
        if (! Eko_Sampa_Capabilities::can_access_eko_wp_admin()) {
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
        if (! current_user_can('manage_options') && ! current_user_can(Eko_Sampa_Roles::CAP_MANAGE_TEMPLATES)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'eko-sampa'));
        }

        $view = EKO_SAMPA_PLUGIN_DIR . 'views/editor-canvas.php';
        if (is_readable($view)) {
            require $view;
        }
    }

    /**
     * Database integrity report and repair actions.
     */
    public function render_diagnostics_page(): void {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'eko-sampa'));
        }

        $database  = new Eko_Sampa_Database();
        $integrity = new Eko_Sampa_Database_Integrity($database);
        $notice    = '';
        $inspect_snapshot = null;
        $inspect_sid        = 0;
        $permission_report  = null;

        $storage_report = null;

        $template_schema_report = null;
        $template_schema_repair_result = null;

        if (isset($_POST['eko_sampa_integrity_action'])
            && check_admin_referer('eko_sampa_integrity', 'eko_sampa_integrity_nonce')) {
            $action = sanitize_key((string) wp_unslash($_POST['eko_sampa_integrity_action']));

            if ($action === 'run_check') {
                $database->ensure_schema();
                $integrity->run(false);
                $notice = __('Integrity check completed.', 'eko-sampa');
            } elseif ($action === 'repair_orphans') {
                $database->ensure_schema();
                $integrity->run(true);
                $notice = __('Orphan template→service links were cleared (service_id set to 0).', 'eko-sampa');
            } elseif ($action === 'inspect_service_delete') {
                $inspect_sid = absint((int) wp_unslash($_POST['service_id'] ?? 0));
                if ($inspect_sid > 0) {
                    $inspect_snapshot = Eko_Sampa_Service_Relations_Inspector::inspect_for($inspect_sid);
                    $notice           = __('Service delete inspection completed.', 'eko-sampa');
                } else {
                    $notice = __('Enter a valid numeric service ID.', 'eko-sampa');
                }
            } elseif ($action === 'storage_integrity_report') {
                $storage_report = Eko_Sampa_Storage_Manager::build_storage_integrity_report();
                $notice           = __('Storage integrity report generated (read-only).', 'eko-sampa');
            } elseif ($action === 'template_schema_simulate') {
                $database->ensure_schema();
                $template_schema_report = [
                    'diagnostics' => Eko_Sampa_Template_Schema_Diagnostics::analyze(),
                    'repair_preview' => (new Eko_Sampa_Database())->preview_templates_legacy_hybrid_relaxed_defaults(),
                ];
                $notice = __('Template schema integrity report generated (read-only).', 'eko-sampa');
            } elseif ($action === 'template_schema_repair') {
                $database->ensure_schema();
                $template_schema_repair_result = Eko_Sampa_Template_Schema_Repair::run_relaxed_defaults((int) get_current_user_id());
                $template_schema_report       = [
                    'diagnostics' => Eko_Sampa_Template_Schema_Diagnostics::analyze(),
                    'repair_preview' => (new Eko_Sampa_Database())->preview_templates_legacy_hybrid_relaxed_defaults(),
                ];
                $notice = ! empty($template_schema_repair_result['ok'])
                    ? __('Template schema repair completed.', 'eko-sampa')
                    : __('Template schema repair failed — see wpdb_error in JSON.', 'eko-sampa');
            }
        }

        $report = $integrity->last_report();
        if ($report === []) {
            $report = $integrity->run(false);
        }

        $view = EKO_SAMPA_PLUGIN_DIR . 'views/admin-diagnostics.php';
        if (is_readable($view)) {
            $delete_audit = get_option('eko_sampa_service_delete_audit', []);
            if (! is_array($delete_audit)) {
                $delete_audit = [];
            }
            require $view;
        }
    }

    public function render_users_admin_page(): void {
        if (! Eko_Sampa_Capabilities::can_manage_eko_users_screen()) {
            wp_die(esc_html__('You do not have permission to access this page.', 'eko-sampa'));
        }

        $uid = isset($_GET['user_id']) ? absint((int) $_GET['user_id']) : 0;
        if ($uid > 0 && current_user_can('edit_user', $uid)) {
            $view = EKO_SAMPA_PLUGIN_DIR . 'views/admin-user-detail.php';
            if (is_readable($view)) {
                require $view;

                return;
            }
        }

        $view = EKO_SAMPA_PLUGIN_DIR . 'views/admin-users-list.php';
        if (is_readable($view)) {
            require $view;
        }
    }

    public function render_public_experience_page(): void {
        if (! Eko_Sampa_Capabilities::can_access_eko_wp_admin()) {
            wp_die(esc_html__('You do not have permission to access this page.', 'eko-sampa'));
        }

        $view = EKO_SAMPA_PLUGIN_DIR . 'views/admin-public-experience.php';
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
