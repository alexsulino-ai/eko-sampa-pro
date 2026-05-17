<?php
/**
 * Deep duplicate readiness — read-only, no mutations.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Structured diagnostics for {@see Eko_Sampa_Template::duplicate()} / REST inspect.
 */
final class Eko_Sampa_Template_Duplicate_Diagnostics {

    /**
     * @return array<string, mixed>
     */
    public static function deep(int $template_id): array {
        $tpl  = new Eko_Sampa_Template();
        $byId = $template_id > 0 ? $tpl->get_row_by_id($template_id) : null;
        $vis  = $template_id > 0 ? $tpl->get($template_id) : null;

        $warnings          = [];
        $failure_candidates = [];
        $legacy_fields      = [];

        $template_exists    = is_array($byId);
        $visible_to_actor   = is_array($vis);

        if ($template_exists && is_array($byId)) {
            foreach (array_keys($byId) as $k) {
                $lk = strtolower((string) $k);
                if (in_array($lk, ['name', 'description', 'category', 'servico_id', 'cliente_id'], true)) {
                    $legacy_fields[] = (string) $k;
                }
            }
        }

        $payload = $visible_to_actor ? $tpl->build_duplicate_create_data($template_id) : null;

        $try = null;
        if (is_array($payload)) {
            $try = $tpl->try_create($payload, true);
        }

        $thumb_inspect = $template_id > 0
            ? Eko_Sampa_Template_Thumbnail::inspect_duplicate_readiness($template_id)
            : [];

        $schema = self::schema_snapshot();

        if (! $template_exists) {
            $failure_candidates[] = 'template_row_missing';
        } elseif (! $visible_to_actor) {
            $failure_candidates[] = 'ownership_or_visibility';
        }

        if ($payload === null) {
            $failure_candidates[] = 'cannot_build_duplicate_payload';
        }

        if (is_array($try)) {
            if (! empty($try['nome_truncated'])) {
                $warnings[] = 'duplicate_title_truncated_for_varchar255';
            }
            if (! empty($try['failure_reason'])) {
                $failure_candidates[] = (string) $try['failure_reason'];
            }
            $idg = $try['insert_diagnostics'] ?? null;
            if (is_array($idg) && ! empty($idg['blocking'])) {
                $failure_candidates[] = 'preinsert:' . (string) ( $idg['failure_reason'] ?? 'blocked' );
            }
        }

        if (empty($schema['consistent']) && ! empty($schema['missing_expected_columns'])) {
            foreach ($schema['missing_expected_columns'] as $m) {
                $failure_candidates[] = 'schema_missing_column:' . $m;
            }
        }

        $json_bytes = 0;
        if (is_array($payload) && isset($payload['json_data']) && is_string($payload['json_data'])) {
            $json_bytes = strlen($payload['json_data']);
        }

        $storage_ready = self::upload_storage_ready();

        $payload_preview = null;
        if (is_array($payload)) {
            $p2 = $payload;
            unset($p2['_duplicate_nome_truncated']);
            $payload_preview = self::sanitize_payload_for_api($p2);
        }

        return [
            'template_exists'           => $template_exists,
            'visible_to_actor'          => $visible_to_actor,
            'insert_payload_valid'      => is_array($try) && ! empty($try['insert_payload_valid']),
            'storage_ready'             => $storage_ready,
            'thumbnail_copy_ready'      => ! empty($thumb_inspect['thumbnail_readable']),
            'preview_image_valid'       => ! empty($thumb_inspect['preview_image_public_ok']),
            'json_valid'                => is_array($try) && ! empty($try['json_valid']),
            'schema_consistent'         => ! empty($schema['consistent']),
            'schema_missing_expected_columns' => $schema['missing_expected_columns'] ?? [],
            'legacy_fields_detected'    => $legacy_fields,
            'db_columns'                => $schema['columns'],
            'db_constraints'            => [],
            'warnings'                  => $warnings,
            'failure_candidates'        => array_values(array_unique(array_filter($failure_candidates))),
            'duplicate_payload_preview' => $payload_preview,
            'insert_row_keys'           => is_array($try) ? ( $try['insert_row_keys'] ?? [] ) : [],
            'row_after_filter_empty'    => is_array($try) ? ! empty($try['empty_row_after_filter']) : false,
            'try_create_dry_run'        => is_array($try) ? ( $try['dry_run'] ?? false ) : false,
            'try_create'                => self::sanitize_try_for_api($try),
            'insert_diagnostics'        => is_array($try) ? ( $try['insert_diagnostics'] ?? null ) : null,
            'thumbnail_inspect'         => $thumb_inspect,
            'json_byte_length'          => $json_bytes,
            'template_schema_integrity' => Eko_Sampa_Template_Schema_Diagnostics::analyze(),
        ];
    }

