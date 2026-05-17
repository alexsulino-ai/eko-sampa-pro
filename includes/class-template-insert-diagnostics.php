<?php
/**
 * Deterministic pre-insert diagnostics for `wp_eko_sampa_templates` (schema, session, payload).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * SQL-level and payload-level validation before {@see wpdb::insert()}.
 */
final class Eko_Sampa_Template_Insert_Diagnostics {

    private const EXPECTED_MODEL_KEYS = [
        'user_id', 'client_id', 'product_id', 'service_id', 'nome', 'categoria', 'descricao',
        'width_mm', 'height_mm', 'preview_image', 'json_data',
    ];

    /**
     * @var array<string, array<string, mixed>>
     */
    private static array $schema_cache = [];

    /**
     * @param array<string, mixed> $row        Real column names → values (post {@see Eko_Sampa_Model_Base::filter_row_to_existing_columns()}).
     * @param array<int, string>   $formats    Same order as {@see Eko_Sampa_Template::insert_formats()} for $row.
     * @param array<string, mixed> $row_context Optional: keys_dropped_by_column_filter, json_byte_length, nome_char_length, …
     *
     * @return array<string, mixed>
     */
    public static function simulate_insert_validation(
        string $table,
        array $row,
        array $formats,
        array $row_context = []
    ): array {
        global $wpdb;

        $out = self::empty_report();
        $out['table']                = $table;
        $out['insert_payload_size']  = self::estimate_insert_packet_bytes($row, $formats);
        $out['estimated_row_bytes'] = self::estimate_row_storage_bytes($row);
        $out['mysql_sql_mode']       = self::session_var_string('@@SESSION.sql_mode');
        $out['max_allowed_packet']   = self::session_int('@@SESSION.max_allowed_packet');
        $out['character_set_connection'] = self::session_var_string('@@SESSION.character_set_connection');
        $out['collation_connection']     = self::session_var_string('@@SESSION.collation_connection');

        $safe = preg_replace('/[^a-z0-9_]/i', '', $table);
        if ($safe === '' || $safe !== $table) {
            $out['failure_reason']       = 'invalid_table_name';
            $out['schema_mismatch']      = true;
            $out['blocking']             = true;
            $out['readiness_score']      = 0;

            return $out;
        }

        $schema = self::load_schema($table);
        $out['table_exists']         = $schema['table_exists'];
        $out['columns_meta']       = $schema['columns'];
        $out['table_collation']    = $schema['table_collation'];
        $out['table_engine']       = $schema['engine'];
        $out['auto_increment_ok']  = $schema['auto_increment_ok'];
        $out['foreign_keys']       = $schema['foreign_keys'];
        $out['broken_index_hints'] = $schema['index_warnings'];

        if (! $schema['table_exists']) {
            $out['failure_reason']  = 'table_missing';
            $out['schema_mismatch'] = true;
            $out['blocking']        = true;
            $out['readiness_score'] = 0;
            $out['actionable_repairs'][] = 'Run Eko Sampa database activation / migration so `eko_sampa_templates` exists.';

            return $out;
        }

        $col_by_lower = [];
        foreach ($schema['columns'] as $meta) {
            $col_by_lower[ strtolower((string) $meta['Field']) ] = $meta;
        }

        $unexpected = [];
        foreach (array_keys($row) as $k) {
            if (! isset($col_by_lower[ strtolower((string) $k) ])) {
                $unexpected[] = (string) $k;
            }
        }
        $out['unexpected_columns'] = $unexpected;
        if ($unexpected !== []) {
            $out['failure_reason']       = 'unknown_column_after_filter';
            $out['offending_column']     = $unexpected[0];
            $out['schema_mismatch']      = true;
            $out['blocking']             = true;
            $out['readiness_score']      = min($out['readiness_score'], 15);
            $out['warnings'][]           = 'Row contains keys not present in SHOW COLUMNS (migration lag or manual table edits).';
            $out['actionable_repairs'][] = 'Align database columns with plugin schema; remove unknown keys from payload or run upgrade.';

            return $out;
        }

        $missing_required = [];
        foreach ($schema['columns'] as $meta) {
            $field = (string) $meta['Field'];
            $lk    = strtolower($field);
            if ($lk === 'id') {
                continue;
            }
            $null    = strtoupper((string) ( $meta['Null'] ?? 'YES' ));
            $default = $meta['Default'] ?? null;
            $extra   = strtolower((string) ( $meta['Extra'] ?? '' ));
            if ($null === 'NO' && $default === null && $extra === '' && ! array_key_exists($field, $row)) {
                $missing_required[] = $field;
            }
        }
        $out['missing_columns'] = $missing_required;
        if ($missing_required !== []) {
            $out['failure_reason']       = 'missing_required_column';
            $out['offending_column']     = $missing_required[0];
            $out['schema_mismatch']      = true;
            $out['blocking']             = true;
            $out['readiness_score']      = min($out['readiness_score'], 10);
            $out['actionable_repairs'][] = 'Populate required NOT NULL columns: ' . implode(', ', $missing_required);

            return $out;
        }

        foreach ($row as $col => $val) {
            $meta = $col_by_lower[ strtolower((string) $col) ] ?? null;
            if (! is_array($meta)) {
                continue;
            }
            $type = (string) ( $meta['Type'] ?? '' );
            $null = strtoupper((string) ( $meta['Null'] ?? 'YES' ));

            if ($null === 'NO' && $val === null) {
                $out['failure_reason']   = 'strict_mode_rejected_null';
                $out['sql_constraint']   = 'NOT NULL without DEFAULT';
                $out['offending_column'] = (string) $col;
                $out['blocking']         = true;
                $out['readiness_score']  = min($out['readiness_score'], 5);
                $out['warnings'][]       = 'NULL value for NOT NULL column under strict SQL mode.';

                return $out;
            }

            if (is_string($val)) {
                $bytes = strlen($val);
                $out['column_byte_lengths'][ (string) $col ] = $bytes;

                $max_chars = self::parse_varchar_max_chars($type);
                if ($max_chars !== null && function_exists('mb_strlen')) {
                    $chars = mb_strlen($val, 'UTF-8');
                    if ($chars > $max_chars) {
                        $out['failure_reason']           = strtolower((string) $col) === 'nome'
                            ? 'nome_exceeds_column_limit'
                            : 'column_value_exceeds_max_length';
                        $out['offending_column']         = (string) $col;
                        $out['offending_value_preview']  = self::preview_value($val, 120);
                        $out['blocking']                 = true;
                        $out['readiness_score']          = min($out['readiness_score'], 5);

                        return $out;
                    }
                }

                if (! mb_check_encoding($val, 'UTF-8')) {
                    $out['failure_reason']             = 'utf8mb4_encoding_failure';
                    $out['offending_column']           = (string) $col;
                    $out['offending_value_preview']    = self::preview_value($val, 80);
                    $out['utf8mb4_incompatible_bytes'] = true;
                    $out['blocking']                   = true;
                    $out['readiness_score']           = min($out['readiness_score'], 5);

                    return $out;
                }

                $trunc = self::detect_utf8_truncation_risk($val, $schema['table_collation'] ?? '');
                if ($trunc !== '') {
                    $out['warnings'][] = $trunc . ' (`' . $col . '`)';
                }

                if ($col === 'preview_image' && $val !== '') {
                    $path_issue = self::validate_preview_image_relative_path($val);
                    if ($path_issue !== '') {
                        $out['failure_reason']            = 'preview_image_invalid_relative_path';
                        $out['offending_column']          = 'preview_image';
                        $out['offending_value_preview']   = self::preview_value($val, 200);
                        $out['blocking']                  = true;
                        $out['readiness_score']           = min($out['readiness_score'], 20);

                        return $out;
                    }
                }
            }
        }

        $packet = (int) $out['max_allowed_packet'];
        if ($packet > 0 && $out['insert_payload_size'] > (int) floor($packet * 0.92)) {
            $out['failure_reason'] = 'json_exceeds_packet_limit';
            $out['blocking']       = true;
            $out['readiness_score'] = min($out['readiness_score'], 10);
            $out['warnings'][]     = 'Estimated INSERT packet size approaches max_allowed_packet; increase server limit or shrink JSON.';

            return $out;
        }

        $model_missing = array_values(array_filter(
            self::EXPECTED_MODEL_KEYS,
            static fn(string $k): bool => ! isset($col_by_lower[ strtolower($k) ])
        ));
        if ($model_missing !== []) {
            $out['schema_mismatch'] = true;
            $out['warnings'][]      = 'Schema missing expected model columns: ' . implode(', ', $model_missing);
            $out['readiness_score']  = min($out['readiness_score'], 40);
        }

        $dropped = $row_context['keys_dropped_by_column_filter'] ?? [];
        if (is_array($dropped) && $dropped !== []) {
            $critical = array_intersect(
                array_map('strtolower', $dropped),
                array_map('strtolower', ['json_data', 'nome', 'user_id'])
            );
            if ($critical !== []) {
                $out['failure_reason'] = 'schema_column_filter_dropped_critical_field';
                $out['blocking']       = true;
                $out['readiness_score'] = min($out['readiness_score'], 5);
                $out['warnings'][]     = 'filter_row_to_existing_columns removed: ' . implode(', ', $dropped);

                return $out;
            }
            $out['warnings'][] = 'Some payload keys were dropped by column filter: ' . implode(', ', $dropped);
        }

        if (stripos($out['mysql_sql_mode'], 'STRICT_TRANS_TABLES') !== false || stripos($out['mysql_sql_mode'], 'STRICT_ALL_TABLES') !== false) {
            $out['warnings'][] = 'MySQL strict mode enabled — implicit defaults and type coercion are rejected.';
        }

        $out['readiness_score'] = $out['blocking'] ? $out['readiness_score'] : 100;

        return $out;
    }

