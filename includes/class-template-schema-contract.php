<?php
/**
 * Canonical vs legacy column contract for `wp_eko_sampa_templates` (integrity, not ad-hoc fixes).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Single source of truth for which columns belong to the modern domain model vs English legacy drift.
 */
final class Eko_Sampa_Template_Schema_Contract {

    /**
     * Columns the PHP model / REST create & duplicate payloads are designed around (Portuguese + FKs).
     *
     * @var list<string>
     */
    public const CANONICAL_COLUMNS = [
        'id',
        'user_id',
        'client_id',
        'product_id',
        'service_id',
        'nome',
        'categoria',
        'descricao',
        'width_mm',
        'height_mm',
        'preview_image',
        'json_data',
        'thumbnail_version',
        'thumbnail_visual_hash',
        'created_at',
        'updated_at',
    ];

    /**
     * Historical English / canvas columns that may still exist on hybrid installs. Not part of the domain contract;
     * {@see Eko_Sampa_Template_Legacy_Row_Bridge} and DB repair align them without dropping data.
     *
     * @var list<string>
     */
    public const LEGACY_ENGLISH_COLUMNS = [
        'title',
        'content',
        'width',
        'height',
        'background_color',
        'thumbnail',
    ];

    /**
     * @return array<string, true>
     */
    public static function canonical_set(): array {
        $o = [];
        foreach (self::CANONICAL_COLUMNS as $c) {
            $o[ strtolower($c) ] = true;
        }

        return $o;
    }

    /**
     * @return array<string, true>
     */
    public static function legacy_set(): array {
        $o = [];
        foreach (self::LEGACY_ENGLISH_COLUMNS as $c) {
            $o[ strtolower($c) ] = true;
        }

        return $o;
    }
}
