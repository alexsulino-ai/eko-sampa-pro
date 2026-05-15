# Regras de negócio: Templates

**Código:** `includes/class-template.php`, `includes/class-rest-api.php` (rotas `/templates`), views `views/crud/templates-*.php`, Alpine `templateCrud` em `frontend-app.js`.

## Ownership

- Todo template tem `user_id` do criador.
- `get($id)` só devolve se owner ou admin unrestricted.
- Admin pode forçar `user_id` no create.

## Cliente no template

- `client_id` opcional (0 ou ausente = sem cliente associado).
- Formulário: opção “Anonymous client” / cliente vazio.
- **Não** bloqueia save nem REST por falta de cliente.

## Serviço no template

- `service_id` recomendado para fluxo de order.
- Frontend `buildCreateOrderPayload`: se `service_id` 0 após GET → toast erro, **sem** POST.
- Backend em create order: template é **autoritativo** (`resolve_order_relations_from_template`).

## Produtos Woo

- `product_id` opcional; bridge WC separado.

## Placeholders

- REST `GET /templates/{id}/placeholders` — tokens para renderer.
- Classe `Eko_Sampa_Placeholder_Tokens` (ver includes).

## Thumbnail / preview

- Pipeline dedicado (`class-template-thumbnail*.php`).
- Não misturar validação de integridade order com geração de thumbnail.

## Templates órfãos

| Tipo | Detecção | Repair |
|------|----------|--------|
| service_id sem row | Integrity `templates_missing_service` | `repair_orphan_template_services` |
| client_id sem row | `templates_missing_client` | manual (não auto-repair) |

## Rotas UI

| URL | Modo Alpine | Uso |
|-----|-------------|-----|
| `/eko-sampa_templates/` | list | |
| `/eko-sampa_templates/new/` | create | |
| `/eko-sampa_templates/{id}/` | view | detalhe + create order |
| `/eko-sampa_templates/{id}/edit/` | edit | edição + create order |

**Diferença view vs edit:** permissões `template.view` vs edição de campos; **mesma** validação REST no create order.

## Save payload (frontend)

`templateSavePayload()` remove campos extra antes de PATCH — evita rejeição por chaves desconhecidas.

## Integridade

Correr Diagnostics após import SQL ou delete manual de services.

Contrato: [../architecture/domain-contracts.md](../architecture/domain-contracts.md).
