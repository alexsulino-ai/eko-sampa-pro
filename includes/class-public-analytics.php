<?php
/**
 * Centralized public product analytics: counters, light rollups, snapshots, rate limits.
 * No PII; no invasive tracking. Listeners only — does not own editor, REST contracts, or derivation math.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Aggregated metrics + popularity snapshots for public catalog scopes and admin insight.
 */
final class Eko_Sampa_Public_Analytics {

    public const OPTION_COUNTERS = 'eko_sampa_analytics_counters_v1';

    public const OPTION_ROLLUP = 'eko_sampa_analytics_tpl_rollup_v1';

    private const TRANSIENT_VIEW_QUEUE = 'eko_sampa_analytics_view_q_v1';

    private const TRANSIENT_POP_SNAPSHOT = 'eko_sampa_analytics_pop_snap_v1';

    private const TRANSIENT_FORK_SIG = 'eko_sampa_analytics_fork_sig_v1';

    private const CRON_HOOK = 'eko_sampa_analytics_hourly';

    private static ?self $instance = null;

    /** Weights: fork, save, print, order (recency applied in snapshot). */
    private const W_FORK = 1;

    private const W_SAVE = 3;

    private const W_PRINT = 2;

    private const W_ORDER = 5;

    private const ROLLUP_MAX_KEYS = 400;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function register_hooks(): void {
        add_action('init', [self::class, 'maybe_schedule_cron'], 30);
        add_action(self::CRON_HOOK, [self::class, 'run_hourly_tasks']);
        add_action('eko_sampa_template_session_forked', [self::class, 'on_template_forked'], 10, 2);
        add_action('eko_sampa_session_persisted_to_user_template', [self::class, 'on_session_persisted'], 10, 3);
        add_action('eko_sampa_order_created', [self::class, 'on_order_created'], 10, 2);
        add_action('eko_sampa_quick_print_job_created', [self::class, 'on_qp_created'], 10, 1);
        add_action('eko_sampa_quick_print_job_completed', [self::class, 'on_qp_completed'], 10, 1);
        add_action('eko_sampa_guest_qp_marked_abandoned', [self::class, 'on_qp_abandoned_bulk'], 10, 1);
        add_action('eko_sampa_guest_sessions_expired_cleanup', [self::class, 'on_guest_sessions_cleaned'], 10, 1);
        add_action('eko_sampa_editor_recovery_tracked', [self::class, 'on_editor_recovery'], 10, 0);
        add_action('user_register', [self::class, 'on_user_register'], 20, 1);
        add_filter('wp_resource_hints', [self::class, 'filter_resource_hints'], 10, 2);
    }

    public static function maybe_schedule_cron(): void {
        if (wp_next_scheduled(self::CRON_HOOK)) {
            return;
        }
        wp_schedule_event(time() + 300, 'hourly', self::CRON_HOOK);
    }

    public static function run_hourly_tasks(): void {
        self::instance()->flush_view_queue_to_rollup();
        self::instance()->rebuild_popularity_snapshot();
        self::instance()->gc_stale_transients();
    }

    /**
     * Remember last guest session for this client + master (server-side reuse across tabs).
     */
    public static function remember_guest_session_for_client(int $master_id, int $session_template_id, string $session_token): void {
        if ($master_id <= 0 || $session_template_id <= 0) {
            return;
        }
        $tok = preg_replace('/[^a-f0-9]/i', '', $session_token);
        if ($tok === '') {
            return;
        }
        $ck = Eko_Sampa_Public_Experience_Service::instance()->request_client_key();
        set_transient(
            'eko_sampa_gl_' . md5($ck . '|' . $master_id),
            ['tid' => $session_template_id, 'tok' => $tok],
            2 * DAY_IN_SECONDS
        );
    }

