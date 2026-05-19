<?php
/**
 * Redirect non-administrator users away from wp-admin (docs/architecture.md).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Keeps wp-admin for administrators only; others go to the frontend app.
 */
final class Eko_Sampa_Admin_Redirect {

    public function register_hooks(): void {
        add_action('admin_init', [$this, 'redirect_non_administrators'], 1);
    }

    public function redirect_non_administrators(): void {
        if (wp_doing_ajax()) {
            return;
        }

        if (defined('DOING_CRON') && DOING_CRON) {
            return;
        }

        if (! is_user_logged_in()) {
            return;
        }

        if ($this->should_remain_in_wp_admin()) {
            return;
        }

        if (! is_admin()) {
            return;
        }

        wp_safe_redirect(Eko_Sampa_Frontend_Router::get_url('dashboard'));
        exit;
    }

    /**
     * Administrators always stay. Eko roles may use Eko submenu pages under admin.php?page=eko-sampa*
     * and their WordPress profile (operational meta is shown there).
     */
    private function should_remain_in_wp_admin(): bool {
        if ($this->is_pure_administrator()) {
            return true;
        }
        if (current_user_can(Eko_Sampa_Roles::CAP_MANAGE_EKO_PLATFORM)) {
            return true;
        }
        if (! current_user_can(Eko_Sampa_Roles::CAP_ACCESS_DASHBOARD)) {
            return false;
        }
        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash((string) $_GET['page'])) : '';
        if ($page !== '' && str_starts_with($page, 'eko-sampa')) {
            return true;
        }
        $script = isset($_SERVER['PHP_SELF']) ? wp_basename((string) $_SERVER['PHP_SELF']) : '';

        return $script === 'profile.php';
    }

    private function is_pure_administrator(): bool {
        $user = wp_get_current_user();
        if (! $user->exists()) {
            return false;
        }

        return in_array('administrator', (array) $user->roles, true);
    }
}
