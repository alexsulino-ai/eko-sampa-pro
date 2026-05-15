<?php
/**
 * Server-side template thumbnail rasterization (GD) — reliable fallback when client capture fails.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Renders template elements to a single JPG using GD (no browser / html-to-image).
 */
final class Eko_Sampa_Template_Thumbnail_Generator {

    private const MM_TO_PX = 3.7795275591;

    /**
     * @return true|\WP_Error
     */
    public static function generate_for_id(int $template_id): bool|\WP_Error {
        if ($template_id <= 0) {
            return new \WP_Error('eko_sampa_thumb_invalid', __('Invalid template.', 'eko-sampa'), ['status' => 400]);
        }

        $row = (new Eko_Sampa_Template())->get($template_id);
        if (! is_array($row)) {
            return new \WP_Error('eko_sampa_not_found', __('Not found.', 'eko-sampa'), ['status' => 404]);
        }

        return self::generate_from_row($row);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return true|\WP_Error
     */
    public static function generate_from_row(array $row): bool|\WP_Error {
        if (! function_exists('imagecreatetruecolor')) {
            return new \WP_Error(
                'eko_sampa_thumb_gd',
                __('GD extension is required to generate thumbnails.', 'eko-sampa'),
                ['status' => 500]
            );
        }

        $id = (int) ( $row['id'] ?? 0 );
        if ($id <= 0) {
            return new \WP_Error('eko_sampa_thumb_invalid', __('Invalid template.', 'eko-sampa'), ['status' => 400]);
        }

        $renderer = new Eko_Sampa_Template_Renderer();
        $elements = $renderer->parse_elements_from_template_row($row);
        if ($elements === []) {
            return new \WP_Error(
                'eko_sampa_thumb_no_elements',
                __('Template has no elements to render.', 'eko-sampa'),
                ['status' => 400]
            );
        }

        $width_mm  = max(1.0, (float) ( $row['width_mm'] ?? 210 ));
        $height_mm = max(1.0, (float) ( $row['height_mm'] ?? 297 ));
        $canvas_w  = max(1, (int) round($width_mm * self::MM_TO_PX));
        $canvas_h  = max(1, (int) round($height_mm * self::MM_TO_PX));
        $max_w     = Eko_Sampa_Template_Thumbnail_Config::MAX_WIDTH_PX;
        $scale     = min(1.0, $max_w / $canvas_w);
        $out_w     = max(1, (int) round($canvas_w * $scale));
        $out_h     = max(1, (int) round($canvas_h * $scale));

        $im = imagecreatetruecolor($out_w, $out_h);
        if (! $im instanceof \GdImage) {
            return new \WP_Error('eko_sampa_thumb_gd', __('Could not allocate image.', 'eko-sampa'), ['status' => 500]);
        }

        imagealphablending($im, true);
        imagesavealpha($im, false);
        $white = imagecolorallocate($im, 255, 255, 255);
        imagefilledrectangle($im, 0, 0, $out_w, $out_h, $white);

        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }
            self::draw_element($im, $element, $scale);
        }

        ob_start();
        $quality = (int) round(Eko_Sampa_Template_Thumbnail_Config::JPEG_QUALITY * 100);
        imagejpeg($im, null, max(50, min(95, $quality)));
        $binary = ob_get_clean();
        if (is_resource($im) || $im instanceof \GdImage) {
            imagedestroy($im);
        }

        if (! is_string($binary) || strlen($binary) < 32) {
            return new \WP_Error('eko_sampa_thumb_empty', __('Thumbnail render produced empty output.', 'eko-sampa'), ['status' => 500]);
        }

        Eko_Sampa_Template_Thumbnail::mark_generating($id);
        $saved = Eko_Sampa_Template_Thumbnail::save_jpeg_binary($id, $binary);
        if ($saved instanceof \WP_Error) {
            Eko_Sampa_Template_Thumbnail::clear_generating($id);

            return $saved;
        }

