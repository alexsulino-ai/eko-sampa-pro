<?php
/**
 * Schema creation and versioned migrations via dbDelta().
 *
 * Specification: docs/database.md (single source of truth).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Database layer: tables {$wpdb->prefix}eko_sampa_* (e.g. wp_eko_sampa_clients when prefix is wp_).
 *
 * Does not drop tables or contain business logic.
 */
final class Eko_Sampa_Database {

    private const OPTION_DB_VERSION = 'eko_sampa_db_version';

    /**
     * Core tables that must exist for the plugin to function.
     *
     * @return list<string> Table suffix without $wpdb->prefix.
     */
    public function required_table_suffixes(): array {
        return [
            'eko_sampa_clients',
            'eko_sampa_services',
            'eko_sampa_fields',
            'eko_sampa_templates',
            'eko_sampa_layers',
            'eko_sampa_orders',
        ];
    }

    /**
     * Suffixes of required tables that are not present in the database.
     *
     * @return list<string>
     */
    public function missing_required_tables(): array {
        global $wpdb;

        $missing = [];
        foreach ($this->required_table_suffixes() as $suffix) {
            if (! $this->table_exists($wpdb, $suffix)) {
                $missing[] = $suffix;
            }
        }

        return $missing;
    }

    /**
     * Run version migrations and repair any missing core tables (e.g. option says 1.0.3 but a table was never created).
     */
    public function ensure_schema(): void {
        $this->migrate();
        $this->run_schema_alignment();

        $integrity = new Eko_Sampa_Database_Integrity($this);
        $integrity->run(false);

        if ($this->missing_required_tables() === []) {
            return;
        }

        $this->repair_missing_tables();
        $this->run_schema_alignment();
        $integrity->run(false);
    }

    /**
     * Whether a row exists in a core table (existence only; no ownership scope).
     */
    public function row_exists(string $table_suffix, int $id): bool {
        if ($id <= 0) {
            return false;
        }

        $safe = preg_replace('/[^a-z0-9_]/i', '', $table_suffix);
        if ($safe === '' || $safe !== $table_suffix) {
            return false;
        }

        if (! in_array($safe, $this->required_table_suffixes(), true)) {
            return false;
        }

        global $wpdb;
        if (! $this->table_exists($wpdb, $safe)) {
            return false;
        }

        $table = $wpdb->prefix . $safe;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- suffix whitelisted.
        $found = $wpdb->get_var($wpdb->prepare("SELECT id FROM `{$table}` WHERE id = %d LIMIT 1", $id));

        return $found !== null && absint((int) $found) === $id;
    }

    /**
     * Align columns / legacy names on all core tables (idempotent).
     */
    public function run_schema_alignment(): void {
        global $wpdb;

        $this->schema_align_clients($wpdb);
        $this->schema_align_services($wpdb);
        $this->schema_align_fields($wpdb);
        $this->schema_align_templates($wpdb);
        $this->schema_align_layers($wpdb);
        $this->schema_align_orders($wpdb);

        Eko_Sampa_Model_Base::clear_table_column_map_cache();
    }

    /**
     * Re-apply all migration steps via dbDelta / ALTER (safe when tables or columns are missing).
     */
    public function repair_missing_tables(): void {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        global $wpdb;
        $charset_collate = $this->get_table_charset_collate($wpdb);

        foreach ($this->migration_callbacks() as $callback) {
            $callback($charset_collate);
        }

        update_option(self::OPTION_DB_VERSION, EKO_SAMPA_DB_VERSION);

        Eko_Sampa_Model_Base::clear_table_column_map_cache();
    }

