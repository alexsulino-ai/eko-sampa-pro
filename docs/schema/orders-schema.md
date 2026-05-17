# Schema: orders (`wp_eko_sampa_orders`)

## `order_title` (operacional)

| | |
|--|--|
| **Tipo** | `varchar(255) NULL` |
| **Desde** | migração DB `1.0.7` (`schema_align_orders`) |
| **Função** | Identificador humano para fila de produção (ex.: «Cartão João Silva», «Lote XPTO»). |

### O que **não** é

- **Não** é o `nome` do template (`eko_sampa_templates.nome`).
- **Não** entra em `dynamic_data_json`, `json_data`, nem em `Eko_Sampa_Order::template_render_context()`.
- **Não** aparece no HTML/PDF/JPG gerados pelo renderer do canvas.
- **Não** altera snapshots de arte: o `order.json` do snapshot inclui `order_title` apenas como metadado da OS (junto com `order_id`, `status`, …), à parte do `template-snapshot.json` e do `render-context.json`.

### REST

- `POST /orders`, `PATCH /orders/{id}`: campo opcional `order_title` (string ou `null` para limpar em update).
- Listagem: parâmetro `s` — se **não** for só dígitos, filtra por `LIKE` em `order_title`; se for só dígitos, filtra por `id` (comportamento anterior).
- `orderby=order_title` permitido no modelo.

### Duplicação

- `duplicate` / `duplicate-revision`: novo título = título anterior + sufixo traduzido ` (Copy)`, com truncagem para caber em 255 caracteres (mesmo padrão que templates em `nome`).

### Diagnóstico

O relatório de integridade (`Eko_Sampa_Database_Integrity::build_report()`) expõe `orders_operational_title`: presença da coluna, `rows_without_title`, `readiness`.

Ver também [../business-rules/orders.md](../business-rules/orders.md) e [../rest-api/orders.md](../rest-api/orders.md).
