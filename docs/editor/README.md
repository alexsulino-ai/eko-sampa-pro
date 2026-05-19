# Editor visual — índice de documentação

Documentação do **editor Alpine + Interact**, alinhada ao código em `assets/js/editor-canvas.js` e componentes PHP associados.

---

## Pacote de continuidade (estado atual do sistema)

| Tópico | Documento |
|--------|------------|
| Lifecycle, DOM vivo vs clone, persistência, o que não alterar | [../architecture/EDITOR-VISUAL-SYSTEM.md](../architecture/EDITOR-VISUAL-SYSTEM.md) |
| Pipeline `EkoCanvasRenderer` (print/thumbnail) | [../rendering/VISUAL-PIPELINE.md](../rendering/VISUAL-PIPELINE.md) |
| Quick Print no editor | [../quick-print/README.md](../quick-print/README.md) |
| Contratos e **DO NOT BREAK** | [../contracts/README.md](../contracts/README.md), [../contracts/DO-NOT-BREAK.md](../contracts/DO-NOT-BREAK.md) |

---

## Relatórios e contratos locais (`docs/editor/`)

- [visual-box-model.md](visual-box-model.md) — modelo de caixa e medidas
- [layer-system.md](layer-system.md) — ordem de camadas / z-index
- [z-index-contract.md](z-index-contract.md) — empilhamento UI
- [text-editing-lifecycle.md](text-editing-lifecycle.md) — edição inline
- [visual-editor-bugfix-report.md](visual-editor-bugfix-report.md) — histórico de correções
- [visual-regression-hardening-report.md](visual-regression-hardening-report.md) — endurecimento anti-regressão

---

## Raiz legada

[`../editor.md`](../editor.md) — apontador; evitar duplicar conteúdo fora da árvore temática.
