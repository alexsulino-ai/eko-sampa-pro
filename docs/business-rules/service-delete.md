# Contrato: exclusão de serviços (SERVICE DELETE)

**Código:** `includes/helpers-service-delete.php` (`eko_sampa_safe_delete_service`), `includes/class-service-relations-inspector.php`, `includes/class-service.php`, REST `DELETE /services/{id}`.

## Princípios

- O serviço **não** é removido sem registo de causa quando o fluxo falha.
- Integridade é validada com **inspeção** antes de mutações destrutivas (contagens, legado `servico_id`, visibilidade API vs row em BD).
- Relações órfãs ou legadas podem ser **reparadas** (`eko_sampa_repair_service_relations`) antes do unlink/delete, sem apagar dados de negócio sem registo.
- O delete final é **auditável** (option `eko_sampa_service_delete_audit`, últimas entradas na página Diagnostics).
- Legado (`servico_id` em templates/orders, se a coluna existir) é suportado com limpeza explícita.
- Mensagens e códigos REST são **semânticos** (`eko_sampa_delete_forbidden`, `eko_sampa_delete_fields_failed`, `eko_sampa_delete_blocked_dependencies`, etc.), com objeto `debug` no corpo do erro.

## Filtro WordPress

`eko_sampa_safe_delete_service_options` — recebe o array de opções já fundido com os defaults (e o `service_id` alvo) antes da validação; permite testes ou políticas custom.

## Fluxo (`eko_sampa_safe_delete_service`)

1. Resolver opções (filtro `eko_sampa_safe_delete_service_options`).
2. Carregar row do serviço com `get_row_by_id` (existência).
3. `can_actor_mutate_existing_row` — alinhado ao REST `manage_eko_services` / `manage_options`.
4. `Eko_Sampa_Service_Relations_Inspector::inspect` — snapshot de dependências.
5. Se `strict_block` e existirem vínculos (`service_id` / legado `servico_id`): **bloquear** sem alterar linhas.
6. Se `repair_legacy`: limpar `servico_id` apontando para o id (templates/orders).
7. Se `unlink_refs`: `service_id = 0` em templates e orders que referenciam o serviço.
8. Apagar linhas em `eko_sampa_fields` para o `service_id`.
9. `DELETE` da linha em `eko_sampa_services`.

## REST — parâmetros de query

| Parâmetro | Default | Efeito |
|-----------|---------|--------|
| `strict` | off | Se `1`, bloqueia se ainda houver templates/orders (ou legado `servico_id`) ligados; não faz unlink automático nesse caso. |
| `unlink_refs` | `1` | Quando `0`, não desvia `service_id` em templates/orders antes do delete (útil com `strict` para diagnóstico). |
| `repair_legacy` | `1` | Quando `0`, não limpa colunas legadas `servico_id`. |
| `ensure_schema` | `0` | Quando `1`, chama `Eko_Sampa_Database::ensure_schema()` antes (custoso; usar após import SQL). |

## Ownership (services)

O model `Eko_Sampa_Service` trata utilizadores com `manage_eko_services` como **scope alargado** (equivalente a unrestricted **apenas** neste model), alinhando com a permissão REST. Outros models mantêm `manage_options` como unrestricted.

## Integração

- Reutiliza `Eko_Sampa_Database`, `Eko_Sampa_Database_Integrity` (padrões de órfãos) sem duplicar `dbDelta`.
- Diagnostics: inspeção manual por id + tabela de audit.

Ver também [services.md](services.md) e [../diagnostics/admin-tool.md](../diagnostics/admin-tool.md).
