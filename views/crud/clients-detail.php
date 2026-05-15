<?php
/**
 * Clients — read-only detail.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$list_url = Eko_Sampa_Frontend_Router::get_resource_url('clients', 'list');
$crud_nav = [
    'label'   => __('Clients', 'eko-sampa'),
    'listUrl' => $list_url,
    'items'   => [['label' => __('Details', 'eko-sampa'), 'url' => '']],
];

?>
<div class="mx-auto max-w-4xl space-y-6" x-data="window.ekoClientsFactory()" x-init="init()">
    <?php require EKO_SAMPA_PLUGIN_DIR . 'views/partials/crud-nav.php'; ?>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-lg font-semibold text-slate-900" x-text="state.record.nome || '<?php echo esc_js(__('Client', 'eko-sampa')); ?>'"></h2>
        <div class="flex flex-wrap gap-2">
            <a class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm hover:bg-slate-50" :href="crudUrls.list"><?php echo esc_html__('Back to list', 'eko-sampa'); ?></a>
            <a class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700" :href="editUrl(state.record.id)" x-show="state.record.id"><?php echo esc_html__('Edit', 'eko-sampa'); ?></a>
        </div>
    </div>
    <p class="text-sm text-red-600" x-show="error" x-text="error || ''"></p>
    <p class="text-xs text-slate-500" x-show="loading" x-cloak><?php echo esc_html__('Loading…', 'eko-sampa'); ?></p>
    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm" x-show="state.record.id" x-cloak>
        <h3 class="text-sm font-semibold text-slate-900"><?php echo esc_html__('Contact', 'eko-sampa'); ?></h3>
        <dl class="mt-4 grid gap-4 sm:grid-cols-2 text-sm">
            <div><dt class="text-xs font-medium uppercase text-slate-500">ID</dt><dd class="mt-1 font-mono text-slate-900" x-text="state.record.id"></dd></div>
            <div><dt class="text-xs font-medium uppercase text-slate-500"><?php echo esc_html__('Name', 'eko-sampa'); ?></dt><dd class="mt-1 text-slate-900" x-text="state.record.nome"></dd></div>
            <div><dt class="text-xs font-medium uppercase text-slate-500"><?php echo esc_html__('Email', 'eko-sampa'); ?></dt><dd class="mt-1 text-slate-900" x-text="state.record.email || '—'"></dd></div>
            <div><dt class="text-xs font-medium uppercase text-slate-500"><?php echo esc_html__('Phone', 'eko-sampa'); ?></dt><dd class="mt-1 text-slate-900" x-text="state.record.telefone || '—'"></dd></div>
            <div><dt class="text-xs font-medium uppercase text-slate-500"><?php echo esc_html__('Document', 'eko-sampa'); ?></dt><dd class="mt-1 text-slate-900" x-text="state.record.documento || '—'"></dd></div>
            <div><dt class="text-xs font-medium uppercase text-slate-500"><?php echo esc_html__('City', 'eko-sampa'); ?></dt><dd class="mt-1 text-slate-900" x-text="state.record.cidade || '—'"></dd></div>
            <div><dt class="text-xs font-medium uppercase text-slate-500"><?php echo esc_html__('State', 'eko-sampa'); ?></dt><dd class="mt-1 text-slate-900" x-text="state.record.estado || '—'"></dd></div>
        </dl>
    </div>
</div>
