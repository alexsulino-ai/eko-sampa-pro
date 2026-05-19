<?php
/**
 * Guest editor: minimal chrome around the existing canvas (session_token in URL).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$pub = Eko_Sampa_Frontend_Router::get_url('public_home');
$tid = isset($_GET['template_id']) ? absint((int) $_GET['template_id']) : 0;
$tok = isset($_GET['session_token']) ? preg_replace('/[^a-f0-9]/i', '', sanitize_text_field(wp_unslash((string) $_GET['session_token']))) : '';
$after_login = $tid > 0
    ? add_query_arg(
        array_filter(
            [
                'template_id'   => $tid,
                'session_token' => $tok !== '' ? $tok : null,
            ]
        ),
        Eko_Sampa_Frontend_Router::get_url('editor')
    )
    : Eko_Sampa_Frontend_Router::get_url('editor');
$login_with_redirect = add_query_arg('redirect_to', rawurlencode($after_login), Eko_Sampa_Frontend_Router::get_url('login'));

$guest_banner_catalog_url = $pub;
$guest_banner_login_url   = $login_with_redirect;

?>
<div class="flex min-h-screen flex-col bg-slate-100">
    <?php require EKO_SAMPA_PLUGIN_DIR . 'views/partials/public-experience/guest-banner.php'; ?>
    <div class="flex min-h-0 flex-1 flex-col">
        <?php
        $GLOBALS['eko_sampa_editor_embedded'] = true;
        require EKO_SAMPA_PLUGIN_DIR . 'views/editor-canvas.php';
        unset($GLOBALS['eko_sampa_editor_embedded']);
        ?>
    </div>
</div>
