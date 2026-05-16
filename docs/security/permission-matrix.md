# Matriz de permissões (REST ↔ models)

**Objetivo:** uma única fonte da verdade por domínio, evitando **permission drift** (REST aceita, model nega sem contexto).

**Código de referência**

| Camada | Ficheiro |
|--------|----------|
| REST gates | `includes/class-rest-api.php` (`require_*_cap`) |
| Capabilities | `includes/class-roles.php`, `includes/helpers-capabilities.php` |
| Model scope | `includes/class-model-base.php` (`is_unrestricted`, `ownership_sql`) |
| Contrato serviços | `includes/helpers-permission-contract.php` (`eko_sampa_services_actor_has_elevated_scope`) |
| Validação estrutural | `includes/class-permission-consistency-validator.php` |

---

## Regra de ouro

> Se a REST permitir uma mutação com uma capability Eko, o model correspondente **não pode** aplicar um `is_unrestricted()` mais restritivo que essa capability, salvo documentação explícita de excepção intencional.

Violacao classica (corrigida para **services**): `require_services_cap()` usava `manage_eko_services`, mas `Eko_Sampa_Service::is_unrestricted()` só reconhecia `manage_options` → `get()` null → falhas silenciosas no pipeline de delete.

---

## Matriz por domínio

| Domínio | REST (`Eko_Sampa_Rest_Api`) | Model `is_unrestricted()` | Contrato partilhado | Estado |
|---------|-----------------------------|-----------------------------|---------------------|--------|
| **Services** | `manage_options` **ou** `manage_eko_services` | Igual, via `eko_sampa_services_actor_has_elevated_scope()` | `helpers-permission-contract.php` | **Alinhado** |
| Clients | `manage_options` **ou** `manage_eko_clients` | Só `manage_options` (`Model_Base`) | *Nenhum ainda* | **Risco documentado** — operações admin-only no model; operadores com só `manage_eko_clients` dependem de REST sem caminhos que exijam `is_unrestricted()` no model, ou podem ver 400 opacos em mutações avançadas. Evoluir com o mesmo padrão dos services se necessário. |
| Templates | `manage_options` **ou** `manage_eko_templates` | Só `manage_options` | *Nenhum ainda* | Idem clients. |
| Orders | `manage_options` **ou** `manage_eko_orders` | Só `manage_options` | *Nenhum ainda* | Idem clients. |

---

## Ownership vs `get()` vs `get_row_by_id()`

| Método | Uso |
|--------|-----|
| `get($id)` | Leitura com **scope** (catalog + ownership). `null` pode significar “não existe” **ou** “existe mas fora do scope”. |
| `get_row_by_id($id)` | Existência em BD, sem ownership — apenas integridade / repair / inspeção. |
| `Eko_Sampa_Service::explain_row_visibility($id)` | Distingue `exists_in_db`, `visible_to_actor`, `blocked_by_scope` — **obrigatório** quando se interpreta `get()` null em fluxos críticos. |

---

## `is_unrestricted()` (model base)

- Default: `current_user_can('manage_options')`.
- **Serviços:** `Eko_Sampa_Service` substitui por `eko_sampa_services_actor_has_elevated_scope()` (equivale a `manage_options || manage_eko_services`).

Novos overrides de `is_unrestricted()` em outros models **devem** referenciar este documento e, quando aplicável, um helper partilhado espelhando o gate REST.

---

## Auditoria e regressão

1. **WP Admin → Eko Sampa → Diagnostics → Run permission consistency check** — valida wiring do helper + override em `Eko_Sampa_Service`.
2. Em PRs que toquem em `require_*_cap` ou `is_unrestricted()`, correr o mesmo check e actualizar esta matriz.

Ver também [../business-rules/service-delete.md](../business-rules/service-delete.md) e [../architecture/safe-delete-pattern.md](../architecture/safe-delete-pattern.md).
