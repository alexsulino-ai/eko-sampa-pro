<?php
/**
 * Order form live preview: same Alpine canvas factory as the visual editor (read-only).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

?>
<div
    class="eko-sampa-editor eko-sampa-order-live-preview flex min-h-0 w-full min-w-0 max-h-[min(65vh,36rem)] flex-1 flex-col overflow-hidden rounded-lg border border-slate-200 bg-slate-100 text-slate-900"
    x-data="window.ekoEditorCanvasFactory()"
    data-eko-order-preview="1"
    @keydown.window.stop
>
    <header class="flex shrink-0 flex-wrap items-center gap-2 border-b border-slate-200 bg-white px-3 py-2">
        <h2 class="text-sm font-semibold text-slate-900"><?php echo esc_html__('Live preview', 'eko-sampa'); ?></h2>
        <p class="hidden text-[10px] text-slate-500 sm:inline"><?php echo esc_html__('Same canvas as the visual editor.', 'eko-sampa'); ?></p>
        <div class="ml-auto flex min-w-0 flex-wrap items-center gap-2 text-xs text-slate-600">
            <label class="inline-flex min-w-0 items-center gap-1" for="eko-order-preview-zoom">
                <span class="hidden sm:inline"><?php echo esc_html__('Zoom', 'eko-sampa'); ?></span>
            </label>
            <input
                id="eko-order-preview-zoom"
                class="h-2 w-20 min-w-0 max-w-full shrink cursor-pointer accent-slate-700 sm:w-24"
                type="range"
                x-model.number="zoomPercent"
                :min="minZoom"
                :max="maxZoom"
                step="1"
            />
            <span class="w-9 shrink-0 text-right text-xs tabular-nums text-slate-700" x-text="zoomPercent + '%'"></span>
        </div>
    </header>
    <div class="flex min-h-0 min-w-0 flex-1 flex-col">
        <section class="eko-sampa-editor__workspace flex min-h-0 min-w-0 flex-1 flex-col" aria-label="<?php echo esc_attr__('Order preview canvas', 'eko-sampa'); ?>">
            <div
                class="eko-sampa-editor__viewport min-h-0 min-w-0 flex-1 overflow-hidden bg-slate-200/90"
                x-ref="orderPreviewViewport"
                role="region"
            >
                <div class="eko-sampa-editor__viewport-frame flex h-full min-h-0 w-full min-w-0 items-start justify-center overflow-hidden p-2 sm:p-3" @mousedown.self="clearSelectionIfCanvas($event)">
                    <div class="eko-sampa-editor__stage max-h-full max-w-full shrink-0" :style="stageTransform">
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
                                        'shadow-lg ring-2 ring-indigo-500': selectedId === item.id && draggingId !== item.id,
                                        'hover:shadow-md hover:ring-1 hover:ring-slate-300/90': selectedId !== item.id && draggingId !== item.id,
                                    }"
                                    :data-element-id="item.id"
                                    :data-layer-index="idx"
                                    :style="elementPositionStyle(item)"
                                    @mousedown="select(item.id)"
                                    @dblclick.prevent
                                >
                                    <template x-if="item.type === 'image'">
                                        <div class="pointer-events-none absolute inset-0" :style="elementFrameCss(item)">
                                            <img class="pointer-events-none h-full w-full max-h-full max-w-full" :style="imageImgCss(item)" :src="item.src || item.content" alt="" />
                                        </div>
                                    </template>
                                    <template x-if="item.type === 'text' || item.type === 'placeholder'">
                                        <div class="absolute inset-0" :style="elementFrameCss(item)">
                                            <div class="eko-sampa-editor__inline-hit pointer-events-none min-h-0 min-w-0 cursor-default">
                                                <span class="pointer-events-none block min-h-full min-w-0 whitespace-pre-wrap break-words" :style="textContentCss(item)" x-text="item.content"></span>
                                            </div>
                                        </div>
                                    </template>
                                    <template x-if="item.type === 'rectangle'">
                                        <div class="pointer-events-none absolute inset-0" :style="elementFrameCss(item)"></div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>
