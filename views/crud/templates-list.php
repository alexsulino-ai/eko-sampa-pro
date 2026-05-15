<?php
/**
 * Templates — visual catalog (grid / list).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$new_url = Eko_Sampa_Frontend_Router::get_resource_url('templates', 'new');

?>
<div class="eko-templates-catalog mx-auto max-w-[1400px] space-y-6" x-data="window.ekoTemplatesFactory()" x-init="init()" @keydown.escape.window="closeZoom()">
    <div class="eko-templates-toolbar">
        <div class="space-y-1">
            <h2 class="text-xl font-semibold tracking-tight text-slate-900"><?php echo esc_html__('Template library', 'eko-sampa'); ?></h2>
            <p class="text-sm text-slate-500"><?php echo esc_html__('Choose a layout by preview — open the editor to customize.', 'eko-sampa'); ?></p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <div class="eko-templates-view-toggle" role="group" aria-label="<?php echo esc_attr__('View mode', 'eko-sampa'); ?>">
                <button type="button" :aria-pressed="listView === 'grid'" @click="setListView('grid')"><?php echo esc_html__('Grid', 'eko-sampa'); ?></button>
                <button type="button" :aria-pressed="listView === 'list'" @click="setListView('list')"><?php echo esc_html__('List', 'eko-sampa'); ?></button>
            </div>
            <?php if (current_user_can('manage_options')) : ?>
                <select class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm" x-model="state.filterUserId" @change="state.page=1; load()">
                    <option value=""><?php echo esc_html__('All users', 'eko-sampa'); ?></option>
                    <template x-for="u in state.users" :key="u.id">
                        <option :value="u.id" x-text="u.display_name + ' (' + u.id + ')'"></option>
                    </template>
                </select>
            <?php endif; ?>
            <input class="rounded-lg border border-slate-200 px-3 py-2 text-sm" type="search" x-model="state.q" @keydown.enter.prevent="state.page=1; load()" placeholder="<?php echo esc_attr__('Search…', 'eko-sampa'); ?>" />
            <input class="rounded-lg border border-slate-200 px-3 py-2 text-sm" type="text" x-model="state.cat" @change="state.page=1; load()" placeholder="<?php echo esc_attr__('Category', 'eko-sampa'); ?>" />
            <button type="button" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm hover:bg-slate-50" @click="state.page=1; load()"><?php echo esc_html__('Apply', 'eko-sampa'); ?></button>
            <a class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700" href="<?php echo esc_url($new_url); ?>"><?php echo esc_html__('New template', 'eko-sampa'); ?></a>
        </div>
    </div>

    <p class="text-sm text-red-600" x-show="error" x-text="error || ''" x-cloak></p>

    <div class="eko-templates-grid" x-show="listView === 'grid' && !loading && state.rows.length" x-cloak>
        <template x-for="r in state.rows" :key="r.id">
            <article class="eko-template-card">
                <div class="eko-template-card__thumb-wrap">
                    <div class="eko-tpl-preview__frame eko-tpl-preview__frame--zoom" @click="openZoom(r)" title="<?php echo esc_attr__('Enlarge preview', 'eko-sampa'); ?>" :data-eko-thumbnail-state="r.thumbnail_state || 'missing'">
                    <div class="eko-tpl-preview__skeleton" x-show="thumbnailSrc(r) && !thumbLoaded(r.id) && !thumbFailed(r.id)" x-cloak></div>
                    <img
                        class="eko-tpl-preview__img eko-template-card__thumb"
                        :src="thumbnailSrc(r)"
                        :alt="r.nome"
                        loading="lazy"
                        decoding="async"
                        x-show="thumbnailSrc(r) && !thumbFailed(r.id)"
                        x-init="ensureThumbLoaded($el, r.id)"
                        @load="markThumbLoaded(r.id)"
                        @error="markThumbFailed(r.id, $event.target)"
                    />
                    <div class="eko-tpl-preview__empty" x-show="!thumbnailSrc(r) || thumbFailed(r.id)" x-cloak>
                            <svg class="eko-tpl-preview__empty-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 18.75V5.25A2.25 2.25 0 0 0 20.25 3H3.75A2.25 2.25 0 0 0 1.5 5.25v13.5A2.25 2.25 0 0 0 3.75 21Z" />
                            </svg>
                            <span class="eko-tpl-preview__empty-label"><?php echo esc_html__('No preview', 'eko-sampa'); ?></span>
                        </div>
                    </div>
                </div>
                <div class="eko-template-card__body">
                    <h3 class="eko-template-card__title" x-text="r.nome"></h3>
                    <p class="eko-template-card__meta" x-text="formatDimensions(r)"></p>
                    <p class="eko-template-card__meta" x-show="r.categoria" x-text="r.categoria"></p>
                    <p class="eko-template-card__meta" x-text="formatUpdated(r)"></p>
                    <button
                        type="button"
                        class="eko-tpl-create-order"
                        x-show="canOrderCreate() && canCreateOrderFrom(r)"
                        :disabled="isCreatingOrder(r.id)"
                        @click="createOrderFromTemplate(r)"
                    ><?php echo esc_html__('Create order', 'eko-sampa'); ?></button>
                    <div class="eko-template-card__actions">
                        <?php
                        eko_sampa_crud_actions_render(
                            [
                                ['type' => 'view', 'href' => 'viewUrl(r.id)', 'can' => 'template.view', 'size' => 'sm', 'icon_only' => true],
                                ['type' => 'edit', 'href' => 'editUrl(r.id)', 'can' => 'template.edit', 'size' => 'sm', 'icon_only' => true],
                                ['type' => 'editor', 'href' => 'editorUrl(r.id)', 'can' => 'template.editor', 'size' => 'sm', 'icon_only' => true],
                                ['type' => 'duplicate', 'click' => 'duplicate(r.id)', 'can' => 'template.duplicate', 'size' => 'sm', 'icon_only' => true],
                                ['type' => 'delete', 'click' => 'remove(r.id)', 'can' => 'template.delete', 'policy' => 'disabled', 'size' => 'sm', 'icon_only' => true],
                            ]
                        );
                        ?>
                    </div>
                </div>
            </article>
        </template>
    </div>

    <div class="eko-templates-list" x-show="listView === 'list' && !loading && state.rows.length" x-cloak>
        <template x-for="r in state.rows" :key="'list-' + r.id">
            <article class="eko-template-row">
                <div class="eko-template-row__thumb-wrap">
                    <div class="eko-tpl-preview__frame eko-tpl-preview__frame--zoom" @click="openZoom(r)" :data-eko-thumbnail-state="r.thumbnail_state || 'missing'">
                        <div class="eko-tpl-preview__skeleton" x-show="thumbnailSrc(r) && !thumbLoaded(r.id) && !thumbFailed(r.id)" x-cloak></div>
                        <img
                            x-show="thumbnailSrc(r) && !thumbFailed(r.id)"
                            class="eko-tpl-preview__img eko-template-row__thumb"
                            :src="thumbnailSrc(r)"
                            :alt="r.nome"
                            loading="lazy"
                            decoding="async"
                            x-init="ensureThumbLoaded($el, r.id)"
                            @load="markThumbLoaded(r.id)"
                            @error="markThumbFailed(r.id, $event.target)"
                        />
                        <div class="eko-tpl-preview__empty" x-show="!thumbnailSrc(r) || thumbFailed(r.id)" x-cloak>
                            <svg class="eko-tpl-preview__empty-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 18.75V5.25A2.25 2.25 0 0 0 20.25 3H3.75A2.25 2.25 0 0 0 1.5 5.25v13.5A2.25 2.25 0 0 0 3.75 21Z" />
                            </svg>
                            <span class="eko-tpl-preview__empty-label"><?php echo esc_html__('No preview', 'eko-sampa'); ?></span>
                        </div>
                    </div>
                </div>
                <div class="eko-template-row__main">
                    <h3 class="text-sm font-semibold text-slate-900" x-text="r.nome"></h3>
                    <p class="text-xs text-slate-500" x-text="formatDimensions(r) + (r.categoria ? ' · ' + r.categoria : '')"></p>
                    <p class="text-xs text-slate-400" x-text="formatUpdated(r)"></p>
                </div>
                <div class="eko-template-row__actions">
                    <?php
                    eko_sampa_crud_actions_render(
                        [
                            ['type' => 'create_order', 'click' => 'createOrderFromTemplate(r)', 'can' => 'order.create', 'show' => 'canCreateOrderFrom(r)', 'loading' => 'isCreatingOrder(r.id)', 'size' => 'sm'],
                            ['type' => 'view', 'href' => 'viewUrl(r.id)', 'can' => 'template.view', 'size' => 'sm'],
                            ['type' => 'edit', 'href' => 'editUrl(r.id)', 'can' => 'template.edit', 'size' => 'sm'],
                            ['type' => 'editor', 'href' => 'editorUrl(r.id)', 'can' => 'template.editor', 'size' => 'sm'],
                            ['type' => 'duplicate', 'click' => 'duplicate(r.id)', 'can' => 'template.duplicate', 'size' => 'sm'],
                            ['type' => 'delete', 'click' => 'remove(r.id)', 'can' => 'template.delete', 'policy' => 'disabled', 'size' => 'sm'],
                        ]
                    );
                    ?>
                </div>
            </article>
        </template>
    </div>

    <div class="eko-templates-grid" x-show="loading" x-cloak>
        <template x-for="i in 8" :key="'sk-' + i">
            <article class="eko-template-card">
                <div class="eko-template-card__thumb-wrap"><div class="eko-tpl-preview__skeleton"></div></div>
                <div class="eko-template-card__body">
                    <div class="h-4 w-3/4 rounded bg-slate-200"></div>
                    <div class="mt-2 h-3 w-1/2 rounded bg-slate-100"></div>
                </div>
            </article>
        </template>
    </div>

    <div class="eko-templates-empty" x-show="!loading && !state.rows.length" x-cloak>
        <p class="text-base font-medium text-slate-700"><?php echo esc_html__('No templates yet', 'eko-sampa'); ?></p>
        <p class="mt-1 text-sm text-slate-500"><?php echo esc_html__('Create your first layout in the visual editor.', 'eko-sampa'); ?></p>
        <a class="mt-4 inline-flex rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700" href="<?php echo esc_url($new_url); ?>"><?php echo esc_html__('New template', 'eko-sampa'); ?></a>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-2 text-sm text-slate-600" x-show="state.rows.length || state.page > 1">
        <span><?php echo esc_html__('Page', 'eko-sampa'); ?> <span x-text="state.page"></span></span>
        <div class="flex gap-2">
            <button type="button" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs hover:bg-slate-50 disabled:opacity-40" @click="prevPage()" :disabled="state.page <= 1"><?php echo esc_html__('Previous', 'eko-sampa'); ?></button>
            <button type="button" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs hover:bg-slate-50 disabled:opacity-40" @click="nextPage()" :disabled="!state.hasNext"><?php echo esc_html__('Next', 'eko-sampa'); ?></button>
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
