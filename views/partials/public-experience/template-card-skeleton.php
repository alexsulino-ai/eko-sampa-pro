<?php
/**
 * Skeleton placeholders while the public catalog REST payload loads.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

?>
<div id="eko-pub-skeleton" class="hidden grid gap-6 sm:grid-cols-2 lg:grid-cols-3" aria-hidden="true">
    <?php
    for ($i = 0; $i < 6; $i++) :
        ?>
    <div class="min-w-0 animate-pulse overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="h-36 w-full bg-slate-200"></div>
            <div class="space-y-2 p-3">
            <div class="h-4 w-11/12 max-w-[12rem] rounded bg-slate-200"></div>
            <div class="h-3 w-1/2 max-w-[8rem] rounded bg-slate-100"></div>
            <div class="mt-4 flex gap-2">
                <div class="h-8 flex-1 rounded bg-slate-200"></div>
                <div class="h-8 flex-1 rounded bg-slate-100"></div>
            </div>
        </div>
    </div>
        <?php
    endfor;
    ?>
</div>
