<?php
/**
 * Persisted JPG thumbnails for templates (one file per template id).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Storage: {uploads}/eko-sampa/templates/{id}.jpg — single file, replaced atomically.
 */
final class Eko_Sampa_Template_Thumbnail {

    public const STATE_MISSING = 'missing';

    public const STATE_GENERATING = 'generating';

    public const STATE_READY = 'ready';

    public const STATE_FAILED = 'failed';

    public const STATE_STALE = 'stale';

    private const TRANSIENT_GENERATING = 'eko_sampa_thumb_gen_';

    public static function uploads_subdir(): string {
        return Eko_Sampa_Template_Thumbnail_Config::SUBDIR;
    }

    public static function file_path(int $template_id): string {
        $upload = wp_upload_dir();
        $base   = trailingslashit((string) ( $upload['basedir'] ?? '' ) );
        if ($base === '' || $upload['error'] ?? false) {
            return '';
        }

        return $base . Eko_Sampa_Template_Thumbnail_Config::SUBDIR . '/'
            . sprintf(Eko_Sampa_Template_Thumbnail_Config::FILENAME_PATTERN, max(0, $template_id));
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

        return $path !== '' && is_readable($path) && filesize($path) > 32;
    }

    public static function public_url(int $template_id, int $version = 0): string {
        if ($template_id <= 0 || ! self::exists($template_id)) {
            return '';
        }

        $upload = wp_upload_dir();
        if (! empty($upload['error'])) {
            return '';
        }

        $url = trailingslashit((string) ( $upload['baseurl'] ?? '' ) );
        if ($url === '/') {
            return '';
        }

        $file = sprintf(Eko_Sampa_Template_Thumbnail_Config::FILENAME_PATTERN, $template_id);
        $path = $url . Eko_Sampa_Template_Thumbnail_Config::SUBDIR . '/' . $file;

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

        $file_ver = self::file_version($id);
        $row_ver  = (int) ( $row['thumbnail_version'] ?? 0 );
        $tpl_at   = isset($row['updated_at']) ? strtotime((string) $row['updated_at']) : false;

        if ($tpl_at && $file_ver > 0 && $file_ver < (int) $tpl_at - 2) {
            return self::STATE_STALE;
        }

        if ($row_ver > 0 && $file_ver > 0 && $row_ver !== $file_ver) {
            return self::STATE_STALE;
        }

        return self::STATE_READY;
    }

    /**
     * @return true|\WP_Error
     */
    public static function save_jpeg_binary(int $template_id, string $binary): bool|\WP_Error {
        if ($template_id <= 0) {
            return new \WP_Error('eko_sampa_thumb_invalid', __('Invalid template.', 'eko-sampa'), ['status' => 400]);
        }

        $max = Eko_Sampa_Template_Thumbnail_Config::MAX_FILE_BYTES;
        if ($binary === '' || strlen($binary) < 32) {
            return new \WP_Error('eko_sampa_thumb_empty', __('Thumbnail data is empty.', 'eko-sampa'), ['status' => 400]);
        }

        if (strlen($binary) > $max) {
            return new \WP_Error(
                'eko_sampa_thumb_large',
                sprintf(
                    /* translators: %d: max kilobytes */
                    __('Thumbnail exceeds %d KB limit.', 'eko-sampa'),
                    (int) round($max / 1024)
                ),
                ['status' => 413]
            );
        }

        $path = self::file_path($template_id);
        if ($path === '') {
            return new \WP_Error('eko_sampa_thumb_dir', __('Upload directory unavailable.', 'eko-sampa'), ['status' => 500]);
        }

        $dir = dirname($path);
        if (! wp_mkdir_p($dir)) {
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
        $rel     = Eko_Sampa_Template_Thumbnail_Config::SUBDIR . '/'
            . sprintf(Eko_Sampa_Template_Thumbnail_Config::FILENAME_PATTERN, $template_id);

        global $wpdb;
        $table = $wpdb->prefix . 'eko_sampa_templates';
        $data  = ['preview_image' => $rel];
        $fmt   = ['%s'];
        if (self::table_has_thumbnail_version_column()) {
            $data['thumbnail_version'] = $version;
            $fmt[]                     = '%d';
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update($table, $data, ['id' => $template_id], $fmt, ['%d']);

        self::clear_generating($template_id);

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
    public static function save_from_data_url(int $template_id, string $data_url_or_base64): bool|\WP_Error {
        $raw = trim($data_url_or_base64);
        if (str_contains($raw, 'base64,')) {
            $parts = explode('base64,', $raw, 2);
            $raw   = $parts[1] ?? '';
        }

        $binary = base64_decode($raw, true);
        if (! is_string($binary) || $binary === '') {
            return new \WP_Error('eko_sampa_thumb_decode', __('Invalid thumbnail encoding.', 'eko-sampa'), ['status' => 400]);
        }

        return self::save_jpeg_binary($template_id, $binary);
    }

    public static function delete(int $template_id): void {
        if ($template_id <= 0) {
            return;
        }

        self::clear_generating($template_id);
        $path = self::file_path($template_id);
        if ($path !== '' && is_readable($path)) {
            wp_delete_file($path);
        }
        self::purge_legacy_variants($template_id, $path);

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
        global $wpdb;
        $table = $wpdb->prefix . 'eko_sampa_templates';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $cols = $wpdb->get_results("SHOW COLUMNS FROM `{$table}` LIKE 'thumbnail_version'", ARRAY_A);

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
        $row['has_thumbnail']     = self::exists($id);
        $row['thumbnail_url']     = $row['has_thumbnail'] ? self::public_url($id, $version) : '';

        if ($state === self::STATE_READY && $version > 0 && is_readable(self::file_path($id))) {
            $row['thumbnail_bytes'] = (int) filesize(self::file_path($id));
        }

        return $row;
    }
}
