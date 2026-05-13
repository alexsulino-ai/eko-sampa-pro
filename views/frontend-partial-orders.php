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
<div class="mx-auto max-w-6xl space-y-6" x-data="window.ekoOrdersFactory()" x-init="init()">
    <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-slate-900"><?php echo esc_html__('Orders', 'eko-sampa'); ?></h2>
            <p class="text-sm text-slate-500"><?php echo esc_html__('Production queue and print.', 'eko-sampa'); ?></p>
        </div>
        <div class="flex flex-wrap gap-2">
            <?php if (current_user_can('manage_options')) : ?>
                <select class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm" x-model="state.filterUserId" @change="state.page=1; loadLookups(); load()">
                    <option value=""><?php echo esc_html__('All users', 'eko-sampa'); ?></option>
                    <template x-for="u in state.users" :key="u.id">
                        <option :value="u.id" x-text="u.display_name + ' (' + u.id + ')'"></option>
                    </template>
                </select>
            <?php endif; ?>
            <input class="rounded-lg border border-slate-200 px-3 py-2 text-sm" type="search" x-model="state.q" @keydown.enter.prevent="state.page=1; load()" placeholder="<?php echo esc_attr__('Order ID…', 'eko-sampa'); ?>" />
            <select class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm" x-model="state.status" @change="state.page=1; load()">
                <option value=""><?php echo esc_html__('All statuses', 'eko-sampa'); ?></option>
                <option value="pending">pending</option>
                <option value="in_progress">in_progress</option>
                <option value="print_queue">print_queue</option>
                <option value="completed">completed</option>
            </select>
            <button type="button" class="rounded-lg bg-slate-900 px-3 py-2 text-sm text-white hover:bg-slate-800" @click="state.page=1; load()"><?php echo esc_html__('Apply', 'eko-sampa'); ?></button>
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
                        <th class="px-4 py-2">ID</th>
                        <th class="px-4 py-2"><?php echo esc_html__('Status', 'eko-sampa'); ?></th>
                        <th class="px-4 py-2"><?php echo esc_html__('WC', 'eko-sampa'); ?></th>
                        <th class="px-4 py-2"><?php echo esc_html__('Print', 'eko-sampa'); ?></th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="r in state.rows" :key="r.id">
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
                <span><?php echo esc_html__('Page', 'eko-sampa'); ?> <span x-text="state.page"></span></span>
                <div class="flex gap-2">
                    <button type="button" class="rounded border border-slate-200 bg-white px-2 py-1 text-xs hover:bg-slate-50 disabled:opacity-40" @click="prevPage()" :disabled="state.page <= 1"><?php echo esc_html__('Previous', 'eko-sampa'); ?></button>
                    <button type="button" class="rounded border border-slate-200 bg-white px-2 py-1 text-xs hover:bg-slate-50 disabled:opacity-40" @click="nextPage()" :disabled="!state.hasNext"><?php echo esc_html__('Next', 'eko-sampa'); ?></button>
                </div>
            </div>
        </div>
        <div class="space-y-4">
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="text-sm font-semibold text-slate-900"><?php echo esc_html__('Order', 'eko-sampa'); ?></h3>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <?php if (current_user_can('manage_options')) : ?>
                        <label class="block text-xs font-medium text-slate-600 sm:col-span-2"><?php echo esc_html__('Owner user ID (new only)', 'eko-sampa'); ?>
                            <input class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" type="number" x-model="state.form.user_id" :disabled="!!state.form.id" />
                        </label>
                    <?php endif; ?>
                    <label class="block text-xs font-medium text-slate-600"><?php echo esc_html__('Client', 'eko-sampa'); ?>
                        <select class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" x-model="state.form.client_id" @change="schedulePreviewDraft()">
                            <option value="">—</option>
                            <template x-for="c in state.clients" :key="c.id">
                                <option :value="c.id" x-text="c.nome + ' (' + c.id + ')'"></option>
                            </template>
                        </select>
                    </label>
                    <label class="block text-xs font-medium text-slate-600"><?php echo esc_html__('Service', 'eko-sampa'); ?>
                        <select class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" x-model="state.form.service_id" @change="onServiceChange()">
                            <option value="">—</option>
                            <template x-for="s in state.services" :key="s.id">
                                <option :value="s.id" x-text="s.nome + ' (' + s.id + ')'"></option>
                            </template>
                        </select>
                    </label>
                    <label class="block text-xs font-medium text-slate-600 sm:col-span-2"><?php echo esc_html__('Template', 'eko-sampa'); ?>
                        <select class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" x-model="state.form.template_id" @change="schedulePreviewDraft()">
                            <option value="">—</option>
                            <template x-for="t in state.templates" :key="t.id">
                                <option :value="t.id" x-text="t.nome + ' (' + t.id + ')'"></option>
                            </template>
                        </select>
                    </label>
                    <label class="block text-xs font-medium text-slate-600"><?php echo esc_html__('Status', 'eko-sampa'); ?>
                        <select class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" x-model="state.form.status">
                            <option value="pending">pending</option>
                            <option value="in_progress">in_progress</option>
                            <option value="print_queue">print_queue</option>
                            <option value="completed">completed</option>
                        </select>
                    </label>
                    <label class="block text-xs font-medium text-slate-600"><?php echo esc_html__('WooCommerce order ID', 'eko-sampa'); ?>
                        <input class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" type="number" min="0" x-model.number="state.form.woo_order_id" placeholder="<?php echo esc_attr__('Optional link', 'eko-sampa'); ?>" />
                    </label>
                    <label class="flex items-center gap-2 text-sm text-slate-700 sm:col-span-2">
                        <input type="checkbox" x-model="state.form.print_ready" :true-value="1" :false-value="0" />
                        <?php echo esc_html__('Print ready', 'eko-sampa'); ?>
                    </label>
                </div>
                <div class="mt-4 border-t border-slate-100 pt-3" x-show="state.serviceFields.length">
                    <p class="text-xs font-medium text-slate-600"><?php echo esc_html__('Service fields (placeholders {{slug}})', 'eko-sampa'); ?></p>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        <template x-for="f in state.serviceFields" :key="f.id">
                            <div class="min-w-0 sm:col-span-2">
                                <label class="block text-xs font-medium text-slate-600">
                                    <span x-text="f.label + (parseInt(String(f.required), 10) ? ' *' : '')"></span>
                                    <span class="ml-1 font-mono text-slate-400" x-text="'{{' + f.slug + '}}'"></span>
                                </label>
                                <template x-if="f.type === 'textarea'">
                                    <textarea class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" rows="2" x-model="state.form.dynamic_data_json[f.slug]"></textarea>
                                </template>
                                <template x-if="f.type === 'select'">
                                    <select class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" x-model="state.form.dynamic_data_json[f.slug]">
                                        <option value="">—</option>
                                        <template x-for="(opt, idx) in fieldSelectOptions(f)" :key="f.id + '-' + idx + '-' + opt.value">
                                            <option :value="opt.value" x-text="opt.label"></option>
                                        </template>
                                    </select>
                                </template>
                                <template x-if="f.type !== 'textarea' && f.type !== 'select'">
                                    <input
                                        class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm"
                                        :type="f.type === 'number' ? 'number' : (f.type === 'date' ? 'date' : 'text')"
                                        x-model="state.form.dynamic_data_json[f.slug]"
                                    />
                                </template>
                            </div>
                        </template>
                    </div>
                </div>
                <div class="mt-3 border-t border-slate-100 pt-3" x-show="!state.serviceFields.length">
                    <p class="text-xs text-slate-500"><?php echo esc_html__('Select a service to load dynamic fields.', 'eko-sampa'); ?></p>
                </div>
                <button type="button" class="mt-4 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700" @click="save()"><?php echo esc_html__('Save', 'eko-sampa'); ?></button>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <?php require EKO_SAMPA_PLUGIN_DIR . 'views/editor-canvas-order-preview.php'; ?>
                <iframe
                    class="mt-2 h-[min(24rem,50vh)] w-full min-h-[8rem] rounded border border-slate-100 bg-white"
                    x-show="state.previewSrcdoc"
                    x-cloak
                    sandbox="allow-same-origin"
                    referrerpolicy="no-referrer"
                    title="<?php echo esc_attr__('Order preview (HTML fallback)', 'eko-sampa'); ?>"
                    x-bind:srcdoc="state.previewSrcdoc"
                ></iframe>
            </div>
        </div>
    </div>
</div>
