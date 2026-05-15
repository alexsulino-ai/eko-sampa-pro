<?php
/**
 * Error state block (bind $eko_ui_show to Alpine error expression).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$eko_ui_show = isset($eko_ui_show) ? (string) $eko_ui_show : 'error';

?>
<div class="eko-ui-error" x-show="<?php echo esc_attr($eko_ui_show); ?>" x-text="<?php echo esc_attr($eko_ui_show); ?>" x-cloak role="alert"></div>
