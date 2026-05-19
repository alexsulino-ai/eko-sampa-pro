# Schema das tabelas

Prefixo físico: `{wpdb->prefix}eko_sampa_*` (ex. `wp_eko_sampa_clients`).  
Charset: **utf8mb4** via `$wpdb->get_charset_collate()`. Engine: **InnoDB**.

## Convenção de colunas

| Tipo | Idioma | Exemplos |
|------|--------|----------|
| Domínio PT | português | `nome`, `descricao`, `telefone` |
| FK / técnico | inglês | `user_id`, `client_id`, `service_id`, `json_data` |
| `fields` | inglês API | `label`, `slug`, `type` |

**Legado (só leitura/migração):** `servico_id`, `cliente_id` em templates — copiados para `service_id` / `client_id` por `schema_align_templates`. Não usar em código novo.

**Categorias:** não há tabela; usar `templates.categoria`.

---

## `wp_eko_sampa_clients`

| Coluna | Notas |
|--------|-------|
| id | PK |
| user_id | owner |
| nome, email, telefone, documento, cidade, estado | |
| created_at, updated_at | |

---

## `wp_eko_sampa_services`

| Coluna | Notas |
|--------|-------|
| id | PK |
| user_id | owner |
| nome, descricao | |
| is_global | visível a outros users na listagem |
| created_at, updated_at | |

---

## `wp_eko_sampa_fields`

| Coluna | Notas |
|--------|-------|
| id | PK |
| service_id | FK lógica |
| label, slug, type, required, options_json, sort_order | |

Tipos: `text`, `textarea`, `number`, `select`, `date`.

---

## `wp_eko_sampa_templates`

| Coluna | Notas |
|--------|-------|
| id | PK |
| user_id | owner; **0** = sessão temporária (`template_type = session`) |
| client_id | opcional; 0/null = sem cliente (1.0.4+) |
| service_id | FK lógica; create order exige > 0 válido |
| product_id | Woo opcional |
| nome, categoria, descricao | |
| width_mm, height_mm, preview_image | |
| json_data | layout editor (JSON, não HTML) |
| template_type | `user` (defeito), `master`, `session` — ver [../template-derivation-system.md](../template-derivation-system.md) |
| parent_template_id | FK lógica para template origem (sessão → master) |
| session_token | segredo de sessão (apenas `session`) |
| expires_at, last_activity_at | TTL / atividade (sessão) |
| saved_from_session_id | rastreio persistência a partir de sessão |
| allow_personalization | permite fork público “Usar template” |
| is_public | catálogo público na app |
| created_at, updated_at | auditoria |

Thumbnail pipeline: rotas REST `/templates/{id}/thumbnail*` — ver código `class-template-thumbnail*.php`.

**Híbrido / legado (inglês):** algumas bases ainda têm `title`, `width`, `height`, `background_color`, … em paralelo. O contrato runtime é PT + `json_data`; alinhamento em [../schema/templates-schema.md](../schema/templates-schema.md) e migração DB `1.0.6`.

---

## `wp_eko_sampa_layers`

| Coluna | Notas |
|--------|-------|
| id | PK |
| template_id | |
| layer_type, layer_order, layer_json | |

---

## `wp_eko_sampa_orders`

| Coluna | Notas |
|--------|-------|
| id | PK |
| user_id | owner |
| client_id | **0 = anónimo** |
| service_id, template_id | FKs lógicas |
| woo_order_id | bridge WC |
| status | `pending`, `in_progress`, `print_queue`, `completed` |
| order_title | opcional; rótulo operacional (fila de produção); **≠** `templates.nome`; ver [../schema/orders-schema.md](../schema/orders-schema.md); índice secundário (DB 1.0.8) |
| dynamic_data_json | dados do formulário |
| service_fields_snapshot_json | opcional (migração 1.0.3+) |
| print_ready | |
| created_at, updated_at | |

---

## Índices

Índices em `user_id`, `template_id`, `service_id`, `product_id`, `status` conforme `migrate_to_1_0_0` / alignment.

---

## JSON

Campos: `json_data`, `layer_json`, `dynamic_data_json`, `options_json`.  
**Nunca** persistir HTML bruto do canvas.

---

## Ownership (resumo)

- Models filtram por `user_id` exceto admin (`manage_options`) / `is_global` em services.
- Detalhe: [../backend/models-and-ownership.md](../backend/models-and-ownership.md).
