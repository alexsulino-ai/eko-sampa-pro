# Relatório: correções visuais do editor (camadas, clipping, texto)

Data de referência: 2026-05-16.

## Bug 1 — Camadas / sobreposição

### Causa raiz

O template Alpine aplicava classes Tailwind de **`z-index` fixo** (`z-10`, `hover:z-20`, `z-30`, `z-50`) nos nós `.eko-sampa-editor__element`. Isso **sobrepujava** a ordem lógica de `json_data.elements`: um elemento não selecionado com hover podia saltar acima de outro com maior índice, e o selecionado ficava sempre “no topo” independentemente da lista de camadas.

O pipeline de thumbnail/print (JS renderer e PHP) **não definia `z-index`**, confiando só na ordem do DOM — comportamento correto, mas **inconsistente** com o editor quando Tailwind alterava a pilha.

### Correção

- Contrato numérico partilhado: `STACK_Z_BASE = 10`, `STACK_Z_STRIDE = 4`, `z = 10 + index * 4` no `EkoCanvasRenderer.elementPositionStyle(item, index)` e no PHP `Eko_Sampa_Template_Renderer::render_element`.
- Editor: `elementPositionStyle` usa o renderer + índice e aplica **boost** só no modo edição (não em `previewOnly`).
- Remoção das classes Tailwind de `z-index` no canvas; botões **Bring forward** / **Send backward** alteram só o array (sem segundo modelo de camadas).
- `normalizeLayerOrder()` como hook documentado após reordenações.

### Risco residual

O boost do elemento selecionado/arrastado **altera** a pilha visual em relação ao output final enquanto edita — intencional para UX (handles, arrasto). O output gravado continua a seguir só a ordem do array.

## Bug 2 — Background / borda / raio “cortando”

### Causa raiz

`elementFrameCss` aplicava **`overflow: hidden`** a todos os tipos. Em **texto**, o conteúdo interno usa `padding` + `overflow: auto/hidden` no span; o frame cortava sombras, bordas e por vezes o fluxo visual esperado quando combinado com `border-radius` e `box-shadow`.

### Correção

- **Texto** e **placeholder**: `overflow: visible` no frame (JS + PHP espelhado).
- **Imagem** e **retângulo**: mantêm `overflow: hidden` para clipping correto.

### Risco residual

A superfície `.eko-sampa-canvas` mantém `overflow: hidden`; sombras muito grandes podem ainda ser cortadas **na borda da página** (comportamento anterior, desejável para delimitar a folha).

## Bug 3 — Texto a “piscar” ao editar

### Causa raiz

O `<textarea>` usava classes utilitárias (`text-sm`, `p-1.5`, `bg-white/95`, etc.) **diferentes** do `<span>` que usa `textContentCss(item)` — saltos de métricas e aparência ao alternar.

### Correção

- `inlineEditorTextareaCss(item)` reutiliza a mesma cadeia de estilo do span + resets mínimos de controlo.
- `rows="1"` para aproximar o bloco ao modo visual único.

### Risco residual

Continua a haver troca `x-if` entre span e textarea (um remount por sessão de edição). O diagnóstico `ekoTextEditDiagnostics` expõe `layout_shift_detected` para validar em casos extremos (fontes lentas, zoom).

## Ficheiros alterados

| Ficheiro | Alteração |
|----------|-----------|
| `assets/js/eko-canvas-renderer.js` | `STACK_Z_*`, `stackZFromIndex`, `elementPositionStyle(item, idx)`, `overflow` por tipo, `buildElementHtml` com índice |
| `assets/js/editor-canvas.js` | `elementPositionStyle` + boosts, `normalizeLayerOrder`, bring/send, `inlineEditorTextareaCss`, diagnósticos `window.*` |
| `includes/class-template-renderer.php` | `z-index` por índice, `overflow` por tipo no frame |
| `views/editor-canvas.php` | Remoção de `z-*` no canvas, `(item, idx)`, `data-layer-index`, textarea estilizado, botões de camada |
| `views/editor-canvas-order-preview.php` | Alinhamento de classes / índice com o editor |
| `docs/editor/layer-system.md` | Documentação do modelo de camadas |
| `docs/editor/visual-box-model.md` | Documentação do box model |
| `docs/editor/text-editing-lifecycle.md` | Ciclo de edição de texto |
| `docs/editor/visual-editor-bugfix-report.md` | Este relatório |

## O que não foi alterado (de propósito)

- Motor `html-to-image`, uploads de thumbnail, REST, storage, snapshots, `json_data` schema, contrato visual além de `z-index` derivado e `overflow` por tipo.
- `EkoVisualRenderContract.computeScene` (sem segunda lógica de escala).
- Regras de negócio de templates/orders.

## Compatibilidade

- **Thumbnail / print**: passam a usar o mesmo `z-index` derivado do índice que o DOM já respeitava implicitamente — alinhamento **melhor** com a ordem do array, sem novo campo persistido.
- **Duplicate template**: inalterado; continua a duplicar elementos no array.

## Validação recomendada (manual)

- Vários elementos sobrepostos; reordenar na lista; bring forward/back.
- Texto com borda grossa, `border-radius` alto, sombra, padding.
- Imagem com `border-radius` (deve continuar a recortar).
- Thumbnail após gravar; pré-visualização de impressão PHP.

## Atualização — endurecimento (pós-correcção)

Contrato explícito de offsets: [z-index-contract.md](z-index-contract.md). Ferramentas: `ekoLayerDiagnostics.validate()`, `ekoTextEditDiagnostics.compareMetrics()`, `?visual_regression_debug=1` + `window.__ekoVisualRegressionDebug.dump()`. Relatório agregado: [visual-regression-hardening-report.md](visual-regression-hardening-report.md).
