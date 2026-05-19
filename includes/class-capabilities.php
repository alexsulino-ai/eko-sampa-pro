<?php
/**
 * Central capability, operational user status, and quota policy for Eko Sampa.
 *
 * Does not replace WordPress authentication. Does not duplicate user tables.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Maps roles, REST gates, quotas, and operational status (eko_user_status).
 */
final class Eko_Sampa_Capabilities {

    public const META_USER_STATUS = 'eko_user_status';

    public const META_QUOTA_MAX_TEMPLATES = 'eko_quota_max_templates';

    public const META_QUOTA_MAX_CLIENTS = 'eko_quota_max_clients';

    public const META_QUOTA_MAX_ORDERS = 'eko_quota_max_orders';

    public const META_QUOTA_MAX_STORAGE_MB = 'eko_quota_max_storage_mb';

    public const META_QUOTA_MAX_QP_PER_HOUR = 'eko_quota_max_quick_print_per_hour';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PENDING = 'pending';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_PAUSED = 'paused';

    public const OPT_DEFAULT_MAX_CLIENTS = 'eko_sampa_quota_default_max_clients';

    public const OPT_DEFAULT_MAX_ORDERS = 'eko_sampa_quota_default_max_orders';

    public const OPT_DEFAULT_MAX_STORAGE_MB = 'eko_sampa_quota_default_max_storage_mb';

    public const OPT_DEFAULT_MAX_QP_HOUR = 'eko_sampa_quota_default_max_qp_per_hour';

    public static function register_hooks(): void {
        add_filter('rest_pre_dispatch', [self::class, 'rest_pre_dispatch_gate'], 5, 3);
        add_action('show_user_profile', [self::class, 'render_profile_section']);
        add_action('edit_user_profile', [self::class, 'render_profile_section']);
        add_action('personal_options_update', [self::class, 'maybe_save_profile_fields']);
        add_action('edit_user_profile_update', [self::class, 'maybe_save_profile_fields']);
    }

    /**
     * WordPress administrators always bypass Eko operational blocks for rescue.
     */
    public static function current_actor_bypasses_eko_gates(): bool {
        return current_user_can('manage_options');
    }

    public static function user_bypasses_eko_gates(int $user_id): bool {
        return user_can($user_id, 'manage_options');
    }

