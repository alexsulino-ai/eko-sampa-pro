<?php
/**
 * Clients — create / edit form.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$is_edit  = (isset($GLOBALS['eko_sampa_crud_action']) && $GLOBALS['eko_sampa_crud_action'] === 'edit');
$list_url = Eko_Sampa_Frontend_Router::get_resource_url('clients', 'list');
$crud_nav = [
    'label'   => __('Clients', 'eko-sampa'),
    'listUrl' => $list_url,
    'items'   => [['label' => $is_edit ? __('Edit', 'eko-sampa') : __('New', 'eko-sampa'), 'url' => '']],
];

?>
<div class="mx-auto max-w-4xl space-y-6" x-data="window.ekoClientsFactory()" x-init="init()">
    <?php require EKO_SAMPA_PLUGIN_DIR . 'views/partials/crud-nav.php'; ?>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-lg font-semibold text-slate-900" x-text="state.form.id ? '<?php echo esc_js(__('Edit client', 'eko-sampa')); ?>' : '<?php echo esc_js(__('New client', 'eko-sampa')); ?>'"></h2>
        <a class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm hover:bg-slate-50" :href="state.form.id ? viewUrl(state.form.id) : crudUrls.list"><?php echo esc_html__('Cancel', 'eko-sampa'); ?></a>
    </div>
    <p class="text-sm text-red-600" x-show="error" x-text="error || ''"></p>
    <p class="text-xs text-slate-500" x-show="loading" x-cloak><?php echo esc_html__('Loading…', 'eko-sampa'); ?></p>
    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <h3 class="text-sm font-semibold text-slate-900"><?php echo esc_html__('Details', 'eko-sampa'); ?></h3>
        <div class="mt-4 grid gap-3 sm:grid-cols-2">
            <?php if (current_user_can('manage_options')) : ?>
                <label class="block text-xs font-medium text-slate-600 sm:col-span-2"><?php echo esc_html__('Owner user ID (new only)', 'eko-sampa'); ?>
                    <input class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" type="number" x-model="state.form.user_id" :disabled="!!state.form.id" />
                </label>
            <?php endif; ?>
            <label class="block text-xs font-medium text-slate-600"><?php echo esc_html__('Name', 'eko-sampa'); ?> *
                <input class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" type="text" x-model="state.form.nome" required />
            </label>
            <label class="block text-xs font-medium text-slate-600"><?php echo esc_html__('Email', 'eko-sampa'); ?>
                <input class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" type="email" x-model="state.form.email" />
            </label>
            <label class="block text-xs font-medium text-slate-600"><?php echo esc_html__('Phone', 'eko-sampa'); ?>
                <input class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" type="text" x-model="state.form.telefone" />
            </label>
            <label class="block text-xs font-medium text-slate-600"><?php echo esc_html__('Document', 'eko-sampa'); ?>
                <input class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" type="text" x-model="state.form.documento" />
            </label>
            <label class="block text-xs font-medium text-slate-600"><?php echo esc_html__('City', 'eko-sampa'); ?>
                <input class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" type="text" x-model="state.form.cidade" />
            </label>
            <label class="block text-xs font-medium text-slate-600"><?php echo esc_html__('State', 'eko-sampa'); ?>
                <input class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" type="text" x-model="state.form.estado" />
            </label>
        </div>
        <div class="mt-4 flex justify-end gap-2">
            <button type="button" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700" @click="save()" :disabled="loading"><?php echo esc_html__('Save', 'eko-sampa'); ?></button>
        </div>
    </div>
</div>
