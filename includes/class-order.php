<?php
/**
 * Order entity: CRUD, JSON dynamic fields, status whitelist (database.md).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * wp_eko_sampa_orders model.
 */
final class Eko_Sampa_Order extends Eko_Sampa_Model_Base {

    private const STATUS_PENDING     = 'pending';

    private const STATUS_IN_PROGRESS = 'in_progress';

    private const STATUS_PRINT_QUEUE = 'print_queue';

    private const STATUS_COMPLETED   = 'completed';

    /**
     * @return array<int, string>
     */
    private function allowed_orderby(): array {
        return ['id', 'status', 'created_at', 'updated_at', 'woo_order_id'];
    }

    /**
     * @return array<int, string>
     */
    private function allowed_statuses(): array {
        return [
            self::STATUS_PENDING,
            self::STATUS_IN_PROGRESS,
            self::STATUS_PRINT_QUEUE,
            self::STATUS_COMPLETED,
        ];
    }

    protected function table_suffix(): string {
        return 'eko_sampa_orders';
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

        if (! $this->relations_visible($data, null)) {
            return false;
        }

        $row = $this->sanitize_row($data, false);
        if (array_key_exists('dynamic_data_json', $data)) {
            $encoded = $this->normalize_json($data['dynamic_data_json']);
            if ($encoded === false && $this->json_input_present($data['dynamic_data_json'])) {
                return false;
            }
            $row['dynamic_data_json'] = $encoded;
        }

        $row['user_id'] = $uid;

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
        $existing = $this->get($id);
        if ($id <= 0 || ! is_array($existing)) {
            return false;
        }

        if (! $this->relations_visible($data, $existing)) {
            return false;
        }

        $row = $this->sanitize_row($data, true);
        if (array_key_exists('dynamic_data_json', $data)) {
            $encoded = $this->normalize_json($data['dynamic_data_json']);
            if ($encoded === false && $this->json_input_present($data['dynamic_data_json'])) {
                return false;
            }
            $row['dynamic_data_json'] = $encoded;
        }

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
        $frag = '';
        $vals = [];
        if (! empty($args['status'])) {
            $st = sanitize_key((string) $args['status']);
            if (in_array($st, $this->allowed_statuses(), true)) {
                $frag .= ' AND status = %s';
                $vals[] = $st;
            }
        }
        if (! empty($args['s'])) {
            $s = sanitize_text_field((string) $args['s']);
            if ($s !== '' && ctype_digit($s)) {
                $frag .= ' AND id = %d';
                $vals[] = (int) $s;
            }
        }

        $sql  = 'SELECT * FROM ' . $this->table() . ' WHERE 1=1' . $extra . $frag
            . ' ORDER BY ' . $orderby . ' ' . $order
            . ' LIMIT %d OFFSET %d';
        $prep = $this->prepare($sql, array_merge($own, $vals, [$limit, $offset]));
        $rows = $this->db()->get_results($prep, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Duplicate an order (pending, not print-ready, same relations).
     */
    public function duplicate(int $id): int|false {
        $source = $this->get($id);
        if (! is_array($source)) {
            return false;
        }

        $payload = [
            'user_id'            => (int) ($source['user_id'] ?? 0),
            'client_id'          => (int) ($source['client_id'] ?? 0),
            'service_id'         => (int) ($source['service_id'] ?? 0),
            'template_id'        => (int) ($source['template_id'] ?? 0),
            'woo_order_id'       => 0,
            'status'             => self::STATUS_PENDING,
            'dynamic_data_json'  => $source['dynamic_data_json'] ?? null,
            'print_ready'        => 0,
        ];

        return $this->create($payload);
    }

    /**
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $existing_row Current row for partial updates; null on create.
     */
    private function relations_visible(array $data, ?array $existing_row): bool {
        $keys = ['client_id', 'service_id', 'template_id'];
        $ids  = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $ids[$key] = absint((int) $data[$key]);
            } elseif (is_array($existing_row)) {
                $ids[$key] = (int) ($existing_row[$key] ?? 0);
            } else {
                $ids[$key] = 0;
            }
        }

        if ($ids['client_id'] > 0) {
            $client = new Eko_Sampa_Client();
            if (! is_array($client->get($ids['client_id']))) {
                return false;
            }
        }

        if ($ids['service_id'] > 0) {
            $service = new Eko_Sampa_Service();
            if (! is_array($service->get($ids['service_id']))) {
                return false;
            }
        }

        if ($ids['template_id'] > 0) {
            $template = new Eko_Sampa_Template();
            if (! is_array($template->get($ids['template_id']))) {
                return false;
            }
        }

        return true;
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
        if (! $partial || array_key_exists('service_id', $data)) {
            $out['service_id'] = isset($data['service_id']) ? absint((int) $data['service_id']) : 0;
        }
        if (! $partial || array_key_exists('template_id', $data)) {
            $out['template_id'] = isset($data['template_id']) ? absint((int) $data['template_id']) : 0;
        }
        if (! $partial || array_key_exists('woo_order_id', $data)) {
            $out['woo_order_id'] = isset($data['woo_order_id']) ? absint((int) $data['woo_order_id']) : 0;
        }
        if (! $partial || array_key_exists('status', $data)) {
            $out['status'] = $this->sanitize_status(
                isset($data['status']) ? (string) $data['status'] : self::STATUS_PENDING
            );
        }
        if (! $partial || array_key_exists('print_ready', $data)) {
            $out['print_ready'] = isset($data['print_ready'])
                ? (int) (bool) absint((int) $data['print_ready'])
                : 0;
        }

        return $out;
    }

    private function sanitize_status(string $status): string {
        $clean = sanitize_key($status);

        return in_array($clean, $this->allowed_statuses(), true)
            ? $clean
            : self::STATUS_PENDING;
    }

    /**
     * @return string|null|string false on invalid.
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
            'user_id'            => '%d',
            'client_id'          => '%d',
            'service_id'         => '%d',
            'template_id'        => '%d',
            'woo_order_id'       => '%d',
            'status'             => '%s',
            'dynamic_data_json'  => '%s',
            'print_ready'        => '%d',
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
