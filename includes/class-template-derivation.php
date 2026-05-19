<?php
/**
 * Template derivation layer: master (immutable) → session (temporary) → user (saved).
 *
 * Does not alter JSON contracts or renderer; only row metadata + lifecycle.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Business rules and helpers for {@see Eko_Sampa_Template} derivation columns.
 */
final class Eko_Sampa_Template_Derivation {

    public const TYPE_USER = 'user';

    public const TYPE_MASTER = 'master';

    public const TYPE_SESSION = 'session';

    public const LIFECYCLE_ACTIVE = 'active';

    public const LIFECYCLE_EXPIRED = 'expired';

    public const LIFECYCLE_ABANDONED = 'abandoned';

    public const LIFECYCLE_PERSISTED = 'persisted';

    public const OPTION_SESSION_TTL_HOURS = 'eko_sampa_template_session_ttl_hours';

    public const OPTION_MAX_SAVED = 'eko_sampa_max_saved_templates_per_user';

    public const OPTION_QP_GUEST_TTL_MINUTES = 'eko_sampa_qp_guest_ttl_minutes';

    public const OPTION_OBSERVABILITY = 'eko_sampa_derivation_observability';

    public const CRON_HOOK = 'eko_sampa_template_derivation_cleanup';

    /**
     * @param array<string, mixed>|null $row
     */
    public static function normalize_type(?array $row): string {
        if (! is_array($row)) {
            return self::TYPE_USER;
        }
        $t = isset($row['template_type']) ? strtolower(trim((string) $row['template_type'])) : '';
        if ($t === '' || $t === 'legacy') {
            return self::TYPE_USER;
        }
        if (in_array($t, [self::TYPE_USER, self::TYPE_MASTER, self::TYPE_SESSION], true)) {
            return $t;
        }

        return self::TYPE_USER;
    }

    public static function session_ttl_hours(): int {
        $v = (int) get_option(self::OPTION_SESSION_TTL_HOURS, 72);

        return max(1, min(720, $v));
    }

    public static function max_saved_templates_per_user(): int {
        $v = (int) get_option(self::OPTION_MAX_SAVED, 50);

        return max(1, min(10000, $v));
    }

    /**
     * Wall-clock TTL for guest quick-print jobs (bound by template session expiry when applicable).
     */
    public static function quick_print_guest_ttl_seconds(): int {
        $v = (int) get_option(self::OPTION_QP_GUEST_TTL_MINUTES, 120);

        return max(15 * MINUTE_IN_SECONDS, min(48 * HOUR_IN_SECONDS, $v * MINUTE_IN_SECONDS));
    }

    /**
     * Whether this row may spawn a public working session (POST …/public/templates/{id}/session).
     *
     * @param array<string, mixed>|null $row
     */
    public static function can_fork_public_session(?array $row): bool {
        if (! is_array($row)) {
            return false;
        }
        if (self::normalize_type($row) === self::TYPE_SESSION) {
            return false;
        }
        if (! self::is_master_row($row)) {
            return false;
        }
        if ((int) ( $row['allow_personalization'] ?? 1 ) !== 1) {
            return false;
        }

        $colmap = ( new Eko_Sampa_Template() )->table_column_name_map();
        if (isset($colmap['is_public_catalog'])) {
            return (int) ( $row['is_public_catalog'] ?? 0 ) === 1;
        }

        return (int) ( $row['is_public'] ?? 0 ) === 1;
    }

    /**
     * @param array<string, mixed>|null $row
     */
    public static function is_master_row(?array $row): bool {
        return self::normalize_type($row) === self::TYPE_MASTER;
    }

    /**
     * @param array<string, mixed>|null $row
     */
    public static function is_session_row(?array $row): bool {
        return self::normalize_type($row) === self::TYPE_SESSION;
    }

    /**
     * @param array<string, mixed>|null $row
     */
    public static function session_token_matches(?array $row, string $token): bool {
        if (! is_array($row) || ! self::is_session_row($row)) {
            return false;
        }
        $token = trim($token);
        if ($token === '') {
            return false;
        }
        $stored = isset($row['session_token']) ? (string) $row['session_token'] : '';

        return $stored !== '' && hash_equals($stored, $token);
    }

