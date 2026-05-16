<?php
/**
 * Persisted JPG thumbnails for templates (one file per template id).
 *
 * Storage: prefers {@see Eko_Sampa_Storage_Manager::user_template_thumbnail_abs()} and falls back to
 * legacy `{uploads}/eko-sampa/templates/{id}.jpg` for reads and old installs.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Thumbnail files: user-scoped when owner known; legacy global path retained as fallback.
 */
final class Eko_Sampa_Template_Thumbnail {

    public const STATE_MISSING = 'missing';

    public const STATE_GENERATING = 'generating';

    public const STATE_READY = 'ready';

    public const STATE_FAILED = 'failed';

    public const STATE_STALE = 'stale';

    /** Client pipeline states (also exposed in REST for debugging). */
    public const STATE_IDLE = 'idle';

    public const STATE_QUEUED = 'queued';

    public const STATE_ABORTED = 'aborted';

    private const TRANSIENT_GENERATING = 'eko_sampa_thumb_gen_';

    private const TRANSIENT_LOCK = 'eko_sampa_thumb_lock_';

    public static function uploads_subdir(): string {
        return Eko_Sampa_Template_Thumbnail_Config::SUBDIR;
    }

    public static function file_path(int $template_id): string {
        $uid = self::template_owner_id($template_id);

        return self::readable_abs($template_id, $uid);
    }

    /**
     * Absolute path used for reads (prefers user-scoped file, then legacy).
     */
    public static function readable_abs(int $template_id, int $owner_user_id = 0): string {
        if ($template_id <= 0) {
            return '';
        }
        if ($owner_user_id <= 0) {
            $owner_user_id = self::template_owner_id($template_id);
        }

        $min = Eko_Sampa_Template_Thumbnail_Config::MIN_FILE_BYTES;

        if ($owner_user_id > 0) {
            $user_path = Eko_Sampa_Storage_Manager::user_template_thumbnail_abs($owner_user_id, $template_id);
            if ($user_path !== '' && is_readable($user_path) && filesize($user_path) > $min) {
                return $user_path;
            }
        }

        $legacy = Eko_Sampa_Storage_Manager::legacy_template_thumbnail_abs($template_id);
        if ($legacy !== '' && is_readable($legacy) && filesize($legacy) > $min) {
            self::maybe_silent_migrate_legacy($template_id, $owner_user_id, $legacy);
            if ($owner_user_id > 0) {
                $user_after = Eko_Sampa_Storage_Manager::user_template_thumbnail_abs($owner_user_id, $template_id);
                if ($user_after !== '' && is_readable($user_after) && filesize($user_after) > $min) {
                    return $user_after;
                }
            }

            return $legacy;
        }

        if ($owner_user_id > 0) {
            return Eko_Sampa_Storage_Manager::user_template_thumbnail_abs($owner_user_id, $template_id);
        }

        return $legacy;
    }

    /**
     * Target path for new writes (user-scoped when owner exists, else legacy).
     */
    public static function writable_abs(int $template_id, int $owner_user_id = 0): string {
        if ($template_id <= 0) {
            return '';
        }
        if ($owner_user_id <= 0) {
            $owner_user_id = self::template_owner_id($template_id);
        }

        if ($owner_user_id > 0) {
            $p = Eko_Sampa_Storage_Manager::user_template_thumbnail_abs($owner_user_id, $template_id);
            if ($p !== '') {
                return $p;
            }
        }

        return Eko_Sampa_Storage_Manager::legacy_template_thumbnail_abs($template_id);
    }

    public static function file_version(int $template_id): int {
        $path = self::file_path($template_id);
        if ($path === '' || ! is_readable($path)) {
            return 0;
        }

        $mtime = @filemtime($path);

        return is_int($mtime) && $mtime > 0 ? $mtime : 0;
    }

    public static function exists(int $template_id): bool {
        if ($template_id <= 0) {
            return false;
        }

        $path = self::file_path($template_id);

        $min = Eko_Sampa_Template_Thumbnail_Config::MIN_FILE_BYTES;

        return $path !== '' && is_readable($path) && filesize($path) > $min;
    }

