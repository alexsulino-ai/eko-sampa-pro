# Template derivation (master → session → user)

This document describes the **logical layer** added on top of the existing Eko Sampa template system. It does **not** change the visual JSON contract, the renderer, or the editor’s canvas rules — only **row metadata**, **REST access paths**, and **lifecycle** for public personalization.

## Concepts

| Type | `template_type` | Purpose |
|------|-------------------|---------|
| **User** | `user` (default for legacy rows) | Normal persisted template owned by `user_id`. Appears in **My templates**. |
| **Master** | `master` | Official base layout created by an administrator. **Immutable** for non-admins (REST `PATCH` on layout/metadata is rejected). |
| **Session** | `session` | Temporary working copy: same `json_data` pipeline as today, `user_id = 0`, bound by `session_token` + `expires_at`. **Not** listed in “My templates”. |

Legacy installs: migration `1.0.11` sets `template_type = 'user'` for existing rows and adds columns with safe defaults (`is_public = 0`, `allow_personalization = 1`).

## Flags

- **`is_public`** — Row may appear in the in-app **Public catalog** list (`GET /templates?catalog_public=1`).
- **`is_public_catalog`** — When `1` on a **master** row, the template appears in the **unauthenticated** marketing catalog (`GET /public/catalog`) and may fork via `POST /public/templates/{id}/session` (subject to `can_fork_public_session` and guest rate limits).
- **`allow_personalization`** — When `1` together with the relevant public flag (`is_public_catalog` for masters, or legacy `is_public` when the catalog column is absent), the row may spawn a **session** via `POST /public/templates/{id}/session` (no login required for this endpoint only).

## Hardening (DB ≥ 1.0.12)

See [session-hardening.md](session-hardening.md): `session_fingerprint`, lifecycle states, guest quick-print expiry/abandon, admin observability, editor session autosave, semantic split `is_public` / `is_public_catalog` / `is_user_shareable` / `is_marketplace_item`, and roadmap note for a future `eko_sampa_template_sessions` table.

## Flow

1. **Master (or public user template)** — Admin (or editor) marks `is_public` / `allow_personalization` / `template_type` as appropriate.
2. **Use template** — Client calls `POST /eko-sampa/v1/public/templates/{masterId}/session` → receives new row id + `session_token` → opens editor with `?template_id={sessionId}&session_token={token}`.
3. **Optional reuse** — The same client may send `{ "reuse": { "template_id": sessionId, "session_token": token } }` on a later `POST` for the **same** `masterId`. If `try_reuse_guest_session_for_public_master()` accepts the row, the server returns **200** with `reused: true` and **does not** emit `eko_sampa_template_session_forked` again. Otherwise the normal fork runs (rate limits apply). **Server reuse** (no JSON body): if a recent transient remembers a valid session for the same client key + master, the service returns **200** the same way before attempting a new fork.
4. **Edit / thumbnails / quick print** — Same REST routes as before; for the session row, `session_token` is passed as a query param or JSON field. Permission is granted without dashboard login when the token matches.
5. **Save to My templates** — Logged-in user: `POST /eko-sampa/v1/templates/{sessionId}/persist-to-mine` with `session_token` in JSON or query. Creates a normal **`user`** row (existing `try_create` path), copies thumbnail when possible, **deletes** the session row and orphan quick-print jobs for that template id.

## Limits (infrastructure only)

- **`eko_sampa_max_saved_templates_per_user`** (option, default `50`) — Enforced on **persist-to-mine** only. Admins can change the option (no subscription UI). The templates list (**My templates**) shows a usage bar (`used` / `max`) for the logged-in owner.
- **`eko_sampa_template_session_ttl_hours`** (option, default `72`) — New sessions get `expires_at` in UTC storage from this TTL.

## Marketplace-ready (no commerce yet)

- Catalog rows pass through `apply_filters('eko_sampa_marketplace_catalog_row', $row)` after `json_data` is removed — attach pricing/visibility later without changing the core catalog query.
- Funnel events: `eko_sampa_public_funnel_do( 'session_forked' | 'session_reused', $context )` → `do_action('eko_sampa_public_funnel', …)` for lightweight analytics.

## Cleanup

- Scheduled action: **`eko_sampa_template_derivation_cleanup`** (hourly).
- Deletes **expired** `session` rows (and their JPEG assets), then removes **orphan** quick-print jobs where `user_id = 0` and the template is no longer a session.
- Emits **`do_action('eko_sampa_guest_qp_marked_abandoned', $n)`** when stale guest jobs are bulk-marked abandoned, and **`do_action('eko_sampa_guest_sessions_expired_cleanup', $deleted)`** after expired session rows are purged — for O(1) analytics listeners.

**Never** deletes `user` or `master` rows via this job.

## REST summary

| Method | Route | Auth |
|--------|-------|------|
| GET | `/public/catalog` | Public when marketing home is enabled — **master** + `is_public_catalog = 1` only (no `json_data` in payload) |
| GET | `/public/categories` | Public when marketing home is enabled |
| POST | `/public/templates/{id}/session` | Public (`permission_callback` open) — only if `can_fork_public_session` |
| GET/PATCH | `/templates/{id}` (+ thumbnails, placeholders) | Dashboard capability **or** valid `session_token` for a **session** row |
| POST | `/templates/{id}/persist-to-mine` | `manage_options` or `CAP_MANAGE_TEMPLATES` + valid session token |
| GET | `/templates?catalog_public=1` | Template capability; returns `master` + `user` public rows |

## Critical contracts (unchanged)

- `json_data` structure and renderer inputs.
- User-owned save path for normal templates (`template_type = user`).
- Thumbnail and quick-print pipelines reuse the same code; only authorization and guest quick-print storage (`session_token` on `eko_sampa_quick_print_jobs`) were extended.

## Known gaps / extensions

- **Orders from anonymous editor**: still require the existing orders REST capability; binding orders to `session_token` is a future extension (not implemented here).
- **Gallery uploads** during anonymous session: still require template REST capability unless extended similarly.
