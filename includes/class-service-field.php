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

    /** @var bool|null Lazily set: whether wp_{prefix}eko_sampa_fields exists. */
    private static ?bool $fields_table_exists = null;

    private static bool $schema_ensure_attempted = false;

    /** Last {@see create()} failure code for REST diagnostics (not persisted). */
    private ?string $last_create_failure = null;

    public function last_create_failure(): ?string {
        return $this->last_create_failure;
    }

    public static function clear_fields_table_cache(): void {
        self::$fields_table_exists = null;
    }

    private function ensure_fields_schema_once(): void {
        if (self::$schema_ensure_attempted) {
            return;
        }
        self::$schema_ensure_attempted = true;

        $database = new Eko_Sampa_Database();
        if ($database->table_exists_for_suffix('eko_sampa_fields')) {
            return;
        }

        $database->ensure_schema();
        self::clear_fields_table_cache();
    }

    private function fields_table_available(): bool {
        $this->ensure_fields_schema_once();

        if (self::$fields_table_exists !== null) {
            return self::$fields_table_exists;
        }

        $database = new Eko_Sampa_Database();
        self::$fields_table_exists = $database->table_exists_for_suffix('eko_sampa_fields');
        if (! self::$fields_table_exists) {
            $this->log_fields_table_missing_once();
        }

        return self::$fields_table_exists;
    }

    private function log_fields_table_missing_once(): void {
        static $logged = false;
        if ($logged) {
            return;
        }
        $logged = true;
        $should_log = (defined('EKO_SAMPA_DEBUG_DB') && EKO_SAMPA_DEBUG_DB)
            || (defined('EKO_SAMPA_DEBUG') && EKO_SAMPA_DEBUG);
        if ($should_log) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[eko-sampa] Table wp_eko_sampa_fields is missing — service field operations are skipped until DB migration runs.');
        }
    }

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
     * Stable slug for DB + uniqueness checks. Matches {@see sanitize_title()} when non-empty;
     * otherwise falls back to ASCII letters/digits/hyphens so numeric-only slugs (e.g. "666") are not
     * treated as empty by {@see sanitize_title()} / filters and do not make {@see slug_is_available()}
     * falsely return "taken".
     */
    public function normalize_field_slug(string $raw): string {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        $core = sanitize_title($raw);
        if ($core !== '') {
            return $core;
        }

        $ascii = strtolower((string) preg_replace('/[^a-z0-9_-]+/i', '-', $raw));
        $ascii = trim($ascii, '-');

        return $ascii !== '' ? $ascii : '';
    }

    /**
     * Whether `slug` is free for this service (optionally ignoring one field row).
     *
     * When the fields table is missing or `service_id` is invalid, returns true so callers do not
     * mis-report {@see WP_Error} 409 "slug exists"; {@see create()} / {@see update()} still fail safely.
     */
    public function slug_is_available(int $service_id, string $slug, ?int $except_field_id): bool {
        if (! $this->fields_table_available() || $service_id <= 0) {
            return true;
        }

        $clean = $this->normalize_field_slug($slug);
        if ($clean === '') {
            return true;
        }

        $sql  = 'SELECT id FROM ' . $this->table() . ' WHERE service_id = %d AND slug = %s';
        $vals = [$service_id, $clean];
        if ($except_field_id !== null && $except_field_id > 0) {
            $sql .= ' AND id != %d';
            $vals[] = $except_field_id;
        }
        $sql .= ' LIMIT 1';

        $prep = $this->prepare($sql, $vals);
        if (false === $prep) {
            return true;
        }
        $found = $this->db()->get_var($prep);

        return null === $found || '' === $found || 0 === (int) $found;
    }

    /**
     * Existing field row id for this service + slug (after {@see sanitize_title()}), if any.
     */
    public function find_field_id_by_service_slug(int $service_id, string $slug): ?int {
        if (! $this->fields_table_available() || $service_id <= 0) {
            return null;
        }
        $clean = $this->normalize_field_slug($slug);
        if ($clean === '') {
            return null;
        }
        $sql  = 'SELECT id FROM ' . $this->table() . ' WHERE service_id = %d AND slug = %s LIMIT 1';
        $prep = $this->prepare($sql, [$service_id, $clean]);
        if (false === $prep) {
            return null;
        }
        $found = $this->db()->get_var($prep);
        if (null === $found || '' === $found) {
            return null;
        }
        $id = (int) $found;

        return $id > 0 ? $id : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(int $service_id, array $data): int|false {
        $this->last_create_failure = null;

        if (! $this->fields_table_available()) {
            $this->last_create_failure = 'fields_table_missing';

            return false;
        }
        if (! $this->actor_may_touch_service($service_id)) {
            $this->last_create_failure = 'service_not_accessible';

            return false;
        }

        $row = $this->sanitize_row($data, false, $service_id);
        if ($row === []) {
            $this->last_create_failure = 'empty_field_row';

            return false;
        }

        $slug = (string) ($row['slug'] ?? '');
        if ($slug !== '' && ! $this->slug_is_available($service_id, $slug, null)) {
            $this->last_create_failure = 'slug_not_available';

            return false;
        }

        // wpdb::insert() is unreliable with literal NULL for %s columns on some stacks; omit nullable keys.
        if (array_key_exists('options_json', $row) && $row['options_json'] === null) {
            unset($row['options_json']);
        }
        if (array_key_exists('validation_rules_json', $row) && $row['validation_rules_json'] === null) {
            unset($row['validation_rules_json']);
        }

        $row = $this->filter_row_to_existing_columns($row);
        if ($row === [] || ! isset($row['service_id'], $row['label'], $row['slug'])) {
            $this->last_create_failure = 'row_missing_required_columns';

            return false;
        }

        $inserted = $this->db()->insert($this->table(), $row, $this->insert_formats($row));
        if (false === $inserted) {
            $this->last_create_failure = 'db_insert_failed';

            return false;
        }
        $new_id = (int) $this->db()->insert_id;

        if ($new_id <= 0) {
            $this->last_create_failure = 'no_insert_id';

            return false;
        }

        return $new_id;
    }

    public function get(int $id): ?array {
        if (! $this->fields_table_available()) {
            return null;
        }
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

        return $this->normalize_field_row($row);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): bool {
        if (! $this->fields_table_available()) {
            return false;
        }
        $existing = $this->get($id);
        if (! is_array($existing)) {
            return false;
        }

        $service_id = (int) ($existing['service_id'] ?? 0);
        if (array_key_exists('slug', $data)) {
            $candidate = $this->normalize_field_slug((string) $data['slug']);
            if ($candidate !== '' && ! $this->slug_is_available($service_id, $candidate, $id)) {
                return false;
            }
        }

        $row        = $this->sanitize_row($data, true, $service_id);
        if ($row === []) {
            return true;
        }

        $row = $this->filter_row_to_existing_columns($row);
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
        if (! $this->fields_table_available()) {
            return false;
        }
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
        if (! $this->fields_table_available()) {
            return true;
        }
        if ($service_id <= 0) {
            return false;
        }

        $svc_model = $this->service_model();
        $svc_row   = $svc_model->get_row_by_id($service_id);
        if (! is_array($svc_row) || ! $svc_model->can_actor_mutate_existing_row($svc_row)) {
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
        if (! $this->fields_table_available()) {
            return [];
        }
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

        if (! is_array($rows)) {
            return [];
        }

        return array_map([$this, 'normalize_field_row'], $rows);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function normalize_field_row(array $row): array {
        if (! array_key_exists('show_in_template', $row)) {
            $row['show_in_template'] = 1;
        } else {
            $row['show_in_template'] = (int) (bool) absint((int) $row['show_in_template']);
        }

        return $row;
    }

    /**
     * Persist display order for all fields of a service (full ordered id list required).
     *
     * @param array<int, int> $ordered_field_ids
     */
    public function reorder_for_service(int $service_id, array $ordered_field_ids): bool {
        if (! $this->fields_table_available() || $service_id <= 0 || ! $this->actor_may_touch_service($service_id)) {
            return false;
        }

        $ids = [];
        foreach ($ordered_field_ids as $id) {
            $n = (int) $id;
            if ($n > 0) {
                $ids[] = $n;
            }
        }

        $existing = $this->list_for_service($service_id, ['limit' => 500, 'offset' => 0]);
        $have      = [];
        foreach ($existing as $row) {
            if (is_array($row) && isset($row['id'])) {
                $have[ (int) $row['id'] ] = true;
            }
        }

        if ($have === [] || count($ids) !== count($have)) {
            return false;
        }

        foreach ($ids as $fid) {
            if (! isset($have[ $fid ])) {
                return false;
            }
        }

        $seen = [];
        foreach ($ids as $fid) {
            if (isset($seen[ $fid ])) {
                return false;
            }
            $seen[ $fid ] = true;
        }

        $pos = 0;
        foreach ($ids as $fid) {
            $ok = $this->update($fid, ['sort_order' => $pos]);
            if (! $ok) {
                return false;
            }
            ++$pos;
        }

        return true;
    }

    /**
     * @param mixed $v REST JSON may send booleans.
     */
    private function normalize_toggle_field(mixed $v): int {
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }

        return (int) (bool) absint((int) $v);
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
            $out['slug'] = isset($data['slug']) ? $this->normalize_field_slug((string) $data['slug']) : '';
        }
        if (! $partial || array_key_exists('type', $data)) {
            $type = isset($data['type']) ? sanitize_key((string) $data['type']) : 'text';
            $out['type'] = in_array($type, $this->allowed_types(), true) ? $type : 'text';
        }
        if (! $partial || array_key_exists('required', $data)) {
            $out['required'] = isset($data['required'])
                ? $this->normalize_toggle_field($data['required'])
                : 0;
        }
        if (! $partial || array_key_exists('options_json', $data)) {
            $out['options_json'] = $this->normalize_options($data['options_json'] ?? null);
        }
        if (! $partial || array_key_exists('sort_order', $data)) {
            $out['sort_order'] = isset($data['sort_order']) ? (int) $data['sort_order'] : 0;
        }
        if (! $partial || array_key_exists('default_value', $data)) {
            $dv = isset($data['default_value']) ? (string) $data['default_value'] : '';
            $out['default_value'] = strlen($dv) > 500 ? sanitize_textarea_field(substr($dv, 0, 500)) : sanitize_text_field($dv);
        }
        if (! $partial || array_key_exists('placeholder', $data)) {
            $out['placeholder'] = isset($data['placeholder'])
                ? sanitize_text_field((string) $data['placeholder'])
                : '';
        }
        if (! $partial || array_key_exists('show_in_template', $data)) {
            $out['show_in_template'] = isset($data['show_in_template'])
                ? $this->normalize_toggle_field($data['show_in_template'])
                : 1;
        }
        if (! $partial || array_key_exists('validation_rules_json', $data)) {
            $out['validation_rules_json'] = $this->normalize_rules($data['validation_rules_json'] ?? null);
        }

        return $out;
    }

    private function normalize_rules(mixed $value): ?string {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return wp_json_encode($value, JSON_UNESCAPED_UNICODE) ?: null;
        }

        if (is_object($value)) {
            $arr = json_decode(wp_json_encode($value, JSON_UNESCAPED_UNICODE) ?: '[]', true);

            return is_array($arr) ? (wp_json_encode($arr, JSON_UNESCAPED_UNICODE) ?: null) : null;
        }

        if (is_string($value)) {
            $decoded = json_decode(wp_unslash($value), true);
            if (JSON_ERROR_NONE !== json_last_error()) {
                return null;
            }

            return is_array($decoded) ? (wp_json_encode($decoded, JSON_UNESCAPED_UNICODE) ?: null) : null;
        }

        return null;
    }

    private function normalize_options(mixed $value): ?string {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return wp_json_encode($value, JSON_UNESCAPED_UNICODE) ?: null;
        }

        if (is_object($value)) {
            $arr = json_decode(wp_json_encode($value, JSON_UNESCAPED_UNICODE) ?: '[]', true);

            return is_array($arr) ? (wp_json_encode($arr, JSON_UNESCAPED_UNICODE) ?: null) : null;
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
            'service_id'              => '%d',
            'label'                   => '%s',
            'slug'                    => '%s',
            'type'                    => '%s',
            'required'                => '%d',
            'options_json'            => '%s',
            'sort_order'              => '%d',
            'default_value'           => '%s',
            'placeholder'             => '%s',
            'show_in_template'        => '%d',
            'validation_rules_json'   => '%s',
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
