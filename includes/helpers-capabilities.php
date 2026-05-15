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
    $admin = current_user_can('manage_options');

    return [
        'client.view'     => $admin || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_CLIENTS),
        'client.edit'     => $admin || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_CLIENTS),
        'client.delete'   => $admin || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_CLIENTS),
        'service.view'    => $admin || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_SERVICES),
        'service.edit'    => $admin || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_SERVICES),
        'service.delete'  => $admin || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_SERVICES),
        'template.view'   => $admin || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_TEMPLATES),
        'template.edit'   => $admin || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_TEMPLATES),
        'template.editor' => $admin || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_TEMPLATES),
        'template.duplicate' => $admin || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_TEMPLATES),
        'template.delete' => $admin || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_TEMPLATES),
        'order.view'      => $admin || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_ORDERS),
        'order.create'    => $admin || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_ORDERS),
        'order.edit'      => $admin || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_ORDERS),
        'order.print'     => $admin || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_ORDERS),
        'order.duplicate' => $admin || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_ORDERS),
        'order.delete'    => $admin || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_ORDERS),
    ];
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
