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
     * Run pending incremental migrations up to EKO_SAMPA_DB_VERSION.
     * Never removes tables automatically.
     */
    public function migrate(): void {
        $installed = (string) get_option(self::OPTION_DB_VERSION, '0');

        if (version_compare($installed, EKO_SAMPA_DB_VERSION, '>=')) {
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
        ];
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

        // templates: id, user_id, product_id, service_id, nome, descricao, width_mm, height_mm, preview_image, json_data, created_at, updated_at
        $sql_templates = "CREATE TABLE {$templates} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
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
}
