<?php
/**
 * Full-screen thumbnail zoom (no x-teleport — compatible with all template CRUD views).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

?>
<div
    class="eko-templates-zoom"
    x-show="zoom.open"
    x-cloak
    x-transition.opacity
    @click.self="closeZoom()"
    style="display: none;"
    :style="zoom.open ? 'display:flex' : 'display:none'"
>
    <div class="eko-templates-zoom__backdrop" @click="closeZoom()"></div>
    <div class="eko-templates-zoom__panel" role="dialog" aria-modal="true" :aria-label="zoom.title || ''">
        <button type="button" class="eko-templates-zoom__close" @click="closeZoom()" aria-label="<?php echo esc_attr__('Close', 'eko-sampa'); ?>">&times;</button>
        <img class="eko-templates-zoom__img" :src="zoom.src" :alt="zoom.title || ''" />
    </div>
</div>
