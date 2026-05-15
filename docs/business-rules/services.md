# Regras de negócio: Services

**Código:** `includes/class-service.php`, `includes/class-service-field.php`.

## Ownership

- `user_id` no create (owner).
- `is_global = 1` → visível na listagem para outros utilizadores (regras em `get` / list SQL).

## Relação com templates

- Template guarda `service_id` (FK lógica).
- Delete de serviço passa por `eko_sampa_safe_delete_service()` (unlink de `service_id` em templates/orders, repair opcional de `servico_id`, delete de fields, audit).
- Modo **strict** (`DELETE ...?strict=1`): bloqueia se ainda houver vínculos em vez de desviar automaticamente.
- Contrato detalhado: [service-delete.md](service-delete.md).
- Integrity detecta órfãos globais; repair em massa continua no admin Diagnostics.

## Fields

- Pertencem a `service_id`.
- REST aninhado: `/services/{id}/fields`, reorder, check-slug.
- Órfão `fields_missing_service` no integrity report.

## Existência vs visibilidade

| Pergunta | Método |
|----------|--------|
| Utilizador vê na API list/get? | `Eko_Sampa_Service::get($id)` |
| Row existe na BD? | `Eko_Sampa_Database::row_exists('eko_sampa_services', $id)` |
| Pode usar em create order com template owned? | `Eko_Sampa_Order::service_visible_for_order()` |

**Regressão comum:** usar só `get()` em validação de order — falha quando serviço existe mas está fora do scope (cenário raro; órfão é o caso frequente com `get()` null e `row_exists` false).

## service_visible_for_order()

Documentado em [orders.md](orders.md) e [../services/visibility.md](../services/visibility.md).

## Detecção de órfãos

- Template aponta service inexistente → integrity + repair.
- Order aponta service inexistente → listado em `orders_missing_service` (sem auto-repair padrão).
