# Padrão: safe delete por entidade

## Estado actual

| Entrada | Implementação | Inspector base |
|---------|----------------|----------------|
| `service` / `services` | `eko_sampa_safe_delete_service()` | `Eko_Sampa_Service_Relations_Inspector` |
| `template` / `templates` | `eko_sampa_safe_delete_template()` | `Eko_Sampa_Template_Relations_Inspector` |
| `order` / `orders` | `eko_sampa_safe_delete_order()` | `Eko_Sampa_Order_Relations_Inspector` |
| `client` / `clients` | (inspector only: `Eko_Sampa_Client_Relations_Inspector`; REST delete unchanged) | `Eko_Sampa_Client_Relations_Inspector` |

## Contrato alvo (futuro comum)

Para cada entidade mutável:

1. **inspect** — `Entity_Relations_Inspector::inspect()` + `delete_readiness()`.
2. **validate** — ownership / política de negócio.
3. **repair** — opcional, só alterações idempotentes e auditáveis (legado, órfãos controlados).
4. **unlink** — desreferenciar FKs lógicas quando a política o permitir (default documentado).
5. **audit** — option ou log estruturado (quem, quando, o quê).
6. **delete** — SQL final só após passos anteriores bem-sucedidos.

## Dispatch

`eko_sampa_safe_delete_entity( string $entity, int $id, array $options )` em `helpers-service-delete.php` centraliza o `match` (inclui `template` / `order`); helpers dedicados em `helpers-entity-storage-delete.php`.

## Serviços (referência)

Fluxo completo e query params REST: [../business-rules/service-delete.md](../business-rules/service-delete.md).
