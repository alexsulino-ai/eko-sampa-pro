# Changelog

Todas as alterações notáveis ao projeto **Eko Sampa** são documentadas aqui.

O formato inspira-se em [Keep a Changelog](https://keepachangelog.com/pt-PT/1.0.0/).

---

## [1.7.2] — 2026-05-15

### Corrigido

- Botões Ver/Editar/Duplicar sumiam nas listas: `sanitize_key()` quebrava chaves `service.view`; removido skip de render no PHP (gating só no Alpine).

## [1.7.1] — 2026-05-15

### Adicionado

- Optimistic UI + toast para delete/reorder de dynamic fields.
- `policy: 'disabled'` em actions destrutivas; REST `check-slug`; `ekoSampaStore`.

## [1.7.0] — 2026-05-15

### Adicionado

- Toast global (`eko-toast.js`) com fila, variantes e bridge EventBus.
- Optimistic UI em dynamic fields (snapshot, requestId, rollback).
- Capabilities no REST localize + `ekoSampaCan()` + `can` nas action buttons.
- `eko-field-registry.js`, `ekoSampaValidationEngine`, envelope `schema_version` em validação.
- `docs/event-contracts.md`.

## [1.6.0] — 2026-05-15

### Adicionado

- Design system (`assets/css/eko-design-system.css`): tokens, z-layers, botões, inputs, estados UI.
- Modal: focus trap, scroll lock com gap da scrollbar, dirty state, `beforeunload`, z-index por stack.
- `ekoSampaFieldSchema` (definition vs meta/instance), `ekoSampaEventBus`, `ekoSampaLayers`.
- Dynamic fields: filtro, painel colapsável, linhas expansíveis; `eko_sampa_crud_actions_render()`.

## [1.5.0] — 2026-05-15

### Adicionado

- Modal reutilizável (`assets/js/eko-ui.js`, `views/partials/eko-base-modal.php`) para Dynamic Fields em Services.
- Botões de ação CRUD padronizados (`includes/helpers-crud-ui.php`) nas listagens.
- Documentação consolidada em `docs/crud-modules.md`.

## [1.4.7] — 2026-05-15

### Corrigido

- `ensure_schema()` e `bin/eko-sampa-repair-db.php` para criar tabelas core em falta (ex. `eko_sampa_fields`).

---

## [1.0.0] — 2026-05-12

### Adicionado

- Documento **`CHECKLIST-MVP.md`** para validação manual antes de testes reais em produção.
- **`CHANGELOG.md`** (este ficheiro).
- Endpoint REST **`GET /eko-sampa/v1/lookups/order-form`** — agrega listas de clientes, serviços e templates para o formulário de ordens (menos pedidos em rede).
- Paginação simples (**Previous** / **Next**) nas listagens de clientes, serviços, templates e ordens (`limit = pageSize + 1` + `offset`).
- Estado **`loading`** nas listagens com texto “Loading…” nas vistas parciais.
- No **wp-admin**, suporte a **`template_id`** na query string ao localizar o script do editor (`ekoSampaEditor.templateId`).
- Regra **`[x-cloak]`** em `assets/css/admin.css` para evitar flash de modais Alpine no admin.

### Corrigido (validação QA — estabilidade JS)

- **Editor em wp-admin:** quando `frontend-app.js` não está carregado, o canvas define um **`ekoSampaApi` mínimo** a partir de `ekoSampaEditor` (root + nonce).
- **Editor (`editor-canvas.js`):** `interact` encadeia **`.draggable().resizable()`** no mesmo alvo (evita duplicação de listeners ao rebind).
- **Editor:** `destroy()` limpa timers, **Sortable** e **interact.unset** nos elementos do canvas.
- **Editor:** `persist()` ignora resultado se `templateId` mudar durante o `await`.
- **Galeria:** falha em `refreshGallery` reporta-se em `saveState`; upload bem-sucedido limpa `saveState` após atualizar a lista.
- Garantia de sintaxe JS verificável (`node --check`) nos bundles principais.

### Segurança / robustez (já integrados na linha 1.0.0)

- Limite de tamanho do corpo JSON nas rotas mutáveis do namespace `eko-sampa/v1` (~512 KiB).
- Limite de `json_data` em templates (~384 KiB codificado) na REST.
- Validação estrita de **`dynamic_data_json`** em ordens (mapa plano, escalares).
- Slug **único** por serviço nos campos dinâmicos (REST **409**).
- **`wp_kses_post`** no HTML de preview (REST) e impressão (`frontend-print.php`).
- Preview de ordem em **iframe sandbox** + URL `data:` (sem `x-html` direto no DOM principal).

### Limitações documentadas

- Ver **`CHECKLIST-MVP.md`** (CDNs, HPOS, `wp_kses_post`, previews grandes, etc.).

---

## Tipos de mudanças

- **Adicionado** — novas funcionalidades.
- **Alterado** — mudanças em comportamento existente.
- **Descontinuado** — funcionalidades marcadas para remoção futura.
- **Removido** — funcionalidades removidas.
- **Corrigido** — correção de bugs.
- **Segurança** — correções relacionadas com segurança.
