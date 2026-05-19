<?php
/**
 * Custom roles and capabilities (docs/architecture.md — Frontend-First).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Registers Eko Sampa capabilities and roles on activation / init.
 */
final class Eko_Sampa_Roles {

    public const CAP_ACCESS_DASHBOARD = 'access_eko_dashboard';

    public const CAP_MANAGE_CLIENTS = 'manage_eko_clients';

    public const CAP_MANAGE_TEMPLATES = 'manage_eko_templates';

    public const CAP_MANAGE_ORDERS = 'manage_eko_orders';

    public const CAP_MANAGE_SERVICES = 'manage_eko_services';

    /** WP-admin: see Eko Sampa menu and platform screens (not full WP settings). */
    public const CAP_MANAGE_EKO_PLATFORM = 'manage_eko_platform';

    /** WP-admin: Eko Sampa → Users. */
    public const CAP_MANAGE_EKO_USERS = 'manage_eko_users';

    /** Read-only template list/get for operators. */
    public const CAP_VIEW_EKO_TEMPLATES = 'view_eko_templates';

    /** Admin analytics / health slices (not public catalog analytics). */
    public const CAP_VIEW_EKO_ANALYTICS_ADMIN = 'view_eko_analytics_admin';

    /** Reset per-user quotas from Eko admin. */
    public const CAP_MANAGE_EKO_QUOTAS = 'manage_eko_quotas';

    public const ROLE_OPERATOR = 'eko_operator';

    public const ROLE_DESIGNER = 'eko_designer';

    public const ROLE_MANAGER = 'eko_manager';

    public function register_hooks(): void {
        add_action('init', [$this, 'ensure_customer_dashboard_cap'], 20);
        add_action('init', [self::class, 'register_default_quota_options'], 5);
    }

    /**
     * Run on plugin activation after DB migrate.
     */
    public static function activate(): void {
        self::sync_roles_from_codebase();
    }

    /**
     * Idempotent: (re)register caps on roles and grant new caps to administrators.
     * Runs on activation and when the plugin version bumps without re-activation.
     */
    public static function sync_roles_from_codebase(): void {
        $instance = new self();
        $instance->add_roles_and_caps();
        $instance->ensure_customer_dashboard_cap();
        self::register_default_quota_options();
    }

    public static function register_default_quota_options(): void {
        if (get_option(Eko_Sampa_Capabilities::OPT_DEFAULT_MAX_CLIENTS, null) === null) {
            add_option(Eko_Sampa_Capabilities::OPT_DEFAULT_MAX_CLIENTS, 500, '', false);
        }
        if (get_option(Eko_Sampa_Capabilities::OPT_DEFAULT_MAX_ORDERS, null) === null) {
            add_option(Eko_Sampa_Capabilities::OPT_DEFAULT_MAX_ORDERS, 5000, '', false);
        }
        if (get_option(Eko_Sampa_Capabilities::OPT_DEFAULT_MAX_STORAGE_MB, null) === null) {
            add_option(Eko_Sampa_Capabilities::OPT_DEFAULT_MAX_STORAGE_MB, 500, '', false);
        }
        if (get_option(Eko_Sampa_Capabilities::OPT_DEFAULT_MAX_QP_HOUR, null) === null) {
            add_option(Eko_Sampa_Capabilities::OPT_DEFAULT_MAX_QP_HOUR, 40, '', false);
        }
    }

    public function ensure_customer_dashboard_cap(): void {
        $role = get_role('customer');
        if ($role instanceof WP_Role && ! $role->has_cap(self::CAP_ACCESS_DASHBOARD)) {
            $role->add_cap(self::CAP_ACCESS_DASHBOARD);
        }
    }

    private function add_roles_and_caps(): void {
        $this->grant_caps_to_administrator();

        $operator_caps = [
            'read'                         => true,
            self::CAP_ACCESS_DASHBOARD     => true,
            self::CAP_MANAGE_ORDERS        => true,
            self::CAP_VIEW_EKO_TEMPLATES   => true,
        ];

        $designer_caps = [
            'read'                       => true,
            self::CAP_ACCESS_DASHBOARD   => true,
            self::CAP_MANAGE_TEMPLATES   => true,
            self::CAP_MANAGE_SERVICES    => true,
            self::CAP_MANAGE_CLIENTS     => true,
            self::CAP_MANAGE_ORDERS      => true,
        ];

        $manager_caps = [
            'read'                            => true,
            self::CAP_MANAGE_EKO_PLATFORM     => true,
            self::CAP_ACCESS_DASHBOARD        => true,
            self::CAP_MANAGE_CLIENTS        => true,
            self::CAP_MANAGE_TEMPLATES      => true,
            self::CAP_MANAGE_ORDERS         => true,
            self::CAP_MANAGE_SERVICES       => true,
            self::CAP_MANAGE_EKO_USERS      => true,
            self::CAP_VIEW_EKO_TEMPLATES    => true,
            self::CAP_VIEW_EKO_ANALYTICS_ADMIN => true,
            self::CAP_MANAGE_EKO_QUOTAS     => true,
        ];

        $this->add_or_update_role(self::ROLE_OPERATOR, __('Eko Operator', 'eko-sampa'), $operator_caps);
        $this->add_or_update_role(self::ROLE_DESIGNER, __('Eko Designer', 'eko-sampa'), $designer_caps);
        $this->add_or_update_role(self::ROLE_MANAGER, __('Eko Manager', 'eko-sampa'), $manager_caps);
    }

    private function grant_caps_to_administrator(): void {
        $admin = get_role('administrator');
        if (! $admin instanceof WP_Role) {
            return;
        }

        foreach ($this->all_caps() as $cap) {
            $admin->add_cap($cap);
        }
    }

    /**
     * @return array<int, string>
     */
    private function all_caps(): array {
        return [
            self::CAP_ACCESS_DASHBOARD,
            self::CAP_MANAGE_CLIENTS,
            self::CAP_MANAGE_TEMPLATES,
            self::CAP_MANAGE_ORDERS,
            self::CAP_MANAGE_SERVICES,
            self::CAP_MANAGE_EKO_PLATFORM,
            self::CAP_MANAGE_EKO_USERS,
            self::CAP_VIEW_EKO_TEMPLATES,
            self::CAP_VIEW_EKO_ANALYTICS_ADMIN,
            self::CAP_MANAGE_EKO_QUOTAS,
        ];
    }

    /**
     * @param array<string, bool> $caps
     */
    private function add_or_update_role(string $slug, string $label, array $caps): void {
        $role = get_role($slug);
        if ($role instanceof WP_Role) {
            foreach ($this->all_caps() as $cap) {
                $role->remove_cap($cap);
            }
            foreach ($caps as $cap => $grant) {
                if ($grant) {
                    $role->add_cap((string) $cap);
                }
            }

            return;
        }

        add_role($slug, $label, $caps);
    }
}
