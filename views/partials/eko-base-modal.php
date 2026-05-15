<?php
/**
 * Reusable modal shell (Alpine + ekoSampaModalService). Include once per page that opens modals.
 *
 * Expects parent scope: modalLayer, confirmEkoModal, cancelEkoModal, onEkoModalBackdropClick, onEkoModalEscape.
 * Body: set $eko_modal_body_path to a PHP partial path before including this file.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$eko_modal_body_path = isset($eko_modal_body_path) ? (string) $eko_modal_body_path : '';

?>
<template x-teleport="body">
    <div
        class="eko-modal-root fixed inset-0 z-[100] flex items-end justify-center p-4 sm:items-center sm:p-6"
        x-show="modalLayer.active"
        x-cloak
        role="dialog"
        aria-modal="true"
        :aria-labelledby="modalLayer.active ? 'eko-modal-title' : null"
        @keydown.escape.window="onEkoModalEscape()"
    >
        <div
            class="eko-modal-backdrop absolute inset-0 bg-slate-900/50 backdrop-blur-[2px]"
            x-show="modalLayer.active"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            aria-hidden="true"
            @click="onEkoModalBackdropClick()"
        ></div>

        <div
            class="eko-modal-panel relative flex max-h-[min(90vh,720px)] w-full max-w-lg flex-col overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-2xl shadow-slate-900/10 sm:max-w-xl"
            x-show="modalLayer.active"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-2 sm:scale-[0.98]"
            x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
            x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-2 sm:scale-[0.98]"
            @click.stop
        >
            <header class="flex shrink-0 items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
                <h2 id="eko-modal-title" class="text-base font-semibold text-slate-900" x-text="modalLayer.active ? modalLayer.active.title : ''"></h2>
                <button
                    type="button"
                    class="rounded-lg p-1.5 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                    :disabled="modalLayer.active && modalLayer.active.saving"
                    @click="cancelEkoModal()"
                    aria-label="<?php echo esc_attr__('Close', 'eko-sampa'); ?>"
                >
                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </header>

            <div class="eko-modal-body min-h-0 flex-1 overflow-y-auto overscroll-contain px-5 py-4">
                <p class="mb-3 text-sm text-red-600" x-show="modalLayer.active && modalLayer.active.error" x-text="modalLayer.active ? modalLayer.active.error : ''"></p>
                <?php
                if ($eko_modal_body_path !== '' && is_readable($eko_modal_body_path)) {
                    require $eko_modal_body_path;
                }
                ?>
            </div>

            <footer class="flex shrink-0 flex-wrap items-center justify-end gap-2 border-t border-slate-100 bg-slate-50/80 px-5 py-3">
                <button
                    type="button"
                    class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400 disabled:opacity-50"
                    :disabled="modalLayer.active && modalLayer.active.saving"
                    @click="cancelEkoModal()"
                    x-text="modalLayer.active ? modalLayer.active.cancelLabel : ''"
                ></button>
                <button
                    type="button"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:opacity-50"
                    :disabled="modalLayer.active && modalLayer.active.saving"
                    @click="confirmEkoModal()"
                >
                    <span x-show="!(modalLayer.active && modalLayer.active.saving)" x-text="modalLayer.active ? modalLayer.active.saveLabel : ''"></span>
                    <span x-show="modalLayer.active && modalLayer.active.saving" x-cloak><?php echo esc_html__('Saving…', 'eko-sampa'); ?></span>
                </button>
            </footer>
        </div>
    </div>
</template>
