<?php
/**
 * Orders CRUD, preview, print link (REST + Alpine).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

?>
<div class="mx-auto max-w-6xl space-y-6" x-data="ekoOrders" x-init="init()">
    <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-slate-900"><?php echo esc_html__('Orders', 'eko-sampa'); ?></h2>
            <p class="text-sm text-slate-500"><?php echo esc_html__('Production queue and print.', 'eko-sampa'); ?></p>
        </div>
        <div class="flex flex-wrap gap-2">
            <?php if (current_user_can('manage_options')) : ?>
                <select class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm" x-model="filterUserId" @change="page=1; loadLookups(); load()">
                    <option value=""><?php echo esc_html__('All users', 'eko-sampa'); ?></option>
                    <template x-for="u in users" :key="u.id">
                        <option :value="u.id" x-text="u.display_name + ' (' + u.id + ')'"></option>
                    </template>
                </select>
            <?php endif; ?>
            <input class="rounded-lg border border-slate-200 px-3 py-2 text-sm" type="search" x-model="q" @keydown.enter.prevent="page=1; load()" placeholder="<?php echo esc_attr__('Order ID…', 'eko-sampa'); ?>" />
            <select class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm" x-model="status" @change="page=1; load()">
                <option value=""><?php echo esc_html__('All statuses', 'eko-sampa'); ?></option>
                <option value="pending">pending</option>
                <option value="in_progress">in_progress</option>
                <option value="print_queue">print_queue</option>
                <option value="completed">completed</option>
            </select>
            <button type="button" class="rounded-lg bg-slate-900 px-3 py-2 text-sm text-white hover:bg-slate-800" @click="page=1; load()"><?php echo esc_html__('Apply', 'eko-sampa'); ?></button>
            <button type="button" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm hover:bg-slate-50" @click="reset()"><?php echo esc_html__('New', 'eko-sampa'); ?></button>
        </div>
    </div>
    <p class="text-sm text-red-600" x-show="err" x-text="err"></p>
    <p class="text-xs text-slate-500" x-show="loading" x-cloak><?php echo esc_html__('Loading…', 'eko-sampa'); ?></p>
    <div class="grid gap-6 lg:grid-cols-2">
        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs font-medium uppercase text-slate-500">
                    <tr>
                        <th class="px-4 py-2">ID</th>
                        <th class="px-4 py-2"><?php echo esc_html__('Status', 'eko-sampa'); ?></th>
                        <th class="px-4 py-2"><?php echo esc_html__('WC', 'eko-sampa'); ?></th>
                        <th class="px-4 py-2"><?php echo esc_html__('Print', 'eko-sampa'); ?></th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="r in rows" :key="r.id">
                        <tr class="hover:bg-slate-50/80">
                            <td class="px-4 py-2 font-mono text-slate-900" x-text="r.id"></td>
                            <td class="px-4 py-2 text-slate-600" x-text="r.status"></td>
                            <td class="px-4 py-2 font-mono text-xs text-slate-600" x-text="r.woo_order_id ? r.woo_order_id : '—'"></td>
                            <td class="px-4 py-2 text-slate-600" x-text="r.print_ready == 1 ? '<?php echo esc_js(__('Yes', 'eko-sampa')); ?>' : '<?php echo esc_js(__('No', 'eko-sampa')); ?>'"></td>
                            <td class="px-4 py-2 text-right whitespace-nowrap">
                                <button type="button" class="text-indigo-600 hover:underline" @click="fetchPreview(r.id)"><?php echo esc_html__('Preview', 'eko-sampa'); ?></button>
                                <a class="ml-2 text-indigo-600 hover:underline" :href="printUrl(r.id)" target="_blank"><?php echo esc_html__('Print', 'eko-sampa'); ?></a>
                                <button type="button" class="ml-2 text-slate-600 hover:underline" @click="dup(r.id)"><?php echo esc_html__('Duplicate', 'eko-sampa'); ?></button>
                                <button type="button" class="ml-2 text-indigo-600 hover:underline" @click="edit(r)"><?php echo esc_html__('Edit', 'eko-sampa'); ?></button>
                                <button type="button" class="ml-2 text-red-600 hover:underline" @click="remove(r.id)"><?php echo esc_html__('Delete', 'eko-sampa'); ?></button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 px-4 py-3 text-sm text-slate-600">
                <span><?php echo esc_html__('Page', 'eko-sampa'); ?> <span x-text="page"></span></span>
                <div class="flex gap-2">
                    <button type="button" class="rounded border border-slate-200 bg-white px-2 py-1 text-xs hover:bg-slate-50 disabled:opacity-40" @click="prevPage()" :disabled="page <= 1"><?php echo esc_html__('Previous', 'eko-sampa'); ?></button>
                    <button type="button" class="rounded border border-slate-200 bg-white px-2 py-1 text-xs hover:bg-slate-50 disabled:opacity-40" @click="nextPage()" :disabled="!hasNext"><?php echo esc_html__('Next', 'eko-sampa'); ?></button>
                </div>
            </div>
        </div>
        <div class="space-y-4">
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="text-sm font-semibold text-slate-900"><?php echo esc_html__('Order', 'eko-sampa'); ?></h3>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <?php if (current_user_can('manage_options')) : ?>
                        <label class="block text-xs font-medium text-slate-600 sm:col-span-2"><?php echo esc_html__('Owner user ID (new only)', 'eko-sampa'); ?>
                            <input class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" type="number" x-model="form.user_id" :disabled="!!form.id" />
                        </label>
                    <?php endif; ?>
                    <label class="block text-xs font-medium text-slate-600"><?php echo esc_html__('Client', 'eko-sampa'); ?>
                        <select class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" x-model="form.client_id">
                            <option value="">—</option>
                            <template x-for="c in clients" :key="c.id">
                                <option :value="c.id" x-text="c.nome + ' (' + c.id + ')'"></option>
                            </template>
                        </select>
                    </label>
                    <label class="block text-xs font-medium text-slate-600"><?php echo esc_html__('Service', 'eko-sampa'); ?>
                        <select class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" x-model="form.service_id">
                            <option value="">—</option>
                            <template x-for="s in services" :key="s.id">
                                <option :value="s.id" x-text="s.nome + ' (' + s.id + ')'"></option>
                            </template>
                        </select>
                    </label>
                    <label class="block text-xs font-medium text-slate-600 sm:col-span-2"><?php echo esc_html__('Template', 'eko-sampa'); ?>
                        <select class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" x-model="form.template_id">
                            <option value="">—</option>
                            <template x-for="t in templates" :key="t.id">
                                <option :value="t.id" x-text="t.nome + ' (' + t.id + ')'"></option>
                            </template>
                        </select>
                    </label>
                    <label class="block text-xs font-medium text-slate-600"><?php echo esc_html__('Status', 'eko-sampa'); ?>
                        <select class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" x-model="form.status">
                            <option value="pending">pending</option>
                            <option value="in_progress">in_progress</option>
                            <option value="print_queue">print_queue</option>
                            <option value="completed">completed</option>
                        </select>
                    </label>
                    <label class="block text-xs font-medium text-slate-600"><?php echo esc_html__('WooCommerce order ID', 'eko-sampa'); ?>
                        <input class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" type="number" min="0" x-model.number="form.woo_order_id" placeholder="<?php echo esc_attr__('Optional link', 'eko-sampa'); ?>" />
                    </label>
                    <label class="flex items-center gap-2 text-sm text-slate-700 sm:col-span-2">
                        <input type="checkbox" x-model="form.print_ready" :true-value="1" :false-value="0" />
                        <?php echo esc_html__('Print ready', 'eko-sampa'); ?>
                    </label>
                </div>
                <div class="mt-4 border-t border-slate-100 pt-3">
                    <p class="text-xs font-medium text-slate-600"><?php echo esc_html__('Dynamic data (slug → value)', 'eko-sampa'); ?></p>
                    <pre class="mt-1 max-h-24 overflow-auto rounded bg-slate-50 p-2 text-xs" x-text="JSON.stringify(form.dynamic_data_json || {}, null, 2)"></pre>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <input class="rounded border border-slate-200 px-2 py-1 text-xs" type="text" x-model="dynKeys" placeholder="slug" />
                        <input class="rounded border border-slate-200 px-2 py-1 text-xs" type="text" x-model="dynVal" placeholder="<?php echo esc_attr__('value', 'eko-sampa'); ?>" />
                        <button type="button" class="rounded bg-slate-200 px-2 py-1 text-xs" @click="setDyn()"><?php echo esc_html__('Add', 'eko-sampa'); ?></button>
                    </div>
                </div>
                <button type="button" class="mt-4 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700" @click="save()"><?php echo esc_html__('Save', 'eko-sampa'); ?></button>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="text-sm font-semibold text-slate-900"><?php echo esc_html__('Live preview', 'eko-sampa'); ?></h3>
                <iframe
                    class="mt-2 h-[min(24rem,50vh)] w-full min-h-[8rem] rounded border border-slate-100 bg-white"
                    sandbox=""
                    referrerpolicy="no-referrer"
                    title="<?php echo esc_attr__('Order preview', 'eko-sampa'); ?>"
                    :src="previewFrameSrc"
                ></iframe>
            </div>
        </div>
    </div>
</div>
