# Quick Print — contratos de lifecycle

## Sequência canónica “Imprimir”

1. Garantir `templateId` e (se necessário) `saveNow`.
2. `waitVisualRenderStable()` — DOM/imagens prontas.
3. `buildLiveQuickPrintPayload()` + validação (`validateQuickPrintLivePayload`).
4. `mountQuickPrintPreview(payload)` — preview idêntico ao pipeline de print.
5. `_injectQuickPrintPrintStyle(editorPreview)` — CSS de isolamento + `@page`.
6. `POST quick-print/jobs` com `editor_preview` **igual** ao snapshot montado.
7. `POST …/browser-handoff` → `window.print()` → (opcional) materialização de imagens para dispositivo → `afterprint` → `POST …/complete`.
8. Restaurar mount se o HTML foi expandido para N folhas.

## Invariantes

- **Um POST `/jobs` por ação explícita** do utilizador no fluxo “criar + imprimir”.
- **Nunca** omitir `editor_preview` no create (servidor rejeita).
- **Não** regressar a esconder `#eko-sampa-quick-print-mount` via classe partilhada com o painel completo.

## Estado da job

- `queued` → utilizador pode imprimir / cancelar.
- `sent_to_browser` — impressão em curso / após handoff.
- `completed` / `cancelled` — terminal.

## Quantidade

- Persistida na job (REST) e repetida no DOM no passo de impressão (N raízes `.eko-sampa-print-root`).
- A UI de “cópias” da impressora do SO é independente — ver `quantity_hint` na resposta REST.

## Abort / fecho

- Fechar modal aborta fetch em voo e limpa preview + estilos de impressão.