    /**
     * @param array<string, mixed> $try_result Output fragment from {@see Eko_Sampa_Template::try_create()} on failure.
     *
     * @return array<string, mixed>
     */
    public static function enrich_wpdb_insert_failure(array $try_result): array {
        global $wpdb;

        $err = trim((string) ( $try_result['wpdb_error'] ?? '' ));
        $map = self::classify_mysql_error($err, self::mysqli_errno());

        return array_merge(
            [
                'mysql_errno' => self::mysqli_errno(),
                'sql_state'   => self::mysqli_sqlstate(),
                'db_last_error' => $err,
            ],
            $map
        );
    }

    /**
     * @return array{failure_reason: string, sql_constraint: string, offending_column: string}
     */
    public static function classify_mysql_error(string $err, int $errno): array {
        $err_l = strtolower($err);
        $col   = self::parse_offending_column($err);
        $fr    = 'wpdb_insert_rejected';
        $sqlc  = '';

        if ($errno === 1062 || str_contains($err_l, 'duplicate entry')) {
            $fr   = 'duplicate_key_violation';
            $sqlc = 'UNIQUE';
        } elseif ($errno === 1406 || str_contains($err_l, 'data too long')) {
            $fr   = $col === 'nome' ? 'nome_exceeds_column_limit' : 'column_value_exceeds_max_length';
            $sqlc = 'MAX_LENGTH';
        } elseif ($errno === 1366 || str_contains($err_l, 'incorrect string value')) {
            $fr   = 'utf8mb4_encoding_failure';
            $sqlc = 'CHARACTER_SET';
        } elseif (str_contains($err_l, "doesn't have a default value") || str_contains($err_l, 'does not have a default value')) {
            $fr   = 'strict_mode_rejected_null';
            $sqlc = 'NOT NULL';
        } elseif (str_contains($err_l, 'unknown column')) {
            $fr   = 'unknown_column_after_filter';
            $sqlc = 'SCHEMA';
        }

        return [
            'failure_reason'    => $fr,
            'sql_constraint'    => $sqlc,
            'offending_column'  => $col,
        ];
    }

