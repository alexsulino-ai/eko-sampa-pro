# Contratos de domínio

Contratos explícitos do estado **atual** do código. Quebrar um contrato exige atualizar este ficheiro, `docs/business-rules/`, testes manuais em Diagnostics e o tutorial afetado.

---

## CONTRATO: Client

| Regra | Implementação |
|-------|-----------------|
| Pertence a um `user_id` (owner) | `Eko_Sampa_Client` → `ownership_predicate()` |
| Listagem respeita owner; admin vê tudo | `Eko_Sampa_Model_Base::is_unrestricted()` |
| Pode ser referenciado por template/order com `client_id > 0` | `Eko_Sampa_Order::relations_validate()` → `client_not_visible` |
| `client_id = 0` na order **não** exige cliente | Validação só quando `client_id > 0` |

**Não é contrato:** template obrigar cliente (template pode ter `client_id` null/0).

---

## CONTRATO: Service

| Regra | Implementação |
|-------|-----------------|
| Tem `user_id` e opcionalmente `is_global` | Tabela `wp_eko_sampa_services` |
| Visível na listagem se owner ou global (regras do model) | `Eko_Sampa_Service::get()` |
| **Existência** ≠ **visibilidade na listagem** | `Eko_Sampa_Database::row_exists('eko_sampa_services', $id)` |
| Create order aceita serviço existente ligado a template visível mesmo se `get()` falhar por escopo | `Eko_Sampa_Order::service_visible_for_order()` |
| Fields pertencem a `service_id` | `wp_eko_sampa_fields` |

**Repair:** serviço ausente referenciado por template → `Eko_Sampa_Database_Integrity::repair_orphan_template_services()`.

---

## CONTRATO: Template

| Regra | Implementação |
|-------|-----------------|
| **Deve** ter `user_id` (owner) no create | `Eko_Sampa_Template::create()` |
| **Pode** ter `client_id` null/0 (cliente anónimo no template) | Form + REST; sem erro `eko_sampa_template_no_client` |
| **Pode** ter `service_id` 0 até o utilizador associar serviço | UI bloqueia create order se `service_id` 0 no GET |
| Create order exige template **visível** ao actor | `relations_validate` → `template_not_visible` |
| Create order exige `service_id` resolvido do template (autoritativo) | `resolve_order_relations_from_template()` |
| FK legada: ler `service_id` ou `servico_id` | `template_service_id_from_row()` |
| Órfão: `service_id > 0` sem linha em services | Integrity + REST one-shot repair |

**Rotas frontend:**

- `/eko-sampa_templates/{id}/` — view (read-only + create order)
- `/eko-sampa_templates/{id}/edit/` — edição + mesmo botão create order

Ambas usam factory Alpine `templateCrud`; diferença é `mode` (`view` vs `edit`), não regras REST diferentes.

---

## CONTRATO: Order

| Regra | Implementação |
|-------|-----------------|
| `client_id = 0` é **válido** (cliente anónimo) | Payload explícito não é sobrescrito por `inherit_client_from_template()` |
| Se payload **omite** `client_id` e template tem `client_id > 0`, herda | `inherit_client_from_template()` |
| Se payload tem `client_id > 0` e template tem `client_id > 0` diferente → erro | `template_client_mismatch` |
| Deve referenciar entidades **visíveis** (template, client se >0) e serviço **válido para order** | `relations_validate()` |
| `template_id` presente implica `service_id` do template (não confiar só no payload JS) | `prepare_create_data()` |
| Status inicial típico: `pending` | Frontend envia; DB aceita enum documentado em schema |

**REST:** `POST /wp-json/eko-sampa/v1/orders` → `route_orders_create`.

---

## CONTRATO: Integridade (cross-cutting)

| Regra | Implementação |
|-------|-----------------|
| `ensure_schema()` corre em bootstrap do plugin | `Eko_Sampa_Plugin` |
| Alignment idempotente em todo `ensure_schema` | `run_schema_alignment()` |
| Relatório guardado em `eko_sampa_integrity_last_report` | `Eko_Sampa_Database_Integrity::OPTION_LAST_REPORT` |
| Histórico de repairs em `eko_sampa_integrity_repair_history` | Após repair admin ou batch |
| Repair automático **pontual** no create order | `maybe_repair_orphan_template_service_for_order()` — só `service_not_visible_for_order` + `service_exists=false` |

---

## Matriz rápida: falha REST create order

| `failed_at` | Significado |
|-------------|-------------|
| `client_not_visible` | `client_id > 0` sem cliente no escopo do user |
| `template_not_visible` | Template inexistente ou outro owner |
| `service_missing_for_template` | Template sem `service_id` |
| `service_not_visible_for_order` | Serviço inexistente ou regra `service_visible_for_order` falhou |
| `template_client_mismatch` | Ambos client_id > 0 e diferentes |

Payload de erro inclui `debug` (espelho de `relations_validate`).
