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

    public const STATUS_ABANDONED         = 'abandoned';

    public function is_storage_ready(): bool {
        return ( new Eko_Sampa_Database() )->table_exists_for_suffix('eko_sampa_quick_print_jobs');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get_for_user(int $id, int $user_id, string $guest_session_token = ''): ?array {
        global $wpdb;

        if (! $this->is_storage_ready() || $id <= 0) {
            return null;
        }

        $t = $wpdb->prefix . 'eko_sampa_quick_print_jobs';

        if ($user_id > 0) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM `{$t}` WHERE id = %d AND user_id = %d LIMIT 1",
                    $id,
                    $user_id
                ),
                ARRAY_A
            );

            $out = is_array($row) ? $this->normalize_row($row) : null;
            if (is_array($out) && ! $this->job_row_usable($out)) {
                return null;
            }

            return $out;
        }

        $tok = preg_replace('/[^a-f0-9]/i', '', $guest_session_token);
        if ($tok === '') {
            return null;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$t}` WHERE id = %d AND user_id = 0 AND session_token = %s LIMIT 1",
                $id,
                $tok
            ),
            ARRAY_A
        );

        $out = is_array($row) ? $this->normalize_row($row) : null;
        if (is_array($out) && ! $this->job_row_usable($out)) {
            return null;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function job_row_usable(array $row): bool {
        $st = (string) ( $row['status'] ?? '' );
        if ($st === self::STATUS_ABANDONED) {
            return false;
        }
        $exp = isset($row['expires_at']) ? trim((string) $row['expires_at']) : '';
        if ($exp !== '' && $exp !== '0000-00-00 00:00:00') {
            $ts = strtotime($exp . ' UTC');
            if ($ts !== false && $ts <= time()) {
                return false;
            }
        }

        $tpl_id = (int) ( $row['template_id'] ?? 0 );
        if ($tpl_id > 0) {
            $tpl = ( new Eko_Sampa_Template() )->get_row_by_id($tpl_id);
            if (is_array($tpl) && Eko_Sampa_Template_Derivation::is_session_row($tpl)
                && ! Eko_Sampa_Template_Derivation::session_is_usable($tpl)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function create(
        int $user_id,
        int $template_id,
        int $quantity,
        string $printer_key,
        string $preset_key,
        string $guest_session_token = ''
    ) {
        global $wpdb;

        if (! $this->is_storage_ready()) {
            return new \WP_Error(
                'eko_sampa_quick_print_unavailable',
                __('Quick print storage is not available yet. Run database migrations or contact the administrator.', 'eko-sampa'),
                ['status' => 503]
            );
        }

        if ($template_id <= 0) {
            return new \WP_Error('eko_sampa_invalid', __('Invalid request.', 'eko-sampa'), ['status' => 400]);
        }

        $tpl = null;

        $guest_tok = preg_replace('/[^a-f0-9]/i', '', $guest_session_token);
        if ($user_id <= 0) {
            if ($guest_tok === '') {
                return new \WP_Error('eko_sampa_invalid', __('Invalid request.', 'eko-sampa'), ['status' => 400]);
            }
            $tpl = ( new Eko_Sampa_Template() )->get_row_by_id($template_id);
            if (! Eko_Sampa_Template_Derivation::request_can_use_session_row($tpl, $guest_tok)) {
                return new \WP_Error('eko_sampa_not_found', __('Not found.', 'eko-sampa'), ['status' => 404]);
            }
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
        $row = [
            'user_id'       => max(0, $user_id),
            'template_id'   => $template_id,
            'status'        => self::STATUS_QUEUED,
            'quantity'      => $qty,
            'printer_key'   => substr($pk, 0, 191),
            'preset_key'    => substr($preset, 0, 191),
            'created_at'    => current_time('mysql'),
            'updated_at'    => current_time('mysql'),
        ];
        $formats = ['%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s'];

        if ($user_id <= 0) {
            $row['session_token'] = $guest_tok;
            $formats[]          = '%s';
            $tpl_for_exp         = isset($tpl) && is_array($tpl) ? $tpl : null;
            $row['expires_at']   = Eko_Sampa_Template_Derivation::quick_print_job_expires_at_for_guest($tpl_for_exp);
            $formats[]           = '%s';
        }

        $ok = $wpdb->insert($t, $row, $formats);

        if (! $ok) {
            return new \WP_Error(
                'eko_sampa_quick_print_create_failed',
                __('Could not create quick print job.', 'eko-sampa'),
                ['status' => 500]
            );
        }

        $id = (int) $wpdb->insert_id;

        return $this->get_for_user($id, max(0, $user_id), $user_id <= 0 ? $guest_tok : '') ?? [];
    }

    /**
     * @return true|\WP_Error
     */
    public function set_status(int $id, int $user_id, string $status, string $guest_session_token = '') {
        global $wpdb;

        if (! $this->is_storage_ready() || $id <= 0) {
            return new \WP_Error('eko_sampa_quick_print_unavailable', __('Quick print unavailable.', 'eko-sampa'), ['status' => 503]);
        }

        $st = sanitize_key($status);
        if (! in_array($st, self::valid_statuses(), true)) {
            return new \WP_Error('eko_sampa_invalid', __('Invalid status.', 'eko-sampa'), ['status' => 400]);
        }

        $t = $wpdb->prefix . 'eko_sampa_quick_print_jobs';

        if ($user_id > 0) {
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
        } else {
            $tok = preg_replace('/[^a-f0-9]/i', '', $guest_session_token);
            if ($tok === '') {
                return new \WP_Error('eko_sampa_invalid', __('Invalid request.', 'eko-sampa'), ['status' => 400]);
            }
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $n = $wpdb->update(
                $t,
                [
                    'status'     => $st,
                    'updated_at' => current_time('mysql'),
                ],
                [
                    'id'            => $id,
                    'user_id'       => 0,
                    'session_token' => $tok,
                ],
                ['%s', '%s'],
                ['%d', '%d', '%s']
            );
        }

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
    public function reprint_clone(int $source_id, int $user_id, string $guest_session_token = '') {
        $src = $this->get_for_user($source_id, $user_id, $user_id <= 0 ? $guest_session_token : '');
        if (! is_array($src)) {
            return new \WP_Error('eko_sampa_not_found', __('Not found.', 'eko-sampa'), ['status' => 404]);
        }

        return $this->create(
            $user_id,
            (int) ( $src['template_id'] ?? 0 ),
            (int) ( $src['quantity'] ?? 1 ),
            (string) ( $src['printer_key'] ?? '__system__' ),
            (string) ( $src['preset_key'] ?? 'default' ),
            $user_id <= 0 ? $guest_session_token : ''
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
            self::STATUS_ABANDONED,
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function normalize_row(array $row): array {
        return [
            'id'            => (int) ( $row['id'] ?? 0 ),
            'user_id'       => (int) ( $row['user_id'] ?? 0 ),
            'template_id'   => (int) ( $row['template_id'] ?? 0 ),
            'status'        => (string) ( $row['status'] ?? '' ),
            'quantity'      => (int) ( $row['quantity'] ?? 1 ),
            'printer_key'   => (string) ( $row['printer_key'] ?? '' ),
            'preset_key'    => (string) ( $row['preset_key'] ?? '' ),
            'session_token' => (string) ( $row['session_token'] ?? '' ),
            'expires_at'    => (string) ( $row['expires_at'] ?? '' ),
            'abandoned_at'  => (string) ( $row['abandoned_at'] ?? '' ),
            'created_at'    => (string) ( $row['created_at'] ?? '' ),
            'updated_at'    => (string) ( $row['updated_at'] ?? '' ),
        ];
    }
}