    private static function parse_offending_column(string $err): string {
        if (preg_match("/column ['\"`]([^'\"`]+)['\"`]/i", $err, $m)) {
            return (string) $m[1];
        }
        if (preg_match("/for column ['\"`]([^'\"`]+)['\"`]/i", $err, $m2)) {
            return (string) $m2[1];
        }

        return '';
    }

    public static function mysqli_errno(): int {
        global $wpdb;
        if (! isset($wpdb->dbh) || ! is_object($wpdb->dbh)) {
            return 0;
        }
        if (function_exists('mysqli_errno') && $wpdb->dbh instanceof \mysqli) {
            return (int) mysqli_errno($wpdb->dbh);
        }

        return 0;
    }

    public static function mysqli_sqlstate(): string {
        global $wpdb;
        if (! isset($wpdb->dbh) || ! is_object($wpdb->dbh)) {
            return '';
        }
        if (function_exists('mysqli_sqlstate') && $wpdb->dbh instanceof \mysqli) {
            return (string) mysqli_sqlstate($wpdb->dbh);
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private static function empty_report(): array {
        return [
            'failure_reason'             => '',
            'sql_constraint'             => '',
            'offending_column'           => '',
            'offending_value_preview'    => '',
            'estimated_row_bytes'        => 0,
            'mysql_sql_mode'             => '',
            'insert_payload_size'        => 0,
            'schema_mismatch'            => false,
            'missing_columns'            => [],
            'unexpected_columns'         => [],
            'table_exists'               => false,
            'max_allowed_packet'         => 0,
            'character_set_connection'   => '',
            'collation_connection'       => '',
            'table_collation'            => '',
            'table_engine'               => '',
            'auto_increment_ok'          => false,
            'foreign_keys'               => [],
            'broken_index_hints'         => [],
            'utf8mb4_incompatible_bytes' => false,
            'column_byte_lengths'        => [],
            'warnings'                   => [],
            'blocking'                   => false,
            'readiness_score'            => 100,
            'actionable_repairs'         => [],
        ];
    }

    private static function session_var_string(string $expr): string {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- expression is a fixed whitelist.
        $v = $wpdb->get_var('SELECT ' . $expr);
        if ($v === null || $v === false) {
            return '';
        }

        return is_scalar($v) ? (string) $v : '';
    }

    private static function session_int(string $expr): int {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $v = $wpdb->get_var('SELECT ' . $expr);
        if ($v === null || $v === false) {
            return 0;
        }

        return (int) $v;
    }

    /**
     * @return array<string, mixed>
     */
    public static function load_schema(string $table): array {
        global $wpdb;
        if (isset(self::$schema_cache[ $table ])) {
            return self::$schema_cache[ $table ];
        }

        $safe = preg_replace('/[^a-z0-9_]/i', '', $table);
        if ($safe === '' || $safe !== $table) {
            $empty = [
                'table_exists'        => false,
                'columns'             => [],
                'table_collation'     => '',
                'engine'              => '',
                'auto_increment_ok'   => false,
                'foreign_keys'        => [],
                'index_warnings'      => [],
            ];
            self::$schema_cache[ $table ] = $empty;

            return $empty;
        }

        $exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
                $safe
            )
        ) > 0;
        if (! $exists) {
            $out = [
                'table_exists'      => false,
                'columns'           => [],
                'table_collation'   => '',
                'engine'            => '',
                'auto_increment_ok' => false,
                'foreign_keys'      => [],
                'index_warnings'    => [],
            ];
            self::$schema_cache[ $table ] = $out;

            return $out;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $cols = $wpdb->get_results('SHOW FULL COLUMNS FROM `' . $safe . '`', ARRAY_A);
        if (! is_array($cols)) {
            $cols = [];
        }

        $auto_ok = false;
        foreach ($cols as $c) {
            if (! isset($c['Field'], $c['Extra'])) {
                continue;
            }
            if (strtolower((string) $c['Field']) === 'id' && str_contains(strtolower((string) $c['Extra']), 'auto_increment')) {
                $auto_ok = true;
                break;
            }
        }

        $coll = '';
        $eng  = '';
        $trow = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT TABLE_COLLATION, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s LIMIT 1',
                DB_NAME,
                $safe
            ),
            ARRAY_A
        );
        if (is_array($trow)) {
            $coll = (string) ( $trow['TABLE_COLLATION'] ?? '' );
            $eng  = (string) ( $trow['ENGINE'] ?? '' );
        }

