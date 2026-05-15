<?php
/**
 * Orders — create / edit with live preview.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$is_edit  = (isset($GLOBALS['eko_sampa_crud_action']) && $GLOBALS['eko_sampa_crud_action'] === 'edit');
$list_url = Eko_Sampa_Frontend_Router::get_resource_url('orders', 'list');
$crud_nav = [
    'label'   => __('Orders', 'eko-sampa'),
    'listUrl' => $list_url,
    'items'   => [['label' => $is_edit ? __('Edit', 'eko-sampa') : __('New', 'eko-sampa'), 'url' => '']],
];

?>
<div class="mx-auto max-w-6xl space-y-6" x-data="window.ekoOrdersFactory()" x-init="init()">
    <?php require EKO_SAMPA_PLUGIN_DIR . 'views/partials/crud-nav.php'; ?>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-lg font-semibold text-slate-900" x-text="state.form.id ? '<?php echo esc_js(__('Edit order', 'eko-sampa')); ?> #' + state.form.id : '<?php echo esc_js(__('New order', 'eko-sampa')); ?>'"></h2>
        <a class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm hover:bg-slate-50" :href="state.form.id ? viewUrl(state.form.id) : crudUrls.list"><?php echo esc_html__('Cancel', 'eko-sampa'); ?></a>
    </div>
    <p class="text-sm text-red-600" x-show="error" x-text="error || ''"></p>
    <p class="text-xs text-slate-500" x-show="loading" x-cloak><?php echo esc_html__('Loading…', 'eko-sampa'); ?></p>
    <div class="grid min-h-0 gap-6 lg:grid-cols-2 lg:items-start">
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <?php require EKO_SAMPA_PLUGIN_DIR . 'views/partials/orders-form-body.php'; ?>
        </div>
        <div class="flex min-h-0 flex-col overflow-hidden rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <?php require EKO_SAMPA_PLUGIN_DIR . 'views/editor-canvas-order-preview.php'; ?>
            <iframe
                class="mt-2 h-[min(24rem,50vh)] w-full min-h-[8rem] rounded border border-slate-100 bg-white"
                x-show="state.previewSrcdoc"
                x-cloak
                sandbox="allow-same-origin"
                referrerpolicy="no-referrer"
                title="<?php echo esc_attr__('Order preview (HTML fallback)', 'eko-sampa'); ?>"
                x-bind:srcdoc="state.previewSrcdoc"
            ></iframe>
        </div>
    </div>
</div>
