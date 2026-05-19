# Eko Sampa — Documentação técnica

Documentação **alinhada ao código atual** do plugin (PHP 8+, REST `eko-sampa/v1`, Alpine CRUD). Objetivo: blindar o domínio contra regressões — cada regra crítica tem arquivo, contrato e ponto de código rastreável.

**Versão do plugin (código):** `EKO_SAMPA_VERSION` em `eko-sampa.php` (ex.: 1.10.0)  
**Versão do schema (código):** `EKO_SAMPA_DB_VERSION` (ex.: 1.0.12 — hardening de sessão + quick print `expires_at` / `abandoned_at`)  
**Opção WordPress:** `eko_sampa_db_version`

---

## Pacote de continuidade (arquitetura + anti-regressão)

Índice único para onboarding técnico do estado atual: [CONSOLIDATION-INDEX.md](CONSOLIDATION-INDEX.md) (editor visual, thumbnails, quick print, contratos, **DO-NOT-BREAK**, limitações, changelog técnico).

---

## Comece aqui

| Perfil | Leitura |
|--------|---------|
| Novo dev no plugin | [architecture/onboarding.md](architecture/onboarding.md) |
| Bug em “Create order” | [troubleshooting/create-order-400.md](troubleshooting/create-order-400.md) |
| Dados inconsistentes | [database/integrity.md](database/integrity.md) + [diagnostics/admin-tool.md](diagnostics/admin-tool.md) |
| Alterar regra de negócio | [tutorials/adding-a-business-rule.md](tutorials/adding-a-business-rule.md) + [MAINTENANCE.md](MAINTENANCE.md) |

---

## Índice

### Segurança e permissões

- [permission-matrix.md](security/permission-matrix.md) — REST ↔ models, drift, `explain_row_visibility`

### Marketing público e convidados

- [public-experience-architecture.md](public-experience-architecture.md) — camada de serviço, quotas, SEO, telemetria leve
- [analytics-architecture.md](analytics-architecture.md) — contadores, rollups, snapshots, cron de analytics
- [platform-observability.md](platform-observability.md) — health REST, painéis admin, pipelines
- [healthcheck-and-cleanup.md](healthcheck-and-cleanup.md) — cron de limpeza, hooks pós-cleanup, healthcheck
- [guest-conversion-flow.md](guest-conversion-flow.md) — modal de conversão, redirect, recuperação
- [public-home-flow.md](public-home-flow.md) — home `/eko-sampa/`, REST de catálogo, conversão
- [guest-session-lifecycle.md](guest-session-lifecycle.md) — sessão convidado, quotas, quick print

### Arquitetura

- [CONSOLIDATION-INDEX.md](CONSOLIDATION-INDEX.md) — **índice** continuidade (thumbnail, quick print, contratos)
- [PROJECT-CONTINUITY-OVERVIEW.md](architecture/PROJECT-CONTINUITY-OVERVIEW.md) — visão transversal
- [EDITOR-VISUAL-SYSTEM.md](architecture/EDITOR-VISUAL-SYSTEM.md) — lifecycle editor, DOM vivo, persistência
- [RECENT-ARCHITECTURAL-DECISIONS.md](architecture/RECENT-ARCHITECTURAL-DECISIONS.md) — decisões recentes explícitas
- [overview.md](architecture/overview.md) — camadas, bootstrap, fluxos principais
- [plugin-layout.md](architecture/plugin-layout.md) — pastas e classes
- [domain-contracts.md](architecture/domain-contracts.md) — contratos Template / Order / Service / Client
- [schema-alignment.md](architecture/schema-alignment.md) — drift `eko_sampa_templates`, DDL 1.0.6, bridge INSERT
- [lessons-learned.md](architecture/lessons-learned.md) — bugs reais e como não repetir
- [safe-delete-pattern.md](architecture/safe-delete-pattern.md) — padrão `safe_delete` multi-entidade (roadmap)
- [onboarding.md](architecture/onboarding.md) — setup local e primeiros passos

### Armazenamento e snapshots

- [storage-architecture.md](storage/storage-architecture.md) — `Eko_Sampa_Storage_Manager`, thumbnails, snapshots de OS concluída, compatibilidade legada

### Banco e integridade

