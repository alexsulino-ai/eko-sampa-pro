<?php
/**
 * Guest editor top notice (session is temporary).
 *
 * Expected variables: $guest_banner_catalog_url (string), $guest_banner_login_url (string).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$catalog = isset($guest_banner_catalog_url) ? (string) $guest_banner_catalog_url : Eko_Sampa_Frontend_Router::get_url('public_home');
$login   = isset($guest_banner_login_url) ? (string) $guest_banner_login_url : Eko_Sampa_Frontend_Router::get_url('login');

?>
<div class="border-b border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
    <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3">
        <p class="min-w-0">
            <span class="font-semibold"><?php echo esc_html__('Sessão temporária.', 'eko-sampa'); ?></span>
            <?php echo esc_html__('O seu trabalho existe neste dispositivo até criar conta ou expirar a sessão.', 'eko-sampa'); ?>
        </p>
        <div class="flex shrink-0 flex-wrap gap-2">
            <a class="rounded-md border border-amber-300 bg-white px-3 py-1 text-xs font-medium text-amber-900 hover:bg-amber-100" href="<?php echo esc_url($catalog); ?>">
                <?php echo esc_html__('Catálogo', 'eko-sampa'); ?>
            </a>
            <a class="rounded-md bg-indigo-600 px-3 py-1 text-xs font-medium text-white hover:bg-indigo-700" href="<?php echo esc_url($login); ?>">
                <?php echo esc_html__('Criar conta / Entrar', 'eko-sampa'); ?>
            </a>
        </div>
    </div>
</div>
