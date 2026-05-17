# Schema alignment — evitar drift estrutural

## Problema

Quando a tabela física `wp_eko_sampa_templates` mistura:

- colunas do **contrato canónico** (PT + `json_data`, `width_mm`, …), e
- colunas **legadas** (`title`, `width`, …) ainda `NOT NULL` **sem** `DEFAULT`,

os `INSERT` modernos (duplicação, create) falham **antes** do MySQL com diagnóstico `missing_required_column` — isto é **falha de integridade de schema**, não um bug de negócio isolado.

## Estratégia em duas camadas (ordem intencional)

1. **DDL (preferida, persistente):** `EKO_SAMPA_DB_VERSION` `1.0.6` — `Eko_Sampa_Database::repair_templates_legacy_hybrid_relaxed_defaults()` aplica `DEFAULT` explícitos às colunas legadas conhecidas e backfill controlado de `title` ← `nome`. Idempotente.
2. **INSERT bridge (transitório, audível):** `Eko_Sampa_Template_Legacy_Row_Bridge` garante que `prepare_create_row` produz uma linha compatível **mesmo** antes do DDL correr, registando `schema_integrity_bridge` para auditoria e API.

Nenhuma das camadas remove colunas nem altera ownership.

## Pontos de extensão

| Componente | Função |
|------------|--------|
| `class-template-schema-contract.php` | Listas canónica vs legada |
| `class-template-schema-diagnostics.php` | `analyze()` — score e bloqueios |
| `class-template-legacy-row-bridge.php` | Política por coluna + `bridged_legacy_keys()` |
| `class-template-schema-repair.php` | `preview()` / `run_relaxed_defaults()` (admin) |
| `class-database.php` | `migrate_to_1_0_6`, preview/repair SQL |
| `class-template-insert-diagnostics.php` | `load_schema()` público para leitura partilhada |

## Como evitar novo drift

- Bump `EKO_SAMPA_DB_VERSION` quando alterar colunas obrigatórias.
- Manter `schema_align_templates()` e dbDelta alinhados com `CANONICAL_COLUMNS`.
- Após import SQL manual, correr Diagnostics + *Simulate template schema repair*.
- Não adicionar colunas `NOT NULL` sem `DEFAULT` sem actualizar o contrato e o bridge/repair.

Ver também [../schema/templates-schema.md](../schema/templates-schema.md).

---

## Orders: `order_title` (1.0.7)

Coluna **opcional** e **operacional** (`varchar(255) NULL`), alinhada via `schema_align_orders` / migração `1.0.7`. Não participa do contrato de render (`template_render_context`). Ver [../schema/orders-schema.md](../schema/orders-schema.md).
