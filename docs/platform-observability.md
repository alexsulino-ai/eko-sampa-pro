# Platform observability

Eko Sampa treats **public marketing**, **guest sessions**, **quick print**, and **derivation cleanup** as production pipelines. Observability is split into:

1. **Product analytics** — `Eko_Sampa_Public_Analytics` (counters, rollups, snapshots). See [analytics-architecture.md](analytics-architecture.md).
2. **Operational snapshots** — `Eko_Sampa_Public_Experience_Service::get_aggregated_stats()`, `Eko_Sampa_Template_Derivation::publish_observability_snapshot()`.
3. **Unified health REST** — `GET /eko-sampa/v1/internals/health` (admin only, same permission model as other `/internals/*` routes).

## Health endpoint

`GET /wp-json/eko-sampa/v1/internals/health`

Returns a JSON document including:

- `counters` — analytics global map.
- `public_experience` — merged metrics + live SQL snippet from `get_aggregated_stats()`.
- `derivation_snapshot` — last published derivation counters.
- `queues.guest_qp_stuck_older_than_30m` — guest jobs in `queued` / `sent_to_browser` with `updated_at` older than 30 minutes (indicator of stuck clients or missing `complete` calls).
- `guest_qp_abandoned_rows` — current rows in `abandoned` state.
- `cron` — next scheduled timestamps for derivation + analytics hourly tasks.
- `storage_bytes_estimate` — rough serialized size of rollup + counters (not full media disk usage).

## Admin UI

**Eko Sampa → Public & guests** now surfaces:

- **Operational warnings** (sessions high vs soft cap, stuck QP, derivation cron missing).
- **Product analytics table** (counter keys) and a short **“masters with activity”** list from rollups.

## Cron

| Hook | Purpose |
|------|---------|
| `eko_sampa_template_derivation_cleanup` | Session expiry, QP abandon/purge, orphan QP rows, observability snapshot. |
| `eko_sampa_analytics_hourly` | Flush view queue → rollup, rebuild popularity snapshot, `delete_expired_transients()`. |

## Design rules

- Avoid **synchronous** popularity recompute on every catalog request.
- Prefer **hooks + small increments** over scanning history tables for dashboards.

## User governance (admin)

Operational user metrics for **Eko Sampa → Users** reuse the same philosophy: the list screen reads **cached usage snapshots** (`Eko_Sampa_Capabilities::get_usage_snapshot`) instead of running heavy `COUNT(*)` across all tables on every request. The optional analytics strip on that page samples at most the **200 most recently registered** accounts for blocked / near-template-quota signals — it is indicative, not a full audit trail. See [user-management-architecture.md](user-management-architecture.md).
