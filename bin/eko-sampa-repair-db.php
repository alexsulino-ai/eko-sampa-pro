<?php
/**
 * One-off database repair for Eko Sampa (creates missing tables / columns).
 *
 * Usage (from WordPress root):
 *   php wp-content/plugins/eko-sampa/bin/eko-sampa-repair-db.php
 *
 * Or with WP-CLI:
 *   wp eval-file wp-content/plugins/eko-sampa/bin/eko-sampa-repair-db.php
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this script from the command line.\n");
    exit(1);
}

$wp_load = locate_wp_load(__DIR__);
if ($wp_load === null) {
    fwrite(STDERR, "Could not find wp-load.php. Run from your WordPress site root or adjust the path.\n");
    exit(1);
}

require_once $wp_load;

if (! defined('EKO_SAMPA_PLUGIN_FILE')) {
    fwrite(STDERR, "Eko Sampa plugin is not active or not installed.\n");
    exit(1);
}

$database = new Eko_Sampa_Database();
$before   = $database->missing_required_tables();
$database->ensure_schema();
$after    = $database->missing_required_tables();

if ($before === []) {
    fwrite(STDOUT, "All required Eko Sampa tables were already present.\n");
    fwrite(STDOUT, 'DB version option: ' . (string) get_option('eko_sampa_db_version', '0') . "\n");
    exit(0);
}

fwrite(STDOUT, 'Missing before repair: ' . implode(', ', $before) . "\n");

if ($after === []) {
    fwrite(STDOUT, "Repair completed. All required tables exist.\n");
    fwrite(STDOUT, 'DB version option: ' . (string) get_option('eko_sampa_db_version', '0') . "\n");
    exit(0);
}

fwrite(STDERR, 'Still missing after repair: ' . implode(', ', $after) . "\n");
exit(1);

/**
 * Walk upward from $start to find wp-load.php (max 8 levels).
 */
function locate_wp_load(string $start): ?string {
    $dir = realpath($start);
    if ($dir === false) {
        return null;
    }

    for ($i = 0; $i < 8; $i++) {
        $candidate = $dir . DIRECTORY_SEPARATOR . 'wp-load.php';
        if (is_readable($candidate)) {
            return $candidate;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }

    return null;
}
