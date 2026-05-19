<?php
/**
 * Eko Sampa → Users — detail + quick actions.
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

$uid = isset($_GET['user_id']) ? absint((int) $_GET['user_id']) : 0;
if ($uid <= 0 || ! current_user_can('edit_user', $uid)) {
    wp_die(esc_html__('Invalid user.', 'eko-sampa'));
}

$d   = Eko_Sampa_User_Admin::build_detail_bundle($uid);
if ($d === []) {
    wp_die(esc_html__('User not found.', 'eko-sampa'));
}
$back  = admin_url('admin.php?page=' . Eko_Sampa_User_Admin::MENU_SLUG);

/**
 * @param string               $action
 * @param array<string, mixed> $d
 */
$action_form = static function (string $action, array $d, string $label): void {
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="display:inline-block;margin-right:6px;">
        <?php wp_nonce_field('eko_sampa_users', 'eko_sampa_users_nonce'); ?>
        <input type="hidden" name="eko_sampa_users_action" value="<?php echo esc_attr($action); ?>" />
        <input type="hidden" name="user_id" value="<?php echo esc_attr((string) (int) ( $d['id'] ?? 0 )); ?>" />
        <button type="submit" class="button"><?php echo esc_html($label); ?></button>
    </form>
    <?php
};

?>
<div class="wrap">
    <h1><?php echo esc_html(sprintf(/* translators: %s: user name */ __('User: %s', 'eko-sampa'), (string) ( $d['display_name'] ?? '' ))); ?></h1>
    <p><a href="<?php echo esc_url($back); ?>">← <?php echo esc_html__('Back to list', 'eko-sampa'); ?></a>
        · <a href="<?php echo esc_url(get_edit_user_link($uid)); ?>"><?php echo esc_html__('WordPress profile', 'eko-sampa'); ?></a></p>

    <?php if (! empty($_GET['updated'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Saved.', 'eko-sampa'); ?></p></div>
    <?php endif; ?>

    <h2 class="title"><?php echo esc_html__('Operational', 'eko-sampa'); ?></h2>
    <table class="widefat striped" style="max-width:720px">
        <tbody>
            <tr><th><?php echo esc_html__('Email', 'eko-sampa'); ?></th><td><?php echo esc_html((string) ( $d['user_email'] ?? '' )); ?></td></tr>
            <tr><th><?php echo esc_html__('Eko role', 'eko-sampa'); ?></th><td><code><?php echo esc_html((string) ( $d['eko_role'] ?? '' )); ?></code></td></tr>
            <tr><th><?php echo esc_html__('Status', 'eko-sampa'); ?></th><td><code><?php echo esc_html((string) ( $d['status'] ?? '' )); ?></code></td></tr>
            <tr><th><?php echo esc_html__('Last WP session activity', 'eko-sampa'); ?></th><td><?php echo esc_html((string) ( $d['last_login'] ?? '—' )); ?></td></tr>
            <tr><th><?php echo esc_html__('Active WP sessions', 'eko-sampa'); ?></th><td><?php echo esc_html((string) (int) ( $d['active_sessions'] ?? 0 )); ?></td></tr>
            <tr><th><?php echo esc_html__('Open quick print jobs', 'eko-sampa'); ?></th><td><?php echo esc_html((string) (int) ( $d['quick_prints_open'] ?? 0 )); ?></td></tr>
        </tbody>
    </table>

    <h2 class="title"><?php echo esc_html__('Usage vs quotas', 'eko-sampa'); ?></h2>
    <?php
    $u = $d['usage'] ?? [];
    $q = $d['quotas'] ?? [];
    ?>
    <table class="widefat striped" style="max-width:720px">
        <thead><tr><th><?php echo esc_html__('Metric', 'eko-sampa'); ?></th><th><?php echo esc_html__('Used', 'eko-sampa'); ?></th><th><?php echo esc_html__('Max', 'eko-sampa'); ?></th></tr></thead>
        <tbody>
            <tr><td><?php echo esc_html__('Templates', 'eko-sampa'); ?></td><td><?php echo esc_html((string) (int) ( $u['templates_saved'] ?? 0 )); ?></td><td><?php echo esc_html((string) (int) ( $q['max_templates'] ?? 0 )); ?></td></tr>
            <tr><td><?php echo esc_html__('Clients', 'eko-sampa'); ?></td><td><?php echo esc_html((string) (int) ( $u['clients'] ?? 0 )); ?></td><td><?php echo esc_html((string) (int) ( $q['max_clients'] ?? 0 )); ?></td></tr>
            <tr><td><?php echo esc_html__('Orders', 'eko-sampa'); ?></td><td><?php echo esc_html((string) (int) ( $u['orders'] ?? 0 )); ?></td><td><?php echo esc_html((string) (int) ( $q['max_orders'] ?? 0 )); ?></td></tr>
            <tr><td><?php echo esc_html__('Template JSON (bytes est.)', 'eko-sampa'); ?></td><td><?php echo esc_html((string) (int) ( $u['payload_bytes'] ?? 0 )); ?></td><td><?php echo esc_html((string) ( (int) ( $q['max_storage_mb'] ?? 0 ) * 1024 * 1024 )); ?></td></tr>
            <tr><td><?php echo esc_html__('Quick prints / hour (cap)', 'eko-sampa'); ?></td><td>—</td><td><?php echo esc_html((string) (int) ( $q['max_qp_per_hour'] ?? 0 )); ?></td></tr>
        </tbody>
    </table>

    <h2 class="title"><?php echo esc_html__('Quick actions', 'eko-sampa'); ?></h2>
    <p class="description"><?php echo esc_html__('Clearing quick print jobs marks open jobs as cancelled (operational reset). Impersonation is a hook stub only.', 'eko-sampa'); ?></p>
    <p>
        <?php $action_form('activate', $d, __('Set active', 'eko-sampa')); ?>
        <?php $action_form('pending', $d, __('Set pending', 'eko-sampa')); ?>
        <?php $action_form('pause', $d, __('Pause (read-only)', 'eko-sampa')); ?>
        <?php $action_form('block', $d, __('Block', 'eko-sampa')); ?>
        <?php $action_form('clear_qp', $d, __('Clear quick print jobs', 'eko-sampa')); ?>
        <?php if (Eko_Sampa_Capabilities::can_manage_eko_quotas()) : ?>
            <?php $action_form('reset_quotas', $d, __('Reset quota overrides', 'eko-sampa')); ?>
        <?php endif; ?>
        <?php $action_form('impersonate_stub', $d, __('Impersonate (hook stub)', 'eko-sampa')); ?>
    </p>

    <h2 class="title"><?php echo esc_html__('Activity (lightweight)', 'eko-sampa'); ?></h2>
    <p class="description"><?php echo esc_html__('Deep per-user timelines and guest conversion counts can be attached via hooks and future analytics jobs — not computed on every page load.', 'eko-sampa'); ?></p>
    <?php
    do_action('eko_sampa_user_admin_detail_panel', $uid, $d);
    ?>
</div>
