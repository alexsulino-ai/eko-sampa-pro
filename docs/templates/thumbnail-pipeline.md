# Thumbnail pipeline — contrato e garantias incrementais

## Visão geral

O JPEG persistido por template é a **fonte de verdade visual** na listagem e no detalhe (`thumbnail_url`). O campo `preview_image` no MySQL guarda o caminho **relativo ao diretório de uploads** do ficheiro canónico (quando válido), alinhado com `Eko_Sampa_Storage_Manager` e `Eko_Sampa_Template_Thumbnail`.

## Cliente (DOM → JPEG)

0. **Contrato visual obrigatório:** `EkoVisualRenderContract.computeScene` (`eko-visual-render-contract.js`) — **única** fonte de mm→px, escala de thumbnail e geometria escalada; `eko-canvas-renderer.js` falha de propósito se o contrato não existir (sem ramo paralelo).
1. **Montagem:** `EkoCanvasRenderer.runRenderPipeline` com `RenderTargets.THUMBNAIL` (`eko-canvas-renderer.js`).
2. **Assets:** `preloadAssets` — imagens com timeout, retries (`eko-thumbnail-config.js` → `ASSET_RETRIES`); `decode()` falho reporta `decode_failed` (não mascarado como `ready`).
3. **Tipografia:** após assets, em modo thumbnail, espera-se `document.fonts.ready` com timeout (`FONT_READY_TIMEOUT_MS`). Em timeout dispara-se `eko-sampa:render:fonts-timeout` (evento explícito).
4. **Raster:** `html-to-image` via `eko-thumbnail-export.js` — `pixelRatio` limitado por `CAPTURE_PIXEL_RATIO_CAP`, `skipAutoScale: true` mantido.
5. **Upload:** `POST /templates/{id}/thumbnail` → `Eko_Sampa_Template_Thumbnail::save_from_data_url` / `save_jpeg_binary`.

## Servidor (fallback)

Se o cliente falhar, mantém-se o fallback existente para `POST /templates/{id}/thumbnail/generate` (GD no servidor). O GD usa `Eko_Sampa_Render_Schema::CSS_PX_PER_MM` e o mesmo `MAX_WIDTH_PX` que o cliente — evita drift numérico entre motor antigo (`MM_TO_PX` solto) e o contrato.

## Drift (servidor)

- `Eko_Sampa_Visual_Drift_Diagnostics::analyze_template_row()` compara dimensões esperadas do contrato com o JPEG em disco (e heurísticas de aspect ratio em `json_data`). Útil em `GET …/duplicate-diagnostics` e relatórios; não substitui métricas DOM no browser.

## Debug

- `?eko_render_debug=1`, `?render_debug=1` ou `?visual_debug=1` — diagnóstico alargado: `unicode_audit`, `layout_surface_drift` (thumbnail), `contract` meta, fonts, assets; `container._ekoRenderDiagnosis`.

## Ficheiros de referência

- `assets/js/eko-thumbnail-export.js`
- `assets/js/eko-visual-render-contract.js`
- `assets/js/eko-canvas-renderer.js`
- `assets/js/eko-thumbnail-config.js`
- `includes/class-template-thumbnail.php`
- `includes/class-template-thumbnail-config.php`
- `includes/class-visual-drift-diagnostics.php`
