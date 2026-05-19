<?php
/**
 * Frontend login screen (Tailwind + no wp-login.php UI).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$failed = isset($_GET['login']) && sanitize_key(wp_unslash((string) $_GET['login'])) === 'failed';
$action = Eko_Sampa_Frontend_Router::ACTION_LOGIN;
$redirect_to = '';
if (isset($_GET['redirect_to'])) {
    $redirect_to = wp_validate_redirect(wp_unslash((string) $_GET['redirect_to']), '');
}

?>
<div class="eko-sampa-login flex min-h-screen flex-col items-center justify-center bg-slate-100 px-4 py-12">
    <div class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
        <h1 class="text-center text-xl font-semibold text-slate-900">
            <?php echo esc_html__('Eko Sampa', 'eko-sampa'); ?>
        </h1>
        <p class="mt-1 text-center text-sm text-slate-500">
            <?php echo esc_html__('Sign in to your account', 'eko-sampa'); ?>
        </p>

        <?php if ($failed) : ?>
            <p class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">
                <?php echo esc_html__('Invalid username or password.', 'eko-sampa'); ?>
            </p>
        <?php endif; ?>

        <form class="mt-6 space-y-4" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
            <?php wp_nonce_field($action); ?>
            <?php if ($redirect_to !== '') : ?>
                <input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect_to); ?>">
            <?php endif; ?>

            <div>
                <label class="block text-sm font-medium text-slate-700" for="eko-sampa-user-login">
                    <?php echo esc_html__('Username or email', 'eko-sampa'); ?>
                </label>
                <input
                    id="eko-sampa-user-login"
                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                    type="text"
                    name="log"
                    autocomplete="username"
                    required
                >
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700" for="eko-sampa-user-pass">
                    <?php echo esc_html__('Password', 'eko-sampa'); ?>
                </label>
                <input
                    id="eko-sampa-user-pass"
                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                    type="password"
                    name="pwd"
                    autocomplete="current-password"
                    required
                >
            </div>
            <div class="flex items-center gap-2">
                <input id="eko-sampa-remember" type="checkbox" name="rememberme" value="forever" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                <label class="text-sm text-slate-600" for="eko-sampa-remember"><?php echo esc_html__('Remember me', 'eko-sampa'); ?></label>
            </div>
            <button
                type="submit"
                class="w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white shadow hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
            >
                <?php echo esc_html__('Sign in', 'eko-sampa'); ?>
            </button>
        </form>
    </div>
</div>
