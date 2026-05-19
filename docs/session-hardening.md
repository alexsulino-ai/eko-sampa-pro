# Session hardening (master → session → user)

This document describes **incremental hardening** added alongside the template derivation system. It does **not** change JSON contracts, the renderer, the thumbnail capture engine, or the quick-print browser flow.

**DB version:** `1.0.12` — adds `session_fingerprint`, `session_lifecycle`, catalog flags (`is_public_catalog`, `is_user_shareable`, `is_marketplace_item`), and quick-print job `expires_at` / `abandoned_at`.

## Session ownership & fingerprint

Each session row stores:

- `session_token` — high-entropy secret in the editor URL.
- `session_fingerprint` — `hash_hmac` over a **light client binding** (partial IPv4 / IPv6 prefix + short SHA-256 of `User-Agent`) keyed with `wp_salt('eko_sampa_tpl_sess_fp')` and the session token.

**Retrocompat:** rows with an empty `session_fingerprint` still validate (pre–1.0.12 sessions).

**Goal:** reduce accidental token reuse across devices and trivial token paste attacks, without banking-grade device binding.

## Request gate (`request_can_use_session_row`)

All REST paths that resolve a template via `session_token` require:

1. `template_type === session`
2. Token match (`hash_equals`)
3. `expires_at` in the future (GMT string compared like before)
4. `session_lifecycle` empty or `active`
5. Fingerprint match (skipped when stored fingerprint is empty)

Implemented centrally in `Eko_Sampa_Template_Derivation::request_can_use_session_row()` and used from `Eko_Sampa_Rest_Api` and `Eko_Sampa_Template::update()`.

## Guest session PATCH surface

Non-capability writes with a valid session token may only send **`json_data`, `width_mm`, `height_mm`**. Other fields are stripped server-side before update (admins editing via capability keep full write).

## Ownership on persistent operations

User-owned templates still go through `Eko_Sampa_Template::get()` / ownership SQL. **Orders:** `Eko_Sampa_Order::prepare_create_data()` accepts optional `session_token` (JSON body or `?session_token=`). `Eko_Sampa_Template::get_for_order_context()` resolves a session row only when the gate passes; otherwise normal ownership applies.

**Persist-to-mine** requires `require_app_user` (logged-in dashboard user) plus a valid session gate.

## Quick print lifecycle (guest)

- On create, guest jobs store `expires_at` = min(plugin TTL, session `expires_at` when applicable). TTL option: `eko_sampa_qp_guest_ttl_minutes` (default **120**, clamped 15 min–48 h).
- Cron marks stale guest jobs (`queued` / `sent_to_browser`, **>3 h** since `created_at`) as `abandoned` with `abandoned_at = UTC_TIMESTAMP()`.
- Cron deletes guest jobs past `expires_at` **or** `abandoned` for **>24 h** (abandoned cleanup uses GMT cut-off aligned with `abandoned_at` storage).
- `get_for_user()` rejects expired / abandoned / session-expired templates so APIs stop surfacing dead jobs.

Status `abandoned` is additive; existing statuses unchanged for normal flows.

## Thumbnail explosion control

- **Server GD auto-thumbnail** after template writes is **skipped** for `template_type = session` (`Eko_Sampa_Template_Thumbnail::should_auto_server_thumbnail_after_template_write`). Client / live capture uploads behave as before.
- Editor throttles **client** thumbnail uploads for session URLs (~**45 s** unless a manual save forces one).

## Session autosave & recovery (editor)

- **Autosave:** debounced (**12 s** default, `sessionAutosaveDebounceMs` in `ekoSampaEditor`) PATCH with `{ json_data }` only; `last_activity_at` is bumped server-side when the session token is present.
- **Recovery banner:** open the editor with `eko_recover_session=1` **and** a valid `session_token` to show a dismissible notice (`showSessionRecoveryBanner` in localized config). Same fingerprint/IP binding applies via the normal session gate on every request.

## “Salvar em Meus Templates”

Toolbar button (session only). If not logged in, redirects to `loginUrl` (return URL preserves `template_id` + `session_token`). On success, redirects to the **new** user template id (session query params removed).

## Public catalog flags (semantic split)

| Column | Meaning |
|--------|--------|
| `is_public` | Legacy “listed as public” flag; **kept for backward compatibility**. |
| `is_public_catalog` | Official catalog listing; backfilled from `is_public` on migration. |
| `is_user_shareable` | Reserved for future user-to-user sharing (default `0`). |
| `is_marketplace_item` | Reserved for marketplace (default `0`). |

Fork + catalog list treat **`is_public_catalog OR is_public`** as visible public catalog until all rows use the new column.

Non-admins cannot set derivation / catalog / marketplace columns via REST (`sanitize_template_admin_only_fields`).

## Persist-to-mine & binary assets

Persist copies **layout JSON** and **thumbnail file** via existing `Eko_Sampa_Template_Thumbnail::copy()` (same as before). Shared uploads referenced inside JSON are **not** duplicated by this layer; avoiding duplicate binaries for referenced media is a **storage policy** concern documented here for future optimization (no JSON rewrite in this hardening).

## Observability & audit

- **Option** `eko_sampa_derivation_observability` — counts refreshed on cron and via `GET /wp-json/eko-sampa/v1/internals/derivation-stats` (`manage_options` only).
- **Unified health:** `GET /wp-json/eko-sampa/v1/internals/health` — admin-only bundle (derivation snapshot + public stats + analytics counters + stuck QP heuristic). See [platform-observability.md](platform-observability.md).
- **Audit:** `Eko_Sampa_Storage_Audit` entries on session purge batches and quick-print abandon sweeps.
- **Cleanup hooks (listeners must stay O(1)):** `eko_sampa_guest_qp_marked_abandoned`, `eko_sampa_guest_sessions_expired_cleanup`.

## Analytics hooks (foundation)

- `do_action('eko_sampa_template_session_forked', $source_id, $new_session_id);`
- `do_action('eko_sampa_session_persisted_to_user_template', $session_id, $new_user_template_id, $parent_template_id);`
- `do_action('eko_sampa_order_created', $order_id, $context);` — `$context` includes `template_id`, `guest_template_flow`.
- `do_action('eko_sampa_quick_print_job_created', $job_row);` / `do_action('eko_sampa_quick_print_job_completed', $job_row);`

## Server-side fork reuse (same client key)

After a successful fork or explicit JSON reuse, the server stores a short-lived transient keyed by **client key + master id** so a later fork request **without** `sessionStorage` can still return **200** with `reused: true` when `try_reuse_guest_session_for_public_master()` validates the remembered session. This complements JSON `reuse` (tabs that never synced storage).

## Safe delete & guest sessions

`eko_sampa_safe_delete_template()` accepts `session_token` in options. When ownership `get()` fails but the session gate passes, the row is deleted with a direct query after the usual relation checks.

## Roadmap: separate session table

Today, **sessions live in `eko_sampa_templates`** with `template_type = session`. At scale, moving ephemeral rows to **`eko_sampa_template_sessions`** (shorter rows, aggressive TTL indexes, no join pollution with user templates) is the documented upgrade path — **not implemented** in this iteration.

## Anti-orphan rules (summary)

| Artifact | Rule |
|----------|------|
| Expired session templates | Hourly cron deletes row + thumbnail file |
| Guest quick-print jobs | `expires_at`, `abandoned` path, purge after TTL |
| Jobs pointing at removed sessions | `DELETE` join cleanup (existing + extended) |
| User / master templates | Never deleted by derivation cron |
