<?php
/**
 * Dashboard home: summary cards (functional counts from models).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$count_clients   = 0;
$count_orders    = 0;
$count_templates = 0;
$count_services  = 0;

if (class_exists('Eko_Sampa_Client')) {
    $count_clients = count((new Eko_Sampa_Client())->list(['limit' => 500]));
}
if (class_exists('Eko_Sampa_Order')) {
    $count_orders = count((new Eko_Sampa_Order())->list(['limit' => 500]));
}
if (class_exists('Eko_Sampa_Template')) {
    $count_templates = count((new Eko_Sampa_Template())->list(['limit' => 500]));
}
if (class_exists('Eko_Sampa_Service')) {
    $count_services = count((new Eko_Sampa_Service())->list(['limit' => 500]));
}

?>
<div class="mx-auto max-w-6xl space-y-6">
    <div>
        <h2 class="text-lg font-semibold text-slate-900"><?php echo esc_html__('Overview', 'eko-sampa'); ?></h2>
        <p class="text-sm text-slate-500"><?php echo esc_html__('Quick snapshot of your workspace.', 'eko-sampa'); ?></p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-sm font-medium text-slate-500"><?php echo esc_html__('Clients', 'eko-sampa'); ?></p>
            <p class="mt-2 text-3xl font-semibold tabular-nums text-slate-900"><?php echo esc_html((string) $count_clients); ?></p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-sm font-medium text-slate-500"><?php echo esc_html__('Orders', 'eko-sampa'); ?></p>
            <p class="mt-2 text-3xl font-semibold tabular-nums text-slate-900"><?php echo esc_html((string) $count_orders); ?></p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-sm font-medium text-slate-500"><?php echo esc_html__('Templates', 'eko-sampa'); ?></p>
            <p class="mt-2 text-3xl font-semibold tabular-nums text-slate-900"><?php echo esc_html((string) $count_templates); ?></p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-sm font-medium text-slate-500"><?php echo esc_html__('Services', 'eko-sampa'); ?></p>
            <p class="mt-2 text-3xl font-semibold tabular-nums text-slate-900"><?php echo esc_html((string) $count_services); ?></p>
        </div>
    </div>

    <div class="rounded-xl border border-dashed border-slate-300 bg-white p-6 text-sm text-slate-600">
        <?php echo esc_html__('Production status and deeper widgets can plug in here as modules ship.', 'eko-sampa'); ?>
    </div>
</div>
