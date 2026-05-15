# Regras de negócio: Orders

**Código:** `includes/class-order.php`, `route_orders_create` em `class-rest-api.php`.

## Criação manual

- Form order (`orders` CRUD) pode criar sem template.
- `relations_validate` aplica regras de visibilidade para cada FK presente.

## Criação via template

1. UI: `createOrderFromTemplate(row)` → `buildCreateOrderPayload` → `POST /orders`
2. Backend: `prepare_create_data` → `relations_validate` → `create`

Payload típico do frontend:

```json
{
  "client_id": 0,
  "template_id": 3,
  "service_id": 12,
  "status": "pending",
  "dynamic_data_json": {}
}
```

`service_id` no payload é **sobrescrito** pelo valor do template em DB se o template tiver `service_id > 0`.

## client_id = 0 (anónimo)

- Válido e intencional.
- Se chave `client_id` existe no payload (mesmo 0), **não** herda do template.
- Herança só quando `client_id` **omitido** e template tem `client_id > 0`.

## Inferência de service_id

Ordem em `prepare_create_data`:

1. `resolve_order_relations_from_template` — lê template visível, copia `service_id` / `servico_id`
2. `inherit_client_from_template`

## Validações (`relations_validate`)

Ordem de falha:

1. `client_id > 0` → cliente visível
2. `template_id > 0` → template visível; atualiza `service_id` debug
3. template com serviço obrigatório → `service_missing_for_template` se 0
4. `service_id > 0` → `service_exists` (row_exists), `service_visible_for_order`
5. mismatch cliente template vs order (ambos > 0)

## service_visible_for_order()

Verdadeiro se:

- `Service::get($id)` OK, **ou**
- template visível + `get_row_by_id` service, **ou**
- template visível + `row_exists('eko_sampa_services', $id)`

Separa **existência** de **listagem scoped**.

## Fallback / repair

Se falha `service_not_visible_for_order` e `service_exists === false`:

- REST tenta `repair_orphan_template_services` para esse template
- Revalida; debug pode ter `repaired_orphan_service: true`

## Visibilidade listagem

- `Eko_Sampa_Order::ownership_predicate()` — mesmo padrão que outras entidades.
- Admin vê todas.

## Erro REST

- Código: `eko_sampa_order_invalid_relations` (400)
- Campos: `failed_at`, `debug`

Ver [../troubleshooting/create-order-400.md](../troubleshooting/create-order-400.md).
