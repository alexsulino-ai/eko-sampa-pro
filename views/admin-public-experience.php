<?php
/**
 * Admin: public home + guest limits (options group `eko_sampa_public_experience`).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

?>
<div class="wrap">
    <h1><?php echo esc_html__('Public home & guest experience', 'eko-sampa'); ?></h1>
    <p class="description">
        <?php echo esc_html__('Control the public catalog at /eko-sampa/, guest session forks, and guest quick print limits. Metrics are counters (read-only here).', 'eko-sampa'); ?>
    </p>

    <form method="post" action="options.php" class="eko-sampa-admin-form" style="max-width: 48rem; margin-top: 1.5rem;">
        <?php
        settings_fields('eko_sampa_public_experience');
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php echo esc_html__('Public home enabled', 'eko-sampa'); ?></th>
                <td>
                    <select name="<?php echo esc_attr(Eko_Sampa_Public_Experience::OPT_HOME_ENABLED); ?>">
                        <option value="1" <?php selected((bool) get_option(Eko_Sampa_Public_Experience::OPT_HOME_ENABLED, 1)); ?>><?php echo esc_html__('Yes', 'eko-sampa'); ?></option>
                        <option value="0" <?php selected(! (bool) get_option(Eko_Sampa_Public_Experience::OPT_HOME_ENABLED, 1)); ?>><?php echo esc_html__('No', 'eko-sampa'); ?></option>
                    </select>
                    <p class="description"><?php echo esc_html__('Public URL:', 'eko-sampa'); ?> <code><?php echo esc_html(Eko_Sampa_Frontend_Router::get_url('public_home')); ?></code></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Guest editing', 'eko-sampa'); ?></th>
                <td>
                    <select name="<?php echo esc_attr(Eko_Sampa_Public_Experience::OPT_GUEST_EDIT_ENABLED); ?>">
                        <option value="1" <?php selected((bool) get_option(Eko_Sampa_Public_Experience::OPT_GUEST_EDIT_ENABLED, 1)); ?>><?php echo esc_html__('Yes', 'eko-sampa'); ?></option>
                        <option value="0" <?php selected(! (bool) get_option(Eko_Sampa_Public_Experience::OPT_GUEST_EDIT_ENABLED, 1)); ?>><?php echo esc_html__('No', 'eko-sampa'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Guest quick print', 'eko-sampa'); ?></th>
                <td>
                    <select name="<?php echo esc_attr(Eko_Sampa_Public_Experience::OPT_GUEST_QP_ENABLED); ?>">
                        <option value="1" <?php selected((bool) get_option(Eko_Sampa_Public_Experience::OPT_GUEST_QP_ENABLED, 1)); ?>><?php echo esc_html__('Yes', 'eko-sampa'); ?></option>
                        <option value="0" <?php selected(! (bool) get_option(Eko_Sampa_Public_Experience::OPT_GUEST_QP_ENABLED, 1)); ?>><?php echo esc_html__('No', 'eko-sampa'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Guest download / export', 'eko-sampa'); ?></th>
                <td>
                    <select name="<?php echo esc_attr(Eko_Sampa_Public_Experience::OPT_ALLOW_DOWNLOAD); ?>">
                        <option value="1" <?php selected((bool) get_option(Eko_Sampa_Public_Experience::OPT_ALLOW_DOWNLOAD, 0)); ?>><?php echo esc_html__('Yes', 'eko-sampa'); ?></option>
                        <option value="0" <?php selected(! (bool) get_option(Eko_Sampa_Public_Experience::OPT_ALLOW_DOWNLOAD, 0)); ?>><?php echo esc_html__('No', 'eko-sampa'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="eko_pub_max_sess"><?php echo esc_html__('Max guest sessions (client key)', 'eko-sampa'); ?></label></th>
                <td><input name="<?php echo esc_attr(Eko_Sampa_Public_Experience::OPT_MAX_SESSIONS_IP); ?>" id="eko_pub_max_sess" type="number" min="1" max="50" value="<?php echo esc_attr((string) (int) get_option(Eko_Sampa_Public_Experience::OPT_MAX_SESSIONS_IP, 5)); ?>" class="small-text" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="eko_pub_qp_hour"><?php echo esc_html__('Guest quick prints / hour', 'eko-sampa'); ?></label></th>
                <td><input name="<?php echo esc_attr(Eko_Sampa_Public_Experience::OPT_QP_MAX_PER_HOUR); ?>" id="eko_pub_qp_hour" type="number" min="5" max="200" value="<?php echo esc_attr((string) (int) get_option(Eko_Sampa_Public_Experience::OPT_QP_MAX_PER_HOUR, 20)); ?>" class="small-text" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="eko_pub_qp_conc"><?php echo esc_html__('Guest concurrent print jobs', 'eko-sampa'); ?></label></th>
                <td><input name="<?php echo esc_attr(Eko_Sampa_Public_Experience::OPT_QP_MAX_CONCURRENT); ?>" id="eko_pub_qp_conc" type="number" min="1" max="10" value="<?php echo esc_attr((string) (int) get_option(Eko_Sampa_Public_Experience::OPT_QP_MAX_CONCURRENT, 2)); ?>" class="small-text" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="eko_pub_fork_deb"><?php echo esc_html__('Fork debounce (seconds)', 'eko-sampa'); ?></label></th>
                <td><input name="<?php echo esc_attr(Eko_Sampa_Public_Experience::OPT_FORK_DEBOUNCE_SEC); ?>" id="eko_pub_fork_deb" type="number" min="5" max="120" value="<?php echo esc_attr((string) (int) get_option(Eko_Sampa_Public_Experience::OPT_FORK_DEBOUNCE_SEC, 25)); ?>" class="small-text" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="eko_pub_fork_hour"><?php echo esc_html__('Forks / hour (client key)', 'eko-sampa'); ?></label></th>
                <td><input name="<?php echo esc_attr(Eko_Sampa_Public_Experience::OPT_FORK_MAX_PER_HOUR); ?>" id="eko_pub_fork_hour" type="number" min="3" max="120" value="<?php echo esc_attr((string) (int) get_option(Eko_Sampa_Public_Experience::OPT_FORK_MAX_PER_HOUR, 15)); ?>" class="small-text" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="eko_pub_feat"><?php echo esc_html__('Featured template IDs', 'eko-sampa'); ?></label></th>
                <td><input name="<?php echo esc_attr(Eko_Sampa_Public_Experience::OPT_FEATURED_IDS); ?>" id="eko_pub_feat" type="text" class="large-text" value="<?php echo esc_attr((string) get_option(Eko_Sampa_Public_Experience::OPT_FEATURED_IDS, '')); ?>" placeholder="12, 34, 56" />
                    <p class="description"><?php echo esc_html__('Comma-separated master IDs (must be is_public_catalog).', 'eko-sampa'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="eko_pub_trend"><?php echo esc_html__('Trending / popular IDs', 'eko-sampa'); ?></label></th>
                <td><input name="<?php echo esc_attr(Eko_Sampa_Public_Experience::OPT_TRENDING_IDS); ?>" id="eko_pub_trend" type="text" class="large-text" value="<?php echo esc_attr((string) get_option(Eko_Sampa_Public_Experience::OPT_TRENDING_IDS, '')); ?>" placeholder="12, 34, 56" />
                    <p class="description"><?php echo esc_html__('Manual boost list for the “Populares” tab (same rules as featured).', 'eko-sampa'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="eko_pub_max_global"><?php echo esc_html__('Max guest sessions (site-wide soft cap)', 'eko-sampa'); ?></label></th>
                <td><input name="<?php echo esc_attr(Eko_Sampa_Public_Experience::OPT_MAX_GUEST_SESSIONS_GLOBAL); ?>" id="eko_pub_max_global" type="number" min="200" max="100000" value="<?php echo esc_attr((string) (int) get_option(Eko_Sampa_Public_Experience::OPT_MAX_GUEST_SESSIONS_GLOBAL, 2500)); ?>" class="small-text" />
                    <p class="description"><?php echo esc_html__('Oldest anonymous session rows are pruned when above this ceiling (masters and user templates are never deleted here).', 'eko-sampa'); ?></p>
                </td>
            </tr>
        </table>
        <?php
        $stats = Eko_Sampa_Public_Experience_Service::instance()->get_aggregated_stats();
        $m     = is_array($stats['metrics'] ?? null) ? $stats['metrics'] : [];
        $live  = is_array($stats['live'] ?? null) ? $stats['live'] : [];
        $lim   = is_array($stats['limits'] ?? null) ? $stats['limits'] : [];
        $cat   = is_array($stats['catalog'] ?? null) ? $stats['catalog'] : [];
        $top   = is_array($cat['top_masters_by_guest_fork'] ?? null) ? $cat['top_masters_by_guest_fork'] : [];
        ?>
        <?php
        $warn = Eko_Sampa_Public_Analytics::instance()->build_admin_warnings();
        $dash = Eko_Sampa_Public_Analytics::instance()->build_admin_business_dashboard();
        $ctr  = is_array($dash['counters'] ?? null) ? $dash['counters'] : [];
        ?>
        <?php if ($warn !== []) : ?>
            <div class="notice notice-warning" style="max-width:48rem;">
                <p><strong><?php echo esc_html__('Operational warnings', 'eko-sampa'); ?></strong></p>
                <ul style="list-style:disc;margin-left:1.25rem;">
                    <?php foreach ($warn as $w) : ?>
                        <li><?php echo esc_html(is_array($w) ? (string) ( $w['message'] ?? '' ) : ''); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div style="max-width:48rem;margin-top:1.5rem;">
            <h2><?php echo esc_html__('Product analytics (aggregated)', 'eko-sampa'); ?></h2>
            <p class="description"><?php echo esc_html__('Counters roll up hourly; catalog “views” are sampled and rate-limited. No personal data stored.', 'eko-sampa'); ?></p>
            <table class="widefat striped" style="max-width:40rem;">
                <tbody>
                    <?php foreach ($ctr as $k => $v) : ?>
                        <tr>
                            <td><code><?php echo esc_html((string) $k); ?></code></td>
                            <td><?php echo esc_html((string) (int) $v); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php
            $pop = is_array($dash['popular_masters'] ?? null) ? $dash['popular_masters'] : [];
            ?>
            <?php if ($pop !== []) : ?>
                <h3 style="margin-top:1rem;"><?php echo esc_html__('Templates with activity (rollup)', 'eko-sampa'); ?></h3>
                <ol style="list-style:decimal;margin-left:1.25rem;">
                    <?php foreach (array_slice($pop, 0, 8) as $row) : ?>
                        <li><?php echo esc_html__('Master', 'eko-sampa'); ?> <?php echo esc_html((string) (int) ( $row['master_id'] ?? 0 )); ?>
                            — <?php echo esc_html((string) (int) ( $row['activity'] ?? 0 )); ?></li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
            <?php if (isset($dash['guest_fork_to_persist_pct']) && $dash['guest_fork_to_persist_pct'] !== null) : ?>
                <p><strong><?php echo esc_html__('Guest fork → persist (rough %)', 'eko-sampa'); ?></strong>
                    <?php echo esc_html((string) $dash['guest_fork_to_persist_pct']); ?>%</p>
            <?php endif; ?>
            <p class="description"><?php echo esc_html__('REST health:', 'eko-sampa'); ?>
                <code>GET <?php echo esc_html(rest_url('eko-sampa/v1/internals/health')); ?></code>
                (<?php echo esc_html__('admin only', 'eko-sampa'); ?>)</p>
        </div>

        <p><strong><?php echo esc_html__('Snapshot (read-only)', 'eko-sampa'); ?></strong></p>
        <ul style="list-style: disc; margin-left: 1.25rem;">
            <li><?php echo esc_html__('Live guest session rows:', 'eko-sampa'); ?> <?php echo esc_html((string) (int) ( $live['guest_session_rows'] ?? 0 )); ?></li>
            <li><?php echo esc_html__('Open guest quick-print jobs:', 'eko-sampa'); ?> <?php echo esc_html((string) (int) ( $live['guest_quick_print_open'] ?? 0 )); ?></li>
            <li><?php echo esc_html__('Counters — sessions / persist / QP / modal / recovery:', 'eko-sampa'); ?>
                <?php echo esc_html(implode(' / ', [(string) (int) ( $m['guest_sessions_created'] ?? 0 ), (string) (int) ( $m['persist_to_library'] ?? 0 ), (string) (int) ( $m['guest_quick_prints'] ?? 0 ), (string) (int) ( $m['conversion_modal_opens'] ?? 0 ), (string) (int) ( $m['recovery_banner_shown'] ?? 0 )])); ?>
            </li>
            <li><?php echo esc_html__('Configured limits — per client / site / QP hour:', 'eko-sampa'); ?>
                <?php echo esc_html(implode(' / ', [(string) (int) ( $lim['max_sessions_per_client'] ?? 0 ), (string) (int) ( $lim['max_guest_sessions_site'] ?? 0 ), (string) (int) ( $lim['guest_qp_per_hour'] ?? 0 )])); ?>
            </li>
        </ul>
        <?php if ($top !== []) : ?>
            <p><strong><?php echo esc_html__('Masters most forked into guest sessions (observed)', 'eko-sampa'); ?></strong></p>
            <ol style="list-style: decimal; margin-left: 1.25rem;">
                <?php foreach ($top as $row) : ?>
                    <li><?php echo esc_html__('Master ID', 'eko-sampa'); ?> <?php echo esc_html((string) (int) ( $row['master_id'] ?? 0 )); ?> — <?php echo esc_html((string) (int) ( $row['guest_sessions_observed'] ?? 0 )); ?></li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
        <?php submit_button(__('Save changes', 'eko-sampa')); ?>
    </form>
</div>
