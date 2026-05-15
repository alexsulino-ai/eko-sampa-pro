<?php
/**
 * Plugin Name:       Eko Sampa
 * Plugin URI:        https://example.com/eko-sampa
 * Description:       Sistema modular de templates de impressão para WordPress e WooCommerce.
 * Version:           1.7.2
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Eko Sampa
 * Text Domain:       eko-sampa
 * Domain Path:       /languages
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    return;
}

define('EKO_SAMPA_VERSION', '1.7.2');
define('EKO_SAMPA_DB_VERSION', '1.0.3');

if (! defined('EKO_SAMPA_DEBUG')) {
    define('EKO_SAMPA_DEBUG', false);
}
if (! defined('EKO_SAMPA_DEBUG_DB')) {
    define('EKO_SAMPA_DEBUG_DB', false);
}
define('EKO_SAMPA_PLUGIN_FILE', __FILE__);
define('EKO_SAMPA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EKO_SAMPA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('EKO_SAMPA_PLUGIN_BASENAME', plugin_basename(__FILE__));

require_once EKO_SAMPA_PLUGIN_DIR . 'includes/helpers-crud-ui.php';
require_once EKO_SAMPA_PLUGIN_DIR . 'includes/helpers-capabilities.php';

/**
 * PSR-4–style autoload for Eko_Sampa_* classes in includes/class-*.php.
 */
spl_autoload_register(
    static function (string $class): void {
        if (str_starts_with($class, 'Eko_Sampa\\')) {
            $relative = substr($class, strlen('Eko_Sampa\\'));
            $slug     = strtolower(str_replace('_', '-', $relative));
            $file     = EKO_SAMPA_PLUGIN_DIR . 'includes/class-' . $slug . '.php';
            if (is_readable($file)) {
                require_once $file;
            }
            return;
        }

        if (! str_starts_with($class, 'Eko_Sampa_')) {
            return;
        }

        $suffix = strtolower(str_replace('_', '-', substr($class, strlen('Eko_Sampa_'))));
        $file   = EKO_SAMPA_PLUGIN_DIR . 'includes/class-' . $suffix . '.php';

        if (is_readable($file)) {
            require_once $file;
        }
    }
);

/**
 * Guard: WordPress minimum version.
 */
add_action(
    'admin_init',
    static function (): void {
        if (! is_admin()) {
            return;
        }

        if (version_compare($GLOBALS['wp_version'] ?? '0', '6.0', '>=')) {
            return;
        }

        if (! function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        deactivate_plugins(EKO_SAMPA_PLUGIN_BASENAME);

        add_action(
            'admin_notices',
            static function (): void {
                echo '<div class="notice notice-error"><p>';
                esc_html_e('Eko Sampa requires WordPress 6.0 or newer.', 'eko-sampa');
                echo '</p></div>';
            }
        );
    }
);

register_activation_hook(
    EKO_SAMPA_PLUGIN_FILE,
    static function (): void {
        if (version_compare(PHP_VERSION, '8.0.0', '<')) {
            wp_die(esc_html__('Eko Sampa requires PHP 8.0 or newer.', 'eko-sampa'));
        }

        $database = new Eko_Sampa_Database();
        $database->ensure_schema();

        Eko_Sampa_Roles::activate();

        $fe = new Eko_Sampa_Frontend_Router();
        $fe->register_rewrite_rules();
        flush_rewrite_rules(false);
    }
);

add_action(
    'plugins_loaded',
    static function (): void {
        if (version_compare($GLOBALS['wp_version'] ?? '0', '6.0', '<')) {
            return;
        }

        Eko_Sampa_Plugin::instance()->boot();
    },
    5
);
