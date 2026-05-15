# REST API — visão geral

**Namespace:** `eko-sampa/v1`  
**Base:** `/wp-json/eko-sampa/v1/`  
**Classe:** `includes/class-rest-api.php`

## Auth

- Sessão WordPress (cookie) no frontend
- Application Passwords suportadas (WP core)
- Permission callbacks por recurso (`require_orders_cap`, etc.)

## Limites

- Body mutating: máx. 524288 bytes (`MAX_JSON_BODY_BYTES`)
- Template `json_data`: máx. 393216 bytes

## Rotas (inventário)

| Método | Rota | Notas |
|--------|------|-------|
| GET | `/me` | user atual |
| GET | `/users` | admin filter |
| * | `/clients`, `/clients/{id}` | CRUD |
| * | `/services`, `/services/{id}` | CRUD |
| * | `/services/{id}/fields/...` | fields CRUD, reorder, check-slug |
| * | `/templates`, `/templates/{id}` | CRUD |
| POST | `/templates/{id}/duplicate` | |
| * | `/templates/{id}/thumbnail*` | pipeline imagem |
| GET | `/templates/{id}/placeholders` | |
| * | `/orders`, `/orders/{id}` | CRUD — ver [orders.md](orders.md) |
| POST | `/orders/render-draft` | |
| * | `/orders/{id}/duplicate`, `/render` | |
| GET | `/lookups/order-form` | |
| * | `/gallery`, `/gallery/{file}` | uploads user |

## Erros comuns

| code | HTTP |
|------|------|
| `eko_sampa_order_invalid_relations` | 400 |
| `eko_sampa_not_found` | 404 |
| `eko_sampa_request_too_large` | 413 |
| `eko_sampa_create_failed` | 400 |

## JS helper

`window.ekoSampaApi(path, { method, body })` — definido em assets enqueue (`class-assets.php`).

Documentação legada `docs/api.md` redireciona para aqui.
