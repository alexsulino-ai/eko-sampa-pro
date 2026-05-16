<?php
/**
 * Pre-delete dependency snapshot for templates (orders by lifecycle).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Eko_Sampa_Template_Relations_Inspector extends Eko_Sampa_Entity_Relations_Inspector {

    /**
     * @return array<string, mixed>
     */
    public static function inspect(int $template_id): array {
        return (new self())->inspect($template_id);
    }

    /**
     * @return array<string, mixed>
     */
    protected function inspect_entity(int $template_id): array {
        global $wpdb;

        if ($template_id <= 0) {
            return [
                'template_id'   => $template_id,
                'valid_id'      => false,
                'failed_reason' => 'invalid_template_id',
            ];
        }

        $tpl = new Eko_Sampa_Template();
        $row = $tpl->get_row_by_id($template_id);

        $ord_table = $wpdb->prefix . 'eko_sampa_orders';
        $safe_ord  = preg_replace('/[^a-z0-9_]/i', '', $ord_table);

        $orders_open_count     = 0;
        $orders_completed_count = 0;
        if ($safe_ord === $ord_table) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $orders_open_count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$safe_ord}` WHERE template_id = %d AND status <> %s",
                    $template_id,
                    'completed'
                )
            );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $orders_completed_count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$safe_ord}` WHERE template_id = %d AND status = %s",
                    $template_id,
                    'completed'
                )
            );
        }

        return [
            'template_id'              => $template_id,
            'valid_id'                 => true,
            'template_exists'          => is_array($row),
            'owner_user_id'            => is_array($row) ? (int) ( $row['user_id'] ?? 0 ) : 0,
            'orders_open_count'        => $orders_open_count,
            'orders_completed_count'   => $orders_completed_count,
            'templates_count'          => 0,
            'orders_count'             => $orders_open_count,
            'templates_servico_only'   => 0,
            'orders_servico_only'      => 0,
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    protected function has_strict_delete_blockers(array $snapshot): bool {
        return ( (int) ( $snapshot['orders_open_count'] ?? 0 ) ) > 0;
    }
}
