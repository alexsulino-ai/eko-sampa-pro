<?php
/**
 * Shared model helpers: table names, ownership checks, prepared query fragments.
 *
 * Specification: docs/database.md (ownership), docs/architecture.md (security).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Internal base for Eko Sampa models. Not a domain entity.
 */
abstract class Eko_Sampa_Model_Base {

    /**
     * Table suffix after {$wpdb->prefix}, e.g. eko_sampa_clients.
     */
    abstract protected function table_suffix(): string;

    /**
     * SQL fragment (without leading AND) for non-admin row scope, with %d / %s placeholders.
     * Example: "user_id = %d".
     *
     * @return array{0: string, 1: array<int, int|string>}
     */
    abstract protected function ownership_predicate(): array;

    protected function db(): wpdb {
        global $wpdb;

        return $wpdb;
    }

    protected function table(): string {
        return $this->db()->prefix . $this->table_suffix();
    }

    protected function is_unrestricted(): bool {
        return current_user_can('manage_options');
    }

    protected function current_user_id(): int {
        return get_current_user_id();
    }

    /**
     * @return array{0: string, 1: array<int, int|string>} Extra WHERE fragment and values (empty for admin).
     */
    protected function ownership_sql(): array {
        if ($this->is_unrestricted()) {
            return ['', []];
        }

        [$fragment, $values] = $this->ownership_predicate();

        return [' AND ' . $fragment, $values];
    }

    /**
     * Fetch a row by primary key without ownership scope (existence checks only).
     */
    public function get_row_by_id(int $id): ?array {
        if ($id <= 0) {
            return null;
        }

        $sql  = 'SELECT * FROM ' . $this->table() . ' WHERE id = %d';
        $prep = $this->prepare($sql, [$id]);
        $row  = $this->db()->get_row($prep, ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<int, int|string|float> $base_values Values for placeholders before ownership fragment.
     */
    protected function prepare(string $sql, array $base_values): string {
        $wpdb = $this->db();

        if ($base_values === []) {
            return $sql;
        }

        return $wpdb->prepare($sql, ...$base_values);
    }

    /**
     * Whitelist orderby column for list queries.
     *
     * @param array<int, string> $allowed
     */
    protected function sanitize_orderby(string $orderby, array $allowed, string $fallback): string {
        return in_array($orderby, $allowed, true) ? $orderby : $fallback;
    }

    /**
     * @return 'ASC'|'DESC'
     */
    protected function sanitize_order(string $order): string {
        return strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
    }

    protected function sanitize_limit(int $limit): int {
        return max(1, min(500, $limit));
    }

    protected function sanitize_offset(int $offset): int {
        return max(0, $offset);
    }

    /**
     * List-query scope: normal users are ownership-restricted; administrators may filter by owner.
     *
     * @param array<string, mixed> $args List args; supports filter_user_id for administrators.
     *
     * @return array{0: string, 1: array<int, int|string|float>}
     */
    protected function list_scope_sql(array $args): array {
        if (! $this->is_unrestricted()) {
            return $this->ownership_sql();
        }

        $filter = isset($args['filter_user_id']) ? absint((int) $args['filter_user_id']) : 0;
        if ($filter > 0) {
            return [' AND user_id = %d', [$filter]];
        }

        return ['', []];
    }

    /**
     * @var array<string, array<string, string>>
     */
    private static array $eko_sampa_table_column_map_cache = [];

    /**
     * Map lowercase column name => actual column name from SHOW COLUMNS (stable INSERT keys).
     *
     * @return array<string, string>
     */
    protected function table_column_name_map(): array {
        $t = $this->table();
        if (isset(self::$eko_sampa_table_column_map_cache[ $t ])) {
            return self::$eko_sampa_table_column_map_cache[ $t ];
        }

        $safe = preg_replace('/[^a-z0-9_]/i', '', $t);
        if ($safe === '' || $safe !== $t) {
            return [];
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name sanitized to [a-z0-9_]+ above.
        $rows = $this->db()->get_results('SHOW COLUMNS FROM `' . $safe . '`', ARRAY_A);
        if (! is_array($rows) || $rows === []) {
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            if (! isset($row['Field']) || ! is_string($row['Field']) || $row['Field'] === '') {
                continue;
            }
            $field                = $row['Field'];
            $map[ strtolower($field) ] = $field;
        }

        self::$eko_sampa_table_column_map_cache[ $t ] = $map;

        return $map;
    }

    /**
     * Clear cached SHOW COLUMNS maps (call after schema migrations).
     */
    public static function clear_table_column_map_cache(): void {
        self::$eko_sampa_table_column_map_cache = [];
    }

    /**
     * When SHOW COLUMNS fails, only allow columns from the original baseline schema (safe INSERT).
     *
     * @return array<int, string>
     */
    protected function table_fallback_column_allowlist(): array {
        return match ($this->table_suffix()) {
            'eko_sampa_fields' => [
                'service_id',
                'label',
                'slug',
                'type',
                'required',
                'options_json',
                'sort_order',
            ],
            'eko_sampa_orders' => [
                'user_id',
                'client_id',
                'service_id',
                'template_id',
                'woo_order_id',
                'status',
                'dynamic_data_json',
                'print_ready',
                'order_title',
            ],
            'eko_sampa_templates' => [
                'user_id',
                'client_id',
                'product_id',
                'service_id',
                'nome',
                'categoria',
                'descricao',
                'width_mm',
                'height_mm',
                'preview_image',
                'template_type',
                'parent_template_id',
                'session_token',
                'expires_at',
                'last_activity_at',
                'saved_from_session_id',
                'allow_personalization',
                'is_public',
                'session_fingerprint',
                'session_lifecycle',
                'is_public_catalog',
                'is_user_shareable',
                'is_marketplace_item',
                'json_data',
                'title',
                'content',
                'width',
                'height',
                'background_color',
                'thumbnail',
                'created_at',
                'updated_at',
                'thumbnail_version',
                'thumbnail_visual_hash',
            ],
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function filter_row_to_allowlist(array $row, array $allow): array {
        if ($allow === []) {
            return $row;
        }

        $set = [];
        foreach ($allow as $col) {
            $set[ strtolower((string) $col) ] = true;
        }

        $out = [];
        foreach ($row as $key => $val) {
            $lk = strtolower((string) $key);
            if (isset($set[ $lk ])) {
                $out[ $key ] = $val;
            }
        }

        return $out;
    }

    /**
     * Remove keys that are not real table columns so INSERT/UPDATE survives when DB migrations lag behind plugin code.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    protected function filter_row_to_existing_columns(array $row): array {
        $map = $this->table_column_name_map();
        if ($map === []) {
            return $this->filter_row_to_allowlist($row, $this->table_fallback_column_allowlist());
        }

        $out = [];
        foreach ($row as $key => $val) {
            $lk = strtolower((string) $key);
            if (! isset($map[ $lk ])) {
                continue;
            }
            $actual         = $map[ $lk ];
            $out[ $actual ] = $val;
        }

        return $out;
    }
}
