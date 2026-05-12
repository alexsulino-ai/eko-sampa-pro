<?php
/**
 * Core plugin singleton and bootstrap.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Main plugin class.
 */
final class Eko_Sampa_Plugin {

    private static ?self $instance = null;

    private Eko_Sampa_Database $database;

    private Eko_Sampa_Assets $assets;

    private Eko_Sampa_Router $router;

    private Eko_Sampa_Roles $roles;

    private Eko_Sampa_Frontend_Router $frontend_router;

    private Eko_Sampa_Admin_Redirect $admin_redirect;

    private Eko_Sampa_Shortcodes $shortcodes;

    private Eko_Sampa_Rest_Api $rest_api;

    private function __construct() {
        $this->database        = new Eko_Sampa_Database();
        $this->assets          = new Eko_Sampa_Assets();
        $this->router          = new Eko_Sampa_Router();
        $this->roles           = new Eko_Sampa_Roles();
        $this->frontend_router = new Eko_Sampa_Frontend_Router();
        $this->admin_redirect  = new Eko_Sampa_Admin_Redirect();
        $this->shortcodes      = new Eko_Sampa_Shortcodes();
        $this->rest_api        = new Eko_Sampa_Rest_Api();
    }

    /**
     * Singleton accessor.
     */
    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Boot hooks after WordPress is loaded.
     */
    public function boot(): void {
        $this->maybe_upgrade_database();
        add_action('init', [$this, 'load_textdomain_on_init'], 1);

        $this->roles->register_hooks();
        $this->frontend_router->register_hooks();
        $this->admin_redirect->register_hooks();
        $this->shortcodes->register_hooks();
        $this->rest_api->register_hooks();
        $this->router->register_hooks();
        $this->assets->register_hooks();

        if (class_exists('WooCommerce', false)) {
            (new Eko_Sampa_Wc_Bridge())->register_hooks();
        }

        /**
         * Fires after Eko Sampa core services are registered.
         *
         * @param Eko_Sampa_Plugin $plugin Main plugin instance.
         */
        do_action('eko_sampa_boot', $this);
    }

    /**
     * Database service.
     */
    public function database(): Eko_Sampa_Database {
        return $this->database;
    }

    /**
     * Assets service.
     */
    public function assets(): Eko_Sampa_Assets {
        return $this->assets;
    }

    /**
     * Router service.
     */
    public function router(): Eko_Sampa_Router {
        return $this->router;
    }

    public function roles(): Eko_Sampa_Roles {
        return $this->roles;
    }

    public function frontend_router(): Eko_Sampa_Frontend_Router {
        return $this->frontend_router;
    }

    /**
     * Run migrations when the stored DB version is behind EKO_SAMPA_DB_VERSION.
     */
    private function maybe_upgrade_database(): void {
        $stored = (string) get_option('eko_sampa_db_version', '0');
        if (version_compare($stored, EKO_SAMPA_DB_VERSION, '>=')) {
            return;
        }

        $this->database->migrate();
    }

    public function load_textdomain_on_init(): void {
        load_plugin_textdomain(
            'eko-sampa',
            false,
            dirname(EKO_SAMPA_PLUGIN_BASENAME) . '/languages'
        );
    }

    private function __clone() {
    }

    /**
     * @throws \Exception Prevent unserialization of singleton.
     */
    public function __wakeup(): void {
        throw new \Exception('Eko_Sampa_Plugin cannot be unserialized.');
    }
}
