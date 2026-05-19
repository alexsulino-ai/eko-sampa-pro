<?php
/**
 * Guest conversion modal (Alpine: guestConversionModal, cfg().loginUrl).
 *
 * Included inside `#eko-sampa-editor` root where `ekoEditorCanvasFactory` is bound.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

?>
<div
    x-show="guestConversionModal"
    x-cloak
    class="fixed inset-0 z-[120] flex items-center justify-center bg-slate-900/50 px-4 py-8"
    role="dialog"
    aria-modal="true"
    @click.self="guestConversionModal = false"
    @keydown.escape.window="guestConversionModal = false"
>
    <div class="max-w-lg rounded-2xl border border-slate-200 bg-white p-6 shadow-xl" @click.stop>
        <h2 class="text-lg font-semibold text-slate-900"><?php echo esc_html__('Não perca este trabalho', 'eko-sampa'); ?></h2>
        <p class="mt-2 text-sm text-slate-600">
            <?php echo esc_html__('Com uma conta, o modelo passa para “Meus templates”: edite mais tarde, em qualquer dispositivo, com histórico e cópias seguras.', 'eko-sampa'); ?>
        </p>
        <ul class="mt-4 list-inside list-disc space-y-1 text-sm text-slate-700">
            <li><?php echo esc_html__('Guarde o estado atual da personalização', 'eko-sampa'); ?></li>
            <li><?php echo esc_html__('Continue depois sem recomeçar do zero', 'eko-sampa'); ?></li>
            <li><?php echo esc_html__('Após entrar, voltamos ao mesmo editor — sem perder a edição', 'eko-sampa'); ?></li>
        </ul>
        <div class="mt-6 flex flex-wrap justify-end gap-2">
            <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-800 hover:bg-slate-50" @click="guestConversionModal = false">
                <?php echo esc_html__('Agora não', 'eko-sampa'); ?>
            </button>
            <a class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow hover:bg-indigo-700" :href="cfg().loginUrl || '#'">
                <?php echo esc_html__('Entrar ou registar', 'eko-sampa'); ?>
            </a>
        </div>
    </div>
</div>
