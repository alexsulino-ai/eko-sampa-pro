# Analytics architecture (public product metrics)

This layer answers **“what is happening in the funnel?”** without turning the plugin into a tracking pixel or storing identifiable visitor data.

## Class

`Eko_Sampa_Public_Analytics` (`includes/class-public-analytics.php`)

- **Registers on** `Eko_Sampa_Public_Experience::register_hooks()` (same boot path as public experience — no coupling to the editor canvas logic).
- **Persists**
  - **Global counters** in option `eko_sampa_analytics_counters_v1` (integer map).
  - **Per-master rollup** in option `eko_sampa_analytics_tpl_rollup_v1` (bounded map, LRU-ish prune by `ts` when oversized).
  - **Transient queues** for batched catalog “views” (`eko_sampa_analytics_view_q_v1`) — flushed on the hourly cron, not on every HTTP request.
- **Snapshots**
  - Popularity / ordering list: transient `eko_sampa_analytics_pop_snap_v1`, rebuilt by `eko_sampa_analytics_hourly` and on-demand when empty.

## Counters (names)

| Key | Source (high level) |
|-----|---------------------|
| `template_view_count` | Batched from catalog item IDs (rate-limited per client key). |
| `template_fork_count` | `eko_sampa_template_session_forked`. |
| `template_save_count` | `eko_sampa_session_persisted_to_user_template`. |
| `template_print_count` | Quick print job completed (mapped to **master** via session parent). |
| `template_order_count` | `eko_sampa_order_created`. |
| `guest_to_saved_template_count` | Same persist hook. |
| `guest_to_order_count` | Order create when `_eko_order_template_session` was present (guest template flow). |
| `guest_to_signup_count` | Heuristic: `user_register` if the same **client key** had forked in the last 24h (no email/name stored). |
| `editor_open_count` | `POST /public/telemetry` event `editor_boot` (deduped per client / hour). |
| `editor_recovery_count` | `do_action('eko_sampa_editor_recovery_tracked')` after recovery metric bump. |
| `abandoned_session_count` | Count of **expired session rows deleted** in derivation cleanup (operational proxy, not UX “abandon”). |
| `qp_job_*` | `eko_sampa_quick_print_job_created`, `eko_sampa_quick_print_job_completed`, `eko_sampa_guest_qp_marked_abandoned`. |

## Rate limits

- Catalog view batching: per-client hourly cap before IDs are dropped from the queue (`queue_catalog_views`).
- Telemetry: existing `ingest_public_telemetry` hour bucket + per-event dedupe transients.

## Extension (future SaaS / marketplace)

- `eko_sampa_analytics_filter_event_context()` in `helpers-public-funnel.php` — filter `eko_sampa_analytics_event_context` for external sinks (Segment, BigQuery, tenant routing) **without** changing REST contracts.

## Non-goals

- No raw IPs, emails, or names in analytics options.
- No synchronous full-table scans for dashboards; admin tables use rollups + small live queries from existing stats.
