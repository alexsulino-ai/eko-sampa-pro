<?php
/**
 * Templates — create / edit metadata form.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$is_edit  = (isset($GLOBALS['eko_sampa_crud_action']) && $GLOBALS['eko_sampa_crud_action'] === 'edit');
$list_url = Eko_Sampa_Frontend_Router::get_resource_url('templates', 'list');
$crud_nav = [
    'label'   => __('Templates', 'eko-sampa'),
    'listUrl' => $list_url,
    'items'   => [['label' => $is_edit ? __('Edit', 'eko-sampa') : __('New', 'eko-sampa'), 'url' => '']],
];

?>
<div class="mx-auto max-w-4xl space-y-6" x-data="window.ekoTemplatesFactory()" x-init="init()">
    <?php require EKO_SAMPA_PLUGIN_DIR . 'views/partials/crud-nav.php'; ?>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-lg font-semibold text-slate-900" x-text="state.form.id ? '<?php echo esc_js(__('Edit template', 'eko-sampa')); ?>' : '<?php echo esc_js(__('New template', 'eko-sampa')); ?>'"></h2>
        <a class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm hover:bg-slate-50" :href="state.form.id ? viewUrl(state.form.id) : crudUrls.list"><?php echo esc_html__('Cancel', 'eko-sampa'); ?></a>
    </div>
    <p class="text-sm text-red-600" x-show="error" x-text="error || ''"></p>
    <p class="text-xs text-slate-500" x-show="loading" x-cloak><?php echo esc_html__('Loading…', 'eko-sampa'); ?></p>
    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <h3 class="text-sm font-semibold text-slate-900"><?php echo esc_html__('Metadata', 'eko-sampa'); ?></h3>
        <p class="mt-1 text-xs text-slate-500"><?php echo esc_html__('Layout design is done in the visual editor after saving.', 'eko-sampa'); ?></p>
        <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <?php if (current_user_can('manage_options')) : ?>
                <label class="block text-xs font-medium text-slate-600 sm:col-span-2"><?php echo esc_html__('Owner user ID (new only)', 'eko-sampa'); ?>
                    <input class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" type="number" x-model="state.form.user_id" :disabled="!!state.form.id" />
                </label>
            <?php endif; ?>
            <label class="block text-xs font-medium text-slate-600 sm:col-span-2"><?php echo esc_html__('Name', 'eko-sampa'); ?> *
                <input class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" type="text" x-model="state.form.nome" />
            </label>
            <label class="block text-xs font-medium text-slate-600"><?php echo esc_html__('Category', 'eko-sampa'); ?>
                <input class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" type="text" x-model="state.form.categoria" />
            </label>
            <label class="block text-xs font-medium text-slate-600 sm:col-span-2"><?php echo esc_html__('Description', 'eko-sampa'); ?>
                <textarea class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" rows="2" x-model="state.form.descricao"></textarea>
            </label>
            <label class="block text-xs font-medium text-slate-600"><?php echo esc_html__('Width mm', 'eko-sampa'); ?>
                <input class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" type="number" x-model.number="state.form.width_mm" />
            </label>
            <label class="block text-xs font-medium text-slate-600"><?php echo esc_html__('Height mm', 'eko-sampa'); ?>
                <input class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" type="number" x-model.number="state.form.height_mm" />
            </label>
            <label class="block text-xs font-medium text-slate-600 sm:col-span-2"><?php echo esc_html__('Linked service ID', 'eko-sampa'); ?>
                <input class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" type="number" x-model.number="state.form.service_id" />
            </label>
            <label class="block text-xs font-medium text-slate-600"><?php echo esc_html__('WooCommerce product ID', 'eko-sampa'); ?>
                <input class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" type="number" min="0" x-model.number="state.form.product_id" />
            </label>
            <label class="block text-xs font-medium text-slate-600 sm:col-span-2"><?php echo esc_html__('Preview image URL', 'eko-sampa'); ?>
                <input class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" type="text" x-model="state.form.preview_image" placeholder="https://…" />
            </label>
        </div>
        <div class="mt-4 flex flex-wrap gap-2">
            <button type="button" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700" @click="save()" :disabled="loading"><?php echo esc_html__('Save', 'eko-sampa'); ?></button>
            <a class="rounded-lg border border-slate-200 px-4 py-2 text-sm hover:bg-slate-50" x-show="state.form.id" :href="editorUrl(state.form.id)"><?php echo esc_html__('Open editor', 'eko-sampa'); ?></a>
        </div>
    </div>
</div>
