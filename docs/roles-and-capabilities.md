# Roles and capabilities

## Eko roles (WordPress)

| Role slug | Label (i18n) | Intent |
|-----------|--------------|--------|
| `eko_manager` | Eko Manager | Platform admin inside Eko: users, quotas, templates/clients/orders/services, analytics admin slice. |
| `eko_designer` | Eko Designer | Template + service + client authoring; orders for own operational workflow. |
| `eko_operator` | Eko Operator | Orders + read-only template visibility + quick print when a template is visible to the account. |

`administrator` is **not** replaced: the role receives all Eko caps on sync for full control.

## Capability reference

| Capability | Typical grant |
|------------|----------------|
| `access_eko_dashboard` | All three Eko roles (+ `customer` when bridged). |
| `manage_eko_platform` | Manager — top-level **Eko Sampa** menu and public/guest admin surfaces. |
| `manage_eko_users` | Manager — **Users** submenu + REST `GET /users`. |
| `manage_eko_quotas` | Manager — reset per-user quota overrides on the detail screen / profile fields. |
| `view_eko_analytics_admin` | Manager — lightweight user analytics strip on the Users list. |
| `view_eko_templates` | Operator — `GET /templates`, template `GET` by id (visibility still enforced in models), frontend template list (not editor). |
| `manage_eko_templates` | Manager, Designer — writes, editor, gallery, duplicates. |
| `manage_eko_clients` | Manager, Designer |
| `manage_eko_services` | Manager, Designer |
| `manage_eko_orders` | All three (operators fulfil print / status flows). |

## Where checks live

- **REST** — `permission_callback` on each route; global `Eko_Sampa_Capabilities::rest_pre_dispatch_gate` for operational status.
- **WP Admin** — `add_menu_page` / `add_submenu_page` capability arguments + `Eko_Sampa_Capabilities::can_*` helpers in render callbacks.
- **Frontend routes** — `Eko_Sampa_Frontend_Router::viewer_can_access()` + `crud_write_policy_block_reason()`.
- **Alpine / JS** — `eko_sampa_frontend_capabilities()` → `window.ekoSampaRest.capabilities` (derived from `Eko_Sampa_Capabilities::frontend_capability_map()`).

## Diagnostics

**Eko Sampa → Diagnostics** remains `manage_options` only so operators and managers cannot touch low-level repair tools.
