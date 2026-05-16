<?php
/**
 * Safe service deletion: legacy FK cleanup, optional strict block, audit trail.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * @param array<string, mixed> $entry
 */
function eko_sampa_append_service_delete_audit(array $entry): void {
    $stored = get_option('eko_sampa_service_delete_audit', []);
    if (! is_array($stored)) {
        $stored = [];
    }
    $entry['at'] = gmdate('c');
    $entry['by'] = get_current_user_id();
    $stored[]   = $entry;
    if (count($stored) > 40) {
        $stored = array_slice($stored, -40);
    }
    update_option('eko_sampa_service_delete_audit', $stored, false);
}

/**
 * Normalize legacy servico_id columns still pointing at this service (does not drop the service row).
 *
 * @return list<array<string, mixed>>
 */
function eko_sampa_repair_service_relations(int $service_id): array {
    global $wpdb;

    $fixes   = [];
    $sid     = absint($service_id);
    $database = new Eko_Sampa_Database();

    if ($sid <= 0) {
        return $fixes;
    }

    $tpl = $wpdb->prefix . 'eko_sampa_templates';
    $ord = $wpdb->prefix . 'eko_sampa_orders';

    if ($database->table_exists_for_suffix('eko_sampa_templates')) {
        $t = preg_replace('/[^a-z0-9_]/i', '', $tpl);
        if ($t === $tpl && eko_sampa_db_table_has_column($tpl, 'servico_id')) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $n = (int) $wpdb->query($wpdb->prepare("UPDATE `{$t}` SET servico_id = 0 WHERE servico_id = %d", $sid));
            if ($n > 0) {
                $fixes[] = ['kind' => 'templates_servico_id_cleared', 'rows' => $n];
            }
        }
    }

    if ($database->table_exists_for_suffix('eko_sampa_orders')) {
        $o = preg_replace('/[^a-z0-9_]/i', '', $ord);
        if ($o === $ord && eko_sampa_db_table_has_column($ord, 'servico_id')) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $n = (int) $wpdb->query($wpdb->prepare("UPDATE `{$o}` SET servico_id = 0 WHERE servico_id = %d", $sid));
            if ($n > 0) {
                $fixes[] = ['kind' => 'orders_servico_id_cleared', 'rows' => $n];
            }
        }
    }

    Eko_Sampa_Model_Base::clear_table_column_map_cache();

    return $fixes;
}

/**
 * @param array{
 *   unlink_refs?: bool,
 *   repair_legacy?: bool,
 *   strict_block?: bool,
 *   ensure_schema?: bool,
 * } $options
 *
 * @return array{
 *   ok: bool,
 *   code: string,
 *   message: string,
 *   debug: array<string, mixed>,
 *   audit: array<string, mixed>,
 * }
 */
