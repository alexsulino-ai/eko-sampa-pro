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
    class="<?php echo esc_attr($eko_editor_root_class); ?> transition-opacity duration-150 ease-out"
    x-data="window.ekoEditorCanvasFactory()"
    @keydown.window="editorWindowKeydown($event)"
>
    <?php do_action('eko_sampa_editor_canvas_shell_ready'); ?>
    <header class="eko-sampa-editor__toolbar flex shrink-0 flex-wrap items-center gap-2 border-b border-slate-200 bg-white px-3 py-2 md:px-4">
        <div
            x-show="!previewOnly && showSessionRecoveryBanner && !sessionRecoveryDismissed"
            x-cloak
            class="flex w-full basis-full flex-col gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-950 sm:flex-row sm:items-center sm:justify-between"
        >
            <span class="font-medium"><?php echo esc_html__('Encontrámos uma personalização em curso neste dispositivo.', 'eko-sampa'); ?></span>
            <div class="flex flex-wrap gap-2">
                <button type="button" class="rounded-lg bg-amber-800 px-3 py-1 text-[11px] font-semibold text-white hover:bg-amber-900" @click="sessionRecoveryDismissed = true"><?php echo esc_html__('Continuar edição', 'eko-sampa'); ?></button>
                <button type="button" class="rounded-lg border border-amber-300 bg-white px-3 py-1 text-[11px] font-medium text-amber-900 hover:bg-amber-100" @click="discardGuestRecovery()"><?php echo esc_html__('Descartar aviso', 'eko-sampa'); ?></button>
            </div>
        </div>
        <h1 class="text-sm font-semibold text-slate-900 md:text-base">
            <?php echo esc_html__('Visual editor', 'eko-sampa'); ?>
            <span
                x-show="cfg().guestEditor"
                x-cloak
                class="ml-2 inline-block rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-900"
                title="<?php echo esc_attr__('Sessão temporária: se sair sem guardar em Meus templates, este trabalho pode ser removido automaticamente.', 'eko-sampa'); ?>"
            ><?php echo esc_html__('Sessão temporária', 'eko-sampa'); ?></span>
        </h1>
        <template x-if="!previewOnly">
            <div class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    class="rounded border border-indigo-600 bg-indigo-600 px-2 py-1 text-xs font-medium text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-40"
                    x-show="Number(cfg().templateId || 0) > 0"
                    :disabled="_persistRunning || !hasUnsavedChanges"
                    @click="saveNow()"
                ><?php echo esc_html__('Salvar', 'eko-sampa'); ?></button>
                <button
                    type="button"
                    class="rounded border border-slate-200 bg-white px-2 py-1 text-xs hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700"
                    x-show="quickPrintFeatureEnabled()"
                    @click="openQuickPrintManager()"
                    title="<?php echo esc_attr__('Quick print manager (saved template)', 'eko-sampa'); ?>"
                >
                    <span class="inline-flex items-center gap-1">
                        <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M6 18h12M6 14h12M4 22h16a2 2 0 0 0 2-2v-4a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v4a2 2 0 0 0 2 2Z" />
                            <path d="M6 10V6a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v4" />
                        </svg>
                        <span><?php echo esc_html__('Imprimir', 'eko-sampa'); ?></span>
                    </span>
                </button>
                <button
                    type="button"
                    class="rounded border border-indigo-200 bg-indigo-50 px-2 py-1 text-xs font-medium text-indigo-800 hover:bg-indigo-100 dark:border-indigo-500/40 dark:bg-indigo-950/60 dark:text-indigo-100 dark:hover:bg-indigo-900/50"
                    x-show="canCreateOrderFromEditor()"
                    @click="createOrderFromEditor()"
                    title="<?php echo esc_attr__('Create order from this template (same flow as template details)', 'eko-sampa'); ?>"
                ><?php echo esc_html__('Create order', 'eko-sampa'); ?></button>
                <button
                    type="button"
                    class="rounded border border-emerald-200 bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-900 hover:bg-emerald-100"
                    x-show="sessionTokenPresent()"
                    @click="persistToMyTemplates()"
                    :disabled="_persistRunning"
                    title="<?php echo esc_attr__('Cria uma cópia na sua biblioteca e fecha esta sessão de trabalho.', 'eko-sampa'); ?>"
                ><?php echo esc_html__('Salvar em Meus Templates', 'eko-sampa'); ?></button>
                <span class="hidden h-6 w-px bg-slate-200 sm:inline-block" aria-hidden="true"></span>
                <button type="button" class="rounded border border-slate-200 bg-white px-2 py-1 text-xs hover:bg-slate-50" @click="addText()"><?php echo esc_html__('Text', 'eko-sampa'); ?></button>
                <button type="button" class="rounded border border-slate-200 bg-white px-2 py-1 text-xs hover:bg-slate-50" @click="addPlaceholder()"><?php echo esc_html__('Placeholder', 'eko-sampa'); ?></button>
                <button type="button" class="rounded border border-slate-200 bg-white px-2 py-1 text-xs hover:bg-slate-50" @click="addRectangle()"><?php echo esc_html__('Rectangle', 'eko-sampa'); ?></button>
                <button type="button" class="rounded border border-indigo-200 bg-indigo-50 px-2 py-1 text-xs text-indigo-800 hover:bg-indigo-100" @click="openGallery()"><?php echo esc_html__('Gallery', 'eko-sampa'); ?></button>
                <button type="button" class="rounded border border-red-200 bg-red-50 px-2 py-1 text-xs text-red-700 hover:bg-red-100" @click="deleteSelected()"><?php echo esc_html__('Delete', 'eko-sampa'); ?></button>
                <button
                    type="button"
                    class="rounded border border-slate-200 bg-white px-2 py-1 text-xs hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
                    :disabled="!selectedId"
                    @click="duplicateElement()"
                    title="<?php echo esc_attr__('Duplicar elemento selecionado', 'eko-sampa'); ?>"
                ><?php echo esc_html__('Duplicar', 'eko-sampa'); ?></button>
                <button type="button" class="rounded border border-slate-200 bg-white px-2 py-1 text-xs hover:bg-slate-50" @click="bringForward()" title="<?php echo esc_attr__('Move selected one step toward front (same as dragging up in the layer list)', 'eko-sampa'); ?>"><?php echo esc_html__('Bring forward', 'eko-sampa'); ?></button>
                <button type="button" class="rounded border border-slate-200 bg-white px-2 py-1 text-xs hover:bg-slate-50" @click="sendBackward()" title="<?php echo esc_attr__('Move selected one step toward back', 'eko-sampa'); ?>"><?php echo esc_html__('Send backward', 'eko-sampa'); ?></button>
                <template x-if="selectedId">
                    <div class="ml-1 flex flex-wrap items-center gap-1 border-l border-slate-200 pl-2">
                        <span class="text-[10px] font-medium uppercase tracking-wide text-slate-400"><?php echo esc_html__('Canvas', 'eko-sampa'); ?></span>
                        <button type="button" class="rounded border border-slate-200 bg-white px-1.5 py-0.5 text-[11px] font-semibold tabular-nums hover:bg-slate-50" @click="alignElementLeft()" title="<?php echo esc_attr__('Align element to left edge of canvas', 'eko-sampa'); ?>">L</button>
                        <button type="button" class="rounded border border-slate-200 bg-white px-1.5 py-0.5 text-[11px] font-semibold tabular-nums hover:bg-slate-50" @click="alignElementCenterHorizontal()" title="<?php echo esc_attr__('Center element horizontally on canvas', 'eko-sampa'); ?>">H</button>
                        <button type="button" class="rounded border border-slate-200 bg-white px-1.5 py-0.5 text-[11px] font-semibold tabular-nums hover:bg-slate-50" @click="alignElementRight()" title="<?php echo esc_attr__('Align element to right edge of canvas', 'eko-sampa'); ?>">R</button>
                        <button type="button" class="rounded border border-slate-200 bg-white px-1.5 py-0.5 text-[11px] font-semibold tabular-nums hover:bg-slate-50" @click="alignElementTop()" title="<?php echo esc_attr__('Align element to top edge of canvas', 'eko-sampa'); ?>">T</button>
                        <button type="button" class="rounded border border-slate-200 bg-white px-1.5 py-0.5 text-[11px] font-semibold tabular-nums hover:bg-slate-50" @click="alignElementCenterVertical()" title="<?php echo esc_attr__('Center element vertically on canvas', 'eko-sampa'); ?>">V</button>
                        <button type="button" class="rounded border border-slate-200 bg-white px-1.5 py-0.5 text-[11px] font-semibold tabular-nums hover:bg-slate-50" @click="alignElementBottom()" title="<?php echo esc_attr__('Align element to bottom edge of canvas', 'eko-sampa'); ?>">B</button>
                    </div>
                </template>
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
                            class="eko-sampa-editor__canvas relative shrink-0 bg-white"
                            :style="canvasSurfaceStyle()"
                            x-ref="editorCanvas"
                            role="application"
                            @mousedown.self="clearSelectionIfCanvas($event)"
                        >
                            <template x-for="(item, idx) in elements" :key="item.id">
                                <div
                                    class="eko-sampa-editor__element absolute touch-none select-none"
                                    :data-element-id="item.id"
                                    :data-layer-index="idx"
                                    :style="elementPositionStyle(item, idx)"
                                    @mousedown="select(item.id)"
                                    @dblclick.prevent="(item.type === 'text' || item.type === 'placeholder') && openInlineEdit(item)"
                                >
                                    <div class="eko-sampa-editor__rotate-wrap absolute inset-0 min-h-0 min-w-0" :style="editorRotateWrapStyle(item)">
                                    <template x-if="item.type === 'image'">
                                        <div class="pointer-events-none absolute inset-0" :style="editorImageFrameStyle(item)">
                                            <img class="pointer-events-none h-full w-full max-h-full max-w-full" :style="imageImgCss(item)" :src="item.src || item.content" alt="" />
                                        </div>
                                    </template>
                                    <template x-if="item.type === 'text' || item.type === 'placeholder'">
                                        <div class="pointer-events-none absolute inset-0" :style="editorTextFrameStyle(item)">
                                            <div class="eko-sampa-editor__inline-hit flex min-h-0 min-w-0 flex-1 flex-col" :class="inlineOpen && String(inlineTargetId) === String(item.id) ? 'pointer-events-auto' : 'pointer-events-none cursor-text'">
                                                <div class="pointer-events-none flex min-h-0 min-w-0 flex-1 flex-col" :style="textVerticalWrapCss(item)">
                                                    <span
                                                        class="pointer-events-none box-border block min-h-0 min-w-0 whitespace-pre-wrap break-words"
                                                        :style="textContentCss(item)"
                                                        x-show="!(inlineOpen && String(inlineTargetId) === String(item.id))"
                                                        x-text="item.content"
                                                    ></span>
                                                    <template x-if="inlineOpen && String(inlineTargetId) === String(item.id)">
                                                        <textarea
                                                            :id="'eko-inline-edit-' + item.id"
                                                            class="eko-sampa-editor__inline-field box-border block min-h-0 min-w-0 whitespace-pre-wrap break-words"
                                                            :style="textContentCss(item) + ';' + inlineEditorTextareaCss()"
                                                            x-model="inlineValue"
                                                            placeholder="<?php echo esc_attr__('Ctrl+Enter to save · Line breaks allowed', 'eko-sampa'); ?>"
                                                            @mousedown.stop
                                                            @click.stop
                                                            @keydown.escape.prevent="cancelInlineEdit()"
                                                            @keydown.ctrl.enter.prevent="confirmInlineEdit()"
                                                        ></textarea>
                                                    </template>
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                    <template x-if="item.type === 'rectangle'">
                                        <div class="pointer-events-none absolute inset-0" :style="editorRectangleFrameStyle(item)"></div>
                                    </template>

                                    <template x-if="selectedId === item.id && !(inlineOpen && String(inlineTargetId) === String(item.id)) && !previewOnly">
                                        <div>
                                            <span class="eko-sampa-editor__resize-handle eko-resize-l eko-resize-t pointer-events-auto absolute left-0 top-0 z-[60] h-3.5 w-3.5 -ml-[7px] -mt-[7px] cursor-nwse-resize rounded-full border-2 border-white bg-indigo-500" aria-hidden="true"></span>
                                            <span class="eko-sampa-editor__resize-handle eko-resize-t pointer-events-auto absolute left-1/2 top-0 z-[60] h-3.5 w-3.5 -ml-[7px] -mt-[7px] cursor-ns-resize rounded-full border-2 border-white bg-indigo-500" aria-hidden="true"></span>
                                            <span class="eko-sampa-editor__resize-handle eko-resize-r eko-resize-t pointer-events-auto absolute right-0 top-0 z-[60] h-3.5 w-3.5 -mr-[7px] -mt-[7px] cursor-nesw-resize rounded-full border-2 border-white bg-indigo-500" aria-hidden="true"></span>
                                            <span class="eko-sampa-editor__resize-handle eko-resize-r pointer-events-auto absolute right-0 top-1/2 z-[60] h-3.5 w-3.5 -mr-[7px] -mt-[7px] cursor-ew-resize rounded-full border-2 border-white bg-indigo-500" aria-hidden="true"></span>
                                            <span class="eko-sampa-editor__resize-handle eko-resize-r eko-resize-b pointer-events-auto absolute bottom-0 right-0 z-[60] h-3.5 w-3.5 -mr-[7px] -mb-[7px] cursor-nwse-resize rounded-full border-2 border-white bg-indigo-500" aria-hidden="true"></span>
                                            <span class="eko-sampa-editor__resize-handle eko-resize-b pointer-events-auto absolute bottom-0 left-1/2 z-[60] h-3.5 w-3.5 -ml-[7px] -mb-[7px] cursor-ns-resize rounded-full border-2 border-white bg-indigo-500" aria-hidden="true"></span>
                                            <span class="eko-sampa-editor__resize-handle eko-resize-l eko-resize-b pointer-events-auto absolute bottom-0 left-0 z-[60] h-3.5 w-3.5 -ml-[7px] -mb-[7px] cursor-nesw-resize rounded-full border-2 border-white bg-indigo-500" aria-hidden="true"></span>
                                            <span class="eko-sampa-editor__resize-handle eko-resize-l pointer-events-auto absolute left-0 top-1/2 z-[60] h-3.5 w-3.5 -ml-[7px] -mt-[7px] cursor-ew-resize rounded-full border-2 border-white bg-indigo-500" aria-hidden="true"></span>
                                            <button
                                                type="button"
                                                class="eko-sampa-editor__rotate-fab pointer-events-auto absolute z-[70] flex h-9 w-9 cursor-grab items-center justify-center rounded-full border-2 border-white bg-indigo-600 text-white shadow-md transition-shadow duration-150 hover:bg-indigo-700 active:cursor-grabbing"
                                                :class="{ 'ring-4 ring-emerald-400 ring-offset-2 ring-offset-white shadow-lg': String(_rotateSnapPulseItemId) === String(item.id) }"
                                                style="right: -22px; top: -22px"
                                                title="<?php echo esc_attr__('Drag to rotate', 'eko-sampa'); ?>"
                                                aria-label="<?php echo esc_attr__('Rotate', 'eko-sampa'); ?>"
                                                @pointerdown.stop="editorRotateFabPointerDown($event, item)"
                                            >
                                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                    <path d="M21 12a9 9 0 1 1-3-6.7" />
                                                    <polyline points="21 3 21 9 15 9" />
                                                </svg>
                                            </button>
                                        </div>
                                    </template>
                                    </div>
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
                    <label class="block">
                        <span class="mb-0.5 block text-slate-500"><?php echo esc_html__('Vertical align', 'eko-sampa'); ?></span>
                        <select class="w-full rounded border border-slate-200 bg-white px-1 py-1" x-model="selectedElement.styles.alignVertical">
                            <option value="top"><?php echo esc_html__('Top', 'eko-sampa'); ?></option>
                            <option value="center"><?php echo esc_html__('Center', 'eko-sampa'); ?></option>
                            <option value="bottom"><?php echo esc_html__('Bottom', 'eko-sampa'); ?></option>
                        </select>
                    </label>
                    <p class="text-[10px] font-medium text-slate-500"><?php echo esc_html__('Padding (frame)', 'eko-sampa'); ?></p>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="block">
                            <span class="mb-0.5 block text-slate-500"><?php echo esc_html__('Top', 'eko-sampa'); ?></span>
                            <input class="w-full rounded border border-slate-200 px-1 py-0.5" type="number" min="0" max="120" x-model.number="selectedElement.styles.paddingTop" />
                        </label>
                        <label class="block">
                            <span class="mb-0.5 block text-slate-500"><?php echo esc_html__('Right', 'eko-sampa'); ?></span>
                            <input class="w-full rounded border border-slate-200 px-1 py-0.5" type="number" min="0" max="120" x-model.number="selectedElement.styles.paddingRight" />
                        </label>
                        <label class="block">
                            <span class="mb-0.5 block text-slate-500"><?php echo esc_html__('Bottom', 'eko-sampa'); ?></span>
                            <input class="w-full rounded border border-slate-200 px-1 py-0.5" type="number" min="0" max="120" x-model.number="selectedElement.styles.paddingBottom" />
                        </label>
                        <label class="block">
                            <span class="mb-0.5 block text-slate-500"><?php echo esc_html__('Left', 'eko-sampa'); ?></span>
                            <input class="w-full rounded border border-slate-200 px-1 py-0.5" type="number" min="0" max="120" x-model.number="selectedElement.styles.paddingLeft" />
                        </label>
                    </div>
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
                    <label class="flex items-center gap-2">
                        <span class="w-14 shrink-0 text-slate-500"><?php echo esc_html__('Rotate', 'eko-sampa'); ?></span>
                        <input class="flex-1 accent-indigo-600" type="range" min="0" max="359" step="1" x-model.number="selectedElement.styles.rotate" @change="editorRotateSidebarCommit()" />
                        <span class="w-10 shrink-0 text-right tabular-nums text-slate-600" x-text="formatRotateDisplayDeg(selectedElement.styles.rotate)"></span>
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
                        <p class="text-[10px] font-medium text-slate-500"><?php echo esc_html__('Padding (frame)', 'eko-sampa'); ?></p>
                        <div class="grid grid-cols-2 gap-2">
                            <label class="block">
                                <span class="mb-0.5 block text-slate-500"><?php echo esc_html__('Top', 'eko-sampa'); ?></span>
                                <input class="w-full rounded border border-slate-200 px-1 py-0.5" type="number" min="0" max="120" x-model.number="selectedElement.styles.paddingTop" />
                            </label>
                            <label class="block">
                                <span class="mb-0.5 block text-slate-500"><?php echo esc_html__('Right', 'eko-sampa'); ?></span>
                                <input class="w-full rounded border border-slate-200 px-1 py-0.5" type="number" min="0" max="120" x-model.number="selectedElement.styles.paddingRight" />
                            </label>
                            <label class="block">
                                <span class="mb-0.5 block text-slate-500"><?php echo esc_html__('Bottom', 'eko-sampa'); ?></span>
                                <input class="w-full rounded border border-slate-200 px-1 py-0.5" type="number" min="0" max="120" x-model.number="selectedElement.styles.paddingBottom" />
                            </label>
                            <label class="block">
                                <span class="mb-0.5 block text-slate-500"><?php echo esc_html__('Left', 'eko-sampa'); ?></span>
                                <input class="w-full rounded border border-slate-200 px-1 py-0.5" type="number" min="0" max="120" x-model.number="selectedElement.styles.paddingLeft" />
                            </label>
                        </div>
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
                            <input class="flex-1 accent-indigo-600" type="range" min="0" max="359" step="1" x-model.number="selectedElement.styles.rotate" @change="editorRotateSidebarCommit()" />
                            <span class="w-10 text-right tabular-nums text-slate-600" x-text="formatRotateDisplayDeg(selectedElement.styles.rotate)"></span>
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
                    <div class="space-y-3">
                        <p class="text-[11px] font-medium text-slate-600"><?php echo esc_html__('Rectangle', 'eko-sampa'); ?></p>
                        <p class="text-[10px] leading-snug text-slate-500"><?php echo esc_html__('Size and position: drag edges on the canvas. Rotation applies to the inner frame only.', 'eko-sampa'); ?></p>
                        <label class="flex items-center gap-2">
                            <span class="text-slate-500"><?php echo esc_html__('Opacity', 'eko-sampa'); ?></span>
                            <input class="flex-1 accent-indigo-600" type="range" min="0.1" max="1" step="0.05" x-model.number="selectedElement.styles.opacity" />
                            <span class="w-8 tabular-nums text-slate-600" x-text="Math.round((selectedElement.styles.opacity || 1) * 100) + '%'"></span>
                        </label>
                        <label class="block">
                            <span class="mb-0.5 block text-slate-500"><?php echo esc_html__('Radius', 'eko-sampa'); ?></span>
                            <input class="w-full rounded border border-slate-200 px-1 py-0.5" type="number" min="0" max="400" x-model.number="selectedElement.styles.borderRadius" />
                        </label>
                        <p class="text-[10px] font-medium text-slate-500"><?php echo esc_html__('Padding (frame)', 'eko-sampa'); ?></p>
                        <div class="grid grid-cols-2 gap-2">
                            <label class="block">
                                <span class="mb-0.5 block text-slate-500"><?php echo esc_html__('Top', 'eko-sampa'); ?></span>
                                <input class="w-full rounded border border-slate-200 px-1 py-0.5" type="number" min="0" max="120" x-model.number="selectedElement.styles.paddingTop" />
                            </label>
                            <label class="block">
                                <span class="mb-0.5 block text-slate-500"><?php echo esc_html__('Right', 'eko-sampa'); ?></span>
                                <input class="w-full rounded border border-slate-200 px-1 py-0.5" type="number" min="0" max="120" x-model.number="selectedElement.styles.paddingRight" />
                            </label>
                            <label class="block">
                                <span class="mb-0.5 block text-slate-500"><?php echo esc_html__('Bottom', 'eko-sampa'); ?></span>
                                <input class="w-full rounded border border-slate-200 px-1 py-0.5" type="number" min="0" max="120" x-model.number="selectedElement.styles.paddingBottom" />
                            </label>
                            <label class="block">
                                <span class="mb-0.5 block text-slate-500"><?php echo esc_html__('Left', 'eko-sampa'); ?></span>
                                <input class="w-full rounded border border-slate-200 px-1 py-0.5" type="number" min="0" max="120" x-model.number="selectedElement.styles.paddingLeft" />
                            </label>
                        </div>
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
                            <span class="w-14 shrink-0 text-slate-500"><?php echo esc_html__('Rotate', 'eko-sampa'); ?></span>
                            <input class="flex-1 accent-indigo-600" type="range" min="0" max="359" step="1" x-model.number="selectedElement.styles.rotate" @change="editorRotateSidebarCommit()" />
                            <span class="w-10 shrink-0 text-right tabular-nums text-slate-600" x-text="formatRotateDisplayDeg(selectedElement.styles.rotate)"></span>
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

    <template x-teleport="body">
        <div
            id="eko-sampa-quick-print-layer"
            x-show="quickPrint.open"
            x-cloak
            x-transition.opacity
            class="fixed inset-0 z-[120] flex items-center justify-center bg-slate-900/60 p-3 dark:bg-black/70"
            role="dialog"
            aria-modal="true"
            aria-labelledby="eko-quick-print-title"
            @click.self="closeQuickPrintManager()"
        >
            <div class="eko-quick-print-panel flex max-h-[92vh] w-full max-w-3xl flex-col overflow-hidden rounded-xl bg-white shadow-2xl dark:border dark:border-slate-700 dark:bg-slate-900">
                <div class="eko-quick-print-hide-print flex shrink-0 items-start justify-between gap-3 border-b border-slate-200 px-4 py-3 dark:border-slate-700">
                    <div>
                        <h2 id="eko-quick-print-title" class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                            <?php echo esc_html__('Quick print manager', 'eko-sampa'); ?>
                        </h2>
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                            <?php echo esc_html__('Prints a snapshot of the canvas as you see it now (saved state). Does not create an order.', 'eko-sampa'); ?>
                        </p>
                    </div>
                    <button
                        type="button"
                        class="rounded border border-slate-200 px-2 py-1 text-xs text-slate-600 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-800"
                        @click="closeQuickPrintManager()"
                    ><?php echo esc_html__('Close', 'eko-sampa'); ?></button>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto px-4 py-3">
                    <div class="eko-quick-print-hide-print space-y-3">
                    <p class="text-xs text-red-600" x-show="quickPrint.error" x-text="quickPrint.error"></p>
                    <p
                        class="text-[10px] font-semibold uppercase tracking-wide text-slate-500"
                        x-show="quickPrintPhase"
                        x-text="quickPrintPhaseLabel()"
                    ></p>
                    <p class="text-xs font-medium text-slate-700 dark:text-slate-200" x-show="quickPrintUserMessage" x-text="quickPrintUserMessage"></p>
                    <p class="text-xs text-slate-500" x-show="quickPrint.loading"><?php echo esc_html__('Loading…', 'eko-sampa'); ?></p>
                    <div class="grid gap-3 sm:grid-cols-3" x-show="!quickPrint.loading">
                        <label class="block text-xs">
                            <span class="mb-0.5 block font-medium text-slate-600 dark:text-slate-300"><?php echo esc_html__('Quantity', 'eko-sampa'); ?></span>
                            <input class="w-full rounded border border-slate-200 px-2 py-1 dark:border-slate-600 dark:bg-slate-800" type="number" min="1" max="500" x-model.number="quickPrint.quantity" />
                        </label>
                        <label class="block text-xs">
                            <span class="mb-0.5 block font-medium text-slate-600 dark:text-slate-300"><?php echo esc_html__('Printer', 'eko-sampa'); ?></span>
                            <select class="w-full rounded border border-slate-200 bg-white px-2 py-1 dark:border-slate-600 dark:bg-slate-800" x-model="quickPrint.printerKey">
                                <template x-for="p in quickPrint.options.printers" :key="'qp-pr-' + (p.id || '')">
                                    <option :value="p.id" x-text="p.label || p.id"></option>
                                </template>
                            </select>
                        </label>
                        <label class="block text-xs">
                            <span class="mb-0.5 block font-medium text-slate-600 dark:text-slate-300"><?php echo esc_html__('Preset', 'eko-sampa'); ?></span>
                            <select class="w-full rounded border border-slate-200 bg-white px-2 py-1 dark:border-slate-600 dark:bg-slate-800" x-model="quickPrint.presetKey">
                                <template x-for="s in quickPrint.options.presets" :key="'qp-prs-' + (s.id || '')">
                                    <option :value="s.id" x-text="s.label || s.id"></option>
                                </template>
                            </select>
                        </label>
                    </div>
                    <div class="flex flex-wrap gap-2" x-show="!quickPrint.loading">
                        <button
                            type="button"
                            class="rounded border border-slate-200 bg-white px-2 py-1 text-xs hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-800 dark:hover:bg-slate-700"
                            @click="quickPrintApplySettings()"
                        ><?php echo esc_html__('Apply settings', 'eko-sampa'); ?></button>
                        <button
                            type="button"
                            class="rounded border border-slate-200 bg-white px-2 py-1 text-xs hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-800 dark:hover:bg-slate-700"
                            x-show="quickPrint.job"
                            @click="quickPrintRefreshJob()"
                        ><?php echo esc_html__('Refresh status', 'eko-sampa'); ?></button>
                        <button
                            type="button"
                            class="rounded border border-amber-200 bg-amber-50 px-2 py-1 text-xs text-amber-900 hover:bg-amber-100 dark:border-amber-900/40 dark:bg-amber-950/40 dark:text-amber-100"
                            x-show="quickPrint.job && quickPrint.job.status === 'queued'"
                            @click="quickPrintCancelJob()"
                        ><?php echo esc_html__('Cancel job', 'eko-sampa'); ?></button>
                        <button
                            type="button"
                            class="rounded border border-indigo-200 bg-indigo-50 px-2 py-1 text-xs text-indigo-900 hover:bg-indigo-100 dark:border-indigo-800 dark:bg-indigo-950/50 dark:text-indigo-100"
                            x-show="quickPrint.job"
                            @click="quickPrintReprint()"
                        ><?php echo esc_html__('Reprint', 'eko-sampa'); ?></button>
                    </div>
                    <div class="rounded border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700 dark:border-slate-600 dark:bg-slate-800/80 dark:text-slate-200" x-show="quickPrint.job">
                        <p>
                            <span class="font-medium"><?php echo esc_html__('Job', 'eko-sampa'); ?></span>
                            #<span x-text="quickPrint.job && quickPrint.job.id"></span>
                            — <span class="uppercase tracking-wide" x-text="quickPrint.job && quickPrint.job.status"></span>
                        </p>
                        <p class="mt-1 text-slate-500 dark:text-slate-400" x-show="quickPrint.quantityHint" x-text="quickPrint.quantityHint"></p>
                    </div>
                    <p class="text-xs text-amber-700 dark:text-amber-300" x-show="quickPrint.previewStatus" x-text="quickPrint.previewStatus"></p>
                    </div>
                    <div class="overflow-auto rounded-lg border border-slate-200 bg-slate-100 p-3 dark:border-slate-600 dark:bg-slate-800">
                        <div
                            id="eko-sampa-quick-print-mount"
                            x-ref="quickPrintMount"
                            class="mx-auto max-w-full bg-white shadow-sm"
                            style="min-height: 120px"
                        ></div>
                    </div>
                </div>
                <div class="eko-quick-print-hide-print flex shrink-0 flex-wrap items-center justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3 dark:border-slate-700 dark:bg-slate-900">
                    <button
                        type="button"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-40"
                        :disabled="quickPrint.loading || quickPrintJobCreating || _quickPrintPrintInProgress || (quickPrint.job && quickPrint.job.status === 'sent_to_browser') || (quickPrint.previewStatus && String(quickPrint.previewStatus).trim() !== '')"
                        @click="quickPrintFooterPrimaryClick()"
                    ><?php echo esc_html__('Print', 'eko-sampa'); ?></button>
                </div>
            </div>
        </div>
    </template>

    <?php require EKO_SAMPA_PLUGIN_DIR . 'views/partials/public-experience/conversion-modal.php'; ?>
</div>
