<?php
/**
 * User-scoped gallery uploads under wp-content/uploads/eko-sampa/galeria/user-{id}/.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Gallery helpers (list / upload / delete) with path traversal protection.
 */
final class Eko_Sampa_Upload_Service {

    private const SUBDIR = 'eko-sampa/galeria';

    public function gallery_dir_for_user(int $user_id): string {
        $user_id = max(0, $user_id);
        $upload   = wp_upload_dir();
        $base     = trailingslashit((string) ($upload['basedir'] ?? ''));

        return $base . self::SUBDIR . '/user-' . $user_id;
    }

    public function gallery_url_for_user(int $user_id): string {
        $user_id = max(0, $user_id);
        $upload   = wp_upload_dir();
        $base     = trailingslashit((string) ($upload['baseurl'] ?? ''));

        return $base . self::SUBDIR . '/user-' . $user_id;
    }

    public function ensure_gallery_dir(int $user_id): bool {
        $dir = $this->gallery_dir_for_user($user_id);
        if ($dir === '') {
            return false;
        }

        if (! wp_mkdir_p($dir)) {
            return false;
        }

        $this->write_htaccess($dir);

        return is_dir($dir);
    }

    /**
     * @return array<int, array{name: string, url: string}>
     */
    public function list_images(int $user_id): array {
        if ($user_id <= 0 || ! $this->ensure_gallery_dir($user_id)) {
            return [];
        }

        $dir = $this->gallery_dir_for_user($user_id);
        $files = glob($dir . '/*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE) ?: [];
        $out   = [];
        $base  = $this->gallery_url_for_user($user_id);

        foreach ($files as $path) {
            if (! is_file($path)) {
                continue;
            }
            $name = basename((string) $path);
            if (! $this->is_safe_filename($name)) {
                continue;
            }
            $out[] = [
                'name' => $name,
                'url'  => trailingslashit($base) . rawurlencode($name),
            ];
        }

        usort(
            $out,
            static function (array $a, array $b): int {
                return strcmp($a['name'], $b['name']);
            }
        );

        return $out;
    }

    /**
     * Handle a single file from $_FILES entry shape.
     *
     * @param array<string, mixed> $file One entry from $_FILES.
     *
     * @return string|\WP_Error Public URL of stored file.
     */
    public function handle_upload(int $user_id, array $file) {
        if ($user_id <= 0) {
            return new \WP_Error('eko_sampa_invalid_user', __('Invalid user.', 'eko-sampa'));
        }

        if (! $this->ensure_gallery_dir($user_id)) {
            return new \WP_Error('eko_sampa_mkdir', __('Could not create gallery directory.', 'eko-sampa'));
        }

        $tmp = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
        if ($tmp === '' || ! is_uploaded_file($tmp)) {
            return new \WP_Error('eko_sampa_upload', __('No file uploaded.', 'eko-sampa'));
        }

        $orig = isset($file['name']) ? sanitize_file_name((string) $file['name']) : 'image';
        $check = wp_check_filetype_and_ext($tmp, $orig, $this->allowed_mimes());
        if (empty($check['ext']) || empty($check['type'])) {
            return new \WP_Error('eko_sampa_type', __('Unsupported file type.', 'eko-sampa'));
        }

        $target_dir = $this->gallery_dir_for_user($user_id);
        $name       = wp_unique_filename($target_dir, pathinfo($orig, PATHINFO_FILENAME) . '.' . $check['ext']);
        if (! $this->is_safe_filename($name)) {
            return new \WP_Error('eko_sampa_name', __('Invalid file name.', 'eko-sampa'));
        }

        $dest = trailingslashit($target_dir) . $name;
        if (! @move_uploaded_file($tmp, $dest)) {
            return new \WP_Error('eko_sampa_move', __('Could not store uploaded file.', 'eko-sampa'));
        }

        return trailingslashit($this->gallery_url_for_user($user_id)) . rawurlencode($name);
    }

    public function delete_image(int $user_id, string $name): bool {
        if ($user_id <= 0 || ! $this->is_safe_filename($name)) {
            return false;
        }

        $path = trailingslashit($this->gallery_dir_for_user($user_id)) . $name;

        if (! is_file($path)) {
            return false;
        }

        return false !== unlink($path);
    }

    private function is_safe_filename(string $name): bool {
        if ($name === '' || str_contains($name, '..') || str_contains($name, '/') || str_contains($name, '\\')) {
            return false;
        }

        return (bool) preg_match('/^[a-zA-Z0-9._-]+$/', $name);
    }

    /**
     * @return array<string, string>
     */
    private function allowed_mimes(): array {
        return [
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png'          => 'image/png',
            'gif'          => 'image/gif',
            'webp'         => 'image/webp',
        ];
    }

    private function write_htaccess(string $dir): void {
        $ht = trailingslashit($dir) . '.htaccess';
        if (is_readable($ht)) {
            return;
        }

        $rules = "# Eko Sampa gallery\nOptions -Indexes\n";
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        @file_put_contents($ht, $rules);
    }
}
