# Eko Sampa — Módulos CRUD, UI e regras de negócio

Documento consolidado (v1.5.0). Fonte de verdade para fluxo **Services**, **Orders**, **Templates**, padrões de listagem/edição, rotas, componentes reutilizáveis e comportamento de UI.

Relacionados: [architecture.md](architecture.md), [frontend.md](frontend.md), [database.md](database.md), [api.md](api.md).

---

## Princípios gerais

| Camada | Responsabilidade |
|--------|------------------|
| **PHP models** (`includes/class-*.php`) | Regras de negócio, ownership, SQL, validação server-side |
| **REST** (`includes/class-rest-api.php`) | Contrato HTTP, códigos de erro, permissões |
| **Views** (`views/crud/*.php`) | Markup + Alpine `x-data`; sem SQL |
| **JS** (`assets/js/frontend-app.js`, `eko-ui.js`) | Estado, REST, modais, factories por módulo |
| **DB** (`includes/class-database.php`) | Schema versionado; `ensure_schema()` repara tabelas ausentes |

O utilizador opera no **frontend** (`/eko-sampa_*`), não no wp-admin.

---

## Rotas frontend (padrão único)

Cada recurso segue o mesmo mapa de ações (`Eko_Sampa_Frontend_Router`):

| Ação | URL (exemplo Services) | View PHP |
|------|------------------------|----------|
| **list** | `/eko-sampa_services/` | `views/crud/services-list.php` |
| **new** | `/eko-sampa_services/new/` | `views/crud/services-form.php` |
| **view** | `/eko-sampa_services/{id}/` | `views/crud/services-detail.php` |
| **edit** | `/eko-sampa_services/{id}/edit/` | `views/crud/services-form.php` |

Recursos equivalentes:

- **Clients** → `eko-sampa_clients`
- **Services** → `eko-sampa_services`
- **Templates** → `eko-sampa_templates`
- **Orders** → `eko-sampa_orders`
- **Editor** → `/eko-sampa_editor/?template_id={id}` (fullscreen, fora do CRUD list/view)

O shell (`views/frontend-shell.php`) define sidebar, topbar e carrega o partial por `eko_sampa_active_view` + `eko_sampa_crud_action`.

---

## Padrão CRUD na UI

### Listagem

- Tabela em card (`rounded-xl border shadow-sm`).
- Pesquisa, paginação (`page` / `pageSize` / `hasNext`).
- Admin: filtro por `filter_user_id` quando aplicável.
- **Ações**: `eko_sampa_crud_action()` em `includes/helpers-crud-ui.php` — variantes View (cyan), Edit (amber), Editor (violet), Duplicate (emerald), Print (slate), Delete (red).

### Visualização (detail)

- Somente leitura; botão principal para **Edit**.
- Services: lista de dynamic fields em modo leitura.

### Edição (form)

- Formulário do recurso na página principal.
- Sub-recursos complexos (ex.: dynamic fields) abrem em **modal** (`views/partials/eko-base-modal.php`), não inline.

### Separação de responsabilidades

- **Services** definem campos dinâmicos e metadados (slug, tipo, validação, `show_in_template`).
- **Templates** consomem placeholders `{{slug}}` dos campos marcados para template.
- **Orders** capturam valores em `dynamic_data_json` e **snapshot** `service_fields_snapshot_json` na criação (imutável para aquela ordem).

---

## Services — fluxo estrutural

```
Catálogo (list) → criar/editar serviço (form)
                      ↓
              Dynamic Fields (modal)
                      ↓
              wp_eko_sampa_fields
                      ↓
        Templates (placeholders) + Orders (form + snapshot)
```

### Regras de negócio (campos dinâmicos)

1. Só é possível gerir fields **depois** de o serviço existir (`service_id` > 0).
2. `slug` único por serviço; normalizado (estilo `sanitize_title`).
3. Tipos: `text`, `textarea`, `number`, `select`, `date`.
4. `validation_rules_json`: objeto JSON no servidor; UI declarativa via `window.ekoSampaFieldValidation` (sem editar JSON bruto na maioria dos casos).
5. `show_in_template`: se desligado, campo é operacional (ordem) mas não entra como placeholder de impressão.
6. Reordenação: drag-and-drop (SortableJS) → `POST /services/{id}/fields/reorder`.
7. REST fields: `GET|POST /services/{id}/fields`, `PATCH|DELETE /services/{id}/fields/{field_id}`.

