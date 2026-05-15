<?php
/**
 * Services � list only.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$new_url = Eko_Sampa_Frontend_Router::get_resource_url('services', 'new');

?>
<div class="mx-auto max-w-6xl space-y-6" x-data="window.ekoServicesFactory()" x-init="init()">
    <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div class="space-y-1">
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
            <input class="rounded-lg border border-slate-200 px-3 py-2 text-sm" type="search" x-model="state.q" @keydown.enter.prevent="state.page=1; load()" placeholder="<?php echo esc_attr__('Search�', 'eko-sampa'); ?>" />
            <button type="button" class="rounded-lg bg-slate-900 px-3 py-2 text-sm text-white hover:bg-slate-800" @click="state.page=1; load()"><?php echo esc_html__('Search', 'eko-sampa'); ?></button>
            <a class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700" href="<?php echo esc_url($new_url); ?>"><?php echo esc_html__('New service', 'eko-sampa'); ?></a>
        </div>
    </div>
    <p class="text-sm text-red-600" x-show="error" x-text="error || ''"></p>
    <p class="text-xs text-slate-500" x-show="loading" x-cloak><?php echo esc_html__('Loading�', 'eko-sampa'); ?></p>
    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-medium uppercase text-slate-500">
                <tr>
                    <th class="px-4 py-3"><?php echo esc_html__('Name', 'eko-sampa'); ?></th>
                    <th class="px-4 py-3"><?php echo esc_html__('Global', 'eko-sampa'); ?></th>
                    <th class="px-4 py-3 text-right"><?php echo esc_html__('Actions', 'eko-sampa'); ?></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <template x-for="r in state.rows" :key="r.id">
                    <tr class="hover:bg-slate-50/80">
                        <td class="px-4 py-3 font-medium text-slate-900" x-text="r.nome"></td>
                        <td class="px-4 py-3 text-slate-600" x-text="r.is_global == 1 ? '<?php echo esc_js(__('Yes', 'eko-sampa')); ?>' : '<?php echo esc_js(__('No', 'eko-sampa')); ?>'"></td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <?php
                            eko_sampa_crud_actions_render(
                                [
                                    ['type' => 'view', 'href' => 'viewUrl(r.id)'],
                                    ['type' => 'edit', 'href' => 'editUrl(r.id)'],
                                    ['type' => 'delete', 'click' => 'remove(r.id)'],
                                ]
                            );
                            ?>
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
</div>
