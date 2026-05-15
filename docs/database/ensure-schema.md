# ensure_schema() e alignment

**Classe:** `Eko_Sampa_Database`  
**Entrada principal:** `ensure_schema()` — chamada em `Eko_Sampa_Plugin` e Diagnostics.

## Pipeline (ordem fixa)

```
ensure_schema()
  ├─ migrate()                    # incremental por eko_sampa_db_version
  ├─ run_schema_alignment()       # idempotente, todas as tabelas core
  ├─ integrity->run(false)        # detecta órfãos, NÃO repara
  └─ se faltam tabelas:
        repair_missing_tables()   # re-aplica todos migrate_to_* via dbDelta
        run_schema_alignment()
        integrity->run(false)
```

## migrate()

- Lê `get_option('eko_sampa_db_version', '0')`
- Se `installed >= EKO_SAMPA_DB_VERSION` mas faltam tabelas → só `repair_missing_tables()`
- Senão executa callbacks em `migration_callbacks()` onde `installed < version`
- Atualiza option para `EKO_SAMPA_DB_VERSION`
- **Nunca** apaga tabelas automaticamente

## run_schema_alignment()

Métodos idempotentes (seguros em cada request):

| Método | Tabela |
|--------|--------|
| `schema_align_clients` | clients |
| `schema_align_services` | services |
| `schema_align_fields` | fields |
| `schema_align_templates` | templates (+ legado servico/cliente) |
| `schema_align_layers` | layers |
| `schema_align_orders` | orders |

Após alignment: `Eko_Sampa_Model_Base::clear_table_column_map_cache()`.

### Templates (crítico)

`schema_align_templates`:

- Garante colunas `client_id`, `service_id`, etc.
- `copy_column_data_if_both_exist`: `servico_id` → `service_id`, `cliente_id` → `client_id`
- Pode dropar colunas legadas quando migração completa

## row_exists()

```php
$database->row_exists('eko_sampa_services', $id);
```

- Suffix whitelisted (`required_table_suffixes()`)
- `id > 0` apenas
- **Sem** filtro `user_id` — uso: integridade FK, não autorização

Usado em:

- `Eko_Sampa_Order::relations_validate()` → `service_exists`
- `service_visible_for_order()` fallback

## repair_missing_column_alignments()

Privado; foco em `client_id` em templates se versão option já avançou sem coluna física.

## Quando adicionar nova tabela

1. `required_table_suffixes()`
2. `migrate_to_1_0_0` ou nova migração com `dbDelta`
3. `schema_align_*` se colunas evoluírem fora de dbDelta
4. Integrity: novo `find_*_orphans` se houver FK lógica
5. Documentar em `schema.md`, `migrations.md`, `MAINTENANCE.md`
