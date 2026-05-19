<?php
/**
 * Service entity: CRUD with ownership; non-admins may read globals (database.md).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * wp_eko_sampa_services model.
 */
final class Eko_Sampa_Service extends Eko_Sampa_Model_Base {

    /** Legacy auto-repair label prefix — hidden from catalog lists, not counted as user-created. */
    public const RECOVERED_NAME_PREFIX = 'Recovered service';

    /**
     * Align REST capability (`manage_eko_services`) with model scope for services only.
     *
     * @see eko_sampa_services_actor_has_elevated_scope() Single source shared with REST.
     */
    protected function is_unrestricted(): bool {
        return eko_sampa_services_actor_has_elevated_scope();
    }

    /**
     * Distinguish "row missing" vs "row exists but hidden by ownership scope" (never infer from plain {@see get()} alone).
     *
     * @return array<string, mixed>
     */
    public function explain_row_visibility(int $id): array {
        if ($id <= 0) {
            return [
                'service_id'         => $id,
                'exists_in_db'       => false,
                'visible_to_actor'   => false,
                'blocked_by_scope'   => false,
                'owner_user_id'      => 0,
                'is_global'          => 0,
                'elevated_scope'     => function_exists('eko_sampa_services_actor_has_elevated_scope')
                    && eko_sampa_services_actor_has_elevated_scope(),
                'hint'               => 'invalid_id',
            ];
        }

        $row    = $this->get_row_by_id($id);
        $exists = is_array($row);
        $visible = is_array($this->get($id));

        $hint = 'ok';
        if (! $exists) {
            $hint = 'not_in_database';
        } elseif (! $visible) {
            $hint = 'hidden_by_ownership_scope_use_get_row_by_id_or_elevated_scope';
        }

        return [
            'service_id'         => $id,
            'exists_in_db'       => $exists,
            'visible_to_actor'   => $visible,
            'blocked_by_scope'   => $exists && ! $visible,
            'owner_user_id'      => $exists ? (int) ( $row['user_id'] ?? 0 ) : 0,
            'is_global'          => $exists ? (int) ( $row['is_global'] ?? 0 ) : 0,
            'elevated_scope'     => function_exists('eko_sampa_services_actor_has_elevated_scope')
                && eko_sampa_services_actor_has_elevated_scope(),
            'hint'               => $hint,
        ];
    }

    /**
     * @param array<string, mixed> $row Row from {@see get_row_by_id()} or {@see get()}.
     */
    public function can_actor_mutate_existing_row(array $row): bool {
        return $this->actor_may_mutate_row($row);
    }

    /**
     * Final DELETE after fields/templates/orders were handled (used by {@see eko_sampa_safe_delete_service()}).
     */
    public function delete_service_row_only(int $id): bool {
        if ($id <= 0) {
            return false;
        }

        if ($this->is_unrestricted()) {
            $sql  = 'DELETE FROM ' . $this->table() . ' WHERE id = %d';
            $prep = $this->prepare($sql, [$id]);
        } else {
            $sql  = 'DELETE FROM ' . $this->table() . ' WHERE id = %d AND user_id = %d';
            $prep = $this->prepare($sql, [$id, $this->current_user_id()]);
        }

        $ok = $this->db()->query($prep);
        if (false === $ok && defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[eko-sampa] delete_service_row_only failed id=' . $id . ' sql=' . $prep . ' last_error=' . $this->db()->last_error);
        }

        return false !== $ok;
    }

    /**
     * @return array<int, string>
     */
    private function allowed_orderby(): array {
        return ['id', 'nome', 'created_at', 'updated_at', 'is_global'];
    }

    protected function table_suffix(): string {
        return 'eko_sampa_services';
    }

