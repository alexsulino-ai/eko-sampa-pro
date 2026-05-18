# Pipeline visual (renderização)

## Papel do `EkoCanvasRenderer`

- **Entrada:** payload normalizado (`width_mm`, `height_mm`, `background_color`, `elements[]`, `schema_version`, `units`…).
- **Contrato:** `normalizePayload` → `buildPrintRootHtml` / `buildThumbnailRootHtml` → `container.innerHTML` → `preloadAssets` → (thumbnail) espera de fontes → eventos de lifecycle.
- **Saída:** DOM sob o container alvo (print mount, thumbnail host, Quick Print mount).

Ficheiro: `assets/js/eko-canvas-renderer.js`.

## Fases (alinhamento com VRC)

1. **Layout** — `EkoVisualRenderContract.computeScene` — única fonte de escala/dimensões.
2. **HTML** — `buildCanvasInnerHtml` + `buildElementHtml` (imagens, texto, retângulos).
3. **Assets** — `preloadImages` / timeouts / retries.
4. **Fontes** — principalmente no alvo **thumbnail** (`document.fonts.ready` com timeout no pipeline).
5. **Paint ready** — eventos `eko-sampa-print-ready` / lifecycle para consumidores.

## Alvos (`RenderTargets`)

- **PRINT** — superfície 1:1 com mm de desenho (Quick Print modal, página de impressão standalone).
- **THUMBNAIL** — escala uniforme até `THUMBNAIL_MAX_WIDTH_PX`.

## Impressão vs ecrã

- **Standalone print page:** `views/frontend-print.php` + `assets/js/eko-print-mount.js` + `assets/css/eko-print.css` — corpo `eko-sampa-print-page`, isolamento documentado em [../architecture/print-isolation.md](../architecture/print-isolation.md).
- **Quick Print:** `window.print()` no contexto do editor + CSS inject em `editor-canvas.js` — ver [../quick-print/README.md](../quick-print/README.md).

## Anti‑padrões

- Segunda matemática mm→px fora do VRC.
- Ignorar falhas de `img.decode()` como sucesso.
- Renderizar thumbnail a partir de payload “inventado” quando o editor está aberto e coerente (perde fidelidade).

## Referências cruzadas

- [../architecture/visual-render-contract.md](../architecture/visual-render-contract.md)
- [../contracts/VISUAL-RENDER-CONTRACT.md](../contracts/VISUAL-RENDER-CONTRACT.md)
