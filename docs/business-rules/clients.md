# Regras de negócio: Clients

**Código:** `includes/class-client.php`.

## Ownership

- `user_id` owner em cada row.
- CRUD frontend `/eko-sampa_clients/` com mesmas regras de model base.

## Relação com templates e orders

- Template **pode** existir sem `client_id`.
- Order **pode** usar `client_id = 0` (anónimo).
- Se order `client_id > 0`, cliente deve ser visível ao actor (`client_not_visible`).
- Se template e order têm ambos `client_id > 0`, devem coincidir (`template_client_mismatch`).

## Integridade

- `templates_missing_client` / `orders_missing_client` no relatório integrity.
- Sem repair automático — corrigir manualmente ou limpar FK.

Contrato: [../architecture/domain-contracts.md](../architecture/domain-contracts.md).
