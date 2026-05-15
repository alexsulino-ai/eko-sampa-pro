# Tutorial: Integridade e repair

## Quando corre automaticamente

| Evento | Repair? |
|--------|---------|
| Plugin init `ensure_schema()` | Não — só `integrity->run(false)` |
| Admin “Run integrity check” | Não |
| Admin “Repair orphan template services” | Sim — batch órfãos template→service |
| `POST /orders` com serviço órfão | Sim — **uma** linha, depois revalida |

## Passo a passo: verificar produção

1. WP Admin → **Eko Sampa → Diagnostics**
2. Confirmar `stored_version` = `1.0.5` (ou código atual)
3. Ver contador **Orphan template→service**
4. Se > 0: clicar **Repair orphan template services**
5. Confirmar contador 0 e histórico em **Repair history**
6. Testar create order no template afetado

## Passo a passo: interpretar relatório

**Raw report** JSON:

- `orphans.templates_missing_service[]` — `{ template_id, service_id, user_id }`
- `columns.eko_sampa_templates.client_id` — deve ser `true`
- `tables.*` — todas `true`

## Órfãos sem repair automático

- `orders_missing_*` — corrigir orders manualmente ou script
- `templates_missing_client` — associar cliente ou zerar FK
- `fields_missing_service` — apagar field ou recriar service

## Adicionar detecção de nova FK

1. Método `find_*` em `class-database-integrity.php`
2. Chave em `build_report()['orphans']`
3. Documentar em `database/integrity.md`
4. UI Diagnostics (opcional) — coluna no summary

## WP-CLI / deploy

```bash
php bin/eko-sampa-repair-db.php
```

Garante schema; não substitui repair de órfãos — usar admin ou REST.

## Após repair

- `Eko_Sampa_Model_Base::clear_table_column_map_cache()` já chamado no repair
- Hard refresh no browser (JS cache)

Ver [../architecture/lessons-learned.md](../architecture/lessons-learned.md).
