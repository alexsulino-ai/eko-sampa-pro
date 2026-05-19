<?php
/**
 * Frontend capability map for policy-aware UI (actions, routes).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Ability keys exposed to JS as window.ekoSampaRest.capabilities.
 *
 * @return array<string, bool>
 */
function eko_sampa_frontend_capabilities(): array {
    return Eko_Sampa_Capabilities::frontend_capability_map();
}

/**
 * Server-side gate before rendering a control.
 */
function eko_sampa_user_can(string $ability): bool {
    $ability = strtolower(trim($ability));
    // Do not use sanitize_key() — it strips dots (service.view → serviceview).
    if ($ability === '' || ! preg_match('/^[a-z0-9_.]+$/', $ability)) {
        return false;
    }
    $caps = eko_sampa_frontend_capabilities();

    return ! empty($caps[ $ability ]);
}

/**
 * Safe Alpine expression for capability checks (never throws if ekoSampaCan is missing).
 */
function eko_sampa_alpine_can_expr(string $ability): string {
    $ability = esc_attr($ability);

    return "(typeof window.ekoSampaCan === 'function' ? window.ekoSampaCan('" . $ability . "') : true)";
}
