<?php
/**
 * Single source of truth for REST ↔ model permission alignment (services scope first).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Whether the current user has the same elevated scope used by:
 * - {@see Eko_Sampa_Rest_Api::require_services_cap()}
 * - {@see Eko_Sampa_Service::is_unrestricted()}
 *
 * Changing REST gates without updating this function (and the Service model) reintroduces permission drift.
 */
function eko_sampa_services_actor_has_elevated_scope(): bool {
    return current_user_can('manage_options') || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_SERVICES);
}
