<?php
/**
 * Services CRUD router (list / view / form).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$action = isset($GLOBALS['eko_sampa_crud_action']) ? sanitize_key((string) $GLOBALS['eko_sampa_crud_action']) : 'list';
$map    = [
    'list' => 'services-list.php',
    'view' => 'services-detail.php',
    'new'  => 'services-form.php',
    'edit' => 'services-form.php',
];
$file = $map[ $action ] ?? 'services-list.php';
$path = EKO_SAMPA_PLUGIN_DIR . 'views/crud/' . $file;

if (is_readable($path)) {
    require $path;
}
