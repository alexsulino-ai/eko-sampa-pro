<?php
/**
 * Dynamic field definitions for services (wp_eko_sampa_fields).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * CRUD for service fields; mutates only when the parent service is editable by the actor.
 */
final class Eko_Sampa_Service_Field extends Eko_Sampa_Model_Base {

    /**
     * @return array<int, string>
     */
    private function allowed_types(): array {
        return ['text', 'textarea', 'number', 'select', 'date'];
    }

    protected function table_suffix(): string {
        return 'eko_sampa_fields';
    }

    /**
     * Fields table has no user_id; scope is enforced via parent service.
     */
    protected function ownership_predicate(): array {
        return ['1=0', []];
    }

    private function service_model(): Eko_Sampa_Service {
        return new Eko_Sampa_Service();
    }

    private function actor_may_touch_service(int $service_id): bool {
        if ($service_id <= 0) {
            return false;
        }

        return is_array($this->service_model()->get($service_id));
    }

    /**
     * Whether `slug` is free for this service (optionally ignoring one field row).
     */
    public function slug_is_available(int $service_id, string $slug, ?int $except_field_id): bool {
        if ($service_id <= 0) {
            return false;
        }

        $clean = sanitize_title($slug);
        if ($clean === '') {
            return false;
        }

        $sql  = 'SELECT id FROM ' . $this->table() . ' WHERE service_id = %d AND slug = %s';
        $vals = [$service_id, $clean];
        if ($except_field_id !== null && $except_field_id > 0) {
            $sql .= ' AND id != %d';
            $vals[] = $except_field_id;
        }
        $sql .= ' LIMIT 1';

        $prep = $this->prepare($sql, $vals);
        $found = $this->db()->get_var($prep);

        return null === $found || '' === $found || 0 === (int) $found;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(int $service_id, array $data): int|false {
        if (! $this->actor_may_touch_service($service_id)) {
            return false;
        }

        $row = $this->sanitize_row($data, false, $service_id);
        if ($row === []) {
            return false;
        }

        $slug = (string) ($row['slug'] ?? '');
        if ($slug !== '' && ! $this->slug_is_available($service_id, $slug, null)) {
            return false;
        }

        $inserted = $this->db()->insert($this->table(), $row, $this->insert_formats($row));

        return $inserted ? (int) $this->db()->insert_id : false;
    }

    public function get(int $id): ?array {
        if ($id <= 0) {
            return null;
        }

        $sql  = 'SELECT * FROM ' . $this->table() . ' WHERE id = %d';
        $prep = $this->prepare($sql, [$id]);
        $row  = $this->db()->get_row($prep, ARRAY_A);
        if (! is_array($row)) {
            return null;
        }

        $sid = (int) ($row['service_id'] ?? 0);
        if (! $this->actor_may_touch_service($sid)) {
            return null;
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): bool {
        $existing = $this->get($id);
        if (! is_array($existing)) {
            return false;
        }

        $service_id = (int) ($existing['service_id'] ?? 0);
        if (array_key_exists('slug', $data)) {
            $candidate = sanitize_title((string) $data['slug']);
            if ($candidate !== '' && ! $this->slug_is_available($service_id, $candidate, $id)) {
                return false;
            }
        }

        $row        = $this->sanitize_row($data, true, $service_id);
        if ($row === []) {
            return true;
        }

        $result = $this->db()->update(
            $this->table(),
            $row,
            ['id' => $id],
            $this->update_formats($row),
            ['%d']
        );

        return false !== $result;
    }

    public function delete(int $id): bool {
        $existing = $this->get($id);
        if (! is_array($existing)) {
            return false;
        }

        $sql  = 'DELETE FROM ' . $this->table() . ' WHERE id = %d';
        $prep = $this->prepare($sql, [$id]);

        return false !== $this->db()->query($prep);
    }

    /**
     * Remove all field rows for a service (used before deleting the service).
     */
    public function delete_all_for_service(int $service_id): bool {
        if ($service_id <= 0 || ! $this->actor_may_touch_service($service_id)) {
            return false;
        }

        $sql  = 'DELETE FROM ' . $this->table() . ' WHERE service_id = %d';
        $prep = $this->prepare($sql, [$service_id]);
        $ok   = $this->db()->query($prep);

        if (false === $ok && defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[eko-sampa] delete_all_for_service failed service_id=' . $service_id . ' sql=' . $prep . ' last_error=' . $this->db()->last_error);
        }

        return false !== $ok;
    }

    public function list_for_service(int $service_id, array $args = []): array {
        if (! $this->actor_may_touch_service($service_id)) {
            return [];
        }

        $limit   = $this->sanitize_limit((int) ($args['limit'] ?? 200));
        $offset  = $this->sanitize_offset((int) ($args['offset'] ?? 0));
        $orderby = 'sort_order';
        $order   = $this->sanitize_order((string) ($args['order'] ?? 'ASC'));

        $sql  = 'SELECT * FROM ' . $this->table() . ' WHERE service_id = %d ORDER BY ' . $orderby . ' ' . $order
            . ' LIMIT %d OFFSET %d';
        $prep = $this->prepare($sql, [$service_id, $limit, $offset]);
        $rows = $this->db()->get_results($prep, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function sanitize_row(array $data, bool $partial, int $service_id): array {
        $out = [];

        if (! $partial) {
            $out['service_id'] = $service_id;
        }

        if (! $partial || array_key_exists('label', $data)) {
            $out['label'] = isset($data['label']) ? sanitize_text_field((string) $data['label']) : '';
        }
        if (! $partial || array_key_exists('slug', $data)) {
            $out['slug'] = isset($data['slug']) ? sanitize_title((string) $data['slug']) : '';
        }
        if (! $partial || array_key_exists('type', $data)) {
            $type = isset($data['type']) ? sanitize_key((string) $data['type']) : 'text';
            $out['type'] = in_array($type, $this->allowed_types(), true) ? $type : 'text';
        }
        if (! $partial || array_key_exists('required', $data)) {
            $out['required'] = isset($data['required']) ? (int) (bool) absint((int) $data['required']) : 0;
        }
        if (! $partial || array_key_exists('options_json', $data)) {
            $out['options_json'] = $this->normalize_options($data['options_json'] ?? null);
        }
        if (! $partial || array_key_exists('sort_order', $data)) {
            $out['sort_order'] = isset($data['sort_order']) ? (int) $data['sort_order'] : 0;
        }

        return $out;
    }

    private function normalize_options(mixed $value): ?string {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return wp_json_encode($value, JSON_UNESCAPED_UNICODE) ?: null;
        }

        if (is_string($value)) {
            $decoded = json_decode(wp_unslash($value), true);
            if (JSON_ERROR_NONE !== json_last_error()) {
                return null;
            }

            return wp_json_encode($decoded, JSON_UNESCAPED_UNICODE) ?: null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<int, string>
     */
    private function insert_formats(array $row): array {
        $map = [
            'service_id'   => '%d',
            'label'        => '%s',
            'slug'         => '%s',
            'type'         => '%s',
            'required'     => '%d',
            'options_json' => '%s',
            'sort_order'   => '%d',
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
