<?php
/**
 * Dynamic field form body (used inside eko-base-modal on service edit).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

?>
<div class="space-y-3">
    <div class="grid gap-2 sm:grid-cols-2">
        <label class="block text-xs font-medium text-slate-600"><?php echo esc_html__('Label', 'eko-sampa'); ?> *
            <input class="mt-1 w-full rounded-lg border border-slate-200 px-2 py-1.5 text-sm focus:border-indigo-300 focus:outline-none focus:ring-2 focus:ring-indigo-500/20" type="text" x-model="state.fieldDraft.definition.label" />
        </label>
        <label class="block text-xs font-medium text-slate-600"><?php echo esc_html__('Slug', 'eko-sampa'); ?> *
            <input class="mt-1 w-full rounded-lg border border-slate-200 px-2 py-1.5 text-sm font-mono focus:border-indigo-300 focus:outline-none focus:ring-2 focus:ring-indigo-500/20" type="text" x-model="state.fieldDraft.definition.slug" />
        </label>
        <label class="block text-xs font-medium text-slate-600"><?php echo esc_html__('Type', 'eko-sampa'); ?>
            <select class="mt-1 w-full rounded-lg border border-slate-200 px-2 py-1.5 text-sm focus:border-indigo-300 focus:outline-none focus:ring-2 focus:ring-indigo-500/20" x-model="state.fieldDraft.definition.type" @change="if (state.fieldDraft.definition.type === 'select' && (state.fieldDraft.definition.options_json == null || state.fieldDraft.definition.options_json === '')) { state.fieldDraft.definition.options_json = '[]'; }">
                <option value="text">text</option>
                <option value="textarea">textarea</option>
                <option value="number">number</option>
                <option value="select">select</option>
                <option value="date">date</option>
            </select>
        </label>
        <label class="flex items-center gap-2 self-end pb-1 text-sm text-slate-700">
            <input type="checkbox" x-model="state.fieldDraft.definition.required" :true-value="1" :false-value="0" />
            <?php echo esc_html__('Required', 'eko-sampa'); ?>
        </label>
        <label class="flex items-center gap-2 text-sm text-slate-700 sm:col-span-2">
            <input type="checkbox" x-model="state.fieldDraft.definition.show_in_template" :true-value="1" :false-value="0" />
            <?php echo esc_html__('Print / template field (uncheck for operational-only)', 'eko-sampa'); ?>
        </label>
    </div>

    <div class="grid gap-2 sm:grid-cols-2">
        <label class="block text-xs font-medium text-slate-600 sm:col-span-2"><?php echo esc_html__('Default value', 'eko-sampa'); ?>
            <input class="mt-1 w-full rounded-lg border border-slate-200 px-2 py-1.5 text-sm" type="text" x-model="state.fieldDraft.definition.default_value" />
        </label>
        <label class="block text-xs font-medium text-slate-600 sm:col-span-2"><?php echo esc_html__('Placeholder (form hint)', 'eko-sampa'); ?>
            <input class="mt-1 w-full rounded-lg border border-slate-200 px-2 py-1.5 text-sm" type="text" x-model="state.fieldDraft.definition.placeholder" />
        </label>
    </div>

    <label class="block text-xs font-medium text-slate-600" x-show="state.fieldDraft.definition.type === 'select'">
        <?php echo esc_html__('Select options (JSON array)', 'eko-sampa'); ?>
        <textarea class="mt-1 w-full rounded-lg border border-slate-200 px-2 py-1.5 font-mono text-xs" rows="3" placeholder='["Option A","Option B"]' x-model="state.fieldDraft.definition.options_json"></textarea>
    </label>

    <div class="rounded-lg border border-slate-100 bg-slate-50/80 p-3">
        <p class="text-xs font-semibold text-slate-800"><?php echo esc_html__('Validation (optional)', 'eko-sampa'); ?></p>
        <p class="mt-1 text-[11px] leading-snug text-slate-600"><?php echo esc_html__('Rules are stored as structured JSON on the server; you edit them here without raw JSON.', 'eko-sampa'); ?></p>
        <p class="mt-2 text-[11px] font-medium text-amber-800" x-show="state.fieldDraft.definition._validationLegacyRaw" x-cloak><?php echo esc_html__('Legacy validation is stored on the server. It is kept on save unless you set rules below — then the new rules replace it.', 'eko-sampa'); ?></p>
        <div class="mt-3 space-y-2" x-show="state.fieldDraft.definition.type === 'text' || state.fieldDraft.definition.type === 'textarea'">
            <div class="grid gap-2 sm:grid-cols-2">
                <label class="block text-[11px] font-medium text-slate-600"><?php echo esc_html__('Min length', 'eko-sampa'); ?>
                    <input class="mt-0.5 w-full rounded-lg border border-slate-200 px-2 py-1 text-sm" type="number" min="0" step="1" x-model="state.fieldDraft.definition.validation.minLength" />
                </label>
                <label class="block text-[11px] font-medium text-slate-600"><?php echo esc_html__('Max length', 'eko-sampa'); ?>
                    <input class="mt-0.5 w-full rounded-lg border border-slate-200 px-2 py-1 text-sm" type="number" min="0" step="1" x-model="state.fieldDraft.definition.validation.maxLength" />
                </label>
            </div>
            <label class="block text-[11px] font-medium text-slate-600"><?php echo esc_html__('Pattern (regex)', 'eko-sampa'); ?>
                <input class="mt-0.5 w-full rounded-lg border border-slate-200 px-2 py-1 font-mono text-xs" type="text" x-model="state.fieldDraft.definition.validation.pattern" placeholder="<?php echo esc_attr__('e.g. ^[0-9]+$', 'eko-sampa'); ?>" />
            </label>
        </div>
        <div class="mt-3 space-y-2" x-show="state.fieldDraft.definition.type === 'number'">
            <div class="grid gap-2 sm:grid-cols-2">
                <label class="block text-[11px] font-medium text-slate-600"><?php echo esc_html__('Minimum', 'eko-sampa'); ?>
                    <input class="mt-0.5 w-full rounded-lg border border-slate-200 px-2 py-1 text-sm" type="text" inputmode="decimal" x-model="state.fieldDraft.definition.validation.minimum" />
                </label>
                <label class="block text-[11px] font-medium text-slate-600"><?php echo esc_html__('Maximum', 'eko-sampa'); ?>
                    <input class="mt-0.5 w-full rounded-lg border border-slate-200 px-2 py-1 text-sm" type="text" inputmode="decimal" x-model="state.fieldDraft.definition.validation.maximum" />
                </label>
            </div>
        </div>
        <div class="mt-3 space-y-2" x-show="state.fieldDraft.definition.type === 'date'">
            <label class="block text-[11px] font-medium text-slate-600"><?php echo esc_html__('Format hint (optional)', 'eko-sampa'); ?>
                <input class="mt-0.5 w-full rounded-lg border border-slate-200 px-2 py-1 text-sm" type="text" x-model="state.fieldDraft.definition.validation.format" placeholder="<?php echo esc_attr__('e.g. date', 'eko-sampa'); ?>" />
            </label>
        </div>
        <div class="mt-3 space-y-2" x-show="state.fieldDraft.definition.type === 'select'">
            <label class="block text-[11px] font-medium text-slate-600"><?php echo esc_html__('Max length of selected value (optional)', 'eko-sampa'); ?>
                <input class="mt-0.5 w-full rounded-lg border border-slate-200 px-2 py-1 text-sm" type="number" min="0" step="1" x-model="state.fieldDraft.definition.validation.maxLength" />
            </label>
        </div>
    </div>
</div>
