<?php
/**
 * Loading state block (Alpine x-show expression in $eko_ui_show).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$eko_ui_show    = isset($eko_ui_show) ? (string) $eko_ui_show : 'loading';
$eko_ui_message = isset($eko_ui_message) ? (string) $eko_ui_message : __('Loading…', 'eko-sampa');

?>
<div class="eko-ui-loading" x-show="<?php echo esc_attr($eko_ui_show); ?>" x-cloak role="status" aria-live="polite">
    <span class="eko-ui-loading__spinner" aria-hidden="true"></span>
    <span><?php echo esc_html($eko_ui_message); ?></span>
</div>
