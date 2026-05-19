<?php
/**
 * Centralized paths and filesystem helpers for Eko Sampa uploads (incremental rollout).
 *
 * All destructive or cross-path operations must pass {@see self::safe_path_guard()}.
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

    private const STAGING_DIRNAME = '_staging';

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

        return $base === '' ? '' : trailingslashit((string) $base) . 'completed-orders';
    }

    /**
     * Hidden workspace for atomic snapshot builds (never served as public URLs).
     */
    public static function completed_orders_staging_root_abs(int $user_id): string {
        $co = self::user_completed_orders_root_abs($user_id);

        return $co === '' ? '' : trailingslashit($co) . self::STAGING_DIRNAME;
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
     * Normalize a path relative to {@see wp_upload_dir()} `basedir` (no leading slash, no traversal).
     */
    public static function normalize_upload_relative(string $relative): string {
        $rel = str_replace('\\', '/', trim($relative));
        $rel = ltrim($rel, '/');
        if ($rel === '' || str_contains($rel, '..')) {
            return '';
        }
        // Reject scheme-prefixed values mistaken for relative paths.
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $rel)) {
            return '';
        }

        return $rel;
    }

    /**
     * Resolve an uploads-relative path to an absolute filesystem path under the site's uploads directory.
     *
     * @return string Absolute path or '' when invalid / outside uploads.
     */
    public static function resolve_upload_relative_to_abs(string $relative): string {
        $rel = self::normalize_upload_relative($relative);
        if ($rel === '') {
            return '';
        }
        $d = self::upload_dirs();
        if ($d['error'] || $d['basedir'] === '' || $d['basedir'] === '/') {
            return '';
        }
        $abs = wp_normalize_path(trailingslashit($d['basedir']) . $rel);
        if (true !== self::safe_path_guard($abs, 'read', false)) {
            return '';
        }

        return $abs;
    }

    /**
     * Build a public URL for a file under uploads from a uploads-relative path (e.g. `eko-sampa/users/user-1/templates/2.jpg`).
     * Returns '' when the path is invalid, escapes uploads, or the file is not a readable regular file.
     */
    public static function public_url_for_upload_relative(string $relative): string {
        $abs = self::resolve_upload_relative_to_abs($relative);
        if ($abs === '' || ! is_readable($abs) || ! is_file($abs)) {
            return '';
        }
        $d = self::upload_dirs();
        if ($d['error'] || $d['baseurl'] === '') {
            return '';
        }
        $from_rel = self::relative_from_abs($abs);
        if ($from_rel === '') {
            return '';
        }

        return trailingslashit($d['baseurl']) . str_replace('\\', '/', $from_rel);
    }

    /**
     * Hard boundary: path must resolve under `uploads/eko-sampa/` (or uploads only when $eko_required is false).
     * Aborts on doubt (symlink escape, missing realpath for existing nodes, traversal).
     *
     * @param 'read'|'write'|'delete_tree' $operation
     *
     * @return true|\WP_Error
     */
    public static function safe_path_guard(string $path, string $operation = 'read', bool $eko_required = true): bool|\WP_Error {
        if ($path === '') {
            return new \WP_Error('eko_sampa_path_empty', __('Empty path rejected.', 'eko-sampa'));
        }
        if (str_contains($path, '..')) {
            return new \WP_Error('eko_sampa_path_traversal', __('Path traversal rejected.', 'eko-sampa'));
        }

        $d = self::upload_dirs();
        if ($d['error'] || $d['basedir'] === '' || $d['basedir'] === '/') {
            return new \WP_Error('eko_sampa_upload_dir', __('Upload directory unavailable.', 'eko-sampa'));
        }

        $base_root = wp_normalize_path(trailingslashit($d['basedir']));
        $eko_root  = wp_normalize_path(trailingslashit(self::eko_root_abs()));
        if ($eko_root === '/') {
            return new \WP_Error('eko_sampa_eko_root', __('Eko root unavailable.', 'eko-sampa'));
        }

        $norm = wp_normalize_path($path);
        if (! str_starts_with($norm, $base_root)) {
            return new \WP_Error('eko_sampa_path_outside_uploads', __('Path outside uploads rejected.', 'eko-sampa'));
        }
        if ($eko_required && ! str_starts_with($norm, $eko_root)) {
            return new \WP_Error('eko_sampa_path_outside_eko', __('Path outside eko-sampa rejected.', 'eko-sampa'));
        }

        if (is_link($path)) {
            $rp = @realpath($path);
            if (! is_string($rp) || $rp === '') {
                return new \WP_Error('eko_sampa_symlink', __('Unresolved symlink rejected.', 'eko-sampa'));
            }
            $rp_n = wp_normalize_path($rp);
            if (! str_starts_with($rp_n, $eko_required ? $eko_root : $base_root)) {
                return new \WP_Error('eko_sampa_symlink_escape', __('Symlink outside allowed root rejected.', 'eko-sampa'));
            }
        }

        if (file_exists($path) || is_dir($path)) {
            $rp = @realpath($path);
            if (is_string($rp) && $rp !== '') {
                $rp_n = wp_normalize_path($rp);
                if (! str_starts_with($rp_n, $base_root)) {
                    return new \WP_Error('eko_sampa_realpath_escape', __('Realpath outside uploads rejected.', 'eko-sampa'));
                }
                if ($eko_required && ! str_starts_with($rp_n, $eko_root)) {
                    return new \WP_Error('eko_sampa_realpath_eko', __('Realpath outside eko-sampa rejected.', 'eko-sampa'));
                }
            }
        }

        // Prevent catastrophic deletes of the entire tree root as target.
        if ($operation === 'delete_tree') {
            if ($norm === rtrim($eko_root, '/') || $norm === rtrim($base_root, '/')) {
                return new \WP_Error('eko_sampa_delete_root', __('Refusing to delete storage root.', 'eko-sampa'));
            }
        }

        return true;
    }

    /**
     * True if $path is inside wp uploads basedir (and eko-sampa root when $require_eko is set).
     */
    public static function is_path_in_uploads(string $path, bool $require_eko = true): bool {
        return true === self::safe_path_guard($path, 'read', $require_eko);
    }

    public static function ensure_dir(string $abs): bool {
        if (true !== self::safe_path_guard($abs, 'write', false)) {
            return false;
        }

        return wp_mkdir_p($abs);
    }

    public static function safe_unlink(string $abs): bool {
        if (true !== self::safe_path_guard($abs, 'write', false)) {
            return false;
        }
        if (! is_file($abs)) {
            return false;
        }

        return false !== wp_delete_file($abs);
    }

    /**
     * @return true|\WP_Error
     */
    public static function safe_copy(string $from, string $to): bool|\WP_Error {
        $g1 = self::safe_path_guard($from, 'read', false);
        if (true !== $g1) {
            return $g1;
        }
        $dir = dirname($to);
        if (true !== self::safe_path_guard($dir, 'write', false)) {
            return new \WP_Error('eko_sampa_storage_dest', __('Invalid destination directory.', 'eko-sampa'));
        }
        if (true !== self::safe_path_guard($to, 'write', false)) {
            return new \WP_Error('eko_sampa_storage_dest', __('Invalid destination path.', 'eko-sampa'));
        }
        if (! self::ensure_dir($dir)) {
            return new \WP_Error('eko_sampa_storage_mkdir', __('Could not create destination directory.', 'eko-sampa'));
        }
        if (! @copy($from, $to)) {
            return new \WP_Error('eko_sampa_storage_copy_failed', __('Copy failed.', 'eko-sampa'));
        }

        $g2 = self::safe_path_guard($to, 'read', false);
        if (true !== $g2) {
            self::safe_unlink($to);

            return new \WP_Error('eko_sampa_storage_copy_verify', __('Destination failed path guard after copy.', 'eko-sampa'));
        }

        return true;
    }

    /**
     * Verify copied file size matches source (lightweight checksum).
     *
     * @return true|\WP_Error
     */
    public static function verify_copy_bytes(string $from, string $to): bool|\WP_Error {
        if (! is_readable($from) || ! is_readable($to)) {
            return new \WP_Error('eko_sampa_verify', __('Cannot verify copy.', 'eko-sampa'));
        }
        $a = filesize($from);
        $b = filesize($to);
        if (! is_int($a) || ! is_int($b) || $a !== $b || $a <= 0) {
            return new \WP_Error('eko_sampa_verify_size', __('Copy size mismatch.', 'eko-sampa'));
        }

        return true;
    }

    /**
     * Remove a directory tree only under the Eko root inside uploads.
     */
    public static function delete_tree_under_eko(string $abs): bool {
        if (true !== self::safe_path_guard($abs, 'delete_tree', true)) {
            Eko_Sampa_Storage_Audit::append('delete_tree_blocked', ['path' => $abs]);

            return false;
        }
        if (! is_dir($abs)) {
            return true;
        }

        return self::delete_tree_recursive($abs);
    }

    private static function delete_tree_recursive(string $dir): bool {
        if (true !== self::safe_path_guard($dir, 'delete_tree', true)) {
            return false;
        }

        $items = @scandir($dir);
        if (! is_array($items)) {
            return false;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = trailingslashit($dir) . $item;
            if (is_link($p)) {
                if (true !== self::safe_path_guard($p, 'delete_tree', true)) {
                    return false;
                }
                // Remove symlink itself (do not follow).
                if (! @unlink($p)) {
                    return false;
                }

                continue;
            }
            if (is_dir($p)) {
                if (! self::delete_tree_recursive($p)) {
                    return false;
                }
            } else {
                if (! self::safe_unlink($p)) {
                    return false;
                }
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
        $d   = self::upload_dirs();
        $out = [
            'checked_at'                  => gmdate('c'),
            'upload_error'                => $d['error'],
            'legacy_template_jpg_orphans' => [],
            'completed_without_snapshot'  => [],
            'completed_snapshot_dirs'     => 0,
            'legacy_templates_dir'        => '',
            'findings'                    => [],
            'staging_dirs_found'          => 0,
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
                if (! Eko_Sampa_Order_Completed_Snapshot::is_ready($uid, $oid)) {
                    $out['completed_without_snapshot'][] = ['order_id' => $oid, 'user_id' => $uid];
                    $out['findings'][]                   = [
                        'severity' => 'CRITICAL',
                        'code'     => 'completed_order_missing_production_snapshot',
                        'order_id' => $oid,
                        'user_id'  => $uid,
                        'detail'   => 'Completed order has no render-ready snapshot (manifest + payloads).',
                    ];
                } elseif (Eko_Sampa_Order_Completed_Snapshot::is_ready($uid, $oid)
                    && ! Eko_Sampa_Order_Completed_Snapshot::has_self_contained_manifest($uid, $oid)) {
                    $out['findings'][] = [
                        'severity' => 'WARNING',
                        'code'     => 'legacy_snapshot_manifest',
                        'order_id' => $oid,
                        'user_id'  => $uid,
                        'detail'   => 'Snapshot exists but predates manifest.json contract; consider regenerating on next template touch or manual repair.',
                    ];
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
                $st = glob(trailingslashit($ud) . 'completed-orders/' . self::STAGING_DIRNAME . '/*') ?: [];
                $out['staging_dirs_found'] += count($st);
                foreach ($st as $sd) {
                    $out['findings'][] = [
                        'severity' => 'WARNING',
                        'code'     => 'snapshot_staging_present',
                        'path'     => $sd,
                        'detail'   => 'Staging workspace exists; may be abandoned if a previous snapshot build failed.',
                    ];
                }
            }
        }

        return $out;
    }
}
