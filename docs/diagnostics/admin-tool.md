# Ferramenta de Diagnostics (admin)

## Acesso

**WP Admin → Eko Sampa → Diagnostics**

- **Classe:** `Eko_Sampa_Router::render_diagnostics_page()`
- **View:** `views/admin-diagnostics.php`
- **Permissão:** `manage_options`

## Ações

| Botão | POST action | Efeito |
|-------|-------------|--------|
| Run integrity check | `run_check` | `ensure_schema()` + `integrity->run(false)` |
| Repair orphan template services | `repair_orphans` | `ensure_schema()` + `integrity->run(true)` |
| Inspect service (delete readiness) | `inspect_service_delete` | `Eko_Sampa_Service_Relations_Inspector::inspect(service_id)` (POST campo `service_id`) |
| Storage integrity report (dry-run) | `storage_integrity_report` | `Eko_Sampa_Storage_Manager::build_storage_integrity_report()` — só leitura |
| Simulate template schema repair | `template_schema_simulate` | `Eko_Sampa_Template_Schema_Diagnostics::analyze()` + preview SQL (`ALTER … MODIFY` + backfill `title`) — **sem escrita** |
| Run template schema repair | `template_schema_repair` | `Eko_Sampa_Template_Schema_Repair::run_relaxed_defaults()` — aplica DDL + backfill; confirmação JS; audit `template_schema_legacy_defaults_relaxed` |

Nonce: `eko_sampa_integrity`

## Relatório

- Option: `eko_sampa_integrity_last_report`
- Mostra: timestamps, versões, contadores de órfãos, tabela template→service, **repair history**, JSON raw
- **`orders_operational_title`:** presença da coluna `order_title`, `rows_without_title`, `readiness` (`ok` | `migration_required`) — ver [../schema/orders-schema.md](../schema/orders-schema.md)
- **Service delete:** snapshot JSON por id + option `eko_sampa_service_delete_audit` (últimas tentativas de DELETE via API / modelo)
- **Template schema (SQL):** análise estrutural + preview/repair de colunas legadas `NOT NULL` sem `DEFAULT` — ver [../schema/templates-schema.md](../schema/templates-schema.md)

## Histórico de repairs

- Option: `eko_sampa_integrity_repair_history`
- Até 25 entradas: `at`, `kind`, `count`, `items`, `by`
- Preenchido após repair batch com fixes

## Quando usar

- Após deploy de código DB 1.0.4+
- Após deploy de **`order_title`** (DB 1.0.7+): confirmar `orders_operational_title.readiness === ok` no relatório
- Após import/migração SQL manual
- Quando create order retorna `service_exists: false`
- Rotina preventiva pós-delete de services
- **Duplicar template:** com a REST autenticada, `GET /wp-json/eko-sampa/v1/templates/{id}/duplicate-diagnostics` (nonce/cookie admin) devolve `readiness_score`, `insert_diagnostics`, `visual_drift` e `actionable_repairs` sem criar linha

## Não faz

- Não repara orders órfãs automaticamente
- Não substitui backup/restore
- Não altera ownership de templates

Ver [../database/integrity.md](../database/integrity.md).
