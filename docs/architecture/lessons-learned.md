# Lições aprendidas (bugs reais)

Registo de incidentes de integridade e domínio já corrigidos. Usar em code review para evitar regressão.

---

## 1. Template órfão → `service_id` sem linha em `services`

### Sintoma

- `POST /orders` → `400` `eko_sampa_order_invalid_relations`
- `debug.failed_at` = `service_not_visible_for_order`
- `debug.service_exists` = **false**
- `debug.template_exists` / `template_visible` = true
- Exemplo: template #3 com `template_service_id` = 12, sem row `id=12` em `wp_eko_sampa_services`

### Causa raiz

FK lógica sem constraint MySQL; serviço apagado ou nunca criado; coluna legada `servico_id` desalinhada; dados migrados parcialmente.

### Impacto

Create order bloqueado; UI parecia “bug de permissões” ou Alpine.

### Solução definitiva

1. `Eko_Sampa_Database_Integrity::find_templates_missing_service()` com `COALESCE(service_id, servico_id)`
2. Repair admin + `repair_orphan_template_services()` (cria serviço recovery, atualiza template)
3. `service_visible_for_order()` usa `row_exists()` quando template é visível
4. One-shot em REST: `maybe_repair_orphan_template_service_for_order()`
5. Diagnostics em **Eko Sampa → Diagnostics**

### Evitar regressão

- Nunca assumir que `Service::get($id)` prova integridade.
- Após DELETE de service, correr integrity ou bloquear delete se templates referenciam.
- Documentar em PR se alterar cascade behavior.

---

## 2. `client_id = 0` (cliente anónimo)

### Sintoma

Order create falhava quando template tinha cliente mas order enviava `client_id: 0`.

### Causa raiz

`relations_visible` / regras antigas tratavam 0 como inválido; `inherit_client_from_template` sobrescrevia 0.

### Solução

- `array_key_exists('client_id', $data)` → respeitar 0 explícito
- Mismatch só quando **ambos** `client_id > 0` e diferentes
- Frontend envia `client_id: 0` em `buildCreateOrderPayload`
- Removido bloqueio REST `eko_sampa_template_no_client`

### Evitar regressão

Testar create order com: (a) template sem cliente, (b) template com cliente + payload 0, (c) template com cliente + payload igual.

---

## 3. Divergência frontend vs backend no `service_id`

### Sintoma

Lista mostrava template “com serviço”; POST falhava.

### Causa raiz

Row Alpine desatualizado; payload montado sem GET fresh; backend sempre lê DB em `resolve_order_relations_from_template`.

### Solução

`buildCreateOrderPayload` faz `GET templates/{id}` antes do POST.

### Evitar regressão

Qualquer botão de ação crítica deve refrescar entidade autoritativa (mesmo padrão para PATCH sensíveis).

---

## 4. `service_id` vs `servico_id` (legado)

### Sintoma

Integrity não via órfãos; order lia 0 de `service_id` com valor só em `servico_id`.

### Solução

- `schema_align_templates`: cópia `servico_id` → `service_id`, drop legado quando seguro
- `template_service_id_from_row()` lê ambos
- SQL de órfãos usa `COALESCE`

### Evitar regressão

Novas colunas só em inglês (`service_id`); nunca reintroduzir `servico_id` em código novo.

---

## 5. Schema drift (`client_id` em templates)

### Sintoma

`PATCH templates/{id}` 400; SQL unknown column `client_id`.

### Causa raiz

`eko_sampa_db_version` à frente da tabela real (deploy parcial).

### Solução

- Migração **1.0.4** + `schema_align_templates` em todo `ensure_schema`
- `repair_missing_column_alignments` para installs já em 1.0.4 sem coluna

### Evitar regressão

Sempre correr `ensure_schema` após deploy; verificar Diagnostics → column presence.

---

## 6. Alpine `:title` inválido na listagem de templates

### Sintoma

Página templates em branco / erros Alpine.

### Causa

Expressão `:title` sem string válida em `templates-list.php`.

### Solução

Corrigir binding; usar `eko_sampa_alpine_can_expr` para capabilities.

---

## 7. Migração 1.0.5 fora do mapa de callbacks

### Sintoma

`migrate_to_1_0_5` existia mas não estava em `migration_callbacks()` — upgrades 1.0.4→1.0.5 não executavam o método nomeado (alignment + integrity snapshot já corriam via `ensure_schema`).

### Solução

Entrada `'1.0.5' => migrate_to_1_0_5` adicionada ao mapa.

### Lição

Versão em `eko-sampa.php` deve ter par correspondente em `migration_callbacks()` **ou** comportamento 100% coberto por `ensure_schema` documentado em `database/ensure-schema.md`.

---

## 8. `ekoSampaCan` ausente quebrava Alpine

### Sintoma

JS throw em init; CRUD não monta.

### Solução

Fallback `(typeof window.ekoSampaCan === 'function' ? ... : true)` e helper PHP `eko_sampa_alpine_can_expr`.