    public static function can_access_eko_wp_admin(): bool {
        return current_user_can('manage_options')
            || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_EKO_PLATFORM);
    }

    public static function can_manage_eko_users_screen(): bool {
        return current_user_can('manage_options')
            || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_EKO_USERS);
    }

    public static function can_manage_eko_quotas(): bool {
        return current_user_can('manage_options')
            || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_EKO_QUOTAS);
    }

    public static function can_view_eko_analytics_admin(): bool {
        return current_user_can('manage_options')
            || current_user_can(Eko_Sampa_Roles::CAP_VIEW_EKO_ANALYTICS_ADMIN);
    }

    /**
     * @return self::STATUS_*
     */
    public static function get_user_status(int $user_id): string {
        if ($user_id <= 0) {
            return self::STATUS_ACTIVE;
        }
        $raw = get_user_meta($user_id, self::META_USER_STATUS, true);
        $s   = is_string($raw) ? sanitize_key($raw) : '';

        return in_array($s, [self::STATUS_ACTIVE, self::STATUS_PENDING, self::STATUS_BLOCKED, self::STATUS_PAUSED], true)
            ? $s
            : self::STATUS_ACTIVE;
    }

    /**
     * @param self::STATUS_* $status
     */
    public static function set_user_status(int $user_id, string $status): void {
        if ($user_id <= 0) {
            return;
        }
        if (! in_array($status, [self::STATUS_ACTIVE, self::STATUS_PENDING, self::STATUS_BLOCKED, self::STATUS_PAUSED], true)) {
            $status = self::STATUS_ACTIVE;
        }
        update_user_meta($user_id, self::META_USER_STATUS, $status);
    }

    public static function effective_max_templates(int $user_id): int {
        $v = (int) get_user_meta($user_id, self::META_QUOTA_MAX_TEMPLATES, true);
        if ($v > 0) {
            return max(1, min(10000, $v));
        }

        return Eko_Sampa_Template_Derivation::max_saved_templates_per_user();
    }

    public static function effective_max_clients(int $user_id): int {
        $v = (int) get_user_meta($user_id, self::META_QUOTA_MAX_CLIENTS, true);
        if ($v > 0) {
            return max(1, min(50000, $v));
        }

        return max(1, min(50000, (int) get_option(self::OPT_DEFAULT_MAX_CLIENTS, 500)));
    }

    public static function effective_max_orders(int $user_id): int {
        $v = (int) get_user_meta($user_id, self::META_QUOTA_MAX_ORDERS, true);
        if ($v > 0) {
            return max(1, min(500000, $v));
        }

        return max(1, min(500000, (int) get_option(self::OPT_DEFAULT_MAX_ORDERS, 5000)));
    }

    public static function effective_max_storage_mb(int $user_id): int {
        $v = (int) get_user_meta($user_id, self::META_QUOTA_MAX_STORAGE_MB, true);
        if ($v > 0) {
            return max(1, min(500000, $v));
        }

        return max(1, min(500000, (int) get_option(self::OPT_DEFAULT_MAX_STORAGE_MB, 500)));
    }

    public static function effective_max_qp_per_hour(int $user_id): int {
        $v = (int) get_user_meta($user_id, self::META_QUOTA_MAX_QP_PER_HOUR, true);
        if ($v > 0) {
            return max(1, min(500, $v));
        }

        return max(1, min(500, (int) get_option(self::OPT_DEFAULT_MAX_QP_HOUR, 40)));
    }

    /**
     * @return array<string, int>
     */
    public static function get_effective_quotas(int $user_id): array {
        $out = [
            'max_templates'        => self::effective_max_templates($user_id),
            'max_clients'          => self::effective_max_clients($user_id),
            'max_orders'           => self::effective_max_orders($user_id),
            'max_storage_mb'       => self::effective_max_storage_mb($user_id),
            'max_qp_per_hour'      => self::effective_max_qp_per_hour($user_id),
        ];

        return apply_filters('eko_sampa_effective_quotas_for_user', $out, $user_id);
    }

    /**
     * @param array<string, int|string|null> $quotas
     */
    public static function set_user_quotas(int $user_id, array $quotas): void {
        $map = [
            'max_templates'   => self::META_QUOTA_MAX_TEMPLATES,
            'max_clients'     => self::META_QUOTA_MAX_CLIENTS,
            'max_orders'      => self::META_QUOTA_MAX_ORDERS,
            'max_storage_mb'  => self::META_QUOTA_MAX_STORAGE_MB,
            'max_qp_per_hour' => self::META_QUOTA_MAX_QP_PER_HOUR,
        ];
        foreach ($map as $key => $meta_key) {
            if (! array_key_exists($key, $quotas)) {
                continue;
            }
            $val = $quotas[ $key ];
            if ($val === null || $val === '' ) {
                delete_user_meta($user_id, $meta_key);

                continue;
            }
            $n = absint((int) $val);
            if ($n <= 0) {
                delete_user_meta($user_id, $meta_key);

                continue;
            }
            update_user_meta($user_id, $meta_key, $n);
        }
    }

    public static function count_user_clients(int $user_id): int {
        if ($user_id <= 0) {
            return 0;
        }
        global $wpdb;
        $t = $wpdb->prefix . 'eko_sampa_clients';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $n = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$t}` WHERE user_id = %d", $user_id));

        return is_numeric($n) ? (int) $n : 0;
    }

    public static function count_user_orders(int $user_id): int {
        if ($user_id <= 0) {
            return 0;
        }
        global $wpdb;
        $t = $wpdb->prefix . 'eko_sampa_orders';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $n = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$t}` WHERE user_id = %d", $user_id));

        return is_numeric($n) ? (int) $n : 0;
    }

    /**
     * Rough payload size for user-owned templates (bytes); not full disk thumbnails.
     */
    public static function estimate_user_template_payload_bytes(int $user_id): int {
        if ($user_id <= 0) {
            return 0;
        }
        global $wpdb;
        $t = $wpdb->prefix . 'eko_sampa_templates';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $n = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(CHAR_LENGTH(COALESCE(json_data,''))), 0) FROM `{$t}` WHERE user_id = %d",
                $user_id
            )
        );

        return is_numeric($n) ? (int) $n : 0;
    }

    /**
     * @return array<string, mixed>
     */
    public static function get_usage_snapshot(int $user_id, bool $force_refresh = false): array {
        if ($user_id <= 0) {
            return [];
        }
        $ck = 'eko_sampa_usage_v1_' . $user_id;
        if (! $force_refresh) {
            $cached = get_transient($ck);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $tpl = new Eko_Sampa_Template();
        $out = [
            'templates_saved' => $tpl->count_user_saved_templates($user_id),
            'clients'         => self::count_user_clients($user_id),
            'orders'          => self::count_user_orders($user_id),
            'payload_bytes'   => self::estimate_user_template_payload_bytes($user_id),
            'generated_at'    => time(),
        ];
        set_transient($ck, $out, 120);

        return apply_filters('eko_sampa_usage_snapshot_for_user', $out, $user_id);
    }

    public static function invalidate_usage_cache(int $user_id): void {
        if ($user_id > 0) {
            delete_transient('eko_sampa_usage_v1_' . $user_id);
        }
    }

    /**
     * @param mixed $result
     * @return mixed|\WP_Error
     */
    public static function rest_pre_dispatch_gate($result, $server, \WP_REST_Request $request) {
        unset($server);
        if (null !== $result) {
            return $result;
        }
        $route = (string) $request->get_route();
        if ($route === '' || ! str_contains($route, '/eko-sampa/v1/')) {
            return $result;
        }
        if (str_contains($route, '/public/')) {
            return $result;
        }
        if (! is_user_logged_in()) {
            return $result;
        }
        $uid = (int) get_current_user_id();
        if ($uid <= 0 || self::current_actor_bypasses_eko_gates()) {
            return $result;
        }
        if (self::user_bypasses_eko_gates($uid)) {
            return $result;
        }

        $st = self::get_user_status($uid);
        if ($st === self::STATUS_BLOCKED) {
            return new \WP_Error(
                'eko_sampa_user_blocked',
                __('Your Eko Sampa account is blocked. Contact an administrator.', 'eko-sampa'),
                ['status' => 403, 'eko_user_status' => $st]
            );
        }
        if ($st === self::STATUS_PAUSED) {
            $m = strtoupper((string) $request->get_method());
            if (in_array($m, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                if (preg_match('#/eko-sampa/v1/me$#', $route)) {
                    return $result;
                }

                return new \WP_Error(
                    'eko_sampa_user_paused',
                    __('Your Eko Sampa account is read-only. You cannot create or modify resources until an administrator reactivates it.', 'eko-sampa'),
                    ['status' => 403, 'eko_user_status' => $st]
                );
            }
        }

        return $result;
    }

    public static function render_profile_section(\WP_User $user): void {
        if (! is_user_logged_in()) {
            return;
        }
        $viewer = get_current_user_id();
        $is_own = $viewer === (int) $user->ID;
        if (! $is_own && ! self::can_manage_eko_users_screen()) {
            return;
        }

        $status = self::get_user_status((int) $user->ID);
        $quotas = self::get_effective_quotas((int) $user->ID);
        $usage  = self::get_usage_snapshot((int) $user->ID);
        $roles  = array_values(array_intersect(
            [Eko_Sampa_Roles::ROLE_MANAGER, Eko_Sampa_Roles::ROLE_DESIGNER, Eko_Sampa_Roles::ROLE_OPERATOR],
            (array) $user->roles
        ));
        $eko_role = $roles[0] ?? '';

        require EKO_SAMPA_PLUGIN_DIR . 'views/partials/admin-user-profile-eko.php';
    }

    public static function maybe_save_profile_fields(int $user_id): void {
        if (! isset($_POST['_wpnonce']) || ! wp_verify_nonce(sanitize_text_field((string) wp_unslash((string) $_POST['_wpnonce'])), 'update-user_' . $user_id)) {
            return;
        }
        if (! self::can_manage_eko_users_screen()) {
            return;
        }
        if (! current_user_can('edit_user', $user_id)) {
            return;
        }
        if (isset($_POST['eko_sampa_user_status'])) {
            $st = sanitize_key((string) wp_unslash((string) $_POST['eko_sampa_user_status']));
            self::set_user_status($user_id, $st);
        }
        if (self::can_manage_eko_quotas() && isset($_POST['eko_sampa_quotas']) && is_array($_POST['eko_sampa_quotas'])) {
            $raw = wp_unslash($_POST['eko_sampa_quotas']);
            if (is_array($raw)) {
                self::set_user_quotas(
                    $user_id,
                    [
                        'max_templates'   => $raw['max_templates'] ?? null,
                        'max_clients'     => $raw['max_clients'] ?? null,
                        'max_orders'      => $raw['max_orders'] ?? null,
                        'max_storage_mb'  => $raw['max_storage_mb'] ?? null,
                        'max_qp_per_hour' => $raw['max_qp_per_hour'] ?? null,
                    ]
                );
            }
        }
        self::invalidate_usage_cache($user_id);
    }

    /**
     * @return array<string, bool>
     */
    public static function frontend_capability_map(): array {
        $admin = current_user_can('manage_options');
        $tpl   = $admin || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_TEMPLATES);
        $tpl_ro = $admin || $tpl || current_user_can(Eko_Sampa_Roles::CAP_VIEW_EKO_TEMPLATES);

        return [
            'client.view'        => $admin || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_CLIENTS),
            'client.edit'        => $admin || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_CLIENTS),
            'client.delete'      => $admin || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_CLIENTS),
            'service.view'       => $admin || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_SERVICES),
            'service.edit'       => $admin || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_SERVICES),
            'service.delete'     => $admin || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_SERVICES),
            'template.view'      => $tpl_ro,
            'template.edit'      => $tpl,
            'template.editor'    => $tpl,
            'template.duplicate' => $tpl,
            'template.delete'    => $tpl,
            'order.view'         => $admin || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_ORDERS),
            'order.create'       => $admin || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_ORDERS),
            'order.edit'         => $admin || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_ORDERS),
            'order.print'        => $admin || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_ORDERS),
            'order.duplicate'    => $admin || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_ORDERS),
            'order.delete'       => $admin || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_ORDERS),
        ];
    }

    public static function check_template_quota_before_create(int $user_id): ?\WP_Error {
        if ($user_id <= 0 || self::user_bypasses_eko_gates($user_id)) {
            return null;
        }
        $max = self::effective_max_templates($user_id);
        $n   = ( new Eko_Sampa_Template() )->count_user_saved_templates($user_id);
        if ($n >= $max) {
            return new \WP_Error(
                'eko_sampa_quota_templates',
                sprintf(
                    /* translators: %d: max templates */
                    __('You reached the maximum of %d saved templates. Remove old templates or ask an administrator to raise your quota.', 'eko-sampa'),
                    $max
                ),
                ['status' => 403, 'quota' => 'max_templates', 'used' => $n, 'max' => $max]
            );
        }

        return null;
    }

    public static function check_client_quota_before_create(int $user_id): ?\WP_Error {
        if ($user_id <= 0 || self::user_bypasses_eko_gates($user_id)) {
            return null;
        }
        $max = self::effective_max_clients($user_id);
        $n   = self::count_user_clients($user_id);
        if ($n >= $max) {
            return new \WP_Error(
                'eko_sampa_quota_clients',
                sprintf(
                    /* translators: %d: max clients */
                    __('You reached the maximum of %d clients. Remove unused clients or ask for a higher quota.', 'eko-sampa'),
                    $max
                ),
                ['status' => 403, 'quota' => 'max_clients', 'used' => $n, 'max' => $max]
            );
        }

        return null;
    }

    public static function check_order_quota_before_create(int $user_id): ?\WP_Error {
        if ($user_id <= 0 || self::user_bypasses_eko_gates($user_id)) {
            return null;
        }
        $max = self::effective_max_orders($user_id);
        $n   = self::count_user_orders($user_id);
        if ($n >= $max) {
            return new \WP_Error(
                'eko_sampa_quota_orders',
                sprintf(
                    /* translators: %d: max orders */
                    __('You reached the maximum of %d orders. Archive or delete old orders or ask for a higher quota.', 'eko-sampa'),
                    $max
                ),
                ['status' => 403, 'quota' => 'max_orders', 'used' => $n, 'max' => $max]
            );
        }

        return null;
    }

    public static function check_storage_quota_for_template_payload(int $user_id, int $additional_bytes): ?\WP_Error {
        if ($user_id <= 0 || $additional_bytes <= 0 || self::user_bypasses_eko_gates($user_id)) {
            return null;
        }
        $max_mb = self::effective_max_storage_mb($user_id);
        $max_b  = $max_mb * 1024 * 1024;
        $cur    = self::estimate_user_template_payload_bytes($user_id);
        if ($cur + $additional_bytes > $max_b) {
            return new \WP_Error(
                'eko_sampa_quota_storage',
                sprintf(
                    /* translators: %d: storage limit in MB */
                    __('This change would exceed your template storage budget (%d MB). Free space by deleting templates or ask for a higher quota.', 'eko-sampa'),
                    $max_mb
                ),
                ['status' => 403, 'quota' => 'max_storage_mb']
            );
        }

        return null;
    }
}
