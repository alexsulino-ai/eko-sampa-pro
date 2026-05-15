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

Nonce: `eko_sampa_integrity`

## Relatório

- Option: `eko_sampa_integrity_last_report`
- Mostra: timestamps, versões, contadores de órfãos, tabela template→service, **repair history**, JSON raw

## Histórico de repairs

- Option: `eko_sampa_integrity_repair_history`
- Até 25 entradas: `at`, `kind`, `count`, `items`, `by`
- Preenchido após repair batch com fixes

## Quando usar

- Após deploy de código DB 1.0.4+
- Após import/migração SQL manual
- Quando create order retorna `service_exists: false`
- Rotina preventiva pós-delete de services

## Não faz

- Não repara orders órfãs automaticamente
- Não substitui backup/restore
- Não altera ownership de templates

Ver [../database/integrity.md](../database/integrity.md).
