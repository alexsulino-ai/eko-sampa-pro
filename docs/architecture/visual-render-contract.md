# Visual Render Contract (VRC)

## Objetivo

Um único cálculo de **layout em px** a partir do payload normalizado (`width_mm`, `height_mm`, `elements`) para:

- superfície **print** (1:1 com mm→px de design);
- superfície **thumbnail** (escala uniforme até `maxWidth`).

Implementação: `assets/js/eko-visual-render-contract.js` expõe `EkoVisualRenderContract.computeScene(payloadNormalized, target, opts)`.

`assets/js/eko-canvas-renderer.js` **exige** esse módulo (`requireVisualContract()`): não existe ramo paralelo com segunda matemática.

## Fases de render (alinhamento com pipeline)

1. **Layout** — apenas `computeScene` + HTML interno do canvas (`buildCanvasInnerHtml`).
2. **Assets** — imagens (`preloadAssets`).
3. **Fontes** (thumbnail) — `document.fonts.ready` com timeout.
4. **Paint** — rasterização (`html-to-image` no export).

## Diagnóstico

- URL: `?render_debug=1`, `?eko_render_debug=1` ou `?visual_debug=1`.
- Com debug: `container._ekoRenderDiagnosis` inclui `unicode_audit`, `layout_surface_drift` (thumbnail), `contract` meta, fonts, assets; eventos `eko-sampa:render:*` incluem `diagnosis` quando aplicável.

## API JS

- `EkoVisualRenderContract.detect_unicode_render_issues(payload)` — texto/placeholder: surrogates órfãos, `\uFFFD`, NUL.

## Constantes

| Símbolo | Valor | Notas |
|---------|-------|-------|
| `MM_TO_CSS_PX` | 96/25.4 | Espelhado em PHP por `Eko_Sampa_Render_Schema::CSS_PX_PER_MM` |
| `THUMBNAIL_MAX_WIDTH_PX` | 520 | Igual a `Eko_Sampa_Template_Thumbnail_Config::MAX_WIDTH_PX` |

## Compatibilidade

O editor (`editor-canvas.js`) usa `EkoVisualRenderContract.mmToCanvasPx` para `canvasWidth` / `canvasHeight`. O handle `eko-sampa-visual-render-contract` está nos `deps` do editor em `Eko_Sampa_Assets` para garantir ordem de carga.

## Anti-patterns (proibidos)

- Segundo `MM_TO_CSS_PX` ou escala de thumbnail só no renderer/editor sem passar por `computeScene`.
- `object-fit` / `transform` “compensatórios” que alterem layout relativamente ao contrato sem registo em diagnóstico.
- Mascarar `img.decode()` falho como sucesso silencioso.
- Reduzir resolução ou blur para esconder drift em produção.
