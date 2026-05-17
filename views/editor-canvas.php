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
    x-data="window.ekoEditorCanvasFactory()"
    @keydown.window="editorWindowKeydown($event)"
>
    <header class="eko-sampa-editor__toolbar flex shrink-0 flex-wrap items-center gap-2 border-b border-slate-200 bg-white px-3 py-2 md:px-4">
        <h1 class="text-sm font-semibold text-slate-900 md:text-base">
            <?php echo esc_html__('Visual editor', 'eko-sampa'); ?>
        </h1>
        <template x-if="!previewOnly">
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" class="rounded border border-slate-200 bg-white px-2 py-1 text-xs hover:bg-slate-50" @click="addText()"><?php echo esc_html__('Text', 'eko-sampa'); ?></button>
                <button type="button" class="rounded border border-slate-200 bg-white px-2 py-1 text-xs hover:bg-slate-50" @click="addPlaceholder()"><?php echo esc_html__('Placeholder', 'eko-sampa'); ?></button>
                <button type="button" class="rounded border border-slate-200 bg-white px-2 py-1 text-xs hover:bg-slate-50" @click="addRectangle()"><?php echo esc_html__('Rectangle', 'eko-sampa'); ?></button>
                <button type="button" class="rounded border border-indigo-200 bg-indigo-50 px-2 py-1 text-xs text-indigo-800 hover:bg-indigo-100" @click="openGallery()"><?php echo esc_html__('Gallery', 'eko-sampa'); ?></button>
                <button type="button" class="rounded border border-red-200 bg-red-50 px-2 py-1 text-xs text-red-700 hover:bg-red-100" @click="deleteSelected()"><?php echo esc_html__('Delete', 'eko-sampa'); ?></button>
                <button type="button" class="rounded border border-slate-200 bg-white px-2 py-1 text-xs hover:bg-slate-50" @click="bringForward()" title="<?php echo esc_attr__('Move selected one step toward front (same as dragging up in the layer list)', 'eko-sampa'); ?>"><?php echo esc_html__('Bring forward', 'eko-sampa'); ?></button>
                <button type="button" class="rounded border border-slate-200 bg-white px-2 py-1 text-xs hover:bg-slate-50" @click="sendBackward()" title="<?php echo esc_attr__('Move selected one step toward back', 'eko-sampa'); ?>"><?php echo esc_html__('Send backward', 'eko-sampa'); ?></button>
            </div>
        </template>
        <template x-if="!previewOnly">
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
        </template>
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
            <span class="inline-block w-[11rem] shrink-0 text-right text-xs tabular-nums text-slate-500" :title="saveLine"><span x-show="saveLine" x-text="saveLine"></span></span>
            <span class="shrink-0 text-[10px] font-normal tabular-nums text-slate-400" x-text="editorVersionLabel" aria-hidden="true"></span>
        </div>
    </header>

    <div class="flex min-h-0 flex-1 flex-col lg:flex-row">
        <section class="eko-sampa-editor__workspace flex min-h-0 min-w-0 flex-1 flex-col" aria-label="<?php echo esc_attr__('Canvas workspace', 'eko-sampa'); ?>">
            <div class="eko-sampa-editor__viewport min-h-0 flex-1 overflow-auto bg-slate-200/90" role="region">
                <div class="eko-sampa-editor__viewport-frame flex min-h-full min-w-full items-start justify-center p-4 md:p-8" @mousedown.self="clearSelectionIfCanvas($event)">
                    <div class="eko-sampa-editor__stage" :style="stageTransform">
                        <div
                            class="eko-sampa-editor__canvas relative shrink-0 bg-white shadow-lg ring-1 ring-slate-900/10 transition-shadow duration-150"
                            :class="{ 'ring-2 ring-emerald-400/60 shadow-md': snapFlash }"
                            :style="canvasSurfaceStyle()"
                            x-ref="editorCanvas"
                            role="application"
                            @mousedown.self="clearSelectionIfCanvas($event)"
                        >
                            <template x-for="(item, idx) in elements" :key="item.id">
                                <div
                                    class="eko-sampa-editor__element group absolute touch-none select-none rounded-[1px]"
                                    :class="{
                                        'shadow-xl ring-2 ring-indigo-500': draggingId === item.id,
                                        'shadow-lg ring-2 ring-indigo-500': selectedId === item.id && !(inlineOpen && inlineTargetId === item.id) && draggingId !== item.id,
                                        'ring-2 ring-indigo-400': selectedId === item.id && (inlineOpen && inlineTargetId === item.id) && draggingId !== item.id,
                                        'hover:shadow-md hover:ring-1 hover:ring-slate-300/90': selectedId !== item.id && draggingId !== item.id,
                                    }"
                                    :data-element-id="item.id"
                                    :data-layer-index="idx"
                                    :style="elementPositionStyle(item)"
                                    @mousedown="select(item.id)"
                                    @dblclick.prevent="(item.type === 'text' || item.type === 'placeholder') && openInlineEdit(item)"
                                >
                                    <template x-if="item.type === 'image'">
                                        <div class="absolute inset-0 cursor-pointer" :style="elementFrameCss(item)" @click.stop="select(item.id)">
                                            <img class="pointer-events-none h-full w-full max-h-full max-w-full" :style="imageImgCss(item)" :src="item.src || item.content" alt="" />
                                        </div>
                                    </template>
                                    <template x-if="item.type === 'text' || item.type === 'placeholder'">
                                        <div class="absolute inset-0" :style="elementFrameCss(item)">
                                            <div
                                                class="eko-sampa-editor__inline-hit pointer-events-none min-h-0 min-w-0 cursor-text"
                                                x-show="!(inlineOpen && inlineTargetId === item.id)"
                                            >
                                                <span class="pointer-events-none block min-h-full min-w-0 whitespace-pre-wrap break-words" :style="textContentCss(item)" x-text="item.content"></span>
                                            </div>
                                            <template x-if="inlineOpen && inlineTargetId === item.id">
                                                <textarea
                                                    id="eko-inline-edit"
                                                    class="eko-sampa-editor__inline-field pointer-events-auto absolute inset-0 box-border ring-1 ring-indigo-300/40 outline-none"
                                                    rows="1"
                                                    :style="inlineEditorTextareaCss(item)"
                                                    x-model="inlineValue"
                                                    placeholder="<?php echo esc_attr__('Ctrl+Enter to save · Line breaks allowed', 'eko-sampa'); ?>"
                                                    @mousedown.stop
                                                    @click.stop
                                                    @keydown.escape.prevent="cancelInlineEdit()"
                                                    @keydown.ctrl.enter.prevent="confirmInlineEdit()"
                                                ></textarea>
                                            </template>
                                        </div>
                                    </template>
                                    <template x-if="item.type === 'rectangle'">
                                        <div class="pointer-events-none absolute inset-0" :style="elementFrameCss(item)"></div>
                                    </template>

                                    <template x-if="selectedId === item.id && !(inlineOpen && inlineTargetId === item.id) && !previewOnly">
                                        <div>
                                            <span class="eko-sampa-editor__resize-handle eko-resize-l eko-resize-t pointer-events-auto absolute left-0 top-0 z-[60] h-3.5 w-3.5 -translate-x-1/2 -translate-y-1/2 cursor-nwse-resize rounded-full border-2 border-white bg-indigo-500 shadow-md ring-1 ring-indigo-600/30 transition hover:scale-110" aria-hidden="true"></span>
                                            <span class="eko-sampa-editor__resize-handle eko-resize-t pointer-events-auto absolute left-1/2 top-0 z-[60] h-3.5 w-3.5 -translate-x-1/2 -translate-y-1/2 cursor-ns-resize rounded-full border-2 border-white bg-indigo-500 shadow-md ring-1 ring-indigo-600/30 transition hover:scale-110" aria-hidden="true"></span>
                                            <span class="eko-sampa-editor__resize-handle eko-resize-r eko-resize-t pointer-events-auto absolute right-0 top-0 z-[60] h-3.5 w-3.5 translate-x-1/2 -translate-y-1/2 cursor-nesw-resize rounded-full border-2 border-white bg-indigo-500 shadow-md ring-1 ring-indigo-600/30 transition hover:scale-110" aria-hidden="true"></span>
                                            <span class="eko-sampa-editor__resize-handle eko-resize-r pointer-events-auto absolute right-0 top-1/2 z-[60] h-3.5 w-3.5 translate-x-1/2 -translate-y-1/2 cursor-ew-resize rounded-full border-2 border-white bg-indigo-500 shadow-md ring-1 ring-indigo-600/30 transition hover:scale-110" aria-hidden="true"></span>
                                            <span class="eko-sampa-editor__resize-handle eko-resize-r eko-resize-b pointer-events-auto absolute bottom-0 right-0 z-[60] h-3.5 w-3.5 translate-x-1/2 translate-y-1/2 cursor-nwse-resize rounded-full border-2 border-white bg-indigo-500 shadow-md ring-1 ring-indigo-600/30 transition hover:scale-110" aria-hidden="true"></span>
                                            <span class="eko-sampa-editor__resize-handle eko-resize-b pointer-events-auto absolute bottom-0 left-1/2 z-[60] h-3.5 w-3.5 -translate-x-1/2 translate-y-1/2 cursor-ns-resize rounded-full border-2 border-white bg-indigo-500 shadow-md ring-1 ring-indigo-600/30 transition hover:scale-110" aria-hidden="true"></span>
                                            <span class="eko-sampa-editor__resize-handle eko-resize-l eko-resize-b pointer-events-auto absolute bottom-0 left-0 z-[60] h-3.5 w-3.5 -translate-x-1/2 translate-y-1/2 cursor-nesw-resize rounded-full border-2 border-white bg-indigo-500 shadow-md ring-1 ring-indigo-600/30 transition hover:scale-110" aria-hidden="true"></span>
                                            <span class="eko-sampa-editor__resize-handle eko-resize-l pointer-events-auto absolute left-0 top-1/2 z-[60] h-3.5 w-3.5 -translate-x-1/2 -translate-y-1/2 cursor-ew-resize rounded-full border-2 border-white bg-indigo-500 shadow-md ring-1 ring-indigo-600/30 transition hover:scale-110" aria-hidden="true"></span>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <aside
            class="flex min-h-0 w-full shrink-0 flex-col border-t border-slate-200 bg-white transition-[width] duration-200 ease-out lg:border-l lg:border-t-0"
            :class="editorSidebarCollapsed ? 'lg:w-12' : 'lg:w-[22rem]'"
            x-show="!previewOnly"
            x-cloak
        >
            <div class="flex shrink-0 items-center justify-between gap-1 border-b border-slate-100 px-2 py-2">
                <span class="truncate pl-1 text-xs font-medium uppercase tracking-wide text-slate-500" x-show="!editorSidebarCollapsed"><?php echo esc_html__('Layers', 'eko-sampa'); ?></span>
                <button
                    type="button"
                    class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-slate-200 text-slate-600 hover:bg-slate-50"
                    @click="editorSidebarCollapsed = !editorSidebarCollapsed"
                    :aria-expanded="!editorSidebarCollapsed"
                    :title="editorSidebarCollapsed ? '<?php echo esc_attr__('Expand sidebar', 'eko-sampa'); ?>' : '<?php echo esc_attr__('Collapse sidebar', 'eko-sampa'); ?>'"
                >
                    <svg x-show="!editorSidebarCollapsed" class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                    <svg x-show="editorSidebarCollapsed" class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
                </button>
            </div>
            <ul class="max-h-48 overflow-auto p-2 text-sm lg:max-h-64" x-ref="layerList" x-show="!editorSidebarCollapsed">
                <template x-for="(item, idx) in [...elements].reverse()" :key="item.id">
                    <li
                        class="mb-1 flex cursor-grab items-center gap-2 rounded border px-2 py-1 transition-colors"
                        :class="selectedId === item.id ? 'border-indigo-200 bg-indigo-50/90 ring-1 ring-indigo-200' : 'border-slate-100 bg-slate-50 hover:border-slate-200 hover:bg-slate-100'"
                        data-layer-row
                        @click="select(item.id)"
                    >
                        <span class="w-6 shrink-0 text-[10px] text-slate-400" x-text="idx + 1"></span>
                        <span class="truncate text-xs text-slate-500" x-text="item.type"></span>
                        <span class="min-w-0 flex-1 truncate" x-text="item.content || item.src || item.id"></span>
                    </li>
                </template>
            </ul>
            <div class="border-t border-slate-100 px-3 py-2 text-xs font-medium uppercase tracking-wide text-slate-500" x-show="!editorSidebarCollapsed">
                <?php echo esc_html__('JSON', 'eko-sampa'); ?>
            </div>
            <pre class="min-h-0 flex-1 overflow-auto p-2 font-mono text-[10px] leading-relaxed text-slate-800 lg:max-h-48" x-show="!editorSidebarCollapsed" x-text="elementsJson"></pre>
        </aside>
    </div>

    <div
        x-show="!previewOnly && selectedElement"
        x-cloak
        class="fixed z-[60] w-[min(18rem,calc(100vw-1.5rem))] max-h-[min(32rem,calc(100vh-5rem))] overflow-hidden rounded-lg border border-slate-200 bg-white text-xs shadow-xl ring-1 ring-slate-900/5 pointer-events-auto"
        :style="propsPanelPositionStyle()"
        role="dialog"
        aria-label="<?php echo esc_attr__('Element properties', 'eko-sampa'); ?>"
    >
        <div
            class="flex cursor-grab select-none items-center gap-2 border-b border-slate-100 bg-slate-50 px-2 py-1.5 active:cursor-grabbing"
            @mousedown.prevent="startPropsPanelDrag($event)"
        >
            <svg class="h-4 w-4 shrink-0 text-slate-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9.75h16.5m-16.5 6.75h16.5" /></svg>
            <span class="min-w-0 flex-1 truncate text-[11px] font-semibold uppercase tracking-wide text-slate-600"><?php echo esc_html__('Properties', 'eko-sampa'); ?></span>
            <span class="truncate text-[10px] font-normal normal-case text-slate-400" x-text="selectedElement ? selectedElement.type : ''"></span>
        </div>
        <div class="max-h-[min(28rem,calc(100vh-8rem))] overflow-y-auto p-3">
            <template x-if="selectedElement && (selectedElement.type === 'text' || selectedElement.type === 'placeholder')">
                <div class="space-y-3">
                    <p class="text-[11px] font-medium text-slate-600" x-text="selectedElement.type === 'placeholder' ? '<?php echo esc_attr__('Placeholder', 'eko-sampa'); ?>' : '<?php echo esc_attr__('Text', 'eko-sampa'); ?>'"></p>
                    <label class="block">
                        <span class="mb-0.5 block text-slate-500"><?php echo esc_html__('Font', 'eko-sampa'); ?></span>
                        <select class="w-full rounded border border-slate-200 bg-white px-1 py-1" x-model="selectedElement.styles.fontFamily">
                            <option value="system-ui, -apple-system, Segoe UI, Roboto, sans-serif">System UI</option>
                            <option value="Georgia, serif">Georgia</option>
                            <option value="'Times New Roman', Times, serif">Times New Roman</option>
                            <option value="Arial, Helvetica, sans-serif">Arial</option>
                            <option value="Verdana, Geneva, sans-serif">Verdana</option>
                            <option value="'Courier New', Courier, monospace">Courier New</option>
                        </select>
                    </label>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="block">
                            <span class="mb-0.5 block text-slate-500"><?php echo esc_html__('Size', 'eko-sampa'); ?></span>
                            <input class="w-full rounded border border-slate-200 px-1 py-0.5" type="number" min="6" max="200" x-model.number="selectedElement.styles.fontSize" />
                        </label>
                        <label class="block">
                            <span class="mb-0.5 block text-slate-500"><?php echo esc_html__('Weight', 'eko-sampa'); ?></span>
                            <select class="w-full rounded border border-slate-200 bg-white px-1 py-1" x-model="selectedElement.styles.fontWeight">
                                <option value="300">300</option>
                                <option value="400">400</option>
                                <option value="500">500</option>
                                <option value="600">600</option>
                                <option value="700">700</option>
                                <option value="800">800</option>
                            </select>
                        </label>
                    </div>
                    <div class="flex flex-wrap gap-1">
                        <button type="button" class="inline-flex h-8 w-8 items-center justify-center rounded border transition" :class="selectedElement.styles.fontStyle === 'italic' ? 'border-indigo-500 bg-indigo-50 text-indigo-900' : 'border-slate-200 bg-white hover:bg-slate-50'" @click="selectedElement.styles.fontStyle = selectedElement.styles.fontStyle === 'italic' ? 'normal' : 'italic'" title="<?php echo esc_attr__('Italic', 'eko-sampa'); ?>">
                            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5l-2 14M15 5l-2 14" /></svg>
                        </button>
                        <button type="button" class="inline-flex h-8 w-8 items-center justify-center rounded border transition" :class="selectedElement.styles.textDecoration && selectedElement.styles.textDecoration.indexOf('underline') !== -1 ? 'border-indigo-500 bg-indigo-50 text-indigo-900' : 'border-slate-200 bg-white hover:bg-slate-50'" @click="selectedElement.styles.textDecoration = (selectedElement.styles.textDecoration && selectedElement.styles.textDecoration.indexOf('underline') !== -1) ? 'none' : 'underline'" title="<?php echo esc_attr__('Underline', 'eko-sampa'); ?>">
                            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 19.5h15M6 4.5v9a6 6 0 0012 0v-9" /></svg>
                        </button>
                    </div>
                    <div class="flex flex-wrap gap-1">
                        <button type="button" class="inline-flex h-8 w-8 items-center justify-center rounded border" :class="selectedElement.styles.textAlign === 'left' ? 'border-indigo-500 bg-indigo-50 text-indigo-900' : 'border-slate-200 bg-white hover:bg-slate-50'" @click="selectedElement.styles.textAlign = 'left'" title="<?php echo esc_attr__('Align left', 'eko-sampa'); ?>">
                            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h10.5M3.75 17.25h13.5" /></svg>
                        </button>
                        <button type="button" class="inline-flex h-8 w-8 items-center justify-center rounded border" :class="selectedElement.styles.textAlign === 'center' ? 'border-indigo-500 bg-indigo-50 text-indigo-900' : 'border-slate-200 bg-white hover:bg-slate-50'" @click="selectedElement.styles.textAlign = 'center'" title="<?php echo esc_attr__('Align center', 'eko-sampa'); ?>">
                            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M6.75 12h10.5M4.5 17.25h15" /></svg>
                        </button>
                        <button type="button" class="inline-flex h-8 w-8 items-center justify-center rounded border" :class="selectedElement.styles.textAlign === 'right' ? 'border-indigo-500 bg-indigo-50 text-indigo-900' : 'border-slate-200 bg-white hover:bg-slate-50'" @click="selectedElement.styles.textAlign = 'right'" title="<?php echo esc_attr__('Align right', 'eko-sampa'); ?>">
                            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M9 12h10.5M6 17.25h13.5" /></svg>
                        </button>
                        <button type="button" class="inline-flex h-8 w-8 items-center justify-center rounded border" :class="selectedElement.styles.textAlign === 'justify' ? 'border-indigo-500 bg-indigo-50 text-indigo-900' : 'border-slate-200 bg-white hover:bg-slate-50'" @click="selectedElement.styles.textAlign = 'justify'" title="<?php echo esc_attr__('Justify', 'eko-sampa'); ?>">
                            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5" /></svg>
                        </button>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="block">
                            <span class="mb-0.5 block text-slate-500"><?php echo esc_html__('Text color', 'eko-sampa'); ?></span>
                            <input class="h-8 w-full cursor-pointer rounded border border-slate-200" type="color" :value="selectedElement.styles.color" @input="selectedElement.styles.color = $event.target.value" />
                        </label>
                        <label class="block">
                            <span class="mb-0.5 block text-slate-500"><?php echo esc_html__('Background', 'eko-sampa'); ?></span>
                            <input class="h-8 w-full cursor-pointer rounded border border-slate-200" type="color" :value="selectedElement.styles.backgroundColor === 'transparent' ? '#ffffff' : selectedElement.styles.backgroundColor" @input="selectedElement.styles.backgroundColor = $event.target.value" />
                        </label>
                    </div>
                    <label class="flex items-center gap-2">
                        <span class="text-slate-500"><?php echo esc_html__('Opacity', 'eko-sampa'); ?></span>
                        <input class="flex-1 accent-indigo-600" type="range" min="0.1" max="1" step="0.05" x-model.number="selectedElement.styles.opacity" />
                        <span class="w-8 tabular-nums text-slate-600" x-text="Math.round((selectedElement.styles.opacity || 1) * 100) + '%'"></span>
                    </label>
                    <div class="grid grid-cols-3 gap-2">
                        <label class="block col-span-1">
                            <span class="mb-0.5 block text-slate-500"><?php echo esc_html__('Border', 'eko-sampa'); ?></span>
                            <input class="w-full rounded border border-slate-200 px-1 py-0.5" type="number" min="0" max="40" x-model.number="selectedElement.styles.borderWidth" />
                        </label>
                        <label class="block col-span-2">
                            <span class="mb-0.5 block text-slate-500"><?php echo esc_html__('Border style', 'eko-sampa'); ?></span>
                            <select class="w-full rounded border border-slate-200 bg-white px-1 py-1" x-model="selectedElement.styles.borderStyle">
                                <option value="none"><?php echo esc_html__('None', 'eko-sampa'); ?></option>
                                <option value="solid"><?php echo esc_html__('Solid', 'eko-sampa'); ?></option>
                                <option value="dashed"><?php echo esc_html__('Dashed', 'eko-sampa'); ?></option>
                                <option value="dotted"><?php echo esc_html__('Dotted', 'eko-sampa'); ?></option>
                            </select>
                        </label>
                    </div>
                    <label class="block">
                        <span class="mb-0.5 block text-slate-500"><?php echo esc_html__('Border color', 'eko-sampa'); ?></span>
                        <input class="h-8 w-full max-w-[8rem] cursor-pointer rounded border border-slate-200" type="color" :value="selectedElement.styles.borderColor" @input="selectedElement.styles.borderColor = $event.target.value" />
                    </label>
                    <label class="block">
                        <span class="mb-0.5 block text-slate-500"><?php echo esc_html__('Radius', 'eko-sampa'); ?></span>
                        <input class="w-full rounded border border-slate-200 px-1 py-0.5" type="number" min="0" max="400" x-model.number="selectedElement.styles.borderRadius" />
                    </label>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="block">
                            <span class="mb-0.5 block text-slate-500"><?php echo esc_html__('Line height', 'eko-sampa'); ?></span>
                            <input class="w-full rounded border border-slate-200 px-1 py-0.5" type="number" min="0.8" max="3" step="0.05" x-model.number="selectedElement.styles.lineHeight" />
                        </label>
                        <label class="block">
                            <span class="mb-0.5 block text-slate-500"><?php echo esc_html__('Letter spacing', 'eko-sampa'); ?></span>
                            <input class="w-full rounded border border-slate-200 px-1 py-0.5" type="number" min="-5" max="20" step="0.5" x-model.number="selectedElement.styles.letterSpacing" />
                        </label>
                    </div>
                    <label class="block">
                        <span class="mb-0.5 block text-slate-500"><?php echo esc_html__('Transform', 'eko-sampa'); ?></span>
                        <select class="w-full rounded border border-slate-200 bg-white px-1 py-1" x-model="selectedElement.styles.textTransform">
                            <option value="none"><?php echo esc_html__('None', 'eko-sampa'); ?></option>
                            <option value="uppercase"><?php echo esc_html__('Uppercase', 'eko-sampa'); ?></option>
                            <option value="lowercase"><?php echo esc_html__('Lowercase', 'eko-sampa'); ?></option>
                            <option value="capitalize"><?php echo esc_html__('Capitalize', 'eko-sampa'); ?></option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="mb-0.5 block text-slate-500"><?php echo esc_html__('Shadow', 'eko-sampa'); ?></span>
                        <select class="w-full rounded border border-slate-200 bg-white px-1 py-1" x-model="selectedElement.styles.boxShadow">
                            <option value="none"><?php echo esc_html__('None', 'eko-sampa'); ?></option>
                            <option value="0 1px 2px rgba(15,23,42,0.08)"><?php echo esc_html__('Small', 'eko-sampa'); ?></option>
                            <option value="0 4px 12px rgba(15,23,42,0.12)"><?php echo esc_html__('Medium', 'eko-sampa'); ?></option>
                            <option value="0 12px 32px rgba(15,23,42,0.18)"><?php echo esc_html__('Large', 'eko-sampa'); ?></option>
                        </select>
                    </label>
                </div>
            </template>
            <template x-if="selectedElement && selectedElement.type === 'image'">
                    <div class="space-y-3">
                        <p class="text-[11px] font-medium text-slate-600"><?php echo esc_html__('Image', 'eko-sampa'); ?></p>
                        <p class="text-[10px] leading-snug text-slate-500"><?php echo esc_html__('Object fit: use Contain / Cover below (default is cover).', 'eko-sampa'); ?></p>
                        <label class="flex items-center gap-2">
                            <span class="text-slate-500"><?php echo esc_html__('Opacity', 'eko-sampa'); ?></span>
                            <input class="flex-1 accent-indigo-600" type="range" min="0.1" max="1" step="0.05" x-model.number="selectedElement.styles.opacity" />
                            <span class="w-8 tabular-nums text-slate-600" x-text="Math.round((selectedElement.styles.opacity || 1) * 100) + '%'"></span>
                        </label>
                        <label class="block">
                            <span class="mb-0.5 block text-slate-500"><?php echo esc_html__('Radius', 'eko-sampa'); ?></span>
                            <input class="w-full rounded border border-slate-200 px-1 py-0.5" type="number" min="0" max="400" x-model.number="selectedElement.styles.borderRadius" />
                        </label>
                        <div class="grid grid-cols-3 gap-2">
                            <label class="block col-span-1">
                                <span class="mb-0.5 block text-slate-500"><?php echo esc_html__('Border', 'eko-sampa'); ?></span>
                                <input class="w-full rounded border border-slate-200 px-1 py-0.5" type="number" min="0" max="40" x-model.number="selectedElement.styles.borderWidth" />
                            </label>
                            <label class="block col-span-2">
                                <span class="mb-0.5 block text-slate-500"><?php echo esc_html__('Border style', 'eko-sampa'); ?></span>
                                <select class="w-full rounded border border-slate-200 bg-white px-1 py-1" x-model="selectedElement.styles.borderStyle">
                                    <option value="none"><?php echo esc_html__('None', 'eko-sampa'); ?></option>
                                    <option value="solid"><?php echo esc_html__('Solid', 'eko-sampa'); ?></option>
                                    <option value="dashed"><?php echo esc_html__('Dashed', 'eko-sampa'); ?></option>
                                    <option value="dotted"><?php echo esc_html__('Dotted', 'eko-sampa'); ?></option>
                                </select>
                            </label>
                        </div>
                        <label class="block">
                            <span class="mb-0.5 block text-slate-500"><?php echo esc_html__('Border color', 'eko-sampa'); ?></span>
                            <input class="h-8 w-full max-w-[8rem] cursor-pointer rounded border border-slate-200" type="color" :value="selectedElement.styles.borderColor" @input="selectedElement.styles.borderColor = $event.target.value" />
                        </label>
                        <label class="flex items-center gap-2">
                            <span class="w-14 text-slate-500"><?php echo esc_html__('Rotate', 'eko-sampa'); ?></span>
                            <input class="flex-1 accent-indigo-600" type="range" min="-180" max="180" step="1" x-model.number="selectedElement.styles.rotate" />
                            <span class="w-10 text-right tabular-nums text-slate-600" x-text="(selectedElement.styles.rotate || 0) + '°'"></span>
                        </label>
                        <label class="block">
                            <span class="mb-0.5 block text-slate-500"><?php echo esc_html__('Shadow', 'eko-sampa'); ?></span>
                            <select class="w-full rounded border border-slate-200 bg-white px-1 py-1" x-model="selectedElement.styles.boxShadow">
                                <option value="none"><?php echo esc_html__('None', 'eko-sampa'); ?></option>
                                <option value="0 1px 2px rgba(15,23,42,0.08)"><?php echo esc_html__('Small', 'eko-sampa'); ?></option>
                                <option value="0 4px 12px rgba(15,23,42,0.12)"><?php echo esc_html__('Medium', 'eko-sampa'); ?></option>
                                <option value="0 12px 32px rgba(15,23,42,0.18)"><?php echo esc_html__('Large', 'eko-sampa'); ?></option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="mb-0.5 block text-slate-500"><?php echo esc_html__('Fit', 'eko-sampa'); ?></span>
                            <div class="flex gap-1">
                                <button type="button" class="flex-1 rounded border px-2 py-1" :class="selectedElement.styles.objectFit === 'contain' ? 'border-indigo-500 bg-indigo-50' : 'border-slate-200'" @click="selectedElement.styles.objectFit = 'contain'"><?php echo esc_html__('Contain', 'eko-sampa'); ?></button>
                                <button type="button" class="flex-1 rounded border px-2 py-1" :class="selectedElement.styles.objectFit === 'cover' ? 'border-indigo-500 bg-indigo-50' : 'border-slate-200'" @click="selectedElement.styles.objectFit = 'cover'"><?php echo esc_html__('Cover', 'eko-sampa'); ?></button>
                            </div>
                        </label>
                    </div>
            </template>
            <template x-if="selectedElement && selectedElement.type === 'rectangle'">
                    <p class="text-[11px] leading-relaxed text-slate-500"><?php echo esc_html__('Rectangle: adjust size and position on the canvas.', 'eko-sampa'); ?></p>
            </template>
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
