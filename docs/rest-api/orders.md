# REST: Orders

## POST `/orders` — create

**Handler:** `route_orders_create`

### Pipeline

1. `$params = $order->prepare_create_data($raw)`
2. `$relations = $order->relations_validate($params, null)`
3. Se `!ok` → `maybe_repair_orphan_template_service_for_order` → revalida
4. Se ainda `!ok` → `WP_Error` `eko_sampa_order_invalid_relations`
5. `$order->create($params)` → 201

### Response erro (400)

```json
{
  "code": "eko_sampa_order_invalid_relations",
  "message": "... [failed_at]",
  "data": {
    "status": 400,
    "failed_at": "service_not_visible_for_order",
    "debug": { ... }
  }
}
```

### Hint automático

Se `failed_at === service_not_visible_for_order` e `service_exists === false`, message inclui texto para abrir **Diagnostics**.

### Debug útil

- `template_service_id`
- `service_exists` vs `service_visible_in_scope` vs `service_visible_for_order`
- `repaired_orphan_service: true` após auto-repair

## GET/PATCH/DELETE `/orders/{id}`

- Ownership via `Eko_Sampa_Order::get`
- **`PUT/PATCH` em `completed`:** bloqueado — `eko_sampa_order_immutable` (409)
- **`DELETE`:** `eko_sampa_safe_delete_order()` — remove snapshot em `completed-orders/order-{id}/` quando existir
- **`order_title`:** opcional em create/update; ver [../schema/orders-schema.md](../schema/orders-schema.md)

## GET `/orders` (listagem)

- Query `s`: se o valor for **apenas dígitos**, filtra por `id`; caso contrário, `LIKE` em `order_title`.
- `orderby` pode incluir `order_title` (whitelist no modelo).

## POST `/orders/{id}/duplicate-revision`

- Só para OS `completed`; caso contrário `eko_sampa_duplicate_revision_failed` (400)
- Equivalente a novo ciclo: nova linha `pending`, `woo_order_id` zerado; `order_title` com sufixo ` (Copy)` (truncado a 255 caracteres)

## POST `/orders/{id}/duplicate`

- Resposta 201 com linha enriquecida (`enrich_row_for_api`); novo `order_title` com sufixo ` (Copy)`.

## POST `/orders/{id}/render`

- Se `completed` + snapshot válido → `render_source: completed_snapshot`
- Caso contrário → `live_template` ou `live_template_pre_snapshot_fallback`
- Campo **`operational_meta`**: `{ order_id, order_title, status }` — só para cabeçalho operacional na UI; não faz parte do payload do canvas. Na rota **`/eko-sampa_print/`**, o mesmo tipo de metadado no HTML **não** é enviado para papel/PDF — ver [../architecture/print-isolation.md](../architecture/print-isolation.md).

Ver [../tutorials/create-order-step-by-step.md](../tutorials/create-order-step-by-step.md) e [../storage/storage-architecture.md](../storage/storage-architecture.md).
