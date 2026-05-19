<?php
/**
 * JPEG integrity checks for persisted template thumbnails.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Eko_Sampa_Template_Thumbnail_Validator {

    /**
     * @return true|\WP_Error
     */
    public static function validate_jpeg_binary(string $binary): bool|\WP_Error {
        $min_bytes = Eko_Sampa_Template_Thumbnail_Config::MIN_FILE_BYTES;
        $max_bytes = Eko_Sampa_Template_Thumbnail_Config::MAX_FILE_BYTES;
        $min_w     = Eko_Sampa_Template_Thumbnail_Config::MIN_WIDTH_PX;
        $min_h     = Eko_Sampa_Template_Thumbnail_Config::MIN_HEIGHT_PX;

        if ($binary === '') {
            return new \WP_Error('eko_sampa_thumb_empty', __('Thumbnail data is empty.', 'eko-sampa'), ['status' => 400]);
        }

        $len = strlen($binary);
        if ($len < $min_bytes) {
            return new \WP_Error(
                'eko_sampa_thumb_small',
                __('Thumbnail file is too small to be valid.', 'eko-sampa'),
                ['status' => 400]
            );
        }

        if ($len > $max_bytes) {
            return new \WP_Error(
                'eko_sampa_thumb_large',
                sprintf(
                    /* translators: %d: max kilobytes */
                    __('Thumbnail exceeds %d KB limit.', 'eko-sampa'),
                    (int) round($max_bytes / 1024)
                ),
                ['status' => 413]
            );
        }

        if (! str_starts_with($binary, "\xFF\xD8\xFF")) {
            return new \WP_Error('eko_sampa_thumb_format', __('Thumbnail is not a valid JPEG.', 'eko-sampa'), ['status' => 400]);
        }

        $info = @getimagesizefromstring($binary);
        if (! is_array($info) || ! isset($info[0], $info[1], $info[2])) {
            return new \WP_Error('eko_sampa_thumb_decode', __('Could not decode thumbnail image.', 'eko-sampa'), ['status' => 400]);
        }

        if ((int) $info[2] !== IMAGETYPE_JPEG) {
            return new \WP_Error('eko_sampa_thumb_format', __('Thumbnail must be JPEG.', 'eko-sampa'), ['status' => 400]);
        }

        $w = (int) $info[0];
        $h = (int) $info[1];
        if ($w < $min_w || $h < $min_h) {
            return new \WP_Error(
                'eko_sampa_thumb_dimensions',
                __('Thumbnail dimensions are below the minimum.', 'eko-sampa'),
                ['status' => 400]
            );
        }

        return true;
    }
}