- [schema.md](database/schema.md) — tabelas, colunas, legado `servico_id` / `cliente_id`
- [templates-schema.md](schema/templates-schema.md) — contrato canónico vs colunas legadas + bridge/repair
- [orders-schema.md](schema/orders-schema.md) — `order_title` (rótulo operacional da OS)
- [print-isolation.md](architecture/print-isolation.md) — UI operacional vs impressão física/PDF
- [VISUAL-PIPELINE.md](rendering/VISUAL-PIPELINE.md) — `EkoCanvasRenderer`, alvos PRINT / THUMBNAIL
- [ensure-schema.md](database/ensure-schema.md) — `ensure_schema()`, alignment, `row_exists()`
- [migrations.md](database/migrations.md) — versões 1.0.0 → 1.0.12
- [integrity.md](database/integrity.md) — órfãos, repair, opções WP

### Regras de negócio

- [DOMAIN-CONSOLIDATED.md](business-rules/DOMAIN-CONSOLIDATED.md) — índice consolidado (templates, orders, quick print)
- [templates.md](business-rules/templates.md)
- [template-derivation-system.md](template-derivation-system.md) — master → sessão → utilizador; cleanup; limites
- [session-hardening.md](session-hardening.md) — fingerprint, quick-print TTL/abandon, autosave, observabilidade, flags de catálogo
- [thumbnail-pipeline.md](templates/thumbnail-pipeline.md) — DOM → JPEG, fonts, assets, fallback servidor
- [Visual Render Contract](architecture/visual-render-contract.md) — layout unificado print/thumbnail
- [duplicate-lifecycle.md](templates/duplicate-lifecycle.md) — POST duplicate, `try_create`, `duplicate-diagnostics`, insert diagnostics
- [preview-image-resolution.md](templates/preview-image-resolution.md) — URL pública vs path relativo
- [orders.md](business-rules/orders.md)
- [services.md](business-rules/services.md)
- [service-delete.md](business-rules/service-delete.md) — contrato DELETE + safe delete
- [clients.md](business-rules/clients.md)

### Frontend

- [overview.md](frontend/overview.md) — rotas virtuais, Alpine factories
- [alpine-crud.md](frontend/alpine-crud.md) — list/view/edit/new
- [create-order.md](frontend/create-order.md) — JS: payload e pré-validação

### Contratos, limitações e changelog técnico

- [contracts/README.md](contracts/README.md) — payloads, invariantes
- [contracts/DO-NOT-BREAK.md](contracts/DO-NOT-BREAK.md) — **sistemas sensíveis**
- [known-limitations/README.md](known-limitations/README.md) — limitações reais (html2canvas, `window.print`, CORS)
- [changelogs/TECH-RECENT.md](changelogs/TECH-RECENT.md) — changelog técnico recente

### Quick Print e thumbnails (pacote)

- [quick-print/README.md](quick-print/README.md) — objetivo, diferença vs Orders, modal, jobs
- [quick-print/LIFECYCLE-AND-CONTRACTS.md](quick-print/LIFECYCLE-AND-CONTRACTS.md) — sequência canónica
- [thumbnail-system/README.md](thumbnail-system/README.md) — regra de ouro, pipeline atual (html2canvas)

### Editor (índice)

- [editor/README.md](editor/README.md) — ligação ao pacote de continuidade + relatórios em `docs/editor/*`

### Backend e API

- [models-and-ownership.md](backend/models-and-ownership.md)
- [rest-api/overview.md](rest-api/overview.md)
- [rest-api/orders.md](rest-api/orders.md)
- [rest-api/templates.md](rest-api/templates.md)

### Tutoriais (passo a passo)

- [create-order-step-by-step.md](tutorials/create-order-step-by-step.md)
- [integrity-and-repair.md](tutorials/integrity-and-repair.md)
- [adding-a-business-rule.md](tutorials/adding-a-business-rule.md)

### Operação

- [diagnostics/admin-tool.md](diagnostics/admin-tool.md)
- [troubleshooting/create-order-400.md](troubleshooting/create-order-400.md)
- [troubleshooting/schema-drift.md](troubleshooting/schema-drift.md)
- [security/capabilities.md](security/capabilities.md)

### Processo

- [MAINTENANCE.md](MAINTENANCE.md) — obrigação de atualizar docs em cada mudança de domínio

---

## Documentos na raiz do repositório

| Arquivo | Uso |
|---------|-----|
| `INSTALL.md` | Instalação e permalinks |
| `MANUAL.md` | Fluxo do usuário final |
| `CHECKLIST-MVP.md` | QA pré-produção |
| `CHANGELOG.md` | Histórico de releases |

---

## Arquivos legados em `docs/` (redirecionam)

Os ficheiros `architecture.md`, `database.md`, `frontend.md`, `editor.md`, `api.md`, `crud-modules.md` e `event-contracts.md` na raiz de `docs/` apontam para esta árvore — **não duplicar conteúdo neles**.
