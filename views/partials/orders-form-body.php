<?php
/**
 * Shared order form fields (create / edit).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

?>
<h3 class="text-sm font-semibold text-slate-900"><?php echo esc_html__('Order', 'eko-sampa'); ?></h3>
<div class="mt-3 grid gap-3 sm:grid-cols-2">
    <?php if (current_user_can('manage_options')) : ?>
        <label class="block text-xs font-medium text-slate-600 sm:col-span-2"><?php echo esc_html__('Owner user ID (new only)', 'eko-sampa'); ?>
            <input class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" type="number" x-model="state.form.user_id" :disabled="!!state.form.id" />
        </label>
    <?php endif; ?>
    <label class="block text-xs font-medium text-slate-600"><?php echo esc_html__('Client', 'eko-sampa'); ?>
        <select class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" x-model="state.form.client_id" @change="schedulePreviewDraft()">
            <option value="0"><?php echo esc_html__('Anonymous client', 'eko-sampa'); ?></option>
            <template x-for="c in state.clients" :key="c.id">
                <option :value="c.id" x-text="c.nome + ' (' + c.id + ')'"></option>
            </template>
        </select>
    </label>
    <label class="block text-xs font-medium text-slate-600"><?php echo esc_html__('Service', 'eko-sampa'); ?>
        <select
            class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm"
            x-model="state.form.service_id"
            @change="onServiceChange()"
            :disabled="state.form.service_is_recovered"
        >
            <option value="">—</option>
            <template x-for="s in state.services" :key="s.id">
                <option :value="s.id" x-text="s.nome + ' (' + s.id + ')'"></option>
            </template>
        </select>
        <span class="mt-1 block text-[11px] text-amber-800" x-show="state.form.service_is_recovered" x-cloak>
            <?php echo esc_html__('Legacy recovered service: fields come from the template layout, not the services catalog.', 'eko-sampa'); ?>
        </span>
    </label>
    <label class="block text-xs font-medium text-slate-600 sm:col-span-2"><?php echo esc_html__('Template', 'eko-sampa'); ?>
        <select class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" x-model="state.form.template_id">
            <option value="">—</option>
            <template x-for="(t, idx) in state.templates" :key="orderTemplateSelectKey(t, idx)">
                <option :value="t.id" x-text="(t.nome || '') + ' (' + (t.id != null ? t.id : '') + ')'"></option>
            </template>
        </select>
        <span class="mt-1 block text-[11px] text-slate-500" x-show="parseInt(String(state.form.service_id || 0), 10) > 0 && !state.templatesFilterBroadened" x-cloak>
            <?php echo esc_html__('Showing templates linked to this service (or with no service).', 'eko-sampa'); ?>
        </span>
        <span class="mt-1 block text-[11px] text-amber-800" x-show="parseInt(String(state.form.service_id || 0), 10) > 0 && state.templatesFilterBroadened" x-cloak>
            <?php echo esc_html__('No templates are linked to this service; showing all templates. Link templates to the service where appropriate.', 'eko-sampa'); ?>
        </span>
        <span class="mt-1 block text-[11px] text-slate-500" x-show="!parseInt(String(state.form.service_id || 0), 10)" x-cloak>
            <?php echo esc_html__('All templates are listed until you pick a service.', 'eko-sampa'); ?>
        </span>
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
<div class="mt-4 border-t border-slate-100 pt-3" x-show="state.serviceFields && state.serviceFields.length > 0">
    <p class="text-xs font-medium text-slate-600" x-text="state.useTemplatePlaceholderFields ? '<?php echo esc_js(__('Template fields (live preview)', 'eko-sampa')); ?>' : '<?php echo esc_js(__('Service fields', 'eko-sampa')); ?>'"></p>
    <p class="mt-1 text-[11px] text-slate-500" x-show="!state.useTemplatePlaceholderFields && parseInt(String(state.form.service_id || 0), 10) > 0">
        <?php echo esc_html__('Print fields update the live preview only when their slug exists in the template. Operational-only fields are stored but not shown on the layout.', 'eko-sampa'); ?>
    </p>
    <p class="mt-1 text-[11px] leading-snug text-slate-500"><?php echo esc_html__('Only tokens present in the template (e.g. {{slug}}) update the live preview. Other values are stored for production.', 'eko-sampa'); ?></p>
    <div class="mt-3 space-y-4">
        <div x-show="printServiceFields().length > 0">
            <p class="text-[11px] font-medium uppercase tracking-wide text-slate-500"><?php echo esc_html__('Print / template', 'eko-sampa'); ?></p>
            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                <template x-for="f in printServiceFields()" :key="'sf-print-' + (f.id != null ? f.id : '') + '-' + (f.slug || '')">
                    <div class="min-w-0 sm:col-span-2">
                        <label class="block text-xs font-medium text-slate-600">
                            <span x-text="f.label + (parseInt(String(f.required), 10) ? ' *' : '')"></span>
                            <span class="ml-1 font-mono text-slate-400" x-text="'{{' + f.slug + '}}'"></span>
                            <span class="ml-2 rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-medium text-emerald-800" x-show="fieldAffectsPreview(f)" x-cloak><?php echo esc_html__('In template', 'eko-sampa'); ?></span>
                        </label>
                        <textarea x-show="f.type === 'textarea'" class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" rows="2" x-model="state.form.dynamic_data_json[f.slug]" x-bind:placeholder="f.placeholder || ''"></textarea>
                        <select x-show="f.type === 'select'" class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" x-model="state.form.dynamic_data_json[f.slug]">
                            <option value="">—</option>
                            <template x-for="(opt, idx) in fieldSelectOptions(f)" :key="(f.slug || '') + '-opt-' + idx + '-' + opt.value">
                                <option :value="opt.value" x-text="opt.label"></option>
                            </template>
                        </select>
                        <input x-show="f.type !== 'textarea' && f.type !== 'select'" class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" :type="f.type === 'number' ? 'number' : (f.type === 'date' ? 'date' : 'text')" x-model="state.form.dynamic_data_json[f.slug]" x-bind:placeholder="f.placeholder || ''" />
                    </div>
                </template>
            </div>
        </div>
        <div x-show="operationalServiceFields().length > 0">
            <p class="text-[11px] font-medium uppercase tracking-wide text-slate-500"><?php echo esc_html__('Operational / internal', 'eko-sampa'); ?></p>
            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                <template x-for="f in operationalServiceFields()" :key="'sf-ops-' + (f.id != null ? f.id : '') + '-' + (f.slug || '')">
                    <div class="min-w-0 sm:col-span-2">
                        <label class="block text-xs font-medium text-slate-600">
                            <span x-text="f.label + (parseInt(String(f.required), 10) ? ' *' : '')"></span>
                            <span class="ml-1 font-mono text-slate-400" x-text="'{{' + f.slug + '}}'"></span>
                        </label>
                        <textarea x-show="f.type === 'textarea'" class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" rows="2" x-model="state.form.dynamic_data_json[f.slug]" x-bind:placeholder="f.placeholder || ''"></textarea>
                        <select x-show="f.type === 'select'" class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" x-model="state.form.dynamic_data_json[f.slug]">
                            <option value="">—</option>
                            <template x-for="(opt, idx) in fieldSelectOptions(f)" :key="(f.slug || '') + '-opt-' + idx + '-' + opt.value">
                                <option :value="opt.value" x-text="opt.label"></option>
                            </template>
                        </select>
                        <input x-show="f.type !== 'textarea' && f.type !== 'select'" class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-sm" :type="f.type === 'number' ? 'number' : (f.type === 'date' ? 'date' : 'text')" x-model="state.form.dynamic_data_json[f.slug]" x-bind:placeholder="f.placeholder || ''" />
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>
<div class="mt-3 border-t border-slate-100 pt-3" x-show="!state.serviceFields || state.serviceFields.length === 0">
    <p class="text-xs text-slate-500" x-show="parseInt(String(state.form.template_id || 0), 10) > 0"><?php echo esc_html__('Select a service, or choose a template with {{placeholders}} to fill fields here.', 'eko-sampa'); ?></p>
    <p class="text-xs text-slate-500" x-show="!parseInt(String(state.form.template_id || 0), 10)"><?php echo esc_html__('Select a template or service to load dynamic fields.', 'eko-sampa'); ?></p>
</div>
<button type="button" class="mt-4 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700" @click="save()" :disabled="loading"><?php echo esc_html__('Save', 'eko-sampa'); ?></button>
