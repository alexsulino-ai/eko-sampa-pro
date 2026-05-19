# Sistema de thumbnails — visão geral

> **Regra de ouro:** o thumbnail system deve **sempre** refletir o **estado visual real** do editor quando a captura ao vivo é possível e válida.

## Ficheiro principal

`assets/js/eko-thumbnail-export.js` — fila, lock por template, abort, checksum visual, adaptadores de raster, histórico.

## Raster atual

- **html2canvas** (`foreignObjectRendering: false`) → bitmap real → downscale JPEG com flatten de fundo.
- Documentação legada em `docs/templates/thumbnail-pipeline.md` ainda menciona “html-to-image” em alguns pontos; o **código atual** usa **html2canvas** (ver cabeçalho de `eko-thumbnail-export.js`).

## `liveCanvasRoot` e snapshot visual

- Quando o export recebe `meta.liveCanvasRoot` apontando para um elemento **ligado ao documento** (`isConnectedLiveCanvas`), o pipeline pode rasterizar **clone de apresentação** do canvas vivo (sem grelha, handles, textarea inline, zoom parent).
- Isto evita “thumbnail de JSON” desalinhada do que o utilizador vê.

## Metadados de captura

- **`thumbnail_capture_source`** (quando preenchido no meta) força a etiqueta de proveniência no upload.
- Caso contrário: `live_editor` se `liveCanvasRoot` conectado; senão `client_dom` (`resolveThumbnailCaptureSourceForUpload`).

## Tipografia sincronizada

- Lista `THUMB_TEXT_LEAF_COMPUTED_PROPS` + funções de sync copiam **computed styles** do DOM vivo para o clone — essencial para WYSIWYG; não “adivinhar” tipografia só a partir do JSON.

## Decode / fontes / estabilização

- Timeouts configuráveis (`EkoThumbnailConfig`, `FONT_READY_TIMEOUT_MS`, etc.).
- `waitFrame` / rAF entre fases quando necessário para layout estável.
- `document.fonts.ready` no caminho thumbnail do renderer + export combinados.

## Anti‑overwrite e concorrência

- **Slots** por `templateId`: `acquireSlot` / `releaseSlot` / `runId` — nova corrida aborta a anterior (`abortSlot`).
- **Não** iniciar segunda captura para o mesmo ID sem respeitar o lock (regressão típica: JPEG intercalado / ordem errada).

## `ignoreElements` / filtros DOM

- `buildHtml2CanvasIgnoreElements` + `liveEditorThumbnailDomFilter` removem chrome de edição (resize, rotate FAB, inline field, `<template>`).

## Strip de bindings reativos

- `stripReactiveBindingsFromSubtree` remove `x-*`, `@*`, `:`, `wire:*` do clone — evita `x-show="false"` persistente no raster.

## Tiers / fallback

- `RasterAdapters.html2canvas` tenta primeiro captura ao vivo se `ctx.liveCanvasRoot` válido; senão `capturePayloadToJpegDom` (host montado com payload).
- Em falha cliente, REST pode usar fallback servidor (`/thumbnail/generate`) conforme rotas — ver `class-rest-api` / thumbnail class.

## Porque thumbnails eram sobrescritas (causas típicas)

1. Duas corridas em paralelo sem `runId` / lock respeitado.
2. Captura a partir de payload desatualizado enquanto o DOM já mostrava alterações não salvas.
3. Clone com bindings Alpine ainda ativos (`display:none`).

## Documentação relacionada

- [README.md](README.md) (este folder)
- [CAPTURE-TIERS-AND-META.md](CAPTURE-TIERS-AND-META.md) — `thumbnail_capture_source`, tiers, stale prevention
- [../../templates/thumbnail-pipeline.md](../../templates/thumbnail-pipeline.md)
- [../contracts/THUMBNAIL-RASTER-CONTRACT.md](../contracts/THUMBNAIL-RASTER-CONTRACT.md)
- [../contracts/DO-NOT-BREAK.md](../contracts/DO-NOT-BREAK.md)
