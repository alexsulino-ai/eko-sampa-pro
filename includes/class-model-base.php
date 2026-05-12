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
}