    /**
     * Run pending incremental migrations up to EKO_SAMPA_DB_VERSION.
     * Never removes tables automatically.
     */
    public function migrate(): void {
        $installed = (string) get_option(self::OPTION_DB_VERSION, '0');

        if (version_compare($installed, EKO_SAMPA_DB_VERSION, '>=')) {
            if ($this->missing_required_tables() !== []) {
                $this->repair_missing_tables();
            }

            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        global $wpdb;
        $charset_collate = $this->get_table_charset_collate($wpdb);

        foreach ($this->migration_callbacks() as $version => $callback) {
            if (version_compare($installed, $version, '<')
                && version_compare(EKO_SAMPA_DB_VERSION, $version, '>=')) {
                $callback($charset_collate);
            }
        }

        update_option(self::OPTION_DB_VERSION, EKO_SAMPA_DB_VERSION);

        Eko_Sampa_Model_Base::clear_table_column_map_cache();
    }

    /**
     * Ordered migration steps (ascending). Add versions here for future upgrades.
     *
     * @return array<string, callable(string): void>
     */
    private function migration_callbacks(): array {
        return [
            '1.0.0' => [$this, 'migrate_to_1_0_0'],
            '1.0.1' => [$this, 'migrate_to_1_0_1'],
            '1.0.2' => [$this, 'migrate_to_1_0_2'],
            '1.0.3' => [$this, 'migrate_to_1_0_3'],
            '1.0.4' => [$this, 'migrate_to_1_0_4'],
            '1.0.5' => [$this, 'migrate_to_1_0_5'],
            '1.0.6' => [$this, 'migrate_to_1_0_6'],
            '1.0.7' => [$this, 'migrate_to_1_0_7'],
            '1.0.8' => [$this, 'migrate_to_1_0_8'],
            '1.0.9' => [$this, 'migrate_to_1_0_9'],
            '1.0.10' => [$this, 'migrate_to_1_0_10'],
        ];
    }

    /**
     * Add columns introduced after the stored DB version was already bumped (safe on every request).
     */
    private function repair_missing_column_alignments(): void {
        global $wpdb;

        if (! $this->table_exists($wpdb, 'eko_sampa_templates')) {
            return;
        }

        $table = $wpdb->prefix . 'eko_sampa_templates';
        $have  = $this->table_column_set($wpdb, $table);
        if (isset($have['client_id'])) {
            return;
        }

        $this->schema_align_templates($wpdb);
        Eko_Sampa_Model_Base::clear_table_column_map_cache();
    }

    /**
     * Charset/collation: utf8mb4 via WordPress (see database.md).
     */
    private function get_table_charset_collate(wpdb $wpdb): string {
        $collate = $wpdb->get_charset_collate();

        return trim($collate) === ''
            ? 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            : $collate;
    }

    /**
     * Full baseline schema (database.md — all tables).
     */
    private function migrate_to_1_0_0(string $charset_collate): void {
        global $wpdb;

        $suffix    = ' ) ENGINE=InnoDB ' . $charset_collate . ';';
        $clients   = $wpdb->prefix . 'eko_sampa_clients';
        $services  = $wpdb->prefix . 'eko_sampa_services';
        $fields    = $wpdb->prefix . 'eko_sampa_fields';
        $templates = $wpdb->prefix . 'eko_sampa_templates';
        $layers    = $wpdb->prefix . 'eko_sampa_layers';
        $orders    = $wpdb->prefix . 'eko_sampa_orders';

        // clients: id, user_id, nome, email, telefone, documento, cidade, estado, created_at, updated_at
        $sql_clients = "CREATE TABLE {$clients} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			nome varchar(255) NOT NULL DEFAULT '',
			email varchar(255) NOT NULL DEFAULT '',
			telefone varchar(100) NOT NULL DEFAULT '',
			documento varchar(100) NOT NULL DEFAULT '',
			cidade varchar(100) NOT NULL DEFAULT '',
			estado varchar(32) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id (user_id)
		{$suffix}";

        // services: id, user_id, nome, descricao, is_global, created_at, updated_at
        $sql_services = "CREATE TABLE {$services} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			nome varchar(255) NOT NULL DEFAULT '',
			descricao text NULL,
			is_global tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id (user_id)
		{$suffix}";

        // fields: id, service_id, label, slug, type, required, options_json, sort_order
        // type values: text, textarea, number, select, date (stored as string)
        $sql_fields = "CREATE TABLE {$fields} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			service_id bigint(20) unsigned NOT NULL DEFAULT 0,
			label varchar(255) NOT NULL DEFAULT '',
			slug varchar(191) NOT NULL DEFAULT '',
			type varchar(20) NOT NULL DEFAULT 'text',
			required tinyint(1) NOT NULL DEFAULT 0,
			options_json longtext NULL,
			sort_order int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY service_id (service_id)
		{$suffix}";

        // templates: id, user_id, client_id, product_id, service_id, nome, descricao, width_mm, height_mm, preview_image, json_data, created_at, updated_at
        $sql_templates = "CREATE TABLE {$templates} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			client_id bigint(20) unsigned NOT NULL DEFAULT 0,
			product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			service_id bigint(20) unsigned NOT NULL DEFAULT 0,
			nome varchar(255) NOT NULL DEFAULT '',
			descricao text NULL,
			width_mm int(11) NOT NULL DEFAULT 0,
			height_mm int(11) NOT NULL DEFAULT 0,
			preview_image varchar(500) NOT NULL DEFAULT '',
			json_data longtext NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY client_id (client_id),
			KEY product_id (product_id),
			KEY service_id (service_id)
		{$suffix}";

        // layers: id, template_id, layer_type, layer_order, layer_json
        $sql_layers = "CREATE TABLE {$layers} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			template_id bigint(20) unsigned NOT NULL DEFAULT 0,
			layer_type varchar(50) NOT NULL DEFAULT '',
			layer_order int(11) NOT NULL DEFAULT 0,
			layer_json longtext NULL,
			PRIMARY KEY  (id),
			KEY template_id (template_id)
		{$suffix}";

        // orders: id, user_id, client_id, service_id, template_id, woo_order_id, status, dynamic_data_json, print_ready, created_at, updated_at
        // status: pending | in_progress | print_queue | completed (application-enforced)
        $sql_orders = "CREATE TABLE {$orders} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			client_id bigint(20) unsigned NOT NULL DEFAULT 0,
			service_id bigint(20) unsigned NOT NULL DEFAULT 0,
			template_id bigint(20) unsigned NOT NULL DEFAULT 0,
			woo_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(32) NOT NULL DEFAULT 'pending',
			dynamic_data_json longtext NULL,
			print_ready tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY template_id (template_id),
			KEY service_id (service_id),
			KEY status (status)
		{$suffix}";

        foreach (
            [
                $sql_clients,
                $sql_services,
                $sql_fields,
                $sql_templates,
                $sql_layers,
                $sql_orders,
            ] as $sql
        ) {
            dbDelta($sql);
        }
    }

    /**
     * Add template category column (MVP list filters / ownership UX).
     */
    private function migrate_to_1_0_1(string $charset_collate): void {
        unset($charset_collate);

        global $wpdb;
        $table = $wpdb->prefix . 'eko_sampa_templates';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted prefix.
        $has = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'categoria'");
        if ($has) {
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN categoria varchar(191) NOT NULL DEFAULT '' AFTER nome");
    }

    /**
     * Align real DB columns with models / REST (Portuguese domain fields + English FK/field DSL).
     *
     * Fixes installs where tables pre-existed with English names (e.g. `name` vs `nome`) or
     * partial schemas so dbDelta never added missing columns.
     */
    private function migrate_to_1_0_2(string $charset_collate): void {
        unset($charset_collate);

        global $wpdb;

        $this->schema_align_clients($wpdb);
        $this->schema_align_services($wpdb);
        $this->schema_align_fields($wpdb);
        $this->schema_align_templates($wpdb);
        $this->schema_align_layers($wpdb);
        $this->schema_align_orders($wpdb);
    }

    /**
     * @return array<string, true> Lowercase column names.
     */
    private function table_column_set(wpdb $wpdb, string $table): array {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table = prefix + known suffix.
        $rows = $wpdb->get_results("SHOW COLUMNS FROM `{$table}`", ARRAY_A);
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (isset($row['Field']) && is_string($row['Field'])) {
                $out[ strtolower($row['Field']) ] = true;
            }
        }

        return $out;
    }

    private function table_exists(wpdb $wpdb, string $suffix): bool {
        $like = $wpdb->esc_like($wpdb->prefix . $suffix);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like));

