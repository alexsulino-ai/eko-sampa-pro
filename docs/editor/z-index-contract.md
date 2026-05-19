# Contrato de z-index (editor + render partilhado)

Este documento formaliza **quem** pode alterar `z-index`, **que intervalos** são válidos e **o que nunca** pode ir para `json_data` ou para o pipeline de thumbnail/print.

## 1. Camada base (paint order)

**Fonte única da ordem:** o array `json_data.elements` — índice `0` = fundo, último índice = frente.

**Fórmula (JS e PHP alinhados):**

| Símbolo | Valor | Ficheiro |
|---------|-------|----------|
| `STACK_Z_BASE` | `10` | `assets/js/eko-canvas-renderer.js` |
| `STACK_Z_STRIDE` | `1` | idem (kept so `stackZ(i) === BASE + i`) |
| `stackZ(i)` | `STACK_Z_BASE + i` | `EkoCanvasRenderer.stackZFromIndex`, PHP `render_element` (`10 + $stack_index`) |

- **Quem altera:** apenas o código que monta o estilo do nó raiz do elemento (`.eko-sampa-canvas__element` no renderer/PHP; `.eko-sampa-editor__element` no editor via `elementPositionStyle`).
- **Intervalo aproximado:** para até ~400 elementos, valores base ~`10` … `409`. Não usar valores arbitrários fora desta progressão para “empilhar” conteúdo de layout.

## 2. Editor (sem boosts de `z-index`)

O editor usa **apenas** `stackZ(layerIndex)` no raiz `.eko-sampa-editor__element` (mesma fórmula que o renderer/PHP). **Não** há `+100000` / `+200000`, nem elevação durante drag ou resize — isso quebrava a ordem visual face ao array `elements[]`.

Selecção e hover usam **`box-shadow` inset** fundido no frame (`editorElementFrameStyle` / `_editorFrameInsetChrome` em `assets/js/editor-canvas.js`), não `ring`/`shadow-lg` no host (evitam composição extra no host e não cortam com `overflow:hidden` do frame).

`previewOnly` mantém só `stackZ(layerIndex)` no estilo de posição.

## 3. Quem pode alterar z-index

| Actor | Pode |
|-------|------|
| `EkoCanvasRenderer.elementPositionStyle(item, index)` | Definir **apenas** `stackZ(index)` em HTML de thumbnail/print/mount JS |
| `Eko_Sampa_Template_Renderer::render_element` (PHP) | Espelhar a mesma fórmula por índice |
| `ekoEditorCanvasFactory().elementPositionStyle` | **Apenas** `stackZ` no host (sem `outline`/`box-shadow` no host) |
| `window.ekoLayerDiagnostics.validate()` | **Ler** e comparar; nunca escrever estilos |

| Actor | Não pode |
|-------|----------|
| Classes Tailwind `z-*` no **raiz** `.eko-sampa-editor__element` | Substituir o contrato ou duplicar pilha |
| Campos em `json_data` | Persistir `z-index` para ordem de camadas (a ordem é o array) |
| Plugins de tema aleatórios | Injectar `z-index` global sobre o canvas |

## 4. Ranges e offsets reservados

- **Base:** `[10 + 0, 10 + (n-1)]` = `10` … `10 + n - 1` para `n` elementos.
- **Editor:** valores `stackZ(i)` apenas (sem boost em drag).
- **Handles:** `z-index: 60` (Tailwind `z-[60]`) **só** nos nós de resize, por cima do frame dentro do mesmo host — não altera o `z-index` global do elemento (esse continua `10 + index`).

## 5. O que nunca pode ser persistido

- Qualquer `z-index` calculado (base ou com offsets de editor).
- Qualquer campo paralelo tipo `layerZ` / `globalZ` só para pintura.
- A ordem persistida continua a ser **só** a ordem do array `elements` no `json_data`.

## 6. Diagnóstico

- `window.ekoLayerDiagnostics.collect(document)` — amostra da pilha.
- `window.ekoLayerDiagnostics.validate(document, editorState?)` — avisos sobre `z-*` Tailwind no raiz, `z-index` fora do contrato, stacking context inesperado no raiz, divergência inline vs computed.

Para validação **estrita**, passar `editorState` (opcional; o `z-index` esperado é só `stackZ(layer_index)`).

## 7. Modo debug visual

Com `?visual_regression_debug=1`, ver `window.__ekoVisualRegressionDebug.dump({ editorState })`.

## Ver também

- [layer-system.md](layer-system.md) — fluxo DOM ↔ JSON ↔ renderer  
- [visual-regression-hardening-report.md](visual-regression-hardening-report.md) — endurecimento e riscos residuais  
