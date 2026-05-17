# Relatório: endurecimento render / layers / debug (2026)

## O que foi blindado

1. **Z-index no editor:** só `stackZ(index)` no raiz do elemento (`10 + index`); selecção com `box-shadow` inset no frame (`editorElementFrameStyle`), sem boosts nem `window.EkoEditorZIndexContract`. Handles com `z-[60]` **só** dentro do host (por cima do frame) e **sem** `translate`/`scale` no host.
2. **`ekoLayerDiagnostics.validate(root, editorState?)`:** detecta `z-*` Tailwind no raiz do canvas, `z-index` fora do triplo {base, +selected, +drag}, stacking context inesperado no host (transform/filter/isolation/perspective), e divergência entre `z-index` no atributo `style` vs computed.
3. **`ekoTextEditDiagnostics.compareMetrics(root)`:** compara métricas computed entre `#eko-inline-edit` e o `span` de preview; expõe `font_metric_drift`, `wrapping_drift`, `line_count_mismatch`, `layout_shift_suspected`, `transform_drift`.
4. **`?visual_regression_debug=1`:** `window.__ekoVisualRegressionDebug.dump({ root?, editorState? })` agrega layer stack, validação, bounds, mapa de z e métricas de overlay de texto.

## Causa raiz residual

- **Boost de editor vs output final:** durante edição, o elemento seleccionado/arrastado ainda sobe na pilha em relação ao PNG/PDF — é intencional; o persistido continua a ser só a ordem do array.
- **`compareMetrics` com span oculto:** com `x-show` falso no span, alguns motores podem devolver métricas menos fiáveis; o diagnóstico serve como heurística, não como prova formal de identidade pixel-perfect.

## Riscos restantes / pontos frágeis

- **Interact** pode voltar a deixar `transform` no nó raiz se algum caminho deixar de limpar `style.transform` — `validate()` avisa.
- **Novos componentes UI** no canvas (badges, labels) que introduzam `z-index` ou `z-*` no raiz — regressão; exigir revisão + `validate()` no PR.
- **Strict `validate(..., editorState)`** depende do consumidor passar o estado Alpine correcto; sem isso, só se aplica o modo “triplo valor” por índice.

## O que não foi alterado (propositalmente)

- `json_data` / schema de elementos, REST, storage, snapshots, pipeline de thumbnail (`eko-thumbnail-export` / geração servidor), e HTML de print além do espelho já existente de `z-index` por índice no PHP.

## Validação manual recomendada

Duplicate template, print, thumbnail, snapshot de encomenda concluída, render de encomenda, drag, resize, edição de texto, upload de imagem — sem alterações contractuais nesses fluxos; apenas ferramentas e constantes de diagnóstico.
