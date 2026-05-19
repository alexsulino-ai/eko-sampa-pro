<?php
/**
 * Template thumbnail preview (detail / edit). Expects Alpine scope: ekoTemplatesFactory.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

?>
<div class="eko-tpl-preview eko-tpl-preview--standalone" x-show="recordId" x-cloak>
    <div class="eko-tpl-preview__frame"
        :data-eko-thumbnail-state="previewRow().thumbnail_state || 'missing'"
        @click="openZoom(previewRow())"
        :class="thumbnailSrc(previewRow()) ? 'eko-tpl-preview__frame--zoom' : ''"
        :title="thumbnailSrc(previewRow()) ? '<?php echo esc_js(__('Enlarge preview', 'eko-sampa')); ?>' : ''"
    >
        <div class="eko-tpl-preview__skeleton" x-show="thumbnailSrc(previewRow()) && !thumbLoaded(recordId) && !thumbFailed(recordId)" x-cloak></div>
        <img
            class="eko-tpl-preview__img"
            :src="thumbnailSrc(previewRow())"
            :alt="previewRow().nome || ''"
            decoding="async"
            x-show="thumbnailSrc(previewRow()) && !thumbFailed(recordId)"
            x-init="ensureThumbLoaded($el, recordId)"
            @load="markThumbLoaded(recordId)"
            @error="markThumbFailed(recordId, $event.target)"
        />
        <div class="eko-tpl-preview__empty" x-show="!thumbnailSrc(previewRow()) || thumbFailed(recordId)" x-cloak>
            <svg class="eko-tpl-preview__empty-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 18.75V5.25A2.25 2.25 0 0 0 20.25 3H3.75A2.25 2.25 0 0 0 1.5 5.25v13.5A2.25 2.25 0 0 0 3.75 21Z" />
            </svg>
            <span class="eko-tpl-preview__empty-label"><?php echo esc_html__('No preview', 'eko-sampa'); ?></span>
        </div>
    </div>
    <p class="eko-tpl-preview__caption" x-text="formatDimensions(previewRow())"></p>
</div>
