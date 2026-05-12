<?php
/**
 * Minimal document shell for Eko Sampa frontend routes (no theme chrome).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$view = sanitize_key((string) get_query_var(Eko_Sampa_Frontend_Router::QUERY_VIEW));
if ($view === '') {
    $view = 'dashboard';
}

?><!DOCTYPE html>
<html <?php language_attributes(); ?> class="h-full">
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class('eko-sampa-frontend min-h-full bg-slate-50 text-slate-900 antialiased'); ?>>
<?php
Eko_Sampa_Frontend_Router::render_app_view($view);
wp_footer();
?>
</body>
</html>
