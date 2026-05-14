<?php
/**
 * Services + dynamic fields (REST + Alpine).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

?>
<div class="mx-auto max-w-6xl space-y-6" x-data="window.ekoServicesFactory()" x-init="init()">
    <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-slate-900"><?php echo esc_html__('Services', 'eko-sampa'); ?></h2>
            <p class="text-sm text-slate-500"><?php echo esc_html__('Catalog and dynamic fields for orders.', 'eko-sampa'); ?></p>
        </div>
        <div class="flex flex-wrap gap-2">
            <?php if (current_user_can('manage_options')) : ?>
                <select class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm" x-model="state.filterUserId" @change="state.page=1; load()">
                    <option value=""><?php echo esc_html__('All users', 'eko-sampa'); ?></option>
                    <template x-for="u in state.users" :key="u.id">
                        <option :value="u.id" x-text="u.display_name + ' (' + u.id + ')'"></option>
                    </template>
                </select>
            <?php endif; ?>
            <input class="rounded-lg border border-slate-200 px-3 py-2 text-sm" type="search" x-model="state.q" @keydown.enter.prevent="state.page=1; load()" placeholder="<?php echo esc_attr__('Search…', 'eko-sampa'); ?>" />
            <button type="button" class="rounded-lg bg-slate-900 px-3 py-2 text-sm text-white hover:bg-slate-800" @click="state.page=1; load()"><?php echo esc_html__('Search', 'eko-sampa'); ?></button>
            <button type="button" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm hover:bg-slate-50" @click="reset()"><?php echo esc_html__('New', 'eko-sampa'); ?></button>
        </div>
    </div>
    <p class="text-sm text-red-600" x-show="error" x-text="error || ''"></p>
    <p class="text-xs text-slate-500" x-show="loading" x-cloak><?php echo esc_html__('Loading…', 'eko-sampa'); ?></p>
    <div class="grid gap-6 lg:grid-cols-2">
        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs font-medium uppercase text-slate-500">
                    <tr>
                        <th class="px-4 py-2"><?php echo esc_html__('Name', 'eko-sampa'); ?></th>
                        <th class="px-4 py-2"><?php echo esc_html__('Global', 'eko-sampa'); ?></th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="r in state.rows" :key="r.id">
                        <tr class="hover:bg-slate-50/80">
                            <td class="px-4 py-2 font-medium text-slate-900" x-text="r.nome"></td>
                            <td class="px-4 py-2 text-slate-600" x-text="r.is_global == 1 ? '<?php echo esc_js(__('Yes', 'eko-sampa')); ?>' : '<?php echo esc_js(__('No', 'eko-sampa')); ?>'"></td>
                            <td class="px-4 py-2 text-right">
                                <button type="button" class="text-indigo-600 hover:underline" @click="edit(r)"><?php echo esc_html__('Edit', 'eko-sampa'); ?></button>
                                <button type="button" class="ml-2 text-red-600 hover:underline" @click="remove(r.id)"><?php echo esc_html__('Delete', 'eko-sampa'); ?></button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 px-4 py-3 text-sm text-slate-600">
                <span><?php echo esc_html__('Page', 'eko-sampa'); ?> <span x-text="state.page"></span></span>
                <div class="flex gap-2">
                    <button type="button" class="rounded border border-slate-200 bg-white px-2 py-1 text-xs hover:bg-slate-50 disabled:opacity-40" @click="prevPage()" :disabled="state.page <= 1"><?php echo esc_html__('Previous', 'eko-sampa'); ?></button>
                    <button type="button" class="rounded border border-slate-200 bg-white px-2 py-1 text-xs hover:bg-slate-50 disabled:opacity-40" @click="nextPage()" :disabled="!state.hasNext"><?php echo esc_html__('Next', 'eko-sampa'); ?></button>
                </div>
            </div>
        </div>
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
                    <button type="button" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700" @click="save()"><?php echo esc_html__('Save service', 'eko-sampa'); ?></button>
                </div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm" x-show="state.form.id">
                <h3 class="text-sm font-semibold text-slate-900"><?php echo esc_html__('Dynamic fields', 'eko-sampa'); ?></h3>
                <ul class="mt-2 divide-y divide-slate-100 text-sm" x-ref="fieldSortRoot" x-show="state.fields.length">
                    <template x-for="f in state.fields" :key="f.id">
                        <li class="flex items-center gap-2 py-2" :data-field-id="f.id">
                            <button
                                type="button"
                                class="cursor-grab rounded border border-transparent px-1 text-slate-400 hover:border-slate-200 hover:bg-slate-50 hover:text-slate-600"
                                data-eko-field-drag="1"
                                title="<?php echo esc_attr__('Drag to reorder', 'eko-sampa'); ?>"
                            >⠿</button>
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
                    <textarea
                        class="mt-1 w-full rounded border border-slate-200 px-2 py-1 font-mono text-xs"
                        rows="3"
                        placeholder='["Option A","Option B"]'
                        x-model="state.fieldForm.options_json"
                    ></textarea>
                </label>
                <label class="mt-2 block text-xs font-medium text-slate-600">
                    <?php echo esc_html__('Validation rules (JSON, optional; reserved for future checks)', 'eko-sampa'); ?>
                    <textarea
                        class="mt-1 w-full rounded border border-slate-200 px-2 py-1 font-mono text-xs"
                        rows="2"
                        placeholder="{}"
                        x-model="state.fieldForm.validation_rules_json"
                    ></textarea>
                </label>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm hover:bg-slate-50" @click="saveField()" x-text="state.fieldForm.id ? '<?php echo esc_js(__('Save field', 'eko-sampa')); ?>' : '<?php echo esc_js(__('Add field', 'eko-sampa')); ?>'"></button>
                    <button type="button" class="rounded-lg px-3 py-1.5 text-sm text-slate-600 hover:underline" x-show="state.fieldForm.id" @click="newField()"><?php echo esc_html__('Cancel edit', 'eko-sampa'); ?></button>
                </div>
            </div>
        </div>
    </div>
</div>
