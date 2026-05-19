# Healthcheck and cleanup

## Internal health API

Administrators (same capability gate as `GET /internals/derivation-stats`) may call:

`GET /wp-json/eko-sampa/v1/internals/health`

Implemented in `Eko_Sampa_Rest_Api::route_internals_health()` delegating to `Eko_Sampa_Public_Analytics::build_health_payload()`.

Use it for:

- Monitoring dashboards (HTTP probe).
- Manual triage when guests report “print stuck” or “session vanished”.

## Derivation cleanup (`eko_sampa_template_derivation_cleanup`)

Defined in `Eko_Sampa_Template_Derivation::run_scheduled_cleanup()`:

1. Marks stale guest quick-print jobs as `abandoned` (hours-old `queued` / `sent_to_browser`).
2. Purges expired / long-abandoned guest jobs.
3. Deletes expired **session** templates (never user/master).
4. Removes orphan guest quick-print rows pointing at non-session templates.

### Hooks for analytics (non-invasive)

After bulk QP abandon SQL:

- `do_action('eko_sampa_guest_qp_marked_abandoned', (int) $rowsAffected)`

After session template deletes:

- `do_action('eko_sampa_guest_sessions_expired_cleanup', (int) $deletedSessionCount)`

Listeners should remain **O(1)** — bump counters or enqueue async work; do not run heavy reporting inside these hooks.

## Analytics cleanup (`eko_sampa_analytics_hourly`)

- Drains the catalog view queue into per-master rollup (`vw` increments + global `template_view_count`).
- Rebuilds the popularity snapshot transient used by **trending** scopes.
- Calls WordPress `delete_expired_transients()` as a light hygiene pass.

## Guest session reuse (server-side)

To reduce duplicate forks across tabs on the same device, successful forks store a short-lived transient keyed by **hashed client key + master id** (`Eko_Sampa_Public_Analytics::remember_guest_session_for_client`). A later `POST /public/templates/{master}/session` without JSON `reuse` may still return **200** if that session is still valid (`try_reuse_guest_session_for_public_master`).

This does **not** replace `session_token` checks for API access; it only avoids creating redundant session rows when the browser lost `sessionStorage` but the server still knows the last good session for that client key.
