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

        $report['snapshot_operational_consistency'] = $this->snapshot_operational_consistency();

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
     * Operational order title: column presence, search strategy, index, title hygiene (diagnostics JSON).
     *
     * @return array<string, mixed>
     */
    private function orders_order_title_metrics(): array {
        global $wpdb;

        $base = [
            'search_strategy'          => 'like_contains',
            'estimated_scalability'    => 'medium_table_ok_large_needs_fulltext_or_elasticsearch',
            'recommended_index'        => 'secondary btree on order_title (prefix 191 utf8mb4); migrate 1.0.8',
            'missing_index_warning'    => false,
            'order_title_index_found'  => false,
            'duplicate_titles_rows'    => null,
            'completed_without_title'  => null,
            'invalid_unicode_titles'   => null,
            'oversized_titles'         => null,
            'titles_needing_normalization_sample' => null,
            'normalization_sample_size'=> 0,
        ];

        if (! $this->database->table_exists_for_suffix('eko_sampa_orders')) {
            return array_merge(
                $base,
                [
                    'table_exists'        => false,
                    'column_present'      => false,
                    'rows_without_title'  => null,
                    'readiness'           => 'no_table',
                ]
            );
        }

        $cols = $this->column_presence_report('eko_sampa_orders', ['order_title']);
        $have = ! empty($cols['order_title']);
        if (! $have) {
            return array_merge(
                $base,
                [
                    'table_exists'        => true,
                    'column_present'      => false,
                    'rows_without_title'  => null,
                    'readiness'           => 'migration_required',
                    'expected_db_version' => '1.0.7',
                    'missing_index_warning' => true,
                ]
            );
        }

        $table = $wpdb->prefix . 'eko_sampa_orders';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $n = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE `order_title` IS NULL OR `order_title` = ''");

        $index_found = false;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $indexes = $wpdb->get_results("SHOW INDEX FROM `{$table}`", ARRAY_A);
        if (is_array($indexes)) {
            foreach ($indexes as $ix) {
                if (! is_array($ix)) {
                    continue;
                }
                $col = isset($ix['Column_name']) ? strtolower((string) $ix['Column_name']) : '';
                $seq = isset($ix['Seq_in_index']) ? (int) $ix['Seq_in_index'] : 0;
                if ($col === 'order_title' && $seq === 1) {
                    $index_found = true;
                    break;
                }
            }
        }

        $dup_rows = null;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $dup = $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}` o INNER JOIN (
                SELECT order_title FROM `{$table}`
                WHERE order_title IS NOT NULL AND order_title <> ''
                GROUP BY order_title HAVING COUNT(*) > 1
            ) d ON o.order_title = d.order_title"
        );
        if (is_numeric($dup)) {
            $dup_rows = (int) $dup;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $completed_no = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}` WHERE status = 'completed' AND (`order_title` IS NULL OR `order_title` = '')"
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $oversized = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}` WHERE `order_title` IS NOT NULL AND CHAR_LENGTH(`order_title`) > 255"
        );

        $invalid_utf8 = 0;
        $norm_drift   = 0;
        $sample_size  = 800;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sample = $wpdb->get_results(
            "SELECT order_title FROM `{$table}` WHERE order_title IS NOT NULL AND order_title <> '' LIMIT {$sample_size}",
            ARRAY_A
        );
        if (is_array($sample)) {
            foreach ($sample as $row) {
                if (! is_array($row) || ! isset($row['order_title']) || ! is_string($row['order_title'])) {
                    continue;
                }
                $t = $row['order_title'];
                if (function_exists('mb_check_encoding') && ! mb_check_encoding($t, 'UTF-8')) {
                    ++$invalid_utf8;
                }
                $norm = Eko_Sampa_Order::normalize_order_title_operational($t);
                if ($norm !== $t) {
                    ++$norm_drift;
                }
            }
        }

        $missing_index = ! $index_found;

        return array_merge(
            $base,
            [
                'table_exists'                         => true,
                'column_present'                       => true,
                'rows_without_title'                   => $n,
                'readiness'                            => 'ok',
                'missing_index_warning'                => $missing_index,
                'order_title_index_found'              => $index_found,
                'duplicate_titles_rows'                => $dup_rows,
                'completed_without_title'              => $completed_no,
                'invalid_unicode_titles'               => $invalid_utf8,
                'oversized_titles'                     => $oversized,
                'titles_needing_normalization_sample'  => $norm_drift,
                'normalization_sample_size'            => is_array($sample) ? count($sample) : 0,
                'normalized_title_changes'             => 'sampled_vs_normalize_order_title_operational',
            ]
        );
    }

    /**
     * Read-only: completed snapshots vs operational metadata contract (no writes).
     *
     * @return array<string, mixed>
     */
    private function snapshot_operational_consistency(): array {
        global $wpdb;

        $out = [
            'checked_snapshots'                      => 0,
            'order_json_missing'                     => 0,
            'order_json_parse_error'                 => 0,
            'manifest_order_id_mismatch'             => 0,
            'legacy_manifest_without_order_title_key'=> 0,
            'note'                                   => 'Snapshots are immutable; missing order_title in order.json indicates snapshot written before plugin stored operational titles.',
        ];

        if (! $this->database->table_exists_for_suffix('eko_sampa_orders')
            || ! class_exists('Eko_Sampa_Order_Completed_Snapshot', false)
            || ! class_exists('Eko_Sampa_Storage_Manager', false)) {
            $out['readiness'] = 'skipped';

            return $out;
        }

        $table = $wpdb->prefix . 'eko_sampa_orders';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            "SELECT id, user_id FROM `{$table}` WHERE status = 'completed' ORDER BY id DESC LIMIT 30",
            ARRAY_A
        );
        if (! is_array($rows)) {
            return $out;
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $oid = (int) ( $row['id'] ?? 0 );
            $uid = (int) ( $row['user_id'] ?? 0 );
            if ($oid <= 0 || $uid <= 0) {
                continue;
            }
            if (! Eko_Sampa_Order_Completed_Snapshot::is_ready($uid, $oid)) {
                continue;
            }

            ++$out['checked_snapshots'];

            $dir = Eko_Sampa_Storage_Manager::completed_order_dir_abs($uid, $oid);
            if ($dir === '') {
                ++$out['order_json_missing'];

                continue;
            }

            $path = trailingslashit($dir) . 'order.json';
            if (! is_readable($path)) {
                ++$out['order_json_missing'];

                continue;
            }

            $raw = file_get_contents($path);
            if (! is_string($raw) || $raw === '') {
                ++$out['order_json_parse_error'];

                continue;
            }

            $json = json_decode($raw, true);
            if (JSON_ERROR_NONE !== json_last_error() || ! is_array($json)) {
                ++$out['order_json_parse_error'];

                continue;
            }

            if ((int) ( $json['order_id'] ?? 0 ) !== $oid) {
                ++$out['manifest_order_id_mismatch'];
            }

            if (! array_key_exists('order_title', $json)) {
                ++$out['legacy_manifest_without_order_title_key'];
            }
        }

        $out['readiness'] = 'ok';

        return $out;
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