    /**
     * @param array<string, mixed>|null $row
     */
    public static function session_is_usable(?array $row): bool {
        if (! is_array($row) || ! self::is_session_row($row)) {
            return false;
        }
        $exp = $row['expires_at'] ?? null;
        if ($exp === null || $exp === '') {
            return true;
        }
        $ts = strtotime((string) $exp);

        return $ts !== false && $ts > time();
    }

    /**
     * Session rows created before DB 1.0.12 have an empty fingerprint — keep them usable (retrocompat).
     *
     * @param array<string, mixed>|null $row
     */
    public static function session_fingerprint_matches(?array $row): bool {
        if (! is_array($row) || ! self::is_session_row($row)) {
            return false;
        }
        $stored = isset($row['session_fingerprint']) ? (string) $row['session_fingerprint'] : '';
        if ($stored === '') {
            return true;
        }
        $tok = isset($row['session_token']) ? trim((string) $row['session_token']) : '';
        if ($tok === '') {
            return false;
        }

        return hash_equals($stored, self::compute_session_fingerprint($tok));
    }

    /**
     * @param array<string, mixed>|null $row
     */
    public static function session_lifecycle_allows_api(?array $row): bool {
        if (! is_array($row) || ! self::is_session_row($row)) {
            return false;
        }
        $s = isset($row['session_lifecycle']) ? strtolower(trim((string) $row['session_lifecycle'])) : '';

        return $s === '' || $s === self::LIFECYCLE_ACTIVE;
    }

    /**
     * Full gate for REST/model session paths: type, token, expiry, lifecycle, light fingerprint binding.
     *
     * @param array<string, mixed>|null $row
     */
    public static function request_can_use_session_row(?array $row, string $token): bool {
        $token = trim($token);
        if (! is_array($row) || $token === '') {
            return false;
        }

        return self::session_token_matches($row, $token)
            && self::session_is_usable($row)
            && self::session_lifecycle_allows_api($row)
            && self::session_fingerprint_matches($row);
    }

    /**
     * HMAC binding: partial IP + UA digest + server secret + session token (token already unguessable).
     */
    public static function compute_session_fingerprint(string $session_token): string {
        $session_token = trim($session_token);
        if ($session_token === '') {
            return '';
        }
        $secret   = (string) wp_salt('eko_sampa_tpl_sess_fp');
        $material = self::fingerprint_material();

        return hash_hmac('sha256', $material, $secret . $session_token);
    }

