# Isolamento de impressão (print vs UI operacional)

## Objetivo

Na rota de impressão standalone (`views/frontend-print.php`), o operador vê:

- botões e links (ecrã),
- bloco **operacional** (Order #, `order_title`, `status`) para contexto na fila,

mas o **papel / PDF do browser** deve conter **apenas** a superfície imprimível (canvas / arte do template).

## Regra de negócio

| Camada | `order_title` / status / IDs |
|--------|------------------------------|
| Listagem CRUD, detalhe, ecrã de print (antes de imprimir) | Permitidos (metadado operacional) |
| `template_render_context()` / `render-context.json` | **Excluídos** (nunca entram no render do canvas) |
| `@media print` (spool do SO, “Guardar como PDF”) | **Excluídos** — mesma política que export: só a arte |

## Implementação (defesa em profundidade)

1. **Classes Tailwind:** `print:hidden` em `.eko-sampa-print-ui-only` (toolbar já tinha `print:hidden`).
2. **CSS explícito** no mesmo template: dentro de `@media print`, `.eko-sampa-print-ui-only`, `.eko-sampa-print-operational`, `.eko-sampa-print-operational-sep` com `display: none !important` (e `visibility` / `overflow` de reforço) para não depender apenas do build Tailwind.
3. **Superfície imprimível:** `.eko-sampa-print-surface` com `data-printable-surface="1"` e `data-print-integrity="canvas-only"` — documentação e eventual validação JS (`window.ekoSampaPrintIntegrity`).

Não existe segundo pipeline de render: o mesmo `ekoSampaPrintPayload` alimenta o canvas; apenas o **layout em volta** é cortado no print.

## Contrato JS (opcional)

`window.ekoSampaPrintIntegrity` expõe flags estáveis para testes ou extensões:

- `operational_ui_hidden_in_print: true`
- `printable_surface_only: true`
- `print_preview_integrity: 'operational_excluded_from_print_media'`

## O que não fazer

- Não injetar `order_title` em `template_render_context()`.
- Não duplicar o DOM do canvas só para print (risco de drift visual).
- Não confiar só em `print:hidden` sem regra `@media print` local se o CSS compilado mudar.

Ver também [../schema/orders-schema.md](../schema/orders-schema.md) e [../business-rules/orders.md](../business-rules/orders.md).
