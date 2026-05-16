# Contrato: exclusão de serviços (SERVICE DELETE)

**Código:** `includes/helpers-service-delete.php` (`eko_sampa_safe_delete_service`, `eko_sampa_safe_delete_entity`), `includes/class-service-relations-inspector.php`, `includes/helpers-permission-contract.php`, `includes/class-service.php`, REST `DELETE /services/{id}`.

## Contrato (resumo)

- O delete **nunca** deve falhar sem código/mensagem/`debug` acionável (para utilizadores com permissão REST).
- **Ownership** validado com `get_row_by_id` + `can_actor_mutate_existing_row` (alinhado a `eko_sampa_services_actor_has_elevated_scope()` — ver [../security/permission-matrix.md](../security/permission-matrix.md)).
- **Dependências** inspeccionadas antes de mutar; modo `strict` permite bloquear em vez de unlink automático.
- **Órfãos / legado** — `repair_legacy` + integrity global (admin); o inspector expõe contagens e `visibility`.
- **Audit** — option `eko_sampa_service_delete_audit` + tabela em Diagnostics.
- **Repair controlado** — só passos documentados (`servico_id` → 0, unlink `service_id`, delete fields, delete row).

## Arquitetura de `eko_sampa_safe_delete_service()`

| Fase | Função | Notas |
|------|--------|--------|
| Opções | `apply_filters( 'eko_sampa_safe_delete_service_options', … )` | Extensível |
| Existência | `get_row_by_id` | Não confundir com `get()` |
| Mutação | `can_actor_mutate_existing_row` | Falha → `eko_sampa_delete_forbidden` |
| Inspect | `Eko_Sampa_Service_Relations_Inspector::inspect` | Inclui `visibility` (`exists_in_db`, `blocked_by_scope`, …) |
| Strict | `strict_block` + `linked_total` | `eko_sampa_delete_blocked_dependencies` |
| Repair legado | `eko_sampa_repair_service_relations` | Só colunas `servico_id` se existirem |
| Unlink | `UPDATE` templates/orders `service_id=0` | Desligável com `unlink_refs=0` |
| Fields | `delete_all_for_service` | Falha → `eko_sampa_delete_fields_failed` |
| Row | `delete_service_row_only` | Falha → `eko_sampa_delete_failed` + `wpdb` |
| Audit | `eko_sampa_append_service_delete_audit` | Sucesso e insucesso |

### `delete_readiness`

`(new Eko_Sampa_Service_Relations_Inspector())->delete_readiness( $id )` agrega snapshot + flag `has_blockers_for_strict_delete` (padrão `Eko_Sampa_Entity_Relations_Inspector`).

## Cenários

| Situação | Comportamento esperado |
|----------|-------------------------|
| Serviço sem templates/orders/fields | Unlink/repair noop; delete rápido |
| Serviço com templates/orders (`service_id` > 0) | Default: unlink depois delete; com `strict=1`: bloquear até desligar strict ou desvincular manualmente |
| Apenas legado `servico_id` | `repair_legacy` zera antes do resto |
| Row invisível por scope antigo | Hoje corrigido: mesmo actor que passa REST passa no model; inspector mostra `visibility.blocked_by_scope` se algum edge residual |
| `get()` null mas BD tem row | Usar `visibility` / `explain_row_visibility` — nunca inferir só de `get()` |

## Edge cases

- **`ensure_schema=1`**: custo por pedido; usar após migração/import.
- **Campos sem tabela `eko_sampa_fields`**: `delete_all_for_service` retorna cedo `true` (noop).
- **REST `debug`**: inclui `inspect`, `unlink_steps`, `repairs`, `failed_at`; `db_last_error` quando `WP_DEBUG` e erro MySQL.

## Filtro WordPress

`eko_sampa_safe_delete_service_options` — recebe o array de opções já fundido com os defaults (e o `service_id` alvo) antes da validação; permite testes ou políticas custom.

## Fluxo (`eko_sampa_safe_delete_service`)

1. Resolver opções (filtro `eko_sampa_safe_delete_service_options`).
2. Carregar row do serviço com `get_row_by_id` (existência).
3. `can_actor_mutate_existing_row` — alinhado ao REST via `eko_sampa_services_actor_has_elevated_scope()`.
4. `Eko_Sampa_Service_Relations_Inspector::inspect` — snapshot de dependências + `visibility`.
5. Se `strict_block` e existirem vínculos (`service_id` / legado `servico_id`): **bloquear** sem alterar linhas.
6. Se `repair_legacy`: limpar `servico_id` apontando para o id (templates/orders).
7. Se `unlink_refs`: `service_id = 0` em templates e orders que referenciam o serviço.
8. Apagar linhas em `eko_sampa_fields` para o `service_id`.
9. `DELETE` da linha em `eko_sampa_services`.

## REST — parâmetros de query

| Parâmetro | Default | Efeito |
|-----------|---------|--------|
| `strict` | off | Se `1`, bloqueia se ainda houver templates/orders (ou legado `servico_id`) ligados; não faz unlink automático nesse caso. |
| `unlink_refs` | `1` | Quando `0`, não desvia `service_id` em templates/orders antes do delete (útil com `strict` para diagnóstico). |
| `repair_legacy` | `1` | Quando `0`, não limpa colunas legadas `servico_id`. |
| `ensure_schema` | `0` | Quando `1`, chama `Eko_Sampa_Database::ensure_schema()` antes (custoso; usar após import SQL). |

## Ownership (services)

O gate REST e o `is_unrestricted()` do model partilham **`eko_sampa_services_actor_has_elevated_scope()`** (`helpers-permission-contract.php`). Ver matriz em [../security/permission-matrix.md](../security/permission-matrix.md).

## Integração

- Reutiliza `Eko_Sampa_Database`, `Eko_Sampa_Database_Integrity` (padrões de órfãos) sem duplicar `dbDelta`.
- Diagnostics: inspeção manual por id, audit de deletes, **permission consistency check**.
- Padrão multi-entidade (futuro): [../architecture/safe-delete-pattern.md](../architecture/safe-delete-pattern.md).

Ver também [services.md](services.md) e [../diagnostics/admin-tool.md](../diagnostics/admin-tool.md).
