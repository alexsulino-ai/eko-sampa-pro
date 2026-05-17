# Contrato de z-index (editor + render partilhado)

Este documento formaliza **quem** pode alterar `z-index`, **que intervalos** são válidos e **o que nunca** pode ir para `json_data` ou para o pipeline de thumbnail/print.

## 1. Camada base (paint order)

**Fonte única da ordem:** o array `json_data.elements` — índice `0` = fundo, último índice = frente.

**Fórmula (JS e PHP alinhados):**

| Símbolo | Valor | Ficheiro |
|---------|-------|----------|
| `STACK_Z_BASE` | `10` | `assets/js/eko-canvas-renderer.js` |
| `STACK_Z_STRIDE` | `4` | idem |
| `stackZ(i)` | `STACK_Z_BASE + i * STACK_Z_STRIDE` | `EkoCanvasRenderer.stackZFromIndex`, PHP `render_element` |

- **Quem altera:** apenas o código que monta o estilo do nó raiz do elemento (`.eko-sampa-canvas__element` no renderer/PHP; `.eko-sampa-editor__element` no editor via `elementPositionStyle`).
- **Intervalo aproximado:** para até ~400 elementos, valores base ~`10` … `1606`. Não usar valores arbitrários fora desta progressão para “empilhar” conteúdo de layout.

## 2. Offsets temporários (só editor, nunca persistidos)

Definidos em `window.EkoEditorZIndexContract` (`assets/js/editor-canvas.js`, antes do factory Alpine):

| Campo | Valor actual | Semântica |
|-------|--------------|-----------|
| `HOVER_OFFSET` | `0` | Reservado; **não** usar classes Tailwind `z-*` no raiz do canvas para simular hover. Banda reservada documentada: `HOVER_BAND_MIN`–`HOVER_BAND_MAX` (1–99) para futura elevação de hover **só via JS**, se algum dia for necessário. |
| `SELECTED_OFFSET` | `0` | **Desactivado.** A selecção **não** altera `z-index` — um boost antigo (`+100000`) fazia o elemento seleccionado saltar para cima de todas as camadas e quebrava a ordem visual vs. `elements[]`. |
| `DRAGGING_OFFSET` | `0` | **Legado / ignorado.** Durante arrasto ou resize, o `z-index` do elemento activo passa a `stackZ(n-1) + STACK_Z_STRIDE + 2` (uma faixa acima do topo da pilha), calculado em `elementPositionStyle` — ver abaixo. |
| `RESIZE_HANDLE_Z` | `60` | Referência para handles **filhos** (Tailwind `z-[60]`); **não** aplicar ao raiz `.eko-sampa-editor__element`. |

**Regra de combinação (editor, não preview):**

```
z_base = stackZ(layerIndex)
z_final = z_base + HOVER_OFFSET (se activo e elemento hovered)

Se draggingId === item.id:
  z_final = stackZ(max(0, elements.length - 1)) + STACK_Z_STRIDE + 2
```

Ou seja: **só** o elemento em movimento sobe ligeiramente acima do último índice; a selecção não muda a pilha.

`previewOnly` (pré-visualização de encomenda) **não** aplica estes ajustes — apenas `stackZ(layerIndex)`.

## 3. Quem pode alterar z-index

| Actor | Pode |
|-------|------|
| `EkoCanvasRenderer.elementPositionStyle(item, index)` | Definir **apenas** `stackZ(index)` em HTML de thumbnail/print/mount JS |
| `Eko_Sampa_Template_Renderer::render_element` (PHP) | Espelhar a mesma fórmula por índice |
| `ekoEditorCanvasFactory().elementPositionStyle` | `stackZ` + hover opcional + substituição durante drag conforme contrato quando **não** `previewOnly` |
| `window.ekoLayerDiagnostics.validate()` | **Ler** e comparar; nunca escrever estilos |

| Actor | Não pode |
|-------|----------|
| Classes Tailwind `z-*` no **raiz** `.eko-sampa-editor__element` | Substituir o contrato ou duplicar pilha |
| Campos em `json_data` | Persistir `z-index` para ordem de camadas (a ordem é o array) |
| Plugins de tema aleatórios | Injectar `z-index` global sobre o canvas |

## 4. Ranges e offsets reservados

- **Base:** `[10 + 0*4, 10 + (n-1)*4]` para `n` elementos.
- **Editor:** valores `stackZ(i)`; durante drag, um único valor global `stackZ(n-1) + STACK_Z_STRIDE + 2` para o elemento arrastado (n = número de elementos no canvas).
- **Handles:** `z-index: 60` (ou equivalente) **só** em nós filhos com classe de resize, nunca no raiz do elemento de layout.
- **Reservado futuro:** `HOVER_BAND_MIN`–`HOVER_BAND_MAX` para hover sem colidir com a progressão `stackZ`.

## 5. O que nunca pode ser persistido

- Qualquer `z-index` calculado (base ou com offsets de editor).
- Qualquer campo paralelo tipo `layerZ` / `globalZ` só para pintura.
- A ordem persistida continua a ser **só** a ordem do array `elements` no `json_data`.

## 6. Diagnóstico

- `window.ekoLayerDiagnostics.collect(document)` — amostra da pilha.
- `window.ekoLayerDiagnostics.validate(document, editorState?)` — avisos sobre `z-*` Tailwind no raiz, `z-index` fora do contrato, stacking context inesperado no raiz, divergência inline vs computed.

Para validação **estrita**, passar `editorState` com as mesmas chaves que o Alpine (`selectedId`, `draggingId`, `hoveredId` ou `hoveredElementId`, `previewOnly`, **`elements`** para o cálculo do `z` durante drag). Pode copiar `$data` do `#eko-sampa-editor`.

## 7. Modo debug visual

Com `?visual_regression_debug=1`, ver `window.__ekoVisualRegressionDebug.dump({ editorState })`.

## Ver também

- [layer-system.md](layer-system.md) — fluxo DOM ↔ JSON ↔ renderer  
- [visual-regression-hardening-report.md](visual-regression-hardening-report.md) — endurecimento e riscos residuais  
