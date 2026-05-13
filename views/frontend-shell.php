<?php
/**
 * Application shell: sidebar, topbar, content (frontend-first).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$active = isset($GLOBALS['eko_sampa_active_view']) ? sanitize_key((string) $GLOBALS['eko_sampa_active_view']) : 'dashboard';

$nav = [
    [
        'view' => 'dashboard',
        'label'  => __('Dashboard', 'eko-sampa'),
        'show'   => true,
    ],
    [
        'view' => 'clients',
        'label'  => __('Clients', 'eko-sampa'),
        'show'   => current_user_can('manage_options') || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_CLIENTS),
    ],
    [
        'view' => 'services',
        'label'  => __('Services', 'eko-sampa'),
        'show'   => current_user_can('manage_options') || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_SERVICES),
    ],
    [
        'view' => 'templates',
        'label'  => __('Templates', 'eko-sampa'),
        'show'   => current_user_can('manage_options') || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_TEMPLATES),
    ],
    [
        'view' => 'editor',
        'label'  => __('Editor', 'eko-sampa'),
        'show'   => current_user_can('manage_options') || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_TEMPLATES),
    ],
    [
        'view' => 'orders',
        'label'  => __('Orders', 'eko-sampa'),
        'show'   => current_user_can('manage_options') || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_ORDERS),
    ],
    [
        'view' => 'profile',
        'label'  => __('Profile', 'eko-sampa'),
        'show'   => true,
    ],
];

$user = wp_get_current_user();
$name = $user->exists() ? $user->display_name : '';

$titles = [
    'dashboard' => __('Dashboard', 'eko-sampa'),
    'clients'   => __('Clients', 'eko-sampa'),
    'services'  => __('Services', 'eko-sampa'),
    'templates' => __('Templates', 'eko-sampa'),
    'editor'    => __('Editor', 'eko-sampa'),
    'orders'    => __('Orders', 'eko-sampa'),
    'profile'   => __('Profile', 'eko-sampa'),
];
$page_title = $titles[ $active ] ?? __('Eko Sampa', 'eko-sampa');

?>
<div
    id="eko-sampa-app"
    class="eko-sampa-app flex min-h-screen"
    x-data="window.ekoShellFactory()"
    x-init="init()"
    @keydown.escape.window="state.navOpen = false"
>
    <div
        class="fixed inset-0 z-40 bg-slate-900/40 lg:hidden"
        x-show="state.navOpen"
        x-transition.opacity
        x-cloak
        @click="state.navOpen = false"
        aria-hidden="true"
    ></div>

    <aside
        class="eko-sampa-sidebar fixed inset-y-0 left-0 z-50 flex w-64 -translate-x-full flex-col border-r border-slate-200 bg-white transition-transform duration-200 lg:static lg:translate-x-0"
        :class="{ '!translate-x-0': state.navOpen }"
        aria-label="<?php echo esc_attr__('Main navigation', 'eko-sampa'); ?>"
    >
        <div class="flex h-14 items-center border-b border-slate-100 px-4">
            <span class="text-sm font-semibold text-slate-900"><?php echo esc_html__('Eko Sampa', 'eko-sampa'); ?></span>
        </div>
        <nav class="flex-1 space-y-0.5 overflow-y-auto p-3">
            <?php foreach ($nav as $item) : ?>
                <?php if (empty($item['show'])) : ?>
                    <?php continue; ?>
                <?php endif; ?>
                <?php
                $href = esc_url(Eko_Sampa_Frontend_Router::get_url($item['view']));
                $is_active = $active === $item['view'];
                $classes = $is_active
                    ? 'bg-indigo-50 text-indigo-700 font-medium'
                    : 'text-slate-600 hover:bg-slate-50';
                ?>
                <a
                    class="block rounded-lg px-3 py-2 text-sm <?php echo esc_attr($classes); ?>"
                    href="<?php echo $href; ?>"
                    @click="state.navOpen = false"
                >
                    <?php echo esc_html($item['label']); ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="border-t border-slate-100 p-3">
            <a
                class="block rounded-lg px-3 py-2 text-sm text-slate-600 hover:bg-slate-50"
                href="<?php echo esc_url(Eko_Sampa_Frontend_Router::logout_url()); ?>"
            >
                <?php echo esc_html__('Log out', 'eko-sampa'); ?>
            </a>
        </div>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col lg:min-h-screen">
        <header class="eko-sampa-topbar sticky top-0 z-30 flex h-14 items-center gap-3 border-b border-slate-200 bg-white px-4 shadow-sm">
            <button
                type="button"
                class="inline-flex rounded-lg p-2 text-slate-600 hover:bg-slate-100 lg:hidden"
                @click="state.navOpen = true"
                aria-expanded="false"
                :aria-expanded="state.navOpen"
                aria-controls="eko-sampa-app"
            >
                <span class="sr-only"><?php echo esc_html__('Open menu', 'eko-sampa'); ?></span>
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <h1 class="min-w-0 truncate text-base font-semibold text-slate-900">
                <?php echo esc_html($page_title); ?>
            </h1>
            <div class="ml-auto flex items-center gap-2">
                <span class="hidden max-w-[12rem] truncate text-sm text-slate-500 sm:inline">
                    <?php echo esc_html($name); ?>
                </span>
                <?php if (current_user_can('manage_options')) : ?>
                    <span class="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800"><?php echo esc_html__('Admin', 'eko-sampa'); ?></span>
                <?php endif; ?>
            </div>
        </header>

        <main class="eko-sampa-content min-h-0 flex-1 overflow-auto p-4 md:p-6 lg:p-8">
            <?php
            switch ($active) {
                case 'dashboard':
                    require EKO_SAMPA_PLUGIN_DIR . 'views/frontend-partial-dashboard.php';
                    break;
                case 'editor':
                    $GLOBALS['eko_sampa_editor_embedded'] = true;
                    require EKO_SAMPA_PLUGIN_DIR . 'views/editor-canvas.php';
                    unset($GLOBALS['eko_sampa_editor_embedded']);
                    break;
                case 'clients':
                    require EKO_SAMPA_PLUGIN_DIR . 'views/frontend-partial-clients.php';
                    break;
                case 'services':
                    require EKO_SAMPA_PLUGIN_DIR . 'views/frontend-partial-services.php';
                    break;
                case 'templates':
                    require EKO_SAMPA_PLUGIN_DIR . 'views/frontend-partial-templates.php';
                    break;
                case 'orders':
                    require EKO_SAMPA_PLUGIN_DIR . 'views/frontend-partial-orders.php';
                    break;
                case 'profile':
                    require EKO_SAMPA_PLUGIN_DIR . 'views/frontend-partial-profile.php';
                    break;
                default:
                    require EKO_SAMPA_PLUGIN_DIR . 'views/frontend-partial-dashboard.php';
            }
            ?>
        </main>
    </div>
</div>
