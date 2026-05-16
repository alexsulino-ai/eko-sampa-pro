<?php
/**
 * Filesystem snapshot for completed orders (immutable render source).
 *
 * Atomic build: `_staging/order-{id}-{uniq}/` → rename to `order-{id}/`.
 * Contract: `manifest.json` with `snapshot_schema_version` + `snapshot_complete` gates production readiness.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Writes under {@see Eko_Sampa_Storage_Manager::completed_order_dir_abs()}.
 */
final class Eko_Sampa_Order_Completed_Snapshot {

    public const SNAPSHOT_SCHEMA_VERSION = 1;

    private const MANIFEST_FILENAME = 'manifest.json';

    private const LOCK_TTL_SECONDS = 120;

    /** Option name prefix (options table; avoids transient race on parallel snapshot builds). */
    private const LOCK_OPTION_PREFIX = 'eko_sampa_snapshot_order_lock_';

    /**
     * True when snapshot is safe for production render (self-contained contract).
     */
    public static function is_ready(int $user_id, int $order_id): bool {
        $dir = Eko_Sampa_Storage_Manager::completed_order_dir_abs($user_id, $order_id);
        if ($dir === '' || ! is_dir($dir)) {
            return false;
        }

        $manifest_path = trailingslashit($dir) . self::MANIFEST_FILENAME;
        if (is_readable($manifest_path)) {
            $man = self::read_json_file($manifest_path);
            if (! is_array($man)) {
                return false;
            }
            if (empty($man['snapshot_complete'])) {
                return false;
            }

            return self::verify_manifest_against_disk($dir, $man);
        }

        // Legacy grandfathering: pre-manifest snapshots.
        return is_readable($dir . '/template-snapshot.json')
            && is_readable($dir . '/order.json');
    }

    /**
     * True when manifest.json exists with our schema (may be incomplete / failed).
     */
    public static function has_self_contained_manifest(int $user_id, int $order_id): bool {
        $dir = Eko_Sampa_Storage_Manager::completed_order_dir_abs($user_id, $order_id);
        if ($dir === '') {
            return false;
        }

        return is_readable(trailingslashit($dir) . self::MANIFEST_FILENAME);
    }

