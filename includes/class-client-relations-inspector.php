<?php
/**
 * Pre-delete snapshot for clients (templates + orders).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Eko_Sampa_Client_Relations_Inspector extends Eko_Sampa_Entity_Relations_Inspector {

    /**
     * Static entrypoint (cannot be named `inspect`: parent declares `inspect()` final).
     *
     * @return array<string, mixed>
     */
    public static function inspect_for(int $client_id): array {
        return (new self())->inspect($client_id);
    }

    /**
     * @return array<string, mixed>
     */
    protected function inspect_entity(int $client_id): array {
        global $wpdb;

        if ($client_id <= 0) {
            return [
                'client_id'     => $client_id,
                'valid_id'      => false,
                'failed_reason' => 'invalid_client_id',
            ];
        }

        $cl  = new Eko_Sampa_Client();
        $row = $cl->get_row_by_id($client_id);

        $tpl_table = $wpdb->prefix . 'eko_sampa_templates';
        $ord_table = $wpdb->prefix . 'eko_sampa_orders';
        $st        = preg_replace('/[^a-z0-9_]/i', '', $tpl_table);
        $so        = preg_replace('/[^a-z0-9_]/i', '', $ord_table);

        $tpl_count = 0;
        $ord_open  = 0;
        $ord_done  = 0;
        if ($st === $tpl_table) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $tpl_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$st}` WHERE client_id = %d", $client_id));
        }
        if ($so === $ord_table) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $ord_open = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$so}` WHERE client_id = %d AND status <> %s",
                    $client_id,
                    'completed'
                )
            );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $ord_done = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$so}` WHERE client_id = %d AND status = %s",
                    $client_id,
                    'completed'
                )
            );
        }

        return [
            'client_id'            => $client_id,
            'valid_id'             => true,
            'client_exists'        => is_array($row),
            'owner_user_id'        => is_array($row) ? (int) ( $row['user_id'] ?? 0 ) : 0,
            'templates_count'      => $tpl_count,
            'orders_open_count'    => $ord_open,
            'orders_completed_count' => $ord_done,
            'orders_count'         => $ord_open + $ord_done,
            'templates_servico_only' => 0,
            'orders_servico_only'    => 0,
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    protected function has_strict_delete_blockers(array $snapshot): bool {
        return ( (int) ( $snapshot['templates_count'] ?? 0 ) + (int) ( $snapshot['orders_open_count'] ?? 0 ) ) > 0;
    }
}
