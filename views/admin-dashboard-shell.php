<?php
/**
 * Admin dashboard layout shell (Tailwind). Markup only — no data layer.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

?>
<div
    id="eko-sampa-dashboard"
    class="eko-sampa-dashboard flex min-h-[calc(100vh-32px)] flex-col bg-slate-50 text-slate-900 lg:flex-row"
>
    <aside
        class="flex w-full shrink-0 flex-col border-b border-slate-200 bg-white lg:w-64 lg:min-h-[calc(100vh-32px)] lg:border-b-0 lg:border-r lg:border-slate-200"
        aria-label="<?php echo esc_attr__('Application', 'eko-sampa'); ?>"
    >
        <div class="border-b border-slate-100 p-4">
            <p class="text-sm font-semibold text-slate-800"><?php echo esc_html__('Eko Sampa', 'eko-sampa'); ?></p>
        </div>
        <nav
            class="flex flex-row gap-1 overflow-x-auto p-2 lg:flex-col lg:overflow-visible lg:p-4"
            aria-label="<?php echo esc_attr__('Main navigation', 'eko-sampa'); ?>"
        >
            <span class="whitespace-nowrap rounded-md bg-slate-100 px-3 py-2 text-sm font-medium text-slate-900">
                <?php echo esc_html__('Dashboard', 'eko-sampa'); ?>
            </span>
        </nav>
    </aside>

    <div class="flex min-h-0 min-w-0 flex-1 flex-col">
        <header
            class="flex flex-shrink-0 items-center justify-between gap-4 border-b border-slate-200 bg-white px-4 py-3 md:px-6"
        >
            <h1 class="text-lg font-semibold text-slate-900">
                <?php echo esc_html__('Dashboard', 'eko-sampa'); ?>
            </h1>
        </header>

        <main class="min-h-0 flex-1 overflow-auto p-4 md:p-6 lg:p-8">
            <div
                class="rounded-lg border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500"
            >
                <?php echo esc_html__('Content area', 'eko-sampa'); ?>
            </div>
        </main>
    </div>
</div>
