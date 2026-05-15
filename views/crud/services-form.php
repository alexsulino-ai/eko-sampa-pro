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
        <a class="eko-btn eko-btn-secondary" :href="state.form.id ? viewUrl(state.form.id) : crudUrls.list"><?php echo esc_html__('Cancel', 'eko-sampa'); ?></a>
    </div>

    <?php
    $eko_ui_show = 'error';
    require EKO_SAMPA_PLUGIN_DIR . 'views/partials/ui-state-error.php';
    $eko_ui_show = 'loading';
    require EKO_SAMPA_PLUGIN_DIR . 'views/partials/ui-state-loading.php';
    ?>

    <div class="space-y-4" x-show="!loading" x-cloak>
        <div class="eko-card p-5">
            <h3 class="text-sm font-semibold text-slate-900"><?php echo esc_html__('Service', 'eko-sampa'); ?></h3>
            <div class="mt-3 space-y-3">
                <label class="block text-xs font-medium text-slate-600"><?php echo esc_html__('Name', 'eko-sampa'); ?> *
                    <input class="eko-input mt-1" type="text" x-model="state.form.nome" />
                </label>
                <label class="block text-xs font-medium text-slate-600"><?php echo esc_html__('Description', 'eko-sampa'); ?>
                    <textarea class="eko-input mt-1" rows="3" x-model="state.form.descricao"></textarea>
                </label>
                <?php if (current_user_can('manage_options')) : ?>
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" x-model="state.form.is_global" :true-value="1" :false-value="0" />
                        <?php echo esc_html__('Global catalog', 'eko-sampa'); ?>
                    </label>
                <?php endif; ?>
                <button type="button" class="eko-btn eko-btn-primary" @click="save()" :disabled="loading"><?php echo esc_html__('Save service', 'eko-sampa'); ?></button>
            </div>
        </div>

        <div class="eko-card p-5" x-show="state.form.id" x-cloak>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <button type="button" class="flex min-w-0 flex-1 items-start gap-2 text-left" @click="toggleFieldsPanel()">
                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-slate-400 transition-transform" :class="state.fieldsPanelOpen ? 'rotate-90' : ''" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                    <span>
                        <span class="block text-sm font-semibold text-slate-900"><?php echo esc_html__('Dynamic fields', 'eko-sampa'); ?></span>
                        <span class="mt-0.5 block text-xs text-slate-500"><?php echo esc_html__('Placeholders for templates and orders.', 'eko-sampa'); ?></span>
                    </span>
                </button>
                <button type="button" class="eko-btn eko-btn-primary shrink-0" @click="openAddFieldModal()">
                    <?php echo esc_html__('Add field', 'eko-sampa'); ?>
                </button>
            </div>

            <div x-show="state.fieldsPanelOpen" x-cloak class="mt-4 space-y-3">
                <input
                    class="eko-input max-w-md"
                    type="search"
                    placeholder="<?php echo esc_attr__('Filter fields…', 'eko-sampa'); ?>"
                    x-model="state.fieldSearch"
                    autocomplete="off"
                />

                <p class="text-xs text-amber-800" x-show="state.fieldSearch" x-cloak><?php echo esc_html__('Clear the filter to reorder fields.', 'eko-sampa'); ?></p>
                <ul class="divide-y divide-slate-100 text-sm" x-ref="fieldSortRoot" x-show="state.fields.length" :class="state.fieldSearch ? 'opacity-80' : ''">
                    <template x-for="f in state.fields" :key="f.id">
                        <li :class="fieldRowClasses(f)" :data-field-id="f.id">
                            <div class="flex items-center gap-2">
                                <button type="button" class="cursor-grab rounded-lg border border-transparent px-1.5 text-slate-400 hover:border-slate-200 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40" data-eko-field-drag="1" :disabled="!!state.fieldSearch" title="<?php echo esc_attr__('Drag to reorder', 'eko-sampa'); ?>">
                                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9h16.5m-16.5 6.75h16.5" /></svg>
                                </button>
                                <button type="button" class="min-w-0 flex-1 text-left" @click="toggleFieldExpand(f.id)">
                                    <span class="font-medium text-slate-900" x-text="f.label"></span>
                                    <span class="ml-1 font-mono text-xs text-slate-500" x-text="'{' + '{' + f.slug + '}' + '}'"></span>
                                    <span class="ml-2 rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] uppercase text-slate-600" x-text="f.type"></span>
                                </button>
                                <div class="flex shrink-0 gap-1">
                                    <?php
                            eko_sampa_crud_action('edit', ['click' => 'editField(f)', 'title' => __('Edit field', 'eko-sampa'), 'size' => 'sm', 'icon_only' => true, 'can' => 'service.edit', 'policy' => 'disabled']);
                            eko_sampa_crud_action('delete', ['click' => 'deleteField(f.id)', 'title' => __('Remove field', 'eko-sampa'), 'size' => 'sm', 'icon_only' => true, 'can' => 'service.delete', 'policy' => 'disabled']);
                                    ?>
                                </div>
                            </div>
                            <div class="eko-field-row__detail ml-9 mt-1 text-xs text-slate-500" x-show="isFieldExpanded(f.id)" x-cloak>
                                <span x-show="f.required == 1"><?php echo esc_html__('Required', 'eko-sampa'); ?> · </span>
                                <span x-text="f.show_in_template == 1 ? '<?php echo esc_js(__('Template', 'eko-sampa')); ?>' : '<?php echo esc_js(__('Operational only', 'eko-sampa')); ?>'"></span>
                            </div>
                        </li>
                    </template>
                </ul>

                <?php
                $eko_ui_show    = '!state.fields.length';
                $eko_ui_title   = __('No fields yet', 'eko-sampa');
                $eko_ui_message = __('Add your first dynamic field to define placeholders.', 'eko-sampa');
                require EKO_SAMPA_PLUGIN_DIR . 'views/partials/ui-state-empty.php';
                $eko_ui_show    = 'state.fields.length && !filteredFieldsList().length';
                $eko_ui_title   = __('No matches', 'eko-sampa');
                $eko_ui_message = __('Try a different search term.', 'eko-sampa');
                require EKO_SAMPA_PLUGIN_DIR . 'views/partials/ui-state-empty.php';
                ?>
            </div>
        </div>
    </div>

    <?php require EKO_SAMPA_PLUGIN_DIR . 'views/partials/eko-base-modal.php'; ?>
</div>
