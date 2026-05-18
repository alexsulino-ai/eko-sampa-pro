<?php
/**
 * Quick print jobs — isolated from orders (operational print sessions only).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Persistence for {@see Eko_Sampa_Rest_Api} quick-print routes.
 */
final class Eko_Sampa_Quick_Print_Job {

    public const STATUS_QUEUED            = 'queued';

    public const STATUS_SENT_TO_BROWSER   = 'sent_to_browser';

    public const STATUS_COMPLETED         = 'completed';

    public const STATUS_CANCELLED         = 'cancelled';

    public const STATUS_FAILED            = 'failed';

    public function is_storage_ready(): bool {
        return ( new Eko_Sampa_Database() )->table_exists_for_suffix('eko_sampa_quick_print_jobs');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get_for_user(int $id, int $user_id): ?array {
        global $wpdb;

        if (! $this->is_storage_ready() || $id <= 0 || $user_id <= 0) {
            return null;
        }

        $t = $wpdb->prefix . 'eko_sampa_quick_print_jobs';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$t}` WHERE id = %d AND user_id = %d LIMIT 1",
                $id,
                $user_id
            ),
            ARRAY_A
        );

        return is_array($row) ? $this->normalize_row($row) : null;
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function create(
        int $user_id,
        int $template_id,
        int $quantity,
        string $printer_key,
        string $preset_key
    ) {
        global $wpdb;

        if (! $this->is_storage_ready()) {
            return new \WP_Error(
                'eko_sampa_quick_print_unavailable',
                __('Quick print storage is not available yet. Run database migrations or contact the administrator.', 'eko-sampa'),
                ['status' => 503]
            );
        }

        if ($user_id <= 0 || $template_id <= 0) {
            return new \WP_Error('eko_sampa_invalid', __('Invalid request.', 'eko-sampa'), ['status' => 400]);
        }

        $qty = max(1, min(500, $quantity));
        $pk  = sanitize_key($printer_key);
        if ($pk === '') {
            $pk = '__system__';
        }
        $preset = sanitize_key($preset_key);
        if ($preset === '') {
            $preset = 'default';
        }

        $t = $wpdb->prefix . 'eko_sampa_quick_print_jobs';
        $ok = $wpdb->insert(
            $t,
            [
                'user_id'      => $user_id,
                'template_id'  => $template_id,
                'status'       => self::STATUS_QUEUED,
                'quantity'     => $qty,
                'printer_key'  => substr($pk, 0, 191),
                'preset_key'   => substr($preset, 0, 191),
                'created_at'   => current_time('mysql'),
                'updated_at'   => current_time('mysql'),
            ],
            ['%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s']
        );

        if (! $ok) {
            return new \WP_Error(
                'eko_sampa_quick_print_create_failed',
                __('Could not create quick print job.', 'eko-sampa'),
                ['status' => 500]
            );
        }

        $id = (int) $wpdb->insert_id;

        return $this->get_for_user($id, $user_id) ?? [];
    }

    /**
     * @return true|\WP_Error
     */
    public function set_status(int $id, int $user_id, string $status) {
        global $wpdb;

        if (! $this->is_storage_ready() || $id <= 0 || $user_id <= 0) {
            return new \WP_Error('eko_sampa_quick_print_unavailable', __('Quick print unavailable.', 'eko-sampa'), ['status' => 503]);
        }

        $st = sanitize_key($status);
        if (! in_array($st, self::valid_statuses(), true)) {
            return new \WP_Error('eko_sampa_invalid', __('Invalid status.', 'eko-sampa'), ['status' => 400]);
        }

        $t = $wpdb->prefix . 'eko_sampa_quick_print_jobs';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $n = $wpdb->update(
            $t,
            [
                'status'     => $st,
                'updated_at' => current_time('mysql'),
            ],
            [
                'id'      => $id,
                'user_id' => $user_id,
            ],
            ['%s', '%s'],
            ['%d', '%d']
        );

        if ($n === false) {
            return new \WP_Error('eko_sampa_quick_print_update_failed', __('Could not update job.', 'eko-sampa'), ['status' => 500]);
        }

        if ((int) $n < 1) {
            return new \WP_Error('eko_sampa_not_found', __('Not found.', 'eko-sampa'), ['status' => 404]);
        }

        return true;
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function reprint_clone(int $source_id, int $user_id) {
        $src = $this->get_for_user($source_id, $user_id);
        if (! is_array($src)) {
            return new \WP_Error('eko_sampa_not_found', __('Not found.', 'eko-sampa'), ['status' => 404]);
        }

        return $this->create(
            $user_id,
            (int) ( $src['template_id'] ?? 0 ),
            (int) ( $src['quantity'] ?? 1 ),
            (string) ( $src['printer_key'] ?? '__system__' ),
            (string) ( $src['preset_key'] ?? 'default' )
        );
    }

    /**
     * @return list<string>
     */
    public static function valid_statuses(): array {
        return [
            self::STATUS_QUEUED,
            self::STATUS_SENT_TO_BROWSER,
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
            self::STATUS_FAILED,
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function normalize_row(array $row): array {
        return [
            'id'           => (int) ( $row['id'] ?? 0 ),
            'user_id'      => (int) ( $row['user_id'] ?? 0 ),
            'template_id' => (int) ( $row['template_id'] ?? 0 ),
            'status'       => (string) ( $row['status'] ?? '' ),
            'quantity'     => (int) ( $row['quantity'] ?? 1 ),
            'printer_key'  => (string) ( $row['printer_key'] ?? '' ),
            'preset_key'   => (string) ( $row['preset_key'] ?? '' ),
            'created_at'   => (string) ( $row['created_at'] ?? '' ),
            'updated_at'   => (string) ( $row['updated_at'] ?? '' ),
        ];
    }
}
