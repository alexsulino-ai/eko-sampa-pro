<?php
/**
 * Central orchestration for public marketing + guest editor (quotas, catalog, conversion hints).
 *
 * Keeps REST/router thin; does not own renderer, json_data contracts, or derivation math.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Eko_Sampa_Public_Experience_Service {

    private static ?self $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function register_cron_hooks(): void {
        add_action(Eko_Sampa_Template_Derivation::CRON_HOOK, [self::class, 'on_derivation_cleanup_soft_cap'], 25);
    }

    /**
     * After core derivation cleanup: enforce global guest session ceiling (session rows only).
     */
    public static function on_derivation_cleanup_soft_cap(): void {
        self::instance()->purge_global_guest_sessions_if_over_cap();
    }

    public function is_public_home_enabled(): bool {
        return (bool) get_option(Eko_Sampa_Public_Experience::OPT_HOME_ENABLED, 1);
    }

    public function is_guest_editing_enabled(): bool {
        return (bool) get_option(Eko_Sampa_Public_Experience::OPT_GUEST_EDIT_ENABLED, 1);
    }

    public function is_guest_quick_print_enabled(): bool {
        return (bool) get_option(Eko_Sampa_Public_Experience::OPT_GUEST_QP_ENABLED, 1);
    }

    public function is_guest_download_enabled(): bool {
        return (bool) get_option(Eko_Sampa_Public_Experience::OPT_ALLOW_DOWNLOAD, 0);
    }

    public function filter_quick_print_guest(bool $enabled): bool {
        if (! $enabled) {
            return false;
        }
        if (get_current_user_id() > 0) {
            return $enabled;
        }

        return $this->is_guest_quick_print_enabled();
    }

    public function on_session_forked(int $source_id, int $new_id): void {
        unset($source_id);
        $this->bump_metric(Eko_Sampa_Public_Experience::OPT_METRIC_SESSIONS);
        $this->register_guest_session_for_quota($new_id);

        $hour_key = 'eko_sampa_pub_fork_h_' . $this->request_client_key();
        $nh        = (int) get_transient($hour_key);
        set_transient($hour_key, (string) ( $nh + 1 ), HOUR_IN_SECONDS);
    }

    public function on_session_persisted(int $session_id, int $new_user_template_id, int $parent_id): void {
        unset($session_id, $new_user_template_id, $parent_id);
        $this->bump_metric(Eko_Sampa_Public_Experience::OPT_METRIC_PERSIST);
    }

    private function bump_metric(string $option): void {
        $v = (int) get_option($option, 0);
        update_option($option, $v + 1, false);
    }

    public function bump_quick_print_metric(): void {
        $this->bump_metric(Eko_Sampa_Public_Experience::OPT_METRIC_QP);
    }

    public function bump_conversion_modal_metric(): void {
        $this->bump_metric(Eko_Sampa_Public_Experience::OPT_METRIC_MODAL_OPENS);
    }

    public function bump_recovery_banner_metric(): void {
        $this->bump_metric(Eko_Sampa_Public_Experience::OPT_METRIC_RECOVERY_SHOWN);
        do_action('eko_sampa_editor_recovery_tracked');
    }

    public function request_client_key(): string {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '0';
        $ua  = isset($_SERVER['HTTP_USER_AGENT']) ? substr(sha1((string) wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 12) : 'na';

        return substr(sha1($ip . '|' . $ua), 0, 32);
    }

    /**
     * @return true|\WP_Error
     */
    public function guard_session_fork(int $source_template_id) {
        if (! $this->is_public_home_enabled() || ! $this->is_guest_editing_enabled()) {
            return new \WP_Error(
                'eko_sampa_guest_disabled',
                __('Public personalization is not available right now.', 'eko-sampa'),
                ['status' => 503]
            );
        }

        $key = 'eko_sampa_pub_fork_' . $this->request_client_key();
        if (get_transient($key)) {
            return new \WP_Error(
                'eko_sampa_rate_limited',
                __('Please wait a moment before starting another session.', 'eko-sampa'),
                ['status' => 429]
            );
        }

        $debounce = max(5, min(120, (int) get_option(Eko_Sampa_Public_Experience::OPT_FORK_DEBOUNCE_SEC, 25)));
        set_transient($key, '1', $debounce);

        $hour_key = 'eko_sampa_pub_fork_h_' . $this->request_client_key();
        $n        = (int) get_transient($hour_key);
        $max_hour = max(3, min(120, (int) get_option(Eko_Sampa_Public_Experience::OPT_FORK_MAX_PER_HOUR, 15)));
        if ($n >= $max_hour) {
            return new \WP_Error(
                'eko_sampa_guest_quota',
                __('Too many sessions started from this network. Try again later.', 'eko-sampa'),
                ['status' => 429]
            );
        }

        $max_sess = max(1, min(50, (int) get_option(Eko_Sampa_Public_Experience::OPT_MAX_SESSIONS_IP, 5)));
        $this->prune_oldest_guest_sessions_if_over($max_sess);

        $this->purge_global_guest_sessions_if_over_cap();

        unset($source_template_id);

        return true;
    }

    private function register_guest_session_for_quota(int $session_id): void {
        if ($session_id <= 0) {
            return;
        }
        $list_key = 'eko_sampa_pub_sess_ids_' . $this->request_client_key();
        $list     = get_transient($list_key);
        if (! is_array($list)) {
            $list = [];
        }
        $list[] = $session_id;
        set_transient($list_key, $list, DAY_IN_SECONDS);
    }

    private function prune_oldest_guest_sessions_if_over(int $max): void {
        $list_key = 'eko_sampa_pub_sess_ids_' . $this->request_client_key();
        $list      = get_transient($list_key);
        if (! is_array($list) || count($list) < $max) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'eko_sampa_templates';
        while (count($list) >= $max) {
            $old = (int) array_shift($list);
            if ($old <= 0) {
                continue;
            }
            $row = ( new Eko_Sampa_Template() )->get_row_by_id($old);
            if (! is_array($row) || ! Eko_Sampa_Template_Derivation::is_session_row($row)) {
                continue;
            }
            $wpdb->delete($table, ['id' => $old], ['%d']);
            Eko_Sampa_Template_Thumbnail::delete($old);
        }
        set_transient($list_key, $list, DAY_IN_SECONDS);
    }

    /**
     * Site-wide soft cap on anonymous session rows (does not touch user/master templates).
     */
    public function purge_global_guest_sessions_if_over_cap(): void {
        $max = max(200, min(100000, (int) get_option(Eko_Sampa_Public_Experience::OPT_MAX_GUEST_SESSIONS_GLOBAL, 2500)));
        global $wpdb;
        $t = $wpdb->prefix . 'eko_sampa_templates';
        $cnt = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$t}` WHERE template_type = %s AND user_id = %d",
                Eko_Sampa_Template_Derivation::TYPE_SESSION,
                0
            )
        );
        if ($cnt <= $max) {
            return;
        }
        $over = $cnt - $max;
        $ids  = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM `{$t}` WHERE template_type = %s AND user_id = %d ORDER BY updated_at ASC, id ASC LIMIT %d",
                Eko_Sampa_Template_Derivation::TYPE_SESSION,
                0,
                min(500, max(1, $over))
            )
        );
        if (! is_array($ids)) {
            return;
        }
        foreach ($ids as $rid) {
            $id = absint((int) $rid);
            if ($id <= 0) {
                continue;
            }
            $row = ( new Eko_Sampa_Template() )->get_row_by_id($id);
            if (! is_array($row) || ! Eko_Sampa_Template_Derivation::is_session_row($row)) {
                continue;
            }
            $wpdb->delete($wpdb->prefix . 'eko_sampa_quick_print_jobs', ['template_id' => $id], ['%d']);
            $wpdb->delete($t, ['id' => $id], ['%d']);
            Eko_Sampa_Template_Thumbnail::delete($id);
        }
    }

    /**
     * @return true|\WP_Error
     */
    public function guard_guest_quick_print_create(int $user_id, int $template_id, string $guest_token) {
        if ($user_id > 0) {
            return true;
        }
        if (! $this->is_guest_quick_print_enabled()) {
            return new \WP_Error('eko_sampa_guest_qp_disabled', __('Quick print is not available for guests right now.', 'eko-sampa'), ['status' => 503]);
        }

        $conc = max(1, min(10, (int) get_option(Eko_Sampa_Public_Experience::OPT_QP_MAX_CONCURRENT, 2)));
        global $wpdb;
        $jobs = $wpdb->prefix . 'eko_sampa_quick_print_jobs';
        $tok  = preg_replace('/[^a-f0-9]/i', '', $guest_token);
        if ($tok !== '') {
            $open = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$jobs}` WHERE user_id = 0 AND session_token = %s AND status IN (%s, %s)",
                    $tok,
                    Eko_Sampa_Quick_Print_Job::STATUS_QUEUED,
                    Eko_Sampa_Quick_Print_Job::STATUS_SENT_TO_BROWSER
                )
            );
            if ($open >= $conc) {
                return new \WP_Error(
                    'eko_sampa_guest_qp_concurrent',
                    __('Too many open print jobs. Finish or cancel one first.', 'eko-sampa'),
                    ['status' => 429]
                );
            }
        }

        $hour_key = 'eko_sampa_pub_qp_h_' . $this->request_client_key();
        $n        = (int) get_transient($hour_key);
        $maxh     = max(5, min(200, (int) get_option(Eko_Sampa_Public_Experience::OPT_QP_MAX_PER_HOUR, 20)));
        if ($n >= $maxh) {
            return new \WP_Error(
                'eko_sampa_guest_qp_quota',
                __('Print limit reached for this network. Try again later.', 'eko-sampa'),
                ['status' => 429]
            );
        }

        unset($template_id);

        return true;
    }

    public function record_guest_quick_print_hour_usage(): void {
        $hour_key = 'eko_sampa_pub_qp_h_' . $this->request_client_key();
        $n        = (int) get_transient($hour_key);
        set_transient($hour_key, (string) ( $n + 1 ), HOUR_IN_SECONDS);
    }

    public function guest_editor_query_looks_valid(): bool {
        if (! $this->is_guest_editing_enabled()) {
            return false;
        }
        $tid = isset($_GET['template_id']) ? absint((int) $_GET['template_id']) : 0;
        $tok = isset($_GET['session_token']) ? preg_replace('/[^a-f0-9]/i', '', sanitize_text_field((string) $_GET['session_token'])) : '';

        return $tid > 0 && $tok !== '';
    }

    public function guest_editor_session_row_valid(): bool {
        if (! $this->guest_editor_query_looks_valid()) {
            return false;
        }
        $tid = absint((int) $_GET['template_id']);
        $tok = preg_replace('/[^a-f0-9]/i', '', sanitize_text_field((string) $_GET['session_token']));
        $row = ( new Eko_Sampa_Template() )->get_row_by_id($tid);

        return Eko_Sampa_Template_Derivation::request_can_use_session_row($row, $tok);
    }

    /**
     * Query params look like a guest editor URL but the session row cannot be used (expired, wrong fingerprint, etc.).
     */
    public function guest_editor_session_stale(): bool {
        if (! $this->guest_editor_query_looks_valid()) {
            return false;
        }
        if ($this->guest_editor_session_row_valid()) {
            return false;
        }

        return true;
    }

    /**
     * @return array<int, int>
     */
    public function featured_template_ids(): array {
        return $this->parse_id_list((string) get_option(Eko_Sampa_Public_Experience::OPT_FEATURED_IDS, ''));
    }

    /**
     * Admin “trending / popular” boost list (same semantics as featured: explicit IDs).
     *
     * @return array<int, int>
     */
    public function trending_template_ids(): array {
        return $this->parse_id_list((string) get_option(Eko_Sampa_Public_Experience::OPT_TRENDING_IDS, ''));
    }

    /**
     * @return array<int, int>
     */
    private function parse_id_list(string $raw): array {
        $out = [];
        foreach (preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $p) {
            $id = absint((int) $p);
            if ($id > 0) {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }

    public function map_public_catalog_row(array $row): array {
        try {
            $row = Eko_Sampa_Template_Thumbnail::enrich_row_for_public_catalog($row);
        } catch (\Throwable $e) {
            unset($row['json_data']);
            if (defined('EKO_SAMPA_DEBUG') && EKO_SAMPA_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[eko-sampa] public catalog row map: ' . $e->getMessage());
            }
        }
        unset($row['json_data']);
        if (is_array($row)) {
            $row['guest_quick_print_enabled'] = $this->is_guest_quick_print_enabled();
        }
        if (is_array($row)) {
            $row = eko_sampa_marketplace_filter_catalog_row($row);
        }

        return is_array($row) ? $row : [];
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function build_public_catalog_payload(\WP_REST_Request $request) {
        if (! $this->is_public_home_enabled()) {
            return new \WP_Error(
                'eko_sampa_public_disabled',
                __('Public catalog is not available.', 'eko-sampa'),
                ['status' => 404]
            );
        }

        $per_page = (int) $request->get_param('per_page');
        $per_page = max(1, min(48, $per_page > 0 ? $per_page : 24));
        $page     = max(1, (int) $request->get_param('page') ?: 1);
        $offset   = ( $page - 1 ) * $per_page;
        $scope    = sanitize_key((string) ( $request->get_param('scope') ?: 'all' ));
        $scopes_ok = [
            'all',
            'featured',
            'recent',
            'popular',
            'trending_today',
            'trending_week',
            'recently_printed',
            'most_saved',
        ];
        if (! in_array($scope, $scopes_ok, true)) {
            $scope = 'all';
        }

        $args = [
            'limit'     => $per_page + 1,
            'offset'    => $offset,
            'orderby'   => 'updated_at',
            'order'     => 'DESC',
            's'         => (string) $request->get_param('search'),
            'categoria' => (string) $request->get_param('categoria'),
        ];
        if ($scope === 'recent') {
            $args['orderby'] = 'created_at';
            $args['order']   = 'DESC';
        }
        if (in_array($scope, ['trending_today', 'trending_week', 'recently_printed', 'most_saved'], true)) {
            $ordered = Eko_Sampa_Public_Analytics::instance()->get_master_ids_for_scope($scope, 200);
            if ($ordered === []) {
                Eko_Sampa_Public_Analytics::instance()->rebuild_popularity_snapshot();
                $ordered = Eko_Sampa_Public_Analytics::instance()->get_master_ids_for_scope($scope, 200);
            }
            if ($ordered === []) {
                return [
                    'items'          => [],
                    'has_more'       => false,
                    'page'           => $page,
                    'per_page'       => $per_page,
                    'scope'          => $scope,
                    'featured_ids'   => $this->featured_template_ids(),
                    'trending_ids'   => $this->trending_template_ids(),
                    'guest_editing'  => $this->is_guest_editing_enabled(),
                    'guest_qp'       => $this->is_guest_quick_print_enabled(),
                ];
            }
            $slice          = array_slice($ordered, $offset, $per_page + 1);
            $args['id_order'] = $slice;
            $args['offset']   = 0;
            $args['orderby']  = 'updated_at';
        }
        if ($scope === 'featured') {
            $args['featured_ids'] = $this->featured_template_ids();
            if ($args['featured_ids'] === []) {
                return [
                    'items'          => [],
                    'has_more'       => false,
                    'page'           => $page,
                    'per_page'       => $per_page,
                    'scope'          => $scope,
                    'featured_ids'   => [],
                    'trending_ids'   => $this->trending_template_ids(),
                    'guest_editing'  => $this->is_guest_editing_enabled(),
                    'guest_qp'       => $this->is_guest_quick_print_enabled(),
                ];
            }
        }
        if ($scope === 'popular') {
            $args['featured_ids'] = $this->trending_template_ids();
            if ($args['featured_ids'] === []) {
                return [
                    'items'          => [],
                    'has_more'       => false,
                    'page'           => $page,
                    'per_page'       => $per_page,
                    'scope'          => $scope,
                    'featured_ids'   => $this->featured_template_ids(),
                    'trending_ids'   => [],
                    'guest_editing'  => $this->is_guest_editing_enabled(),
                    'guest_qp'       => $this->is_guest_quick_print_enabled(),
                ];
            }
        }

        $cache_key = 'eko_sampa_pubcat_v2_' . md5(wp_json_encode($args));
        $cached    = get_transient($cache_key);
        if (is_string($cached) && $cached !== '') {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $tpl  = new Eko_Sampa_Template();
        $rows = $tpl->list_public_master_catalog($args);
        $more = is_array($rows) && count($rows) > $per_page;
        if ($more && is_array($rows)) {
            array_pop($rows);
        }
        $rows = is_array($rows) ? $rows : [];
        $out  = [
            'items'         => array_map([$this, 'map_public_catalog_row'], $rows),
            'has_more'      => $more,
            'page'          => $page,
            'per_page'      => $per_page,
            'scope'         => $scope,
            'featured_ids'  => $this->featured_template_ids(),
            'trending_ids'  => $this->trending_template_ids(),
            'guest_editing' => $this->is_guest_editing_enabled(),
            'guest_qp'      => $this->is_guest_quick_print_enabled(),
        ];

        set_transient($cache_key, wp_json_encode($out), 45);

        return $out;
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function build_public_categories_payload() {
        if (! $this->is_public_home_enabled()) {
            return new \WP_Error(
                'eko_sampa_public_disabled',
                __('Public catalog is not available.', 'eko-sampa'),
                ['status' => 404]
            );
        }

        $ck = 'eko_sampa_pubcats_v1';
        $cached = get_transient($ck);
        if (is_string($cached) && $cached !== '') {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $cats    = ( new Eko_Sampa_Template() )->list_public_master_categories();
        $payload = ['categories' => $cats];
        set_transient($ck, wp_json_encode($payload), 120);

        return $payload;
    }

    /**
     * @param array<string, mixed> $body Parsed JSON body (optional `reuse` with `template_id` + `session_token`).
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function fork_public_catalog_session(int $source_id, array $body = []) {
        $tpl = new Eko_Sampa_Template();
        $src = $tpl->get_row_by_id($source_id);
        if (! is_array($src) || ! Eko_Sampa_Template_Derivation::can_fork_public_session($src)) {
            return new \WP_Error(
                'eko_sampa_session_fork_failed',
                __('Could not start a personalization session for this template.', 'eko-sampa'),
                ['status' => 403, 'failure_reason' => 'fork_not_allowed']
            );
        }

        $server_row = Eko_Sampa_Public_Analytics::try_reuse_last_guest_session_for_master($source_id, $tpl);
        if (is_array($server_row)) {
            return $this->fork_reused_catalog_session_response($source_id, $server_row);
        }

        $reuse = isset( $body['reuse'] ) && is_array( $body['reuse'] ) ? $body['reuse'] : [];
        $reuse_tid = isset( $reuse['template_id'] ) ? absint( (int) $reuse['template_id'] ) : 0;
        $reuse_tok = isset( $reuse['session_token'] ) ? (string) $reuse['session_token'] : '';
        $reuse_tok = preg_replace( '/[^a-f0-9]/i', '', $reuse_tok );

        if ( $reuse_tid > 0 && $reuse_tok !== '' ) {
            $reuse_row = $tpl->try_reuse_guest_session_for_public_master( $source_id, $reuse_tid, $reuse_tok );
            if ( is_array( $reuse_row ) ) {
                return $this->fork_reused_catalog_session_response($source_id, $reuse_row);
            }
        }

        $guard = $this->guard_session_fork($source_id);
        if ($guard instanceof \WP_Error) {
            return $guard;
        }

        $try = $tpl->create_public_session_from_source($source_id);
        if (empty($try['success'])) {
            $reason = (string) ( $try['failure_reason'] ?? '' );
            $status = $reason === 'fork_not_allowed' ? 403 : 400;

            return new \WP_Error(
                'eko_sampa_session_fork_failed',
                __('Could not start a personalization session for this template.', 'eko-sampa'),
                ['status' => $status, 'failure_reason' => $reason]
            );
        }

        $new_id = (int) ( $try['id'] ?? 0 );
        $row    = $tpl->get_row_by_id($new_id);
        if (! is_array($row)) {
            return new \WP_Error(
                'eko_sampa_session_reload_failed',
                __('Session was created but could not be reloaded.', 'eko-sampa'),
                ['status' => 500]
            );
        }

        $token = (string) ( $row['session_token'] ?? '' );
        $out   = Eko_Sampa_Template_Thumbnail::enrich_row_for_public_catalog($row);
        unset($out['json_data']);
        $out['session_token'] = $token;
        $out['reused']        = false;
        $out['derivation']    = [
            'parent_template_id' => $source_id,
            'editor_query'       => [
                'template_id'   => $new_id,
                'session_token' => $token,
            ],
        ];

        do_action('eko_sampa_template_session_forked', $source_id, $new_id);
        if ( function_exists( 'eko_sampa_public_funnel_do' ) ) {
            eko_sampa_public_funnel_do(
                'session_forked',
                [
                    'master_id'  => $source_id,
                    'session_id' => $new_id,
                ]
            );
        }

        Eko_Sampa_Public_Analytics::remember_guest_session_for_client($source_id, $new_id, $token);

        return new \WP_REST_Response($out, 201);
    }

    /**
     * @param array<string, mixed> $reuse_row Session template row.
     */
    private function fork_reused_catalog_session_response(int $master_id, array $reuse_row): \WP_REST_Response {
        $new_id = (int) ( $reuse_row['id'] ?? 0 );
        $token  = (string) ( $reuse_row['session_token'] ?? '' );
        $out    = Eko_Sampa_Template_Thumbnail::enrich_row_for_public_catalog( $reuse_row );
        unset( $out['json_data'] );
        $out['session_token'] = $token;
        $out['reused']        = true;
        $out['derivation']    = [
            'parent_template_id' => $master_id,
            'editor_query'       => [
                'template_id'   => $new_id,
                'session_token' => $token,
            ],
        ];
        if ( function_exists( 'eko_sampa_public_funnel_do' ) ) {
            eko_sampa_public_funnel_do(
                'session_reused',
                [
                    'master_id'  => $master_id,
                    'session_id' => $new_id,
                ]
            );
        }
        Eko_Sampa_Public_Analytics::remember_guest_session_for_client($master_id, $new_id, $token);

        return new \WP_REST_Response( $out, 200 );
    }

    /**
     * @return array<string, mixed>
     */
    public function get_aggregated_stats(): array {
        global $wpdb;
        $t   = $wpdb->prefix . 'eko_sampa_templates';
        $j   = $wpdb->prefix . 'eko_sampa_quick_print_jobs';
        $sess = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$t}` WHERE template_type = %s AND user_id = %d",
                Eko_Sampa_Template_Derivation::TYPE_SESSION,
                0
            )
        );
        $qp_open = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$j}` WHERE user_id = %d AND status IN (%s, %s)",
                0,
                Eko_Sampa_Quick_Print_Job::STATUS_QUEUED,
                Eko_Sampa_Quick_Print_Job::STATUS_SENT_TO_BROWSER
            )
        );

        $top_fork = [];
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is prefixed, safe.
        $fork_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT parent_template_id AS pid, COUNT(*) AS c FROM `{$t}` WHERE template_type = %s AND user_id = %d AND parent_template_id > 0 GROUP BY parent_template_id ORDER BY c DESC LIMIT %d",
                Eko_Sampa_Template_Derivation::TYPE_SESSION,
                0,
                10
            ),
            ARRAY_A
        );
        if (is_array($fork_rows)) {
            foreach ($fork_rows as $fr) {
                $pid = isset($fr['pid']) ? absint((int) $fr['pid']) : 0;
                $cnt = isset($fr['c']) ? absint((int) $fr['c']) : 0;
                if ($pid > 0 && $cnt > 0) {
                    $top_fork[] = ['master_id' => $pid, 'guest_sessions_observed' => $cnt];
                }
            }
        }

        return [
            'metrics' => [
                'guest_sessions_created' => (int) get_option(Eko_Sampa_Public_Experience::OPT_METRIC_SESSIONS, 0),
                'persist_to_library'     => (int) get_option(Eko_Sampa_Public_Experience::OPT_METRIC_PERSIST, 0),
                'guest_quick_prints'     => (int) get_option(Eko_Sampa_Public_Experience::OPT_METRIC_QP, 0),
                'conversion_modal_opens' => (int) get_option(Eko_Sampa_Public_Experience::OPT_METRIC_MODAL_OPENS, 0),
                'recovery_banner_shown'  => (int) get_option(Eko_Sampa_Public_Experience::OPT_METRIC_RECOVERY_SHOWN, 0),
            ],
            'live'    => [
                'guest_session_rows'     => $sess,
                'guest_quick_print_open' => $qp_open,
            ],
            'limits'  => [
                'max_sessions_per_client' => max(1, min(50, (int) get_option(Eko_Sampa_Public_Experience::OPT_MAX_SESSIONS_IP, 5))),
                'max_guest_sessions_site' => max(200, min(100000, (int) get_option(Eko_Sampa_Public_Experience::OPT_MAX_GUEST_SESSIONS_GLOBAL, 2500))),
                'guest_qp_per_hour'       => max(5, min(200, (int) get_option(Eko_Sampa_Public_Experience::OPT_QP_MAX_PER_HOUR, 20))),
            ],
            'catalog' => [
                'top_masters_by_guest_fork' => $top_fork,
            ],
            'notes'   => [
                'heavy_analytics' => 'Deferred — counters and live SQL only.',
            ],
        ];
    }

    /**
     * Lightweight, nonce-gated telemetry from the public editor (conversion UX).
     *
     * @return true|\WP_Error
     */
    public function ingest_public_telemetry(string $event) {
        $event = sanitize_key($event);
        $allowed = ['conversion_modal_open', 'editor_boot'];
        if (! in_array($event, $allowed, true)) {
            return new \WP_Error('eko_sampa_invalid', __('Unknown event.', 'eko-sampa'), ['status' => 400]);
        }
        $dedupe = 'eko_sampa_pubtel_d_' . $this->request_client_key() . '_' . $event;
        if (get_transient($dedupe)) {
            return true;
        }
        set_transient($dedupe, '1', 8);
        $hour = 'eko_sampa_pubtel_h_' . $this->request_client_key();
        $n    = (int) get_transient($hour);
        if ($n >= 120) {
            return new \WP_Error('eko_sampa_rate_limited', __('Too many signals.', 'eko-sampa'), ['status' => 429]);
        }
        set_transient($hour, (string) ( $n + 1 ), HOUR_IN_SECONDS);
        if ($event === 'conversion_modal_open') {
            $this->bump_conversion_modal_metric();
        }
        if ($event === 'editor_boot') {
            $dedupe_eb = 'eko_sampa_pubtel_eb_' . $this->request_client_key();
            if (get_transient($dedupe_eb)) {
                return true;
            }
            set_transient($dedupe_eb, '1', HOUR_IN_SECONDS);
            Eko_Sampa_Public_Analytics::instance()->bump_editor_open();
        }

        return true;
    }

    /**
     * @return 'ok'|'not_found'|'not_session'|'token'|'expired'|'lifecycle'|'fingerprint'
     */
    public function guest_session_diagnostic(?array $row, string $token): string {
        $token = trim($token);
        if (! is_array($row)) {
            return 'not_found';
        }
        if (! Eko_Sampa_Template_Derivation::is_session_row($row)) {
            return 'not_session';
        }
        if (! Eko_Sampa_Template_Derivation::session_token_matches($row, $token)) {
            return 'token';
        }
        if (! Eko_Sampa_Template_Derivation::session_is_usable($row)) {
            return 'expired';
        }
        if (! Eko_Sampa_Template_Derivation::session_lifecycle_allows_api($row)) {
            return 'lifecycle';
        }
        if (! Eko_Sampa_Template_Derivation::session_fingerprint_matches($row)) {
            return 'fingerprint';
        }

        return 'ok';
    }

    public function maybe_output_public_home_seo(): void {
        if ((string) get_query_var(Eko_Sampa_Frontend_Router::QUERY_FLAG) !== '1') {
            return;
        }
        if (sanitize_key((string) get_query_var(Eko_Sampa_Frontend_Router::QUERY_VIEW)) !== 'public_home') {
            return;
        }
        if (! $this->is_public_home_enabled()) {
            return;
        }

        $title = __('Modelos Eko Sampa — personalize e imprima', 'eko-sampa');
        $desc  = __('Explore modelos oficiais, personalize no editor e imprima sem criar conta. Crie conta para guardar na sua biblioteca.', 'eko-sampa');
        $url   = esc_url(Eko_Sampa_Frontend_Router::get_url('public_home'));

        $cat = isset($_GET['categoria']) ? sanitize_text_field((string) wp_unslash((string) $_GET['categoria'])) : '';
        if ($cat !== '') {
            /* translators: %s: category label */
            $title = sprintf(__('Modelos %s — Eko Sampa', 'eko-sampa'), $cat);
            /* translators: %s: category label */
            $desc  = sprintf(__('Modelos oficiais na categoria %s. Personalize e imprima; crie conta para guardar na biblioteca.', 'eko-sampa'), $cat);
            $url   = esc_url(add_query_arg('categoria', rawurlencode($cat), Eko_Sampa_Frontend_Router::get_url('public_home')));
        }

        echo '<meta name="description" content="' . esc_attr($desc) . "\" />\n";
        echo '<meta property="og:type" content="website" />' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($title) . "\" />\n";
        echo '<meta property="og:description" content="' . esc_attr($desc) . "\" />\n";
        echo '<meta property="og:url" content="' . esc_attr(esc_url($url)) . "\" />\n";
        echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
        $json = [
            '@context' => 'https://schema.org',
            '@type'    => 'WebSite',
            'name'     => $title,
            'url'      => $url,
            'description' => $desc,
        ];
        echo '<script type="application/ld+json">' . wp_json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "</script>\n";
    }
}
