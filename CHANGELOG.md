# Changelog

Todas as alterações notáveis ao projeto **Eko Sampa** são documentadas aqui.

O formato inspira-se em [Keep a Changelog](https://keepachangelog.com/pt-PT/1.0.0/).

---

## [1.7.4] — 2026-05-16

### Adicionado / Endurecimento

- Snapshots concluídos: `manifest.json` com `snapshot_schema_version`, inventário de assets (hash, mime, tamanho), estados de integridade; build em `_staging/order-{id}-{uniq}/` e publicação atómica (rename com fallback a cópia).
- Locks: snapshot por OS (`add_option` + TTL, evita corrida de transients); migração silenciosa de thumbnail com transient + verificação byte-a-byte após cópia e audit em falha.
- REST `GET /orders/{id}/render`: campos `render_warning`, `integrity_warning`, `snapshot_missing`, `legacy_snapshot_without_manifest` alinhados ao `render_source` (incl. `live_template_pre_snapshot_fallback`).
- `Eko_Sampa_Storage_Audit`: trilho limitado para falhas de snapshot/migração; relatório de integridade marca OS `completed` sem snapshot pronto como **CRITICAL** em `findings`.
- `Eko_Sampa_Storage_Manager`: validação de paths (`safe_path_guard`) e deletes sob fronteira `uploads/eko-sampa/`; deteção de pastas de staging abandonadas no relatório.

### Documentação

- `docs/storage/storage-architecture.md`: ciclo de vida do snapshot, manifesto, locks, invariantes e riscos do fallback ao template vivo.

## [1.7.3] — 2026-05-16

### Adicionado

- `Eko_Sampa_Storage_Manager`: paths centralizados, cópia/remoção segura sob `uploads/eko-sampa/`, relatório dry-run de integridade filesystem.
- Galeria: novos uploads em `eko-sampa/users/user-{id}/gallery/` com listagem merged com legado `eko-sampa/galeria/user-{id}/`.
- Thumbnails de template: escrita preferencial em `users/user-{id}/templates/{id}.jpg` com leitura fallback no path legado; migração silenciosa opcional (`eko_sampa_storage_silent_migrate_thumbnail`).
- Snapshot filesystem ao concluir OS (`completed-orders/order-{id}/`); render via snapshot quando disponível; OS `completed` imutáveis em `update`; `POST /orders/{id}/duplicate-revision`.
- Safe delete: `eko_sampa_safe_delete_template`, `eko_sampa_safe_delete_order`; inspectors `Template`, `Order`, `Client`; `DELETE /templates/{id}` com `?strict=1`.
- Diagnostics: botão **Storage integrity report (dry-run)**.
- Documentação: `docs/storage/storage-architecture.md`, atualizações em `orders`, REST orders, safe-delete-pattern, diagnostics.

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