    /**
     * @return array<string, mixed>|null Session row if reusable.
     */
    public static function try_reuse_last_guest_session_for_master(int $master_id, Eko_Sampa_Template $tpl): ?array {
        if ($master_id <= 0) {
            return null;
        }
        $ck  = Eko_Sampa_Public_Experience_Service::instance()->request_client_key();
        $raw = get_transient('eko_sampa_gl_' . md5($ck . '|' . $master_id));
        if (! is_array($raw)) {
            return null;
        }
        $tid = isset($raw['tid']) ? absint((int) $raw['tid']) : 0;
        $tok = isset($raw['tok']) ? (string) $raw['tok'] : '';
        $tok = preg_replace('/[^a-f0-9]/i', '', $tok);
        if ($tid <= 0 || $tok === '') {
            return null;
        }

        return $tpl->try_reuse_guest_session_for_public_master($master_id, $tid, $tok);
    }

    /**
     * Queue template IDs from a catalog response (rate-limited per client).
     *
     * @param array<int> $master_ids
     */
    public function queue_catalog_views(array $master_ids): void {
        $master_ids = array_values(array_filter(array_map('absint', $master_ids), static fn (int $v): bool => $v > 0));
        if ($master_ids === []) {
            return;
        }
        $ck = Eko_Sampa_Public_Experience_Service::instance()->request_client_key();
        $rk = 'eko_sampa_anav_rl_' . $ck;
        $n  = (int) get_transient($rk);
        if ($n >= 80) {
            return;
        }
        set_transient($rk, (string) ( $n + 1 ), HOUR_IN_SECONDS);

        $q = get_transient(self::TRANSIENT_VIEW_QUEUE);
        if (! is_array($q)) {
            $q = [];
        }
        foreach ($master_ids as $id) {
            $q[] = $id;
        }
        if (count($q) > 2000) {
            $q = array_slice($q, -2000);
        }
        set_transient(self::TRANSIENT_VIEW_QUEUE, $q, 2 * HOUR_IN_SECONDS);
    }

    public function flush_view_queue_to_rollup(): void {
        $q = get_transient(self::TRANSIENT_VIEW_QUEUE);
        delete_transient(self::TRANSIENT_VIEW_QUEUE);
        if (! is_array($q) || $q === []) {
            return;
        }
        $counts = [];
        foreach ($q as $raw) {
            $id = absint((int) $raw);
            if ($id <= 0) {
                continue;
            }
            $counts[$id] = ( $counts[$id] ?? 0 ) + 1;
        }
        $sum = 0;
        foreach ($counts as $tid => $c) {
            $add = max(1, min(50, (int) $c));
            $this->bump_template_dim((int) $tid, 'vw', $add);
            $sum += $add;
        }
        if ($sum > 0) {
            $this->bump_global('template_view_count', $sum);
        }
    }

