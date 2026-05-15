# Migrações de schema

**Constante:** `EKO_SAMPA_DB_VERSION` em `eko-sampa.php` (atual: **1.0.5**)  
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

## Comportamento em upgrade 1.0.4 → 1.0.5

1. `migrate()` executa só callbacks com `version > installed`
2. 1.0.5 roda alignment + integrity report (sem auto-repair)
3. Em **todo** request subsequente, `ensure_schema()` ainda corre alignment + integrity check

## Schema drift

Sintoma: option diz 1.0.4+ mas coluna falta.  
Mitigação: `run_schema_alignment()` em cada `ensure_schema`, não só na migração.

## Adicionar 1.0.6 (processo)

1. Bump `EKO_SAMPA_DB_VERSION` em `eko-sampa.php`
2. Adicionar `'1.0.6' => [$this, 'migrate_to_1_0_6']` no mapa
3. Implementar `migrate_to_1_0_6` (preferir dbDelta + alignment helper)
4. Atualizar este ficheiro + `schema.md`
5. Se FK nova, estender `class-database-integrity.php`

## bin/eko-sampa-repair-db.php

Script WP-CLI-like: força `ensure_schema()` — útil pós-deploy.
