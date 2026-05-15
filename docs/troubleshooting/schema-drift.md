# Troubleshooting: Schema drift

## Sintoma

- SQL error: Unknown column `client_id` / `service_id`
- REST 400 em template save
- `eko_sampa_db_version` alto mas colunas em falta

## Causa

Deploy parcial, restore DB antigo, ou option atualizada sem `dbDelta` completo.

## Fix

1. Atualizar plugin para versão com `EKO_SAMPA_DB_VERSION` atual
2. Carregar qualquer página WP (dispara `ensure_schema`)
3. **Diagnostics → Run integrity check**
4. Verificar `columns.eko_sampa_templates` no raw report — `client_id: true`

## CLI

```bash
php bin/eko-sampa-repair-db.php
```

## Verificação SQL manual

```sql
SHOW COLUMNS FROM wp_eko_sampa_templates LIKE 'client_id';
SELECT option_value FROM wp_options WHERE option_name = 'eko_sampa_db_version';
```

## Legado servico_id

Se dados só em `servico_id`:

- `run_schema_alignment` copia para `service_id`
- Não apagar legado antes da cópia

## Prevenção

- Sempre deploy código + visit admin após migrate
- Documentar bump em `docs/database/migrations.md`
- Seguir [../MAINTENANCE.md](../MAINTENANCE.md)
