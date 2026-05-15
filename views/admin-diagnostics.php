<?php
/**
 * Admin: database integrity diagnostics.
 *
 * @package Eko_Sampa
 *
 * @var string               $notice
 * @var array<string, mixed> $report
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$orphan_tpl = isset($report['orphans']['templates_missing_service']) && is_array($report['orphans']['templates_missing_service'])
    ? $report['orphans']['templates_missing_service']
    : [];
$repair_history = isset($report['repair_history']) && is_array($report['repair_history'])
    ? $report['repair_history']
    : [];
$orphan_counts = [
    'templates_missing_service' => count($orphan_tpl),
    'templates_missing_client'  => count(is_array($report['orphans']['templates_missing_client'] ?? null) ? $report['orphans']['templates_missing_client'] : []),
    'orders_missing_service'    => count(is_array($report['orphans']['orders_missing_service'] ?? null) ? $report['orphans']['orders_missing_service'] : []),
    'orders_missing_template'   => count(is_array($report['orphans']['orders_missing_template'] ?? null) ? $report['orphans']['orders_missing_template'] : []),
    'orders_missing_client'     => count(is_array($report['orphans']['orders_missing_client'] ?? null) ? $report['orphans']['orders_missing_client'] : []),
    'fields_missing_service'    => count(is_array($report['orphans']['fields_missing_service'] ?? null) ? $report['orphans']['fields_missing_service'] : []),
];

?>
<div class="wrap">
    <h1><?php echo esc_html__('Eko Sampa — Diagnostics', 'eko-sampa'); ?></h1>

    <?php if ($notice !== '') : ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
    <?php endif; ?>

    <p class="description">
        <?php echo esc_html__('Validates tables, columns, and foreign-key-like relations. Orphan template→service rows block “Create order” until repaired.', 'eko-sampa'); ?>
    </p>

    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;margin:16px 0;">
        <?php wp_nonce_field('eko_sampa_integrity', 'eko_sampa_integrity_nonce'); ?>
        <button type="submit" class="button button-secondary" name="eko_sampa_integrity_action" value="run_check">
            <?php echo esc_html__('Run integrity check', 'eko-sampa'); ?>
        </button>
        <button type="submit" class="button button-primary" name="eko_sampa_integrity_action" value="repair_orphans">
            <?php echo esc_html__('Repair orphan template services', 'eko-sampa'); ?>
        </button>
    </form>

    <h2><?php echo esc_html__('Summary', 'eko-sampa'); ?></h2>
    <table class="widefat striped" style="max-width:640px;">
        <tbody>
            <tr><th><?php echo esc_html__('Checked at', 'eko-sampa'); ?></th><td><code><?php echo esc_html((string) ( $report['checked_at'] ?? '—' )); ?></code></td></tr>
            <tr><th><?php echo esc_html__('DB version (code)', 'eko-sampa'); ?></th><td><code><?php echo esc_html((string) ( $report['db_version'] ?? '' )); ?></code></td></tr>
            <tr><th><?php echo esc_html__('DB version (stored)', 'eko-sampa'); ?></th><td><code><?php echo esc_html((string) ( $report['stored_version'] ?? '' )); ?></code></td></tr>
            <tr><th><?php echo esc_html__('Plugin version', 'eko-sampa'); ?></th><td><code><?php echo esc_html((string) ( $report['plugin_version'] ?? '—' )); ?></code></td></tr>
            <tr><th><?php echo esc_html__('Orphan template→service', 'eko-sampa'); ?></th><td><strong><?php echo esc_html((string) $orphan_counts['templates_missing_service']); ?></strong></td></tr>
            <tr><th><?php echo esc_html__('Orphan orders→service', 'eko-sampa'); ?></th><td><?php echo esc_html((string) $orphan_counts['orders_missing_service']); ?></td></tr>
            <tr><th><?php echo esc_html__('Orphan orders→template', 'eko-sampa'); ?></th><td><?php echo esc_html((string) $orphan_counts['orders_missing_template']); ?></td></tr>
            <tr><th><?php echo esc_html__('Orphan fields→service', 'eko-sampa'); ?></th><td><?php echo esc_html((string) $orphan_counts['fields_missing_service']); ?></td></tr>
        </tbody>
    </table>

    <?php if ($repair_history !== []) : ?>
        <h2><?php echo esc_html__('Repair history (last runs)', 'eko-sampa'); ?></h2>
        <table class="widefat striped" style="max-width:960px;">
            <thead>
                <tr>
                    <th><?php echo esc_html__('When (UTC)', 'eko-sampa'); ?></th>
                    <th><?php echo esc_html__('Kind', 'eko-sampa'); ?></th>
                    <th><?php echo esc_html__('Fixed', 'eko-sampa'); ?></th>
                    <th><?php echo esc_html__('User ID', 'eko-sampa'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_reverse($repair_history) as $entry) : ?>
                    <tr>
                        <td><code><?php echo esc_html((string) ( $entry['at'] ?? '' )); ?></code></td>
                        <td><?php echo esc_html((string) ( $entry['kind'] ?? '' )); ?></td>
                        <td><?php echo esc_html((string) ( $entry['count'] ?? 0 )); ?></td>
                        <td><?php echo esc_html((string) ( $entry['by'] ?? '' )); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($orphan_tpl !== []) : ?>
        <h2><?php echo esc_html__('Templates referencing missing services', 'eko-sampa'); ?></h2>
        <p><?php echo esc_html__('These templates store a service_id with no matching row in wp_eko_sampa_services. Create order will fail until repaired.', 'eko-sampa'); ?></p>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php echo esc_html__('Template ID', 'eko-sampa'); ?></th>
                    <th><?php echo esc_html__('Missing service ID', 'eko-sampa'); ?></th>
                    <th><?php echo esc_html__('Owner user ID', 'eko-sampa'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orphan_tpl as $row) : ?>
                    <tr>
                        <td><?php echo esc_html((string) ( $row['template_id'] ?? '' )); ?></td>
                        <td><?php echo esc_html((string) ( $row['service_id'] ?? '' )); ?></td>
                        <td><?php echo esc_html((string) ( $row['user_id'] ?? '' )); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h2><?php echo esc_html__('Raw report', 'eko-sampa'); ?></h2>
    <pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-height:480px;overflow:auto;font-size:12px;"><?php echo esc_html(wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
</div>
