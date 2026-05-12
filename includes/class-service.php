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

        $field_model = new Eko_Sampa_Service_Field();
        if (! $field_model->delete_all_for_service($id)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[eko-sampa] service_delete: delete_all_for_service returned false for service_id=' . $id . ' last_error=' . $this->db()->last_error);
            }

            return false;
        }

        $tpl_table = $this->db()->prefix . 'eko_sampa_templates';
        $this->db()->update($tpl_table, ['service_id' => 0], ['service_id' => $id], ['%d'], ['%d']);
        $ord_table = $this->db()->prefix . 'eko_sampa_orders';
        $this->db()->update($ord_table, ['service_id' => 0], ['service_id' => $id], ['%d'], ['%d']);

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
            error_log('[eko-sampa] service_delete_failed id=' . $id . ' sql=' . $prep . ' last_error=' . $this->db()->last_error);
        }

        return false !== $ok;
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

        return is_array($rows) ? $rows : [];
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
