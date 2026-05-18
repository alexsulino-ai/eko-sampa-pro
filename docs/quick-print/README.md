# Quick Print Manager

## Objetivo

Fluxo **rápido** de impressão a partir do editor visual, **sem** criar encomenda (Order). Persiste **jobs** próprios (`wp_eko_sampa_quick_print_jobs`) para telemetria / estado, mas a arte impressa vem do **snapshot do cliente**.

## Quick Print **vs** Orders

| Aspeto | Quick Print | Orders |
|--------|-------------|--------|
| Cria OS / Woo | Não | Sim (fluxo oficial) |
| Snapshot | `editor_preview` obrigatório no POST (cliente) | Dados de order + template / contexto |
| Impressão | `window.print()` no browser | Página dedicada `frontend-print.php` / rotas `print` |
| Substituibilidade | Não substitui Orders | Fluxo canónico de produção |

## REST (`eko-sampa/v1/quick-print/*`)

- `GET quick-print/options` — impressoras/presets (filters WP).
- `POST quick-print/jobs` — exige `editor_preview` validado; **não** reconstrói preview “sintético” no servidor para criar o job.
- `POST …/browser-handoff`, `…/complete`, `…/cancel`, `GET` job, `POST reprint` — ver `includes/class-rest-api.php`.

Permissão: `quick_print_permission()` + filtro `eko_sampa_quick_print_enabled`.

## Lifecycle do modal (JS)

1. `openQuickPrintManager` — carrega opções; hidrata preview com `_quickPrintHydrateLivePreviewSilent` (sem POST).
2. Utilizador ajusta quantidade/preset/impressora — **local** até “Print” ou “Apply settings”.
3. **Print** (`quickPrintFooterPrimaryClick`) — um gesto: snapshot + mount + `POST jobs` + `quickPrintRunBrowser` (handoff + `window.print()`).
4. `closeQuickPrintManager` — abort requests, remove estilos de impressão, `disposeQuickPrintPreview`, limpa flags.

## Anti‑duplicação de jobs

- `quickPrintJobCreating` bloqueia reentrância.
- `_quickPrintPrintInProgress` evita segundo `window.print()` em paralelo.
- Modal **não** cria job ao abrir.

## Snapshot e estabilização

- `waitVisualRenderStable` — fontes, decode de `<img>` no canvas, `requestAnimationFrame` duplo, validação de layout (ref + fallback `canvasWidth`/`canvasHeight`).
- `buildLiveQuickPrintPayload` — clone profundo de `elements` + mm.
- `mountQuickPrintPreview` — `EkoCanvasRenderer.runRenderPipeline` no `#eko-sampa-quick-print-mount`.

## Impressão (`@media print`)

- Layer com `id="eko-sampa-quick-print-layer"` (teleport para `body`).
- **Não** usar a mesma classe “chrome” no painel inteiro e escondê‑la no print — isso escondia o mount (regressão corrigida).
- UI a esconder: `.eko-quick-print-hide-print`.
- Isolamento: `body > *:not(#eko-sampa-quick-print-layer) { display:none }` no print.
- Quantidade: réplicas de `.eko-sampa-print-root` em `.eko-sampa-quick-print-sheet` com `page-break-after` entre folhas; `_quickPrintResolvedQuantity()` para parsing robusto.

## Limitações de `window.print()`

- Não distingue “OK” vs “Cancelar” no diálogo (usa `afterprint` como aproximação).
- Rasterização depende do motor do browser / drivers.
- Imagens externas podem falhar sem CORS / materialização — ver `known-limitations`.

## Cleanup obrigatório

- `AbortController` por sessão de modal.
- Restaurar HTML do mount após impressão multi‑folha (`_quickPrintMountHtmlBackup`).
- Remover `#eko-sampa-quick-print-page-style` ao fechar.

## Ficheiros

- `assets/js/editor-canvas.js` — estado e métodos `quickPrint*`
- `views/editor-canvas.php` — markup modal + classes print
- `includes/class-rest-api.php` — rotas
- `includes/class-quick-print-job.php` — persistência

## Ver também

- [LIFECYCLE-AND-CONTRACTS.md](LIFECYCLE-AND-CONTRACTS.md)
- [../contracts/PRINT-MEDIA-CONTRACT.md](../contracts/PRINT-MEDIA-CONTRACT.md)
- [../contracts/DO-NOT-BREAK.md](../contracts/DO-NOT-BREAK.md)