### UI/UX (v1.5.0)

- Lista compacta de fields na página de edição do serviço.
- **Add field** / **Edit** abrem modal reutilizável (`ekoSampaModalService` + `ekoModalMixin`).
- Sem expansão inline do formulário completo na página.

### Base de dados

- Tabela: `{prefix}eko_sampa_fields`.
- Migração **1.0.3**: colunas `default_value`, `placeholder`, `show_in_template`, `validation_rules_json`.
- **`Eko_Sampa_Database::ensure_schema()`**: em cada boot, cria tabelas em falta mesmo se `eko_sampa_db_version` já estiver atualizado.
- Script manual: `bin/eko-sampa-repair-db.php`.

---

## Templates — fluxo estrutural

```
List → View / Edit (metadados: nome, serviço, dimensões mm)
     → Editor (canvas JSON, layers, placeholders)
     → Preview / impressão via renderer
```

### Regras de negócio

1. `json_data` estruturado — **nunca** HTML do editor persistido como fonte.
2. Placeholders no canvas alinhados a tokens do serviço (`Eko_Sampa_Placeholder_Tokens`).
3. Preview substitui apenas placeholders **existentes** no template.
4. Duplicação via REST/action na listagem.

### UI

- Listagem com ações View, Edit, **Editor**, Duplicate, Delete.
- Editor em rota dedicada (sem chrome do tema).

---

## Orders — fluxo estrutural

```
List → View (detalhe + preview render)
     → Edit (cliente, serviço, template, dynamic_data_json, status)
     → Print (nova aba)
```

### Regras de negócio

1. Ao criar ordem, **snapshot** dos fields do serviço em `service_fields_snapshot_json`.
2. Valores do utilizador em `dynamic_data_json` (chaves = slug dos fields).
3. Render/preview usa template + dados dinâmicos + snapshot para consistência histórica.
4. Status: fluxo operacional (`pending`, `in_progress`, etc.) — application-enforced.

### UI

- Formulário de ordem na página; campos dinâmicos gerados a partir do serviço selecionado.
- Listagem: View, Edit, Print, Duplicate, Delete.

---

## Componentes reutilizáveis (frontend)

| Componente | Ficheiro | Uso |
|------------|----------|-----|
| **Modal shell** | `views/partials/eko-base-modal.php` | Backdrop, animação, header, body scroll, footer Save/Cancel, ESC, clique fora |
| **ModalService** | `assets/js/eko-ui.js` → `window.ekoSampaModalService` | Stack global, `open` / `close` / `confirm` |
| **Modal mixin** | `assets/js/eko-ui.js` → `window.ekoModalMixin()` | Alpine: `modalLayer`, `openEkoModal`, `confirmEkoModal` |
| **Action buttons** | `includes/helpers-crud-ui.php` | `eko_sampa_crud_action()`, variantes de cor/ícone |
| **CRUD mixin** | `frontend-app.js` → `ekoCrudMixin()` | `mode`, `recordId`, `crudUrls`, `viewUrl` / `editUrl` |
| **Factories** | `ekoServicesFactory`, `ekoTemplatesFactory`, `ekoOrdersFactory`, … | Um por módulo; `state` único |

### Ordem de scripts

1. Tailwind  
2. `eko-ui.js`  
3. `frontend-app.js` (regista `alpine:init`)  
4. Alpine **por último**

### Incluir modal num módulo

```php
$eko_modal_body_path = EKO_SAMPA_PLUGIN_DIR . 'views/partials/meu-form-corpo.php';
require EKO_SAMPA_PLUGIN_DIR . 'views/partials/eko-base-modal.php';
```

```javascript
// Na factory: Object.assign({}, ekoCrudMixin(), ekoModalMixin(), { ... })
this.openEkoModal({
  title: 'Título',
  saveLabel: 'Salvar',
  onSave: () => this.salvar(true),
  onCancel: () => this.resetar(),
});
```

---

## REST e diagnósticos

- Namespace: `eko-sampa/v1`.
- Autenticação: cookie + `X-WP-Nonce`.
- Erros de fields: `failure_reason` (ex. `fields_table_missing`) — resolvido por `ensure_schema()` após deploy.

