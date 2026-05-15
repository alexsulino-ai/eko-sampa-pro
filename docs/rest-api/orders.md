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
- PATCH não documenta aqui validação extra — ver `update()` no model

## POST `/orders/{id}/render`

- Render print — `class-template-renderer.php`

Ver [../tutorials/create-order-step-by-step.md](../tutorials/create-order-step-by-step.md).
