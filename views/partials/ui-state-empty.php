<?php
/**
 * Empty state block.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$eko_ui_show    = isset($eko_ui_show) ? (string) $eko_ui_show : 'false';
$eko_ui_title   = isset($eko_ui_title) ? (string) $eko_ui_title : __('Nothing here yet', 'eko-sampa');
$eko_ui_message = isset($eko_ui_message) ? (string) $eko_ui_message : '';

?>
<div class="eko-ui-empty" x-show="<?php echo esc_attr($eko_ui_show); ?>" x-cloak>
    <p class="eko-ui-empty__title"><?php echo esc_html($eko_ui_title); ?></p>
    <?php if ($eko_ui_message !== '') : ?>
        <p class="mt-1 text-xs"><?php echo esc_html($eko_ui_message); ?></p>
    <?php endif; ?>
    <?php if (! empty($eko_ui_slot)) : ?>
        <div class="mt-3"><?php echo $eko_ui_slot; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
    <?php endif; ?>
</div>
