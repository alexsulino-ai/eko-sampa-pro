<?php
/**
 * Breadcrumb / back navigation for CRUD sub-routes.
 *
 * Expects $crud_nav (array): label, listUrl, optional items [{label, url}].
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$crud_nav = isset($crud_nav) && is_array($crud_nav) ? $crud_nav : [];
$section  = isset($crud_nav['label']) ? (string) $crud_nav['label'] : '';
$list_url = isset($crud_nav['listUrl']) ? (string) $crud_nav['listUrl'] : '';
$items    = isset($crud_nav['items']) && is_array($crud_nav['items']) ? $crud_nav['items'] : [];

?>
<nav class="flex flex-wrap items-center gap-2 text-sm text-slate-500" aria-label="<?php echo esc_attr__('Breadcrumb', 'eko-sampa'); ?>">
    <?php if ($list_url !== '') : ?>
        <a class="text-indigo-600 hover:underline" href="<?php echo esc_url($list_url); ?>"><?php echo esc_html($section); ?></a>
    <?php else : ?>
        <span><?php echo esc_html($section); ?></span>
    <?php endif; ?>
    <?php foreach ($items as $item) : ?>
        <?php
        if (! is_array($item)) {
            continue;
        }
        $ilabel = isset($item['label']) ? (string) $item['label'] : '';
        $iurl   = isset($item['url']) ? (string) $item['url'] : '';
        if ($ilabel === '') {
            continue;
        }
        ?>
        <span aria-hidden="true">/</span>
        <?php if ($iurl !== '') : ?>
            <a class="text-indigo-600 hover:underline" href="<?php echo esc_url($iurl); ?>"><?php echo esc_html($ilabel); ?></a>
        <?php else : ?>
            <span class="font-medium text-slate-900"><?php echo esc_html($ilabel); ?></span>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>
