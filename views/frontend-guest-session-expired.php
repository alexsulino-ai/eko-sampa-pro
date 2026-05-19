<?php
/**
 * Guest editor URL is present but the session row cannot be used (expired, wrong token, etc.).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$reason = isset($GLOBALS['eko_sampa_guest_session_expired_reason'])
    ? sanitize_key((string) $GLOBALS['eko_sampa_guest_session_expired_reason'])
    : 'unknown';
$parent_id = isset($GLOBALS['eko_sampa_guest_session_parent_master_id'])
    ? absint((int) $GLOBALS['eko_sampa_guest_session_parent_master_id'])
    : 0;

$pub = Eko_Sampa_Frontend_Router::get_url('public_home');
$login_base = Eko_Sampa_Frontend_Router::get_url('login');

$copy_title = __('Esta sessão já não está disponível', 'eko-sampa');
$copy_body  = __('O link de edição temporária expirou ou deixou de ser válido neste dispositivo. O seu trabalho não está neste URL — pode voltar ao catálogo e abrir uma sessão nova a partir do mesmo modelo.', 'eko-sampa');

if ($reason === 'expired') {
    $copy_title = __('A sessão temporária expirou', 'eko-sampa');
    $copy_body  = __('Por segurança, as sessões de convidado terminam automaticamente. Pode recriar uma sessão nova a partir do catálogo e continuar a personalizar.', 'eko-sampa');
} elseif ($reason === 'token' || $reason === 'fingerprint') {
    $copy_title = __('Não conseguimos validar este link', 'eko-sampa');
    $copy_body  = __('O token da sessão não corresponde a este modelo, ou o ambiente mudou. Abra novamente o modelo a partir do catálogo para obter um link válido.', 'eko-sampa');
} elseif ($reason === 'not_found' || $reason === 'not_session') {
    $copy_title = __('Modelo ou sessão não encontrados', 'eko-sampa');
    $copy_body  = __('Este endereço já não aponta para uma sessão ativa. Volte ao catálogo e escolha o modelo outra vez.', 'eko-sampa');
}

$fork_arg = $parent_id > 0 ? add_query_arg('fork', (string) $parent_id, $pub) : $pub;
$after_login = $parent_id > 0 ? add_query_arg('fork', (string) $parent_id, $pub) : $pub;
$login_cta   = add_query_arg('redirect_to', rawurlencode($after_login), $login_base);

?>
<div class="min-h-screen bg-slate-50">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-3xl flex-wrap items-center justify-between gap-4 px-4 py-6">
            <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600"><?php echo esc_html__('Eko Sampa', 'eko-sampa'); ?></p>
            <a class="text-sm font-medium text-indigo-600 underline" href="<?php echo esc_url($pub); ?>"><?php echo esc_html__('Catálogo público', 'eko-sampa'); ?></a>
        </div>
    </header>
    <main class="mx-auto max-w-3xl px-4 py-12">
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-8 shadow-sm">
            <h1 class="text-xl font-semibold text-amber-950"><?php echo esc_html($copy_title); ?></h1>
            <p class="mt-3 text-sm leading-relaxed text-amber-900/90"><?php echo esc_html($copy_body); ?></p>
            <div class="mt-8 flex flex-wrap gap-3">
                <a class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow hover:bg-indigo-700" href="<?php echo esc_url($fork_arg); ?>">
                    <?php echo esc_html__('Recriar sessão a partir do modelo', 'eko-sampa'); ?>
                </a>
                <a class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-800 hover:bg-slate-50" href="<?php echo esc_url($pub); ?>">
                    <?php echo esc_html__('Ver todos os modelos', 'eko-sampa'); ?>
                </a>
                <a class="inline-flex items-center rounded-lg border border-indigo-200 bg-white px-4 py-2 text-sm font-medium text-indigo-800 hover:bg-indigo-50" href="<?php echo esc_url($login_cta); ?>">
                    <?php echo esc_html__('Entrar para guardar na biblioteca', 'eko-sampa'); ?>
                </a>
            </div>
        </div>
    </main>
</div>
