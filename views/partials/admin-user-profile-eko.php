<?php
/**
 * WP user profile — Eko Sampa section (read-heavy; quotas editable only with manage_eko_quotas).
 *
 * Expected variables: $user (WP_User), $status, $quotas, $usage, $eko_role (string).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$uid        = (int) $user->ID;
$can_edit   = Eko_Sampa_Capabilities::can_manage_eko_users_screen() && current_user_can('edit_user', $uid);
$can_quotas = Eko_Sampa_Capabilities::can_manage_eko_quotas() && $can_edit;
$role_label = $eko_role !== '' ? $eko_role : __('(no Eko role)', 'eko-sampa');

?>
<h2 id="eko-sampa-profile"><?php echo esc_html__('Eko Sampa', 'eko-sampa'); ?></h2>
<table class="form-table" role="presentation">
    <tr>
        <th scope="row"><?php echo esc_html__('Eko role', 'eko-sampa'); ?></th>
        <td><code><?php echo esc_html($role_label); ?></code></td>
    </tr>
    <tr>
        <th scope="row"><label for="eko_sampa_user_status"><?php echo esc_html__('Operational status', 'eko-sampa'); ?></label></th>
        <td>
            <?php if ($can_edit) : ?>
                <select name="eko_sampa_user_status" id="eko_sampa_user_status">
                    <?php
                    $opts = [
                        Eko_Sampa_Capabilities::STATUS_ACTIVE  => __('Active', 'eko-sampa'),
                        Eko_Sampa_Capabilities::STATUS_PENDING => __('Pending (limited)', 'eko-sampa'),
                        Eko_Sampa_Capabilities::STATUS_PAUSED  => __('Paused (read-only)', 'eko-sampa'),
                        Eko_Sampa_Capabilities::STATUS_BLOCKED => __('Blocked', 'eko-sampa'),
                    ];
                    foreach ($opts as $val => $lab) {
                        printf(
                            '<option value="%s" %s>%s</option>',
                            esc_attr($val),
                            selected($status, $val, false),
                            esc_html($lab)
                        );
                    }
                    ?>
                </select>
                <p class="description"><?php echo esc_html__('Separate from WordPress user status. Controls Eko REST and app behaviour.', 'eko-sampa'); ?></p>
            <?php else : ?>
                <code><?php echo esc_html($status); ?></code>
            <?php endif; ?>
        </td>
    </tr>
    <tr>
        <th scope="row"><?php echo esc_html__('Usage (cached ~2 min)', 'eko-sampa'); ?></th>
        <td>
            <ul class="ul-disc">
                <li><?php echo esc_html(sprintf(/* translators: 1: count, 2: max */ __('Templates: %1$d / %2$d', 'eko-sampa'), (int) ( $usage['templates_saved'] ?? 0 ), (int) ( $quotas['max_templates'] ?? 0 ))); ?></li>
                <li><?php echo esc_html(sprintf(/* translators: 1: count, 2: max */ __('Clients: %1$d / %2$d', 'eko-sampa'), (int) ( $usage['clients'] ?? 0 ), (int) ( $quotas['max_clients'] ?? 0 ))); ?></li>
                <li><?php echo esc_html(sprintf(/* translators: 1: count, 2: max */ __('Orders: %1$d / %2$d', 'eko-sampa'), (int) ( $usage['orders'] ?? 0 ), (int) ( $quotas['max_orders'] ?? 0 ))); ?></li>
                <li><?php echo esc_html(sprintf(/* translators: %s: KB */ __('Template JSON payload (estimate): %s KB', 'eko-sampa'), number_format_i18n((int) round(((int) ( $usage['payload_bytes'] ?? 0 )) / 1024)))); ?></li>
            </ul>
        </td>
    </tr>
    <?php if ($can_quotas) : ?>
        <tr>
            <th scope="row"><?php echo esc_html__('Per-user quota overrides', 'eko-sampa'); ?></th>
            <td>
                <p class="description"><?php echo esc_html__('Leave blank to use global defaults or derivation limits (templates).', 'eko-sampa'); ?></p>
                <fieldset>
                    <?php
                    $qv = static function (string $meta_key) use ($uid): string {
                        $raw = get_user_meta($uid, $meta_key, true);

                        return ($raw !== '' && $raw !== false && is_numeric($raw) && (int) $raw > 0) ? (string) (int) $raw : '';
                    };
                    ?>
                    <p><label><?php echo esc_html__('Max templates', 'eko-sampa'); ?>
                        <input type="number" class="small-text" name="eko_sampa_quotas[max_templates]" min="0" step="1" value="<?php echo esc_attr($qv(Eko_Sampa_Capabilities::META_QUOTA_MAX_TEMPLATES)); ?>" placeholder="—" /></label></p>
                    <p><label><?php echo esc_html__('Max clients', 'eko-sampa'); ?>
                        <input type="number" class="small-text" name="eko_sampa_quotas[max_clients]" min="0" step="1" value="<?php echo esc_attr($qv(Eko_Sampa_Capabilities::META_QUOTA_MAX_CLIENTS)); ?>" placeholder="—" /></label></p>
                    <p><label><?php echo esc_html__('Max orders', 'eko-sampa'); ?>
                        <input type="number" class="small-text" name="eko_sampa_quotas[max_orders]" min="0" step="1" value="<?php echo esc_attr($qv(Eko_Sampa_Capabilities::META_QUOTA_MAX_ORDERS)); ?>" placeholder="—" /></label></p>
                    <p><label><?php echo esc_html__('Max template JSON storage (MB)', 'eko-sampa'); ?>
                        <input type="number" class="small-text" name="eko_sampa_quotas[max_storage_mb]" min="0" step="1" value="<?php echo esc_attr($qv(Eko_Sampa_Capabilities::META_QUOTA_MAX_STORAGE_MB)); ?>" placeholder="—" /></label></p>
                    <p><label><?php echo esc_html__('Max quick prints / hour', 'eko-sampa'); ?>
                        <input type="number" class="small-text" name="eko_sampa_quotas[max_qp_per_hour]" min="0" step="1" value="<?php echo esc_attr($qv(Eko_Sampa_Capabilities::META_QUOTA_MAX_QP_PER_HOUR)); ?>" placeholder="—" /></label></p>
                </fieldset>
            </td>
        </tr>
    <?php endif; ?>
</table>
