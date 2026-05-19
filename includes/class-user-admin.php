<?php
/**
 * WP-admin user management screens (Eko Sampa → Users).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * List/detail + quick actions for operational governance.
 */
final class Eko_Sampa_User_Admin {

    public const MENU_SLUG = 'eko-sampa-users';

    public static function register_hooks(): void {
        add_action('admin_init', [self::class, 'handle_post']);
    }

    public static function handle_post(): void {
        if (! isset($_GET['page']) || sanitize_key((string) wp_unslash((string) $_GET['page'])) !== self::MENU_SLUG) {
            return;
        }
        if (! isset($_POST['eko_sampa_users_action']) || ! isset($_POST['eko_sampa_users_nonce'])) {
            return;
        }
        if (! wp_verify_nonce(sanitize_text_field((string) wp_unslash((string) $_POST['eko_sampa_users_nonce'])), 'eko_sampa_users')) {
            return;
        }
        if (! Eko_Sampa_Capabilities::can_manage_eko_users_screen()) {
            return;
        }
        $action = sanitize_key((string) wp_unslash((string) $_POST['eko_sampa_users_action']));
        $uid    = absint((int) wp_unslash((string) ( $_POST['user_id'] ?? 0 )));
        if ($uid <= 0 || ! current_user_can('edit_user', $uid)) {
            return;
        }
        if ($uid === get_current_user_id() && in_array($action, ['block', 'pause'], true)) {
            return;
        }

        switch ($action) {
            case 'activate':
                Eko_Sampa_Capabilities::set_user_status($uid, Eko_Sampa_Capabilities::STATUS_ACTIVE);
                break;
            case 'pending':
                Eko_Sampa_Capabilities::set_user_status($uid, Eko_Sampa_Capabilities::STATUS_PENDING);
                break;
            case 'pause':
                Eko_Sampa_Capabilities::set_user_status($uid, Eko_Sampa_Capabilities::STATUS_PAUSED);
                break;
            case 'block':
                Eko_Sampa_Capabilities::set_user_status($uid, Eko_Sampa_Capabilities::STATUS_BLOCKED);
                break;
            case 'reset_quotas':
                if (Eko_Sampa_Capabilities::can_manage_eko_quotas()) {
                    Eko_Sampa_Capabilities::set_user_quotas(
                        $uid,
                        [
                            'max_templates'   => null,
                            'max_clients'     => null,
                            'max_orders'      => null,
                            'max_storage_mb'  => null,
                            'max_qp_per_hour' => null,
                        ]
                    );
                }
                break;
            case 'clear_qp':
                self::cancel_open_quick_print_jobs_for_user($uid);
                break;
            case 'impersonate_stub':
                do_action('eko_sampa_user_admin_impersonate_requested', $uid, get_current_user_id());
                break;
        }
        Eko_Sampa_Capabilities::invalidate_usage_cache($uid);
        wp_safe_redirect(
            add_query_arg(
                ['page' => self::MENU_SLUG, 'updated' => '1', 'user_id' => $uid],
                admin_url('admin.php')
            )
        );
        exit;
    }

