<?php
/**
 * Structural drift analysis for `wp_eko_sampa_templates` (schema integrity).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Eko_Sampa_Template_Schema_Diagnostics {

    /**
     * @return array<string, mixed>
     */
    public static function analyze(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'eko_sampa_templates';
        $schema = Eko_Sampa_Template_Insert_Diagnostics::load_schema($table);

        $canonical = Eko_Sampa_Template_Schema_Contract::canonical_set();
        $legacy    = Eko_Sampa_Template_Schema_Contract::legacy_set();
        $bridged   = array_flip(Eko_Sampa_Template_Legacy_Row_Bridge::bridged_legacy_keys());

        $db_lower          = [];
        $canonical_present = [];
        $legacy_present    = [];
        $required_runtime  = [];
        $blocking_columns  = [];

        foreach ($schema['columns'] as $meta) {
            if (! isset($meta['Field']) || ! is_string($meta['Field'])) {
                continue;
            }
            $f  = $meta['Field'];
            $lk = strtolower($f);
            $db_lower[] = $lk;

            if (isset($canonical[ $lk ])) {
                $canonical_present[] = $f;
            }
            if (isset($legacy[ $lk ])) {
                $legacy_present[] = $f;
            }

            $null    = strtoupper((string) ( $meta['Null'] ?? 'YES' ));
            $default = $meta['Default'] ?? null;
            $extra   = strtolower((string) ( $meta['Extra'] ?? '' ));

            if ($null === 'NO' && $lk !== 'id' && ! str_contains($extra, 'auto_increment')) {
                $required_runtime[] = $f;
            }

            if ($null === 'NO' && $default === null && $extra === '' && $lk !== 'id') {
                $blocking_columns[] = [
                    'column'    => $f,
                    'legacy'    => isset($legacy[ $lk ]),
                    'canonical' => isset($canonical[ $lk ]),
                    'bridged'   => isset($bridged[ $lk ]),
                ];
            }
        }

        $db_lower_flip = array_fill_keys($db_lower, true);

        $missing_canonical = [];
        foreach (array_keys($canonical) as $c) {
            if ($c === 'id') {
                continue;
            }
            if (! isset($db_lower_flip[ $c ])) {
                $missing_canonical[] = $c;
            }
        }

        $orphan_legacy = [];
        foreach ($db_lower as $lk) {
            if (isset($legacy[ $lk ]) && ! isset($canonical[ $lk ])) {
                $orphan_legacy[] = $lk;
            }
        }

        $nullable_no_default = [];
        foreach ($schema['columns'] as $meta) {
            if (! isset($meta['Field'])) {
                continue;
            }
            $null    = strtoupper((string) ( $meta['Null'] ?? 'YES' ));
            $default = $meta['Default'] ?? null;
            $extra   = strtolower((string) ( $meta['Extra'] ?? '' ));
            if ($null === 'YES' && $default === null && $extra === '') {
                $nullable_no_default[] = (string) $meta['Field'];
            }
        }

        $exists = (bool) ( $schema['table_exists'] ?? false );

        $drift = self::compute_drift_score(
            count($missing_canonical),
            count($orphan_legacy),
            count($blocking_columns),
            $exists
        );

        $migration_needed = $missing_canonical !== [] || $blocking_columns !== [];
        $safe_to_repair   = $exists;

        $actionable = [];
        if ($missing_canonical !== []) {
            $actionable[] = 'Add missing canonical columns (dbDelta / plugin upgrade): ' . implode(', ', $missing_canonical);
        }
        foreach ($blocking_columns as $b) {
            $c = (string) ( $b['column'] ?? '' );
            if ($c === '') {
                continue;
            }
            if (! empty($b['bridged'])) {
                $actionable[] = 'INSERT bridge fills `' . $c . '` until ALTER adds DEFAULT (see Template Legacy Row Bridge policy).';
            } elseif (! empty($b['legacy'])) {
                $actionable[] = 'ALTER `' . $c . '` to add DEFAULT or NULL, or extend bridge policy (legacy NOT NULL, not bridged).';
            } else {
                $actionable[] = 'Resolve NOT NULL without DEFAULT on `' . $c . '` (non-legacy blocking column).';
            }
        }
        $actionable[] = 'Run DB migration 1.0.6+ (relax legacy template NOT NULL defaults) from Diagnostics when ready.';

        return [
            'table'                     => $table,
            'table_exists'              => $exists,
            'canonical_columns'         => $canonical_present,
            'legacy_columns'            => $legacy_present,
            'required_runtime_columns'  => $required_runtime,
            'blocking_columns'          => $blocking_columns,
            'nullable_without_default'  => $nullable_no_default,
            'unused_columns'            => array_values(array_unique($orphan_legacy)),
            'missing_canonical_columns' => $missing_canonical,
            'orphan_legacy_columns'     => array_values(array_unique($orphan_legacy)),
            'schema_drift_score'        => $drift,
            'migration_needed'          => $migration_needed,
            'safe_to_repair'            => $safe_to_repair,
            'actionable_repairs'        => array_values(array_unique($actionable)),
            'columns_meta'              => $schema['columns'],
        ];
    }

    private static function compute_drift_score(int $missing_canonical, int $orphan_legacy, int $blocking, bool $exists): int {
        if (! $exists) {
            return 0;
        }
        $score = 100;
        $score -= min(40, $missing_canonical * 10);
        $score -= min(30, $orphan_legacy * 3);
        $score -= min(40, $blocking * 5);

        return max(0, $score);
    }
}
