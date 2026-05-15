<?php
/**
 * Template entity: CRUD, JSON payload only (database.md / architecture.md).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * wp_eko_sampa_templates model.
 */
final class Eko_Sampa_Template extends Eko_Sampa_Model_Base {

    /**
     * @return array<int, string>
     */
    private function allowed_orderby(): array {
        return ['id', 'nome', 'categoria', 'created_at', 'updated_at', 'product_id', 'service_id'];
    }

    protected function table_suffix(): string {
        return 'eko_sampa_templates';
    }

    protected function ownership_predicate(): array {
        return ['user_id = %d', [$this->current_user_id()]];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int|false {
        $uid = $this->current_user_id();
        if ($uid <= 0) {
            return false;
        }

        if ($this->is_unrestricted() && isset($data['user_id'])) {
            $forced = absint((int) $data['user_id']);
            if ($forced > 0) {
                $uid = $forced;
            }
        }

        $row = $this->sanitize_row($data, false);
        if (array_key_exists('json_data', $data)) {
            $encoded = $this->normalize_json($data['json_data']);
            if ($encoded === false && $this->json_input_present($data['json_data'])) {
                return false;
            }
            $row['json_data'] = $encoded;
        }

        $row['user_id'] = $uid;
        $row = $this->filter_row_to_existing_columns($row);

        if ($row === []) {
            return false;
        }

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
        if ($id <= 0 || ! $this->get($id)) {
            return false;
        }

        $row = $this->sanitize_row($data, true);
        if (array_key_exists('json_data', $data)) {
            $encoded = $this->normalize_json($data['json_data']);
            if ($encoded === false && $this->json_input_present($data['json_data'])) {
                return false;
            }
            $row['json_data'] = $encoded;
        }

        if ($row === []) {
            return true;
        }

        $row = $this->filter_row_to_existing_columns($row);
        if ($row === []) {
            return true;
        }

        [$extra, $own] = $this->ownership_sql();
        $where  = array_merge(['id' => $id], $this->ownership_where_columns($own));
        $wfmt   = array_merge(['%d'], $this->ownership_where_formats($own));
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

        [$extra, $own] = $this->ownership_sql();
        $sql  = 'DELETE FROM ' . $this->table() . ' WHERE id = %d' . $extra;
        $prep = $this->prepare($sql, array_merge([$id], $own));

        return false !== $this->db()->query($prep);
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
            $search_sql  = ' AND (nome LIKE %s OR descricao LIKE %s OR categoria LIKE %s)';
            $search_vals = [$like, $like, $like];
        }

        $cat_sql = '';
        $cat_vals = [];
        if (! empty($args['categoria'])) {
            $cat_sql = ' AND categoria = %s';
            $cat_vals[] = sanitize_text_field((string) $args['categoria']);
        }

        $sql  = 'SELECT * FROM ' . $this->table() . ' WHERE 1=1' . $extra . $search_sql . $cat_sql
            . ' ORDER BY ' . $orderby . ' ' . $order
            . ' LIMIT %d OFFSET %d';
        $prep = $this->prepare($sql, array_merge($own, $search_vals, $cat_vals, [$limit, $offset]));
        $rows = $this->db()->get_results($prep, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Clone a template row (same JSON payload; new name suffix).
     */
    public function duplicate(int $id): int|false {
        $source = $this->get($id);
        if (! is_array($source)) {
            return false;
        }

        $nome = (string) ($source['nome'] ?? '');
        $copy  = $nome . ' (' . __('Copy', 'eko-sampa') . ')';

        $payload = [
            'nome'           => $copy,
            'categoria'      => (string) ($source['categoria'] ?? ''),
            'descricao'      => (string) ($source['descricao'] ?? ''),
            'client_id'      => (int) ( $source['client_id'] ?? 0 ),
            'product_id'     => (int) ($source['product_id'] ?? 0),
            'service_id'     => (int) ($source['service_id'] ?? 0),
            'width_mm'       => (int) ($source['width_mm'] ?? 0),
            'height_mm'      => (int) ($source['height_mm'] ?? 0),
            'preview_image'  => (string) ($source['preview_image'] ?? ''),
            'json_data'      => $source['json_data'] ?? null,
            'user_id'        => (int) ($source['user_id'] ?? 0),
        ];

        return $this->create($payload);
    }

    /**
     * @param array<int, int|string|float> $own
     *
     * @return array<string, int>
     */
    private function ownership_where_columns(array $own): array {
        if ($this->is_unrestricted()) {
            return [];
        }

        return ['user_id' => (int) $own[0]];
    }

    /**
     * @param array<int, int|string|float> $own
     *
     * @return array<int, string>
     */
    private function ownership_where_formats(array $own): array {
        return $own === [] ? [] : ['%d'];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function sanitize_row(array $data, bool $partial): array {
        $out = [];

        if (! $partial || array_key_exists('client_id', $data)) {
            $out['client_id'] = isset($data['client_id']) ? absint((int) $data['client_id']) : 0;
        }
        if (! $partial || array_key_exists('product_id', $data)) {
            $out['product_id'] = isset($data['product_id']) ? absint((int) $data['product_id']) : 0;
        }
        if (! $partial || array_key_exists('service_id', $data)) {
            $out['service_id'] = isset($data['service_id']) ? absint((int) $data['service_id']) : 0;
        }
        if (! $partial || array_key_exists('nome', $data)) {
            $out['nome'] = isset($data['nome'])
                ? sanitize_text_field((string) $data['nome'])
                : '';
        }
        if (! $partial || array_key_exists('categoria', $data)) {
            $out['categoria'] = isset($data['categoria'])
                ? sanitize_text_field((string) $data['categoria'])
                : '';
        }
        if (! $partial || array_key_exists('descricao', $data)) {
            $out['descricao'] = isset($data['descricao'])
                ? sanitize_textarea_field((string) $data['descricao'])
                : '';
        }
        if (! $partial || array_key_exists('width_mm', $data)) {
            $out['width_mm'] = isset($data['width_mm']) ? absint((int) $data['width_mm']) : 0;
        }
        if (! $partial || array_key_exists('height_mm', $data)) {
            $out['height_mm'] = isset($data['height_mm']) ? absint((int) $data['height_mm']) : 0;
        }
        if (! $partial || array_key_exists('preview_image', $data)) {
            $out['preview_image'] = isset($data['preview_image'])
                ? sanitize_text_field((string) $data['preview_image'])
                : '';
        }

        return $out;
    }

    /**
     * @return string|null Encoded JSON or null for empty; false on invalid input.
     */
    private function normalize_json(mixed $value): string|false|null {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return wp_json_encode($value, JSON_UNESCAPED_UNICODE) ?: false;
        }

        if (is_string($value)) {
            $decoded = json_decode(wp_unslash($value), true);
            if (JSON_ERROR_NONE !== json_last_error()) {
                return false;
            }

            return wp_json_encode($decoded, JSON_UNESCAPED_UNICODE) ?: false;
        }

        return false;
    }

    private function json_input_present(mixed $value): bool {
        return ! ($value === null || $value === '');
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<int, string>
     */
    private function insert_formats(array $row): array {
        $map = [
            'user_id'        => '%d',
            'client_id'      => '%d',
            'product_id'     => '%d',
            'service_id'     => '%d',
            'nome'           => '%s',
            'categoria'      => '%s',
            'descricao'      => '%s',
            'width_mm'       => '%d',
            'height_mm'      => '%d',
            'preview_image'  => '%s',
            'json_data'      => '%s',
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
