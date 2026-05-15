<?php
/**
 * Services — create / edit form (+ dynamic fields when saved).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$is_edit  = (isset($GLOBALS['eko_sampa_crud_action']) && $GLOBALS['eko_sampa_crud_action'] === 'edit');
$list_url = Eko_Sampa_Frontend_Router::get_resource_url('services', 'list');
$crud_nav = [
    'label'   => __('Services', 'eko-sampa'),
    'listUrl' => $list_url,
    'items'   => [
        [
            'label' => $is_edit ? __('Edit', 'eko-sampa') : __('New', 'eko-sampa'),
            'url'   => '',
        ],
    ],
];

$eko_modal_body_path = EKO_SAMPA_PLUGIN_DIR . 'views/partials/service-field-form-body.php';

?>
<div class="mx-auto max-w-4xl space-y-6" x-data="window.ekoServicesFactory()" x-init="init()">
    <?php require EKO_SAMPA_PLUGIN_DIR . 'views/partials/crud-nav.php'; ?>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-lg font-semibold text-slate-900" x-text="state.form.id ? '<?php echo esc_js(__('Edit service', 'eko-sampa')); ?>' : '<?php echo esc_js(__('New service', 'eko-sampa')); ?>'"></h2>
        <a class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm transition-colors hover:bg-slate-50" :href="state.form.id ? viewUrl(state.form.id) : crudUrls.list"><?php echo esc_html__('Cancel', 'eko-sampa'); ?></a>
    </div>
    <p class="text-sm text-red-600" x-show="error" x-text="error || ''"></p>
    <p class="text-xs text-slate-500" x-show="loading" x-cloak><?php echo esc_html__('Loading…', 'eko-sampa'); ?></p>
    <div class="space-y-4">
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-sm font-semibold text-slate-900"><?php echo esc_html__('Service', 'eko-sampa'); ?></h3>
            <div class="mt-3 space-y-3">
                <label class="block text-xs font-medium text-slate-600"><?php echo esc_html__('Name', 'eko-sampa'); ?> *
                    <input class="mt-1 w-full rounded-lg border border-slate-200 px-2 py-1.5 text-sm focus:border-indigo-300 focus:outline-none focus:ring-2 focus:ring-indigo-500/20" type="text" x-model="state.form.nome" />
                </label>
                <label class="block text-xs font-medium text-slate-600"><?php echo esc_html__('Description', 'eko-sampa'); ?>
                    <textarea class="mt-1 w-full rounded-lg border border-slate-200 px-2 py-1.5 text-sm focus:border-indigo-300 focus:outline-none focus:ring-2 focus:ring-indigo-500/20" rows="3" x-model="state.form.descricao"></textarea>
                </label>
                <?php if (current_user_can('manage_options')) : ?>
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" x-model="state.form.is_global" :true-value="1" :false-value="0" />
                        <?php echo esc_html__('Global catalog', 'eko-sampa'); ?>
                    </label>
                <?php endif; ?>
                <button type="button" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-indigo-700" @click="save()" :disabled="loading"><?php echo esc_html__('Save service', 'eko-sampa'); ?></button>
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm" x-show="state.form.id" x-cloak>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="text-sm font-semibold text-slate-900"><?php echo esc_html__('Dynamic fields', 'eko-sampa'); ?></h3>
                    <p class="mt-0.5 text-xs text-slate-500"><?php echo esc_html__('Define placeholders consumed by templates and orders.', 'eko-sampa'); ?></p>
                </div>
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-sm font-medium text-indigo-800 transition-colors hover:bg-indigo-100"
                    @click="openAddFieldModal()"
                >
                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    <?php echo esc_html__('Add field', 'eko-sampa'); ?>
                </button>
            </div>

            <ul class="mt-4 divide-y divide-slate-100 text-sm" x-ref="fieldSortRoot" x-show="state.fields.length">
                <template x-for="f in state.fields" :key="f.id">
                    <li class="flex items-center gap-2 py-2.5" :data-field-id="f.id">
                        <button type="button" class="cursor-grab rounded-lg border border-transparent px-1.5 text-slate-400 transition-colors hover:border-slate-200 hover:bg-slate-50 hover:text-slate-600" data-eko-field-drag="1" title="<?php echo esc_attr__('Drag to reorder', 'eko-sampa'); ?>" aria-label="<?php echo esc_attr__('Drag to reorder', 'eko-sampa'); ?>">
                            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9h16.5m-16.5 6.75h16.5" /></svg>
                        </button>
                        <div class="min-w-0 flex-1">
                            <span class="font-medium text-slate-900" x-text="f.label"></span>
                            <span class="ml-1 font-mono text-xs text-slate-500" x-text="'{{' + f.slug + '}}'"></span>
                            <span class="ml-2 rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-slate-600" x-text="f.type"></span>
                        </div>
                        <div class="flex shrink-0 items-center gap-1">
                            <?php
                            eko_sampa_crud_action('edit', [
                                'click'     => 'editField(f)',
                                'title'     => __('Edit field', 'eko-sampa'),
                                'size'      => 'sm',
                                'icon_only' => true,
                            ]);
                            eko_sampa_crud_action('delete', [
                                'click'     => 'deleteField(f.id)',
                                'title'     => __('Remove field', 'eko-sampa'),
                                'size'      => 'sm',
                                'icon_only' => true,
                            ]);
                            ?>
                        </div>
                    </li>
                </template>
            </ul>
            <p class="mt-3 rounded-lg border border-dashed border-slate-200 bg-slate-50/50 px-4 py-6 text-center text-sm text-slate-500" x-show="!state.fields.length">
                <?php echo esc_html__('No fields yet. Add your first dynamic field.', 'eko-sampa'); ?>
            </p>
        </div>
    </div>

    <?php require EKO_SAMPA_PLUGIN_DIR . 'views/partials/eko-base-modal.php'; ?>
</div>
