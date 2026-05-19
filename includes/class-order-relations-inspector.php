<?php
/**
 * Pre-delete snapshot for orders (filesystem snapshot presence).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Eko_Sampa_Order_Relations_Inspector extends Eko_Sampa_Entity_Relations_Inspector {

    /**
     * Static entrypoint (cannot be named `inspect`: parent declares `inspect()` final).
     *
     * @return array<string, mixed>
     */
    public static function inspect_for(int $order_id): array {
        return (new self())->inspect($order_id);
    }

    /**
     * @return array<string, mixed>
     */
    protected function inspect_entity(int $order_id): array {
        if ($order_id <= 0) {
            return [
                'order_id'      => $order_id,
                'valid_id'      => false,
                'failed_reason' => 'invalid_order_id',
            ];
        }

        $ord = new Eko_Sampa_Order();
        $row = $ord->get_row_by_id($order_id);

        $uid = is_array($row) ? (int) ( $row['user_id'] ?? 0 ) : 0;
        $snap = $uid > 0 && Eko_Sampa_Order_Completed_Snapshot::is_ready($uid, $order_id);

        return [
            'order_id'           => $order_id,
            'valid_id'           => true,
            'order_exists'       => is_array($row),
            'owner_user_id'      => $uid,
            'status'             => is_array($row) ? (string) ( $row['status'] ?? '' ) : '',
            'has_fs_snapshot'    => $snap,
            'templates_count'    => 0,
            'orders_count'       => 0,
            'templates_servico_only' => 0,
            'orders_servico_only'    => 0,
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    protected function has_strict_delete_blockers(array $snapshot): bool {
        return false;
    }
}
