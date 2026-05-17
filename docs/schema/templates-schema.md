# Templates table — canonical vs legacy (schema contract)

**Código:** `includes/class-template-schema-contract.php`, `includes/class-template-schema-diagnostics.php`, `includes/class-template-legacy-row-bridge.php`, `includes/class-template-schema-repair.php`, `includes/class-database.php` (migração `1.0.6`).

## Contrato canónico (domínio actual)

Colunas que o modelo PHP / REST tratam como **fonte de verdade** para criação, duplicação e listagens:

- Identidade / ownership: `user_id`, `client_id`
- Catálogo: `product_id`, `service_id`
- Conteúdo PT: `nome`, `categoria`, `descricao`
- Layout: `width_mm`, `height_mm`, `preview_image`, `json_data`
- Thumbnail metadados: `thumbnail_version`, `thumbnail_visual_hash`
- Auditoria: `created_at`, `updated_at` (quando presentes no schema dbDelta)

Lista literal: ver `Eko_Sampa_Template_Schema_Contract::CANONICAL_COLUMNS`.

## Colunas legadas (inglês / canvas antigo)

Instalações híbridas podem ainda ter, **em paralelo** com o contrato PT:

`title`, `content`, `width`, `height`, `background_color`, `thumbnail`

— listadas em `Eko_Sampa_Template_Schema_Contract::LEGACY_ENGLISH_COLUMNS`.

Estas colunas **não** fazem parte do contrato de negócio actual; são **resíduo estrutural** até migração/ALTER alinharem defaults ou forem removidas com plano de dados.

## Política de compatibilidade (sem “fallback silencioso”)

1. **INSERT bridge (runtime):** `Eko_Sampa_Template_Legacy_Row_Bridge::augment()` só preenche colunas listadas em política explícita (`POLICY`), quando `SHOW FULL COLUMNS` indica `NOT NULL` **sem** `DEFAULT` e a chave **não** veio no payload. Cada valor aplicado devolve entrada em `schema_integrity_bridge` (REST / `try_create` / diagnostics).
2. **Repair (DDL):** migração `1.0.6` + botão Diagnostics aplicam `ALTER TABLE … MODIFY … DEFAULT …` documentados — não removem colunas nem dados.
3. **Backfill opcional:** após relaxar `title`, `UPDATE … SET title = nome WHERE title vazio` alinha dados legíveis sem tocar em `nome`.

## Onde diagnosticar

- `Eko_Sampa_Template_Schema_Diagnostics::analyze()` — `schema_drift_score`, `blocking_columns`, `orphan_legacy_columns`, `actionable_repairs`.
- Admin: **Eko Sampa → Diagnostics** — secção *Template schema integrity*.
- REST: `GET …/templates/{id}/duplicate-diagnostics` inclui `template_schema_integrity` (via bundle / deep).

## Relacionado

- [../architecture/schema-alignment.md](../architecture/schema-alignment.md)
- [../business-rules/templates.md](../business-rules/templates.md)
- [../templates/duplicate-lifecycle.md](../templates/duplicate-lifecycle.md)
