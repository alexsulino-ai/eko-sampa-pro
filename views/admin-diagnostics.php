<?php
/**
 * Admin: database integrity diagnostics.
 *
 * @package Eko_Sampa
 *
 * @var string               $notice
 * @var array<string, mixed> $report
 * @var array<string, mixed>|null $inspect_snapshot
 * @var int                         $inspect_sid
 * @var list<array<string, mixed>>  $delete_audit
 * @var array<string, mixed>|null     $storage_report Result of {@see Eko_Sampa_Storage_Manager::build_storage_integrity_report()}
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

if (! isset($delete_audit) || ! is_array($delete_audit)) {
    $delete_audit = [];
}
if (! isset($inspect_sid)) {
    $inspect_sid = 0;
}
if (! isset($inspect_snapshot)) {
    $inspect_snapshot = null;
}

if (! isset($storage_report)) {
    $storage_report = null;
}

$permission_violations = is_array($permission_report) && ! empty($permission_report['violations'])
    ? $permission_report['violations']
    : [];

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
        <?php
        $notice_class = 'notice-success';
        if (is_array($permission_report) && $permission_violations !== []) {
            $notice_class = 'notice-warning';
        }
        ?>
        <div class="notice <?php echo esc_attr($notice_class); ?> is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
    <?php endif; ?>

    <p class="description">
        <?php echo esc_html__('Validates tables, columns, and foreign-key-like relations. Orphan template→service links are cleared (no auto-created catalog services).', 'eko-sampa'); ?>
    </p>

    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;margin:16px 0;">
        <?php wp_nonce_field('eko_sampa_integrity', 'eko_sampa_integrity_nonce'); ?>
        <button type="submit" class="button button-secondary" name="eko_sampa_integrity_action" value="run_check">
            <?php echo esc_html__('Run integrity check', 'eko-sampa'); ?>
        </button>
        <button type="submit" class="button button-primary" name="eko_sampa_integrity_action" value="repair_orphans">
            <?php echo esc_html__('Unlink orphan template services', 'eko-sampa'); ?>
        </button>
        <span style="display:inline-flex;align-items:center;gap:6px;margin-left:8px;">
            <label for="eko_sampa_inspect_service_id" class="screen-reader-text"><?php echo esc_html__('Service ID to inspect', 'eko-sampa'); ?></label>
            <input
                id="eko_sampa_inspect_service_id"
                name="service_id"
                type="number"
                min="1"
                step="1"
                class="small-text"
                placeholder="<?php echo esc_attr__('Service ID', 'eko-sampa'); ?>"
                value="<?php echo $inspect_sid > 0 ? esc_attr((string) $inspect_sid) : ''; ?>"
            />
            <button type="submit" class="button" name="eko_sampa_integrity_action" value="inspect_service_delete">
                <?php echo esc_html__('Inspect service (delete readiness)', 'eko-sampa'); ?>
            </button>
        </span>
        <button type="submit" class="button" name="eko_sampa_integrity_action" value="storage_integrity_report" style="margin-left:8px;">
            <?php echo esc_html__('Storage integrity report (dry-run)', 'eko-sampa'); ?>
        </button>
    </form>

    <?php if (is_array($storage_report)) : ?>
        <h2><?php echo esc_html__('Storage integrity (filesystem, dry-run)', 'eko-sampa'); ?></h2>
        <p class="description"><?php echo esc_html__('Read-only scan: legacy JPG orphans, completed orders missing snapshot manifest, snapshot directory count.', 'eko-sampa'); ?></p>
        <?php
        $storage_critical = 0;
        if (isset($storage_report['findings']) && is_array($storage_report['findings'])) {
            foreach ($storage_report['findings'] as $f) {
                if (is_array($f) && ( $f['severity'] ?? '' ) === 'CRITICAL') {
                    ++$storage_critical;
                }
            }
        }
        ?>
        <?php if ($storage_critical > 0) : ?>
            <div class="notice notice-error inline"><p>
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: %d: number of CRITICAL findings */
                        _n(
                            '%d CRITICAL storage finding — completed orders may be rendering without a production snapshot.',
                            '%d CRITICAL storage findings — completed orders may be rendering without a production snapshot.',
                            $storage_critical,
                            'eko-sampa'
                        ),
                        $storage_critical
                    )
                );
                ?>
            </p></div>
        <?php endif; ?>
        <pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-height:360px;overflow:auto;font-size:12px;"><?php echo esc_html(wp_json_encode($storage_report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
    <?php endif; ?>

    <?php if (is_array($permission_report)) : ?>
        <h2><?php echo esc_html__('REST ↔ model permission consistency', 'eko-sampa'); ?></h2>
        <p class="description"><?php echo esc_html__('Structural checks to catch permission drift (e.g. REST allows manage_eko_services but the service model no longer calls the shared contract helper).', 'eko-sampa'); ?></p>
        <?php if ($permission_violations !== []) : ?>
            <div class="notice notice-error inline"><p><?php echo esc_html__('Violations detected — fix before release.', 'eko-sampa'); ?></p></div>
        <?php endif; ?>
        <pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-height:420px;overflow:auto;font-size:12px;"><?php echo esc_html(wp_json_encode($permission_report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
    <?php endif; ?>
    <p class="description">
        <?php echo esc_html__('Shows template/order/field counts, legacy servico_id usage, and API visibility flags for a single service id. Use after a failed REST DELETE or before bulk cleanup.', 'eko-sampa'); ?>
    </p>
    <?php if (is_array($inspect_snapshot)) : ?>
        <pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-height:360px;overflow:auto;font-size:12px;"><?php echo esc_html(wp_json_encode($inspect_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
    <?php endif; ?>

    <?php if ($delete_audit !== []) : ?>
        <h3><?php echo esc_html__('Recent service delete audit (last attempts)', 'eko-sampa'); ?></h3>
        <p class="description"><?php echo esc_html__('Stored server-side (ids and outcomes only).', 'eko-sampa'); ?></p>
        <table class="widefat striped" style="max-width:960px;">
            <thead>
                <tr>
                    <th><?php echo esc_html__('When (UTC)', 'eko-sampa'); ?></th>
                    <th><?php echo esc_html__('User', 'eko-sampa'); ?></th>
                    <th><?php echo esc_html__('Service ID', 'eko-sampa'); ?></th>
                    <th><?php echo esc_html__('OK', 'eko-sampa'); ?></th>
                    <th><?php echo esc_html__('Code / outcome', 'eko-sampa'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_reverse(array_slice($delete_audit, -15)) as $entry) : ?>
                    <tr>
                        <td><code><?php echo esc_html((string) ( $entry['at'] ?? '' )); ?></code></td>
                        <td><?php echo esc_html((string) ( $entry['by'] ?? '' )); ?></td>
                        <td><?php echo esc_html((string) ( $entry['service_id'] ?? '' )); ?></td>
                        <td><?php echo ! empty($entry['ok']) ? esc_html__('Yes', 'eko-sampa') : esc_html__('No', 'eko-sampa'); ?></td>
                        <td><code><?php echo esc_html((string) ( $entry['code'] ?? $entry['outcome'] ?? '' )); ?></code></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

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
        <p><?php echo esc_html__('These templates store a service_id with no matching row. Unlink clears service_id so orders use template placeholders only.', 'eko-sampa'); ?></p>
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
