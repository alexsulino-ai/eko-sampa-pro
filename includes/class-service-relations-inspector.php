<?php
/**
 * Pre-delete dependency snapshot for services (templates, orders, fields, legacy columns).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Central read-only inspection used by safe delete, REST errors, and admin diagnostics.
 */
final class Eko_Sampa_Service_Relations_Inspector {

    /**
     * @return array<string, mixed>
     */
    public static function inspect(int $service_id): array {
        global $wpdb;

        if ($service_id <= 0) {
            return [
                'service_id'   => $service_id,
                'valid_id'     => false,
                'failed_reason' => 'invalid_service_id',
            ];
        }

        $database = new Eko_Sampa_Database();
        $svc      = new Eko_Sampa_Service();
        $row      = $svc->get_row_by_id($service_id);

        $tpl_suffix = 'eko_sampa_templates';
        $ord_suffix = 'eko_sampa_orders';
        $fld_suffix = 'eko_sampa_fields';

        $tpl_have = $database->table_exists_for_suffix($tpl_suffix)
            ? self::column_presence_map($wpdb->prefix . $tpl_suffix)
            : [];
        $ord_have = $database->table_exists_for_suffix($ord_suffix)
            ? self::column_presence_map($wpdb->prefix . $ord_suffix)
            : [];

        $out = [
            'service_id'              => $service_id,
            'valid_id'                => true,
            'service_exists'          => is_array($row),
            'owner_user_id'           => is_array($row) ? (int) ( $row['user_id'] ?? 0 ) : 0,
            'is_global'               => is_array($row) ? (int) ( $row['is_global'] ?? 0 ) : 0,
            'actor_may_mutate'        => is_array($row) ? $svc->can_actor_mutate_existing_row($row) : false,
            'service_visible_in_api'  => is_array($svc->get($service_id)),
            'templates_count'         => 0,
            'orders_count'            => 0,
            'fields_count'            => 0,
            'templates_servico_only'  => 0,
            'orders_servico_only'     => 0,
            'legacy_columns'          => [
                'templates_has_servico_id' => isset($tpl_have['servico_id']) && $tpl_have['servico_id'],
                'orders_has_servico_id'    => isset($ord_have['servico_id']) && $ord_have['servico_id'],
            ],
            'orphan_relations'        => [
                'fields_missing_parent' => 0,
            ],
            'sample_template_ids'     => [],
            'sample_order_ids'        => [],
        ];

        $sid = absint($service_id);

        if ($database->table_exists_for_suffix($tpl_suffix)) {
            $tpl_table = $wpdb->prefix . $tpl_suffix;
            $safe_tpl  = preg_replace('/[^a-z0-9_]/i', '', $tpl_table);
            if ($safe_tpl !== '' && $safe_tpl === $tpl_table) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name sanitized.
                $out['templates_count'] = (int) $wpdb->get_var(
                    $wpdb->prepare("SELECT COUNT(*) FROM `{$safe_tpl}` WHERE service_id = %d", $sid)
                );

                if (! empty($out['legacy_columns']['templates_has_servico_id'])) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $out['templates_servico_only'] = (int) $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT COUNT(*) FROM `{$safe_tpl}` WHERE servico_id = %d AND (service_id IS NULL OR service_id = 0)",
                            $sid
                        )
                    );
                }

                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM `{$safe_tpl}` WHERE service_id = %d ORDER BY id ASC LIMIT 8", $sid));
                $out['sample_template_ids'] = array_map('intval', is_array($ids) ? $ids : []);
            }
        }

        if ($database->table_exists_for_suffix($ord_suffix)) {
            $ord_table = $wpdb->prefix . $ord_suffix;
            $safe_ord  = preg_replace('/[^a-z0-9_]/i', '', $ord_table);
            if ($safe_ord !== '' && $safe_ord === $ord_table) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $out['orders_count'] = (int) $wpdb->get_var(
                    $wpdb->prepare("SELECT COUNT(*) FROM `{$safe_ord}` WHERE service_id = %d", $sid)
                );
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $oids = $wpdb->get_col($wpdb->prepare("SELECT id FROM `{$safe_ord}` WHERE service_id = %d ORDER BY id ASC LIMIT 8", $sid));
                $out['sample_order_ids'] = array_map('intval', is_array($oids) ? $oids : []);

                if (! empty($out['legacy_columns']['orders_has_servico_id'])) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $out['orders_servico_only'] = (int) $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT COUNT(*) FROM `{$safe_ord}` WHERE servico_id = %d AND (service_id IS NULL OR service_id = 0)",
                            $sid
                        )
                    );
                }
            }
        }

        if ($database->table_exists_for_suffix($fld_suffix)) {
            $fld_table = $wpdb->prefix . $fld_suffix;
            $safe_fld  = preg_replace('/[^a-z0-9_]/i', '', $fld_table);
            if ($safe_fld !== '' && $safe_fld === $fld_table) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $out['fields_count'] = (int) $wpdb->get_var(
                    $wpdb->prepare("SELECT COUNT(*) FROM `{$safe_fld}` WHERE service_id = %d", $sid)
                );
            }
        }

        if ($database->table_exists_for_suffix($fld_suffix) && $database->table_exists_for_suffix('eko_sampa_services')) {
            $fld_table = $wpdb->prefix . $fld_suffix;
            $svc_table = $wpdb->prefix . 'eko_sampa_services';
            $sf        = preg_replace('/[^a-z0-9_]/i', '', $fld_table);
            $ss        = preg_replace('/[^a-z0-9_]/i', '', $svc_table);
            if ($sf === $fld_table && $ss === $svc_table) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $out['orphan_relations']['fields_missing_parent'] = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM `{$sf}` f LEFT JOIN `{$ss}` s ON s.id = f.service_id
                        WHERE f.service_id = %d AND s.id IS NULL",
                        $sid
                    )
                );
            }
        }

        return $out;
    }

    /**
     * @return array<string, bool>
     */
    private static function column_presence_map(string $table): array {
        global $wpdb;

        $safe = preg_replace('/[^a-z0-9_]/i', '', $table);
        if ($safe === '' || $safe !== $table) {
            return [];
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results('SHOW COLUMNS FROM `' . $safe . '`', ARRAY_A);
        $have = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (isset($row['Field']) && is_string($row['Field'])) {
                    $have[ strtolower($row['Field']) ] = true;
                }
            }
        }

        return $have;
    }
}
