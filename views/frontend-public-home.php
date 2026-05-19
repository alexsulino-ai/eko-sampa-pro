<?php
/**
 * Public marketing home (no auth): catalog + CTAs.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

?>
<div class="min-h-screen bg-slate-50">
    <?php require EKO_SAMPA_PLUGIN_DIR . 'views/partials/public-experience/public-hero.php'; ?>

    <main id="eko-pub-main" class="mx-auto max-w-6xl px-4 py-8">
        <?php require EKO_SAMPA_PLUGIN_DIR . 'views/partials/public-experience/category-filter.php'; ?>

        <p id="eko-pub-error" class="mb-4 hidden rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800" role="alert"></p>

        <?php require EKO_SAMPA_PLUGIN_DIR . 'views/partials/public-experience/template-card-skeleton.php'; ?>

        <div id="eko-pub-grid" class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3"></div>

        <div id="eko-pub-sentinel" class="h-8 w-full" aria-hidden="true"></div>

        <div id="eko-pub-load-row" class="mt-8 flex justify-center">
            <button type="button" id="eko-pub-more" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-800 hover:bg-slate-50">
                <?php echo esc_html__('Carregar mais', 'eko-sampa'); ?>
            </button>
        </div>
    </main>
</div>
