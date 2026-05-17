# Ciclo de vida — duplicar template

## Ordem das operações

1. **Autorização:** `Eko_Sampa_Template::get($id)` — 404 se o modelo não for visível (ownership / admin).
2. **Payload:** `Eko_Sampa_Template::build_duplicate_create_data($id)` — nome truncado para caber em `varchar(255)` com sufixo traduzido ` (Copy)` (evita overflow de coluna).
3. **Preparação + simulação:** `prepare_create_row()` + `Eko_Sampa_Template_Insert_Diagnostics::simulate_insert_validation()` — valida tabela/colunas, `max_allowed_packet`, `sql_mode`, UTF-8, tamanhos e colunas inesperadas **antes** do `INSERT`. Se `blocking`, `try_create` falha com `failure_reason` semântico e `insert_diagnostics` (REST).
4. **Insert:** `wpdb->insert()` — em falha: `mysql_errno`, `sql_state`, `offending_column` (quando dedutível), `classify_mysql_error` + `insert_diagnostics.post_insert_classify`.
5. **Thumbnail:** `Eko_Sampa_Template_Thumbnail::copy($from, $to)`:
   - Sem ficheiro legível no origem: **sucesso** (no-op), audit `duplicate_thumbnail_source_missing` / `duplicate_thumbnail_source_unreadable`; resposta `201` com aviso estruturado.
   - Erro na cópia: **a linha nova mantém-se** — não há rollback destrutivo; `201` com `duplicate_warnings.thumbnail_copy_failed` (código + mensagem) + audit `duplicate_thumbnail_copy_failed`. Regeneração posterior via rotas `/thumbnail*`.
6. **Resposta:** `201` com `enrich_row`.

## Diagnóstico

| Query / rota | Conteúdo |
|-------|-----------|
| `GET …/templates/{id}?inspect_duplicate=1` | `duplicate_inspect` (thumbnail/preview readiness curto) |
| `GET …/templates/{id}?inspect_duplicate=deep` | `duplicate_inspect_deep` — `Eko_Sampa_Template_Duplicate_Diagnostics::deep()` (schema, `try_create` dry-run, `insert_diagnostics`, payload preview truncado, candidatos a falha) |
| `GET …/templates/{id}/duplicate-diagnostics` | `duplicate_diagnostics_bundle`: `duplicate_inspect_deep`, `visual_drift`, `final_row_meta`, `readiness_score`, `actionable_repairs` |

## Erros REST (insert)

| Código | `failure_reason` típico | Dados extra |
|--------|-------------------------|-------------|
| `eko_sampa_duplicate_source_not_found` | `source_not_found` | — |
| `eko_sampa_duplicate_failed` | `nome_exceeds_column_limit`, `json_exceeds_packet_limit`, `utf8mb4_encoding_failure`, `unknown_column_after_filter`, `wpdb_insert_rejected`, `preinsert_validation_failed`, … | `duplicate_try`, `db_last_error`, `mysql_errno`, `sql_state`, `offending_column`, `insert_diagnostics` |

## Audit / logs

- Falha de insert: `Eko_Sampa_Storage_Audit::append('template_duplicate_insert_failed', …)` (+ `error_log` se `EKO_SAMPA_DEBUG`).

## Ficheiros

- `includes/class-template.php` — `build_duplicate_create_data()`, `prepare_create_row()`, `try_create()`, `duplicate()`
- `includes/class-template-insert-diagnostics.php` — `simulate_insert_validation()`, `classify_mysql_error()`
- `includes/class-template-duplicate-diagnostics.php` — `deep()`, `duplicate_diagnostics_bundle()`
- `includes/class-visual-drift-diagnostics.php` — heurísticas servidor (JPEG vs contrato canónico, aspect ratio de imagens no JSON)
- `includes/class-template-thumbnail.php` — `copy()`, `inspect_duplicate_readiness()`
- `includes/class-rest-api.php` — `route_templates_duplicate`, `route_templates_duplicate_diagnostics`, `route_templates_get`
- `includes/class-model-base.php` — allowlist de fallback para colunas `eko_sampa_templates` quando `SHOW COLUMNS` falha
