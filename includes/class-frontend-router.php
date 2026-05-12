<?php
/**
 * Frontend virtual routes, blank template, auth gates, login/logout handlers.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Frontend-first routing (docs/architecture.md).
 */
final class Eko_Sampa_Frontend_Router {

    public const QUERY_FLAG = 'eko_sampa_fe';

    public const QUERY_VIEW = 'eko_sampa_view';

    public const QUERY_ORDER_ID = 'eko_sampa_order_id';

    public const ACTION_LOGIN = 'eko_sampa_login';

    public const ACTION_LOGOUT = 'eko_sampa_logout';

    public function register_hooks(): void {
        add_filter('query_vars', [$this, 'register_query_vars']);
        add_action('init', [$this, 'register_rewrite_rules'], 5);
        add_action('template_redirect', [$this, 'enforce_route_access'], 4);
        add_filter('template_include', [$this, 'maybe_blank_template'], 99);
        add_action('admin_post_nopriv_' . self::ACTION_LOGIN, [$this, 'handle_login']);
        add_action('admin_post_' . self::ACTION_LOGIN, [$this, 'handle_login']);
        add_action('admin_post_nopriv_' . self::ACTION_LOGOUT, [$this, 'handle_logout']);
        add_action('admin_post_' . self::ACTION_LOGOUT, [$this, 'handle_logout']);
    }

    /**
     * @param array<int, string> $vars
     *
     * @return array<int, string>
     */
    public function register_query_vars(array $vars): array {
        $vars[] = self::QUERY_FLAG;
        $vars[] = self::QUERY_VIEW;
        $vars[] = self::QUERY_ORDER_ID;

        return $vars;
    }

    public function register_rewrite_rules(): void {
        $routes = [
            'dashboard' => 'eko-sampa_dashboard',
            'login'     => 'eko-sampa_login',
            'clients'   => 'eko-sampa_clients',
            'services'  => 'eko-sampa_services',
            'templates' => 'eko-sampa_templates',
            'editor'    => 'eko-sampa_editor',
            'orders'    => 'eko-sampa_orders',
            'profile'   => 'eko-sampa_profile',
            'print'     => 'eko-sampa_print',
        ];

        foreach ($routes as $view => $slug) {
            if ($view === 'print') {
                add_rewrite_rule(
                    '^' . preg_quote($slug, '/') . '/([0-9]+)/?$',
                    'index.php?' . self::QUERY_FLAG . '=1&' . self::QUERY_VIEW . '=print&' . self::QUERY_ORDER_ID . '=$matches[1]',
                    'top'
                );

                continue;
            }

            add_rewrite_rule(
                '^' . preg_quote($slug, '/') . '/?$',
                'index.php?' . self::QUERY_FLAG . '=1&' . self::QUERY_VIEW . '=' . $view,
                'top'
            );
        }
    }

    public function enforce_route_access(): void {
        if (! $this->is_frontend_route_request()) {
            return;
        }

        $view = $this->current_view();
        if ($view === 'login') {
            if (is_user_logged_in() && $this->user_may_use_app()) {
                wp_safe_redirect(self::get_url('dashboard'));
                exit;
            }

            return;
        }

        if (! is_user_logged_in()) {
            wp_safe_redirect(self::get_url('login'));
            exit;
        }

        if (! $this->user_may_use_app()) {
            wp_die(esc_html__('You do not have access to this application.', 'eko-sampa'), esc_html__('Eko Sampa', 'eko-sampa'), ['response' => 403]);
        }

        if (! self::viewer_can_access($view)) {
            wp_die(esc_html__('You do not have permission to view this page.', 'eko-sampa'), esc_html__('Eko Sampa', 'eko-sampa'), ['response' => 403]);
        }

        if ($view === 'print') {
            $oid = self::current_order_id();
            if ($oid <= 0 || ! is_array((new Eko_Sampa_Order())->get($oid))) {
                wp_die(esc_html__('Invalid print request.', 'eko-sampa'), esc_html__('Eko Sampa', 'eko-sampa'), ['response' => 404]);
            }
        }
    }

    /**
     * Route-level capability guard (frontend + shortcode).
     */
    private static function viewer_can_access(string $view): bool {
        if (current_user_can('manage_options')) {
            return true;
        }

        return match ($view) {
            'login' => true,
            'print' => current_user_can(Eko_Sampa_Roles::CAP_MANAGE_ORDERS),
            'dashboard', 'profile' => current_user_can(Eko_Sampa_Roles::CAP_ACCESS_DASHBOARD),
            'clients' => current_user_can(Eko_Sampa_Roles::CAP_MANAGE_CLIENTS),
            'services' => current_user_can(Eko_Sampa_Roles::CAP_MANAGE_SERVICES),
            'templates', 'editor' => current_user_can(Eko_Sampa_Roles::CAP_MANAGE_TEMPLATES),
            'orders' => current_user_can(Eko_Sampa_Roles::CAP_MANAGE_ORDERS),
            default => false,
        };
    }

    /**
     * @param string $template Absolute path.
     *
     * @return string
     */
    public function maybe_blank_template(string $template): string {
        if (! $this->is_frontend_route_request()) {
            return $template;
        }

        $file = EKO_SAMPA_PLUGIN_DIR . 'templates/frontend-blank.php';

        return is_readable($file) ? $file : $template;
    }

