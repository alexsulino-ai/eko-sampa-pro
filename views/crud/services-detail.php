<?php
/**
 * Services � read-only detail.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$list_url = Eko_Sampa_Frontend_Router::get_resource_url('services', 'list');
$crud_nav = [
    'label'   => __('Services', 'eko-sampa'),
    'listUrl' => $list_url,
    'items'   => [['label' => __('Details', 'eko-sampa'), 'url' => '']],
];

?>
<div class="mx-auto max-w-4xl space-y-6" x-data="window.ekoServicesFactory()" x-init="init()">
    <?php require EKO_SAMPA_PLUGIN_DIR . 'views/partials/crud-nav.php'; ?>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-lg font-semibold text-slate-900" x-text="state.record.nome || '<?php echo esc_js(__('Service', 'eko-sampa')); ?>'"></h2>
        <div class="flex flex-wrap gap-2">
            <a class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm hover:bg-slate-50" :href="crudUrls.list"><?php echo esc_html__('Back to list', 'eko-sampa'); ?></a>
            <a class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700" :href="editUrl(state.record.id)" x-show="state.record.id"><?php echo esc_html__('Edit', 'eko-sampa'); ?></a>
        </div>
    </div>
    <p class="text-sm text-red-600" x-show="error" x-text="error || ''"></p>
    <p class="text-xs text-slate-500" x-show="loading" x-cloak><?php echo esc_html__('Loading�', 'eko-sampa'); ?></p>
    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm" x-show="state.record.id" x-cloak>
        <dl class="grid gap-4 sm:grid-cols-2 text-sm">
            <div><dt class="text-xs font-medium uppercase text-slate-500">ID</dt><dd class="mt-1 font-mono text-slate-900" x-text="state.record.id"></dd></div>
            <div><dt class="text-xs font-medium uppercase text-slate-500"><?php echo esc_html__('Global', 'eko-sampa'); ?></dt><dd class="mt-1 text-slate-900" x-text="state.record.is_global == 1 ? '<?php echo esc_js(__('Yes', 'eko-sampa')); ?>' : '<?php echo esc_js(__('No', 'eko-sampa')); ?>'"></dd></div>
            <div class="sm:col-span-2"><dt class="text-xs font-medium uppercase text-slate-500"><?php echo esc_html__('Description', 'eko-sampa'); ?></dt><dd class="mt-1 whitespace-pre-wrap text-slate-900" x-text="state.record.descricao || '�'"></dd></div>
        </dl>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm" x-show="state.record.id" x-cloak>
        <h3 class="text-sm font-semibold text-slate-900"><?php echo esc_html__('Dynamic fields', 'eko-sampa'); ?></h3>
        <p class="mt-1 text-xs text-slate-500"><?php echo esc_html__('Read-only overview. Edit the service to change fields.', 'eko-sampa'); ?></p>
        <table class="mt-4 min-w-full divide-y divide-slate-200 text-sm" x-show="state.fields.length">
            <thead class="bg-slate-50 text-left text-xs font-medium uppercase text-slate-500">
                <tr>
                    <th class="px-3 py-2"><?php echo esc_html__('Label', 'eko-sampa'); ?></th>
                    <th class="px-3 py-2"><?php echo esc_html__('Slug', 'eko-sampa'); ?></th>
                    <th class="px-3 py-2"><?php echo esc_html__('Type', 'eko-sampa'); ?></th>
                    <th class="px-3 py-2"><?php echo esc_html__('Required', 'eko-sampa'); ?></th>
                    <th class="px-3 py-2"><?php echo esc_html__('Print', 'eko-sampa'); ?></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <template x-for="f in state.fields" :key="f.id">
                    <tr>
                        <td class="px-3 py-2 font-medium" x-text="f.label"></td>
                        <td class="px-3 py-2 font-mono text-xs text-slate-600" x-text="f.slug"></td>
                        <td class="px-3 py-2 text-slate-600" x-text="f.type"></td>
                        <td class="px-3 py-2" x-text="f.required == 1 ? '<?php echo esc_js(__('Yes', 'eko-sampa')); ?>' : '<?php echo esc_js(__('No', 'eko-sampa')); ?>'"></td>
                        <td class="px-3 py-2" x-text="f.show_in_template == 1 ? '<?php echo esc_js(__('Yes', 'eko-sampa')); ?>' : '<?php echo esc_js(__('No', 'eko-sampa')); ?>'"></td>
                    </tr>
                </template>
            </tbody>
        </table>
        <p class="mt-3 text-xs text-slate-500" x-show="!state.fields.length"><?php echo esc_html__('No fields defined.', 'eko-sampa'); ?></p>
    </div>
</div>
