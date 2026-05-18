# DO NOT BREAK — sistemas sensíveis (anti-regressão)

Este documento lista **contratos implícitos** que, se violados, produzem bugs difíceis de reproduzir (thumbnails errados, impressão em branco, corrida de jobs, tipografia desalinhada). **Não alterar comportamento** sem reler os ficheiros citados e os testes manuais indicados.

---

## 1. Thumbnail capture (`assets/js/eko-thumbnail-export.js`)

### O que não quebrar

- **Lock por template + `runId`**: `acquireSlot` incrementa `runId` e aborta a corrida anterior. Upload/commit deve respeitar `isRunCurrent` antes e depois do async.
- **Fila com debounce + dedupe por `visual_hash`**: duas entradas com o mesmo hash não devem disparar dois uploads paralelos (`queue._pending` + `fp`).
- **`liveCanvasRoot` ligado ao `document`**: só então o adaptador html2canvas usa `captureLiveEditorCanvasToJpeg`; caso contrário cai para `capturePayloadToJpegDom` (tier inferior para fidelidade WYSIWYG).
- **Clone de apresentação** antes do raster: remover chrome (handles, rotate FAB, inline field), **strip de bindings** (`x-*`, `@*`, etc.), sync de **computed styles** de texto do DOM vivo → clone.
- **Ordem no `captureLiveEditorCanvasToJpeg`**: fonts/images → clone → mount → tipografia → layout containers → overflow bump → flatten de `<img>` → `rasterDomToJpegDataUrl`. Reordenar sem evidência = regressão visual.

### Sintomas típicos de regressão

| Sintoma | Causa provável |
|---------|----------------|
| Thumbnail “pisca” entre duas versões | duas corridas sem `runId` / lock |
| Thumbnail antiga após editar | commit após supersede; falta `isRunCurrent` |
| Thumbnail com grelha/handles | `ignoreElements` / `liveEditorThumbnailDomFilter` incompleto |
| Texto cortado ou fonte errada | sync tipográfica omitida ou `document.fonts.ready` ignorado |
| Thumbnail em branco / min bytes | clone com `x-show` falso; imagem ainda a carregar |

### Ficheiros críticos

- `assets/js/eko-thumbnail-export.js`
- `assets/js/eko-canvas-renderer.js` (pipeline THUMBNAIL / `normalizePayload`)
- `includes/class-rest-api.php` (POST thumbnail, validação)
- `docs/thumbnail-system/README.md`, `docs/contracts/THUMBNAIL-RASTER-CONTRACT.md`

---

## 2. Live canvas snapshot (Quick Print + preview)

### O que não quebrar

- **`buildLiveQuickPrintPayload` + `validateQuickPrintLivePayload`**: imagens sem `src` válido bloqueiam — o servidor valida o snapshot (`validate_quick_print_client_snapshot`).
- **`waitVisualRenderStable` → `decodeEditorCanvasImages` → rAF duplo** antes de montar preview ou POST job: snapshot deve refletir **pixels e fontes** prontos, não só JSON.
- **Montagem**: `EkoCanvasRenderer.runRenderPipeline(..., { forPrint: true, target: PRINT })` no `#eko-sampa-quick-print-mount`.

### Sintomas

| Sintoma | Causa provável |
|---------|----------------|
| PDF/impressão vazia | CSS print escondeu o mount (regra `body > *` ou classe no pai errado) |
| Imagens em falta na impressão física | falta `_quickPrintMaterializeImagesForDevicePrint` |
| Preview “sem dimensões” | mount não no DOM; layout ainda 0×0 |

### Ficheiros

- `assets/js/editor-canvas.js` (`mountQuickPrintPreview`, `_injectQuickPrintPrintStyle`, `quickPrintRunBrowser`)
- `docs/quick-print/README.md`, `docs/contracts/PRINT-MEDIA-CONTRACT.md`

---

## 3. Impressão Quick Print — isolamento CSS

### O que não quebrar

- **`#eko-sampa-quick-print-layer`** deve ser o único ramo de `body` visível em `@media print` (regra `body > *:not(#eko-sampa-quick-print-layer)`).
- **Chrome** (toolbar do modal) usa **`.eko-quick-print-hide-print`** — não misturar com a classe do painel que contém o mount; esconder o pai do mount = impressão em branco (lição documentada em `docs/quick-print/README.md`).
- **`@page { size: width_mm height_mm }`** injetado dinamicamente alinhado ao payload.