    public function handle_login(): void {
        if (! isset($_POST['_wpnonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), self::ACTION_LOGIN)) {
            wp_die(esc_html__('Invalid login request.', 'eko-sampa'), '', ['response' => 403]);
        }

        $login    = isset($_POST['log']) ? sanitize_user(wp_unslash((string) $_POST['log'])) : '';
        $password = isset($_POST['pwd']) ? (string) wp_unslash($_POST['pwd']) : '';
        $remember = ! empty($_POST['rememberme']);

        $credentials = [
            'user_login'    => $login,
            'user_password' => $password,
            'remember'      => $remember,
        ];

        $user = wp_signon($credentials, is_ssl());
        if (is_wp_error($user)) {
            wp_safe_redirect(add_query_arg('login', 'failed', self::get_url('login')));
            exit;
        }

        wp_safe_redirect(self::get_url('dashboard'));
        exit;
    }

    public function handle_logout(): void {
        if (! isset($_GET['_wpnonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), self::ACTION_LOGOUT)) {
            wp_die(esc_html__('Invalid logout request.', 'eko-sampa'), '', ['response' => 403]);
        }

        if (is_user_logged_in()) {
            wp_logout();
        }

        wp_safe_redirect(self::get_url('login'));
        exit;
    }

    public static function get_url(string $view, ?int $order_id = null): string {
        $slugs = [
            'dashboard' => 'eko-sampa_dashboard',
            'login'     => 'eko-sampa_login',
            'clients'   => 'eko-sampa_clients',
            'services'  => 'eko-sampa_services',
            'templates' => 'eko-sampa_templates',
            'editor'    => 'eko-sampa_editor',
            'orders'    => 'eko-sampa_orders',
            'profile'   => 'eko-sampa_profile',
            'print'     => 'eko-sampa_print',
        ];

        if ($view === 'print') {
            $slug = $slugs['print'];
            if ($order_id !== null && $order_id > 0) {
                return home_url('/' . $slug . '/' . $order_id . '/');
            }

            return home_url('/' . $slug . '/');
        }

        if (! isset($slugs[ $view ])) {
            return home_url('/');
        }

        return home_url('/' . $slugs[ $view ] . '/');
    }

    public static function logout_url(): string {
        return wp_nonce_url(
            admin_url('admin-post.php?action=' . self::ACTION_LOGOUT),
            self::ACTION_LOGOUT
        );
    }

    public function is_frontend_route_request(): bool {
        return (string) get_query_var(self::QUERY_FLAG) === '1' && $this->current_view() !== '';
    }

    public function current_view(): string {
        $view = sanitize_key((string) get_query_var(self::QUERY_VIEW));

        return $view;
    }

    public static function current_order_id(): int {
        return absint((int) get_query_var(self::QUERY_ORDER_ID));
    }

    private function user_may_use_app(): bool {
        return self::current_user_may_use_app();
    }

    /**
     * Render inner view inside shell (used by shortcodes and blank template).
     */
    public static function render_app_view(string $view): void {
        $allowed = [
            'dashboard',
            'login',
            'clients',
            'services',
            'templates',
            'editor',
            'orders',
            'profile',
            'print',
        ];

        if (! in_array($view, $allowed, true)) {
            $view = 'dashboard';
        }

        if ($view === 'login') {
            if (is_user_logged_in() && self::current_user_may_use_app()) {
                $dash = esc_url(self::get_url('dashboard'));
                echo '<p class="eko-sampa-gate">';
                echo esc_html__('You are signed in.', 'eko-sampa');
                echo ' <a class="text-indigo-600 underline" href="' . $dash . '">';
                echo esc_html__('Open dashboard', 'eko-sampa');
                echo '</a></p>';

                return;
            }

            $login_file = EKO_SAMPA_PLUGIN_DIR . 'views/frontend-login.php';
            if (is_readable($login_file)) {
                require $login_file;
            }

            return;
        }

        if (! is_user_logged_in()) {
            self::render_shortcode_gate();

            return;
        }

        if (! self::current_user_may_use_app()) {
            echo '<p class="eko-sampa-gate text-red-600">' . esc_html__('You do not have access to this application.', 'eko-sampa') . '</p>';

            return;
        }

        if (! self::viewer_can_access($view)) {
            echo '<p class="eko-sampa-gate text-red-600">' . esc_html__('You do not have permission to view this page.', 'eko-sampa') . '</p>';

            return;
        }

        if ($view === 'print') {
            $print = EKO_SAMPA_PLUGIN_DIR . 'views/frontend-print.php';
            if (is_readable($print)) {
                require $print;
            }

            return;
        }

        $shell = EKO_SAMPA_PLUGIN_DIR . 'views/frontend-shell.php';
        if (! is_readable($shell)) {
            return;
        }

        $GLOBALS['eko_sampa_active_view'] = $view;
        require $shell;
        unset($GLOBALS['eko_sampa_active_view']);
    }

    private static function current_user_may_use_app(): bool {
        return current_user_can('manage_options')
            || current_user_can(Eko_Sampa_Roles::CAP_ACCESS_DASHBOARD);
    }

    private static function render_shortcode_gate(): void {
        $url = esc_url(self::get_url('login'));
        echo '<p class="eko-sampa-gate">';
        echo esc_html__('Please sign in to continue.', 'eko-sampa');
        echo ' <a class="text-indigo-600 underline" href="' . $url . '">';
        echo esc_html__('Log in', 'eko-sampa');
        echo '</a></p>';
    }
}
