# Quota system

Quotas are **advisory limits** for fair use: users can delete their own rows to free capacity. Enforcement returns structured `WP_Error` objects with HTTP 403 and hints (`quota`, `used`, `max` where applicable).

## Sources of truth

| Limit | User meta (override) | Fallback |
|-------|----------------------|----------|
| Templates | `eko_quota_max_templates` | `Eko_Sampa_Template_Derivation::max_saved_templates_per_user()` |
| Clients | `eko_quota_max_clients` | Option `eko_sampa_quota_default_max_clients` |
| Orders | `eko_quota_max_orders` | Option `eko_sampa_quota_default_max_orders` |
| Template JSON storage | `eko_quota_max_storage_mb` | Option `eko_sampa_quota_default_max_storage_mb` |
| Quick prints / hour | `eko_quota_max_quick_print_per_hour` | Option `eko_sampa_quota_default_max_qp_per_hour` |

Empty or deleted user meta means “inherit default”.

## Usage snapshots

`Eko_Sampa_Capabilities::get_usage_snapshot( $user_id )` returns:

- `templates_saved` — count of saved templates for the user (derivation-aware).
- `clients`, `orders` — simple scoped counts.
- `payload_bytes` — rough sum of `CHAR_LENGTH(json_data)` for owned templates (not full disk / thumbnails).

Results are cached **120 seconds** per user in a transient. Call `Eko_Sampa_Capabilities::invalidate_usage_cache( $user_id )` after administrative mutations (user admin actions already do this).

## Enforcement points

| Action | Guard |
|--------|--------|
| `POST /clients` | `check_client_quota_before_create` |
| `POST /templates` | `check_template_quota_before_create` + `check_storage_quota_for_template_payload` (payload size of incoming JSON) |
| `POST /orders` | `check_order_quota_before_create` |

Guest/public flows are unchanged. Administrators bypass quota checks inside the helper implementations.

## Filters

- `eko_sampa_effective_quotas_for_user` — adjust the computed map after defaults are merged.
- `eko_sampa_usage_snapshot_for_user` — decorate or replace snapshot rows (e.g. add team rollups later).
