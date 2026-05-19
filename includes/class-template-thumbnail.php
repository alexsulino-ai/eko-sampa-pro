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

    /** Persisted JPEG provenance (see {@see self::save_jpeg_binary()}). */
    public const CAPTURE_LIVE_EDITOR = 'live_editor';

    public const CAPTURE_CLIENT_DOM = 'client_dom';

    public const CAPTURE_SERVER_GD = 'server_gd';

    public const CAPTURE_CATALOG_BACKFILL = 'catalog_backfill';

    public const CAPTURE_SYNTHETIC = 'synthetic';

    public const CAPTURE_FALLBACK = 'fallback';

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

    /**
     * Normalize persisted / request capture tier labels.
     */
    public static function normalize_capture_source(string $raw): string {
        $k = strtolower(trim($raw));
        if ($k === '') {
            return '';
        }

        return match ($k) {
            'live_editor', 'live-editor', 'editor_live' => self::CAPTURE_LIVE_EDITOR,
            'client_dom', 'client-dom', 'client', 'client_raster', 'html2canvas', 'direct' => self::CAPTURE_CLIENT_DOM,
            'server_gd', 'server-gd', 'gd' => self::CAPTURE_SERVER_GD,
            'catalog_backfill', 'catalog-backfill', 'list_backfill' => self::CAPTURE_CATALOG_BACKFILL,
            'synthetic' => self::CAPTURE_SYNTHETIC,
            'fallback', 'editor_save_fallback', 'editor-save-fallback' => self::CAPTURE_FALLBACK,
            'editor_save', 'editor-save' => self::CAPTURE_CLIENT_DOM,
            default => preg_match('/^[a-z0-9_-]{1,32}$/', $k) ? $k : self::CAPTURE_CLIENT_DOM,
        };
    }

    /**
     * Map REST `source` on POST /thumbnail/generate to a capture tier.
     */
    public static function normalize_generate_request_source(string $raw): string {
        $k = sanitize_key($raw);

        return match ($k) {
            'catalog_backfill' => self::CAPTURE_CATALOG_BACKFILL,
            'editor_save_fallback' => self::CAPTURE_FALLBACK,
            'template_write_hook' => self::CAPTURE_SERVER_GD,
            default => self::CAPTURE_SERVER_GD,
        };
    }

    /**
     * @param array<string, mixed> $params JSON body for POST /templates/{id}/thumbnail
     */
    public static function resolve_capture_source_for_client_upload(array $params): string {
        if (isset($params['thumbnail_capture_source'])) {
            $v = self::normalize_capture_source((string) $params['thumbnail_capture_source']);

            return $v !== '' ? $v : self::CAPTURE_CLIENT_DOM;
        }
        if (isset($params['thumbnail_source'])) {
            $v = self::normalize_capture_source((string) $params['thumbnail_source']);

            return $v !== '' ? $v : self::CAPTURE_CLIENT_DOM;
        }
        $meta = isset($params['source']) ? sanitize_key((string) $params['source']) : '';

        return match ($meta) {
            'editor_save', 'editor-save' => self::CAPTURE_CLIENT_DOM,
            'editor_save_fallback', 'editor-save-fallback' => self::CAPTURE_FALLBACK,
            'catalog_backfill' => self::CAPTURE_CATALOG_BACKFILL,
            default => self::CAPTURE_CLIENT_DOM,
        };
    }

    public static function capture_tier_rank(string $tier): int {
        $t = self::normalize_capture_source($tier);

        return match ($t) {
            self::CAPTURE_LIVE_EDITOR => 100,
            self::CAPTURE_CLIENT_DOM => 80,
            self::CAPTURE_SERVER_GD => 35,
            self::CAPTURE_CATALOG_BACKFILL => 25,
            self::CAPTURE_SYNTHETIC => 22,
            self::CAPTURE_FALLBACK => 20,
            default => 15,
        };
    }

    /**
     * When true, skip server GD so a higher-fidelity on-disk thumbnail is not replaced.
     *
     * Allows regeneration when the stored visual hash no longer matches the current layout
     * (real stale thumbnails after layout edits).
     *
     * @param array<string, mixed> $row Template row including thumbnail_visual_hash
     */
    public static function refuse_regeneration_due_to_capture_tier(array $row, string $incoming_tier, bool $force): bool {
        if ($force) {
            return false;
        }
        $id = (int) ( $row['id'] ?? 0 );
        if ($id <= 0 || ! self::exists($id)) {
            return false;
        }

        $php_hash = Eko_Sampa_Template_Thumbnail_Visual::hash_from_row($row);
        $stored   = (string) ( $row['thumbnail_visual_hash'] ?? '' );
        $in_sync  = $stored !== '' && $php_hash !== '' && $stored === $php_hash;
        if (! $in_sync) {
            return false;
        }

        $existing = self::normalize_capture_source((string) ( $row['thumbnail_capture_source'] ?? '' ));

        return self::capture_tier_rank($existing) > self::capture_tier_rank($incoming_tier);
    }

    /**
     * Server-side raster after template create/update — only when no JPEG exists yet.
     *
     * @param array<string, mixed> $row
     */
    public static function should_auto_server_thumbnail_after_template_write(array $row): bool {
        if (! self::needs_regeneration($row)) {
            return false;
        }
        $id = (int) ( $row['id'] ?? 0 );
        if ($id <= 0) {
            return false;
        }

        if (Eko_Sampa_Template_Derivation::is_session_row($row)) {
            return false;
        }

        return ! self::exists($id);
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
     * @param bool               $lightweight When true, never decode `json_data` (public catalog REST).
     */
    public static function resolve_state(array $row, bool $lightweight = false): string {
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

        if ($lightweight) {
            return self::STATE_READY;
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
    public static function save_jpeg_binary(int $template_id, string $binary, string $visual_hash = '', string $capture_source = ''): bool|\WP_Error {
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
        $tier = self::normalize_capture_source($capture_source);
        if ($tier !== '' && self::table_has_thumbnail_capture_source_column()) {
            $data['thumbnail_capture_source'] = substr($tier, 0, 32);
            $fmt[]                            = '%s';
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update($table, $data, ['id' => $template_id], $fmt, ['%d']);

        self::clear_generating($template_id);
        self::release_generation_lock($template_id);

        Eko_Sampa_Storage_Audit::append(
            'thumbnail_jpeg_saved',
            [
                'template_id'    => $template_id,
                'capture_source' => $tier !== '' ? $tier : null,
                'visual_hash'    => $visual_hash !== '' ? substr($visual_hash, 0, 16) : '',
                'version'        => $version,
            ]
        );

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
     * @param string $visual_hash         optional 16-char checksum
     * @param string $capture_source      {@see self::CAPTURE_LIVE_EDITOR} etc.
     *
     * @return true|\WP_Error
     */
    public static function save_from_data_url(
        int $template_id,
        string $data_url_or_base64,
        string $visual_hash = '',
        string $capture_source = ''
    ): bool|\WP_Error {
        $raw = trim($data_url_or_base64);
        if (str_contains($raw, 'base64,')) {
            $parts = explode('base64,', $raw, 2);
            $raw   = $parts[1] ?? '';
        }

        $binary = base64_decode($raw, true);
        if (! is_string($binary) || $binary === '') {
            return new \WP_Error('eko_sampa_thumb_decode', __('Invalid thumbnail encoding.', 'eko-sampa'), ['status' => 400]);
        }

        return self::save_jpeg_binary($template_id, $binary, $visual_hash, $capture_source);
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
        if (self::table_has_thumbnail_capture_source_column()) {
            $data['thumbnail_capture_source'] = '';
            $fmt[]                            = '%s';
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

    private static function table_has_thumbnail_capture_source_column(): bool {
        return self::table_has_column('thumbnail_capture_source');
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

    /**
     * Copy persisted JPEG from one template id to another (user-scoped write for destination owner).
     *
     * When the source has no readable thumbnail, returns **true** (duplicate still valid) and records audit.
     * On filesystem / validation errors returns {@see \WP_Error} — caller may roll back the new template row.
     *
     * @return true|\WP_Error
     */
    public static function copy(int $from_id, int $to_id): bool|\WP_Error {
        if ($from_id <= 0 || $to_id <= 0) {
            return new \WP_Error(
                'eko_sampa_duplicate_thumbnail_invalid',
                __('Invalid template id for thumbnail copy.', 'eko-sampa'),
                ['status' => 400, 'failure_reason' => 'invalid_ids']
            );
        }

        if (! self::exists($from_id)) {
            Eko_Sampa_Storage_Manager::audit('duplicate_thumbnail_source_missing', ['from' => $from_id, 'to' => $to_id]);
            Eko_Sampa_Storage_Audit::append('duplicate_thumbnail_source_missing', ['from' => $from_id, 'to' => $to_id]);

            return true;
        }

        $src = self::file_path($from_id);
        if ($src === '' || ! is_readable($src)) {
            Eko_Sampa_Storage_Manager::audit('duplicate_thumbnail_source_unreadable', ['from' => $from_id, 'to' => $to_id]);
            Eko_Sampa_Storage_Audit::append('duplicate_thumbnail_source_unreadable', ['from' => $from_id, 'to' => $to_id]);

            return true;
        }

        $bin = file_get_contents($src);
        if (! is_string($bin) || $bin === '') {
            return new \WP_Error(
                'eko_sampa_duplicate_thumbnail_read',
                __('Could not read source thumbnail file.', 'eko-sampa'),
                ['status' => 500, 'failure_reason' => 'thumbnail_read_failed', 'from' => $from_id]
            );
        }

        $saved = self::save_jpeg_binary($to_id, $bin);
        if ($saved instanceof \WP_Error) {
            return $saved;
        }

        return true;
    }

    /**
     * Read-only diagnostics for POST /templates/{id}/duplicate or GET ?inspect_duplicate=1.
     *
     * @return array<string, mixed>
     */
    public static function inspect_duplicate_readiness(int $template_id): array {
        if ($template_id <= 0) {
            return ['template_id' => $template_id, 'ok' => false, 'code' => 'invalid_id'];
        }

        $owner = self::template_owner_id($template_id);
        global $wpdb;
        $table = $wpdb->prefix . 'eko_sampa_templates';
        $safe  = preg_replace('/[^a-z0-9_]/i', '', $table);
        $preview_rel = '';
        if ($safe !== '' && $safe === $table) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $preview_rel = (string) $wpdb->get_var($wpdb->prepare("SELECT preview_image FROM `{$safe}` WHERE id = %d LIMIT 1", $template_id));
        }

        $src_abs   = self::file_path($template_id);
        $thumb_rel = $src_abs !== '' ? Eko_Sampa_Storage_Manager::relative_from_abs($src_abs) : '';
        $preview_public = $preview_rel !== ''
            ? Eko_Sampa_Storage_Manager::public_url_for_upload_relative($preview_rel)
            : '';

        return [
            'template_id'              => $template_id,
            'owner_user_id'            => $owner,
            'thumbnail_readable'       => self::exists($template_id),
            'thumbnail_relative'       => $thumb_rel,
            'preview_image_stored'     => $preview_rel,
            'preview_image_public_ok'  => $preview_public !== '',
            'preview_image_broken_stored' => $preview_rel !== '' && $preview_public === '',
        ];
    }

    /**
     * Public marketing catalog: thumbnail + preview URLs only (no json_data / visual hash recompute).
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function enrich_row_for_public_catalog(array $row): array {
        unset($row['json_data']);

        $id = (int) ( $row['id'] ?? 0 );
        if ($id <= 0) {
            return $row;
        }

        $version = self::file_version($id);
        if ($version <= 0) {
            $version = (int) ( $row['thumbnail_version'] ?? 0 );
        }

        $has                         = self::exists($id);
        $row['thumbnail_version']    = $version;
        $row['thumbnail_state']      = self::resolve_state($row, true);
        $row['has_thumbnail']        = $has;
        $row['thumbnail_url']          = $has ? self::public_url($id, $version) : '';
        $row['thumbnail_capture_source'] = (string) ( $row['thumbnail_capture_source'] ?? '' );

        $preview_rel = trim((string) ( $row['preview_image'] ?? '' ));
        $stored_preview_url = $preview_rel !== ''
            ? Eko_Sampa_Storage_Manager::public_url_for_upload_relative($preview_rel)
            : '';
        $row['preview_image_resolved'] = $preview_rel !== '' && $stored_preview_url !== '';

        $display_preview = $stored_preview_url;
        if ($display_preview === '' && $row['has_thumbnail']) {
            $display_preview = (string) $row['thumbnail_url'];
        }
        $row['preview_image_public_url'] = $display_preview;

        return $row;
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
        $row['thumbnail_capture_source'] = (string) ( $row['thumbnail_capture_source'] ?? '' );

        $preview_rel = trim((string) ( $row['preview_image'] ?? '' ));
        $stored_preview_url = $preview_rel !== ''
            ? Eko_Sampa_Storage_Manager::public_url_for_upload_relative($preview_rel)
            : '';
        $row['preview_image_resolved'] = $preview_rel !== '' && $stored_preview_url !== '';

        $display_preview = $stored_preview_url;
        if ($display_preview === '' && $row['has_thumbnail']) {
            $display_preview = (string) $row['thumbnail_url'];
        }
        $row['preview_image_public_url'] = $display_preview;

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
