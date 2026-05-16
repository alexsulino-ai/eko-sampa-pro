<?php
/**
 * Filesystem snapshot for completed orders (immutable render source).
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

    private const MANIFEST_VERSION = 1;

    /**
     * Whether snapshot on disk is complete enough for render fallback.
     */
    public static function is_ready(int $user_id, int $order_id): bool {
        $dir = Eko_Sampa_Storage_Manager::completed_order_dir_abs($user_id, $order_id);
        if ($dir === '') {
            return false;
        }

        return is_readable($dir . '/template-snapshot.json')
            && is_readable($dir . '/order.json');
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

        $dir = Eko_Sampa_Storage_Manager::completed_order_dir_abs($uid, $oid);
        if ($dir === '' || ! Eko_Sampa_Storage_Manager::ensure_dir($dir)) {
            return new \WP_Error('eko_sampa_snapshot_dir', __('Could not create snapshot directory.', 'eko-sampa'), ['status' => 500]);
        }

        $assets_dir = trailingslashit($dir) . 'assets';
        Eko_Sampa_Storage_Manager::ensure_dir($assets_dir);

        $ctx = Eko_Sampa_Order::template_render_context($order_row);

        $tpl_id = (int) ($template_row['id'] ?? 0);
        $raw_json = $template_row['json_data'] ?? null;
        $json_str = is_string($raw_json) ? $raw_json : (is_array($raw_json) ? (wp_json_encode($raw_json, JSON_UNESCAPED_UNICODE) ?: '{}') : '{}');

        [$frozen_json, $asset_map] = self::freeze_layout_assets($json_str, $assets_dir);

        $template_snapshot = [
            'version'     => self::MANIFEST_VERSION,
            'frozen_at'   => gmdate('c'),
            'template_id' => $tpl_id,
            'width_mm'    => (int) ($template_row['width_mm'] ?? 210),
            'height_mm'   => (int) ($template_row['height_mm'] ?? 297),
            'json_data'   => $frozen_json,
        ];

        $order_manifest = [
            'version'   => self::MANIFEST_VERSION,
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

        $dynamic = $order_row['dynamic_data_json'] ?? null;
        if (! is_string($dynamic)) {
            $dynamic = is_array($dynamic) ? (wp_json_encode($dynamic, JSON_UNESCAPED_UNICODE) ?: '{}') : '{}';
        }

        $writes = [
            'order.json'               => wp_json_encode($order_manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'template-snapshot.json'   => wp_json_encode($template_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'dynamic-data.json'        => $dynamic,
            'render-context.json'      => wp_json_encode($ctx, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'asset-map.json'           => wp_json_encode($asset_map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        ];

        foreach ($writes as $name => $payload) {
            if (! is_string($payload) || $payload === '') {
                continue;
            }
            $ok = file_put_contents($dir . '/' . $name, $payload);
            if (false === $ok) {
                return new \WP_Error('eko_sampa_snapshot_write', __('Could not write snapshot manifest.', 'eko-sampa'), ['status' => 500]);
            }
        }

        self::copy_preview_jpeg($uid, $tpl_id, $dir . '/preview.jpg');

        Eko_Sampa_Storage_Manager::audit('order_snapshot_created', ['order_id' => $oid, 'user_id' => $uid]);

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
            'id'         => (int) ($tpl['template_id'] ?? 0),
            'width_mm'   => (int) ($tpl['width_mm'] ?? 210),
            'height_mm'  => (int) ($tpl['height_mm'] ?? 297),
            'json_data'  => $json_data,
            'nome'       => 'snapshot',
            'preview_image' => '',
        ];

        return [
            'template_row' => $template_row,
            'context'      => $ctx,
        ];
    }

    /**
     * @return array{0: array<string, mixed>|array<int, mixed>, 1: array<string, string>}
     */
    private static function freeze_layout_assets(string $json_data, string $assets_dir): array {
        $decoded = json_decode($json_data, true);
        if (JSON_ERROR_NONE !== json_last_error() || ! is_array($decoded)) {
            return [['elements' => []], []];
        }

        $map      = [];
        $elements = $decoded['elements'] ?? null;
        if (! is_array($elements)) {
            return [$decoded, $map];
        }

        $dirs = Eko_Sampa_Storage_Manager::upload_dirs();
        $i    = 0;
        foreach ($elements as $idx => $el) {
            if (! is_array($el) || ($el['type'] ?? '') !== 'image') {
                continue;
            }
            $src = isset($el['src']) ? (string) $el['src'] : '';
            if ($src === '') {
                continue;
            }
            if (isset($map[ $src ])) {
                $elements[ $idx ]['src'] = $map[ $src ];
                if (isset($elements[ $idx ]['content'])) {
                    $elements[ $idx ]['content'] = $map[ $src ];
                }

                continue;
            }

            $abs = Eko_Sampa_Storage_Manager::uploads_url_to_abs($src);
            if ($abs === '' || ! is_readable($abs)) {
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
            $rel = Eko_Sampa_Storage_Manager::relative_from_abs($dest);
            $pub = $rel !== '' && ! $dirs['error']
                ? trailingslashit($dirs['baseurl']) . str_replace('\\', '/', $rel)
                : '';
            if ($pub === '') {
                continue;
            }
            $map[ $src ]             = $pub;
            $elements[ $idx ]['src'] = $pub;
            if (isset($elements[ $idx ]['content'])) {
                $elements[ $idx ]['content'] = $pub;
            }
        }

        $decoded['elements'] = $elements;

        return [$decoded, $map];
    }

    private static function copy_preview_jpeg(int $user_id, int $template_id, string $dest_jpg): void {
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
            if (true === Eko_Sampa_Storage_Manager::safe_copy($c, $dest_jpg)) {
                return;
            }
        }
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
