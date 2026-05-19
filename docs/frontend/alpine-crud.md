# Alpine CRUD factories

**Ficheiro:** `assets/js/frontend-app.js`

## Padrão comum

- `init()` carrega record por `recordId` + `mode` (list|view|edit|new)
- `ekoSampaApi` para REST
- Capabilities: `canOrderCreate()`, etc.
- Erros em `this.error` + `ekoSampaToast`

## templateCrud

- Rotas: `views/crud/templates-list.php`, `templates-detail.php`, `templates-form.php`
- `normalizeTemplateRow()` — unifica campos API
- `templateSavePayload()` — strip campos read-only no PATCH
- Create order: [create-order.md](create-order.md)

## Expressões Alpine seguras (PHP)

`eko_sampa_alpine_can_expr('order.create')` em views — evita throw se `ekoSampaCan` missing.

**Nunca** `:title` ou atributos dinâmicos sem string JS válida (ver lessons-learned Alpine).

## List vs view vs edit

| mode | recordId | Comportamento |
|------|----------|---------------|
| list | 0 | tabela + filtros |
| view | >0 | read-only + ações |
| edit | >0 | form PATCH |
| new | 0 | POST create |

Templates: view e edit **ambos** expõem create order — mesma API.

Documentação anterior: `docs/crud-modules.md` → redireciona aqui.