function eko_sampa_safe_delete_service(int $service_id, array $options = []): array {
    global $wpdb;

    $defaults = [
        'unlink_refs'   => true,
        'repair_legacy' => true,
        'strict_block'  => false,
        'ensure_schema' => false,
    ];
    /**
     * Adjust safe-delete behaviour (tests, custom policies).
     *
     * @param array<string, mixed> $options Merged defaults + caller options.
     * @param int                    $service_id Target id (may be invalid until validated below).
     *
     * @return array<string, mixed>
     */
    $options = apply_filters('eko_sampa_safe_delete_service_options', array_merge($defaults, $options), $service_id);

    $unlink_refs    = ! empty($options['unlink_refs']);
    $repair_legacy  = ! empty($options['repair_legacy']);
    $strict_block   = ! empty($options['strict_block']);
    $ensure_schema  = ! empty($options['ensure_schema']);

    $audit = [
        'service_id' => $service_id,
        'unlink_refs' => $unlink_refs,
        'repair_legacy' => $repair_legacy,
        'strict_block' => $strict_block,
    ];

    $debug = [
        'service_id'   => $service_id,
        'failed_at'    => null,
        'wpdb_error'   => '',
        'inspect'      => [],
        'repairs'      => [],
        'unlink_steps' => [],
    ];

    $id = absint($service_id);
    if ($id <= 0) {
        $debug['failed_at'] = 'invalid_id';

        return [
            'ok'      => false,
            'code'    => 'eko_sampa_bad_request',
            'message' => __('Invalid service id.', 'eko-sampa'),
            'debug'   => $debug,
            'audit'   => $audit,
        ];
    }

    if ($ensure_schema) {
        (new Eko_Sampa_Database())->ensure_schema();
    }

    $model = new Eko_Sampa_Service();
    $row   = $model->get_row_by_id($id);
    if (! is_array($row)) {
        $debug['failed_at']  = 'service_row_missing';
        $debug['inspect']    = Eko_Sampa_Service_Relations_Inspector::inspect($id);
        $audit['outcome']    = 'not_found';
        eko_sampa_append_service_delete_audit($audit + ['ok' => false, 'code' => 'eko_sampa_not_found']);

        return [
            'ok'      => false,
            'code'    => 'eko_sampa_not_found',
            'message' => __('Not found.', 'eko-sampa'),
            'debug'   => $debug,
            'audit'   => $audit,
        ];
    }

    if (! $model->can_actor_mutate_existing_row($row)) {
        $debug['failed_at']           = 'forbidden_ownership';
        $debug['inspect']             = Eko_Sampa_Service_Relations_Inspector::inspect($id);
        $debug['owner_user_id']       = (int) ( $row['user_id'] ?? 0 );
        $debug['current_user_id']    = get_current_user_id();
        $audit['outcome']             = 'forbidden';

        eko_sampa_append_service_delete_audit($audit + ['ok' => false, 'code' => 'eko_sampa_delete_forbidden']);

        return [
            'ok'      => false,
            'code'    => 'eko_sampa_delete_forbidden',
            'message' => __('You do not have permission to delete this service.', 'eko-sampa'),
            'debug'   => $debug,
            'audit'   => $audit,
        ];
    }

    $inspect = Eko_Sampa_Service_Relations_Inspector::inspect($id);
    $debug['inspect'] = $inspect;

    $linked_total = (int) ( $inspect['templates_count'] ?? 0 )
        + (int) ( $inspect['orders_count'] ?? 0 )
        + (int) ( $inspect['templates_servico_only'] ?? 0 )
        + (int) ( $inspect['orders_servico_only'] ?? 0 );

    if ($strict_block && $linked_total > 0) {
        $debug['failed_at'] = 'blocked_active_links';
        $audit['outcome'] = 'blocked_strict';
        eko_sampa_append_service_delete_audit(
            $audit + [
                'ok'                => false,
                'code'              => 'eko_sampa_delete_blocked_dependencies',
                'linked_refs_total' => $linked_total,
            ]
        );

        return [
            'ok'      => false,
            'code'    => 'eko_sampa_delete_blocked_dependencies',
            'message' => __(
                'Cannot delete this service while it is still linked to templates or orders. Unlink or reassign them first, or repeat the request with unlink enabled (default).',
                'eko-sampa'
            ),
            'debug'   => $debug + ['linked_refs_total' => $linked_total],
            'audit'   => $audit + ['linked_refs_total' => $linked_total],
        ];
    }

    if ($repair_legacy) {
        $repairs                    = eko_sampa_repair_service_relations($id);
        $debug['repairs']           = $repairs;
        $audit['repairs_applied']   = $repairs;
        $inspect                    = Eko_Sampa_Service_Relations_Inspector::inspect($id);
        $debug['inspect_post_repair'] = $inspect;
    }

    if ($unlink_refs) {
        $tpl_table = $wpdb->prefix . 'eko_sampa_templates';
        $ord_table = $wpdb->prefix . 'eko_sampa_orders';
        $t         = preg_replace('/[^a-z0-9_]/i', '', $tpl_table);
        $o         = preg_replace('/[^a-z0-9_]/i', '', $ord_table);

        if ($t === $tpl_table) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $u = $wpdb->query($wpdb->prepare("UPDATE `{$t}` SET service_id = 0 WHERE service_id = %d", $id));
            $debug['unlink_steps'][] = [
                'table'   => 'eko_sampa_templates',
                'column'  => 'service_id',
                'result'  => $u,
                'last_error' => $wpdb->last_error,
            ];
        }
        if ($o === $ord_table) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $u2 = $wpdb->query($wpdb->prepare("UPDATE `{$o}` SET service_id = 0 WHERE service_id = %d", $id));
            $debug['unlink_steps'][] = [
                'table'   => 'eko_sampa_orders',
                'column'  => 'service_id',
                'result'  => $u2,
                'last_error' => $wpdb->last_error,
            ];
        }
    }

    $field_model = new Eko_Sampa_Service_Field();
    if (! $field_model->delete_all_for_service($id)) {
        $debug['failed_at']  = 'delete_fields_failed';
        $debug['wpdb_error'] = (string) $wpdb->last_error;
        $audit['outcome']    = 'delete_fields_failed';

        eko_sampa_append_service_delete_audit($audit + ['ok' => false, 'code' => 'eko_sampa_delete_fields_failed', 'debug' => $debug]);

        return [
            'ok'      => false,
            'code'    => 'eko_sampa_delete_fields_failed',
            'message' => __('Could not remove service field definitions before deleting the service.', 'eko-sampa'),
            'debug'   => $debug,
            'audit'   => $audit,
        ];
    }

    if (! $model->delete_service_row_only($id)) {
        $debug['failed_at']        = 'delete_service_row_failed';
        $debug['wpdb_error']       = (string) $wpdb->last_error;
        $debug['delete_sql_error'] = 'query_or_prepare_failed';
        $audit['outcome']          = 'delete_row_failed';

        eko_sampa_append_service_delete_audit($audit + ['ok' => false, 'code' => 'eko_sampa_delete_failed', 'debug' => $debug]);

        return [
            'ok'      => false,
            'code'    => 'eko_sampa_delete_failed',
            'message' => __('Could not delete service.', 'eko-sampa'),
            'debug'   => $debug,
            'audit'   => $audit,
        ];
    }

    $audit['outcome'] = 'deleted';
    eko_sampa_append_service_delete_audit($audit + ['ok' => true, 'code' => 'deleted']);

    return [
        'ok'      => true,
        'code'    => 'deleted',
        'message' => '',
        'debug'   => $debug,
        'audit'   => $audit,
    ];
}