        return $found !== null
            && $found !== ''
            && strcasecmp((string) $found, $wpdb->prefix . $suffix) === 0;
    }

    /**
     * Public check for models / REST when migrations have not run yet (avoids fatal SQL errors).
     */
    public function table_exists_for_suffix(string $suffix): bool {
        global $wpdb;

        return $this->table_exists($wpdb, $suffix);
    }

    /**
     * @param array<string, string> $definitions column => MySQL fragment after column name (e.g. "varchar(255) NOT NULL DEFAULT ''")
     */
    private function add_missing_columns(wpdb $wpdb, string $table, array $definitions): void {
        $have = $this->table_column_set($wpdb, $table);
        foreach ($definitions as $col => $ddl) {
            $c = strtolower($col);
            if (isset($have[ $c ])) {
                continue;
            }
            $col_sql = preg_replace('/[^a-z0-9_]/i', '', $col);
            if ($col_sql === '' || strtolower($col_sql) !== $c) {
                continue;
            }
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `{$col_sql}` {$ddl}");
            $have[ $c ] = true;
        }
    }

    /**
     * Copy data from legacy column into target when both exist.
     *
     * @param bool $numeric When true, treats empty as 0 (for bigint FK columns).
     */
    private function copy_column_data_if_both_exist(wpdb $wpdb, string $table, string $target, string $source, bool $numeric = false): void {
        $have = $this->table_column_set($wpdb, $table);
        $t    = strtolower($target);
        $s    = strtolower($source);
        if (! isset($have[ $t ], $have[ $s ])) {
            return;
        }

        $tt = preg_replace('/[^a-z0-9_]/i', '', $target);
        $ss = preg_replace('/[^a-z0-9_]/i', '', $source);
        if ($tt === '' || $ss === '') {
            return;
        }

        if ($numeric) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                "UPDATE `{$table}` SET `{$tt}` = `{$ss}` "
                . "WHERE ( `{$tt}` = 0 OR `{$tt}` IS NULL ) "
                . "AND ( `{$ss}` IS NOT NULL AND `{$ss}` != 0 )"
            );

            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            "UPDATE `{$table}` SET `{$tt}` = `{$ss}` "
            . "WHERE ( `{$tt}` = '' OR `{$tt}` IS NULL ) "
            . "AND ( `{$ss}` IS NOT NULL AND `{$ss}` != '' )"
        );
    }

    private function drop_column_if_exists(wpdb $wpdb, string $table, string $column): void {
        $have = $this->table_column_set($wpdb, $table);
        $c    = strtolower($column);
        if (! isset($have[ $c ])) {
            return;
        }

        $cc = preg_replace('/[^a-z0-9_]/i', '', $column);
        if ($cc === '' || strtolower($cc) !== $c) {
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("ALTER TABLE `{$table}` DROP COLUMN `{$cc}`");
    }

    private function schema_align_clients(wpdb $wpdb): void {
        if (! $this->table_exists($wpdb, 'eko_sampa_clients')) {
            return;
        }

        $table = $wpdb->prefix . 'eko_sampa_clients';
        $this->add_missing_columns(
            $wpdb,
            $table,
            [
                'user_id'     => 'bigint(20) unsigned NOT NULL DEFAULT 0',
                'nome'        => "varchar(255) NOT NULL DEFAULT ''",
                'email'       => "varchar(255) NOT NULL DEFAULT ''",
                'telefone'    => "varchar(100) NOT NULL DEFAULT ''",
                'documento'   => "varchar(100) NOT NULL DEFAULT ''",
                'cidade'      => "varchar(100) NOT NULL DEFAULT ''",
                'estado'      => "varchar(32) NOT NULL DEFAULT ''",
                'created_at'  => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP',
                'updated_at'  => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
            ]
        );

        $pairs = [
            ['nome', 'name'],
            ['nome', 'full_name'],
            ['email', 'email_address'],
            ['telefone', 'phone'],
            ['telefone', 'mobile'],
            ['telefone', 'tel'],
            ['documento', 'document'],
            ['documento', 'cpf'],
            ['documento', 'document_id'],
            ['cidade', 'city'],
            ['estado', 'state'],
            ['estado', 'province'],
        ];
        foreach ($pairs as [$dest, $src]) {
            $this->copy_column_data_if_both_exist($wpdb, $table, $dest, $src);
        }

        foreach (
            [
                'name',
                'full_name',
                'email_address',
                'phone',
                'mobile',
                'tel',
                'document',
                'cpf',
                'document_id',
                'city',
                'state',
                'province',
            ] as $legacy
        ) {
            $this->drop_column_if_exists($wpdb, $table, $legacy);
        }
    }

    private function schema_align_services(wpdb $wpdb): void {
        if (! $this->table_exists($wpdb, 'eko_sampa_services')) {
            return;
        }

        $table = $wpdb->prefix . 'eko_sampa_services';
        $this->add_missing_columns(
            $wpdb,
            $table,
            [
                'user_id'     => 'bigint(20) unsigned NOT NULL DEFAULT 0',
                'nome'        => "varchar(255) NOT NULL DEFAULT ''",
                'descricao'   => 'text NULL',
                'is_global'   => 'tinyint(1) NOT NULL DEFAULT 0',
                'created_at'  => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP',
                'updated_at'  => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
            ]
        );

        $this->copy_column_data_if_both_exist($wpdb, $table, 'nome', 'name');
        $this->copy_column_data_if_both_exist($wpdb, $table, 'descricao', 'description');
        $this->drop_column_if_exists($wpdb, $table, 'name');
        $this->drop_column_if_exists($wpdb, $table, 'description');
    }

    private function schema_align_fields(wpdb $wpdb): void {
        if (! $this->table_exists($wpdb, 'eko_sampa_fields')) {
            return;
        }

        $table = $wpdb->prefix . 'eko_sampa_fields';
        $this->add_missing_columns(
            $wpdb,
            $table,
            [
                'service_id'            => 'bigint(20) unsigned NOT NULL DEFAULT 0',
                'label'                 => "varchar(255) NOT NULL DEFAULT ''",
                'slug'                  => "varchar(191) NOT NULL DEFAULT ''",
                'type'                  => "varchar(20) NOT NULL DEFAULT 'text'",
                'required'              => 'tinyint(1) NOT NULL DEFAULT 0',
                'options_json'          => 'longtext NULL',
                'sort_order'            => 'int(11) NOT NULL DEFAULT 0',
                'default_value'         => "varchar(500) NOT NULL DEFAULT ''",
                'placeholder'           => "varchar(255) NOT NULL DEFAULT ''",
                'show_in_template'      => 'tinyint(1) NOT NULL DEFAULT 1',
                'validation_rules_json' => 'longtext NULL',
            ]
        );

        $this->copy_column_data_if_both_exist($wpdb, $table, 'label', 'rotulo');
        $this->copy_column_data_if_both_exist($wpdb, $table, 'slug', 'identificador');
        $this->drop_column_if_exists($wpdb, $table, 'rotulo');
        $this->drop_column_if_exists($wpdb, $table, 'identificador');
    }

    private function schema_align_templates(wpdb $wpdb): void {
        if (! $this->table_exists($wpdb, 'eko_sampa_templates')) {
            return;
        }

        $table = $wpdb->prefix . 'eko_sampa_templates';
        $this->add_missing_columns(
            $wpdb,
            $table,
            [
                'user_id'       => 'bigint(20) unsigned NOT NULL DEFAULT 0',
                'client_id'     => 'bigint(20) unsigned NOT NULL DEFAULT 0',
                'product_id'    => 'bigint(20) unsigned NOT NULL DEFAULT 0',
                'service_id'    => 'bigint(20) unsigned NOT NULL DEFAULT 0',
                'nome'          => "varchar(255) NOT NULL DEFAULT ''",
                'categoria'     => "varchar(191) NOT NULL DEFAULT ''",
                'descricao'     => 'text NULL',
                'width_mm'      => 'int(11) NOT NULL DEFAULT 0',
                'height_mm'     => 'int(11) NOT NULL DEFAULT 0',
                'preview_image' => "varchar(500) NOT NULL DEFAULT ''",
                'thumbnail_version'     => 'bigint(20) unsigned NOT NULL DEFAULT 0',
                'thumbnail_visual_hash' => "varchar(16) NOT NULL DEFAULT ''",
                'thumbnail_capture_source' => "varchar(32) NOT NULL DEFAULT ''",
                'json_data'             => 'longtext NULL',
                'created_at'    => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP',
                'updated_at'    => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
            ]
        );

        $this->copy_column_data_if_both_exist($wpdb, $table, 'nome', 'name');
        $this->copy_column_data_if_both_exist($wpdb, $table, 'descricao', 'description');
        $this->copy_column_data_if_both_exist($wpdb, $table, 'categoria', 'category');
        $this->copy_column_data_if_both_exist($wpdb, $table, 'service_id', 'servico_id', true);
        $this->copy_column_data_if_both_exist($wpdb, $table, 'client_id', 'cliente_id', true);
        $this->drop_column_if_exists($wpdb, $table, 'name');
        $this->drop_column_if_exists($wpdb, $table, 'description');
        $this->drop_column_if_exists($wpdb, $table, 'category');
    }

    private function schema_align_layers(wpdb $wpdb): void {
        if (! $this->table_exists($wpdb, 'eko_sampa_layers')) {
            return;
        }

        $table = $wpdb->prefix . 'eko_sampa_layers';
        $this->add_missing_columns(
            $wpdb,
            $table,
            [
                'template_id' => 'bigint(20) unsigned NOT NULL DEFAULT 0',
                'layer_type'  => "varchar(50) NOT NULL DEFAULT ''",
                'layer_order' => 'int(11) NOT NULL DEFAULT 0',
                'layer_json'  => 'longtext NULL',
            ]
        );
    }

    private function schema_align_orders(wpdb $wpdb): void {
        if (! $this->table_exists($wpdb, 'eko_sampa_orders')) {
            return;
        }

        $table = $wpdb->prefix . 'eko_sampa_orders';
        $this->add_missing_columns(
            $wpdb,
            $table,
            [
                'user_id'            => 'bigint(20) unsigned NOT NULL DEFAULT 0',
                'client_id'          => 'bigint(20) unsigned NOT NULL DEFAULT 0',
                'service_id'         => 'bigint(20) unsigned NOT NULL DEFAULT 0',
                'template_id'        => 'bigint(20) unsigned NOT NULL DEFAULT 0',
                'woo_order_id'       => 'bigint(20) unsigned NOT NULL DEFAULT 0',
                'status'             => "varchar(32) NOT NULL DEFAULT 'pending'",
                'dynamic_data_json'  => 'longtext NULL',
                'print_ready'        => 'tinyint(1) NOT NULL DEFAULT 0',
                'order_title'        => 'varchar(255) NULL',
                'created_at'         => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP',
                'updated_at'         => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
            ]
        );

        $this->copy_column_data_if_both_exist($wpdb, $table, 'client_id', 'cliente_id', true);
        $this->copy_column_data_if_both_exist($wpdb, $table, 'service_id', 'servico_id', true);
        $this->copy_column_data_if_both_exist($wpdb, $table, 'template_id', 'modelo_id', true);
        $this->drop_column_if_exists($wpdb, $table, 'cliente_id');
        $this->drop_column_if_exists($wpdb, $table, 'servico_id');
        $this->drop_column_if_exists($wpdb, $table, 'modelo_id');
    }

    /**
     * Dynamic field metadata (defaults, placeholders, template visibility hint) + immutable order field snapshot.
     */
    private function migrate_to_1_0_3(string $charset_collate): void {
        unset($charset_collate);

        global $wpdb;

        if ($this->table_exists($wpdb, 'eko_sampa_fields')) {
            $table = $wpdb->prefix . 'eko_sampa_fields';
            $this->add_missing_columns(
                $wpdb,
                $table,
                [
                    'default_value'         => "varchar(500) NOT NULL DEFAULT ''",
                    'placeholder'           => "varchar(255) NOT NULL DEFAULT ''",
                    'show_in_template'      => 'tinyint(1) NOT NULL DEFAULT 1',
                    'validation_rules_json' => 'longtext NULL',
                ]
            );
        }

        if ($this->table_exists($wpdb, 'eko_sampa_orders')) {
            $table = $wpdb->prefix . 'eko_sampa_orders';
            $this->add_missing_columns(
                $wpdb,
                $table,
                [
                    'service_fields_snapshot_json' => 'longtext NULL',
                ]
            );
        }
    }

    /**
     * Templates: client_id for order creation / ownership UX.
     */
    private function migrate_to_1_0_4(string $charset_collate): void {
        unset($charset_collate);

        global $wpdb;

        $this->schema_align_templates($wpdb);
        $this->copy_column_data_if_both_exist(
            $wpdb,
            $wpdb->prefix . 'eko_sampa_templates',
            'client_id',
            'cliente_id',
            true
        );
    }

    /**
     * Post-1.0.4: re-run alignment + integrity snapshot (no automatic repair).
     */
    private function migrate_to_1_0_5(string $charset_collate): void {
        unset($charset_collate);

        $this->run_schema_alignment();

        $integrity = new Eko_Sampa_Database_Integrity($this);
        $integrity->run(false);
    }

    /**
     * Hybrid installs: legacy English NOT NULL columns (`title`, `width`, …) without DEFAULT block modern INSERTs.
     * Relaxes server-side constraints (DEFAULTs) — no column drops. Idempotent ALTER … MODIFY.
     */
    private function migrate_to_1_0_6(string $charset_collate): void {
        unset($charset_collate);
        $this->repair_templates_legacy_hybrid_relaxed_defaults(0);
    }

    /**
     * Orders: optional operational label (`order_title`) — not part of template / render context.
     */
    private function migrate_to_1_0_7(string $charset_collate): void {
        unset($charset_collate);

        global $wpdb;
        $this->schema_align_orders($wpdb);
    }

    /**
     * Orders: secondary index on `order_title` for listing / prefix-friendly lookups (LIKE 'x%' can use index).
     */
    private function migrate_to_1_0_8(string $charset_collate): void {
        unset($charset_collate);

        global $wpdb;
        if (! $this->table_exists($wpdb, 'eko_sampa_orders')) {
            return;
        }

        $table = $wpdb->prefix . 'eko_sampa_orders';
        $have  = $this->table_column_set($wpdb, $table);
        if (! isset($have['order_title'])) {
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $indexes = $wpdb->get_results("SHOW INDEX FROM `{$table}`", ARRAY_A);
        if (! is_array($indexes)) {
            return;
        }

        foreach ($indexes as $ix) {
            if (! is_array($ix)) {
                continue;
            }
            $col = isset($ix['Column_name']) ? strtolower((string) $ix['Column_name']) : '';
            $seq = isset($ix['Seq_in_index']) ? (int) $ix['Seq_in_index'] : 0;
            if ($col === 'order_title' && $seq === 1) {
                return;
            }
        }

        // Prefix 191: utf8mb4 max key length compatibility on older InnoDB.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("ALTER TABLE `{$table}` ADD INDEX eko_sampa_orders_order_title (`order_title`(191))");
        Eko_Sampa_Model_Base::clear_table_column_map_cache();
    }

    /**
     * Templates: persisted thumbnail capture tier (live vs server GD) for overwrite protection.
     */
    private function migrate_to_1_0_9(string $charset_collate): void {
        unset($charset_collate);

        global $wpdb;
        $this->schema_align_templates($wpdb);
    }

    /**
     * Quick print jobs (editor) — no link to orders table.
     */
    private function migrate_to_1_0_10(string $charset_collate): void {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        global $wpdb;

        $suffix = ' ) ENGINE=InnoDB ' . $charset_collate . ';';
        $table   = $wpdb->prefix . 'eko_sampa_quick_print_jobs';
        $sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			template_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(32) NOT NULL DEFAULT 'queued',
			quantity smallint(5) unsigned NOT NULL DEFAULT 1,
			printer_key varchar(191) NOT NULL DEFAULT '',
			preset_key varchar(191) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_created (user_id, created_at),
			KEY template_id (template_id)
		{$suffix}";

        dbDelta($sql);
    }

    /**
     * Preview ALTER statements for legacy hybrid columns (diagnostics / repair UI).
     *
     * @return array{statements: list<string>, backfill_title_sql: string}
     */
    public function preview_templates_legacy_hybrid_relaxed_defaults(): array {
        return $this->build_templates_legacy_hybrid_relaxed_sql(true);
    }

    /**
     * Apply ALTER + optional title backfill. Call from admin repair or migration 1.0.6.
     *
     * @return array{ok: bool, statements: list<string>, backfill_rows: int, wpdb_error: string}
     */
    public function repair_templates_legacy_hybrid_relaxed_defaults(int $actor_user_id): array {
        $built = $this->build_templates_legacy_hybrid_relaxed_sql(false);
        global $wpdb;

        $ok    = true;
        $error = '';
        foreach ($built['statements'] as $sql) {
            if ($sql === '') {
                continue;
            }
            $wpdb->query($sql);
            if ($wpdb->last_error !== '') {
                $ok    = false;
                $error = trim((string) $wpdb->last_error);
                break;
            }
        }

        $backfill_rows = 0;
        if ($ok && $built['backfill_title_sql'] !== '') {
            $r = $wpdb->query($built['backfill_title_sql']);
            $backfill_rows = is_numeric($r) ? (int) $r : 0;
            if ($wpdb->last_error !== '') {
                $ok    = false;
                $error = trim((string) $wpdb->last_error);
            }
        }

        Eko_Sampa_Model_Base::clear_table_column_map_cache();

        if ($actor_user_id > 0 && class_exists('Eko_Sampa_Storage_Audit', false)) {
            Eko_Sampa_Storage_Audit::append(
                'template_schema_legacy_defaults_relaxed',
                [
                    'by'             => $actor_user_id,
                    'ok'             => $ok,
                    'statements_n'   => count($built['statements']),
                    'backfill_rows'  => $backfill_rows,
                    'wpdb_error'     => $error,
                ]
            );
        }

        return [
            'ok'             => $ok,
            'statements'     => $built['statements'],
            'backfill_rows'  => $backfill_rows,
            'wpdb_error'     => $error,
        ];
    }

    /**
     * @return array{statements: list<string>, backfill_title_sql: string}
     */
    private function build_templates_legacy_hybrid_relaxed_sql(bool $preview_only): array {
        unset($preview_only);

        global $wpdb;
        $statements = [];
        if (! $this->table_exists($wpdb, 'eko_sampa_templates')) {
            return ['statements' => [], 'backfill_title_sql' => ''];
        }

        $table = $wpdb->prefix . 'eko_sampa_templates';
        $have  = $this->table_column_set($wpdb, $table);
        $mods  = [];

        if (isset($have['title'])) {
            $mods[] = "MODIFY COLUMN `title` varchar(255) NOT NULL DEFAULT ''";
        }
        if (isset($have['width'])) {
            $mods[] = 'MODIFY COLUMN `width` float NOT NULL DEFAULT 0';
        }
        if (isset($have['height'])) {
            $mods[] = 'MODIFY COLUMN `height` float NOT NULL DEFAULT 0';
        }
        if (isset($have['background_color'])) {
            $mods[] = "MODIFY COLUMN `background_color` varchar(20) NOT NULL DEFAULT '#ffffff'";
        }
        if (isset($have['created_at'])) {
            $mods[] = 'MODIFY COLUMN `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP';
        }

        if ($mods !== []) {
            $statements[] = 'ALTER TABLE `' . $table . '` ' . implode(', ', $mods);
        }

        $backfill = '';
        if (isset($have['title'], $have['nome'])) {
            $backfill = "UPDATE `{$table}` SET `title` = `nome` WHERE (`title` = '' OR `title` IS NULL) AND `nome` <> ''";
        }

        return ['statements' => $statements, 'backfill_title_sql' => $backfill];
    }
}
