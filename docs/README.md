# Eko Sampa — Documentação técnica

Documentação **alinhada ao código atual** do plugin (PHP 8+, REST `eko-sampa/v1`, Alpine CRUD). Objetivo: blindar o domínio contra regressões — cada regra crítica tem arquivo, contrato e ponto de código rastreável.

**Versão do plugin (código):** `EKO_SAMPA_VERSION` em `eko-sampa.php` (1.7.3+)  
**Versão do schema (código):** `EKO_SAMPA_DB_VERSION` = `1.0.5`  
**Opção WordPress:** `eko_sampa_db_version`

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

### Arquitetura

- [overview.md](architecture/overview.md) — camadas, bootstrap, fluxos principais
- [plugin-layout.md](architecture/plugin-layout.md) — pastas e classes
- [domain-contracts.md](architecture/domain-contracts.md) — contratos Template / Order / Service / Client
- [lessons-learned.md](architecture/lessons-learned.md) — bugs reais e como não repetir
- [safe-delete-pattern.md](architecture/safe-delete-pattern.md) — padrão `safe_delete` multi-entidade (roadmap)
- [onboarding.md](architecture/onboarding.md) — setup local e primeiros passos

### Armazenamento e snapshots

- [storage-architecture.md](storage/storage-architecture.md) — `Eko_Sampa_Storage_Manager`, thumbnails, snapshots de OS concluída, compatibilidade legada

### Banco e integridade

- [schema.md](database/schema.md) — tabelas, colunas, legado `servico_id` / `cliente_id`
- [ensure-schema.md](database/ensure-schema.md) — `ensure_schema()`, alignment, `row_exists()`
- [migrations.md](database/migrations.md) — versões 1.0.0 → 1.0.5
- [integrity.md](database/integrity.md) — órfãos, repair, opções WP

### Regras de negócio

- [templates.md](business-rules/templates.md)
- [orders.md](business-rules/orders.md)
- [services.md](business-rules/services.md)
- [service-delete.md](business-rules/service-delete.md) — contrato DELETE + safe delete
- [clients.md](business-rules/clients.md)

### Frontend

- [overview.md](frontend/overview.md) — rotas virtuais, Alpine factories
- [alpine-crud.md](frontend/alpine-crud.md) — list/view/edit/new
- [create-order.md](frontend/create-order.md) — JS: payload e pré-validação

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
