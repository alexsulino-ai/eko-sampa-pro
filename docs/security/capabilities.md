# Capabilities e permissões

## WordPress roles

`includes/class-roles.php` — capabilities custom no activation:

- `eko_sampa_manage_clients`
- `eko_sampa_manage_services`
- `eko_sampa_manage_templates`
- `eko_sampa_manage_orders`

## Mapa frontend

`includes/helpers-capabilities.php` → `eko_sampa_frontend_capabilities()`

| Ability | Cap WP |
|---------|--------|
| `client.*` | `CAP_MANAGE_CLIENTS` ou admin |
| `service.*` | `CAP_MANAGE_SERVICES` ou admin |
| `template.*` | `CAP_MANAGE_TEMPLATES` ou admin |
| `order.create`, `order.view`, … | `CAP_MANAGE_ORDERS` ou admin |

`manage_options` → todas true.

## JS

- Localized em `ekoSampaRest.capabilities`
- `window.ekoSampaCan(ability)` — wrapper no frontend bundle
- PHP views: `eko_sampa_user_can()` / `eko_sampa_alpine_can_expr()`

## REST

Permission callbacks em `class-rest-api.php` — espelham roles (não enviar só capabilities do JS).

## Admin Diagnostics

`current_user_can('manage_options')` — apenas administradores WP.

## Create order

Requer `order.create` na UI **e** `require_orders_cap` no REST **e** `relations_validate` (ownership das FKs).
