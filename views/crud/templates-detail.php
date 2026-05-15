<?php
/**
 * Templates — read-only detail.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$list_url = Eko_Sampa_Frontend_Router::get_resource_url('templates', 'list');
$crud_nav = [
    'label'   => __('Templates', 'eko-sampa'),
    'listUrl' => $list_url,
    'items'   => [['label' => __('Details', 'eko-sampa'), 'url' => '']],
];

?>
<div class="mx-auto max-w-4xl space-y-6" x-data="window.ekoTemplatesFactory()" x-init="init()" @keydown.escape.window="closeZoom()">
    <?php require EKO_SAMPA_PLUGIN_DIR . 'views/partials/crud-nav.php'; ?>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-lg font-semibold text-slate-900" x-text="state.record.nome || '<?php echo esc_js(__('Template', 'eko-sampa')); ?>'"></h2>
        <div class="flex flex-wrap gap-2">
            <a class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm hover:bg-slate-50" :href="crudUrls.list"><?php echo esc_html__('Back to list', 'eko-sampa'); ?></a>
            <a class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700" :href="editUrl(state.record.id)" x-show="state.record.id"><?php echo esc_html__('Edit', 'eko-sampa'); ?></a>
            <a class="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm text-indigo-800 hover:bg-indigo-100" :href="editorUrl(state.record.id)" x-show="state.record.id"><?php echo esc_html__('Open editor', 'eko-sampa'); ?></a>
            <button
                type="button"
                class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50"
                x-show="state.record.id && canOrderCreate() && canCreateOrderFrom(state.record)"
                :disabled="isCreatingOrder(state.record.id)"
                @click="createOrderFromTemplate(state.record)"
            ><?php echo esc_html__('Create order', 'eko-sampa'); ?></button>
        </div>
    </div>
    <p class="text-sm text-red-600" x-show="error" x-text="error || ''"></p>
    <p class="text-xs text-slate-500" x-show="loading" x-cloak><?php echo esc_html__('Loading…', 'eko-sampa'); ?></p>
    <div class="grid gap-6 lg:grid-cols-2" x-show="state.record.id" x-cloak>
        <?php require EKO_SAMPA_PLUGIN_DIR . 'views/partials/template-preview-block.php'; ?>
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-sm font-semibold text-slate-900"><?php echo esc_html__('Metadata', 'eko-sampa'); ?></h3>
            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                <div><dt class="text-xs font-medium uppercase text-slate-500">ID</dt><dd class="mt-1 font-mono" x-text="state.record.id"></dd></div>
                <div><dt class="text-xs font-medium uppercase text-slate-500"><?php echo esc_html__('Category', 'eko-sampa'); ?></dt><dd class="mt-1" x-text="state.record.categoria || '—'"></dd></div>
                <div class="sm:col-span-2"><dt class="text-xs font-medium uppercase text-slate-500"><?php echo esc_html__('Description', 'eko-sampa'); ?></dt><dd class="mt-1 whitespace-pre-wrap" x-text="state.record.descricao || '—'"></dd></div>
                <div><dt class="text-xs font-medium uppercase text-slate-500"><?php echo esc_html__('Size (mm)', 'eko-sampa'); ?></dt><dd class="mt-1" x-text="(state.record.width_mm || '—') + ' x ' + (state.record.height_mm || '—')"></dd></div>
                <div><dt class="text-xs font-medium uppercase text-slate-500"><?php echo esc_html__('Client', 'eko-sampa'); ?></dt><dd class="mt-1" x-text="state.record.client_id ? state.record.client_id : '<?php echo esc_js(__('Anonymous', 'eko-sampa')); ?>'"></dd></div>
                <div><dt class="text-xs font-medium uppercase text-slate-500"><?php echo esc_html__('Service ID', 'eko-sampa'); ?></dt><dd class="mt-1 font-mono" x-text="state.record.service_id || '—'"></dd></div>
                <div><dt class="text-xs font-medium uppercase text-slate-500"><?php echo esc_html__('Product ID', 'eko-sampa'); ?></dt><dd class="mt-1 font-mono" x-text="state.record.product_id || '—'"></dd></div>
            </dl>
            <p class="mt-4 text-xs text-slate-500" x-show="state.record.preview_image">
                <span class="font-medium"><?php echo esc_html__('Preview image:', 'eko-sampa'); ?></span>
                <a class="text-indigo-600 hover:underline break-all" :href="state.record.preview_image" x-text="state.record.preview_image" target="_blank" rel="noopener"></a>
            </p>
        </div>
        <div class="space-y-4">
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="text-sm font-semibold text-slate-900"><?php echo esc_html__('Structure', 'eko-sampa'); ?></h3>
                <p class="mt-2 text-sm text-slate-700">
                    <?php echo esc_html__('Canvas elements:', 'eko-sampa'); ?>
                    <span class="font-mono font-medium" x-text="state.elementCount"></span>
                </p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="text-sm font-semibold text-slate-900"><?php echo esc_html__('Variables / placeholders', 'eko-sampa'); ?></h3>
                <ul class="mt-2 list-inside list-disc text-sm text-slate-700" x-show="state.placeholders.length">
                    <template x-for="(ph, i) in state.placeholders" :key="'ph-' + i">
                        <li class="font-mono text-xs" x-text="typeof ph === 'string' ? ph : (ph.key || ph.slug || JSON.stringify(ph))"></li>
                    </template>
                </ul>
                <p class="mt-2 text-xs text-slate-500" x-show="!state.placeholders.length"><?php echo esc_html__('No placeholders detected.', 'eko-sampa'); ?></p>
            </div>
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
