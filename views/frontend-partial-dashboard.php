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

    <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <h3 class="text-sm font-semibold text-slate-900"><?php echo esc_html__('Quick links', 'eko-sampa'); ?></h3>
        <p class="mt-1 text-sm text-slate-500"><?php echo esc_html__('Jump to a section you use often.', 'eko-sampa'); ?></p>
        <ul class="mt-4 flex flex-wrap gap-2">
            <?php
            $quick = [
                [
                    'view'  => 'clients',
                    'label' => __('Clients', 'eko-sampa'),
                    'show'  => current_user_can('manage_options') || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_CLIENTS),
                ],
                [
                    'view'  => 'services',
                    'label' => __('Services', 'eko-sampa'),
                    'show'  => current_user_can('manage_options') || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_SERVICES),
                ],
                [
                    'view'  => 'templates',
                    'label' => __('Templates', 'eko-sampa'),
                    'show'  => current_user_can('manage_options') || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_TEMPLATES),
                ],
                [
                    'view'  => 'editor',
                    'label' => __('Editor', 'eko-sampa'),
                    'show'  => current_user_can('manage_options') || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_TEMPLATES),
                ],
                [
                    'view'  => 'orders',
                    'label' => __('Orders', 'eko-sampa'),
                    'show'  => current_user_can('manage_options') || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_ORDERS),
                ],
                [
                    'view'  => 'profile',
                    'label' => __('Profile', 'eko-sampa'),
                    'show'  => true,
                ],
            ];
            foreach ($quick as $item) {
                if (empty($item['show'])) {
                    continue;
                }
                $href = esc_url(Eko_Sampa_Frontend_Router::get_url((string) $item['view']));
                echo '<li><a class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-800 hover:bg-slate-50" href="' . $href . '">' . esc_html((string) $item['label']) . '</a></li>';
            }
            ?>
        </ul>
    </div>
</div>
