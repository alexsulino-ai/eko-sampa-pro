<?php
/**
 * Orders CRUD router (list / view / form).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$action = isset($GLOBALS['eko_sampa_crud_action']) ? sanitize_key((string) $GLOBALS['eko_sampa_crud_action']) : 'list';
$map    = [
    'list' => 'orders-list.php',
    'view' => 'orders-detail.php',
    'new'  => 'orders-form.php',
    'edit' => 'orders-form.php',
];
$file = $map[ $action ] ?? 'orders-list.php';
$path = EKO_SAMPA_PLUGIN_DIR . 'views/crud/' . $file;

if (is_readable($path)) {
    require $path;
}
