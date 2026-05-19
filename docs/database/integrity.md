# Integridade de dados

**Classe:** `Eko_Sampa_Database_Integrity`  
**Não duplica** `dbDelta` — apenas valida e repara FKs lógicas.

## Relatório (`build_report`)

Campos principais:

| Campo | Conteúdo |
|-------|----------|
| `checked_at` | ISO8601 UTC |
| `db_version` / `stored_version` | código vs option |
| `plugin_version`, `wp_version`, `php_version` | ambiente |
| `tables` | bool por suffix |
| `counts` | COUNT(*) por tabela |
| `columns` | presença colunas críticas em templates/services/orders |
| `orphans` | listas de FKs quebradas |
| `repairs` | resultado do último repair no mesmo `run(true)` |
| `repair_history` | últimas entradas da option de histórico |

**Option:** `eko_sampa_integrity_last_report`

## Tipos de órfão

| Chave | Significado |
|-------|-------------|
| `templates_missing_service` | `COALESCE(service_id,servico_id) > 0` sem row em services |
| `templates_missing_client` | `client_id > 0` sem client |
| `orders_missing_service` | order.service_id inválido |
| `orders_missing_template` | order.template_id inválido |
| `orders_missing_client` | order.client_id inválido |
| `fields_missing_service` | field.service_id inválido |

**Bloqueador de create order:** `templates_missing_service` (e validação em `relations_validate`).

## Repair por serviço (legado)

Função `eko_sampa_repair_service_relations($service_id)` (`helpers-service-delete.php`): zera `servico_id` em templates/orders quando essa coluna ainda existe e apontava para o id — usada antes do delete seguro. Não substitui o repair batch de templates órfãos acima.

## Repair: `repair_orphan_template_services`

Para cada órfão:

1. Carrega template (`get_row_by_id` — sem scope owner no repair admin)
2. Se serviço antigo já existe → skip
3. `INSERT` novo service (`Recovered service (was #N)`)
4. `Template::update($id, ['service_id' => $new_id])`
5. Limpa column map cache

Histórico append em `eko_sampa_integrity_repair_history` (máx. 25 entradas).

## run($repair)

| Parâmetro | Efeito |
|-----------|--------|
| `false` | Só relatório (default em `ensure_schema`) |
| `true` | Repair template→service + rebuild report |

## REST auto-repair (limitado)

`Eko_Sampa_Rest_Api::maybe_repair_orphan_template_service_for_order`:

- Só se `failed_at === service_not_visible_for_order`
- E `service_exists === false`
- Repara **uma** linha (template_id + service_id do payload)
- Reexecuta `prepare_create_data` + `relations_validate`
- Debug pode incluir `repaired_orphan_service: true`

Não substitui repair em massa no admin.

## WP_DEBUG

Órfãos em check logam `[eko-sampa integrity] orphan template→service: ...`

Ver [../diagnostics/admin-tool.md](../diagnostics/admin-tool.md) e [../tutorials/integrity-and-repair.md](../tutorials/integrity-and-repair.md).