        return true;
    }

    /**
     * @param \GdImage              $im
     * @param array<string, mixed>  $el
     */
    private static function draw_element(\GdImage $im, array $el, float $scale): void {
        $type = sanitize_key((string) ( $el['type'] ?? 'text' ));
        $x    = (int) round((float) ( $el['x'] ?? 0 ) * $scale);
        $y    = (int) round((float) ( $el['y'] ?? 0 ) * $scale);
        $w    = max(1, (int) round((float) ( $el['width'] ?? 10 ) * $scale));
        $h    = max(1, (int) round((float) ( $el['height'] ?? 10 ) * $scale));
        $st   = is_array($el['styles'] ?? null) ? $el['styles'] : [];

        if ($type === 'image') {
            self::draw_image_element($im, $el, $x, $y, $w, $h);
            return;
        }

        if ($type === 'rectangle') {
            $fill = self::allocate_color($im, (string) ( $st['backgroundColor'] ?? '#f1f5f9' ), 0 );
            imagefilledrectangle($im, $x, $y, $x + $w, $y + $h, $fill);
            self::draw_border($im, $st, $x, $y, $w, $h, $scale);
            return;
        }

        $bg = (string) ( $st['backgroundColor'] ?? 'transparent' );
        if ($bg !== '' && $bg !== 'transparent') {
            $bgc = self::allocate_color($im, $bg, 0);
            imagefilledrectangle($im, $x, $y, $x + $w, $y + $h, $bgc);
        }

        $text = (string) ( $el['content'] ?? '' );
        if ($text !== '') {
            $color = self::allocate_color($im, (string) ( $st['color'] ?? '#111827' ), 0);
            self::draw_text_in_box($im, $text, $x, $y, $w, $h, $color, $st, $scale);
        }

        self::draw_border($im, $st, $x, $y, $w, $h, $scale);
    }

    /**
     * @param array<string, mixed> $el
     */
    private static function draw_image_element(\GdImage $im, array $el, int $x, int $y, int $w, int $h): void {
        $src = '';
        if (isset($el['src']) && is_string($el['src']) && $el['src'] !== '') {
            $src = $el['src'];
        } elseif (isset($el['content']) && is_string($el['content'])) {
            $src = $el['content'];
        }

        $src = trim($src);
        $placeholder = imagecolorallocate($im, 226, 232, 240);
        imagefilledrectangle($im, $x, $y, $x + $w, $y + $h, $placeholder);

        $sub = self::load_image($src);
        if (! $sub instanceof \GdImage) {
            return;
        }

        $sw = imagesx($sub);
        $sh = imagesy($sub);
        if ($sw > 0 && $sh > 0) {
            imagecopyresampled($im, $sub, $x, $y, 0, 0, $w, $h, $sw, $sh);
        }
        imagedestroy($sub);
    }

    private static function load_image(string $src): ?\GdImage {
        if ($src === '' || str_starts_with($src, 'javascript:')) {
            return null;
        }

        if (str_starts_with($src, 'data:image')) {
            $parts = explode(',', $src, 2);
            $bin   = base64_decode($parts[1] ?? '', true);
            if (! is_string($bin) || $bin === '') {
                return null;
            }
            $img = @imagecreatefromstring($bin);

            return $img instanceof \GdImage ? $img : null;
        }

        $path = self::resolve_local_path($src);
        if ($path === '' || ! is_readable($path)) {
            return null;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $img = match ($ext) {
            'png'  => @imagecreatefrompng($path),
            'gif'  => @imagecreatefromgif($path),
            'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => @imagecreatefromjpeg($path),
        };

        return $img instanceof \GdImage ? $img : null;
    }

    private static function resolve_local_path(string $src): string {
        $src = trim($src);
        if ($src === '') {
            return '';
        }

        if (str_starts_with($src, '/') && is_readable($src)) {
            return $src;
        }

        $upload = wp_upload_dir();
        $baseurl = trailingslashit((string) ( $upload['baseurl'] ?? '' ) );
        $basedir = trailingslashit((string) ( $upload['basedir'] ?? '' ) );
        if ($baseurl !== '' && $basedir !== '' && str_starts_with($src, $baseurl)) {
            $rel = substr($src, strlen($baseurl));
            $path = $basedir . ltrim($rel, '/');
            if (is_readable($path)) {
                return $path;
            }
        }

        if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://')) {
            $home = home_url('/');
            if (str_starts_with($src, $home)) {
                $rel = wp_parse_url($src, PHP_URL_PATH);
                if (is_string($rel) && $rel !== '') {
                    $abs = ABSPATH . ltrim($rel, '/');
                    if (is_readable($abs)) {
                        return $abs;
                    }
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $st
     */
    private static function draw_border(\GdImage $im, array $st, int $x, int $y, int $w, int $h, float $scale): void {
        $bw = (int) round((float) ( $st['borderWidth'] ?? 0 ) * $scale);
        if ($bw <= 0) {
            return;
        }
        $bc = self::allocate_color($im, (string) ( $st['borderColor'] ?? '#cbd5e1' ), 0);
        imagesetthickness($im, max(1, $bw));
        imagerectangle($im, $x, $y, $x + $w, $y + $h, $bc);
        imagesetthickness($im, 1);
    }

    /**
     * @param array<string, mixed> $st
     */
    private static function draw_text_in_box(
        \GdImage $im,
        string $text,
        int $x,
        int $y,
        int $w,
        int $h,
        int $color,
        array $st,
        float $scale
    ): void {
        $text = preg_replace('/\s+/u', ' ', trim(wp_strip_all_tags($text))) ?? '';
        if ($text === '') {
            return;
        }

        $font_size = max(8, (int) round((float) ( $st['fontSize'] ?? 14 ) * $scale));
        $pad       = max(2, (int) round(4 * $scale));
        $max_chars = max(8, (int) floor( ( $w - $pad * 2 ) / ( $font_size * 0.55 ) ) );
        if (strlen($text) > $max_chars) {
            $text = substr($text, 0, max(1, $max_chars - 1) ) . '…';
        }

        $font = self::resolve_font_path();
        if ($font !== '' && function_exists('imagettftext')) {
            $box   = imagettfbbox($font_size, 0, $font, $text);
            $text_h = is_array($box) ? abs((int) ( $box[1] - $box[7] )) : $font_size;
            $text_y = $y + $pad + $text_h;
            imagettftext($im, $font_size, 0, $x + $pad, min($y + $h - $pad, $text_y), $color, $font, $text);
            return;
        }

        imagestring($im, 3, $x + $pad, $y + $pad, $text, $color);
    }

    private static function resolve_font_path(): string {
        $candidates = [
            EKO_SAMPA_PLUGIN_DIR . 'assets/fonts/DejaVuSans.ttf',
            'C:\\Windows\\Fonts\\arial.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/System/Library/Fonts/Supplemental/Arial.ttf',
        ];
        foreach ($candidates as $path) {
            if (is_string($path) && is_readable($path)) {
                return $path;
            }
        }

        return '';
    }

    private static function allocate_color(\GdImage $im, string $css, int $alpha): int {
        $css = trim($css);
        if ($css === '' || $css === 'transparent') {
            return imagecolorallocatealpha($im, 255, 255, 255, 127);
        }

        $hex = ltrim($css, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            return imagecolorallocate($im, 17, 24, 39);
        }

        $r = (int) hexdec(substr($hex, 0, 2));
        $g = (int) hexdec(substr($hex, 2, 2));
        $b = (int) hexdec(substr($hex, 4, 2));

        if ($alpha > 0) {
            return imagecolorallocatealpha($im, $r, $g, $b, min(127, $alpha));
        }

        return imagecolorallocate($im, $r, $g, $b);
    }
}
