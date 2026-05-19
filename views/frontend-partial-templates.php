<?php
/**
 * Templates CRUD router (list / view / form).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$action = isset($GLOBALS['eko_sampa_crud_action']) ? sanitize_key((string) $GLOBALS['eko_sampa_crud_action']) : 'list';
$map    = [
    'list' => 'templates-list.php',
    'view' => 'templates-detail.php',
    'new'  => 'templates-form.php',
    'edit' => 'templates-form.php',
];
$file = $map[ $action ] ?? 'templates-list.php';
$path = EKO_SAMPA_PLUGIN_DIR . 'views/crud/' . $file;

if (is_readable($path)) {
    require $path;
}
