<?php
/**
 * Public conversion funnel + future marketplace extension points (no core mutation).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Fires for lightweight analytics / integrations (guest fork, reuse, telemetry bridges).
 * Does not persist by default — listeners may bump options or send to external systems.
 *
 * @param string               $event   e.g. `session_forked`, `session_reused`.
 * @param array<string, mixed> $context Must include integer `master_id` / `session_id` when relevant.
 */
function eko_sampa_public_funnel_do(string $event, array $context = []): void {
    /**
     * @param string               $event
     * @param array<string, mixed> $context
     */
    do_action('eko_sampa_public_funnel', $event, $context);
}

/**
 * Bridge hook for future marketplace / pricing layers on catalog rows (json_data already stripped).
 *
 * @param array<string, mixed> $row Public catalog row.
 *
 * @return array<string, mixed>
 */
function eko_sampa_marketplace_filter_catalog_row(array $row): array {
    return apply_filters('eko_sampa_marketplace_catalog_row', $row);
}

/**
 * Extension point for multi-tenant / marketplace analytics adapters (no PII in core payloads).
 *
 * @param array<string, mixed> $context
 *
 * @return array<string, mixed>
 */
function eko_sampa_analytics_filter_event_context(array $context): array {
    return apply_filters('eko_sampa_analytics_event_context', $context);
}
