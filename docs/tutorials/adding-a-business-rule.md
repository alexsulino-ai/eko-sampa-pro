# Tutorial: Adicionar nova regra de negócio

## Onde validar

| Tipo de regra | Local primário |
|---------------|----------------|
| Relação order ↔ template/service/client | `Eko_Sampa_Order::relations_validate()` |
| Campo permitido / sanitização | `Eko_Sampa_*::sanitize_row` / `create` / `update` |
| Autorização HTTP | `Eko_Sampa_Rest_Api::require_*_cap` + model `get()` |
| FK existe na BD | `Eko_Sampa_Database::row_exists()` |
| FK + ownership visível | Model `get()` |

## Onde NÃO validar

- Views PHP (exceto esconder UI com capabilities)
- `frontend-app.js` sozinho (pode pré-validar UX, não substitui backend)
- Template renderer / thumbnail pipeline
- Hooks Woo bridge sem relação com order

## Evitar duplicidade

1. Uma função canónica (ex. `relations_validate`) devolve `{ ok, debug, failed_at }`
2. `relations_visible()` deve delegar para `relations_validate()['ok']`
3. REST expõe mesmo `debug` em erros 400

## Evitar quebrar REST

- Não remover campos do JSON de erro sem versionar API
- Novos `failed_at` → documentar em `business-rules/orders.md` + troubleshooting
- Testar `POST /orders` com admin e user comum

## Evitar quebrar integridade

- Nova coluna FK → orphan query + nota em `MAINTENANCE.md`
- Se repair automático → limitar escopo (como one-shot order) + histórico

## Compatibilidade legada

- Ler `servico_id` / `cliente_id` só em helpers de leitura (`template_service_id_from_row`)
- Escrever sempre `service_id` / `client_id`
- Alignment copia dados; não assumir que legado existe em installs novos

## Checklist PR

- [ ] Código
- [ ] `domain-contracts.md`
- [ ] business-rules/<entidade>.md
- [ ] tutorial se fluxo UX mudou
- [ ] Diagnostics se novo órfão
- [ ] `CHANGELOG.md` se release