        $fks = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND REFERENCED_TABLE_NAME IS NOT NULL',
                DB_NAME,
                $safe
            ),
            ARRAY_A
        );
        if (! is_array($fks)) {
            $fks = [];
        }

        $index_warnings = [];
        if (strtoupper($eng) !== 'INNODB' && $eng !== '') {
            $index_warnings[] = 'Table engine is ' . $eng . ' — foreign keys and transactional behaviour may differ from InnoDB.';
        }

        $out = [
            'table_exists'      => true,
            'columns'           => $cols,
            'table_collation'   => $coll,
            'engine'            => $eng,
            'auto_increment_ok' => $auto_ok,
            'foreign_keys'      => $fks,
            'index_warnings'    => $index_warnings,
        ];
        self::$schema_cache[ $table ] = $out;

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string>   $formats
     */
    private static function estimate_insert_packet_bytes(array $row, array $formats): int {
        $keys = array_keys($row);
        $n     = 0;
        foreach ($keys as $i => $k) {
            $n += strlen((string) $k) + 8;
            $v = $row[ $k ];
            if ($v === null) {
                $n += 4;
            } elseif (is_int($v) || is_float($v)) {
                $n += 12;
            } else {
                $n += strlen((string) $v) * 2;
            }
        }
        $n += count($formats) * 4 + 256;

        return max(1, $n);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function estimate_row_storage_bytes(array $row): int {
        $sum = 24;
        foreach ($row as $v) {
            if ($v === null) {
                $sum += 1;
            } elseif (is_int($v) || is_float($v)) {
                $sum += 8;
            } else {
                $sum += strlen((string) $v);
            }
        }

        return max(1, $sum);
    }

    private static function parse_varchar_max_chars(string $mysql_type): ?int {
        if (preg_match('/varchar\((\d+)\)/i', $mysql_type, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    private static function preview_value(string $val, int $max): string {
        if (function_exists('mb_substr')) {
            $s = mb_substr($val, 0, $max, 'UTF-8');
        } else {
            $s = substr($val, 0, $max);
        }

        return strlen($val) > $max ? $s . '…' : $s;
    }

    private static function validate_preview_image_relative_path(string $val): string {
        $v = trim($val);
        if ($v === '') {
            return '';
        }
        if (str_contains($v, '..') || str_contains($v, "\0")) {
            return 'path_traversal_or_null_byte';
        }
        if (preg_match('#^(https?:)?//#i', $v)) {
            return 'absolute_url_not_allowed_for_stored_preview_path';
        }

        return '';
    }

    private static function detect_utf8_truncation_risk(string $val, string $table_collation): string {
        if ($table_collation !== '' && stripos($table_collation, 'utf8mb4') === false) {
            if (preg_match('/[\xF0-\xF7][\x80-\xBF]{3}/', $val)) {
                return '4-byte UTF-8 sequences (emoji) may be rejected under non-utf8mb4 table collation';
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $diag
     *
     * @return array<string, mixed>
     */
    public static function sanitize_for_rest(array $diag): array {
        $out              = $diag;
        $out['warnings']  = array_values(array_filter((array) ( $out['warnings'] ?? [] )));
        $preview_keys     = ['offending_value_preview'];
        foreach ($preview_keys as $pk) {
            if (! empty($out[ $pk ]) && is_string($out[ $pk ]) && strlen($out[ $pk ]) > 400) {
                $out[ $pk ] = substr($out[ $pk ], 0, 400) . '…';
            }
        }
        if (! empty($out['columns_meta']) && is_array($out['columns_meta'])) {
            $slim = [];
            foreach ($out['columns_meta'] as $c) {
                if (! is_array($c) || count($slim) >= 40) {
                    break;
                }
                $slim[] = [
                    'Field' => (string) ( $c['Field'] ?? '' ),
                    'Type'  => (string) ( $c['Type'] ?? '' ),
                    'Null'  => (string) ( $c['Null'] ?? '' ),
                ];
            }
            $out['columns_meta'] = $slim;
        }

        return $out;
    }
}
