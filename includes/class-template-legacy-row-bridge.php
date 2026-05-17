<?php
/**
 * Explicit INSERT bridge for hybrid `eko_sampa_templates` rows (legacy NOT NULL without default).
 *
 * Policy: only fills columns documented in {@see self::POLICY}; unknown required columns remain absent so
 * {@see Eko_Sampa_Template_Insert_Diagnostics} still blocks with a clear integrity signal.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Eko_Sampa_Template_Legacy_Row_Bridge {

    /**
     * machine_key => [ 'source' => human-readable, 'type' => 'copy_nome'|'float_mm'|'hex_default'|'int_zero'|'hash_empty'|'mysql_now' ]
     *
     * @var array<string, array{source: string, type: string}>
     */
    private const POLICY = [
        'title'                 => ['source' => 'nome (canonical display name)', 'type' => 'copy_nome'],
        'width'                 => ['source' => 'width_mm cast to float (legacy hybrid; mm numeric)', 'type' => 'float_mm'],
        'height'                => ['source' => 'height_mm cast to float', 'type' => 'float_mm'],
        'background_color'      => ['source' => 'constant #ffffff until palette is migrated', 'type' => 'hex_default'],
        'thumbnail_version'     => ['source' => 'canonical default 0', 'type' => 'int_zero'],
        'thumbnail_visual_hash' => ['source' => "canonical default ''", 'type' => 'hash_empty'],
        'created_at'            => ['source' => 'WordPress current_time(mysql) at insert', 'type' => 'mysql_now'],
    ];

    /**
     * Lowercase column names that receive explicit INSERT values when the column is NOT NULL without DEFAULT.
     *
     * @return list<string>
     */
    public static function bridged_legacy_keys(): array {
        return array_keys(self::POLICY);
    }

    /**
     * @param array<string, mixed> $row Insert row after sanitize + json + user_id (pre- or post-filter; caller passes table-qualified names).
     *
     * @return array{row: array<string, mixed>, applied: list<array{column: string, policy: string, source: string}>}
     */
    public static function augment(string $table, array $row): array {
        $schema = Eko_Sampa_Template_Insert_Diagnostics::load_schema($table);
        if (empty($schema['table_exists']) || ! is_array($schema['columns'])) {
            return ['row' => $row, 'applied' => []];
        }

        $applied = [];
        foreach ($schema['columns'] as $meta) {
            if (! isset($meta['Field']) || ! is_string($meta['Field'])) {
                continue;
            }
            $field = $meta['Field'];
            $lk    = strtolower($field);
            if ($lk === 'id') {
                continue;
            }

            $null    = strtoupper((string) ( $meta['Null'] ?? 'YES' ));
            $default = $meta['Default'] ?? null;
            $extra   = strtolower((string) ( $meta['Extra'] ?? '' ));

            // Same contract as {@see Eko_Sampa_Template_Insert_Diagnostics::simulate_insert_validation()}.
            if (! ($null === 'NO' && $default === null && $extra === '' && ! array_key_exists($field, $row))) {
                continue;
            }

            $policy = self::POLICY[ $lk ] ?? null;
            if (! is_array($policy)) {
                continue;
            }

            $val = self::value_for($lk, $row, (string) $policy['type']);
            if ($val === null) {
                continue;
            }

            $row[ $field ] = $val;
            $applied[]    = [
                'column' => $field,
                'policy' => (string) $policy['type'],
                'source' => (string) $policy['source'],
            ];
        }

        return ['row' => $row, 'applied' => $applied];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function value_for(string $lk, array $row, string $type): mixed {
        switch ($type) {
            case 'copy_nome':
                $nome = isset($row['nome']) && is_string($row['nome']) ? $row['nome'] : '';

                return function_exists('mb_substr')
                    ? mb_substr($nome, 0, 255, 'UTF-8')
                    : substr($nome, 0, 255);
            case 'float_mm':
                if ($lk === 'width') {
                    return (float) ( $row['width_mm'] ?? 0 );
                }
                if ($lk === 'height') {
                    return (float) ( $row['height_mm'] ?? 0 );
                }

                return 0.0;
            case 'hex_default':
                return '#ffffff';
            case 'int_zero':
                return 0;
            case 'hash_empty':
                return '';
            case 'mysql_now':
                return current_time('mysql');
        }

        return null;
    }
}
