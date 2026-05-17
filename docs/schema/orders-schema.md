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
- **Não** aparece na impressão física do browser a partir da página `eko-sampa_print` — ver [../architecture/print-isolation.md](../architecture/print-isolation.md).
- **Não** altera snapshots de arte: o `order.json` do snapshot inclui `order_title` apenas como metadado da OS (junto com `order_id`, `status`, …), à parte do `template-snapshot.json` e do `render-context.json`.

### Índice e busca

- Migração **1.0.8:** índice secundário `eko_sampa_orders_order_title` em `order_title(191)` (utf8mb4). Ajuda `ORDER BY order_title` e `LIKE 'prefix%'`; **`LIKE '%texto%'`** continua a ser varredura por conteúdo — em volume muito alto, considerar FULLTEXT ou motor de busca à parte (métricas em `orders_operational_title` no integrity report).

### Normalização (persistência)

- `Eko_Sampa_Order::normalize_order_title_operational()`: remove zero-width / BOM / marcadores invisíveis comuns, normaliza NBSP, colapsa quebras e espaços, `trim`, trunca a 255, `sanitize_text_field`. Usada em create/update e na amostra de diagnostics.

### REST

- `POST /orders`, `PATCH /orders/{id}`: campo opcional `order_title` (string ou `null` para limpar em update).
- Listagem: parâmetro `s` — se **não** for só dígitos, filtra por `LIKE` em `order_title`; se for só dígitos, filtra por `id` (comportamento anterior).
- `orderby=order_title` permitido no modelo.

### Duplicação

- `duplicate` / `duplicate-revision`: novo título = título anterior + sufixo traduzido ` (Copy)`, com truncagem para caber em 255 caracteres (mesmo padrão que templates em `nome`).

### Diagnóstico

O relatório de integridade (`Eko_Sampa_Database_Integrity::build_report()`) inclui:

- **`orders_operational_title`:** coluna, `rows_without_title`, índice, `duplicate_titles_rows`, `completed_without_title`, amostra de drift de normalização / UTF-8, `search_strategy`, `missing_index_warning`, etc.
- **`snapshot_operational_consistency`:** leitura só de `order.json` em snapshots `completed` prontos (chave legada sem `order_title`, ficheiro em falta, parse).

Ver também [../business-rules/orders.md](../business-rules/orders.md) e [../rest-api/orders.md](../rest-api/orders.md).
