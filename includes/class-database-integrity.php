<?php
/**
 * Schema alignment follow-up: orphan FK detection and optional repair.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Works with {@see Eko_Sampa_Database}; does not duplicate dbDelta migrations.
 */
final class Eko_Sampa_Database_Integrity {

    public const OPTION_LAST_REPORT = 'eko_sampa_integrity_last_report';

    public const OPTION_REPAIR_HISTORY = 'eko_sampa_integrity_repair_history';

    private const REPAIR_HISTORY_MAX = 25;

    private Eko_Sampa_Database $database;

    public function __construct(?Eko_Sampa_Database $database = null) {
        $this->database = $database ?? new Eko_Sampa_Database();
    }

    /**
     * Run checks; optionally repair orphan template→service links.
     *
     * @return array<string, mixed>
     */
    public function run(bool $repair_orphan_template_services = false): array {
        $report = $this->build_report();

        if ($repair_orphan_template_services && ! empty($report['orphans']['templates_missing_service'])) {
            $report['repairs']['templates_missing_service'] = $this->repair_orphan_template_services(
                $report['orphans']['templates_missing_service']
            );
            if (! empty($report['repairs']['templates_missing_service'])) {
                $this->append_repair_history('templates_missing_service', $report['repairs']['templates_missing_service']);
            }
            $report = $this->build_report();
        }

        $report['repair_history'] = $this->repair_history();

        update_option(self::OPTION_LAST_REPORT, $report, false);

        if (defined('WP_DEBUG') && WP_DEBUG && ! empty($report['orphans']['templates_missing_service'])) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log(
                '[eko-sampa integrity] orphan template→service: '
                . wp_json_encode($report['orphans']['templates_missing_service'])
            );
        }

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    public function last_report(): array {
        $stored = get_option(self::OPTION_LAST_REPORT, []);

        return is_array($stored) ? $stored : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function build_report(): array {
        global $wpdb;

        $report = [
            'checked_at'     => gmdate('c'),
            'plugin_version' => defined('EKO_SAMPA_VERSION') ? EKO_SAMPA_VERSION : '',
            'db_version'     => defined('EKO_SAMPA_DB_VERSION') ? EKO_SAMPA_DB_VERSION : '',
            'stored_version' => (string) get_option('eko_sampa_db_version', ''),
            'wp_version'     => get_bloginfo('version'),
            'php_version'    => PHP_VERSION,
            'tables'      => [],
            'columns'     => [],
            'orphans'     => [
                'templates_missing_service' => [],
                'templates_missing_client'  => [],
                'orders_missing_service'    => [],
                'orders_missing_template'   => [],
                'orders_missing_client'     => [],
                'fields_missing_service'    => [],
            ],
            'counts'      => [],
            'repairs'     => [],
        ];

        foreach ($this->database->required_table_suffixes() as $suffix) {
            $exists                     = $this->database->table_exists_for_suffix($suffix);
            $report['tables'][ $suffix ] = $exists;
            if (! $exists) {
                continue;
            }

            $table = $wpdb->prefix . $suffix;
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $count = $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
            $report['counts'][ $suffix ] = absint((int) $count);
        }

        $report['columns']['eko_sampa_templates']  = $this->column_presence_report('eko_sampa_templates', [
            'user_id',
            'client_id',
            'service_id',
            'servico_id',
            'product_id',
            'nome',
            'json_data',
        ]);
        $report['columns']['eko_sampa_services']   = $this->column_presence_report('eko_sampa_services', [
            'user_id',
            'nome',
            'is_global',
        ]);
        $report['columns']['eko_sampa_orders']     = $this->column_presence_report('eko_sampa_orders', [
            'user_id',
            'client_id',
            'service_id',
            'template_id',
            'status',
            'order_title',
        ]);

        $report['orders_operational_title'] = $this->orders_order_title_metrics();

        $report['orphans']['templates_missing_service'] = $this->find_templates_missing_service();
        $report['orphans']['templates_missing_client']  = $this->find_templates_missing_client();
        $report['orphans']['orders_missing_service']    = $this->find_orders_missing_service();
        $report['orphans']['orders_missing_template']   = $this->find_orders_missing_template();
        $report['orphans']['orders_missing_client']     = $this->find_orders_missing_client();
        $report['orphans']['fields_missing_service']    = $this->find_fields_missing_service();

        return $report;
    }

    /**
     * @param list<string> $required
     *
     * @return array<string, bool>
     */
    private function column_presence_report(string $suffix, array $required): array {
        global $wpdb;

        if (! $this->database->table_exists_for_suffix($suffix)) {
            return array_fill_keys($required, false);
        }

        $table = $wpdb->prefix . $suffix;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results("SHOW COLUMNS FROM `{$table}`", ARRAY_A);
        $have = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (isset($row['Field'])) {
                    $have[ strtolower((string) $row['Field']) ] = true;
                }
            }
        }

