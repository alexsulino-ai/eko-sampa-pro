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

require_once EKO_SAMPA_PLUGIN_DIR . 'views/partial-shell-nav-icon.php';

$active = isset($GLOBALS['eko_sampa_active_view']) ? sanitize_key((string) $GLOBALS['eko_sampa_active_view']) : 'dashboard';

$nav = [
    [
        'view'   => 'dashboard',
        'label'  => __('Dashboard', 'eko-sampa'),
        'icon'   => 'dashboard',
        'show'   => true,
    ],
    [
        'view'   => 'clients',
        'label'  => __('Clients', 'eko-sampa'),
        'icon'   => 'clients',
        'show'   => current_user_can('manage_options') || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_CLIENTS),
    ],
    [
        'view'   => 'services',
        'label'  => __('Services', 'eko-sampa'),
        'icon'   => 'services',
        'show'   => current_user_can('manage_options') || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_SERVICES),
    ],
    [
        'view'   => 'templates',
        'label'  => __('Templates', 'eko-sampa'),
        'icon'   => 'templates',
        'show'   => current_user_can('manage_options') || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_TEMPLATES),
    ],
    [
        'view'   => 'editor',
        'label'  => __('Editor', 'eko-sampa'),
        'icon'   => 'editor',
        'show'   => current_user_can('manage_options') || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_TEMPLATES),
    ],
    [
        'view'   => 'orders',
        'label'  => __('Orders', 'eko-sampa'),
        'icon'   => 'orders',
        'show'   => current_user_can('manage_options') || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_ORDERS),
    ],
    [
        'view'   => 'profile',
        'label'  => __('Profile', 'eko-sampa'),
        'icon'   => 'profile',
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
        class="eko-sampa-sidebar fixed inset-y-0 left-0 z-50 flex w-64 max-w-[85vw] -translate-x-full flex-col border-r border-slate-200 bg-white transition-[width,transform] duration-200 ease-out lg:static lg:max-w-none lg:translate-x-0"
        :class="[
            state.navOpen ? '!translate-x-0' : '',
            state.sidebarCollapsed ? 'lg:!w-[4.5rem]' : 'lg:!w-64',
        ]"
        aria-label="<?php echo esc_attr__('Main navigation', 'eko-sampa'); ?>"
    >
        <div
            class="flex h-14 shrink-0 items-center gap-2 border-b border-slate-100 px-2 lg:justify-between lg:px-3"
            :class="(state.sidebarCollapsed && !state.navOpen) ? 'lg:justify-center' : ''"
        >
            <span
                class="min-w-0 flex-1 truncate pl-1 text-sm font-semibold text-slate-900"
                x-show="state.navOpen || !state.sidebarCollapsed"
                x-cloak
            ><?php echo esc_html__('Eko Sampa', 'eko-sampa'); ?></span>
            <button
                type="button"
                class="hidden h-8 w-8 shrink-0 items-center justify-center rounded-md border border-slate-200 text-slate-600 hover:bg-slate-50 lg:inline-flex"
                @click="toggleLeftSidebar()"
                :aria-expanded="!state.sidebarCollapsed"
                :title="state.sidebarCollapsed ? '<?php echo esc_attr__('Expand menu', 'eko-sampa'); ?>' : '<?php echo esc_attr__('Collapse menu', 'eko-sampa'); ?>'"
            >
                <svg x-show="!state.sidebarCollapsed" class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
                <svg x-show="state.sidebarCollapsed" class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
            </button>
        </div>
        <nav class="flex flex-1 flex-col space-y-0.5 overflow-y-auto p-2 lg:p-3">
            <?php foreach ($nav as $item) : ?>
                <?php if (empty($item['show'])) : ?>
                    <?php continue; ?>
                <?php endif; ?>
                <?php
                $href       = esc_url(Eko_Sampa_Frontend_Router::get_url($item['view']));
                $is_active  = $active === $item['view'];
                $classes    = $is_active
                    ? 'bg-indigo-50 text-indigo-700 font-medium'
                    : 'text-slate-600 hover:bg-slate-50';
                $icon_key   = isset($item['icon']) ? sanitize_key((string) $item['icon']) : 'dashboard';
                ?>
                <a
                    class="flex items-center gap-3 rounded-lg py-2 text-sm <?php echo esc_attr($classes); ?>"
                    :class="(state.sidebarCollapsed && !state.navOpen) ? 'lg:justify-center lg:gap-0 lg:px-2' : 'px-3'"
                    href="<?php echo $href; ?>"
                    title="<?php echo esc_attr($item['label']); ?>"
                    @click="state.navOpen = false"
                >
                    <?php eko_sampa_shell_nav_icon($icon_key); ?>
                    <span class="min-w-0 flex-1 truncate" x-show="state.navOpen || !state.sidebarCollapsed" x-cloak><?php echo esc_html($item['label']); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="border-t border-slate-100 p-2 lg:p-3">
            <a
                class="flex items-center gap-3 rounded-lg py-2 text-sm text-slate-600 hover:bg-slate-50"
                :class="(state.sidebarCollapsed && !state.navOpen) ? 'lg:justify-center lg:gap-0 lg:px-2' : 'px-3'"
                href="<?php echo esc_url(Eko_Sampa_Frontend_Router::logout_url()); ?>"
                title="<?php echo esc_attr__('Log out', 'eko-sampa'); ?>"
            >
                <?php eko_sampa_shell_nav_icon('logout'); ?>
                <span class="min-w-0 flex-1 truncate" x-show="state.navOpen || !state.sidebarCollapsed" x-cloak><?php echo esc_html__('Log out', 'eko-sampa'); ?></span>
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
