# Migrações de schema

**Constante:** `EKO_SAMPA_DB_VERSION` em `eko-sampa.php` (atual: **1.0.12**)  
**Option WP:** `eko_sampa_db_version`  
**Mapa:** `Eko_Sampa_Database::migration_callbacks()`

## Versões

| Versão | Método | O que faz |
|--------|--------|-----------|
| 1.0.0 | `migrate_to_1_0_0` | Baseline `dbDelta` — 6 tabelas core |
| 1.0.1 | `migrate_to_1_0_1` | Coluna `categoria` em templates |
| 1.0.2 | `migrate_to_1_0_2` | Alinhamento PT/EN colunas (`nome`, drops de `name`, etc.) |
| 1.0.3 | `migrate_to_1_0_3` | Evoluções orders (ex. snapshot fields) |
| 1.0.4 | `migrate_to_1_0_4` | `client_id` em templates + cópia de `cliente_id` |
| 1.0.5 | `migrate_to_1_0_5` | `run_schema_alignment()` + `integrity->run(false)` snapshot |
| 1.0.6 | `migrate_to_1_0_6` | Híbrido `eko_sampa_templates`: `ALTER … MODIFY` com `DEFAULT` em colunas legadas inglesas (`title`, `width`, `height`, `background_color`, `created_at`) + backfill `title` ← `nome` onde vazio |
| 1.0.7 | `migrate_to_1_0_7` | Coluna opcional `order_title` em `eko_sampa_orders` (rótulo operacional; ver [../schema/orders-schema.md](../schema/orders-schema.md)) |
| 1.0.8 | `migrate_to_1_0_8` | Índice `eko_sampa_orders_order_title` em `order_title(191)` (listagem / prefix `LIKE`) |
| 1.0.9 | `migrate_to_1_0_9` | Templates: tier de captura de thumbnail (colunas de metadados) |
| 1.0.10 | `migrate_to_1_0_10` | Tabela `eko_sampa_quick_print_jobs` |
| 1.0.11 | `migrate_to_1_0_11` | Templates: derivação (`template_type`, `parent_template_id`, `session_token`, `expires_at`, `last_activity_at`, `saved_from_session_id`, `allow_personalization`, `is_public`) + índice cleanup; quick print: `session_token`; backfill `template_type` vazio → `user` |
| 1.0.12 | `migrate_to_1_0_12` | Templates: `session_fingerprint`, `session_lifecycle`, `is_public_catalog`, `is_user_shareable`, `is_marketplace_item` + backfill catálogo; quick print: `expires_at`, `abandoned_at` |

## Comportamento em upgrade 1.0.4 → 1.0.5

1. `migrate()` executa só callbacks com `version > installed`
2. 1.0.5 roda alignment + integrity report (sem auto-repair)
3. Em **todo** request subsequente, `ensure_schema()` ainda corre alignment + integrity check

## Schema drift

Sintoma: option diz 1.0.4+ mas coluna falta.  
Mitigação: `run_schema_alignment()` em cada `ensure_schema`, não só na migração.

## Upgrade 1.0.6 (templates híbrido / legado)

Corrige instalações onde colunas inglesas antigas (`title`, `width`, …) permanecem `NOT NULL` **sem** `DEFAULT`, bloqueando `INSERT` do contrato PT. Não remove colunas. Ver [../schema/templates-schema.md](../schema/templates-schema.md).

## bin/eko-sampa-repair-db.php

Script WP-CLI-like: força `ensure_schema()` — útil pós-deploy.
