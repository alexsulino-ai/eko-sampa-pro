<?php
/**
 * Public marketing hero — conversion-focused; no heavy animation or autoplay.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$login = Eko_Sampa_Frontend_Router::get_url('login');

?>
<header class="border-b border-slate-200 bg-gradient-to-b from-white to-slate-50">
    <div class="mx-auto grid max-w-6xl gap-8 px-4 py-10 md:grid-cols-[minmax(0,1fr)_minmax(0,20rem)] md:items-center">
        <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600"><?php echo esc_html__('Eko Sampa', 'eko-sampa'); ?></p>
            <h1 class="mt-2 text-3xl font-bold tracking-tight text-slate-900 md:text-4xl">
                <?php echo esc_html__('Edite agora. Imprima em minutos.', 'eko-sampa'); ?>
            </h1>
            <p class="mt-3 max-w-xl text-base text-slate-600">
                <?php echo esc_html__('Modelos prontos para personalizar no browser — sem conta para experimentar. Guarde na sua biblioteca quando quiser.', 'eko-sampa'); ?>
            </p>
            <div class="mt-6 flex flex-wrap items-center gap-3">
                <a
                    class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-md transition hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                    href="#eko-pub-grid"
                ><?php echo esc_html__('Começar agora', 'eko-sampa'); ?></a>
                <a
                    class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-5 py-2.5 text-sm font-semibold text-slate-800 shadow-sm transition hover:border-slate-400 hover:bg-slate-50"
                    href="#eko-pub-main"
                ><?php echo esc_html__('Ver modelos', 'eko-sampa'); ?></a>
                <a class="text-sm font-medium text-indigo-700 underline-offset-2 hover:underline" href="<?php echo esc_url($login); ?>">
                    <?php echo esc_html__('Já tenho conta', 'eko-sampa'); ?>
                </a>
            </div>
        </div>
        <div class="relative hidden min-h-[12rem] md:block" aria-hidden="true">
            <div class="absolute inset-0 rounded-2xl border border-slate-200/80 bg-white shadow-lg shadow-slate-200/60">
                <div class="flex h-full flex-col p-4">
                    <div class="flex items-center gap-2 border-b border-slate-100 pb-3">
                        <span class="h-2.5 w-2.5 rounded-full bg-red-400/90"></span>
                        <span class="h-2.5 w-2.5 rounded-full bg-amber-400/90"></span>
                        <span class="h-2.5 w-2.5 rounded-full bg-emerald-400/90"></span>
                        <span class="ml-2 flex-1 rounded bg-slate-100 py-1 text-center text-[10px] font-medium text-slate-500"><?php echo esc_html__('Pré-visualização', 'eko-sampa'); ?></span>
                    </div>
                    <div class="mt-4 flex flex-1 flex-col gap-2">
                        <div class="h-3 w-[75%] max-w-[12rem] rounded bg-indigo-100"></div>
                        <div class="h-3 w-[50%] max-w-[8rem] rounded bg-slate-100"></div>
                        <div class="mt-auto grid grid-cols-2 gap-2">
                            <div class="h-16 rounded-lg bg-gradient-to-br from-indigo-50 to-indigo-100 ring-1 ring-indigo-100"></div>
                            <div class="h-16 rounded-lg bg-slate-50 ring-1 ring-slate-100"></div>
                        </div>
                        <div class="mt-2 flex gap-2">
                            <div class="h-8 flex-1 rounded-lg bg-indigo-600/90"></div>
                            <div class="h-8 w-20 rounded-lg border border-slate-200 bg-white"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>
