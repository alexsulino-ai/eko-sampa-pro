<?php
/**
 * Templates CRUD, duplicate, link to editor (REST + Alpine).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

?>
<div class="mx-auto max-w-6xl space-y-6" x-data="ekoTemplates" x-init="init()">
    <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-slate-900"><?php echo esc_html__('Templates', 'eko-sampa'); ?></h2>
            <p class="text-sm text-slate-500"><?php echo esc_html__('Design layouts in the visual editor.', 'eko-sampa'); ?></p>
        </div>
        <div class="flex flex-wrap gap-2">
            <?php if (current_user_can('manage_options')) : ?>
                <select class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm" x-model="filterUserId" @change="load()">
                    <option value=""><?php echo esc_html__('All users', 'eko-sampa'); ?></option>
                    <template x-for="u in users" :key="u.id">
                        <option :value="u.id" x-text="u.display_name + ' (' + u.id + ')'"></option>
                    </template>
                </select>
            <?php endif; ?>
            <input class="rounded-lg border border-slate-200 px-3 py-2 text-sm" type="search" x-model="q" @keydown.enter.prevent="load()" placeholder="<?php echo esc_attr__('Search…', 'eko-sampa'); ?>" />
            <input class="rounded-lg border border-slate-200 px-3 py-2 text-sm" type="text" x-model="cat" @change="load()" placeholder="<?php echo esc_attr__('Category', 'eko-sampa'); ?>" />
            <button type="button" class="rounded-lg bg-slate-900 px-3 py-2 text-sm text-white hover:bg-slate-800" @click="load()"><?php echo esc_html__('Apply', 'eko-sampa'); ?></button>
            <button type="button" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm hover:bg-slate-50" @click="reset()"><?php echo esc_html__('New', 'eko-sampa'); ?></button>
        </div>
    </div>
    <p class="text-sm text-red-600" x-show="err" x-text="err"></p>
    <div class="grid gap-6 lg:grid-cols-2">
        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs font-medium uppercase text-slate-500">
                    <tr>
                        <th class="px-4 py-2"><?php echo esc_html__('Name', 'eko-sampa'); ?></th>
                        <th class="px-4 py-2"><?php echo esc_html__('Category', 'eko-sampa'); ?></th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="r in rows" :key="r.id">
                        <tr class="hover:bg-slate-50/80">
                            <td class="px-4 py-2 font-medium text-slate-900" x-text="r.nome"></td>
                            <td class="px-4 py-2 text-slate-600" x-text="r.categoria"></td>
                            <td class="px-4 py-2 text-right whitespace-nowrap">
                                <a class="text-indigo-600 hover:underline" :href="editorUrl(r.id)"><?php echo esc_html__('Editor', 'eko-sampa'); ?></a>
                                <button type="button" class="ml-2 text-slate-600 hover:underline" @click="preview(r.id)"><?php echo esc_html__('Preview', 'eko-sampa'); ?></button>
                                <button type="button" class="ml-2 text-slate-600 hover:underline" @click="duplicate(r.id)"><?php echo esc_html__('Duplicate', 'eko-sampa'); ?></button>
                                <button type="button" class="ml-2 text-indigo-600 hover:underline" @click="edit(r)"><?php echo esc_html__('Edit', 'eko-sampa'); ?></button>
                                <button type="button" class="ml-2 text-red-600 hover:underline" @click="remove(r.id)"><?php echo esc_html__('Delete', 'eko-sampa'); ?></button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-sm font-semibold text-slate-900"><?php echo esc_html__('Metadata', 'eko-sampa'); ?></h3>
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <?php if (current_user_can('manage_options')) : ?>
                    <label class="block text-xs font-medium text-slate-600 sm:col-span-2"><?php echo esc_html__('Owner user ID (new only)', 'eko-sampa'); ?>
                        <input class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" type="number" x-model="form.user_id" :disabled="!!form.id" />
                    </label>
                <?php endif; ?>
                <label class="block text-xs font-medium text-slate-600 sm:col-span-2"><?php echo esc_html__('Name', 'eko-sampa'); ?> *
                    <input class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" type="text" x-model="form.nome" />
                </label>
                <label class="block text-xs font-medium text-slate-600"><?php echo esc_html__('Category', 'eko-sampa'); ?>
                    <input class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" type="text" x-model="form.categoria" />
                </label>
                <label class="block text-xs font-medium text-slate-600 sm:col-span-2"><?php echo esc_html__('Description', 'eko-sampa'); ?>
                    <textarea class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" rows="2" x-model="form.descricao"></textarea>
                </label>
                <label class="block text-xs font-medium text-slate-600"><?php echo esc_html__('Width mm', 'eko-sampa'); ?>
                    <input class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" type="number" x-model.number="form.width_mm" />
                </label>
                <label class="block text-xs font-medium text-slate-600"><?php echo esc_html__('Height mm', 'eko-sampa'); ?>
                    <input class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" type="number" x-model.number="form.height_mm" />
                </label>
                <label class="block text-xs font-medium text-slate-600 sm:col-span-2"><?php echo esc_html__('Linked service ID', 'eko-sampa'); ?>
                    <input class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" type="number" x-model.number="form.service_id" />
                </label>
            </div>
            <button type="button" class="mt-4 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700" @click="save()"><?php echo esc_html__('Save', 'eko-sampa'); ?></button>
        </div>
    </div>
</div>
