<?php
/**
 * Centralized paths and filesystem helpers for Eko Sampa uploads (incremental rollout).
 *
 * Legacy dirs remain authoritative until migrated; new user-scoped dirs are preferred for writes.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Single entry point for `wp_upload_dir`-relative Eko storage (avoid scattering uploads logic).
 */
final class Eko_Sampa_Storage_Manager {

    private const EKO_ROOT = 'eko-sampa';

    /** New layout: per-owner tree (gallery uses English segment per product spec). */
    public const USERS_PREFIX = 'eko-sampa/users';

    /** Legacy gallery segment (Portuguese) — still scanned for reads. */
    public const LEGACY_GALLERY_SUBDIR = 'eko-sampa/galeria';

    /**
     * @return array{basedir: string, baseurl: string, error: string|false}
     */
    public static function upload_dirs(): array {
        $u = wp_upload_dir();
        $err = $u['error'] ?? false;

        return [
            'basedir' => trailingslashit((string) ($u['basedir'] ?? '')),
            'baseurl' => trailingslashit((string) ($u['baseurl'] ?? '')),
            'error'   => is_string($err) && $err !== '' ? $err : false,
        ];
    }

    public static function eko_root_abs(): string {
        $d = self::upload_dirs();
        if ($d['basedir'] === '/' || $d['error']) {
            return '';
        }

        return trailingslashit($d['basedir']) . self::EKO_ROOT;
    }

    public static function user_root_abs(int $user_id): string {
        $user_id = max(0, $user_id);
        $root     = self::eko_root_abs();
        if ($root === '') {
            return '';
        }

        return trailingslashit($root) . 'users/user-' . $user_id;
    }

    public static function user_templates_dir_abs(int $user_id): string {
        $base = self::user_root_abs($user_id);

        return $base === '' ? '' : trailingslashit($base) . 'templates';
    }

    public static function user_orders_dir_abs(int $user_id): string {
        $base = self::user_root_abs($user_id);

        return $base === '' ? '' : trailingslashit($base) . 'orders';
    }

    public static function user_gallery_dir_abs(int $user_id): string {
        $base = self::user_root_abs($user_id);

        return $base === '' ? '' : trailingslashit($base) . 'gallery';
    }

    public static function user_completed_orders_root_abs(int $user_id): string {
        $base = self::user_root_abs($user_id);

        return $base === '' ? '' : trailingslashit($base) . 'completed-orders';
    }

    public static function completed_order_dir_abs(int $user_id, int $order_id): string {
        $root = self::user_completed_orders_root_abs($user_id);
        if ($root === '') {
            return '';
        }

        return trailingslashit($root) . 'order-' . max(0, $order_id);
    }

    /**
     * Canonical user-scoped template thumbnail (new layout).
     */
    public static function user_template_thumbnail_abs(int $user_id, int $template_id): string {
        $dir = self::user_templates_dir_abs($user_id);
        if ($dir === '') {
            return '';
        }

        return trailingslashit($dir) . sprintf('%d.jpg', max(0, $template_id));
    }

    /**
     * Legacy global thumbnail path (still valid for reads / fallback URLs).
     */
    public static function legacy_template_thumbnail_abs(int $template_id): string {
        $d = self::upload_dirs();
        if ($d['basedir'] === '/' || $d['error']) {
            return '';
        }

        return trailingslashit($d['basedir'])
            . Eko_Sampa_Template_Thumbnail_Config::SUBDIR . '/'
            . sprintf(Eko_Sampa_Template_Thumbnail_Config::FILENAME_PATTERN, max(0, $template_id));
    }

    public static function legacy_gallery_dir_abs(int $user_id): string {
        $d = self::upload_dirs();
        if ($d['basedir'] === '/' || $d['error']) {
            return '';
        }

        return trailingslashit($d['basedir']) . self::LEGACY_GALLERY_SUBDIR . '/user-' . max(0, $user_id);
    }

    public static function relative_from_abs(string $abs_path): string {
        $d = self::upload_dirs();
        $base = $d['basedir'];
        if ($base === '' || $base === '/') {
            return '';
        }
        $norm_base = wp_normalize_path($base);
        $norm_abs  = wp_normalize_path($abs_path);
        if (! str_starts_with($norm_abs, $norm_base)) {
            return '';
        }

        return ltrim(substr($norm_abs, strlen($norm_base)), '/');
    }

    /**
     * True if $path is inside wp uploads basedir (and eko-sampa root when $require_eko is set).
     */
    public static function is_path_in_uploads(string $path, bool $require_eko = true): bool {
        $d = self::upload_dirs();
        $base = wp_normalize_path($d['basedir']);
        $p    = wp_normalize_path($path);
        if ($base === '' || $p === '' || ! str_starts_with($p, $base)) {
            return false;
        }
        if (! $require_eko) {
            return true;
        }

        $eko = wp_normalize_path(trailingslashit($d['basedir']) . self::EKO_ROOT);

        return str_starts_with($p, $eko);
    }

    public static function ensure_dir(string $abs): bool {
        if ($abs === '' || ! self::is_path_in_uploads(dirname($abs), false)) {
            return false;
        }

        return wp_mkdir_p($abs);
    }

    public static function safe_unlink(string $abs): bool {
        if ($abs === '' || ! is_file($abs) || ! self::is_path_in_uploads($abs, false)) {
            return false;
        }

        return false !== wp_delete_file($abs);
    }

