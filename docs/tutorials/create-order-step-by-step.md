# Tutorial: Create order from template

Fluxo completo com classes e pontos de falha.

## 1. Utilizador clica no botão

- **View:** `views/crud/templates-detail.php` (ou edit) — botão com `@click="createOrderFromTemplate(...)"`
- **Capacidade:** `order.create` via `canOrderCreate()` / `ekoSampaCan`
- **Alpine:** factory `templateCrud` em `assets/js/frontend-app.js`

## 2. Frontend — debounce e loading

`createOrderFromTemplate(row)`:

- Guard `_createOrderBusy` evita double-click
- Toast loading `eko-create-order-{id}`

## 3. Frontend — GET template atualizado

`buildCreateOrderPayload(row)`:

```
GET /wp-json/eko-sampa/v1/templates/{tid}
```

- **Por quê:** row da lista pode estar stale; backend usa DB.
- Normaliza: `normalizeTemplateRow(template)`
- Atualiza `state.record` (view) ou `state.form` (edit)

**Falha A:** GET 404/403 → toast, `return null`

## 4. Frontend — pré-validação service_id

```javascript
const sid = parseInt(String(t.service_id || 0), 10);
if (!sid) { /* toast: link a service */ return null; }
```

**Falha B:** template sem serviço — não chega ao POST

## 5. Frontend — monta payload

```javascript
{
  client_id: 0,
  template_id: tid,
  service_id: sid,
  status: 'pending',
  dynamic_data_json: {},
}
```

## 6. Frontend — POST order

```
POST /wp-json/eko-sampa/v1/orders
```

Helper: `window.ekoSampaApi('orders', { method: 'POST', body: payload })`

Opcional: `order_title` — rótulo operacional da OS (não é o nome do template); ver [../schema/orders-schema.md](../schema/orders-schema.md).

## 7. REST — prepare_create_data

`Eko_Sampa_Rest_Api::route_orders_create`:

```php
$params = $order->prepare_create_data($raw);
```

### 7a. resolve_order_relations_from_template

- `Eko_Sampa_Template::get($template_id)` — **com ownership**
- Sobrescreve `service_id` com valor do template (`service_id` ou `servico_id`)

### 7b. inherit_client_from_template

- Se `client_id` já no array (incl. 0) → não altera
- Se omitido e template tem cliente → copia

## 8. REST — relations_validate

`Eko_Sampa_Order::relations_validate($params, null)`

Popula `debug`: `template_exists`, `template_visible`, `service_exists`, `service_visible_for_order`, `failed_at`.

**Falha C:** `template_not_visible`  
**Falha D:** `service_missing_for_template`  
**Falha E:** `service_not_visible_for_order` + `service_exists: false` → órfão

## 9. REST — repair opcional

`maybe_repair_orphan_template_service_for_order`:

- Só falha E com exists false
- `Eko_Sampa_Database_Integrity::repair_orphan_template_services([...])`
- Reexecuta prepare + validate

**Falha F:** repair não aplicável ou insert falhou → 400 com hint Diagnostics

## 10. REST — create

`$order->create($params)` → 201 + body `get($id)`

## 11. Frontend — sucesso

- Toast success
- Redirect opcional para `/eko-sampa_orders/{id}/` (ver código após create)

---

## Tabela de diagnóstico rápido

| failed_at | Ação |
|-----------|------|
| template_not_visible | Permissão / ID errado |
| service_missing_for_template | Associar service no edit |
| service_not_visible_for_order + !service_exists | Diagnostics → Repair |
| template_client_mismatch | Alinhar client_id ou usar 0 explícito |
| client_not_visible | Escolher cliente do user |

---

## Ficheiros para grep

| Ficheiro | Símbolo |
|----------|---------|
| `frontend-app.js` | `createOrderFromTemplate`, `buildCreateOrderPayload` |
| `class-order.php` | `prepare_create_data`, `relations_validate` |
| `class-rest-api.php` | `route_orders_create`, `maybe_repair_orphan_*` |
| `class-database-integrity.php` | `repair_orphan_template_services` |