---

## Consistência de estado (v1.7.1)

- **Delete / reorder** de fields: optimistic + toast + rollback (mesmo padrão do save).
- **`policy: 'disabled'`** em ações sensíveis (delete) — botão visível mas inativo sem capability.
- **`validateAsync()`** + `GET .../fields/check-slug` (slug remoto).
- **`ekoSampaStore`** — cache fino (`service:{id}:fields`), separado do EventBus.

---

## Consistência de estado (v1.7.0)

| Peça | API |
|------|-----|
| Toast | `window.ekoSampaToast.show({ type, message, duration, id })` |
| Capabilities | `window.ekoSampaCan('service.edit')` + PHP `eko_sampa_user_can()` |
| Field registry | `window.ekoSampaFieldRegistry` |
| Validation | `window.ekoSampaValidationEngine` + `schema_version` em `validation_rules_json` |
| Eventos | [event-contracts.md](event-contracts.md) |
| Optimistic fields | snapshot + `requestId` + rollback em `saveField()` |

---

## Infraestrutura UI (v1.6.0)

### Z-index centralizado

CSS: `assets/css/eko-design-system.css` — variáveis `--eko-z-modal`, `--eko-z-dropdown`, `--eko-z-tooltip`, `--eko-z-toast`, etc.

JS: `window.ekoSampaLayers.z('modal', stackOffset)` — modal aninhado sobe camada sem `z-[999999]`.

### Modal (acessibilidade + persistência)

- Scroll lock com compensação da scrollbar (`padding-right` no body).
- Focus trap (Tab cicla no painel), foco inicial, restore ao fechar.
- `role="dialog"`, `aria-modal`, `aria-labelledby`.
- Dirty state: `isDirty` no `open()` + confirmação ao cancelar + `beforeunload`.
- Anti double-submit: `saving` bloqueia botões e segundo `confirm`.
- Evento: `eko:modal:saved` via `ekoSampaEventBus`.

### Field Definition vs Instance

`window.ekoSampaFieldSchema`:

- **Definition** (`fieldDraft.definition`): label, slug, type, validation, defaults…
- **Meta** (`fieldDraft.meta`): `id`, `service_id`, `sort_order` (persistência)
- **Instance** = draft em edição no modal; lista `state.fields` = registos da API

### Event bus

`window.ekoSampaEventBus.on('eko:service:fields-changed', fn)` — desacoplamento entre módulos.

### Action buttons por configuração

```php
eko_sampa_crud_actions_render([
    ['type' => 'view', 'href' => 'viewUrl(r.id)'],
    ['type' => 'edit', 'href' => 'editUrl(r.id)', 'show' => 'canEdit'],
    ['type' => 'delete', 'click' => 'remove(r.id)', 'disabled' => 'loading'],
]);
```

Variantes em `eko_sampa_crud_action_variants()` — extensível para `loading`, `disabled`, `show`.

### Estados UI

Partials: `ui-state-loading.php`, `ui-state-empty.php`, `ui-state-error.php` + classes `.eko-ui-*`.

### Dynamic Fields (lista)

- Drag-and-drop reorder (SortableJS)
- Painel colapsável
- Filtro por texto (`fieldSearch`)
- Linhas expansíveis (detalhe)

Próximos passos documentados (não implementados): categorias, presets, validation preview, schema preview.

---

## Registo de ajustes recentes (memória operacional)

| Versão | Ajuste |
|--------|--------|
| 1.6.0 | Design system CSS, z-layers, focus trap, field schema, event bus, UI states, actions config |
| 1.5.0 | Modal global para dynamic fields; action buttons padronizados; documentação CRUD consolidada |
| 1.4.7 | `ensure_schema()` / reparo de tabelas; fields REST com diagnóstico |
| 1.0.3 | Colunas extended em fields + snapshot em orders |

---

## Checklist UX obrigatório

- [ ] Loading e mensagens de erro visíveis (`error`, modal `error`)
- [ ] Confirmação antes de delete
- [ ] Página de edição de serviço sem formulário de field inline expandido
- [ ] Modal fecha com ESC e backdrop (quando `closeOnBackdrop`)
- [ ] Ações de listagem com hierarquia visual consistente
- [ ] Após deploy, uma visita ao site ou script de repair garante tabelas DB
