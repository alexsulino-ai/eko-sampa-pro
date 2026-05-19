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
<div class="mx-auto max-w-4xl space-y-6" x-data="window.ekoTemplatesFactory()" x-init="init()" @keydown.escape.window="closeZoom()">
    <?php require EKO_SAMPA_PLUGIN_DIR . 'views/partials/crud-nav.php'; ?>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-lg font-semibold text-slate-900" x-text="state.form.id ? '<?php echo esc_js(__('Edit template', 'eko-sampa')); ?>' : '<?php echo esc_js(__('New template', 'eko-sampa')); ?>'"></h2>
        <div class="flex flex-wrap gap-2">
            <button
                type="button"
                class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50"
                x-show="state.form.id && canOrderCreate() && canCreateOrderFrom(state.form)"
                :disabled="isCreatingOrder(state.form.id)"
                @click="createOrderFromTemplate(state.form)"
            ><?php echo esc_html__('Create order', 'eko-sampa'); ?></button>
            <a class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm hover:bg-slate-50" :href="state.form.id ? viewUrl(state.form.id) : crudUrls.list"><?php echo esc_html__('Cancel', 'eko-sampa'); ?></a>
        </div>
    </div>
    <p class="text-sm text-red-600" x-show="error" x-text="error || ''"></p>
    <p class="text-xs text-slate-500" x-show="loading" x-cloak><?php echo esc_html__('Loading…', 'eko-sampa'); ?></p>
    <div class="grid gap-6 lg:grid-cols-[minmax(0,16rem)_1fr]" x-show="state.form.id" x-cloak>
        <?php require EKO_SAMPA_PLUGIN_DIR . 'views/partials/template-preview-block.php'; ?>
    </div>
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
            <label class="block text-xs font-medium text-slate-600 sm:col-span-2"><?php echo esc_html__('Client (optional)', 'eko-sampa'); ?>
                <select class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" x-model.number="state.form.client_id">
                    <option value="0"><?php echo esc_html__('Anonymous client', 'eko-sampa'); ?></option>
                    <template x-for="c in state.clients" :key="'tpl-client-' + c.id">
                        <option :value="c.id" x-text="c.nome + ' (' + c.id + ')'"></option>
                    </template>
                </select>
            </label>
            <label class="block text-xs font-medium text-slate-600 sm:col-span-2"><?php echo esc_html__('Linked service ID', 'eko-sampa'); ?>
                <input class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" type="number" x-model.number="state.form.service_id" />
            </label>
            <label class="block text-xs font-medium text-slate-600 sm:col-span-2"><?php echo esc_html__('WooCommerce product ID', 'eko-sampa'); ?>
                <input class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" type="number" min="0" x-model.number="state.form.product_id" />
            </label>
            <?php if (current_user_can('manage_options')) : ?>
                <label class="block text-xs font-medium text-slate-600 sm:col-span-2"><?php echo esc_html__('Template role', 'eko-sampa'); ?>
                    <select class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" x-model="state.form.template_type" @change="onTemplateTypeChanged()">
                        <option value="user"><?php echo esc_html__('User template (saved copy)', 'eko-sampa'); ?></option>
                        <option value="master"><?php echo esc_html__('Master (official, immutable for non-admins)', 'eko-sampa'); ?></option>
                    </select>
                </label>
            <?php endif; ?>
            <template x-if="String(state.form.template_type || 'user') === 'master'">
                <label class="flex items-start gap-2 text-xs font-medium text-slate-600 sm:col-span-2">
                    <input
                        type="checkbox"
                        class="mt-0.5 rounded border-slate-300"
                        :checked="templatePublicHomeChecked()"
                        @change="setTemplatePublicHomeVisible($event.target.checked)"
                    />
                    <span>
                        <?php echo esc_html__('Show on public home (/eko-sampa/) without login', 'eko-sampa'); ?>
                        <span class="mt-0.5 block font-normal text-slate-500"><?php echo esc_html__('Requires “Allow personalization” below. Only Master templates can appear in the public catalog.', 'eko-sampa'); ?></span>
                    </span>
                </label>
            </template>
            <template x-if="String(state.form.template_type || 'user') !== 'master'">
                <label class="flex items-center gap-2 text-xs font-medium text-slate-600 sm:col-span-2">
                    <input
                        type="checkbox"
                        class="rounded border-slate-300"
                        :checked="!!parseInt(String(state.form.is_public || 0), 10)"
                        @change="state.form.is_public = $event.target.checked ? 1 : 0"
                    />
                    <span><?php echo esc_html__('Public catalog (internal list / legacy visibility)', 'eko-sampa'); ?></span>
                </label>
            </template>
            <label class="flex items-center gap-2 text-xs font-medium text-slate-600 sm:col-span-2">
                <input
                    type="checkbox"
                    class="rounded border-slate-300"
                    :checked="!!parseInt(String(state.form.allow_personalization !== undefined ? state.form.allow_personalization : 1), 10)"
                    @change="state.form.allow_personalization = $event.target.checked ? 1 : 0"
                />
                <span><?php echo esc_html__('Allow “Use template” (fork working session)', 'eko-sampa'); ?></span>
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

    <template x-teleport="body">
        <div class="eko-templates-zoom" x-show="zoom.open" x-cloak x-transition.opacity @click.self="closeZoom()">
            <div class="eko-templates-zoom__backdrop" @click="closeZoom()"></div>
            <div class="eko-templates-zoom__panel" role="dialog" aria-modal="true" :aria-label="zoom.title">
                <button type="button" class="eko-templates-zoom__close" @click="closeZoom()" aria-label="<?php echo esc_attr__('Close', 'eko-sampa'); ?>">&times;</button>
                <img class="eko-templates-zoom__img" :src="zoom.src" :alt="zoom.title" />
            </div>
        </div>
    </template>
</div>