/**
 * Exposed for helpers; column existence probe for legacy repair paths.
 */
function eko_sampa_db_table_has_column(string $table, string $column): bool {
    global $wpdb;

    $t = preg_replace('/[^a-z0-9_]/i', '', $table);
    $c = strtolower(preg_replace('/[^a-z0-9_]/i', '', $column));
    if ($t === '' || $t !== $table || $c === '') {
        return false;
    }

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $rows = $wpdb->get_results('SHOW COLUMNS FROM `' . $t . '`', ARRAY_A);
    if (! is_array($rows)) {
        return false;
    }
    foreach ($rows as $row) {
        if (isset($row['Field']) && strtolower((string) $row['Field']) === $c) {
            return true;
        }
    }

    return false;
}

/**
 * Dispatch safe delete by entity key (extensible).
 *
 * @param string               $entity One of: service(s), template(s), order(s).
 * @param array<string, mixed> $options Passed to the matching safe delete helper when applicable.
 *
 * @return array<string, mixed>
 */
function eko_sampa_safe_delete_entity(string $entity, int $id, array $options = []): array {
    $key = strtolower(trim($entity));

    return match ($key) {
        'service', 'services' => eko_sampa_safe_delete_service($id, $options),
        'template', 'templates' => eko_sampa_safe_delete_template($id, $options),
        'order', 'orders' => eko_sampa_safe_delete_order($id, $options),
        default => [
            'ok'      => false,
            'code'    => 'eko_sampa_unsupported_entity',
            'message' => __('This entity type does not support safe delete yet.', 'eko-sampa'),
            'debug'   => ['entity' => $entity, 'id' => $id],
            'audit'   => [],
        ],
    };
}
