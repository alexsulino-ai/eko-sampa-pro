# Models e ownership

**Base:** `includes/class-model-base.php`

## Padrão

Cada entidade extends `Eko_Sampa_Model_Base`:

- `table_suffix()` — ex. `eko_sampa_templates`
- `ownership_predicate()` — tipicamente `['user_id = %d', [$uid]]`
- `get($id)` — SELECT com ownership
- `get_row_by_id($id)` — **sem** ownership (uso interno / integrity)
- `is_unrestricted()` — `current_user_can('manage_options')` (exceção: em **`Eko_Sampa_Service`** também `manage_eko_services`, alinhado ao REST de serviços — ver [../business-rules/service-delete.md](../business-rules/service-delete.md))

## Listagens

- `list($args)` — admin pode passar `filter_user_id`
- Utilizador normal: só rows com seu `user_id` (e globais em services conforme SQL do model)

## Column map

- `filter_row_to_existing_columns()` — evita INSERT em colunas inexistentes (schema drift)
- Cache limpo após alignment/repair: `clear_table_column_map_cache()`

## Classes

| Classe | Tabela |
|--------|--------|
| `Eko_Sampa_Client` | clients |
| `Eko_Sampa_Service` | services |
| `Eko_Sampa_Service_Field` | fields |
| `Eko_Sampa_Template` | templates |
| `Eko_Sampa_Order` | orders |

## Order é especial

Relações cross-entity em `class-order.php`, não no model base:

- `prepare_create_data`
- `relations_validate`
- `service_visible_for_order`

Não mover essa lógica para REST sem delegar ao order model.
