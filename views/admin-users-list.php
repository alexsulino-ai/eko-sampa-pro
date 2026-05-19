<?php
/**
 * Eko Sampa → Users — list.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

if (! Eko_Sampa_Capabilities::can_manage_eko_users_screen()) {
    wp_die(esc_html__('You do not have permission to access this page.', 'eko-sampa'));
}

$paged    = isset($_GET['paged']) ? max(1, absint((int) $_GET['paged'])) : 1;
$page     = Eko_Sampa_User_Admin::query_users_page($paged, 30);
$rows     = $page['rows'];
$total    = (int) $page['total'];
$per_page = 30;
$max_page = max(1, (int) ceil($total / $per_page));
$analytics = Eko_Sampa_User_Admin::analytics_slice();

?>
<div class="wrap">
    <h1><?php echo esc_html__('Eko Sampa — Users', 'eko-sampa'); ?></h1>

    <?php if (! empty($_GET['updated'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('User updated.', 'eko-sampa'); ?></p></div>
    <?php endif; ?>

    <?php if (Eko_Sampa_Capabilities::can_view_eko_analytics_admin()) : ?>
        <p class="description">
            <?php
            echo esc_html(
                sprintf(
                    /* translators: 1: sample size, 2: blocked count, 3: near-quota count */
                    __('Sample (recent %1$d users): %2$d blocked, %3$d at ≥85%% template quota.', 'eko-sampa'),
                    (int) ( $analytics['sample_size'] ?? 0 ),
                    (int) ( $analytics['blocked_in_sample'] ?? 0 ),
                    (int) ( $analytics['near_template_quota'] ?? 0 )
                )
            );
            ?>
        </p>
    <?php endif; ?>

    <p>
        <a class="button" href="<?php echo esc_url(admin_url('users.php')); ?>"><?php echo esc_html__('WordPress Users (classic)', 'eko-sampa'); ?></a>
    </p>

    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php echo esc_html__('Name', 'eko-sampa'); ?></th>
                <th><?php echo esc_html__('Email', 'eko-sampa'); ?></th>
                <th><?php echo esc_html__('Eko role', 'eko-sampa'); ?></th>
                <th><?php echo esc_html__('Status', 'eko-sampa'); ?></th>
                <th><?php echo esc_html__('Templates', 'eko-sampa'); ?></th>
                <th><?php echo esc_html__('Clients', 'eko-sampa'); ?></th>
                <th><?php echo esc_html__('Orders', 'eko-sampa'); ?></th>
                <th><?php echo esc_html__('Storage (KB est.)', 'eko-sampa'); ?></th>
                <th><?php echo esc_html__('Registered', 'eko-sampa'); ?></th>
                <th><?php echo esc_html__('Actions', 'eko-sampa'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r) : ?>
                <tr>
                    <td><strong><?php echo esc_html((string) ( $r['display_name'] ?? '' )); ?></strong></td>
                    <td><?php echo esc_html((string) ( $r['user_email'] ?? '' )); ?></td>
                    <td><code><?php echo esc_html((string) ( $r['eko_role'] ?? '' )); ?></code></td>
                    <td><code><?php echo esc_html((string) ( $r['status'] ?? '' )); ?></code></td>
                    <td><?php echo esc_html((string) ( $r['templates'] ?? '' )); ?></td>
                    <td><?php echo esc_html((string) ( $r['clients'] ?? '' )); ?></td>
                    <td><?php echo esc_html((string) ( $r['orders'] ?? '' )); ?></td>
                    <td><?php echo esc_html((string) ( $r['storage_kb'] ?? '0' )); ?></td>
                    <td><?php echo esc_html(mysql2date(get_option('date_format', 'Y-m-d'), (string) ( $r['registered'] ?? '' ), true)); ?></td>
                    <td>
                        <?php if (current_user_can('edit_user', (int) ( $r['id'] ?? 0 ))) : ?>
                            <a href="<?php echo esc_url(add_query_arg(['page' => Eko_Sampa_User_Admin::MENU_SLUG, 'user_id' => (int) ( $r['id'] ?? 0 )], admin_url('admin.php'))); ?>"><?php echo esc_html__('Detail', 'eko-sampa'); ?></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows === []) : ?>
                <tr><td colspan="10"><?php echo esc_html__('No users found.', 'eko-sampa'); ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ($max_page > 1) : ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <?php
                echo wp_kses_post(
                    paginate_links(
                        [
                            'base'    => add_query_arg('paged', '%#%', admin_url('admin.php?page=' . Eko_Sampa_User_Admin::MENU_SLUG)),
                            'format'  => '',
                            'current' => $paged,
                            'total'   => $max_page,
                        ]
                    ) ?? ''
                );
                ?>
            </div>
        </div>
    <?php endif; ?>
</div>
