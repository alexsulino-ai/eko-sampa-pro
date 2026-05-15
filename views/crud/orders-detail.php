<?php
/**
 * Orders — read-only detail + preview.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$list_url = Eko_Sampa_Frontend_Router::get_resource_url('orders', 'list');
$crud_nav = [
    'label'   => __('Orders', 'eko-sampa'),
    'listUrl' => $list_url,
    'items'   => [['label' => __('Details', 'eko-sampa'), 'url' => '']],
];

?>
<div class="mx-auto max-w-6xl space-y-6" x-data="window.ekoOrdersFactory()" x-init="init()">
    <?php require EKO_SAMPA_PLUGIN_DIR . 'views/partials/crud-nav.php'; ?>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-lg font-semibold text-slate-900">
            <?php echo esc_html__('Order', 'eko-sampa'); ?>
            <span class="font-mono" x-text="'#' + (state.record.id || '')"></span>
        </h2>
        <div class="flex flex-wrap gap-2">
            <a class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm hover:bg-slate-50" :href="crudUrls.list"><?php echo esc_html__('Back to list', 'eko-sampa'); ?></a>
            <a class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700" :href="editUrl(state.record.id)" x-show="state.record.id"><?php echo esc_html__('Edit', 'eko-sampa'); ?></a>
            <a class="rounded-lg border border-slate-200 px-3 py-2 text-sm hover:bg-slate-50" :href="printUrl(state.record.id)" target="_blank" x-show="state.record.id"><?php echo esc_html__('Print', 'eko-sampa'); ?></a>
        </div>
    </div>
    <p class="text-sm text-red-600" x-show="error" x-text="error || ''"></p>
    <p class="text-xs text-slate-500" x-show="loading" x-cloak><?php echo esc_html__('Loading…', 'eko-sampa'); ?></p>
    <div class="grid gap-6 lg:grid-cols-2" x-show="state.record.id" x-cloak>
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-sm font-semibold text-slate-900"><?php echo esc_html__('Order data', 'eko-sampa'); ?></h3>
            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                <div><dt class="text-xs font-medium uppercase text-slate-500"><?php echo esc_html__('Status', 'eko-sampa'); ?></dt><dd class="mt-1" x-text="state.record.status"></dd></div>
                <div><dt class="text-xs font-medium uppercase text-slate-500"><?php echo esc_html__('Print ready', 'eko-sampa'); ?></dt><dd class="mt-1" x-text="state.record.print_ready == 1 ? '<?php echo esc_js(__('Yes', 'eko-sampa')); ?>' : '<?php echo esc_js(__('No', 'eko-sampa')); ?>'"></dd></div>
                <div><dt class="text-xs font-medium uppercase text-slate-500"><?php echo esc_html__('Client ID', 'eko-sampa'); ?></dt><dd class="mt-1 font-mono" x-text="state.record.client_id || '0'"></dd></div>
                <div><dt class="text-xs font-medium uppercase text-slate-500"><?php echo esc_html__('Service ID', 'eko-sampa'); ?></dt><dd class="mt-1 font-mono" x-text="state.record.service_id || '—'"></dd></div>
                <div><dt class="text-xs font-medium uppercase text-slate-500"><?php echo esc_html__('Template ID', 'eko-sampa'); ?></dt><dd class="mt-1 font-mono" x-text="state.record.template_id || '—'"></dd></div>
                <div><dt class="text-xs font-medium uppercase text-slate-500"><?php echo esc_html__('WC order', 'eko-sampa'); ?></dt><dd class="mt-1 font-mono" x-text="state.record.woo_order_id || '—'"></dd></div>
            </dl>
            <div class="mt-4 border-t border-slate-100 pt-3" x-show="state.serviceFields.length">
                <p class="text-xs font-medium text-slate-600"><?php echo esc_html__('Dynamic field values', 'eko-sampa'); ?></p>
                <dl class="mt-2 space-y-2 text-sm">
                    <template x-for="f in state.serviceFields" :key="f.id">
                        <div class="flex gap-2">
                            <dt class="shrink-0 font-medium text-slate-700" x-text="f.label + ':'"></dt>
                            <dd class="min-w-0 font-mono text-slate-600" x-text="(state.record.dynamic_data_json && state.record.dynamic_data_json[f.slug]) != null ? state.record.dynamic_data_json[f.slug] : '—'"></dd>
                        </div>
                    </template>
                </dl>
            </div>
        </div>
        <div class="flex min-h-0 flex-col overflow-hidden rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <h3 class="text-sm font-semibold text-slate-900"><?php echo esc_html__('Preview', 'eko-sampa'); ?></h3>
            <?php require EKO_SAMPA_PLUGIN_DIR . 'views/editor-canvas-order-preview.php'; ?>
            <iframe
                class="mt-2 h-[min(24rem,50vh)] w-full min-h-[8rem] rounded border border-slate-100 bg-white"
                x-show="state.previewSrcdoc"
                x-cloak
                sandbox="allow-same-origin"
                referrerpolicy="no-referrer"
                title="<?php echo esc_attr__('Order preview', 'eko-sampa'); ?>"
                x-bind:srcdoc="state.previewSrcdoc"
            ></iframe>
        </div>
    </div>
</div>
