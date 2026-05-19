# Manutenção da documentação (regra permanente)

Qualquer alteração que mude **comportamento observável** do Eko Sampa deve atualizar a documentação no mesmo PR/commit.

---

## Checklist obrigatório

### Tabelas / schema

- [ ] `docs/database/schema.md` — colunas novas ou renomeadas
- [ ] `docs/database/migrations.md` — bump de `EKO_SAMPA_DB_VERSION` + descrição da migração
- [ ] `docs/database/ensure-schema.md` — se `ensure_schema()` ou `schema_align_*` mudar
- [ ] `includes/class-database.php` — `migration_callbacks()` com nova versão
- [ ] `Eko_Sampa_Database_Integrity` — novos tipos de órfão, se aplicável

### Regras de negócio

- [ ] `docs/business-rules/<entidade>.md`
- [ ] `docs/architecture/domain-contracts.md` — contrato afetado
- [ ] Tutorial em `docs/tutorials/` se o fluxo de usuário mudar

### REST

- [ ] `docs/rest-api/overview.md` ou rota específica
- [ ] Códigos de erro `eko_sampa_*` documentados em troubleshooting, se novos
- [ ] Se alterar `require_*_cap` ou `is_unrestricted()` em qualquer model: `docs/security/permission-matrix.md` + correr **Diagnostics → Run permission consistency check**

### Repairs / integridade

- [ ] `docs/database/integrity.md`
- [ ] `docs/diagnostics/admin-tool.md`
- [ ] Testar **Eko Sampa → Diagnostics** após deploy

### Frontend

- [ ] `docs/frontend/*.md` se factories Alpine, payload ou rotas CRUD mudarem

### Regressões conhecidas

- [ ] Entrada em `docs/architecture/lessons-learned.md` se o bug foi sério ou já ocorreu antes

---

## Onde validar (código)

| Tema | Fonte da verdade |
|------|------------------|
| Schema | `includes/class-database.php` |
| Integridade | `includes/class-database-integrity.php` |
| Orders | `includes/class-order.php` |
| REST create order | `includes/class-rest-api.php` → `route_orders_create` |
| Templates | `includes/class-template.php` |
| Capabilities | `includes/helpers-capabilities.php` |
| Create order UI | `assets/js/frontend-app.js` → `buildCreateOrderPayload` |

---

## Anti-padrões

- Duplicar validação só no JS (backend sempre valida em `relations_validate()`).
- Confiar em `Service::get()` para provar existência de FK (usar `Eko_Sampa_Database::row_exists()` quando a regra é **existência**, não **visibilidade na listagem**).
- Bump de `eko_sampa_db_version` sem passo em `migration_callbacks()` (1.0.5 é exemplo corrigido).
- Documentação genérica sem referência a classe/método.
