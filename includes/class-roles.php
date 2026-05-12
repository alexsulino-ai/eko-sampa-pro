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

    public const ROLE_OPERATOR = 'eko_operator';

    public const ROLE_DESIGNER = 'eko_designer';

    public const ROLE_MANAGER = 'eko_manager';

    public function register_hooks(): void {
        add_action('init', [$this, 'ensure_customer_dashboard_cap'], 20);
    }

    /**
     * Run on plugin activation after DB migrate.
     */
    public static function activate(): void {
        $instance = new self();
        $instance->add_roles_and_caps();
        $instance->ensure_customer_dashboard_cap();
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
            'read'                       => true,
            self::CAP_ACCESS_DASHBOARD => true,
            self::CAP_MANAGE_ORDERS    => true,
        ];

        $designer_caps = [
            'read'                       => true,
            self::CAP_ACCESS_DASHBOARD   => true,
            self::CAP_MANAGE_TEMPLATES   => true,
            self::CAP_MANAGE_SERVICES    => true,
        ];

        $manager_caps = [
            'read'                       => true,
            self::CAP_ACCESS_DASHBOARD   => true,
            self::CAP_MANAGE_CLIENTS     => true,
            self::CAP_MANAGE_TEMPLATES   => true,
            self::CAP_MANAGE_ORDERS      => true,
            self::CAP_MANAGE_SERVICES    => true,
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