    /**
     * @param array<string, mixed> $order_row From DB (must include user_id, template_id, status=completed).
     * @param array<string, mixed> $template_row From DB (json_data, dimensions).
     *
     * @return true|\WP_Error
     */
    public static function create(array $order_row, array $template_row): bool|\WP_Error {
        $uid = (int) ($order_row['user_id'] ?? 0);
        $oid = (int) ($order_row['id'] ?? 0);
        if ($uid <= 0 || $oid <= 0) {
            return new \WP_Error('eko_sampa_snapshot', __('Invalid order for snapshot.', 'eko-sampa'), ['status' => 400]);
        }

        if (! self::acquire_lock($oid)) {
            return new \WP_Error(
                'eko_sampa_snapshot_locked',
                __('Snapshot generation already in progress for this order.', 'eko-sampa'),
                ['status' => 409]
            );
        }

        $final_dir = Eko_Sampa_Storage_Manager::completed_order_dir_abs($uid, $oid);
        if ($final_dir === '') {
            self::release_lock($oid);

            return new \WP_Error('eko_sampa_snapshot_dir', __('Could not resolve snapshot directory.', 'eko-sampa'), ['status' => 500]);
        }

        $staging_root = Eko_Sampa_Storage_Manager::completed_orders_staging_root_abs($uid);
        if ($staging_root === '' || ! Eko_Sampa_Storage_Manager::ensure_dir($staging_root)) {
            self::release_lock($oid);

            return new \WP_Error('eko_sampa_snapshot_dir', __('Could not create staging root.', 'eko-sampa'), ['status' => 500]);
        }

        $staging_dir = trailingslashit($staging_root) . 'order-' . $oid . '-' . preg_replace('/[^a-z0-9_-]/i', '', uniqid('', true));
        if (! Eko_Sampa_Storage_Manager::ensure_dir($staging_dir)) {
            self::release_lock($oid);

            return new \WP_Error('eko_sampa_snapshot_dir', __('Could not create staging directory.', 'eko-sampa'), ['status' => 500]);
        }

        $assets_dir = trailingslashit($staging_dir) . 'assets';
        Eko_Sampa_Storage_Manager::ensure_dir($assets_dir);

        $ctx = Eko_Sampa_Order::template_render_context($order_row);

        $tpl_id = (int) ($template_row['id'] ?? 0);
        $raw_json = $template_row['json_data'] ?? null;
        $json_str = is_string($raw_json) ? $raw_json : (is_array($raw_json) ? (wp_json_encode($raw_json, JSON_UNESCAPED_UNICODE) ?: '{}') : '{}');

        $dynamic = $order_row['dynamic_data_json'] ?? null;
        if (! is_string($dynamic)) {
            $dynamic = is_array($dynamic) ? (wp_json_encode($dynamic, JSON_UNESCAPED_UNICODE) ?: '{}') : '{}';
        }

        $extra_urls = self::extract_upload_urls_from_string($dynamic);

        [$frozen_json, $asset_inventory, $asset_map] = self::freeze_layout_assets(
            $json_str,
            $assets_dir,
            $extra_urls
        );

        $template_snapshot = [
            'version'     => self::SNAPSHOT_SCHEMA_VERSION,
            'frozen_at'   => gmdate('c'),
            'template_id' => $tpl_id,
            'width_mm'    => (int) ($template_row['width_mm'] ?? 210),
            'height_mm'   => (int) ($template_row['height_mm'] ?? 297),
            'json_data'   => $frozen_json,
        ];

        $order_manifest = [
            'version'   => self::SNAPSHOT_SCHEMA_VERSION,
            'frozen_at' => gmdate('c'),
            'order_id'  => $oid,
            'user_id'   => $uid,
            'client_id' => (int) ($order_row['client_id'] ?? 0),
            'service_id' => (int) ($order_row['service_id'] ?? 0),
            'template_id' => (int) ($order_row['template_id'] ?? 0),
            'woo_order_id' => (int) ($order_row['woo_order_id'] ?? 0),
            'print_ready' => (int) ($order_row['print_ready'] ?? 0),
            'status'     => (string) ($order_row['status'] ?? 'completed'),
            'service_fields_snapshot_json' => (string) ($order_row['service_fields_snapshot_json'] ?? ''),
        ];

        $writes = [
            'order.json'             => wp_json_encode($order_manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'template-snapshot.json' => wp_json_encode($template_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'dynamic-data.json'      => $dynamic,
            'render-context.json'    => wp_json_encode($ctx, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'asset-map.json'         => wp_json_encode($asset_map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        ];

        foreach ($writes as $name => $payload) {
            if (! is_string($payload) || $payload === '') {
                continue;
            }
            if (false === file_put_contents($staging_dir . '/' . $name, $payload)) {
                Eko_Sampa_Storage_Manager::delete_tree_under_eko($staging_dir);
                self::release_lock($oid);
                Eko_Sampa_Storage_Audit::append('snapshot_failed', ['order_id' => $oid, 'step' => 'write_json', 'file' => $name]);

                return new \WP_Error('eko_sampa_snapshot_write', __('Could not write snapshot payload.', 'eko-sampa'), ['status' => 500]);
            }
        }

        $preview_ok = self::copy_preview_jpeg($uid, $tpl_id, $staging_dir . '/preview.jpg');
        if ($preview_ok) {
            $asset_inventory[] = self::inventory_file(
                'preview.jpg',
                'template_thumbnail',
                $staging_dir . '/preview.jpg'
            );
        }

        $integrity = self::build_integrity_state($staging_dir, $asset_inventory);
        $manifest  = [
            'snapshot_schema_version'   => self::SNAPSHOT_SCHEMA_VERSION,
            'created_at'                => gmdate('c'),
            'order_id'                  => $oid,
            'template_id_original'      => $tpl_id,
            'snapshot_state'            => 'validating',
            'snapshot_complete'         => false,
            'integrity_state'           => $integrity['state'],
            'render_source'             => 'completed_snapshot',
            'assets'                    => $asset_inventory,
        ];

        if ($integrity['state'] !== 'ok') {
            $manifest['snapshot_state']    = 'failed';
            $manifest['integrity_detail']   = $integrity['detail'];
            file_put_contents(
                $staging_dir . '/' . self::MANIFEST_FILENAME,
                wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}'
            );
            Eko_Sampa_Storage_Manager::delete_tree_under_eko($staging_dir);
            self::release_lock($oid);
            Eko_Sampa_Storage_Audit::append('snapshot_failed', ['order_id' => $oid, 'integrity' => $integrity]);

            return new \WP_Error(
                'eko_sampa_snapshot_integrity',
                __('Snapshot integrity validation failed; snapshot was not published.', 'eko-sampa'),
                ['status' => 500, 'detail' => $integrity]
            );
        }

        $manifest['snapshot_state']    = 'ready';
        $manifest['snapshot_complete'] = true;
        $manifest['integrity_state']   = 'ok';
        if (false === file_put_contents(
            $staging_dir . '/' . self::MANIFEST_FILENAME,
            wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}'
        )) {
            Eko_Sampa_Storage_Manager::delete_tree_under_eko($staging_dir);
            self::release_lock($oid);

            return new \WP_Error('eko_sampa_snapshot_manifest', __('Could not write manifest.', 'eko-sampa'), ['status' => 500]);
        }

        if (! self::publish_staging_to_final($staging_dir, $final_dir, $uid, $oid)) {
            Eko_Sampa_Storage_Manager::delete_tree_under_eko($staging_dir);
            self::release_lock($oid);
            Eko_Sampa_Storage_Audit::append('snapshot_publish_failed', ['order_id' => $oid]);

            return new \WP_Error('eko_sampa_snapshot_publish', __('Could not publish snapshot atomically.', 'eko-sampa'), ['status' => 500]);
        }

        self::release_lock($oid);
        Eko_Sampa_Storage_Manager::audit('order_snapshot_created', ['order_id' => $oid, 'user_id' => $uid]);
        Eko_Sampa_Storage_Audit::append('snapshot_created', ['order_id' => $oid, 'user_id' => $uid, 'assets' => count($asset_inventory)]);

        return true;
    }

    /**
     * Remove snapshot tree for an order (used on order delete).
     */
    public static function delete_for_order(int $user_id, int $order_id): void {
        $dir = Eko_Sampa_Storage_Manager::completed_order_dir_abs($user_id, $order_id);
        if ($dir !== '') {
            Eko_Sampa_Storage_Manager::delete_tree_under_eko($dir);
        }
        $staging_root = Eko_Sampa_Storage_Manager::completed_orders_staging_root_abs($user_id);
        if ($staging_root !== '' && is_dir($staging_root)) {
            foreach (glob(trailingslashit($staging_root) . 'order-' . $order_id . '-*') ?: [] as $orphan) {
                if (is_string($orphan) && is_dir($orphan)) {
                    Eko_Sampa_Storage_Manager::delete_tree_under_eko($orphan);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $order_row
     *
     * @return array{template_row: array<string, mixed>, context: array<string, string>}|null
     */
    public static function load_render_bundle(array $order_row): ?array {
        $uid = (int) ($order_row['user_id'] ?? 0);
        $oid = (int) ($order_row['id'] ?? 0);
        if ($uid <= 0 || $oid <= 0 || ! self::is_ready($uid, $oid)) {
            return null;
        }

        $dir = Eko_Sampa_Storage_Manager::completed_order_dir_abs($uid, $oid);
        $mf  = trailingslashit($dir) . self::MANIFEST_FILENAME;
        if (is_readable($mf)) {
            $man = self::read_json_file($mf);
            if (is_array($man) && empty($man['snapshot_complete'])) {
                return null;
            }
        }

        $tpl = self::read_json_file($dir . '/template-snapshot.json');
        if (! is_array($tpl)) {
            return null;
        }
        $ctx_raw = self::read_json_file($dir . '/render-context.json');
        $ctx     = is_array($ctx_raw) ? self::stringify_context($ctx_raw) : [];

        $json_data = $tpl['json_data'] ?? null;
        if (is_array($json_data)) {
            $json_data = wp_json_encode($json_data, JSON_UNESCAPED_UNICODE) ?: '{}';
        } elseif (! is_string($json_data)) {
            $json_data = '{}';
        }

        $template_row = [
            'id'            => (int) ($tpl['template_id'] ?? 0),
            'width_mm'      => (int) ($tpl['width_mm'] ?? 210),
            'height_mm'     => (int) ($tpl['height_mm'] ?? 297),
            'json_data'     => $json_data,
            'nome'          => 'snapshot',
            'preview_image' => '',
        ];

        return [
            'template_row' => $template_row,
            'context'      => $ctx,
        ];
    }

    /**
     * Hints for REST consumers when rendering completed orders (fallback risk surfacing).
     *
     * @return array{
     *   render_warning: bool,
     *   integrity_warning: bool,
     *   snapshot_missing: bool,
     *   legacy_snapshot_without_manifest: bool
     * }
     */
    public static function render_integrity_hints(int $user_id, int $order_id, string $order_status): array {
        if ($order_status !== 'completed') {
            return [
                'render_warning'                   => false,
                'integrity_warning'                => false,
                'snapshot_missing'                 => false,
                'legacy_snapshot_without_manifest' => false,
            ];
        }

        $ready  = self::is_ready($user_id, $order_id);
        $hasMan = self::has_self_contained_manifest($user_id, $order_id);
        $warn   = ! $ready || ( $ready && ! $hasMan );

        return [
            'render_warning'                   => $warn,
            'integrity_warning'                => $warn,
            'snapshot_missing'                 => ! $ready,
            'legacy_snapshot_without_manifest' => $ready && ! $hasMan,
        ];
    }

    private static function lock_option_name(int $order_id): string {
        return self::LOCK_OPTION_PREFIX . $order_id;
    }

    private static function acquire_lock(int $order_id): bool {
        $opt = self::lock_option_name($order_id);
        $now = time();
        $raw = get_option($opt, false);
        if ($raw !== false) {
            $started = (int) $raw;
            if ($now - $started < self::LOCK_TTL_SECONDS) {
                return false;
            }
            delete_option($opt);
        }

        return add_option($opt, $now, '', 'no');
    }

    private static function release_lock(int $order_id): void {
        delete_option(self::lock_option_name($order_id));
    }

    /**
     * Promote staging directory to final `order-{id}` atomically (best-effort cross-platform).
     */
    private static function publish_staging_to_final(string $staging_dir, string $final_dir, int $user_id, int $order_id): bool {
        if (true !== Eko_Sampa_Storage_Manager::safe_path_guard($staging_dir, 'delete_tree', true)) {
            return false;
        }
        if (is_dir($final_dir)) {
            $mf = trailingslashit($final_dir) . self::MANIFEST_FILENAME;
            if (is_readable($mf)) {
                $old = self::read_json_file($mf);
                if (is_array($old) && ! empty($old['snapshot_complete'])) {
                    // Do not replace a valid production snapshot silently.
                    Eko_Sampa_Storage_Manager::delete_tree_under_eko($staging_dir);
                    self::release_lock($order_id);

                    return true;
                }
            }
            if (! Eko_Sampa_Storage_Manager::delete_tree_under_eko($final_dir)) {
                return false;
            }
        }

        if (! @rename($staging_dir, $final_dir)) {
            // Fallback: recursive copy then delete staging (non-atomic).
            if (! self::recursive_copy_dir($staging_dir, $final_dir)) {
                return false;
            }
            Eko_Sampa_Storage_Manager::delete_tree_under_eko($staging_dir);
        }

        return is_dir($final_dir) && self::is_ready($user_id, $order_id);
    }

    private static function recursive_copy_dir(string $src, string $dst): bool {
        if (! is_dir($src)) {
            return false;
        }
        if (! Eko_Sampa_Storage_Manager::ensure_dir($dst)) {
            return false;
        }
        $items = @scandir($src);
        if (! is_array($items)) {
            return false;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $from = trailingslashit($src) . $item;
            $to   = trailingslashit($dst) . $item;
            if (is_dir($from)) {
                if (! self::recursive_copy_dir($from, $to)) {
                    return false;
                }
            } else {
                if (true !== Eko_Sampa_Storage_Manager::safe_copy($from, $to)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return array{state: string, detail: string}
     */
    private static function build_integrity_state(string $base_dir, array $inventory): array {
        foreach ($inventory as $row) {
            if (! is_array($row)) {
                return ['state' => 'invalid', 'detail' => 'malformed_inventory'];
            }
            $rel = (string) ( $row['path'] ?? '' );
            if ($rel === '' || str_contains($rel, '..')) {
                return ['state' => 'invalid', 'detail' => 'bad_relative_path'];
            }
            $abs = trailingslashit($base_dir) . ltrim($rel, '/');
            if (! is_readable($abs)) {
                return ['state' => 'missing_file', 'detail' => $rel];
            }
            $sz = (int) ( $row['size'] ?? 0 );
            $fs = (int) filesize($abs);
            if ($sz > 0 && $sz !== $fs) {
                return ['state' => 'size_mismatch', 'detail' => $rel];
            }
            $sha = (string) ( $row['sha1'] ?? '' );
            if ($sha !== '' && is_readable($abs)) {
                $h = sha1_file($abs);
                if (! is_string($h) || strtolower($h) !== strtolower($sha)) {
                    return ['state' => 'hash_mismatch', 'detail' => $rel];
                }
            }
        }

        return ['state' => 'ok', 'detail' => ''];
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private static function verify_manifest_against_disk(string $dir, array $manifest): bool {
        $assets = $manifest['assets'] ?? [];
        if (! is_array($assets)) {
            return false;
        }
        $st = self::build_integrity_state($dir, $assets);

        return $st['state'] === 'ok';
    }

    /**
     * @return list<string>
     */
    private static function extract_upload_urls_from_string(string $blob): array {
        $out = [];
        if (preg_match_all('#(https?://[^\s"\'<>]+|/wp-content/uploads/[^\s"\'<>]+)#i', $blob, $m) && ! empty($m[1])) {
            foreach ($m[1] as $u) {
                $u = (string) $u;
                if (str_contains($u, 'wp-content/uploads') || str_contains($u, 'eko-sampa')) {
                    $out[] = $u;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param list<string> $extra_urls
     *
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>, 2: array<string, string>}
     */
    private static function freeze_layout_assets(string $json_data, string $assets_dir, array $extra_urls): array {
        $decoded = json_decode($json_data, true);
        if (JSON_ERROR_NONE !== json_last_error() || ! is_array($decoded)) {
            $decoded = ['elements' => []];
        }

        $map         = [];
        $inventory   = [];
        $elements    = $decoded['elements'] ?? null;
        if (! is_array($elements)) {
            $elements = [];
        }

        $image_urls  = [];

        foreach ($elements as $el) {
            if (! is_array($el) || ($el['type'] ?? '') !== 'image') {
                continue;
            }
            $src = isset($el['src']) ? (string) $el['src'] : '';
            if ($src !== '') {
                $image_urls[] = $src;
            }
            $styles = $el['styles'] ?? null;
            if (is_array($styles)) {
                foreach ($styles as $sv) {
                    if (is_string($sv)) {
                        foreach (self::extract_upload_urls_from_string($sv) as $u) {
                            $image_urls[] = $u;
                        }
                    }
                }
            }
        }

        $image_urls = array_merge($image_urls, $extra_urls);
        $image_urls = array_values(array_unique($image_urls));

        $dirs = Eko_Sampa_Storage_Manager::upload_dirs();
        $i    = 0;
        foreach ($image_urls as $src) {
            if (isset($map[ $src ])) {
                continue;
            }
            $abs = Eko_Sampa_Storage_Manager::uploads_url_to_abs($src);
            if ($abs === '' && str_starts_with($src, '/')) {
                $d = Eko_Sampa_Storage_Manager::upload_dirs();
                if (! $d['error'] && $d['basedir'] !== '') {
                    $abs = wp_normalize_path(trailingslashit($d['basedir']) . ltrim($src, '/'));
                }
            }
            if ($abs === '' || ! is_readable($abs)) {
                continue;
            }
            if (true !== Eko_Sampa_Storage_Manager::safe_path_guard($abs, 'read', false)) {
                continue;
            }

            $ext  = pathinfo($abs, PATHINFO_EXTENSION) ?: 'bin';
            $ext  = preg_replace('/[^a-z0-9]/i', '', $ext) ?: 'bin';
            $name = 'asset-' . $i . '.' . strtolower($ext);
            ++$i;
            $dest = trailingslashit($assets_dir) . $name;
            if (true !== Eko_Sampa_Storage_Manager::safe_copy($abs, $dest)) {
                continue;
            }
            if (true !== Eko_Sampa_Storage_Manager::verify_copy_bytes($abs, $dest)) {
                Eko_Sampa_Storage_Manager::safe_unlink($dest);
                continue;
            }
            $rel = Eko_Sampa_Storage_Manager::relative_from_abs($dest);
            $pub = $rel !== '' && ! $dirs['error']
                ? trailingslashit($dirs['baseurl']) . str_replace('\\', '/', $rel)
                : '';
            if ($pub === '') {
                Eko_Sampa_Storage_Manager::safe_unlink($dest);
                continue;
            }
            $map[ $src ]       = $pub;
            $inventory[]     = self::inventory_file('assets/' . $name, $src, $dest);
        }

        foreach ($elements as $idx => $el) {
            if (! is_array($el) || ($el['type'] ?? '') !== 'image') {
                continue;
            }
            $src = isset($el['src']) ? (string) $el['src'] : '';
            if ($src !== '' && isset($map[ $src ])) {
                $elements[ $idx ]['src'] = $map[ $src ];
                if (isset($elements[ $idx ]['content'])) {
                    $elements[ $idx ]['content'] = $map[ $src ];
                }
            }
        }

        $decoded['elements'] = $elements;

        return [$decoded, $inventory, $map];
    }

    /**
     * @return array<string, mixed>
     */
    private static function inventory_file(string $relative_path, string $source, string $abs): array {
        $mime = 'application/octet-stream';
        if (function_exists('mime_content_type')) {
            $m = @mime_content_type($abs);
            if (is_string($m) && $m !== '') {
                $mime = $m;
            }
        }
        $size = is_readable($abs) ? (int) filesize($abs) : 0;
        $sha1  = is_readable($abs) && $size > 0 ? (string) sha1_file($abs) : '';

        return [
            'path'   => $relative_path,
            'source' => $source,
            'sha1'   => $sha1,
            'mime'   => $mime,
            'size'   => $size,
            'exists' => $size > 0,
        ];
    }

    private static function copy_preview_jpeg(int $user_id, int $template_id, string $dest_jpg): bool {
        $candidates = [];
        $new        = Eko_Sampa_Storage_Manager::user_template_thumbnail_abs($user_id, $template_id);
        if ($new !== '' && is_readable($new)) {
            $candidates[] = $new;
        }
        $legacy = Eko_Sampa_Storage_Manager::legacy_template_thumbnail_abs($template_id);
        if ($legacy !== '' && is_readable($legacy)) {
            $candidates[] = $legacy;
        }
        foreach ($candidates as $c) {
            if (true === Eko_Sampa_Storage_Manager::safe_copy($c, $dest_jpg)
                && true === Eko_Sampa_Storage_Manager::verify_copy_bytes($c, $dest_jpg)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function read_json_file(string $path): ?array {
        if (! is_readable($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $d = json_decode($raw, true);

        return JSON_ERROR_NONE === json_last_error() && is_array($d) ? $d : null;
    }

    /**
     * @param array<string, mixed> $ctx
     *
     * @return array<string, string>
     */
    private static function stringify_context(array $ctx): array {
        $out = [];
        foreach ($ctx as $k => $v) {
            $out[ (string) $k ] = is_scalar($v) ? (string) $v : (wp_json_encode($v) ?: '');
        }

        return $out;
    }
}
