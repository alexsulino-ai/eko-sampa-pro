# Editor visual — arquitetura e lifecycle

## Componente

- **View:** `views/editor-canvas.php` — `x-data="window.ekoEditorCanvasFactory()"`, toolbar, viewport, sidebar, modais (galeria, Quick Print via `x-teleport="body"`).
- **Lógica:** `assets/js/editor-canvas.js` — factory Alpine; **não** listar Alpine como dependência do bundle (ver cabeçalho do ficheiro).

## Estado “vivo” vs captura

| Conceito | O que é | Onde vive |
|----------|---------|-----------|
| **DOM vivo** | Árvore renderizada pelo Alpine (`x-for`, `x-show`, estilos inline de drag/rotate). Ref principal `x-ref="editorCanvas"`. | `#eko-sampa-editor .eko-sampa-editor__canvas` |
| **Estado lógico** | `elements[]`, `widthMm`/`heightMm`, `templateBackgroundColor`, `canvasWidth`/`canvasHeight` (sincronizados com VRC). | Objeto retornado por `ekoEditorCanvasFactory()` |
| **Clone de captura** | Cópia ou ramo offscreen usado por thumbnail export / normalização para raster — remove chrome de edição, bindings, etc. | `eko-thumbnail-export.js` (apresentação) |
| **Snapshot JSON (Quick Print)** | `buildLiveQuickPrintPayload()` — `JSON.stringify` de `elements` + mm + fundo, depois `EkoCanvasRenderer.normalizePayload`. Não é “reconstruir do zero” no servidor para o job atual. | `editor-canvas.js` |

## O que **não** alterar sem revisão total

- **Ordem de scripts:** editor antes de Alpine (`Eko_Sampa_Assets`).
- **Contrato VRC:** `canvasWidth`/`canvasHeight` devem continuar alinhados a `EkoVisualRenderContract.mmToCanvasPx` (ver documentação VRC).
- **Interact + Sortable:** rebinding após mutações de lista; não duplicar listeners.
- **Persistência:** `saveNow`, fingerprints `lastOkFingerprint` / `hasUnsavedChanges` — alterar sem testes quebra revert/reload.

## Transformações (rotate / drag / resize)

- Geometria em estado: `x`, `y`, `width`, `height`, `styles.rotate`, etc.
- **DOM:** `elementPositionStyle`, `editorRotateWrapStyle`, handles de resize no template.
- **Print/Thumbnail:** o renderer (`eko-canvas-renderer.js`) consome o **mesmo** modelo normalizado, não o DOM do editor diretamente (exceto path de thumbnail **live** que clona o DOM).

## Placeholders e texto

- `type === 'placeholder' | 'text'` com `content` editável (inline textarea quando aberto).
- Tokens / substituição em fluxos de encomenda são responsabilidade do pipeline de order — no editor puro o conteúdo é o guardado em JSON.

## Escala e zoom

- `zoomPercent` + `stageTransform` aplicam `scale` no **stage**, não alteram mm de página.
- **Quick Print** e thumbnails usam o payload em **unidades de desenho** (canvas lógico / mm), não o zoom de ecrã como “tamanho de página”.

## Sincronização DOM ↔ estado

- Alpine é a fonte de verdade para lista de elementos; o DOM reflete `elements`.
- Após operações assíncronas, usar `$nextTick` (e por vezes duplo `requestAnimationFrame`) antes de medir layout ou capturar.

## Persistência

- REST `templates` (ver `docs/rest-api/templates.md`); save bloqueia estados inválidos.
- Quick Print exige template persistido (`templateId`) para abrir o gestor — ver `quick-print/README.md`.

## Leituras relacionadas

- [../rendering/VISUAL-PIPELINE.md](../rendering/VISUAL-PIPELINE.md)
- [../contracts/EDITOR-STATE-PAYLOAD.md](../contracts/EDITOR-STATE-PAYLOAD.md)
- [../editor/text-editing-lifecycle.md](../editor/text-editing-lifecycle.md) (já existente)
