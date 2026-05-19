<?php
/**
 * Public catalog tabs + search + category (paired with public-home.js).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

?>
<div class="mb-6 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
    <div class="flex flex-wrap gap-2 border-b border-slate-200 pb-1" role="tablist" aria-label="<?php echo esc_attr__('Filtros do catálogo', 'eko-sampa'); ?>">
        <button type="button" class="eko-pub-tab -mb-px border-b-2 border-indigo-600 px-3 py-2 text-sm font-medium text-indigo-700" data-scope="all"><?php echo esc_html__('Todos', 'eko-sampa'); ?></button>
        <button type="button" class="eko-pub-tab -mb-px border-b-2 border-transparent px-3 py-2 text-sm font-medium text-slate-600 hover:text-slate-900" data-scope="featured"><?php echo esc_html__('Destaque', 'eko-sampa'); ?></button>
        <button type="button" class="eko-pub-tab -mb-px border-b-2 border-transparent px-3 py-2 text-sm font-medium text-slate-600 hover:text-slate-900" data-scope="recent"><?php echo esc_html__('Recentes', 'eko-sampa'); ?></button>
        <button type="button" class="eko-pub-tab -mb-px border-b-2 border-transparent px-3 py-2 text-sm font-medium text-slate-600 hover:text-slate-900" data-scope="popular"><?php echo esc_html__('Populares', 'eko-sampa'); ?></button>
        <button type="button" class="eko-pub-tab -mb-px border-b-2 border-transparent px-2 py-2 text-xs font-medium text-slate-600 hover:text-slate-900" data-scope="trending_today"><?php echo esc_html__('Em alta (24h)', 'eko-sampa'); ?></button>
        <button type="button" class="eko-pub-tab -mb-px border-b-2 border-transparent px-2 py-2 text-xs font-medium text-slate-600 hover:text-slate-900" data-scope="trending_week"><?php echo esc_html__('Em alta (7d)', 'eko-sampa'); ?></button>
        <button type="button" class="eko-pub-tab -mb-px border-b-2 border-transparent px-2 py-2 text-xs font-medium text-slate-600 hover:text-slate-900" data-scope="recently_printed"><?php echo esc_html__('Impressos', 'eko-sampa'); ?></button>
        <button type="button" class="eko-pub-tab -mb-px border-b-2 border-transparent px-2 py-2 text-xs font-medium text-slate-600 hover:text-slate-900" data-scope="most_saved"><?php echo esc_html__('Mais salvos', 'eko-sampa'); ?></button>
    </div>
    <div class="flex w-full flex-col gap-2 sm:flex-row sm:items-center md:w-auto">
        <input
            id="eko-pub-search"
            type="search"
            class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm sm:min-w-[14rem]"
            placeholder="<?php echo esc_attr__('Pesquisar…', 'eko-sampa'); ?>"
            autocomplete="off"
        />
        <select id="eko-pub-category" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm sm:w-48" aria-label="<?php echo esc_attr__('Categoria', 'eko-sampa'); ?>">
            <option value=""><?php echo esc_html__('Todas as categorias', 'eko-sampa'); ?></option>
        </select>
    </div>
</div>
