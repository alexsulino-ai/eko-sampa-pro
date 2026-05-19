# Public home flow (conversion funnel)

## Goal

Unauthenticated visitors browse **master templates** with **`is_public_catalog = 1`** only, start a **session** (fork), use the **existing** editor and quick print, and are nudged to **register** for library / persistence.

## Routes

| URL | View | Auth |
|-----|------|------|
| `/eko-sampa/` | `public_home` | None |
| `/eko-sampa_editor/?template_id=&session_token=` | `editor` | Guest when token validates |

The authenticated app (`/eko-sampa_dashboard/`, etc.) is unchanged.

## REST (public)

- `GET /wp-json/eko-sampa/v1/public/catalog` — paginated catalog (no `json_data`). Disabled when public home is off (`404`).
- `GET /wp-json/eko-sampa/v1/public/categories` — category filter list.
- **Browser note:** `public-home.js` does **not** send `X-WP-Nonce` on these GETs so a **cached HTML** page with an expired embedded nonce cannot break the catalog (LiteSpeed / full-page cache). `POST …/public/templates/{id}/session` still sends the nonce.
- `POST /wp-json/eko-sampa/v1/public/templates/{id}/session` — fork session; optional JSON body `{ "reuse": { "template_id", "session_token" } }` returns **200** when an existing guest session for that master is still valid. Otherwise **201** after a new fork. Guarded by `Eko_Sampa_Public_Experience::guard_session_fork()` (skipped on successful reuse) and `can_fork_public_session()`.
- `POST /wp-json/eko-sampa/v1/public/telemetry` — optional nonce-gated counters (e.g. conversion modal opens).

Server-side catalog assembly and caches live in `Eko_Sampa_Public_Experience_Service` (see `docs/public-experience-architecture.md`).

## Admin

**Eko Sampa → Public & guests** — toggles, limits, featured + trending IDs, site-wide guest session ceiling, read-only snapshot (counters + live SQL + top forked masters).

## Login continuity

Frontend login accepts `redirect_to` (validated). After sign-in, the user returns to the editor URL with `template_id` + `session_token` so work is not lost; **Persist to mine** then moves JSON into a user template (existing REST).

## Session reuse (guest, same browser)

To avoid duplicate anonymous session rows for the same master:

1. After a successful `POST …/session`, `public-home.js` stores `{ template_id, session_token, t }` in `sessionStorage` under `eko_sampa_pub_fork_v1`, keyed by **master** id (client TTL ~36h; server still validates).
2. The next **Personalizar** / **Imprimir rápido** sends JSON `{ "reuse": { "template_id": …, "session_token": "…" } }`.
3. If `Eko_Sampa_Template::try_reuse_guest_session_for_public_master()` accepts the row, the API returns **200** with `reused: true` and **does not** fire `eko_sampa_template_session_forked` again.
4. Otherwise the server falls through to the normal fork path (rate limits / quotas apply).

## Quick print entry from catalog

`Imprimir rápido` redirects to the guest editor with `eko_open_qp=1`. The editor removes the query param after reading it and opens the quick-print manager for guests only (`guestEditor`).

## Hooks (analytics / marketplace bridge)

- `do_action('eko_sampa_public_funnel', $event, $context)` — emitted via `eko_sampa_public_funnel_do()` for `session_reused` and `session_forked` (after the legacy fork hook on new sessions only).
- `apply_filters('eko_sampa_marketplace_catalog_row', $row)` — catalog rows after `json_data` is stripped; no-op unless extensions attach.
