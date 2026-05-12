<?php
/**
 * Visual editor — canvas, toolbar, layers, gallery modal, REST-backed JSON.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$eko_sampa_editor_embedded = ! empty($GLOBALS['eko_sampa_editor_embedded']);
$eko_editor_root_class     = $eko_sampa_editor_embedded
    ? 'eko-sampa-editor flex min-h-0 flex-1 flex-col bg-slate-100 text-slate-900'
    : 'eko-sampa-editor flex min-h-[calc(100vh-32px)] flex-col bg-slate-100 text-slate-900';

?>
<div
    id="eko-sampa-editor"
    class="<?php echo esc_attr($eko_editor_root_class); ?>"
    x-data="ekoEditorCanvas"
    @keydown.window="if ($event.key === 'Delete' && !inlineOpen && !galleryOpen) { deleteSelected(); }"
>
    <header class="eko-sampa-editor__toolbar flex shrink-0 flex-wrap items-center gap-2 border-b border-slate-200 bg-white px-3 py-2 md:px-4">
        <h1 class="text-sm font-semibold text-slate-900 md:text-base">
            <?php echo esc_html__('Visual editor', 'eko-sampa'); ?>
        </h1>
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" class="rounded border border-slate-200 bg-white px-2 py-1 text-xs hover:bg-slate-50" @click="addText()"><?php echo esc_html__('Text', 'eko-sampa'); ?></button>
            <button type="button" class="rounded border border-slate-200 bg-white px-2 py-1 text-xs hover:bg-slate-50" @click="addPlaceholder()"><?php echo esc_html__('Placeholder', 'eko-sampa'); ?></button>
            <button type="button" class="rounded border border-slate-200 bg-white px-2 py-1 text-xs hover:bg-slate-50" @click="addRectangle()"><?php echo esc_html__('Rectangle', 'eko-sampa'); ?></button>
            <button type="button" class="rounded border border-indigo-200 bg-indigo-50 px-2 py-1 text-xs text-indigo-800 hover:bg-indigo-100" @click="openGallery()"><?php echo esc_html__('Gallery', 'eko-sampa'); ?></button>
            <button type="button" class="rounded border border-red-200 bg-red-50 px-2 py-1 text-xs text-red-700 hover:bg-red-100" @click="deleteSelected()"><?php echo esc_html__('Delete', 'eko-sampa'); ?></button>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-xs text-slate-600">
            <label class="inline-flex items-center gap-1">
                <span><?php echo esc_html__('W mm', 'eko-sampa'); ?></span>
                <input class="w-16 rounded border border-slate-200 px-1 py-0.5" type="number" x-model.number="widthMm" min="10" max="2000" />
            </label>
            <label class="inline-flex items-center gap-1">
                <span><?php echo esc_html__('H mm', 'eko-sampa'); ?></span>
                <input class="w-16 rounded border border-slate-200 px-1 py-0.5" type="number" x-model.number="heightMm" min="10" max="2000" />
            </label>
        </div>
        <div class="eko-sampa-editor__zoom ml-auto flex min-w-0 flex-wrap items-center gap-2">
            <label class="text-xs text-slate-600" for="eko-sampa-editor-zoom"><?php echo esc_html__('Zoom', 'eko-sampa'); ?></label>
            <input
                id="eko-sampa-editor-zoom"
                class="h-2 w-28 cursor-pointer accent-slate-700"
                type="range"
                x-model.number="zoomPercent"
                :min="minZoom"
                :max="maxZoom"
                step="1"
            />
            <span class="w-10 text-right text-xs tabular-nums text-slate-700" x-text="zoomPercent + '%'"></span>
            <span class="text-xs text-slate-500" x-text="saveState"></span>
        </div>
    </header>

    <div class="flex min-h-0 flex-1 flex-col lg:flex-row">
        <section class="eko-sampa-editor__workspace flex min-h-0 min-w-0 flex-1 flex-col" aria-label="<?php echo esc_attr__('Canvas workspace', 'eko-sampa'); ?>">
            <div class="eko-sampa-editor__viewport min-h-0 flex-1 overflow-auto bg-slate-200/90" role="region">
                <div class="eko-sampa-editor__viewport-frame flex min-h-full min-w-full items-center justify-center p-4 md:p-8">
                    <div class="eko-sampa-editor__stage" :style="stageTransform">
                        <div
                            class="eko-sampa-editor__canvas relative h-[1131px] w-[800px] shrink-0 bg-white shadow-lg ring-1 ring-slate-900/10"
                            role="application"
                            style="background-image: linear-gradient(to right, rgb(226 232 240) 1px, transparent 1px), linear-gradient(to bottom, rgb(226 232 240) 1px, transparent 1px); background-size: 5px 5px;"
                        >
                            <template x-for="item in elements" :key="item.id">
                                <div
                                    class="eko-sampa-editor__element group absolute touch-none select-none"
                                    :class="selectedId === item.id ? 'ring-2 ring-indigo-500' : ''"
                                    :data-element-id="item.id"
                                    :style="`left: ${item.x}px; top: ${item.y}px; width: ${item.width}px; height: ${item.height}px`"
                                    @mousedown="select(item.id)"
                                >
                                    <template x-if="item.type === 'image'">
                                        <img
                                            class="pointer-events-none h-full w-full object-contain"
                                            :src="item.src || item.content"
                                            alt=""
                                        />
                                    </template>
                                    <div
                                        class="eko-sampa-editor__inline-hit pointer-events-auto absolute inset-0 overflow-hidden text-sm"
                                        :class="item.type === 'text' || item.type === 'placeholder' ? 'cursor-text px-2 py-1' : 'pointer-events-none'"
                                        x-show="item.type === 'text' || item.type === 'placeholder'"
                                        @dblclick.prevent="openInlineEdit(item)"
                                    >
                                        <span class="pointer-events-none text-slate-800" x-text="item.content"></span>
                                    </div>
                                    <div x-show="item.type === 'rectangle'" class="h-full w-full bg-slate-100 ring-1 ring-slate-300"></div>

                                    <template x-if="selectedId === item.id">
                                        <div>
                                            <span class="eko-sampa-editor__resize-handle eko-resize-l eko-resize-t pointer-events-auto absolute left-0 top-0 z-20 h-3 w-3 -translate-x-1/2 -translate-y-1/2 cursor-nwse-resize rounded-sm border border-slate-600 bg-white shadow" aria-hidden="true"></span>
                                            <span class="eko-sampa-editor__resize-handle eko-resize-t pointer-events-auto absolute left-1/2 top-0 z-20 h-3 w-3 -translate-x-1/2 -translate-y-1/2 cursor-ns-resize rounded-sm border border-slate-600 bg-white shadow" aria-hidden="true"></span>
                                            <span class="eko-sampa-editor__resize-handle eko-resize-r eko-resize-t pointer-events-auto absolute right-0 top-0 z-20 h-3 w-3 translate-x-1/2 -translate-y-1/2 cursor-nesw-resize rounded-sm border border-slate-600 bg-white shadow" aria-hidden="true"></span>
                                            <span class="eko-sampa-editor__resize-handle eko-resize-r pointer-events-auto absolute right-0 top-1/2 z-20 h-3 w-3 translate-x-1/2 -translate-y-1/2 cursor-ew-resize rounded-sm border border-slate-600 bg-white shadow" aria-hidden="true"></span>
                                            <span class="eko-sampa-editor__resize-handle eko-resize-r eko-resize-b pointer-events-auto absolute bottom-0 right-0 z-20 h-3 w-3 translate-x-1/2 translate-y-1/2 cursor-nwse-resize rounded-sm border border-slate-600 bg-white shadow" aria-hidden="true"></span>
                                            <span class="eko-sampa-editor__resize-handle eko-resize-b pointer-events-auto absolute bottom-0 left-1/2 z-20 h-3 w-3 -translate-x-1/2 translate-y-1/2 cursor-ns-resize rounded-sm border border-slate-600 bg-white shadow" aria-hidden="true"></span>
                                            <span class="eko-sampa-editor__resize-handle eko-resize-l eko-resize-b pointer-events-auto absolute bottom-0 left-0 z-20 h-3 w-3 -translate-x-1/2 translate-y-1/2 cursor-nesw-resize rounded-sm border border-slate-600 bg-white shadow" aria-hidden="true"></span>
                                            <span class="eko-sampa-editor__resize-handle eko-resize-l pointer-events-auto absolute left-0 top-1/2 z-20 h-3 w-3 -translate-x-1/2 -translate-y-1/2 cursor-ew-resize rounded-sm border border-slate-600 bg-white shadow" aria-hidden="true"></span>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <aside class="flex w-full shrink-0 flex-col border-t border-slate-200 bg-white lg:w-80 lg:border-l lg:border-t-0">
            <div class="border-b border-slate-100 px-3 py-2 text-xs font-medium uppercase tracking-wide text-slate-500">
                <?php echo esc_html__('Layers', 'eko-sampa'); ?>
            </div>
            <ul class="max-h-48 overflow-auto p-2 text-sm lg:max-h-64" x-ref="layerList">
                <template x-for="(item, idx) in elements" :key="item.id">
                    <li
                        class="mb-1 flex cursor-grab items-center gap-2 rounded border border-slate-100 bg-slate-50 px-2 py-1"
                        data-layer-row
                        @click="select(item.id)"
                    >
                        <span class="w-6 shrink-0 text-[10px] text-slate-400" x-text="idx + 1"></span>
                        <span class="truncate text-xs text-slate-500" x-text="item.type"></span>
                        <span class="min-w-0 flex-1 truncate" x-text="item.content || item.src || item.id"></span>
                    </li>
                </template>
            </ul>
            <div class="border-t border-slate-100 px-3 py-2 text-xs font-medium uppercase tracking-wide text-slate-500">
                <?php echo esc_html__('JSON', 'eko-sampa'); ?>
            </div>
            <pre class="min-h-0 flex-1 overflow-auto p-2 font-mono text-[10px] leading-relaxed text-slate-800 lg:max-h-48" x-text="elementsJson"></pre>
        </aside>
    </div>

    <div
        x-show="inlineOpen"
        x-cloak
        class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/50 p-4"
        @keydown.escape.window="inlineOpen = false"
    >
        <div class="w-full max-w-lg rounded-xl bg-white p-4 shadow-xl" @click.outside="inlineOpen = false">
            <label class="block text-sm font-medium text-slate-700"><?php echo esc_html__('Edit content', 'eko-sampa'); ?></label>
            <textarea class="mt-2 w-full rounded border border-slate-200 p-2 font-mono text-sm" rows="4" x-model="inlineValue"></textarea>
            <div class="mt-3 flex justify-end gap-2">
                <button type="button" class="rounded px-3 py-1 text-sm text-slate-600 hover:bg-slate-50" @click="inlineOpen = false"><?php echo esc_html__('Cancel', 'eko-sampa'); ?></button>
                <button type="button" class="rounded bg-indigo-600 px-3 py-1 text-sm text-white hover:bg-indigo-700" @click="applyInlineEdit()"><?php echo esc_html__('Apply', 'eko-sampa'); ?></button>
            </div>
        </div>
    </div>

    <div
        x-show="galleryOpen"
        x-cloak
        class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/50 p-4"
    >
        <div class="flex max-h-[90vh] w-full max-w-3xl flex-col rounded-xl bg-white shadow-xl" @click.outside="galleryOpen = false">
            <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                <h2 class="text-sm font-semibold text-slate-900"><?php echo esc_html__('Image gallery', 'eko-sampa'); ?></h2>
                <button type="button" class="text-sm text-slate-500 hover:text-slate-800" @click="galleryOpen = false"><?php echo esc_html__('Close', 'eko-sampa'); ?></button>
            </div>
            <div class="border-b border-slate-100 px-4 py-2">
                <input type="file" accept="image/*" class="text-sm" @change="uploadGallery($event)" />
                <span class="ml-2 text-xs text-slate-500" x-show="galleryLoading"><?php echo esc_html__('Loading…', 'eko-sampa'); ?></span>
            </div>
            <div class="grid flex-1 grid-cols-3 gap-2 overflow-auto p-4 sm:grid-cols-4 md:grid-cols-5">
                <template x-for="g in galleryItems" :key="g.url">
                    <button type="button" class="overflow-hidden rounded border border-slate-200 hover:ring-2 hover:ring-indigo-400" @click="pickGallery(g.url)">
                        <img class="h-24 w-full object-cover" :src="g.url" :alt="g.name" loading="lazy" />
                    </button>
                </template>
            </div>
        </div>
    </div>
</div>