    /**
     * @return array<int, array{score: float, ts: int, id: int}>
     */
    public function rebuild_popularity_snapshot(): array {
        $rollup = $this->get_rollup();
        $now    = time();
        $out    = [];
        foreach ($rollup as $k => $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = absint((int) $k);
            if ($id <= 0) {
                continue;
            }
            $fk = (int) ( $row['fk'] ?? 0 );
            $sv = (int) ( $row['sv'] ?? 0 );
            $pr = (int) ( $row['pr'] ?? 0 );
            $or = (int) ( $row['or'] ?? 0 );
            $vw = (int) ( $row['vw'] ?? 0 );
            $ts = (int) ( $row['ts'] ?? 0 );
            $base = $fk * self::W_FORK + $sv * self::W_SAVE + $pr * self::W_PRINT + $or * self::W_ORDER + min(20, $vw) * 0.05;
            $age  = $ts > 0 ? max(0, $now - $ts ) : 86400 * 30;
            $decay = exp(- $age / ( 14 * DAY_IN_SECONDS ) );
            $score = $base * ( 0.35 + 0.65 * $decay );
            $out[] = ['id' => $id, 'score' => $score, 'ts' => $ts];
        }
        usort(
            $out,
            static function (array $a, array $b): int {
                return ( $b['score'] <=> $a['score'] ) ?: ( ( $b['ts'] ?? 0 ) <=> ( $a['ts'] ?? 0 ) );
            }
        );
        set_transient(self::TRANSIENT_POP_SNAPSHOT, $out, 2 * HOUR_IN_SECONDS);

        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get_popularity_snapshot(): array {
        $snap = get_transient(self::TRANSIENT_POP_SNAPSHOT);
        if (! is_array($snap) || $snap === []) {
            return $this->rebuild_popularity_snapshot();
        }

        return $snap;
    }

    /**
     * Ordered master IDs for catalog scopes (analytics-driven).
     *
     * @return array<int>
     */
    public function get_master_ids_for_scope(string $scope, int $limit): array {
        $limit = max(1, min(200, $limit));
        $snap  = $this->get_popularity_snapshot();
        $rollup = $this->get_rollup();
        $now   = time();
        $ids   = [];

        switch ($scope) {
            case 'trending_today':
                foreach ($snap as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    if ( (int) ( $row['ts'] ?? 0 ) >= $now - DAY_IN_SECONDS ) {
                        $ids[] = (int) ( $row['id'] ?? 0 );
                    }
                }
                break;
            case 'trending_week':
                foreach ($snap as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    if ( (int) ( $row['ts'] ?? 0 ) >= $now - 7 * DAY_IN_SECONDS ) {
                        $ids[] = (int) ( $row['id'] ?? 0 );
                    }
                }
                break;
            case 'most_saved':
                uasort(
                    $rollup,
                    static function ($a, $b) {
                        $as = is_array($a) ? (int) ( $a['sv'] ?? 0 ) : 0;
                        $bs = is_array($b) ? (int) ( $b['sv'] ?? 0 ) : 0;

                        return $bs <=> $as;
                    }
                );
                foreach (array_keys($rollup) as $k) {
                    $ids[] = absint((int) $k);
                }
                break;
            case 'recently_printed':
                $pairs = [];
                foreach ($rollup as $k => $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $lp = (int) ( $row['last_pr'] ?? 0 );
                    if ($lp > 0) {
                        $pairs[] = ['id' => absint((int) $k), 't' => $lp];
                    }
                }
                usort($pairs, static fn (array $a, array $b): int => ( $b['t'] <=> $a['t'] ));
                foreach ($pairs as $p) {
                    $ids[] = (int) ( $p['id'] ?? 0 );
                }
                break;
            default:
                foreach ($snap as $row) {
                    if (is_array($row) && (int) ( $row['id'] ?? 0 ) > 0) {
                        $ids[] = (int) $row['id'];
                    }
                }
        }

        $ids = array_values(array_unique(array_filter($ids, static fn (int $v): bool => $v > 0)));

        return array_slice($ids, 0, $limit);
    }

    /**
     * @return array<string, int|string|float>
     */
    public function get_global_counters(): array {
        $d = get_option(self::OPTION_COUNTERS, []);
        if (! is_array($d)) {
            $d = [];
        }
        $defaults = [
            'template_view_count'            => 0,
            'template_fork_count'            => 0,
            'template_save_count'            => 0,
            'template_print_count'           => 0,
            'template_order_count'           => 0,
            'guest_to_signup_count'          => 0,
            'guest_to_saved_template_count'  => 0,
            'guest_to_order_count'           => 0,
            'editor_open_count'              => 0,
            'editor_recovery_count'          => 0,
            'abandoned_session_count'        => 0,
            'qp_job_created_count'           => 0,
            'qp_job_completed_count'         => 0,
            'qp_job_abandoned_count'         => 0,
        ];
        foreach (array_keys($defaults) as $k) {
            if (isset($d[ $k ]) && is_numeric($d[ $k ])) {
                $defaults[ $k ] = (int) $d[ $k ];
            }
        }

        return $defaults;
    }

    public function bump_editor_open(): void {
        $this->bump_global('editor_open_count', 1);
    }

    /**
     * Operational + product snapshot for admin health UI / REST.
     *
     * @return array<string, mixed>
     */
    public function build_health_payload(): array {
        global $wpdb;
        $svc = Eko_Sampa_Public_Experience_Service::instance()->get_aggregated_stats();
        Eko_Sampa_Template_Derivation::publish_observability_snapshot();
        $deriv = get_option(Eko_Sampa_Template_Derivation::OPTION_OBSERVABILITY, []);
        if (! is_array($deriv)) {
            $deriv = [];
        }

        $jobs = $wpdb->prefix . 'eko_sampa_quick_print_jobs';
        $stuck = 0;
        if ( ( new Eko_Sampa_Quick_Print_Job() )->is_storage_ready() ) {
            $cut = gmdate('Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS);
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $stuck = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$jobs}` WHERE status IN (%s, %s) AND updated_at < %s",
                    Eko_Sampa_Quick_Print_Job::STATUS_QUEUED,
                    Eko_Sampa_Quick_Print_Job::STATUS_SENT_TO_BROWSER,
                    $cut
                )
            );
        }

        $qp_abandoned = ( new Eko_Sampa_Quick_Print_Job() )->is_storage_ready()
            ? (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$jobs}` WHERE user_id = %d AND status = %s",
                    0,
                    Eko_Sampa_Quick_Print_Job::STATUS_ABANDONED
                )
            )
            : 0;

        $next_cron = wp_next_scheduled(Eko_Sampa_Template_Derivation::CRON_HOOK);
        $cron_note = is_int($next_cron) && $next_cron > 0
            ? max(0, $next_cron - time())
            : null;

        $storage_est = strlen(wp_json_encode($this->get_rollup()))
            + strlen(wp_json_encode($this->get_global_counters()));

        return [
            'ts'                    => time(),
            'counters'              => $this->get_global_counters(),
            'public_experience'     => $svc,
            'derivation_snapshot'   => $deriv,
            'queues'                => [
                'guest_qp_stuck_older_than_30m' => $stuck,
            ],
            'guest_qp_abandoned_rows' => $qp_abandoned,
            'cron'                  => [
                'derivation_next_scheduled' => $next_cron,
                'derivation_seconds_until_next' => $cron_note,
                'analytics_next_scheduled'  => wp_next_scheduled(self::CRON_HOOK),
            ],
            'storage_bytes_estimate' => $storage_est,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function build_admin_business_dashboard(): array {
        $rollup = $this->get_rollup();
        $pairs  = [];
        foreach ($rollup as $k => $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = absint((int) $k);
            if ($id <= 0) {
                continue;
            }
            $score = (int) ( $row['fk'] ?? 0 ) + (int) ( $row['sv'] ?? 0 ) + (int) ( $row['pr'] ?? 0 ) + (int) ( $row['or'] ?? 0 );
            $pairs[] = [
                'master_id' => $id,
                'forks'     => (int) ( $row['fk'] ?? 0 ),
                'saves'     => (int) ( $row['sv'] ?? 0 ),
                'prints'    => (int) ( $row['pr'] ?? 0 ),
                'orders'    => (int) ( $row['or'] ?? 0 ),
                'views'     => (int) ( $row['vw'] ?? 0 ),
                'activity'  => $score,
            ];
        }
        usort($pairs, static fn (array $a, array $b): int => ( $b['activity'] <=> $a['activity'] ));
        $popular = array_slice($pairs, 0, 12);
        $dead    = array_slice(array_reverse($pairs), 0, 12);

        $g = $this->get_global_counters();
        $persist = (int) get_option(Eko_Sampa_Public_Experience::OPT_METRIC_PERSIST, 0);
        $forks   = (int) get_option(Eko_Sampa_Public_Experience::OPT_METRIC_SESSIONS, 0);
        $conv    = $forks > 0 ? round(100 * $persist / $forks, 1) : null;

        return [
            'popular_masters'     => $popular,
            'low_activity_masters' => $dead,
            'guest_fork_to_persist_pct' => $conv,
            'rollup_masters_tracked' => count($pairs),
            'counters'            => $g,
        ];
    }

    /**
     * @return array<int, array{code: string, message: string}>
     */
    public function build_admin_warnings(): array {
        $h    = $this->build_health_payload();
        $live = is_array($h['public_experience']['live'] ?? null) ? $h['public_experience']['live'] : [];
        $lim  = is_array($h['public_experience']['limits'] ?? null) ? $h['public_experience']['limits'] : [];
        $warn = [];

        $sess = (int) ( $live['guest_session_rows'] ?? 0 );
        $cap  = (int) ( $lim['max_guest_sessions_site'] ?? 0 );
        if ($cap > 0 && $sess > (int) floor($cap * 0.85 ) ) {
            $warn[] = [
                'code'    => 'guest_sessions_high',
                'message' => sprintf(
                    /* translators: 1: current guest session rows, 2: configured soft cap */
                    __('Guest session rows are high (%1$d / soft cap %2$d).', 'eko-sampa'),
                    $sess,
                    $cap
                ),
            ];
        }

        $stuck = (int) ( $h['queues']['guest_qp_stuck_older_than_30m'] ?? 0 );
        if ($stuck > 0) {
            $warn[] = [
                'code'    => 'quick_print_stuck',
                'message' => sprintf(
                    /* translators: %d: number of jobs */
                    __('Quick print: %d guest job(s) appear stuck in early states (>30m).', 'eko-sampa'),
                    $stuck
                ),
            ];
        }

        $cron_lag = $h['cron']['derivation_seconds_until_next'] ?? null;
        if ($cron_lag === null && empty($h['cron']['derivation_next_scheduled'])) {
            $warn[] = [
                'code'    => 'derivation_cron_unscheduled',
                'message' => __('Derivation cleanup cron is not scheduled — verify WP-Cron or a real cron hitting wp-cron.php.', 'eko-sampa'),
            ];
        }

        $near = (int) ( $h['derivation_snapshot']['sessions_near_expiry'] ?? 0 );
        if ($near > 200) {
            $warn[] = [
                'code'    => 'sessions_near_expiry_high',
                'message' => sprintf(
                    /* translators: %d: session rows */
                    __('Many guest sessions expire within the hour (%d rows) — expect churn or cleanup pressure.', 'eko-sampa'),
                    $near
                ),
            ];
        }

        return $warn;
    }

    /**
     * @param mixed $job_row
     */
    public static function on_qp_created($job_row): void {
        if (! is_array($job_row)) {
            return;
        }
        self::instance()->bump_global('qp_job_created_count', 1);
    }

    /**
     * @param mixed $job_row
     */
    public static function on_qp_completed($job_row): void {
        if (! is_array($job_row)) {
            return;
        }
        self::instance()->bump_global('qp_job_completed_count', 1);
        self::instance()->bump_global('template_print_count', 1);
        $tid = (int) ( $job_row['template_id'] ?? 0 );
        $mid = self::instance()->resolve_master_id_for_template($tid);
        if ($mid > 0) {
            self::instance()->bump_template_dim($mid, 'pr', 1);
        }
    }

    public static function on_qp_abandoned_bulk(int $n): void {
        if ($n <= 0) {
            return;
        }
        self::instance()->bump_global('qp_job_abandoned_count', $n);
    }

    public static function on_guest_sessions_cleaned(int $deleted): void {
        if ($deleted <= 0) {
            return;
        }
        self::instance()->bump_global('abandoned_session_count', $deleted);
    }

    public static function on_editor_recovery(): void {
        self::instance()->bump_global('editor_recovery_count', 1);
    }

    public static function on_user_register(int $user_id): void {
        unset($user_id);
        $ck = Eko_Sampa_Public_Experience_Service::instance()->request_client_key();
        if (! get_transient(self::TRANSIENT_FORK_SIG . $ck)) {
            return;
        }
        self::instance()->bump_global('guest_to_signup_count', 1);
    }

    public static function on_template_forked(int $source_id, int $new_id): void {
        unset($new_id);
        $ck = Eko_Sampa_Public_Experience_Service::instance()->request_client_key();
        set_transient(self::TRANSIENT_FORK_SIG . $ck, '1', DAY_IN_SECONDS);
        $s = self::instance();
        $s->bump_global('template_fork_count', 1);
        if ($source_id > 0) {
            $s->bump_template_dim($source_id, 'fk', 1);
        }
    }

    public static function on_session_persisted(int $session_id, int $new_user_template_id, int $parent_id): void {
        unset($session_id, $new_user_template_id);
        $s = self::instance();
        $s->bump_global('guest_to_saved_template_count', 1);
        $s->bump_global('template_save_count', 1);
        if ($parent_id > 0) {
            $s->bump_template_dim($parent_id, 'sv', 1);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function on_order_created(int $order_id, array $context): void {
        unset($order_id);
        $s = self::instance();
        $s->bump_global('template_order_count', 1);
        if (! empty($context['guest_template_flow'] )) {
            $s->bump_global('guest_to_order_count', 1);
        }
        $tid = isset($context['template_id']) ? absint((int) $context['template_id']) : 0;
        $mid = $s->resolve_master_id_for_template($tid);
        if ($mid > 0) {
            $s->bump_template_dim($mid, 'or', 1);
        }
    }

    /**
     * @param array<int, mixed> $hints
     * @param string              $relation_type
     *
     * @return array<int, mixed>
     */
    public static function filter_resource_hints($hints, $relation_type): array {
        if ($relation_type !== 'dns-prefetch' || ! is_array($hints)) {
            return $hints;
        }
        if (! Eko_Sampa_Public_Experience_Service::instance()->is_public_home_enabled()) {
            return $hints;
        }
        if (! is_string(get_query_var(Eko_Sampa_Frontend_Router::QUERY_FLAG))
            || (string) get_query_var(Eko_Sampa_Frontend_Router::QUERY_FLAG) !== '1') {
            return $hints;
        }
        if (sanitize_key((string) get_query_var(Eko_Sampa_Frontend_Router::QUERY_VIEW)) !== 'public_home') {
            return $hints;
        }
        $host = wp_parse_url((string) rest_url(), PHP_URL_HOST);
        if (is_string($host) && $host !== '' && ! in_array($host, $hints, true)) {
            $hints[] = $host;
        }

        return $hints;
    }

    private function gc_stale_transients(): void {
        delete_expired_transients();
    }

    private function bump_global(string $key, int $delta): bool {
        if ($delta <= 0) {
            return true;
        }
        $d = get_option(self::OPTION_COUNTERS, []);
        if (! is_array($d)) {
            $d = [];
        }
        $d[ $key ] = (int) ( $d[ $key ] ?? 0 ) + $delta;
        update_option(self::OPTION_COUNTERS, $d, false);

        return true;
    }

    private function bump_template_dim(int $master_id, string $dim, int $delta): void {
        if ($master_id <= 0 || $delta <= 0) {
            return;
        }
        $allowed = ['fk', 'sv', 'pr', 'or', 'vw'];
        if (! in_array($dim, $allowed, true)) {
            return;
        }
        $map = $this->get_rollup();
        $k   = (string) $master_id;
        if (! isset($map[ $k ]) || ! is_array($map[ $k ])) {
            $map[ $k ] = ['fk' => 0, 'sv' => 0, 'pr' => 0, 'or' => 0, 'vw' => 0, 'ts' => 0, 'last_pr' => 0];
        }
        $map[ $k ][ $dim ] = (int) ( $map[ $k ][ $dim ] ?? 0 ) + $delta;
        $map[ $k ]['ts']   = time();
        if ($dim === 'pr') {
            $map[ $k ]['last_pr'] = time();
        }
        if (count($map) > self::ROLLUP_MAX_KEYS) {
            uasort(
                $map,
                static function ($a, $b) {
                    $at = is_array($a) ? (int) ( $a['ts'] ?? 0 ) : 0;
                    $bt = is_array($b) ? (int) ( $b['ts'] ?? 0 ) : 0;

                    return $at <=> $bt;
                }
            );
            $map = array_slice($map, -300, 300, true);
        }
        update_option(self::OPTION_ROLLUP, $map, false);
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function get_rollup(): array {
        $m = get_option(self::OPTION_ROLLUP, []);
        if (! is_array($m)) {
            return [];
        }

        return $m;
    }

    private function resolve_master_id_for_template(int $template_id): int {
        if ($template_id <= 0) {
            return 0;
        }
        $row = ( new Eko_Sampa_Template() )->get_row_by_id($template_id);
        if (! is_array($row)) {
            return 0;
        }
        if ( Eko_Sampa_Template_Derivation::is_master_row($row)) {
            return $template_id;
        }
        if ( Eko_Sampa_Template_Derivation::is_session_row($row)) {
            $p = (int) ( $row['parent_template_id'] ?? 0 );

            return $p > 0 ? $p : 0;
        }
        $p = (int) ( $row['parent_template_id'] ?? 0 );

        return $p > 0 ? $p : 0;
    }
}