    /**
     * @return true|\WP_Error
     */
    public static function safe_copy(string $from, string $to): bool|\WP_Error {
        if ($from === '' || $to === '' || ! is_readable($from) || ! self::is_path_in_uploads($from, false)) {
            return new \WP_Error('eko_sampa_storage_copy', __('Invalid source path.', 'eko-sampa'));
        }
        $dir = dirname($to);
        if (! self::ensure_dir($dir)) {
            return new \WP_Error('eko_sampa_storage_mkdir', __('Could not create destination directory.', 'eko-sampa'));
        }
        if (! self::is_path_in_uploads($to, false)) {
            return new \WP_Error('eko_sampa_storage_dest', __('Invalid destination path.', 'eko-sampa'));
        }
        if (! @copy($from, $to)) {
            return new \WP_Error('eko_sampa_storage_copy_failed', __('Copy failed.', 'eko-sampa'));
        }

        return true;
    }

    /**
     * Remove a directory tree only under the Eko root inside uploads.
     */
    public static function delete_tree_under_eko(string $abs): bool {
        $root = wp_normalize_path(trailingslashit(self::eko_root_abs()));
        $path = wp_normalize_path($abs);
        if ($root === '/' || $path === '' || ! str_starts_with($path, $root)) {
            return false;
        }
        if (! is_dir($abs)) {
            return true;
        }

        return self::delete_tree_recursive($abs);
    }

    private static function delete_tree_recursive(string $dir): bool {
        $items = @scandir($dir);
        if (! is_array($items)) {
            return false;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = trailingslashit($dir) . $item;
            if (is_dir($p)) {
                if (! self::delete_tree_recursive($p)) {
                    return false;
                }
            } else {
                wp_delete_file($p);
            }
        }

        return @rmdir($dir);
    }

    /**
     * Map a public uploads URL to an absolute path when it belongs to this site's uploads.
     */
    public static function uploads_url_to_abs(string $url): string {
        $d = self::upload_dirs();
        if ($d['baseurl'] === '' || $d['basedir'] === '') {
            return '';
        }
        $u = set_url_scheme($url, 'https');
        $b = set_url_scheme($d['baseurl'], 'https');
        if (! str_starts_with($u, $b)) {
            // Try http variant.
            $u2 = set_url_scheme($url, 'http');
            $b2 = set_url_scheme($d['baseurl'], 'http');
            if (! str_starts_with($u2, $b2)) {
                return '';
            }
            $rel = substr($u2, strlen($b2));

            return $rel !== false ? trailingslashit($d['basedir']) . ltrim($rel, '/') : '';
        }
        $rel = substr($u, strlen($b));

        return $rel !== false ? trailingslashit($d['basedir']) . ltrim($rel, '/') : '';
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function audit(string $message, array $context = []): void {
        /**
         * Fires when storage manager records an auditable event (optional persistence).
         *
         * @param string               $message Context line.
         * @param array<string, mixed> $context Structured fields.
         */
        do_action('eko_sampa_storage_audit', $message, $context);
        if (defined('EKO_SAMPA_DEBUG') && EKO_SAMPA_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[eko-sampa storage] ' . $message . ' ' . wp_json_encode($context));
        }
    }

    /**
     * Read-only storage integrity snapshot (admin / diagnostics). Never deletes.
     *
     * @return array<string, mixed>
     */
    public static function build_storage_integrity_report(): array {
        global $wpdb;
        $d     = self::upload_dirs();
        $out   = [
            'checked_at' => gmdate('c'),
            'upload_error' => $d['error'],
            'legacy_template_jpg_orphans' => [],
            'completed_without_snapshot' => [],
            'completed_snapshot_dirs'    => 0,
            'legacy_templates_dir'       => '',
        ];
        if ($d['error']) {
            return $out;
        }
        $legacy_dir = trailingslashit($d['basedir']) . Eko_Sampa_Template_Thumbnail_Config::SUBDIR;
        $out['legacy_templates_dir'] = $legacy_dir;
        if (is_dir($legacy_dir)) {
            $files = glob($legacy_dir . '/*.jpg') ?: [];
            $ids   = [];
            foreach ($files as $f) {
                if (! is_string($f) || ! is_file($f)) {
                    continue;
                }
                $base = basename($f, '.jpg');
                if (ctype_digit($base)) {
                    $ids[] = (int) $base;
                }
            }
            $tpl_table = $wpdb->prefix . 'eko_sampa_templates';
            foreach (array_unique($ids) as $tid) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$tpl_table}` WHERE id = %d", $tid));
                if ($exists === 0) {
                    $out['legacy_template_jpg_orphans'][] = $tid;
                }
            }
        }

        $ord_table = $wpdb->prefix . 'eko_sampa_orders';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $completed = $wpdb->get_results(
            "SELECT id, user_id FROM `{$ord_table}` WHERE status = 'completed' ORDER BY id DESC LIMIT 500",
            ARRAY_A
        );
        if (is_array($completed)) {
            foreach ($completed as $row) {
                $oid = (int) ($row['id'] ?? 0);
                $uid = (int) ($row['user_id'] ?? 0);
                if ($oid <= 0 || $uid <= 0) {
                    continue;
                }
                $snap_dir = self::completed_order_dir_abs($uid, $oid);
                $ready    = $snap_dir !== ''
                    && is_readable($snap_dir . '/template-snapshot.json')
                    && is_readable($snap_dir . '/order.json');
                if (! $ready) {
                    $out['completed_without_snapshot'][] = ['order_id' => $oid, 'user_id' => $uid];
                }
            }
        }

        $eko = self::eko_root_abs();
        if ($eko !== '' && is_dir($eko)) {
            $users = glob(trailingslashit($eko) . 'users/user-*') ?: [];
            foreach ($users as $ud) {
                if (! is_string($ud) || ! is_dir($ud)) {
                    continue;
                }
                $co = glob(trailingslashit($ud) . 'completed-orders/order-*') ?: [];
                $out['completed_snapshot_dirs'] += count($co);
            }
        }

        return $out;
    }
}
