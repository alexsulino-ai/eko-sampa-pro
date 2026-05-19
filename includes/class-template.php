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

    /** MySQL `varchar(255)` on `nome` — duplicate suffix must fit (UTF-8 codepoints). */
    public const NOME_MAX_CHARS = 255;

    /** Layout documents can be large; MySQL JSON / deep trees need headroom beyond PHP default (512). */
    private const JSON_DECODE_MAX_DEPTH = 8192;

    /** Set when {@see normalize_json()} returns false (for REST / diagnostics). */
    private string $json_normalize_last_error = '';

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
        $r = $this->try_create($data, false);

        return ! empty($r['success']) ? (int) ( $r['id'] ?? 0 ) : false;
    }

    /**
     * Physical DB table name (insert diagnostics / logging).
     */
    public function insert_table_name(): string {
        return $this->table();
    }

    /**
     * Build the final INSERT row after sanitize, JSON normalisation, ownership, and column filter.
     *
     * @param array<string, mixed> $data Raw create payload (same shape as {@see create()}).
     *
     * @return array<string, mixed> Keys: ready, failure_reason, row, formats, keys_dropped_by_column_filter, …
     */
    public function prepare_create_row(array $data): array {
        $work = $data;
        $nome_trunc_meta = false;
        if (array_key_exists('_duplicate_nome_truncated', $work)) {
            $nome_trunc_meta = (bool) $work['_duplicate_nome_truncated'];
            unset($work['_duplicate_nome_truncated']);
        }

        $out = [
            'ready'                         => false,
            'failure_reason'                => '',
            'json_valid'                    => true,
            'insert_payload_valid'          => false,
            'empty_row_after_filter'        => false,
            'insert_row_keys'               => [],
            'keys_dropped_by_column_filter' => [],
            'nome_truncated'                => $nome_trunc_meta,
            'nome_char_length'              => 0,
            'json_byte_length'              => 0,
            'schema_integrity_bridge'       => [],
            'row'                           => [],
            'formats'                       => [],
        ];

        $guest_session = ! empty($work['_eko_internal_session_insert']);
        if ($guest_session) {
            unset($work['_eko_internal_session_insert']);
        }

        $uid = $this->current_user_id();
        if ($uid <= 0 && ! $guest_session) {
            $out['failure_reason'] = 'no_actor_user';

            return $out;
        }

        if (! $guest_session && $this->is_unrestricted() && isset($work['user_id'])) {
            $forced = absint((int) $work['user_id']);
            if ($forced > 0) {
                $uid = $forced;
            }
        }

        $row = $this->sanitize_row($work, false);
        $this->align_master_catalog_flags_on_insert_row($row);
        if (array_key_exists('json_data', $work)) {
            $this->json_normalize_last_error = '';
            $encoded = $this->normalize_json($work['json_data']);
            if ($encoded === false && $this->json_input_present($work['json_data'])) {
                $out['failure_reason']       = 'json_invalid';
                $out['json_valid']           = false;
                $out['insert_payload_valid'] = false;
                $out['json_decode_error']    = $this->json_normalize_last_error !== ''
                    ? $this->json_normalize_last_error
                    : 'json_decode_failed';

                return $out;
            }
            $row['json_data'] = $encoded;
        }

        if ($guest_session) {
            $row['user_id'] = 0;
        } else {
            $row['user_id'] = $uid;
        }

        $bridge                         = Eko_Sampa_Template_Legacy_Row_Bridge::augment($this->insert_table_name(), $row);
        $row                            = $bridge['row'];
        $out['schema_integrity_bridge'] = $bridge['applied'];

        $keys_before    = array_keys($row);
        $row            = $this->filter_row_to_existing_columns($row);
        $out['keys_dropped_by_column_filter'] = array_values(array_diff($keys_before, array_keys($row)));

        if (isset($row['nome']) && is_string($row['nome'])) {
            $out['nome_char_length'] = function_exists('mb_strlen')
                ? mb_strlen($row['nome'], 'UTF-8')
                : strlen($row['nome']);
            if ($out['nome_char_length'] > self::NOME_MAX_CHARS) {
                $out['failure_reason']       = 'nome_exceeds_varchar255';
                $out['insert_payload_valid'] = false;

                return $out;
            }
        }

        if (isset($row['json_data']) && is_string($row['json_data'])) {
            $out['json_byte_length'] = strlen($row['json_data']);
        }

        if ($row === []) {
            $out['failure_reason']         = 'empty_row_after_filter';
            $out['empty_row_after_filter'] = true;
            $out['insert_payload_valid']   = false;

            return $out;
        }

        $out['insert_payload_valid'] = true;
        $out['insert_row_keys']      = array_keys($row);
        $out['row']                  = $row;
        $out['formats']              = $this->insert_formats($row);
        $out['ready']                = true;

        return $out;
    }

    /**
     * Attempt insert with optional dry-run (no DB write). Used by duplicate diagnostics and REST.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed> Keys: success, dry_run, failure_reason, insert_diagnostics, …
     */
    public function try_create(array $data, bool $dry_run = false): array {
        $out = [
            'success'                       => false,
            'dry_run'                       => $dry_run,
            'failure_reason'                => '',
            'json_valid'                    => true,
            'insert_payload_valid'          => false,
            'empty_row_after_filter'        => false,
            'insert_row_keys'               => [],
            'keys_dropped_by_column_filter' => [],
            'wpdb_error'                    => '',
            'wpdb_last_query'               => '',
            'nome_truncated'                => false,
            'nome_char_length'              => 0,
            'json_byte_length'              => 0,
            'insert_diagnostics'            => null,
            'mysql_errno'                   => 0,
            'sql_state'                     => '',
            'offending_column'              => '',
            'json_decode_error'             => '',
            'schema_integrity_bridge'       => [],
        ];

        $prep = $this->prepare_create_row($data);
        $out['nome_truncated']                = (bool) ( $prep['nome_truncated'] ?? false );
        $out['nome_char_length']              = (int) ( $prep['nome_char_length'] ?? 0 );
        $out['json_byte_length']              = (int) ( $prep['json_byte_length'] ?? 0 );
        $out['json_valid']                    = (bool) ( $prep['json_valid'] ?? true );
        $out['insert_payload_valid']          = (bool) ( $prep['insert_payload_valid'] ?? false );
        $out['empty_row_after_filter']        = (bool) ( $prep['empty_row_after_filter'] ?? false );
        $out['insert_row_keys']               = (array) ( $prep['insert_row_keys'] ?? [] );
        $out['keys_dropped_by_column_filter'] = (array) ( $prep['keys_dropped_by_column_filter'] ?? [] );
        $out['json_decode_error']             = (string) ( $prep['json_decode_error'] ?? '' );
        $out['schema_integrity_bridge']      = (array) ( $prep['schema_integrity_bridge'] ?? [] );

        if (empty($prep['ready'])) {
            $out['failure_reason'] = (string) ( $prep['failure_reason'] ?? 'prepare_create_row_failed' );

            return $out;
        }

        /** @var array<string, mixed> $row */
        $row     = $prep['row'];
        $formats = $prep['formats'];

        $diag = Eko_Sampa_Template_Insert_Diagnostics::simulate_insert_validation(
            $this->insert_table_name(),
            $row,
            $formats,
            $prep
        );
        $out['insert_diagnostics'] = Eko_Sampa_Template_Insert_Diagnostics::sanitize_for_rest($diag);

        if (! empty($diag['blocking'])) {
            $out['failure_reason']     = (string) ( $diag['failure_reason'] !== '' ? $diag['failure_reason'] : 'preinsert_validation_failed' );
            $out['insert_payload_valid'] = false;
            $out['offending_column']     = (string) ( $diag['offending_column'] ?? '' );

            return $out;
        }

        $out['insert_payload_valid'] = true;

        if ($dry_run) {
            $out['success'] = true;

            return $out;
        }

        $inserted = $this->db()->insert($this->table(), $row, $formats);
        if (! $inserted) {
            $out['wpdb_error']      = trim((string) $this->db()->last_error);
            $out['wpdb_last_query'] = (string) $this->db()->last_query;
            $enrich                 = Eko_Sampa_Template_Insert_Diagnostics::enrich_wpdb_insert_failure($out);
            $out['mysql_errno']     = (int) ( $enrich['mysql_errno'] ?? 0 );
            $out['sql_state']       = (string) ( $enrich['sql_state'] ?? '' );
            $mapped                 = Eko_Sampa_Template_Insert_Diagnostics::classify_mysql_error(
                (string) ( $enrich['db_last_error'] ?? '' ),
                $out['mysql_errno']
            );
            $out['failure_reason'] = $out['wpdb_error'] === ''
                ? 'wpdb_insert_returned_false'
                : ( $mapped['failure_reason'] !== 'wpdb_insert_rejected' ? $mapped['failure_reason'] : 'wpdb_insert_rejected' );
            $out['offending_column'] = (string) ( $mapped['offending_column'] ?? '' );
            if ($out['offending_column'] === '' && ! empty($diag['offending_column'])) {
                $out['offending_column'] = (string) $diag['offending_column'];
            }
            $out['insert_diagnostics'] = Eko_Sampa_Template_Insert_Diagnostics::sanitize_for_rest(
                array_merge($diag, [
                    'post_insert_classify' => $mapped,
                    'db_last_error'        => $out['wpdb_error'],
                ])
            );

            return $out;
        }

        $out['success'] = true;
        $out['id']      = (int) $this->db()->insert_id;

        return $out;
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
     * Template visibility for order creation: ownership-scoped rows, or session rows when token validates.
     */
    public function get_for_order_context(int $id, string $session_token = ''): ?array {
        if ($id <= 0) {
            return null;
        }
        $session_token = preg_replace('/[^a-f0-9]/i', '', trim($session_token));
        $row           = $this->get_row_by_id($id);
        if (! is_array($row)) {
            return null;
        }
        if (Eko_Sampa_Template_Derivation::is_session_row($row)) {
            if ($session_token === '') {
                return null;
            }

            return Eko_Sampa_Template_Derivation::request_can_use_session_row($row, $session_token) ? $row : null;
        }

        return $this->get($id);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data, ?string $session_token = null): bool {
        $session_token = $session_token !== null ? trim($session_token) : null;

        if ($id <= 0) {
            return false;
        }

        $session_mode = $session_token !== null && $session_token !== '';
        if ($session_mode) {
            $scope = $this->get_row_by_id($id);
            if (! is_array($scope)
                || ! Eko_Sampa_Template_Derivation::request_can_use_session_row($scope, (string) $session_token)) {
                return false;
            }
        } elseif (! $this->get($id)) {
            return false;
        }

        $row = $this->sanitize_row($data, true);
        $this->align_master_catalog_flags_on_update_row($id, $data, $row);
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

        if ($session_mode) {
            $row['last_activity_at'] = current_time('mysql');
        }

        [, $own] = $session_mode ? ['', []] : $this->ownership_sql();
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

        $colmap = $this->table_column_name_map();
        if (! empty($args['catalog_public_only']) && isset($colmap['template_type'], $colmap['is_public'], $colmap['allow_personalization'])) {
            if (isset($colmap['is_public_catalog'])) {
                $extra = ' AND (is_public_catalog = 1 OR is_public = 1) AND allow_personalization = 1 AND template_type IN (%s, %s)';
            } else {
                $extra = ' AND is_public = 1 AND allow_personalization = 1 AND template_type IN (%s, %s)';
            }
            $own     = [Eko_Sampa_Template_Derivation::TYPE_MASTER, Eko_Sampa_Template_Derivation::TYPE_USER];
        } elseif (isset($colmap['template_type']) && empty($args['include_sessions'])) {
            $extra .= ' AND template_type <> %s';
            $own[] = Eko_Sampa_Template_Derivation::TYPE_SESSION;
        }

        $sql  = 'SELECT * FROM ' . $this->table() . ' WHERE 1=1' . $extra . $search_sql . $cat_sql
            . ' ORDER BY ' . $orderby . ' ' . $order
            . ' LIMIT %d OFFSET %d';
        $prep = $this->prepare($sql, array_merge($own, $search_vals, $cat_vals, [$limit, $offset]));
        $rows = $this->db()->get_results($prep, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Public marketing catalog: master rows only, official catalog flag (fallback: legacy is_public).
     * No ownership scope — intended only for unauthenticated REST after server-side checks.
     *
     * @param array<string, mixed> $args limit, offset, orderby, order, s, categoria, featured_ids, id_order
     *
     * @return array<int, array<string, mixed>>
     */
    public function list_public_master_catalog(array $args = []): array {
        global $wpdb;

        $limit   = $this->sanitize_limit((int) ($args['limit'] ?? 24));
        $offset  = $this->sanitize_offset((int) ($args['offset'] ?? 0));
        $orderby = $this->sanitize_orderby(
            sanitize_key((string) ($args['orderby'] ?? 'updated_at')),
            $this->allowed_orderby(),
            'updated_at'
        );
        $order = $this->sanitize_order((string) ($args['order'] ?? 'DESC'));

        $colmap = $this->table_column_name_map();
        if (! isset($colmap['template_type'])) {
            return [];
        }

        $pub_sql = isset($colmap['is_public_catalog'])
            ? 'is_public_catalog = 1'
            : 'is_public = 1';

        $search_sql = '';
        $search_vals = [];
        if (! empty($args['s'])) {
            $like         = '%' . $this->db()->esc_like(sanitize_text_field((string) $args['s'])) . '%';
            $search_sql   = ' AND (nome LIKE %s OR descricao LIKE %s OR categoria LIKE %s)';
            $search_vals  = [$like, $like, $like];
        }

        $cat_sql = '';
        $cat_vals = [];
        if (! empty($args['categoria'])) {
            $cat_sql = ' AND categoria = %s';
            $cat_vals[] = sanitize_text_field((string) $args['categoria']);
        }

        $feat_sql = '';
        $feat_vals = [];
        if (! empty($args['featured_ids']) && is_array($args['featured_ids'])) {
            $ids = array_values(array_filter(array_map('absint', $args['featured_ids']), static fn (int $v): bool => $v > 0));
            if ($ids !== []) {
                $placeholders = implode(',', array_fill(0, count($ids), '%d'));
                $feat_sql     = " AND id IN ({$placeholders})";
                $feat_vals    = $ids;
            }
        }

        $id_order = [];
        if (! empty($args['id_order']) && is_array($args['id_order'])) {
            $id_order = array_values(array_filter(array_map('absint', $args['id_order']), static fn (int $v): bool => $v > 0));
            if ($id_order !== [] && $feat_sql === '') {
                $placeholders = implode(',', array_fill(0, count($id_order), '%d'));
                $feat_sql     = " AND id IN ({$placeholders})";
                $feat_vals    = $id_order;
            }
        }

        $t    = $this->table();
        $type = Eko_Sampa_Template_Derivation::TYPE_MASTER;
        // Exclude json_data — public catalog must not load full layouts into memory per row.
        $cols = 'id, nome, categoria, descricao, width_mm, height_mm, preview_image, thumbnail_version,'
            . ' thumbnail_visual_hash, thumbnail_capture_source, allow_personalization, template_type,'
            . ' updated_at, created_at';

        $order_sql = ' ORDER BY ' . $orderby . ' ' . $order;
        if ($id_order !== []) {
            $safe = implode(',', array_map('absint', $id_order));
            if ($safe !== '') {
                $order_sql = ' ORDER BY FIELD(id,' . $safe . ') ASC ';
            }
        }

        $sql  = "SELECT {$cols} FROM {$t} WHERE template_type = %s AND allow_personalization = 1 AND {$pub_sql}"
            . $search_sql . $cat_sql . $feat_sql
            . $order_sql
            . ' LIMIT %d OFFSET %d';

        $prep = $this->prepare($sql, array_merge([$type], $search_vals, $cat_vals, $feat_vals, [$limit, $offset]));
        $rows = $this->db()->get_results($prep, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Distinct non-empty categories for public master catalog.
     *
     * @return array<int, string>
     */
    public function list_public_master_categories(): array {
        global $wpdb;

        $colmap = $this->table_column_name_map();
        if (! isset($colmap['template_type'])) {
            return [];
        }
        $pub_sql = isset($colmap['is_public_catalog'])
            ? 'is_public_catalog = 1'
            : 'is_public = 1';

        $t = $this->table();
        $type = Eko_Sampa_Template_Derivation::TYPE_MASTER;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $wpdb->prepare(
            "SELECT DISTINCT categoria FROM {$t} WHERE template_type = %s AND categoria <> '' AND allow_personalization = 1 AND {$pub_sql} ORDER BY categoria ASC LIMIT 200",
            $type
        );
        $col = $wpdb->get_col($sql);
        if (! is_array($col)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $col)));
    }

    /**
     * Build the {@see try_create()} payload for duplicating a template (ownership-scoped read).
     *
     * Truncates `nome` so that name + translated " (Copy)" fits in `varchar(255)` (common insert failure).
     *
     * @return array<string, mixed>|null
     */
    public function build_duplicate_create_data(int $id): ?array {
        $source = $this->get($id);
        if (! is_array($source)) {
            return null;
        }

        $nome   = (string) ($source['nome'] ?? '');
        $suffix = ' (' . __('Copy', 'eko-sampa') . ')';
        $suffixLen = function_exists('mb_strlen') ? mb_strlen($suffix, 'UTF-8') : strlen($suffix);
        $max    = self::NOME_MAX_CHARS - $suffixLen;
        if ($max < 1) {
            $max = 1;
        }
        $nomeLen = function_exists('mb_strlen') ? mb_strlen($nome, 'UTF-8') : strlen($nome);
        $truncated = $nomeLen > $max;
        if ($truncated) {
            $nome = function_exists('mb_substr')
                ? mb_substr($nome, 0, $max, 'UTF-8')
                : substr($nome, 0, $max);
        }

        return [
            'nome'           => $nome . $suffix,
            'categoria'      => (string) ($source['categoria'] ?? ''),
            'descricao'      => (string) ($source['descricao'] ?? ''),
            'client_id'      => (int) ( $source['client_id'] ?? 0 ),
            'product_id'     => (int) ($source['product_id'] ?? 0),
            'service_id'     => (int) ($source['service_id'] ?? 0),
            'width_mm'       => (int) ($source['width_mm'] ?? 0),
            'height_mm'      => (int) ($source['height_mm'] ?? 0),
            'preview_image'  => '',
            'json_data'      => $source['json_data'] ?? null,
            'user_id'        => (int) ($source['user_id'] ?? 0),
            '_duplicate_nome_truncated' => $truncated,
        ];
    }

    /**
     * Clone a template row (same JSON payload; new name suffix).
     */
    public function duplicate(int $id): int|false {
        $payload = $this->build_duplicate_create_data($id);
        if (! is_array($payload)) {
            return false;
        }

        $r = $this->try_create($payload, false);

        return ! empty($r['success']) ? (int) ( $r['id'] ?? 0 ) : false;
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

        if (! $partial || array_key_exists('template_type', $data)) {
            if (array_key_exists('template_type', $data)) {
                $raw = sanitize_key((string) $data['template_type']);
            } elseif (! $partial) {
                $raw = Eko_Sampa_Template_Derivation::TYPE_USER;
            } else {
                $raw = null;
            }
            if ($raw !== null) {
                $out['template_type'] = in_array(
                    $raw,
                    [
                        Eko_Sampa_Template_Derivation::TYPE_USER,
                        Eko_Sampa_Template_Derivation::TYPE_MASTER,
                        Eko_Sampa_Template_Derivation::TYPE_SESSION,
                    ],
                    true
                )
                    ? $raw
                    : Eko_Sampa_Template_Derivation::TYPE_USER;
            }
        }
        if (! $partial || array_key_exists('parent_template_id', $data)) {
            $out['parent_template_id'] = isset($data['parent_template_id']) ? absint((int) $data['parent_template_id']) : 0;
        }
        if (! $partial || array_key_exists('session_token', $data)) {
            $out['session_token'] = isset($data['session_token'])
                ? preg_replace('/[^a-f0-9]/i', '', (string) $data['session_token'])
                : '';
        }
        if (! $partial || array_key_exists('expires_at', $data)) {
            if (array_key_exists('expires_at', $data) && $data['expires_at'] !== null && (string) $data['expires_at'] !== '') {
                $out['expires_at'] = sanitize_text_field((string) $data['expires_at']);
            } elseif (! $partial) {
                $out['expires_at'] = null;
            }
        }
        if (! $partial || array_key_exists('last_activity_at', $data)) {
            if (array_key_exists('last_activity_at', $data) && $data['last_activity_at'] !== null && (string) $data['last_activity_at'] !== '') {
                $out['last_activity_at'] = sanitize_text_field((string) $data['last_activity_at']);
            } elseif (! $partial) {
                $out['last_activity_at'] = null;
            }
        }
        if (! $partial || array_key_exists('saved_from_session_id', $data)) {
            $out['saved_from_session_id'] = isset($data['saved_from_session_id']) ? absint((int) $data['saved_from_session_id']) : 0;
        }
        if (! $partial || array_key_exists('allow_personalization', $data)) {
            if (array_key_exists('allow_personalization', $data)) {
                $out['allow_personalization'] = ! empty($data['allow_personalization']) ? 1 : 0;
            } elseif (! $partial) {
                $out['allow_personalization'] = 1;
            }
        }
        if (! $partial || array_key_exists('is_public', $data)) {
            if (array_key_exists('is_public', $data)) {
                $out['is_public'] = ! empty($data['is_public']) ? 1 : 0;
            } elseif (! $partial) {
                $out['is_public'] = 0;
            }
        }
        if (! $partial || array_key_exists('session_fingerprint', $data)) {
            if (array_key_exists('session_fingerprint', $data)) {
                $out['session_fingerprint'] = preg_replace('/[^a-f0-9]/i', '', (string) $data['session_fingerprint']);
            }
        }
        if (! $partial || array_key_exists('session_lifecycle', $data)) {
            if (array_key_exists('session_lifecycle', $data)) {
                $raw = strtolower(trim(sanitize_key((string) $data['session_lifecycle'])));
                $allow = [
                    '',
                    Eko_Sampa_Template_Derivation::LIFECYCLE_ACTIVE,
                    Eko_Sampa_Template_Derivation::LIFECYCLE_EXPIRED,
                    Eko_Sampa_Template_Derivation::LIFECYCLE_ABANDONED,
                    Eko_Sampa_Template_Derivation::LIFECYCLE_PERSISTED,
                ];
                $out['session_lifecycle'] = in_array($raw, $allow, true) ? $raw : Eko_Sampa_Template_Derivation::LIFECYCLE_ACTIVE;
            }
        }
        if (! $partial || array_key_exists('is_public_catalog', $data)) {
            if (array_key_exists('is_public_catalog', $data)) {
                $out['is_public_catalog'] = ! empty($data['is_public_catalog']) ? 1 : 0;
            } elseif (! $partial) {
                $out['is_public_catalog'] = 0;
            }
        }
        if (! $partial || array_key_exists('is_user_shareable', $data)) {
            if (array_key_exists('is_user_shareable', $data)) {
                $out['is_user_shareable'] = ! empty($data['is_user_shareable']) ? 1 : 0;
            } elseif (! $partial) {
                $out['is_user_shareable'] = 0;
            }
        }
        if (! $partial || array_key_exists('is_marketplace_item', $data)) {
            if (array_key_exists('is_marketplace_item', $data)) {
                $out['is_marketplace_item'] = ! empty($data['is_marketplace_item']) ? 1 : 0;
            } elseif (! $partial) {
                $out['is_marketplace_item'] = 0;
            }
        }

        return $out;
    }

    /**
     * @return string|null Encoded JSON or null for empty; false on invalid input.
     */
    private function normalize_json(mixed $value): string|false|null {
        $this->json_normalize_last_error = '';

        if ($value === null || $value === '') {
            return null;
        }

        // MySQL `JSON` columns (or some drivers) can surface values as objects, not strings.
        if (is_object($value)) {
            $coerced = $this->coerce_object_tree_to_array($value);
            if ($coerced === false) {
                return false;
            }
            $value = $coerced;
        }

        if (is_array($value)) {
            $clean = $this->json_sanitize_numeric_edge_cases($value);
            $enc   = $this->json_encode_canonical($clean);
            if ($enc === false) {
                $this->json_normalize_last_error = 'wp_json_encode_failed';

                return false;
            }

            return $enc;
        }

        if (! is_string($value)) {
            $this->json_normalize_last_error = 'json_data_not_string_or_array';

            return false;
        }

        $decoded = null;
        $candidates = $this->json_string_decode_candidates($value);
        if ($candidates === []) {
            return null;
        }
        foreach ($candidates as $blob) {
            $decoded = $this->json_decode_lenient_assoc($blob);
            if ($decoded !== null) {
                break;
            }
        }

        if ($decoded === null) {
            $this->json_normalize_last_error = $this->json_normalize_last_error !== ''
                ? $this->json_normalize_last_error
                : json_last_error_msg();

            return false;
        }

        $clean = $this->json_sanitize_numeric_edge_cases($decoded);
        $enc   = $this->json_encode_canonical($clean);
        if ($enc === false) {
            $this->json_normalize_last_error = 'wp_json_encode_failed_after_decode';

            return false;
        }

        return $enc;
    }

    /**
     * Strip UTF-8 BOM, Unicode whitespace / controls at edges (NBSP, ZWSP), then ASCII trim.
     */
    private function trim_json_blob(string $s): string {
        $s = preg_replace('/^\xEF\xBB\xBF/', '', $s) ?? $s;
        if (class_exists('Normalizer', false) && function_exists('normalizer_normalize')) {
            $n = normalizer_normalize($s, \Normalizer::FORM_C);
            if (is_string($n)) {
                $s = $n;
            }
        }
        $lead = @preg_replace('/^[\p{Z}\p{Cc}]+/u', '', $s);
        if (is_string($lead)) {
            $s = $lead;
        }
        $trail = @preg_replace('/[\p{Z}\p{Cc}]+$/u', '', $s);
        if (is_string($trail)) {
            $s = $trail;
        }

        return trim($s);
    }

    /**
     * Try DB text first (no wp_unslash — stripslashes can break valid JSON with backslashes), then wp_unslash for legacy slashed rows.
     *
     * @return array<int, string>
     */
    private function json_string_decode_candidates(string $value): array {
        $first = $this->trim_json_blob($value);
        $out     = [];
        if ($first !== '') {
            $out[] = $first;
        }
        $sl = wp_unslash($value);
        if (is_string($sl)) {
            $second = $this->trim_json_blob($sl);
            if ($second !== '' && $second !== $first) {
                $out[] = $second;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return array<string|int, mixed>|false
     */
    private function coerce_object_tree_to_array(object $value): array|false {
        $flags = JSON_UNESCAPED_UNICODE;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        if (defined('JSON_PARTIAL_OUTPUT_ON_ERROR')) {
            $flags |= JSON_PARTIAL_OUTPUT_ON_ERROR;
        }

        $json = wp_json_encode($value, $flags);
        if (false === $json) {
            $this->json_normalize_last_error = 'json_object_coerce_encode_failed';

            return false;
        }

        $dflags = 0;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $dflags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }

        $arr = json_decode($json, true, self::JSON_DECODE_MAX_DEPTH, $dflags);
        if (JSON_ERROR_NONE !== json_last_error() || ! is_array($arr)) {
            $this->json_normalize_last_error = 'json_object_coerce_decode_failed:' . json_last_error_msg();

            return false;
        }

        return $arr;
    }

    /**
     * @param array<string|int, mixed> $data
     *
     * @return array<string|int, mixed>
     */
    private function json_sanitize_numeric_edge_cases(array $data): array {
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[ $k ] = $this->json_sanitize_numeric_edge_cases($v);
                continue;
            }
            if (is_float($v) && ( is_nan($v) || is_infinite($v) )) {
                $data[ $k ] = 0.0;
            }
        }

        return $data;
    }

    /**
     * @param array<string|int, mixed> $data
     */
    private function json_encode_canonical(array $data): string|false {
        $flags = JSON_UNESCAPED_UNICODE;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        if (defined('JSON_PARTIAL_OUTPUT_ON_ERROR')) {
            $flags |= JSON_PARTIAL_OUTPUT_ON_ERROR;
        }

        $s = wp_json_encode($data, $flags);

        return false !== $s ? (string) $s : false;
    }

    /**
     * After a successful {@see json_decode()}, accept an object/array root or unwrap a JSON *string*
     * (double-encoded payloads: the DB value is a JSON string literal whose text is another JSON document).
     *
     * @param mixed $decoded Result of json_decode(..., true) when json_last_error() is JSON_ERROR_NONE.
     *
     * @return array<string|int, mixed>|null
     */
    private function json_coerce_decoded_to_assoc_array(mixed $decoded, int $unwrap_depth): ?array {
        if ($unwrap_depth > 8) {
            $this->json_normalize_last_error = 'json_unwrap_depth_exceeded';

            return null;
        }
        if ($decoded === null) {
            $this->json_normalize_last_error = 'json_literal_null_not_supported';

            return null;
        }
        if (is_array($decoded)) {
            return $decoded;
        }
        if (is_string($decoded)) {
            $inner = $this->trim_json_blob($decoded);
            if ($inner === '' || ($inner[0] !== '{' && $inner[0] !== '[')) {
                $this->json_normalize_last_error = 'json_double_encoded_string_not_document';

                return null;
            }

            return $this->json_decode_lenient_assoc($inner, $unwrap_depth + 1);
        }
        $this->json_normalize_last_error = 'json_root_must_be_object_or_array';

        return null;
    }

    /**
     * @return array<string|int, mixed>|null Null = decode failed.
     */
    private function json_decode_lenient_assoc(string $raw, int $unwrap_depth = 0): ?array {
        if ($unwrap_depth > 8) {
            $this->json_normalize_last_error = 'json_unwrap_depth_exceeded';

            return null;
        }

        $raw = $this->trim_json_blob($raw);
        if ($raw === '') {
            $this->json_normalize_last_error = 'json_empty_after_trim';

            return null;
        }

        $flags = 0;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }

        $depth = self::JSON_DECODE_MAX_DEPTH;
        $try   = static function (string $s, int $f) use ($depth): mixed {
            return json_decode($s, true, $depth, $f);
        };

        $decoded = $try($raw, $flags);
        if (JSON_ERROR_NONE === json_last_error()) {
            return $this->json_coerce_decoded_to_assoc_array($decoded, $unwrap_depth);
        }

        $stripped = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $raw) ?? $raw;
        if ($stripped !== $raw) {
            $decoded = $try($stripped, $flags);
            if (JSON_ERROR_NONE === json_last_error()) {
                return $this->json_coerce_decoded_to_assoc_array($decoded, $unwrap_depth);
            }
        }

        if (function_exists('iconv')) {
            $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $raw);
            if (is_string($clean) && $clean !== '') {
                $decoded = $try($clean, $flags);
                if (JSON_ERROR_NONE === json_last_error()) {
                    return $this->json_coerce_decoded_to_assoc_array($decoded, $unwrap_depth);
                }
            }
        }

        $this->json_normalize_last_error = json_last_error_msg();

        return null;
    }

    private function json_input_present(mixed $value): bool {
        if ($value === null || $value === '') {
            return false;
        }
        if (is_string($value)) {
            return $this->trim_json_blob($value) !== '';
        }

        return true;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<int, string>
     */
    private function insert_formats(array $row): array {
        $map = [
            'user_id'               => '%d',
            'client_id'             => '%d',
            'product_id'            => '%d',
            'service_id'            => '%d',
            'nome'                  => '%s',
            'categoria'             => '%s',
            'descricao'             => '%s',
            'width_mm'              => '%d',
            'height_mm'             => '%d',
            'preview_image'         => '%s',
            'json_data'             => '%s',
            'title'                 => '%s',
            'content'               => '%s',
            'width'                 => '%f',
            'height'                => '%f',
            'background_color'      => '%s',
            'thumbnail'             => '%s',
            'thumbnail_version'     => '%d',
            'thumbnail_visual_hash' => '%s',
            'thumbnail_capture_source' => '%s',
            'template_type'         => '%s',
            'parent_template_id'    => '%d',
            'session_token'         => '%s',
            'expires_at'            => '%s',
            'last_activity_at'      => '%s',
            'saved_from_session_id' => '%d',
            'allow_personalization' => '%d',
            'is_public'             => '%d',
            'session_fingerprint'   => '%s',
            'session_lifecycle'     => '%s',
            'is_public_catalog'     => '%d',
            'is_user_shareable'     => '%d',
            'is_marketplace_item'   => '%d',
            'created_at'            => '%s',
            'updated_at'            => '%s',
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

    /**
     * Fork a public catalog row into an anonymous / unscoped working session (same JSON pipeline).
     *
     * @return array<string, mixed> Same shape as {@see try_create()} (success, id, failure_reason, …).
     */
    public function create_public_session_from_source(int $source_id): array {
        $src = $this->get_row_by_id($source_id);
        if (! is_array($src) || ! Eko_Sampa_Template_Derivation::can_fork_public_session($src)) {
            return [
                'success'        => false,
                'failure_reason' => 'fork_not_allowed',
            ];
        }

        $bounds = Eko_Sampa_Template_Derivation::expires_bounds_for_new_session();
        if (! is_array($bounds)) {
            return [
                'success'        => false,
                'failure_reason' => 'session_bounds_failed',
            ];
        }

        $nome_base = (string) ( $src['nome'] ?? '' );
        $suffix    = ' — ' . __('Working session', 'eko-sampa');
        $nome      = $nome_base . $suffix;
        $max       = self::NOME_MAX_CHARS;
        $len       = function_exists('mb_strlen')
            ? mb_strlen($nome, 'UTF-8')
            : strlen($nome);
        if ($len > $max) {
            $suffix_len = function_exists('mb_strlen')
                ? mb_strlen($suffix, 'UTF-8')
                : strlen($suffix);
            $cut = $max - $suffix_len;
            if ($cut < 1) {
                $cut = 1;
            }
            $nome = ( function_exists('mb_substr')
                ? mb_substr($nome_base, 0, $cut, 'UTF-8')
                : substr($nome_base, 0, $cut) ) . $suffix;
        }

        $sess_tok = Eko_Sampa_Template_Derivation::new_session_token();

        $payload = [
            '_eko_internal_session_insert' => true,
            'user_id'                      => 0,
            'nome'                         => $nome,
            'categoria'                    => (string) ( $src['categoria'] ?? '' ),
            'descricao'                    => (string) ( $src['descricao'] ?? '' ),
            'client_id'                    => 0,
            'product_id'                   => (int) ( $src['product_id'] ?? 0 ),
            'service_id'                   => (int) ( $src['service_id'] ?? 0 ),
            'width_mm'                     => (int) ( $src['width_mm'] ?? 0 ),
            'height_mm'                    => (int) ( $src['height_mm'] ?? 0 ),
            'preview_image'                => '',
            'json_data'                    => $src['json_data'] ?? null,
            'template_type'                => Eko_Sampa_Template_Derivation::TYPE_SESSION,
            'parent_template_id'           => $source_id,
            'session_token'                => $sess_tok,
            'session_fingerprint'          => Eko_Sampa_Template_Derivation::compute_session_fingerprint($sess_tok),
            'session_lifecycle'            => Eko_Sampa_Template_Derivation::LIFECYCLE_ACTIVE,
            'expires_at'                   => (string) ( $bounds['expires_gmt'] ?? '' ),
            'last_activity_at'             => (string) ( $bounds['created_mysql'] ?? '' ),
            'saved_from_session_id'        => 0,
            'allow_personalization'        => 1,
            'is_public'                    => 0,
            'is_public_catalog'            => 0,
            'is_user_shareable'            => 0,
            'is_marketplace_item'          => 0,
        ];

        $try = $this->try_create($payload, false);
        if (empty($try['success'])) {
            return $try;
        }

        $new_id = (int) ( $try['id'] ?? 0 );
        if ($new_id > 0) {
            Eko_Sampa_Template_Thumbnail::copy($source_id, $new_id);
        }

        return $try;
    }

    /**
     * Reuse an existing guest session row for a public master when the client still holds a valid token.
     * Does not create a new fork; validates parent, type, and full session gate.
     *
     * @return array<string, mixed>|null Session row or null.
     */
    public function try_reuse_guest_session_for_public_master(int $master_id, int $session_template_id, string $token): ?array {
        if ($master_id <= 0 || $session_template_id <= 0) {
            return null;
        }
        $token = preg_replace('/[^a-f0-9]/i', '', trim($token));
        if ($token === '') {
            return null;
        }
        $row = $this->get_row_by_id($session_template_id);
        if (! is_array($row)) {
            return null;
        }
        if (! Eko_Sampa_Template_Derivation::is_session_row($row)) {
            return null;
        }
        if ((int) ( $row['user_id'] ?? -1 ) !== 0) {
            return null;
        }
        if ((int) ( $row['parent_template_id'] ?? 0 ) !== $master_id) {
            return null;
        }
        if (! Eko_Sampa_Template_Derivation::request_can_use_session_row($row, $token)) {
            return null;
        }

        return $row;
    }

    /**
     * Count persisted user-owned templates (excludes master/session rows when columns exist).
     */
    public function count_user_saved_templates(int $user_id): int {
        if ($user_id <= 0) {
            return 0;
        }

        $map = $this->table_column_name_map();
        if (! isset($map['template_type'])) {
            return 0;
        }

        $sql = 'SELECT COUNT(*) FROM ' . $this->table()
            . ' WHERE user_id = %d AND (template_type = %s OR template_type = \'\' OR template_type IS NULL)';
        $prep = $this->prepare($sql, [$user_id, Eko_Sampa_Template_Derivation::TYPE_USER]);
        $n    = $this->db()->get_var($prep);

        return is_numeric($n) ? (int) $n : 0;
    }

    /**
     * Public `/eko-sampa/` catalog uses `is_public_catalog` on **master** rows; older UI only sent `is_public`.
     * After full sanitise on create, OR the two flags so both columns match.
     *
     * @param array<string, mixed> $row
     */
    private function align_master_catalog_flags_on_insert_row(array &$row): void {
        if (! isset($this->table_column_name_map()['is_public_catalog'])) {
            return;
        }
        if (($row['template_type'] ?? '') !== Eko_Sampa_Template_Derivation::TYPE_MASTER) {
            return;
        }
        $v = ( (int) ( ! empty($row['is_public_catalog']) ) ) | ( (int) ( ! empty($row['is_public']) ) );
        $row['is_public_catalog'] = $v ? 1 : 0;
        $row['is_public']        = $v ? 1 : 0;
    }

    /**
     * On PATCH, if either public flag is present for a **master** row, write both columns consistently.
     *
     * @param array<string, mixed> $original_patch Raw request body keys.
     * @param array<string, mixed> $row            Sanitised update map (by ref).
     */
    private function align_master_catalog_flags_on_update_row(int $id, array $original_patch, array &$row): void {
        if (! isset($this->table_column_name_map()['is_public_catalog'])) {
            return;
        }
        if (! array_key_exists('is_public', $original_patch) && ! array_key_exists('is_public_catalog', $original_patch)) {
            return;
        }
        $existing = $this->get_row_by_id($id);
        if (! is_array($existing) || ! Eko_Sampa_Template_Derivation::is_master_row($existing)) {
            return;
        }
        $ip = array_key_exists('is_public', $original_patch) ? ( (int) ( ! empty($original_patch['is_public']) ) ) : null;
        $ic = array_key_exists('is_public_catalog', $original_patch) ? ( (int) ( ! empty($original_patch['is_public_catalog']) ) ) : null;
        $v  = 0;
        if ($ic !== null && $ip !== null) {
            $v = ( $ic || $ip ) ? 1 : 0;
        } elseif ($ic !== null) {
            $v = $ic ? 1 : 0;
        } elseif ($ip !== null) {
            $v = $ip ? 1 : 0;
        }
        $row['is_public_catalog'] = $v;
        $row['is_public']         = $v;
    }
}
