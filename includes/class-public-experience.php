<?php
/**
 * Public marketing home + guest editor funnel — option keys + hooks façade.
 *
 * Business logic lives in {@see Eko_Sampa_Public_Experience_Service}.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Eko_Sampa_Public_Experience {

    public const OPT_HOME_ENABLED = 'eko_sampa_public_home_enabled';

    public const OPT_GUEST_EDIT_ENABLED = 'eko_sampa_guest_editing_enabled';

    public const OPT_GUEST_QP_ENABLED = 'eko_sampa_guest_quick_print_enabled';

    public const OPT_MAX_SESSIONS_IP = 'eko_sampa_guest_max_sessions_ip';

    public const OPT_QP_MAX_PER_HOUR = 'eko_sampa_guest_qp_max_per_hour';

    public const OPT_QP_MAX_CONCURRENT = 'eko_sampa_guest_qp_max_concurrent';

    public const OPT_FORK_DEBOUNCE_SEC = 'eko_sampa_guest_fork_debounce_sec';

    public const OPT_FORK_MAX_PER_HOUR = 'eko_sampa_guest_max_fork_per_hour';

    public const OPT_FEATURED_IDS = 'eko_sampa_public_home_featured_ids';

    /** Comma-separated master IDs for “Popular” tab (manual boost; no heavy analytics). */
    public const OPT_TRENDING_IDS = 'eko_sampa_public_home_trending_ids';

    /** Soft ceiling for `user_id = 0` session rows site-wide (cleanup job + fork guard). */
    public const OPT_MAX_GUEST_SESSIONS_GLOBAL = 'eko_sampa_guest_max_sessions_global';

    public const OPT_ALLOW_DOWNLOAD = 'eko_sampa_guest_allow_download';

    public const OPT_METRIC_SESSIONS = 'eko_sampa_metric_guest_sessions_created';

    public const OPT_METRIC_PERSIST = 'eko_sampa_metric_guest_persist_count';

    public const OPT_METRIC_QP = 'eko_sampa_metric_guest_quick_print';

    public const OPT_METRIC_MODAL_OPENS = 'eko_sampa_metric_guest_conversion_modal';

    public const OPT_METRIC_RECOVERY_SHOWN = 'eko_sampa_metric_guest_recovery_banner';

    public static function register_hooks(): void {
        $s = Eko_Sampa_Public_Experience_Service::instance();
        Eko_Sampa_Public_Analytics::register_hooks();
        add_filter('eko_sampa_quick_print_enabled', [$s, 'filter_quick_print_guest'], 5, 1);
        add_action('admin_init', [self::class, 'register_settings']);
        add_action('eko_sampa_template_session_forked', [$s, 'on_session_forked'], 10, 2);
        add_action('eko_sampa_session_persisted_to_user_template', [$s, 'on_session_persisted'], 10, 3);
        add_action('wp_head', [$s, 'maybe_output_public_home_seo'], 2);
        add_filter('document_title_parts', [self::class, 'filter_public_home_document_title'], 20, 1);
        Eko_Sampa_Public_Experience_Service::register_cron_hooks();
    }

    /**
     * @param mixed $parts WordPress expects an array; other plugins may corrupt the filter chain.
     *
     * @return array<string, string>
     */
    public static function filter_public_home_document_title($parts): array {
        if (! is_array($parts)) {
            $parts = [
                'title' => is_string($parts) ? $parts : '',
            ];
        }

        if ((string) get_query_var(Eko_Sampa_Frontend_Router::QUERY_FLAG) !== '1') {
            return $parts;
        }
        if (sanitize_key((string) get_query_var(Eko_Sampa_Frontend_Router::QUERY_VIEW)) !== 'public_home') {
            return $parts;
        }
        if (! Eko_Sampa_Public_Experience_Service::instance()->is_public_home_enabled()) {
            return $parts;
        }
        $cat = isset($_GET['categoria']) ? sanitize_text_field((string) wp_unslash((string) $_GET['categoria'])) : '';
        if ($cat === '') {
            return $parts;
        }
        $parts['title'] = sprintf(
            /* translators: %s: category label */
            __('Modelos %s — Eko Sampa', 'eko-sampa'),
            $cat
        );

        return $parts;
    }

    public static function register_settings(): void {
        foreach (
            [
                self::OPT_HOME_ENABLED              => ['default' => 1, 'type' => 'boolean'],
                self::OPT_GUEST_EDIT_ENABLED        => ['default' => 1, 'type' => 'boolean'],
                self::OPT_GUEST_QP_ENABLED          => ['default' => 1, 'type' => 'boolean'],
                self::OPT_MAX_SESSIONS_IP         => ['default' => 5, 'type' => 'integer'],
                self::OPT_QP_MAX_PER_HOUR         => ['default' => 20, 'type' => 'integer'],
                self::OPT_QP_MAX_CONCURRENT       => ['default' => 2, 'type' => 'integer'],
                self::OPT_FORK_DEBOUNCE_SEC       => ['default' => 25, 'type' => 'integer'],
                self::OPT_FORK_MAX_PER_HOUR       => ['default' => 15, 'type' => 'integer'],
                self::OPT_FEATURED_IDS            => ['default' => '', 'type' => 'string'],
                self::OPT_TRENDING_IDS            => ['default' => '', 'type' => 'string'],
                self::OPT_MAX_GUEST_SESSIONS_GLOBAL => ['default' => 2500, 'type' => 'integer'],
                self::OPT_ALLOW_DOWNLOAD          => ['default' => 0, 'type' => 'boolean'],
                self::OPT_METRIC_SESSIONS         => ['default' => 0, 'type' => 'integer'],
                self::OPT_METRIC_PERSIST          => ['default' => 0, 'type' => 'integer'],
                self::OPT_METRIC_QP               => ['default' => 0, 'type' => 'integer'],
                self::OPT_METRIC_MODAL_OPENS      => ['default' => 0, 'type' => 'integer'],
                self::OPT_METRIC_RECOVERY_SHOWN   => ['default' => 0, 'type' => 'integer'],
            ] as $key => $schema
        ) {
            register_setting(
                'eko_sampa_public_experience',
                $key,
                [
                    'type'              => $schema['type'],
                    'default'           => $schema['default'],
                    'sanitize_callback' => [self::class, 'sanitize_setting'],
                ]
            );
        }
    }

    /**
     * @param mixed $value
     */
    public static function sanitize_setting($value): mixed {
        if (is_string($value) && ( $value === '0' || $value === '1' )) {
            return (int) $value;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        $s = is_scalar($value) ? (string) $value : '';
        if ($s !== '' && preg_match('/^\d+$/', $s)) {
            return (int) $s;
        }
        if (is_string($value)) {
            return sanitize_text_field($value);
        }

        return $value;
    }

    public static function is_public_home_enabled(): bool {
        return Eko_Sampa_Public_Experience_Service::instance()->is_public_home_enabled();
    }

    public static function is_guest_editing_enabled(): bool {
        return Eko_Sampa_Public_Experience_Service::instance()->is_guest_editing_enabled();
    }

    public static function is_guest_quick_print_enabled(): bool {
        return Eko_Sampa_Public_Experience_Service::instance()->is_guest_quick_print_enabled();
    }

    public static function is_guest_download_enabled(): bool {
        return Eko_Sampa_Public_Experience_Service::instance()->is_guest_download_enabled();
    }

    public static function filter_quick_print_guest(bool $enabled): bool {
        return Eko_Sampa_Public_Experience_Service::instance()->filter_quick_print_guest($enabled);
    }

    public static function on_session_forked(int $source_id, int $new_id): void {
        Eko_Sampa_Public_Experience_Service::instance()->on_session_forked($source_id, $new_id);
    }

    public static function on_session_persisted(int $session_id, int $new_user_template_id, int $parent_id): void {
        Eko_Sampa_Public_Experience_Service::instance()->on_session_persisted($session_id, $new_user_template_id, $parent_id);
    }

    public static function bump_quick_print_metric(): void {
        Eko_Sampa_Public_Experience_Service::instance()->bump_quick_print_metric();
    }

    public static function request_client_key(): string {
        return Eko_Sampa_Public_Experience_Service::instance()->request_client_key();
    }

    /**
     * @return true|\WP_Error
     */
    public static function guard_session_fork(int $source_template_id) {
        return Eko_Sampa_Public_Experience_Service::instance()->guard_session_fork($source_template_id);
    }

    /**
     * @return true|\WP_Error
     */
    public static function guard_guest_quick_print_create(int $user_id, int $template_id, string $guest_token) {
        return Eko_Sampa_Public_Experience_Service::instance()->guard_guest_quick_print_create($user_id, $template_id, $guest_token);
    }

    public static function record_guest_quick_print_hour_usage(): void {
        Eko_Sampa_Public_Experience_Service::instance()->record_guest_quick_print_hour_usage();
    }

    public static function guest_editor_query_looks_valid(): bool {
        return Eko_Sampa_Public_Experience_Service::instance()->guest_editor_query_looks_valid();
    }

    public static function guest_editor_session_row_valid(): bool {
        return Eko_Sampa_Public_Experience_Service::instance()->guest_editor_session_row_valid();
    }

    /**
     * @return array<int, int>
     */
    public static function featured_template_ids(): array {
        return Eko_Sampa_Public_Experience_Service::instance()->featured_template_ids();
    }
}