    public static function acquire_generation_lock(int $template_id): bool {
        if ($template_id <= 0) {
            return false;
        }
        $key = self::TRANSIENT_LOCK . $template_id;
        if (get_transient($key)) {
            return false;
        }
        set_transient($key, (string) time(), Eko_Sampa_Template_Thumbnail_Config::LOCK_TTL_SECONDS);

        return true;
    }

    public static function release_generation_lock(int $template_id): void {
        delete_transient(self::TRANSIENT_LOCK . $template_id);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function needs_regeneration(array $row, string $visual_hash = ''): bool {
        $id = (int) ( $row['id'] ?? 0 );
        if ($id <= 0) {
            return true;
        }

        $hash = $visual_hash !== ''
            ? $visual_hash
            : Eko_Sampa_Template_Thumbnail_Visual::hash_from_row($row);
        $stored = (string) ( $row['thumbnail_visual_hash'] ?? '' );

        if ($hash !== '' && $stored === $hash && self::exists($id)) {
            return false;
        }

        return true;
    }

    public static function public_url(int $template_id, int $version = 0): string {
        if ($template_id <= 0 || ! self::exists($template_id)) {
            return '';
        }

        $dirs = Eko_Sampa_Storage_Manager::upload_dirs();
        if ($dirs['error'] || $dirs['baseurl'] === '') {
            return '';
        }

        $read = self::file_path($template_id);
        $rel  = Eko_Sampa_Storage_Manager::relative_from_abs($read);
        if ($rel === '') {
            // Fallback legacy URL shape.
            $file = sprintf(Eko_Sampa_Template_Thumbnail_Config::FILENAME_PATTERN, $template_id);
            $path = trailingslashit($dirs['baseurl']) . Eko_Sampa_Template_Thumbnail_Config::SUBDIR . '/' . $file;
        } else {
            $path = trailingslashit($dirs['baseurl']) . str_replace('\\', '/', $rel);
        }

        $ver = $version > 0 ? $version : self::file_version($template_id);
        if ($ver > 0) {
            $path .= '?v=' . $ver;
        }

        return $path;
    }

    public static function mark_generating(int $template_id): void {
        if ($template_id <= 0) {
            return;
        }
        set_transient(self::TRANSIENT_GENERATING . $template_id, (string) time(), 120);
    }

    public static function clear_generating(int $template_id): void {
        delete_transient(self::TRANSIENT_GENERATING . $template_id);
    }

    public static function is_generating(int $template_id): bool {
        return (bool) get_transient(self::TRANSIENT_GENERATING . $template_id);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function resolve_state(array $row): string {
        $id = (int) ( $row['id'] ?? 0 );
        if ($id <= 0) {
            return self::STATE_MISSING;
        }

        if (self::is_generating($id)) {
            return self::STATE_GENERATING;
        }

        if (! self::exists($id)) {
            return self::STATE_MISSING;
        }

        $stored_visual = (string) ( $row['thumbnail_visual_hash'] ?? '' );
        $current_visual = Eko_Sampa_Template_Thumbnail_Visual::hash_from_row($row);
        if ($stored_visual !== '' && $current_visual !== '' && $stored_visual !== $current_visual) {
            return self::STATE_STALE;
        }

        return self::STATE_READY;
    }

    /**
     * @return true|\WP_Error
     */
    public static function save_jpeg_binary(int $template_id, string $binary, string $visual_hash = ''): bool|\WP_Error {
        if ($template_id <= 0) {
            return new \WP_Error('eko_sampa_thumb_invalid', __('Invalid template.', 'eko-sampa'), ['status' => 400]);
        }

        $valid = Eko_Sampa_Template_Thumbnail_Validator::validate_jpeg_binary($binary);
        if ($valid instanceof \WP_Error) {
            return $valid;
        }

        $owner = self::template_owner_id($template_id);
        $path  = self::writable_abs($template_id, $owner);
        if ($path === '') {
            return new \WP_Error('eko_sampa_thumb_dir', __('Upload directory unavailable.', 'eko-sampa'), ['status' => 500]);
        }

        $dir = dirname($path);
        if (! Eko_Sampa_Storage_Manager::ensure_dir($dir)) {
            return new \WP_Error('eko_sampa_thumb_dir', __('Could not create thumbnail directory.', 'eko-sampa'), ['status' => 500]);
        }

        self::purge_legacy_variants($template_id, $path);

        $tmp = $path . '.tmp';
        if (false === file_put_contents($tmp, $binary)) {
            return new \WP_Error('eko_sampa_thumb_write', __('Could not write thumbnail.', 'eko-sampa'), ['status' => 500]);
        }

        if (is_readable($path)) {
            wp_delete_file($path);
        }

        if (! @rename($tmp, $path)) {
            wp_delete_file($tmp);

            return new \WP_Error('eko_sampa_thumb_replace', __('Could not replace thumbnail.', 'eko-sampa'), ['status' => 500]);
        }

        $version = self::file_version($template_id);
        $rel     = Eko_Sampa_Storage_Manager::relative_from_abs($path);
        if ($rel === '') {
            $rel = Eko_Sampa_Template_Thumbnail_Config::SUBDIR . '/'
                . sprintf(Eko_Sampa_Template_Thumbnail_Config::FILENAME_PATTERN, $template_id);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'eko_sampa_templates';
        $data  = ['preview_image' => $rel];
        $fmt   = ['%s'];
        if (self::table_has_thumbnail_version_column()) {
            $data['thumbnail_version'] = $version;
            $fmt[]                     = '%d';
        }
        if ($visual_hash !== '' && self::table_has_thumbnail_visual_hash_column()) {
            $data['thumbnail_visual_hash'] = substr($visual_hash, 0, 16);
            $fmt[]                         = '%s';
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update($table, $data, ['id' => $template_id], $fmt, ['%d']);

        self::clear_generating($template_id);
        self::release_generation_lock($template_id);

        return true;
    }

    /**
     * Remove accidental extra files (e.g. id-v123.jpg) — keep single canonical file only.
     */
    private static function purge_legacy_variants(int $template_id, string $canonical_path): void {
        $dir = dirname($canonical_path);
        if (! is_dir($dir)) {
            return;
        }

        $pattern = $dir . '/' . $template_id . '*.jpg';
        foreach (glob($pattern) ?: [] as $file) {
            if (! is_string($file) || $file === $canonical_path) {
                continue;
            }
            wp_delete_file($file);
        }
    }

    /**
     * @param string $data_url_or_base64 data:image/jpeg;base64,... or raw base64
     *
     * @return true|\WP_Error
     */
    public static function save_from_data_url(int $template_id, string $data_url_or_base64, string $visual_hash = ''): bool|\WP_Error {
        $raw = trim($data_url_or_base64);
        if (str_contains($raw, 'base64,')) {
            $parts = explode('base64,', $raw, 2);
            $raw   = $parts[1] ?? '';
        }

        $binary = base64_decode($raw, true);
        if (! is_string($binary) || $binary === '') {
            return new \WP_Error('eko_sampa_thumb_decode', __('Invalid thumbnail encoding.', 'eko-sampa'), ['status' => 400]);
        }

        return self::save_jpeg_binary($template_id, $binary, $visual_hash);
    }

    public static function delete(int $template_id): void {
        if ($template_id <= 0) {
            return;
        }

        self::clear_generating($template_id);
        $owner = self::template_owner_id($template_id);
        $paths = array_unique(
            array_filter(
                [
                    Eko_Sampa_Storage_Manager::user_template_thumbnail_abs($owner, $template_id),
                    Eko_Sampa_Storage_Manager::legacy_template_thumbnail_abs($template_id),
                ]
            )
        );
        foreach ($paths as $path) {
            if ($path !== '' && is_readable($path)) {
                wp_delete_file($path);
            }
            self::purge_legacy_variants($template_id, $path);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'eko_sampa_templates';
        $data  = ['preview_image' => ''];
        $fmt   = ['%s'];
        if (self::table_has_thumbnail_version_column()) {
            $data['thumbnail_version'] = 0;
            $fmt[]                     = '%d';
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update($table, $data, ['id' => $template_id], $fmt, ['%d']);
    }

    private static function table_has_thumbnail_version_column(): bool {
        return self::table_has_column('thumbnail_version');
    }

    private static function table_has_thumbnail_visual_hash_column(): bool {
        return self::table_has_column('thumbnail_visual_hash');
    }

    private static function table_has_column(string $column): bool {
        global $wpdb;
        $table  = $wpdb->prefix . 'eko_sampa_templates';
        $column = preg_replace('/[^a-z0-9_]/', '', $column) ?? '';
        if ($column === '') {
            return false;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $cols = $wpdb->get_results("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'", ARRAY_A);

        return is_array($cols) && $cols !== [];
    }

    public static function copy(int $from_id, int $to_id): bool {
        if ($from_id <= 0 || $to_id <= 0 || ! self::exists($from_id)) {
            return false;
        }

        $src = self::file_path($from_id);
        $bin = file_get_contents($src);
        if (! is_string($bin) || $bin === '') {
            return false;
        }

        return true === self::save_jpeg_binary($to_id, $bin);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function enrich_row(array $row): array {
        $id = (int) ( $row['id'] ?? 0 );
        if ($id <= 0) {
            return $row;
        }

        $version = self::file_version($id);
        if ($version <= 0) {
            $version = (int) ( $row['thumbnail_version'] ?? 0 );
        }

        $state                    = self::resolve_state($row);
        $row['thumbnail_version'] = $version;
        $row['thumbnail_state']   = $state;
        $row['has_thumbnail']          = self::exists($id);
        $row['thumbnail_url']          = $row['has_thumbnail'] ? self::public_url($id, $version) : '';
        $row['thumbnail_visual_hash']  = (string) ( $row['thumbnail_visual_hash'] ?? '' );
        if ($row['thumbnail_visual_hash'] === '') {
            $row['thumbnail_visual_hash'] = Eko_Sampa_Template_Thumbnail_Visual::hash_from_row($row);
        }

        $read = self::file_path($id);
        if ($state === self::STATE_READY && $version > 0 && $read !== '' && is_readable($read)) {
            $row['thumbnail_bytes'] = (int) filesize($read);
        }

        return $row;
    }

    private static function template_owner_id(int $template_id): int {
        global $wpdb;
        $table = $wpdb->prefix . 'eko_sampa_templates';
        $safe  = preg_replace('/[^a-z0-9_]/i', '', $table);
        if ($safe === '' || $safe !== $table) {
            return 0;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $uid = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM `{$safe}` WHERE id = %d LIMIT 1", $template_id));

        return absint((int) $uid);
    }

    private static function maybe_silent_migrate_legacy(int $template_id, int $owner_user_id, string $legacy_abs): void {
        if ($owner_user_id <= 0) {
            return;
        }
        if (! apply_filters('eko_sampa_storage_silent_migrate_thumbnail', true, $template_id, $owner_user_id)) {
            return;
        }
        $lock_key = 'eko_sampa_thumb_migrate_lock_' . $template_id;
        if (get_transient($lock_key)) {
            return;
        }
        set_transient($lock_key, '1', 60);

        $dest = Eko_Sampa_Storage_Manager::user_template_thumbnail_abs($owner_user_id, $template_id);
        if ($dest === '' || is_readable($dest)) {
            delete_transient($lock_key);

            return;
        }
        if (true !== Eko_Sampa_Storage_Manager::safe_copy($legacy_abs, $dest)) {
            delete_transient($lock_key);
            Eko_Sampa_Storage_Audit::append('thumbnail_migrate_failed', ['template_id' => $template_id, 'step' => 'copy']);

            return;
        }
        if (true !== Eko_Sampa_Storage_Manager::verify_copy_bytes($legacy_abs, $dest)) {
            Eko_Sampa_Storage_Manager::safe_unlink($dest);
            delete_transient($lock_key);
            Eko_Sampa_Storage_Audit::append('thumbnail_migrate_failed', ['template_id' => $template_id, 'step' => 'verify']);

            return;
        }
        $rel = Eko_Sampa_Storage_Manager::relative_from_abs($dest);
        if ($rel === '') {
            Eko_Sampa_Storage_Manager::safe_unlink($dest);
            delete_transient($lock_key);

            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'eko_sampa_templates';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update($table, ['preview_image' => $rel], ['id' => $template_id], ['%s'], ['%d']);
        delete_transient($lock_key);
        Eko_Sampa_Storage_Manager::audit('thumbnail_silent_migrated', ['template_id' => $template_id, 'user_id' => $owner_user_id]);
        Eko_Sampa_Storage_Audit::append('thumbnail_migrated', ['template_id' => $template_id, 'user_id' => $owner_user_id]);
    }
}