        $out = [];
        foreach ($required as $col) {
            $out[ $col ] = isset($have[ strtolower($col) ]);
        }

        return $out;
    }

    /**
     * @return list<array<string, int>>
     */
    public function find_templates_missing_service(): array {
        global $wpdb;

        if (! $this->database->table_exists_for_suffix('eko_sampa_templates')) {
            return [];
        }

        $templates = $wpdb->prefix . 'eko_sampa_templates';
        $services  = $wpdb->prefix . 'eko_sampa_services';
        $have      = $this->column_presence_report('eko_sampa_templates', ['service_id', 'servico_id']);
        $service_expr = 'COALESCE(NULLIF(t.service_id, 0), NULLIF(t.servico_id, 0))';
        if ($have['service_id'] && ! $have['servico_id']) {
            $service_expr = 'NULLIF(t.service_id, 0)';
        } elseif (! $have['service_id'] && $have['servico_id']) {
            $service_expr = 'NULLIF(t.servico_id, 0)';
        }

        if (! $this->database->table_exists_for_suffix('eko_sampa_services')) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $sql = "SELECT t.id AS template_id, {$service_expr} AS service_id, t.user_id
                FROM `{$templates}` t
                HAVING service_id IS NOT NULL AND service_id > 0";
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $sql = "SELECT t.id AS template_id, {$service_expr} AS service_id, t.user_id
                FROM `{$templates}` t
                LEFT JOIN `{$services}` s ON s.id = {$service_expr}
                WHERE {$service_expr} IS NOT NULL AND {$service_expr} > 0 AND s.id IS NULL";
        }

        $rows = $wpdb->get_results($sql, ARRAY_A);

        return $this->normalize_orphan_rows($rows, ['template_id', 'service_id', 'user_id']);
    }

    /**
     * @return list<array<string, int>>
     */
    private function find_templates_missing_client(): array {
        return $this->find_fk_orphans('eko_sampa_templates', 'eko_sampa_clients', 'client_id', 'template_id');
    }

    /**
     * @return list<array<string, int>>
     */
    private function find_orders_missing_service(): array {
        return $this->find_fk_orphans('eko_sampa_orders', 'eko_sampa_services', 'service_id', 'order_id');
    }

    /**
     * @return list<array<string, int>>
     */
    private function find_orders_missing_template(): array {
        return $this->find_fk_orphans('eko_sampa_orders', 'eko_sampa_templates', 'template_id', 'order_id');
    }

    /**
     * @return list<array<string, int>>
     */
    private function find_orders_missing_client(): array {
        return $this->find_fk_orphans('eko_sampa_orders', 'eko_sampa_clients', 'client_id', 'order_id');
    }

    /**
     * @return list<array<string, int>>
     */
    private function find_fields_missing_service(): array {
        return $this->find_fk_orphans('eko_sampa_fields', 'eko_sampa_services', 'service_id', 'field_id');
    }

    /**
     * @return list<array<string, int>>
     */
    private function find_fk_orphans(
        string $child_suffix,
        string $parent_suffix,
        string $fk_column,
        string $id_alias
    ): array {
        global $wpdb;

        if (! $this->database->table_exists_for_suffix($child_suffix)
            || ! $this->database->table_exists_for_suffix($parent_suffix)) {
            return [];
        }

        $child  = $wpdb->prefix . $child_suffix;
        $parent = $wpdb->prefix . $parent_suffix;
        $fk     = preg_replace('/[^a-z0-9_]/i', '', $fk_column);
        if ($fk === '') {
            return [];
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "SELECT c.id AS {$id_alias}, c.{$fk} AS {$fk}
            FROM `{$child}` c
            LEFT JOIN `{$parent}` p ON p.id = c.{$fk}
            WHERE c.{$fk} > 0 AND p.id IS NULL";

        $rows = $wpdb->get_results($sql, ARRAY_A);

        return $this->normalize_orphan_rows($rows, [$id_alias, $fk]);
    }

    /**
     * Clear orphan template.service_id (does not insert catalog services).
     *
     * @param list<array<string, int>> $orphans
     *
     * @return list<array<string, int>>
     */
    public function repair_orphan_template_services(array $orphans): array {
        return $this->unlink_orphan_template_services($orphans);
    }

    /**
     * @param list<array<string, int>> $orphans
     *
     * @return list<array<string, int>>
     */
    public function unlink_orphan_template_services(array $orphans): array {
        $fixed = [];

        foreach ($orphans as $row) {
            $template_id = (int) ( $row['template_id'] ?? 0 );
            $old_sid     = (int) ( $row['service_id'] ?? 0 );
            if ($template_id <= 0) {
                continue;
            }

            if ($old_sid > 0 && $this->database->row_exists('eko_sampa_services', $old_sid)) {
                continue;
            }

            $ok = (new Eko_Sampa_Template())->update(
                $template_id,
                ['service_id' => 0]
            );

            if ($ok) {
                $fixed[] = [
                    'template_id'    => $template_id,
                    'old_service_id' => $old_sid,
                    'new_service_id' => 0,
                ];
            }
        }

        Eko_Sampa_Model_Base::clear_table_column_map_cache();

        return $fixed;
    }

    /**
     * @param array<int, array<string, mixed>>|null $rows
     * @param list<string>                          $keys
     *
     * @return list<array<string, int>>
     */
    private function normalize_orphan_rows(?array $rows, array $keys): array {
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $item = [];
            foreach ($keys as $key) {
                $item[ $key ] = absint((int) ( $row[ $key ] ?? 0 ));
            }
            if ($item !== []) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * Operational order title column (DB 1.0.7+): presence and fill rate for diagnostics JSON.
     *
     * @return array<string, mixed>
     */
    private function orders_order_title_metrics(): array {
        global $wpdb;

        if (! $this->database->table_exists_for_suffix('eko_sampa_orders')) {
            return [
                'table_exists'         => false,
                'column_present'      => false,
                'rows_without_title'  => null,
                'readiness'           => 'no_table',
            ];
        }

        $cols = $this->column_presence_report('eko_sampa_orders', ['order_title']);
        $have  = ! empty($cols['order_title']);
        if (! $have) {
            return [
                'table_exists'        => true,
                'column_present'      => false,
                'rows_without_title'  => null,
                'readiness'           => 'migration_required',
                'expected_db_version' => '1.0.7',
            ];
        }

        $table = $wpdb->prefix . 'eko_sampa_orders';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $n = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE `order_title` IS NULL OR `order_title` = ''");

        return [
            'table_exists'        => true,
            'column_present'      => true,
            'rows_without_title'  => $n,
            'readiness'           => 'ok',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function repair_history(): array {
        $stored = get_option(self::OPTION_REPAIR_HISTORY, []);

        return is_array($stored) ? $stored : [];
    }

    /**
     * @param list<array<string, int>> $fixed
     */
    private function append_repair_history(string $kind, array $fixed): void {
        if ($fixed === []) {
            return;
        }

        $entry = [
            'at'    => gmdate('c'),
            'kind'  => $kind,
            'count' => count($fixed),
            'items' => $fixed,
            'by'    => get_current_user_id(),
        ];

        $history = $this->repair_history();
        $history[] = $entry;
        if (count($history) > self::REPAIR_HISTORY_MAX) {
            $history = array_slice($history, -self::REPAIR_HISTORY_MAX);
        }

        update_option(self::OPTION_REPAIR_HISTORY, $history, false);
    }
}