    private static function fingerprint_material(): string {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $ip = self::ip_prefix_for_binding($ip);
        $ua = '';
        if (isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT'])) {
            $ua = substr(hash('sha256', (string) wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 16);
        }

        return $ip . '|' . $ua;
    }

    private static function ip_prefix_for_binding(string $ip): string {
        $ip = trim($ip);
        if ($ip === '') {
            return '0';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $p = explode('.', $ip);

            return $p[0] . '.' . $p[1] . '.' . $p[2] . '.x';
        }
        if (str_contains($ip, ':')) {
            $parts = explode(':', $ip);

            return implode(':', array_slice($parts, 0, 4)) . '::';
        }

        return '0';
    }

    public static function new_session_token(): string {
        try {
            return bin2hex(random_bytes(24));
        } catch (\Throwable) {
            return strtolower((string) wp_generate_password(48, false, false));
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function expires_bounds_for_new_session(): ?array {
        $ttl = self::session_ttl_hours();
        $now = current_time('mysql');
        $ts  = time() + ( $ttl * HOUR_IN_SECONDS );
        $exp = gmdate('Y-m-d H:i:s', $ts);

        return [
            'created_mysql' => $now,
            'expires_gmt'   => $exp,
        ];
    }

    /**
     * Best-effort guest job expiry instant (UTC mysql), capped by session template expiry when present.
     *
     * @param array<string, mixed>|null $session_template_row
     */
    public static function quick_print_job_expires_at_for_guest(?array $session_template_row): string {
        $candidate = time() + self::quick_print_guest_ttl_seconds();
        if (is_array($session_template_row)) {
            $exp = $session_template_row['expires_at'] ?? '';
            if (is_string($exp) && $exp !== '') {
                $ts = strtotime($exp);
                if ($ts !== false) {
                    $candidate = min($candidate, $ts);
                }
            }
        }

        return gmdate('Y-m-d H:i:s', $candidate);
    }

    /**
     * Delete expired session templates and related thumbnails (never user/master rows).
     */
    public static function run_scheduled_cleanup(): void {
        global $wpdb;

        self::mark_abandoned_stale_guest_quick_print_jobs();
        self::purge_guest_quick_print_jobs_expired_or_long_abandoned();

        $table = $wpdb->prefix . 'eko_sampa_templates';
        if (! ( new Eko_Sampa_Database() )->table_exists_for_suffix('eko_sampa_templates')) {
            self::cleanup_orphan_quick_print_sessions();
            self::publish_observability_snapshot();

            return;
        }

        $now_gmt = gmdate('Y-m-d H:i:s', time());
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM `{$table}` WHERE template_type = %s AND expires_at IS NOT NULL AND expires_at != '' AND expires_at < %s LIMIT 200",
                self::TYPE_SESSION,
                $now_gmt
            )
        );
        $deleted = 0;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $have_lifecycle_col = (bool) $wpdb->get_var("SHOW COLUMNS FROM `{$table}` LIKE 'session_lifecycle'");

        if (is_array($ids) && $ids !== []) {
            foreach ($ids as $raw_id) {
                $id = absint((int) $raw_id);
                if ($id <= 0) {
                    continue;
                }
                $row = ( new Eko_Sampa_Template() )->get_row_by_id($id);
                if (! is_array($row) || ! self::is_session_row($row)) {
                    continue;
                }
                if ($have_lifecycle_col) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $wpdb->update(
                        $table,
                        ['session_lifecycle' => self::LIFECYCLE_EXPIRED],
                        ['id' => $id],
                        ['%s'],
                        ['%d']
                    );
                }
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->delete($table, ['id' => $id], ['%d']);
                Eko_Sampa_Template_Thumbnail::delete($id);
                ++$deleted;
            }
        }

        if ($deleted > 0) {
            Eko_Sampa_Storage_Audit::append(
                'derivation_session_cleanup',
                ['deleted_session_templates' => $deleted]
            );
            do_action('eko_sampa_guest_sessions_expired_cleanup', $deleted);
        }

        self::cleanup_orphan_quick_print_sessions();
        self::publish_observability_snapshot();
    }

