# Changelog técnico recente (continuidade)

Registo **orientado a desenvolvimento** (não substitui `CHANGELOG.md` de release). Datas aproximadas quando não há tag única no repositório.

---

## Public experience (guest + home)

- **Service layer:** `Eko_Sampa_Public_Experience_Service` centraliza catálogo público, quotas, SEO leve, stats agregados, `POST /public/telemetry`.
- **Home JS:** paginação incremental + cache curto + tab “Populares”; `?fork=` para reabrir sessão a partir do master.
- **Sessão expirada:** página dedicada sem enqueue do bundle do editor quando `eko_sampa_guest_session_expired` está activo.

---

## Thumbnails

- **Correções de overwrite / corrida**: lock por `templateId`, `runId`, abort da corrida anterior, verificação `isRunCurrent` antes de upload; fila com debounce e skip quando `visual_hash` coincide com thumbnail existente.
- **Snapshot do canvas vivo**: caminho `liveCanvasRoot` → clone de apresentação (sem chrome de edição) → html2canvas; tier secundário `client_dom` via host `data-eko-thumbnail-capture`.
- **Sincronização tipográfica**: cópia de computed styles do DOM vivo para folhas no clone (`syncPresentationTextTypographyFromLive`, etc.).
- **Metadados**: `thumbnail_capture_source` no POST para auditoria (`live_editor` vs `client_dom`).
- **Estabilização**: esperas por fontes/imagens, rAF, flatten de imagens antes do raster.
- **Fallback servidor**: adaptador `server` quando captura cliente falha de forma não-abortada.

---

## Quick Print Manager

- **Módulo**: REST `quick-print/*`, modelo `eko_sampa_quick_print_jobs` (DB `1.0.10+`), modal no editor.
- **Snapshot visual obrigatório**: validação `validate_quick_print_client_snapshot` no PHP.
- **Anti-duplicação UX**: flags `quickPrintJobCreating`, `_quickPrintPrintInProgress`; bloqueio de segundo “primary” enquanto job `sent_to_browser`.
- **Impressão**: isolamento `#eko-sampa-quick-print-layer` + `.eko-quick-print-hide-print`; materialização de imagens para `data:` antes de `window.print()`; folhas múltiplas com `page-break-after`.
- **Cleanup**: `closeQuickPrintManager` remove estilo print, abort requests, `disposeQuickPrintPreview`, `afterprint` listener.

---

## Editor

- **Rotate UX**: sensibilidade angular, soft snap para cardinais, coerência com estado persistido (ver código `ROTATE_*`).
- **Duplicate element / camadas**: fluxos documentados em `docs/templates/duplicate-lifecycle.md` e relatórios em `docs/editor/`.

---

## Contratos e documentação

- Pacote `docs/CONSOLIDATION-INDEX.md` + pastas `architecture/`, `rendering/`, `thumbnail-system/`, `quick-print/`, `contracts/`, `known-limitations/`, `changelogs/`.
- **`docs/contracts/DO-NOT-BREAK.md`**: invariantes e sintomas de regressão.

---

## Decisões explícitas (repetição)

- Não usar geração sintética no servidor como **preview fiel** do que o editor mostra.
- Snapshot para thumbnail/quick print deve preferir **estado visual real** (DOM + renderer).
- Jobs de impressão quick print: sequência **criar job → handoff → print → complete** com cleanup obrigatório.
