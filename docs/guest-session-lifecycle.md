# Guest session lifecycle

See also: [public-experience-architecture.md](public-experience-architecture.md) and [guest-conversion-flow.md](guest-conversion-flow.md).

## Creation

1. Visitor loads `/eko-sampa/` (if enabled).
2. `POST /public/templates/{master_id}/session` creates a row with `template_type = session`, `user_id = 0`, `session_token`, `expires_at` / `last_activity_at` from `Eko_Sampa_Template_Derivation::expires_bounds_for_new_session()`.
3. Anti-abuse: debounce + hourly fork cap + max concurrent guest sessions per client key (`Eko_Sampa_Public_Experience::request_client_key()`), with pruning of oldest idle session rows when over quota.

## Editor access

The frontend router allows the `editor` view without login **only** when `Eko_Sampa_Public_Experience::guest_editor_session_row_valid()` is true (GET `template_id` + `session_token` and `request_can_use_session_row`).

## Autosave

Session rows support `PATCH` with `session_token` (existing contract). Autosave uses the same path as authenticated sessions.

## Quick print

Guests may create jobs when `eko_sampa_quick_print_enabled` passes the guest filter, the template/session token validates, snapshot validates, and `guard_guest_quick_print_create()` allows (concurrent open jobs + hourly cap). Successful job creation increments the hourly counter and a metric.

## Conversion

- **Salvar em Meus templates**: opens a non-aggressive modal; link goes to frontend login with `redirect_to` the current editor URL.
- After login, user calls existing `POST /templates/{session_id}/persist-to-mine` (authenticated).

## Expiry

Session TTL follows `Eko_Sampa_Template_Derivation::session_ttl_hours()` and cron cleanup already documented for derivation — guest rows use the same session machinery.
