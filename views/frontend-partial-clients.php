<?php
/**
 * Clients CRUD router (list / view / form).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$action = isset($GLOBALS['eko_sampa_crud_action']) ? sanitize_key((string) $GLOBALS['eko_sampa_crud_action']) : 'list';
$map    = [
    'list' => 'clients-list.php',
    'view' => 'clients-detail.php',
    'new'  => 'clients-form.php',
    'edit' => 'clients-form.php',
];
$file = $map[ $action ] ?? 'clients-list.php';
$path = EKO_SAMPA_PLUGIN_DIR . 'views/crud/' . $file;

if (is_readable($path)) {
    require $path;
}