    /**
     * Guest jobs left in early states for several hours — mark abandoned (does not cancel in-flight browser prints).
     */
    private static function mark_abandoned_stale_guest_quick_print_jobs(): void {
        global $wpdb;

        if (! ( new Eko_Sampa_Database() )->table_exists_for_suffix('eko_sampa_quick_print_jobs')) {
            return;
        }

        $jobs = $wpdb->prefix . 'eko_sampa_quick_print_jobs';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $col = $wpdb->get_var("SHOW COLUMNS FROM `{$jobs}` LIKE 'abandoned_at'");
        if (! $col) {
            return;
        }

        $cut = wp_date('Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $n = $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$jobs}` SET abandoned_at = UTC_TIMESTAMP(), status = %s, updated_at = %s "
                . "WHERE user_id = 0 AND status IN (%s, %s) AND created_at < %s "
                . "AND (abandoned_at IS NULL OR abandoned_at = '0000-00-00 00:00:00')",
                Eko_Sampa_Quick_Print_Job::STATUS_ABANDONED,
                current_time('mysql'),
                Eko_Sampa_Quick_Print_Job::STATUS_QUEUED,
                Eko_Sampa_Quick_Print_Job::STATUS_SENT_TO_BROWSER,
                $cut
            )
        );
        if (is_numeric($n) && (int) $n > 0) {
            Eko_Sampa_Storage_Audit::append('derivation_qp_abandoned', ['rows' => (int) $n]);
            do_action('eko_sampa_guest_qp_marked_abandoned', (int) $n);
        }
    }

    /**
     * Remove guest jobs that are past expires_at or abandoned long enough ago.
     */
    private static function purge_guest_quick_print_jobs_expired_or_long_abandoned(): void {
        global $wpdb;

        if (! ( new Eko_Sampa_Database() )->table_exists_for_suffix('eko_sampa_quick_print_jobs')) {
            return;
        }

        $jobs = $wpdb->prefix . 'eko_sampa_quick_print_jobs';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $col = $wpdb->get_var("SHOW COLUMNS FROM `{$jobs}` LIKE 'expires_at'");
        if (! $col) {
            return;
        }

        $cut = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$jobs}` WHERE user_id = 0 AND ("
                . '(expires_at IS NOT NULL AND expires_at != \'\' AND expires_at < UTC_TIMESTAMP()) OR '
                . '(status = %s AND abandoned_at IS NOT NULL AND abandoned_at != \'\' AND abandoned_at < %s)'
                . ')',
                Eko_Sampa_Quick_Print_Job::STATUS_ABANDONED,
                $cut
            )
        );
    }

    /**
     * Remove quick-print jobs that reference deleted session templates (guest jobs use user_id = 0).
     */
    private static function cleanup_orphan_quick_print_sessions(): void {
        global $wpdb;

        $jobs = $wpdb->prefix . 'eko_sampa_quick_print_jobs';
        $tpl  = $wpdb->prefix . 'eko_sampa_templates';
        if (! ( new Eko_Sampa_Database() )->table_exists_for_suffix('eko_sampa_quick_print_jobs')) {
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $col = $wpdb->get_var("SHOW COLUMNS FROM `{$jobs}` LIKE 'session_token'");
        if (! $col) {
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            "DELETE q FROM `{$jobs}` q "
            . "LEFT JOIN `{$tpl}` t ON t.id = q.template_id "
            . "WHERE q.user_id = 0 AND q.session_token != '' AND (t.id IS NULL OR t.template_type != 'session')"
        );
    }

    /**
     * Lightweight counters for administrators (refreshed after cleanup).
     */
    public static function publish_observability_snapshot(): void {
        global $wpdb;

        $out = [
            'updated_at'              => gmdate('c'),
            'sessions_active'         => 0,
            'sessions_near_expiry'    => 0,
            'guest_qp_open'           => 0,
            'guest_qp_abandoned_rows' => 0,
        ];

        $tpl = $wpdb->prefix . 'eko_sampa_templates';
        if (( new Eko_Sampa_Database() )->table_exists_for_suffix('eko_sampa_templates')) {
            $now = gmdate('Y-m-d H:i:s', time());
            $soon = gmdate('Y-m-d H:i:s', time() + HOUR_IN_SECONDS);
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $out['sessions_active'] = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$tpl}` WHERE template_type = %s AND (expires_at IS NULL OR expires_at = '' OR expires_at >= %s)",
                    self::TYPE_SESSION,
                    $now
                )
            );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $out['sessions_near_expiry'] = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$tpl}` WHERE template_type = %s AND expires_at IS NOT NULL AND expires_at != '' AND expires_at < %s AND expires_at >= %s",
                    self::TYPE_SESSION,
                    $soon,
                    $now
                )
            );
        }

        if (( new Eko_Sampa_Database() )->table_exists_for_suffix('eko_sampa_quick_print_jobs')) {
            $jobs = $wpdb->prefix . 'eko_sampa_quick_print_jobs';
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $out['guest_qp_open'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM `{$jobs}` WHERE user_id = 0 AND status IN ('queued','sent_to_browser')"
            );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $out['guest_qp_abandoned_rows'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM `{$jobs}` WHERE user_id = 0 AND status = 'abandoned'"
            );
        }

        update_option(self::OPTION_OBSERVABILITY, $out, false);
    }

    public static function register_cron(): void {
        static $hooked = false;
        if (! $hooked) {
            $hooked = true;
            add_action(self::CRON_HOOK, [self::class, 'run_scheduled_cleanup']);
        }

        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 600, 'hourly', self::CRON_HOOK);
        }
    }
}