    private static function cancel_open_quick_print_jobs_for_user(int $user_id): void {
        global $wpdb;
        if (! ( new Eko_Sampa_Quick_Print_Job() )->is_storage_ready()) {
            return;
        }
        $j = $wpdb->prefix . 'eko_sampa_quick_print_jobs';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$j}` SET status = %s, updated_at = %s WHERE user_id = %d AND status IN (%s, %s)",
                Eko_Sampa_Quick_Print_Job::STATUS_CANCELLED,
                current_time('mysql'),
                $user_id,
                Eko_Sampa_Quick_Print_Job::STATUS_QUEUED,
                Eko_Sampa_Quick_Print_Job::STATUS_SENT_TO_BROWSER
            )
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function query_users_page(int $paged = 1, int $per_page = 30): array {
        $paged    = max(1, $paged);
        $per_page = max(1, min(100, $per_page));
        $q        = new \WP_User_Query(
            [
                'number'       => $per_page,
                'paged'        => $paged,
                'orderby'      => 'registered',
                'order'        => 'DESC',
                'count_total'  => true,
                'fields'       => 'all',
            ]
        );

        $users = $q->get_results();
        $out   = [];
        foreach ($users as $u) {
            if (! $u instanceof \WP_User) {
                continue;
            }
            $out[] = self::build_row((int) $u->ID, $u);
        }

        return [
            'rows'  => $out,
            'total' => (int) $q->get_total(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function build_row(int $user_id, ?\WP_User $user = null): array {
        if (! $user instanceof \WP_User) {
            $u = get_userdata($user_id);
            $user = $u instanceof \WP_User ? $u : new \WP_User($user_id);
        }
        $usage  = Eko_Sampa_Capabilities::get_usage_snapshot($user_id);
        $quotas = Eko_Sampa_Capabilities::get_effective_quotas($user_id);
        $st     = Eko_Sampa_Capabilities::get_user_status($user_id);
        $roles  = array_values(array_intersect(
            [Eko_Sampa_Roles::ROLE_MANAGER, Eko_Sampa_Roles::ROLE_DESIGNER, Eko_Sampa_Roles::ROLE_OPERATOR],
            (array) $user->roles
        ));
        $eko_role = $roles[0] ?? '—';

        return [
            'id'            => $user_id,
            'display_name'  => $user->display_name,
            'user_email'    => $user->user_email,
            'eko_role'      => $eko_role,
            'status'        => $st,
            'registered'    => $user->user_registered,
            'templates'     => sprintf('%d / %d', (int) ( $usage['templates_saved'] ?? 0 ), $quotas['max_templates']),
            'clients'       => sprintf('%d / %d', (int) ( $usage['clients'] ?? 0 ), $quotas['max_clients']),
            'orders'        => sprintf('%d / %d', (int) ( $usage['orders'] ?? 0 ), $quotas['max_orders']),
            'storage_kb'    => (int) round(((int) ( $usage['payload_bytes'] ?? 0 )) / 1024),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function build_detail_bundle(int $user_id): array {
        $user = get_userdata($user_id);
        if (! $user instanceof \WP_User) {
            return [];
        }
        $row = self::build_row($user_id, $user);
        global $wpdb;
        $qp_open = 0;
        $j       = $wpdb->prefix . 'eko_sampa_quick_print_jobs';
        if (( new Eko_Sampa_Database() )->table_exists_for_suffix('eko_sampa_quick_print_jobs')) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $qp_open = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$j}` WHERE user_id = %d AND status IN (%s, %s)",
                    $user_id,
                    Eko_Sampa_Quick_Print_Job::STATUS_QUEUED,
                    Eko_Sampa_Quick_Print_Job::STATUS_SENT_TO_BROWSER
                )
            );
        }
        $row['quick_prints_open'] = $qp_open;
        $row['last_login']         = self::last_login_human($user_id);
        $row['active_sessions']    = self::count_active_wp_sessions($user_id);
        $row['quotas']             = Eko_Sampa_Capabilities::get_effective_quotas($user_id);
        $row['usage']              = Eko_Sampa_Capabilities::get_usage_snapshot($user_id, true);

        return $row;
    }

    private static function count_active_wp_sessions(int $user_id): int {
        $raw = get_user_meta($user_id, 'session_tokens', true);

        return is_array($raw) ? count($raw) : 0;
    }

    private static function last_login_human(int $user_id): string {
        $raw = get_user_meta($user_id, 'session_tokens', true);
        if (! is_array($raw) || $raw === []) {
            return '—';
        }
        $max = 0;
        foreach ($raw as $blob) {
            if (! is_string($blob)) {
                continue;
            }
            $d = json_decode($blob, true);
            if (is_array($d) && ! empty($d['login'])) {
                $max = max($max, (int) $d['login']);
            }
        }

        return $max > 0 ? wp_human_time_diff($max, time()) . ' ' . __('ago', 'eko-sampa') : '—';
    }

    /**
     * @return array<string, mixed>
     */
    public static function analytics_slice(): array {
        $users = get_users(
            [
                'number'  => 200,
                'orderby' => 'registered',
                'order'   => 'DESC',
                'fields'  => ['ID'],
            ]
        );
        $blocked = 0;
        $near    = 0;
        foreach ($users as $u) {
            $id = is_numeric($u) ? (int) $u : (int) ( is_object($u) && isset($u->ID) ? $u->ID : 0 );
            if ($id <= 0) {
                continue;
            }
            if (Eko_Sampa_Capabilities::get_user_status($id) === Eko_Sampa_Capabilities::STATUS_BLOCKED) {
                ++$blocked;
            }
            $usage = Eko_Sampa_Capabilities::get_usage_snapshot($id);
            $q     = Eko_Sampa_Capabilities::get_effective_quotas($id);
            $t     = (int) ( $usage['templates_saved'] ?? 0 );
            if ($q['max_templates'] > 0 && $t >= (int) floor($q['max_templates'] * 0.85 )) {
                ++$near;
            }
        }

        return [
            'sample_size'     => count($users),
            'blocked_in_sample' => $blocked,
            'near_template_quota' => $near,
        ];
    }
}
