<?php
/**
 * Services � create / edit form (+ dynamic fields when saved).
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

?>
<div class="mx-auto max-w-4xl space-y-6" x-data="window.ekoServicesFactory()" x-init="init()">
    <?php require EKO_SAMPA_PLUGIN_DIR . 'views/partials/crud-nav.php'; ?>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-lg font-semibold text-slate-900" x-text="state.form.id ? '<?php echo esc_js(__('Edit service', 'eko-sampa')); ?>' : '<?php echo esc_js(__('New service', 'eko-sampa')); ?>'"></h2>
        <a class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm hover:bg-slate-50" :href="state.form.id ? viewUrl(state.form.id) : crudUrls.list"><?php echo esc_html__('Cancel', 'eko-sampa'); ?></a>
    </div>
    <p class="text-sm text-red-600" x-show="error" x-text="error || ''"></p>
    <p class="text-xs text-slate-500" x-show="loading" x-cloak><?php echo esc_html__('Loading�', 'eko-sampa'); ?></p>
    <div class="space-y-4">
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-sm font-semibold text-slate-900"><?php echo esc_html__('Service', 'eko-sampa'); ?></h3>
            <div class="mt-3 space-y-3">
                <label class="block text-xs font-medium text-slate-600"><?php echo esc_html__('Name', 'eko-sampa'); ?> *
                    <input class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" type="text" x-model="state.form.nome" />
                </label>
                <label class="block text-xs font-medium text-slate-600"><?php echo esc_html__('Description', 'eko-sampa'); ?>
                    <textarea class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" rows="3" x-model="state.form.descricao"></textarea>
                </label>
                <?php if (current_user_can('manage_options')) : ?>
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" x-model="state.form.is_global" :true-value="1" :false-value="0" />
                        <?php echo esc_html__('Global catalog', 'eko-sampa'); ?>
                    </label>
                <?php endif; ?>
                <button type="button" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700" @click="save()" :disabled="loading"><?php echo esc_html__('Save service', 'eko-sampa'); ?></button>
            </div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm" x-show="state.form.id" x-cloak>
            <h3 class="text-sm font-semibold text-slate-900"><?php echo esc_html__('Dynamic fields', 'eko-sampa'); ?></h3>
            <ul class="mt-2 divide-y divide-slate-100 text-sm" x-ref="fieldSortRoot" x-show="state.fields.length">
                <template x-for="f in state.fields" :key="f.id">
                    <li class="flex items-center gap-2 py-2" :data-field-id="f.id">
                        <button type="button" class="cursor-grab rounded border border-transparent px-1 text-slate-400 hover:border-slate-200 hover:bg-slate-50 hover:text-slate-600" data-eko-field-drag="1" title="<?php echo esc_attr__('Drag to reorder', 'eko-sampa'); ?>">?</button>
                        <div class="min-w-0 flex-1">
                            <span class="font-medium" x-text="f.label"></span>
                            <span class="text-slate-500" x-text="'(' + f.slug + ')'"></span>
                        </div>
                        <span class="shrink-0 space-x-2">
                            <button type="button" class="text-indigo-600 hover:underline" @click="editField(f)"><?php echo esc_html__('Edit', 'eko-sampa'); ?></button>
                            <button type="button" class="text-red-600 hover:underline" @click="deleteField(f.id)"><?php echo esc_html__('Remove', 'eko-sampa'); ?></button>
                        </span>
                    </li>
                </template>
            </ul>
            <p class="mt-2 text-xs text-slate-500" x-show="!state.fields.length"><?php echo esc_html__('No fields yet.', 'eko-sampa'); ?></p>
            <div class="mt-3 grid gap-2 sm:grid-cols-2">
                <input class="rounded border border-slate-200 px-2 py-1 text-sm" type="text" placeholder="<?php echo esc_attr__('Label', 'eko-sampa'); ?>" x-model="state.fieldForm.label" />
                <input class="rounded border border-slate-200 px-2 py-1 text-sm" type="text" placeholder="<?php echo esc_attr__('Slug', 'eko-sampa'); ?>" x-model="state.fieldForm.slug" />
                <select class="rounded border border-slate-200 px-2 py-1 text-sm" x-model="state.fieldForm.type" @change="if (state.fieldForm.type === 'select' && (state.fieldForm.options_json == null || state.fieldForm.options_json === '')) { state.fieldForm.options_json = '[]'; }">
                    <option value="text">text</option>
                    <option value="textarea">textarea</option>
                    <option value="number">number</option>
                    <option value="select">select</option>
                    <option value="date">date</option>
                </select>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" x-model="state.fieldForm.required" :true-value="1" :false-value="0" /> <?php echo esc_html__('Required', 'eko-sampa'); ?></label>
                <label class="flex items-center gap-2 text-sm sm:col-span-2">
                    <input type="checkbox" x-model="state.fieldForm.show_in_template" :true-value="1" :false-value="0" />
                    <?php echo esc_html__('Print / template field (uncheck for operational-only)', 'eko-sampa'); ?>
                </label>
            </div>
            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                <label class="block text-xs font-medium text-slate-600 sm:col-span-2"><?php echo esc_html__('Default value', 'eko-sampa'); ?>
                    <input class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" type="text" x-model="state.fieldForm.default_value" />
                </label>
                <label class="block text-xs font-medium text-slate-600 sm:col-span-2"><?php echo esc_html__('Placeholder (form hint)', 'eko-sampa'); ?>
                    <input class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" type="text" x-model="state.fieldForm.placeholder" />
                </label>
            </div>
            <label class="mt-2 block text-xs font-medium text-slate-600" x-show="state.fieldForm.type === 'select'">
                <?php echo esc_html__('Select options (JSON array)', 'eko-sampa'); ?>
                <textarea class="mt-1 w-full rounded border border-slate-200 px-2 py-1 font-mono text-xs" rows="3" placeholder='["Option A","Option B"]' x-model="state.fieldForm.options_json"></textarea>
            </label>
            <div class="mt-3 rounded-lg border border-slate-100 bg-slate-50/80 p-3">
                <p class="text-xs font-semibold text-slate-800"><?php echo esc_html__('Validation (optional)', 'eko-sampa'); ?></p>
                <p class="mt-1 text-[11px] leading-snug text-slate-600"><?php echo esc_html__('Rules are stored as structured JSON on the server; you edit them here without raw JSON.', 'eko-sampa'); ?></p>
                <p class="mt-2 text-[11px] font-medium text-amber-800" x-show="state.fieldForm._validationLegacyRaw" x-cloak><?php echo esc_html__('Legacy validation is stored on the server. It is kept on save unless you set rules below � then the new rules replace it.', 'eko-sampa'); ?></p>
                <div class="mt-3 space-y-2" x-show="state.fieldForm.type === 'text' || state.fieldForm.type === 'textarea'">
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label class="block text-[11px] font-medium text-slate-600"><?php echo esc_html__('Min length', 'eko-sampa'); ?>
                            <input class="mt-0.5 w-full rounded border border-slate-200 px-2 py-1 text-sm" type="number" min="0" step="1" x-model="state.fieldForm.validation.minLength" />
                        </label>
                        <label class="block text-[11px] font-medium text-slate-600"><?php echo esc_html__('Max length', 'eko-sampa'); ?>
                            <input class="mt-0.5 w-full rounded border border-slate-200 px-2 py-1 text-sm" type="number" min="0" step="1" x-model="state.fieldForm.validation.maxLength" />
                        </label>
                    </div>
                    <label class="block text-[11px] font-medium text-slate-600"><?php echo esc_html__('Pattern (regex)', 'eko-sampa'); ?>
                        <input class="mt-0.5 w-full rounded border border-slate-200 px-2 py-1 font-mono text-xs" type="text" x-model="state.fieldForm.validation.pattern" placeholder="<?php echo esc_attr__('e.g. ^[0-9]+$', 'eko-sampa'); ?>" />
                    </label>
                </div>
                <div class="mt-3 space-y-2" x-show="state.fieldForm.type === 'number'">
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label class="block text-[11px] font-medium text-slate-600"><?php echo esc_html__('Minimum', 'eko-sampa'); ?>
                            <input class="mt-0.5 w-full rounded border border-slate-200 px-2 py-1 text-sm" type="text" inputmode="decimal" x-model="state.fieldForm.validation.minimum" />
                        </label>
                        <label class="block text-[11px] font-medium text-slate-600"><?php echo esc_html__('Maximum', 'eko-sampa'); ?>
                            <input class="mt-0.5 w-full rounded border border-slate-200 px-2 py-1 text-sm" type="text" inputmode="decimal" x-model="state.fieldForm.validation.maximum" />
                        </label>
                    </div>
                </div>
                <div class="mt-3 space-y-2" x-show="state.fieldForm.type === 'date'">
                    <label class="block text-[11px] font-medium text-slate-600"><?php echo esc_html__('Format hint (optional)', 'eko-sampa'); ?>
                        <input class="mt-0.5 w-full rounded border border-slate-200 px-2 py-1 text-sm" type="text" x-model="state.fieldForm.validation.format" placeholder="<?php echo esc_attr__('e.g. date', 'eko-sampa'); ?>" />
                    </label>
                </div>
                <div class="mt-3 space-y-2" x-show="state.fieldForm.type === 'select'">
                    <label class="block text-[11px] font-medium text-slate-600"><?php echo esc_html__('Max length of selected value (optional)', 'eko-sampa'); ?>
                        <input class="mt-0.5 w-full rounded border border-slate-200 px-2 py-1 text-sm" type="number" min="0" step="1" x-model="state.fieldForm.validation.maxLength" />
                    </label>
                </div>
            </div>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <button type="button" class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm hover:bg-slate-50" @click="saveField()" x-text="state.fieldForm.id ? '<?php echo esc_js(__('Save field', 'eko-sampa')); ?>' : '<?php echo esc_js(__('Add field', 'eko-sampa')); ?>'"></button>
                <button type="button" class="rounded-lg px-3 py-1.5 text-sm text-slate-600 hover:underline" x-show="state.fieldForm.id" @click="newField()"><?php echo esc_html__('Cancel edit', 'eko-sampa'); ?></button>
            </div>
        </div>
    </div>
</div>