### Ficheiros

- `assets/js/editor-canvas.js` — `_injectQuickPrintPrintStyle`, `_removeQuickPrintPrintStyle`
- `includes/...` templates PHP do modal (classes `eko-quick-print-*`)

---

## 4. Quick Print — fluxo transacional (job + browser handoff)

### Ordem esperada

1. Utilizador com template gravado (`openQuickPrintManager` força save se `hasUnsavedChanges`).
2. `POST quick-print/jobs` com `editor_preview` (snapshot já normalizado no cliente).
3. `POST quick-print/jobs/{id}/browser-handoff` antes de `window.print()`.
4. `afterprint` → restaurar HTML do mount se quantity > 1 → `POST .../complete`.
5. **`closeQuickPrintManager`**: remover listener `afterprint`, abort fetch, remover estilo print, `disposeQuickPrintPreview`, limpar flags.

### O que não quebrar

- **`quickPrintJobCreating` / `_quickPrintPrintInProgress`**: evitam duplo POST e diálogo de impressão sobreposto.
- **Cancel / reprint**: cancelar job `queued` antes de novo snapshot evita duplicados operacionais confusos.

### Sintomas

| Sintoma | Causa |
|---------|--------|
| Dois jobs para um clique | flags de exclusão mútua removidas |
| Job fica `queued` para sempre | `complete` não chamado; `afterprint` não disparou |
| Mount corrompido após imprimir 2+ cópias | backup/restore `innerHTML` quebrado |

### Ficheiros

- `assets/js/editor-canvas.js`
- `includes/class-quick-print-job.php`, `includes/class-rest-api.php`

---

## 5. Rotate / transform sync (editor)

### O que não quebrar

- Estado lógico (`rotation` / transform no modelo de elemento) deve permanecer **coerente** com o que `EkoCanvasRenderer` e o DOM do editor aplicam; alterar só um lado gera thumbnail/print desalinhados face ao canvas interativo.
- Interact + atualização Alpine: debounces e `nextTick` evitam leitura de geometria a meio do frame.

### Ficheiros

- `assets/js/editor-canvas.js` (handlers rotate/drag/resize)
- `docs/architecture/EDITOR-VISUAL-SYSTEM.md`
- `docs/editor/visual-box-model.md`, `docs/editor/layer-system.md`

---

## 6. Tipografia — computed styles reais

### O que não quebrar

- Thumbnails a partir do **live root** copiam estilos computados das folhas de texto para o clone (`THUMB_TEXT_LEAF_*` no export). **Não** substituir por inferência só a partir de `elements[].styles` sem validar WYSIWYG.

---

## 7. Renderer — estabilização e timing

### O que não quebrar

- **Visual Render Contract** (`eko-visual-render-contract.js` / PHP espelhado): dimensões px/mm coerentes entre THUMBNAIL e PRINT.
- **Timeouts de asset/font** no `runRenderPipeline` para alvo THUMBNAIL — reduzir agressivamente gera raster incompleto.

### Ficheiros

- `assets/js/eko-canvas-renderer.js`
- `docs/architecture/visual-render-contract.md`, `docs/rendering/VISUAL-PIPELINE.md`

---

## 8. Prioridade de capture source (`thumbnail_capture_source`)

1. Meta explícito `thumbnail_capture_source` (se não vazio) — auditoria.
2. `live_editor` se `liveCanvasRoot` conectado ao documento.
3. Caso contrário `client_dom` (host offscreen + payload).

Alterar esta ordem sem motivo quebra métricas e decisões de backfill no servidor.

Ver: `docs/thumbnail-system/CAPTURE-TIERS-AND-META.md`.

---

## Princípio global (repetição intencional)

> **O sistema de thumbnails e o Quick Print devem refletir o estado visual real do editor** quando o caminho ao vivo está disponível. **Não** usar “geração sintética” (re-desenhar de cabeça no servidor) como substituto de preview real sem aceitar perda de fidelidade.

---

## Teste manual mínimo após tocar nestes sistemas

1. Editor: alterar texto + rotação + imagem → guardar → thumbnail atualizado sem artefactos de UI.
2. Quick Print: abrir modal → pré-visualização → imprimir 1 e 3 cópias → verificar ausência de página em branco e recuperação do preview ao fechar.
3. Rede lenta: cancelar modal durante loading — sem erros JS persistentes, sem slot lock eterno.