    /**
     * Read scope: own rows or global catalog entries.
     *
     * @return array{0: string, 1: array<int, int|string>}
     */
    protected function ownership_predicate(): array {
        return ['(user_id = %d OR is_global = 1)', [$this->current_user_id()]];
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array{0: string, 1: array<int, int|string|float>}
     */
    protected function list_scope_sql(array $args): array {
        if (! $this->is_unrestricted()) {
            return $this->ownership_sql();
        }

        $filter = isset($args['filter_user_id']) ? absint((int) $args['filter_user_id']) : 0;
        if ($filter > 0) {
            return [' AND (user_id = %d OR is_global = 1)', [$filter]];
        }

        return ['', []];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int|false {
        $uid = $this->current_user_id();
        if ($uid <= 0) {
            return false;
        }

        $row              = $this->sanitize_row($data, false);
        $row['user_id']   = $uid;
        $row['is_global'] = $this->sanitize_is_global($data, false);

        $inserted = $this->db()->insert($this->table(), $row, $this->insert_formats($row));

        return $inserted ? (int) $this->db()->insert_id : false;
    }

    public function get(int $id): ?array {
        if ($id <= 0) {
            return null;
        }

        [$extra, $own] = $this->ownership_sql();
        $sql  = 'SELECT * FROM ' . $this->table() . ' WHERE id = %d' . $extra;
        $prep = $this->prepare($sql, array_merge([$id], $own));
        $row  = $this->db()->get_row($prep, ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): bool {
        if ($id <= 0) {
            return false;
        }

        $existing = $this->get($id);
        if (! is_array($existing)) {
            return false;
        }

        if (! $this->actor_may_mutate_row($existing)) {
            return false;
        }

        $row = $this->sanitize_row($data, true);
        if (array_key_exists('is_global', $data)) {
            $row['is_global'] = $this->sanitize_is_global($data, true);
        }

        if ($row === []) {
            return true;
        }

        if ($this->is_unrestricted()) {
            $where = ['id' => $id];
            $wfmt  = ['%d'];
        } else {
            $where = ['id' => $id, 'user_id' => $this->current_user_id()];
            $wfmt  = ['%d', '%d'];
        }

        $result = $this->db()->update(
            $this->table(),
            $row,
            $where,
            $this->update_formats($row),
            $wfmt
        );

        return false !== $result;
    }

    public function delete(int $id): bool {
        if (! function_exists('eko_sampa_safe_delete_service')) {
            return false;
        }

        $result = eko_sampa_safe_delete_service($id, []);

        return ! empty($result['ok']);
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(array $args = []): array {
        $limit   = $this->sanitize_limit((int) ($args['limit'] ?? 100));
        $offset  = $this->sanitize_offset((int) ($args['offset'] ?? 0));
        $orderby = $this->sanitize_orderby(
            sanitize_key((string) ($args['orderby'] ?? 'id')),
            $this->allowed_orderby(),
            'id'
        );
        $order = $this->sanitize_order((string) ($args['order'] ?? 'DESC'));

        [$extra, $own] = $this->list_scope_sql($args);
        $search_sql = '';
        $search_vals = [];
        if (! empty($args['s'])) {
            $like = '%' . $this->db()->esc_like(sanitize_text_field((string) $args['s'])) . '%';
            $search_sql  = ' AND (nome LIKE %s OR descricao LIKE %s)';
            $search_vals = [$like, $like];
        }

        $sql  = 'SELECT * FROM ' . $this->table() . ' WHERE 1=1' . $extra . $search_sql
            . ' ORDER BY ' . $orderby . ' ' . $order
            . ' LIMIT %d OFFSET %d';
        $prep = $this->prepare($sql, array_merge($own, $search_vals, [$limit, $offset]));
        $rows = $this->db()->get_results($prep, ARRAY_A);

        if (! is_array($rows)) {
            return [];
        }

        return $this->filter_catalog_rows($rows);
    }

    /**
     * System / legacy repair rows must not appear in user service catalogs.
     *
     * @param array<string, mixed>|null $row
     */
    public static function is_recovered_row(?array $row): bool {
        if (! is_array($row)) {
            return false;
        }

        $nome = trim((string) ( $row['nome'] ?? '' ));
        if ($nome === '') {
            return false;
        }

        if (str_starts_with($nome, self::RECOVERED_NAME_PREFIX)) {
            return true;
        }

        return str_contains($nome, '(was #');
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    public function filter_catalog_rows(array $rows): array {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row) || self::is_recovered_row($row)) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function actor_may_mutate_row(array $row): bool {
        if ($this->is_unrestricted()) {
            return true;
        }

        return (int) $row['user_id'] === $this->current_user_id();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function sanitize_is_global(array $data, bool $partial): int {
        if ($partial && ! array_key_exists('is_global', $data)) {
            return 0;
        }

        $raw = $data['is_global'] ?? 0;
        $bit = (int) (bool) absint((int) $raw);

        return $this->is_unrestricted() ? $bit : 0;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function sanitize_row(array $data, bool $partial): array {
        $out = [];

        if (! $partial || array_key_exists('nome', $data)) {
            $out['nome'] = isset($data['nome'])
                ? sanitize_text_field((string) $data['nome'])
                : '';
        }
        if (! $partial || array_key_exists('descricao', $data)) {
            $out['descricao'] = isset($data['descricao'])
                ? sanitize_textarea_field((string) $data['descricao'])
                : '';
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<int, string>
     */
    private function insert_formats(array $row): array {
        $map = [
            'user_id'   => '%d',
            'nome'      => '%s',
            'descricao' => '%s',
            'is_global' => '%d',
        ];
        $out = [];
        foreach (array_keys($row) as $key) {
            $out[] = $map[$key] ?? '%s';
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<int, string>
     */
    private function update_formats(array $row): array {
        return $this->insert_formats($row);
    }
}
