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

    /** @var array{ok: bool, debug: array<string, mixed>}|null */
    private ?array $last_relations_check = null;

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

        $data = $this->prepare_create_data($data);

        if (! $this->relations_visible($data, null)) {
            return false;
        }

        $snap_override = null;
        if (isset($data['_eko_sampa_field_snapshot']) && is_string($data['_eko_sampa_field_snapshot'])) {
            $snap_override = $data['_eko_sampa_field_snapshot'];
        }
        unset($data['_eko_sampa_field_snapshot']);

        $row = $this->sanitize_row($data, false);
        if (array_key_exists('dynamic_data_json', $data)) {
            $encoded = $this->normalize_json($data['dynamic_data_json']);
            if ($encoded === false && $this->json_input_present($data['dynamic_data_json'])) {
                return false;
            }
            $row['dynamic_data_json'] = $encoded;
        }

        $row['user_id'] = $uid;

        $sid = (int) ($row['service_id'] ?? 0);
        $snap_in = $snap_override;
        if (is_string($snap_in) && $snap_in !== '' && strlen($snap_in) <= 131072) {
            $row['service_fields_snapshot_json'] = $snap_in;
        } elseif ($sid > 0) {
            $built = $this->build_service_fields_snapshot_json($sid);
            if ($built !== null) {
                $row['service_fields_snapshot_json'] = $built;
            }
        }

        $row = $this->filter_row_to_existing_columns($row);

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
     * Find an OS row linked to a WooCommerce order ID (woo_order_id column).
     */
    public function get_by_woo_order_id(int $woo_order_id): ?array {
        if ($woo_order_id <= 0) {
            return null;
        }

        [$extra, $own] = $this->ownership_sql();
        $sql  = 'SELECT * FROM ' . $this->table() . ' WHERE woo_order_id = %d' . $extra . ' LIMIT 1';
        $prep = $this->prepare($sql, array_merge([$woo_order_id], $own));
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

        unset($row['service_fields_snapshot_json']);

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

        $snap = $source['service_fields_snapshot_json'] ?? null;
        if (is_string($snap) && $snap !== '') {
            $payload['_eko_sampa_field_snapshot'] = $snap;
        }

        return $this->create($payload);
    }

    /**
     * Normalize create payload: inherit client_id from template when omitted.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function prepare_create_data(array $data): array {
        $data = $this->resolve_order_relations_from_template($data);
        $data = $this->inherit_client_from_template($data);

        return $data;
    }

    /**
     * Last failed relations_validate() result (for REST error payloads).
     *
     * @return array{ok: bool, debug: array<string, mixed>}|null
     */
    public function last_relations_check(): ?array {
        return $this->last_relations_check;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function inherit_client_from_template(array $data): array {
        $template_id = absint((int) ( $data['template_id'] ?? 0 ));
        if ($template_id <= 0) {
            return $data;
        }

        // Explicit client_id in payload (including 0 = anonymous) must not be overridden.
        if (array_key_exists('client_id', $data)) {
            $data['client_id'] = absint((int) $data['client_id']);

            return $data;
        }

        $template = (new Eko_Sampa_Template())->get($template_id);
        if (! is_array($template)) {
            return $data;
        }

        $client_id = absint((int) ( $template['client_id'] ?? 0 ));
        if ($client_id > 0) {
            $data['client_id'] = $client_id;
        }

        return $data;
    }

    /**
     * When template_id is set, load the template from DB and align service_id (template is authoritative).
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function resolve_order_relations_from_template(array $data): array {
        $template_id = absint((int) ( $data['template_id'] ?? 0 ));
        if ($template_id <= 0) {
            return $data;
        }

        $template = (new Eko_Sampa_Template())->get($template_id);
        if (! is_array($template)) {
            return $data;
        }

        $tpl_service     = $this->template_service_id_from_row($template);
        $payload_service = absint((int) ( $data['service_id'] ?? 0 ));

        if ($tpl_service > 0) {
            $data['service_id'] = $tpl_service;
        } elseif ($payload_service > 0) {
            $data['service_id'] = $payload_service;
        }

        $data['template_id'] = $template_id;

        return $data;
    }

    /**
     * Validate relations; returns which check failed (for API debug).
     *
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $existing_row
     *
     * @return array{ok: bool, debug: array<string, mixed>}
     */
    public function relations_validate(array $data, ?array $existing_row = null): array {
        $ids   = $this->resolve_relation_ids($data, $existing_row);
        $debug = [
            'template_id'     => $ids['template_id'],
            'service_id'      => $ids['service_id'],
            'client_id'       => $ids['client_id'],
            'current_user_id' => get_current_user_id(),
            'failed_at'       => null,
        ];

        if ($ids['client_id'] > 0) {
            $client_row = (new Eko_Sampa_Client())->get($ids['client_id']);
            $debug['client_visible'] = is_array($client_row);
            if (! is_array($client_row)) {
                $debug['failed_at'] = 'client_not_visible';

                $this->last_relations_check = ['ok' => false, 'debug' => $debug];

                return $this->last_relations_check;
            }
        }

        $template_row = null;
        if ($ids['template_id'] > 0) {
            $template_model = new Eko_Sampa_Template();
            $template_row   = $template_model->get($ids['template_id']);
            $debug['template_exists']  = is_array($template_model->get_row_by_id($ids['template_id']));
            $debug['template_visible'] = is_array($template_row);
            $debug['template_user_id'] = is_array($template_row) ? absint((int) ( $template_row['user_id'] ?? 0 )) : null;
            $debug['template_service_id'] = is_array($template_row)
                ? $this->template_service_id_from_row($template_row)
                : null;

            if (! is_array($template_row)) {
                $debug['failed_at'] = 'template_not_visible';

                $this->last_relations_check = ['ok' => false, 'debug' => $debug];

                return $this->last_relations_check;
            }

            $tpl_service = $this->template_service_id_from_row($template_row);
            if ($tpl_service > 0) {
                $ids['service_id']      = $tpl_service;
                $debug['service_id']    = $tpl_service;
                $data['service_id']     = $tpl_service;
            }
        }

        if ($ids['template_id'] > 0 && $ids['service_id'] <= 0) {
            $debug['failed_at'] = 'service_missing_for_template';

            $this->last_relations_check = ['ok' => false, 'debug' => $debug];

            return $this->last_relations_check;
        }

        if ($ids['service_id'] > 0) {
            $service_model = new Eko_Sampa_Service();
            $debug['service_exists']            = ( new Eko_Sampa_Database() )->row_exists('eko_sampa_services', $ids['service_id']);
            $debug['service_lookup_table']      = $GLOBALS['wpdb']->prefix . 'eko_sampa_services';
            $debug['service_visible_in_scope']  = is_array($service_model->get($ids['service_id']));
            $debug['service_visible_for_order'] = $this->service_visible_for_order(
                $ids['service_id'],
                $ids['template_id'],
                is_array($template_row) ? $template_row : null
            );

            if (! $debug['service_visible_for_order']) {
                $debug['failed_at'] = 'service_not_visible_for_order';

                $this->last_relations_check = ['ok' => false, 'debug' => $debug];

                return $this->last_relations_check;
            }
        }

        if (is_array($template_row)) {
            $tpl_client = absint((int) ( $template_row['client_id'] ?? 0 ));
            if ($tpl_client > 0 && $ids['client_id'] > 0 && $ids['client_id'] !== $tpl_client) {
                $debug['template_client_id'] = $tpl_client;
                $debug['failed_at']          = 'template_client_mismatch';

                $this->last_relations_check = ['ok' => false, 'debug' => $debug];

                return $this->last_relations_check;
            }
        }

        $this->last_relations_check = ['ok' => true, 'debug' => $debug];

        return $this->last_relations_check;
    }

    /**
     * Whether referenced client/service/template rows exist and are visible for this actor.
     *
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $existing_row
     */
    public function relations_visible(array $data, ?array $existing_row): bool {
        return $this->relations_validate($data, $existing_row)['ok'];
    }

    /**
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $existing_row
     *
     * @return array{client_id: int, service_id: int, template_id: int}
     */
    private function resolve_relation_ids(array $data, ?array $existing_row): array {
        $keys = ['client_id', 'service_id', 'template_id'];
        $ids  = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $ids[ $key ] = absint((int) $data[ $key ]);
            } elseif (is_array($existing_row)) {
                $ids[ $key ] = (int) ( $existing_row[ $key ] ?? 0 );
            } else {
                $ids[ $key ] = 0;
            }
        }

        return $ids;
    }

    /**
     * Service readable by actor, or linked/existing when creating from an owned template.
     *
     * @param array<string, mixed>|null $template_row Preloaded visible template (avoids double query).
     */
    private function service_visible_for_order(int $service_id, int $template_id, ?array $template_row = null): bool {
        if ($service_id <= 0) {
            return false;
        }

        $service_model = new Eko_Sampa_Service();
        if (is_array($service_model->get($service_id))) {
            return true;
        }

        if ($template_id <= 0) {
            return false;
        }

        if ($template_row === null) {
            $template_row = (new Eko_Sampa_Template())->get($template_id);
        }

        if (! is_array($template_row)) {
            return false;
        }

        if (is_array($service_model->get_row_by_id($service_id))) {
            return true;
        }

        return ( new Eko_Sampa_Database() )->row_exists('eko_sampa_services', $service_id);
    }

    /**
     * @param array<string, mixed> $template_row
     */
    private function template_service_id_from_row(array $template_row): int {
        if (array_key_exists('service_id', $template_row)) {
            return absint((int) $template_row['service_id']);
        }

        if (array_key_exists('servico_id', $template_row)) {
            return absint((int) $template_row['servico_id']);
        }

        return 0;
    }

    /**
     * Flat context for template preview/print: order id, client columns, dynamic_data slugs.
     *
     * `dynamic_data_json` may be a JSON string (DB) or an associative array (REST draft).
     *
     * @param array<string, mixed> $order
     *
     * @return array<string, string>
     */
    public static function template_render_context(array $order): array {
        $ctx = [
            'order_id' => (string) ($order['id'] ?? ''),
        ];

        $cid = (int) ($order['client_id'] ?? 0);
        if ($cid > 0) {
            $client = (new Eko_Sampa_Client())->get($cid);
            if (is_array($client)) {
                foreach (['nome', 'email', 'telefone', 'documento', 'cidade', 'estado'] as $k) {
                    $ctx[ $k ]             = (string) ($client[ $k ] ?? '');
                    $ctx[ 'client_' . $k ] = (string) ($client[ $k ] ?? '');
                }
            }
        }

        $raw     = $order['dynamic_data_json'] ?? null;
        $decoded = null;
        if (is_array($raw)) {
            $decoded = $raw;
        } elseif (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (JSON_ERROR_NONE !== json_last_error() || ! is_array($decoded)) {
                $decoded = null;
            }
        }

        if (is_array($decoded)) {
            foreach ($decoded as $k => $v) {
                $key = strtolower(sanitize_title((string) $k));
                if ($key === '') {
                    continue;
                }
                $ctx[ $key ] = is_scalar($v)
                    ? (string) $v
                    : (wp_json_encode($v) ?: '');
            }
        }

        return $ctx;
    }

    /**
     * Immutable schema snapshot for the service field definitions at order creation time.
     *
     * @return non-empty-string|null
     */
    private function build_service_fields_snapshot_json(int $service_id): ?string {
        if ($service_id <= 0) {
            return null;
        }

        $field_model = new Eko_Sampa_Service_Field();
        $rows        = $field_model->list_for_service($service_id, ['limit' => 500, 'offset' => 0]);
        $fields      = [];
        foreach ($rows as $r) {
            if (! is_array($r)) {
                continue;
            }
            $opt_raw = $r['options_json'] ?? null;
            $opts    = null;
            if (is_string($opt_raw) && $opt_raw !== '') {
                $dec = json_decode($opt_raw, true);
                $opts = JSON_ERROR_NONE === json_last_error() && is_array($dec) ? $dec : null;
            }

            $rules_raw = $r['validation_rules_json'] ?? null;
            $rules     = null;
            if (is_string($rules_raw) && $rules_raw !== '') {
                $dec2 = json_decode($rules_raw, true);
                $rules = JSON_ERROR_NONE === json_last_error() && is_array($dec2) ? $dec2 : null;
            }

            $fields[] = [
                'id'                 => (int) ($r['id'] ?? 0),
                'label'              => (string) ($r['label'] ?? ''),
                'slug'               => (string) ($r['slug'] ?? ''),
                'type'               => (string) ($r['type'] ?? 'text'),
                'required'           => (int) ($r['required'] ?? 0),
                'sort_order'         => (int) ($r['sort_order'] ?? 0),
                'default_value'      => (string) ($r['default_value'] ?? ''),
                'placeholder'        => (string) ($r['placeholder'] ?? ''),
                'show_in_template'   => (int) ($r['show_in_template'] ?? 1),
                'options'            => $opts,
                'validation_rules'   => $rules,
            ];
        }

        $payload = [
            'version'     => 1,
            'captured_at' => gmdate('c'),
            'service_id'  => $service_id,
            'fields'      => $fields,
        ];

        $json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);

        return is_string($json) && $json !== '' ? $json : null;
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
     * Flat map: string keys, scalar values only; caps size for MVP stability.
     *
     * @return array<string, string>|false
     */
    private function normalize_dynamic_data_array(mixed $value): array|false {
        if (! is_array($value)) {
            return false;
        }

        // JSON `{}` decodes to `[]` in PHP, which is a list — treat as empty map (valid).
        if ($value === []) {
            return [];
        }

        if (function_exists('array_is_list') && array_is_list($value)) {
            return false;
        }

        $out = [];
        $i   = 0;

        foreach ($value as $k => $v) {
            if (++$i > 120) {
                return false;
            }

            $key = strtolower(sanitize_title((string) $k));
            if ($key === '') {
                continue;
            }

            if (is_array($v) || is_object($v)) {
                return false;
            }

            if ($v === null) {
                $out[ $key ] = '';

                continue;
            }

            if (is_bool($v)) {
                $out[ $key ] = $v ? '1' : '0';

                continue;
            }

            $s = (string) $v;
            if (strlen($s) > 8000) {
                return false;
            }

            $out[ $key ] = strlen($s) > 240
                ? sanitize_textarea_field($s)
                : sanitize_text_field($s);
        }

        return $out;
    }

    /**
     * @return string|null|string false on invalid.
     */
    private function normalize_json(mixed $value): string|false|null {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            $flat = $this->normalize_dynamic_data_array($value);
            if (false === $flat) {
                return false;
            }

            return wp_json_encode($flat, JSON_UNESCAPED_UNICODE) ?: false;
        }

        if (is_string($value)) {
            $decoded = json_decode(wp_unslash($value), true);
            if (JSON_ERROR_NONE !== json_last_error()) {
                return false;
            }

            if (is_array($decoded)) {
                $flat = $this->normalize_dynamic_data_array($decoded);
                if (false === $flat) {
                    return false;
                }

                return wp_json_encode($flat, JSON_UNESCAPED_UNICODE) ?: false;
            }

            return false;
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
            'user_id'                        => '%d',
            'client_id'                      => '%d',
            'service_id'                     => '%d',
            'template_id'                    => '%d',
            'woo_order_id'                   => '%d',
            'status'                         => '%s',
            'dynamic_data_json'              => '%s',
            'service_fields_snapshot_json'   => '%s',
            'print_ready'                    => '%d',
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
