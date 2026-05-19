# Public experience architecture

This document describes how **public marketing**, **guest sessions**, and **conversion** are orchestrated after the consolidation into `Eko_Sampa_Public_Experience_Service`.

It does **not** replace renderer, `json_data`, thumbnail pipelines, derivation math, or orders — those stay in their existing modules.

## Layers

| Layer | Responsibility |
|--------|----------------|
| `Eko_Sampa_Public_Experience` | Option keys, `register_settings`, WordPress hooks façade (`document_title_parts`, quick-print filter, session fork/persist hooks). |
| `Eko_Sampa_Public_Experience_Service` | Catalog payloads, guest quotas, global guest session soft cap, REST-adjacent helpers, SEO meta for public home, aggregated admin stats, nonce-gated public telemetry ingest, fork reuse formatting (client JSON + server transient reuse). |
| `Eko_Sampa_Public_Analytics` | Product counters, bounded per-master rollup, popularity snapshots, hourly flush cron, admin dashboard slice, `/internals/health` payload assembly. |
| `Eko_Sampa_Frontend_Router` | Route access: valid guest editor session vs stale URL (`guest_editor_session_stale`) → expired view globals. |
| `Eko_Sampa_Rest_Api` | Thin REST delegates (`/public/catalog`, `/public/categories`, `/public/templates/{id}/session`, `POST /public/telemetry`). |
| Views under `views/partials/public-experience/` | Reusable hero, filters, guest banner, conversion modal shell (Alpine bindings stay on `#eko-sampa-editor`). |

## Lifecycle (high level)

1. **Public home** loads `public-home.js` only (no editor bundle). Catalog: `GET /public/catalog` with scopes `all | featured | recent | popular` (`popular` uses admin “trending IDs” list) plus **analytics scopes** `trending_today | trending_week | recently_printed | most_saved` (ordered server-side from rollups + snapshots — no giant SQL).
2. **Fork** `POST /public/templates/{master}/session` → derivation creates `template_type = session`, `user_id = 0`, token + fingerprint. Optional JSON **`reuse`** may return HTTP **200** with an existing session (no duplicate fork, no extra `eko_sampa_template_session_forked`). **Server-side reuse:** before creating a new session, the service may reuse the last remembered session for the same **client key + master** (transient), still returning **200** when valid.
3. **Guest editor** is `editor` view + `frontend-guest-editor-wrap.php` → `editor-canvas.php` (unchanged contract).
4. **Autosave / PATCH** continues to use existing REST with `session_token` query binding.
5. **Quick print** uses existing job pipeline; service applies guest hourly + concurrent caps.
6. **Persist to library** requires logged-in user; conversion modal + `redirect_to` preserves editor URL.
7. **Stale session URL** → `frontend-guest-session-expired.php` with `?fork={parent_master_id}` when derivable.

## Quotas & cleanup

- Per-client session list + prune on fork (`OPT_MAX_SESSIONS_IP`).
- Fork debounce + hourly fork cap (`OPT_FORK_DEBOUNCE_SEC`, `OPT_FORK_MAX_PER_HOUR`).
- Site-wide guest session row ceiling (`OPT_MAX_GUEST_SESSIONS_GLOBAL`) enforced after successful forks and on derivation cron hook.
- Guest quick print: concurrent open jobs + hourly counter (existing REST guards).

## Caching

- Catalog responses: short JSON transients keyed by query args (service).
- Categories: `eko_sampa_pubcats_v1` transient.
- **Client-side:** `public-home.js` keeps an in-memory GET cache (~45s) to avoid redundant fetches while tabbing/searching.

## Observability (lightweight)

- Counters in options: sessions forked, persist, guest QP jobs, conversion modal telemetry, recovery-banner metric.
- **`Eko_Sampa_Public_Analytics`** options/transients: full counter map + per-master rollup + hourly popularity snapshot (see [analytics-architecture.md](analytics-architecture.md)).
- `do_action('eko_sampa_public_funnel', $event, $context)` — optional extension point (`eko_sampa_public_funnel_do()`): `session_forked`, `session_reused`.
- `GET /internals/public-experience-stats` (admin): merges counters + live SQL counts + `top_masters_by_guest_fork` (guest session rows grouped by `parent_template_id`).
- `GET /internals/health` (admin): merges derivation snapshot, public experience stats, analytics counters, stuck QP heuristic — see [platform-observability.md](platform-observability.md).
- `POST /public/telemetry` with `X-WP-Nonce`: `conversion_modal_open`, `editor_boot` (rate-limited + deduped).

## SEO

- `wp_head` injects description + OpenGraph + minimal `WebSite` JSON-LD.
- `document_title_parts` adjusts HTML title when `?categoria=` is present on public home.

## Rollback

- Disable public home or guest editing via **Eko Sampa → Public & guests**.
- Remove or empty trending / featured ID lists to make tabs return empty sets without code rollback.