    /**
     * Full bundle for {@see Eko_Sampa_Rest_Api::route_templates_duplicate_diagnostics()}.
     *
     * @return array<string, mixed>
     */
    public static function duplicate_diagnostics_bundle(int $template_id): array {
        $tpl  = new Eko_Sampa_Template();
        $row  = $tpl->get($template_id);
        $deep = self::deep($template_id);

        $final_row_meta = null;
        $payload        = is_array($row) ? $tpl->build_duplicate_create_data($template_id) : null;
        if (is_array($payload)) {
            $prep = $tpl->prepare_create_row($payload);
            if (! empty($prep['ready']) && isset($prep['row']) && is_array($prep['row'])) {
                $r = $prep['row'];
                $final_row_meta = [
                    'insert_row_keys' => array_keys($r),
                    'nome_byte_length' => isset($r['nome']) && is_string($r['nome']) ? strlen($r['nome']) : 0,
                    'json_byte_length' => isset($r['json_data']) && is_string($r['json_data']) ? strlen($r['json_data']) : 0,
                    'preview_image_length' => isset($r['preview_image']) && is_string($r['preview_image']) ? strlen((string) $r['preview_image']) : 0,
                    'nome_preview' => isset($r['nome']) && is_string($r['nome'])
                        ? ( function_exists('mb_substr') ? mb_substr($r['nome'], 0, 120, 'UTF-8') : substr($r['nome'], 0, 120) )
                        : '',
                    'schema_integrity_bridge' => (array) ( $prep['schema_integrity_bridge'] ?? [] ),
                ];
            } else {
                $final_row_meta = [
                    'prepare_failed' => true,
                    'failure_reason' => (string) ( $prep['failure_reason'] ?? '' ),
                ];
            }
        }

        $drift = is_array($row) ? Eko_Sampa_Visual_Drift_Diagnostics::analyze_template_row($row) : [];

        $readiness = 100;
        if (empty($deep['insert_payload_valid'])) {
            $readiness -= 40;
        }
        if (empty($deep['schema_consistent'])) {
            $readiness -= 25;
        }
        if (! empty($drift['drift_score'])) {
            $readiness -= min(30, (int) $drift['drift_score'] / 4);
        }
        if (is_array($deep['insert_diagnostics']) && isset($deep['insert_diagnostics']['readiness_score'])) {
            $readiness = min($readiness, (int) $deep['insert_diagnostics']['readiness_score']);
        }
        $readiness = max(0, min(100, $readiness));

        $repairs = [];
        if (! empty($deep['failure_candidates'])) {
            $repairs[] = 'Resolve failure_candidates before duplicating: ' . implode('; ', $deep['failure_candidates']);
        }
        if (! empty($deep['insert_diagnostics']['actionable_repairs'])) {
            $repairs = array_merge($repairs, (array) $deep['insert_diagnostics']['actionable_repairs']);
        }
        if (! empty($drift['suspected_root_causes'])) {
            $repairs[] = 'Visual drift: ' . implode('; ', (array) $drift['suspected_root_causes']);
        }

        return [
            'template_id'            => $template_id,
            'readiness_score'        => $readiness,
            'duplicate_inspect_deep' => $deep,
            'visual_drift'           => $drift,
            'final_row_meta'         => $final_row_meta,
            'actionable_repairs'     => array_values(array_unique(array_filter($repairs))),
            'template_schema_integrity' => is_array($deep['template_schema_integrity'] ?? null)
                ? $deep['template_schema_integrity']
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function schema_snapshot(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'eko_sampa_templates';
        $safe  = preg_replace('/[^a-z0-9_]/i', '', $table);
        $cols  = [];
        if ($safe === '' || $safe !== $table) {
            return [
                'columns'                    => [],
                'consistent'                 => false,
                'missing_expected_columns'   => [],
                'optional_columns_present'   => [],
            ];
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results('SHOW COLUMNS FROM `' . $safe . '`', ARRAY_A);
        if (! is_array($rows)) {
            return [
                'columns'                    => [],
                'consistent'                 => false,
                'missing_expected_columns'   => [],
                'optional_columns_present'   => [],
            ];
        }
        $names = [];
        foreach ($rows as $r) {
            if (isset($r['Field']) && is_string($r['Field'])) {
                $names[] = strtolower($r['Field']);
                $cols[]  = [
                    'field'   => (string) $r['Field'],
                    'type'    => isset($r['Type']) ? (string) $r['Type'] : '',
                    'null'    => isset($r['Null']) ? (string) $r['Null'] : '',
                    'default' => array_key_exists('Default', $r) ? $r['Default'] : null,
                ];
            }
        }
        $expected = [
            'id', 'user_id', 'client_id', 'product_id', 'service_id', 'nome', 'categoria', 'descricao',
            'width_mm', 'height_mm', 'preview_image', 'json_data', 'created_at', 'updated_at',
        ];
        $optional = ['thumbnail_version', 'thumbnail_visual_hash'];
        $have     = array_flip($names);
        $missing  = [];
        foreach ($expected as $e) {
            if ($e === 'id') {
                continue;
            }
            if (! isset($have[ $e ])) {
                $missing[] = $e;
            }
        }
        $consistent = $missing === [];

        return [
            'columns'    => $cols,
            'consistent' => $consistent,
            'missing_expected_columns' => $missing,
            'optional_columns_present' => array_values(array_filter($optional, static fn(string $c): bool => isset($have[ $c ]))),
        ];
    }

    private static function upload_storage_ready(): bool {
        $d = Eko_Sampa_Storage_Manager::upload_dirs();

        return ! $d['error'] && $d['basedir'] !== '' && $d['basedir'] !== '/';
    }

    /**
     * @param array<string, mixed>|null $payload
     *
     * @return array<string, mixed>|null
     */
    private static function sanitize_payload_for_api(?array $payload): ?array {
        if (! is_array($payload)) {
            return null;
        }
        $out = $payload;
        if (isset($out['json_data']) && is_string($out['json_data'])) {
            $len = strlen($out['json_data']);
            if ($len > 8000) {
                $out['json_data'] = '[truncated ' . $len . ' bytes]';
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed>|null $try
     *
     * @return array<string, mixed>|null
     */
    public static function sanitize_try_for_api(?array $try): ?array {
        if (! is_array($try)) {
            return null;
        }
        $out = $try;
        unset($out['insert_row_values_redacted']);
        if (! empty($out['wpdb_last_query']) && is_string($out['wpdb_last_query'])) {
            $out['wpdb_last_query'] = self::redact_sql_literals($out['wpdb_last_query']);
        }

        return $out;
    }

    private static function redact_sql_literals(string $sql): string {
        $sql = preg_replace("/'(?:\\\\.|[^'\\\\])*'/", '?', $sql) ?? $sql;
        $sql = preg_replace('/"(?:\\\\.|[^"\\\\])*"/', '?', $sql) ?? $sql;

        return strlen($sql) > 2000 ? substr($sql, 0, 2000) . '…[truncated]' : $sql;
    }
}
