<?php
/**
 * Controlled repair entrypoint for hybrid template table drift (admin / tooling).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Eko_Sampa_Template_Schema_Repair {

    /**
     * @return array{statements: list<string>, backfill_title_sql: string}
     */
    public static function preview(): array {
        return (new Eko_Sampa_Database())->preview_templates_legacy_hybrid_relaxed_defaults();
    }

    /**
     * @return array{ok: bool, statements: list<string>, backfill_rows: int, wpdb_error: string}
     */
    public static function run_relaxed_defaults(int $actor_user_id): array {
        if ($actor_user_id <= 0 || ! current_user_can('manage_options')) {
            return [
                'ok'            => false,
                'statements'    => [],
                'backfill_rows' => 0,
                'wpdb_error'    => 'forbidden',
            ];
        }

        return (new Eko_Sampa_Database())->repair_templates_legacy_hybrid_relaxed_defaults($actor_user_id);
    }
}
