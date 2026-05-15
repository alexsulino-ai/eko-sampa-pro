# Service: existência vs visibilidade

## Três níveis no código

### 1. Listagem / GET scoped

`Eko_Sampa_Service::get($id)`:

- Row do owner (`user_id` = current)
- Ou `is_global = 1`
- Admin unrestricted vê tudo

Usado em UI de seleção de serviços.

### 2. Existência na BD

`Eko_Sampa_Database::row_exists('eko_sampa_services', $id)`:

- Sem filtro `user_id`
- Usado em `relations_validate` → `service_exists`

### 3. Válido para create order

`Eko_Sampa_Order::service_visible_for_order($service_id, $template_id, $template_row)`:

1. `Service::get` OK → true  
2. Senão, template visível + `get_row_by_id(service)` → true  
3. Senão, template visível + `row_exists(services, id)` → true  
4. Caso contrário → false (`service_not_visible_for_order`)

## Por que existe o nível 3?

Permite order a partir de template **owned** quando o serviço existe fisicamente mas não apareceria na listagem scoped (edge case). O caso crítico corrigido foi **órfão** (`row_exists` false) — aí repair ou erro.

## Diagrama

```
                    ┌─────────────────┐
                    │  service_id     │
                    └────────┬────────┘
                             │
         ┌───────────────────┼───────────────────┐
         ▼                   ▼                   ▼
   Service::get()      get_row_by_id()     row_exists()
   (visibility)        (admin/internal)    (integrity)
```

Referência: `includes/class-order.php` métodos privados acima.
