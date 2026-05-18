# Continuidade do projeto — visão geral

## Propósito

Este documento liga **camadas**, **contratos** e **pontos sensíveis** para quem retoma o trabalho sem regressões.

## Camadas (resumo)

| Camada | Responsabilidade principal | Ficheiros‑chave |
|--------|------------------------------|-----------------|
| PHP / REST | Persistência, permissões, validação de snapshots | `includes/class-rest-api.php`, models em `includes/` |
| Editor (Alpine) | Estado vivo `elements`, mm, zoom, interact, save | `assets/js/editor-canvas.js`, `views/editor-canvas.php` |
| Contrato visual (VRC) | mm→px, cena print vs thumbnail | `assets/js/eko-visual-render-contract.js` |
| Renderer DOM | HTML do canvas, preload, lifecycle | `assets/js/eko-canvas-renderer.js` |
| Thumbnail export | Lock por template, html2canvas, upload | `assets/js/eko-thumbnail-export.js` |
| Quick Print | Jobs isolados, snapshot cliente, `window.print()` | `assets/js/editor-canvas.js`, `includes/class-rest-api.php` (rotas `quick-print/*`) |

## Princípios que não devem ser violados

1. **Uma matemática de layout** para print/thumbnail: `EkoVisualRenderContract.computeScene` — ver [visual-render-contract.md](visual-render-contract.md).
2. **Thumbnail** deve refletir o **estado visual real** quando `liveCanvasRoot` está disponível; não substituir por payload “sintético” ignorando o DOM.
3. **Quick Print** não substitui **Orders**; é fluxo rápido com snapshot explícito e job transacional.
4. **Print media**: isolamento correto (layer dedicada + classes `eko-quick-print-hide-print`); nunca esconder o mount com a mesma classe do painel inteiro.

## Onde ir a seguir

- Decisões recentes explícitas: [RECENT-ARCHITECTURAL-DECISIONS.md](RECENT-ARCHITECTURAL-DECISIONS.md)
- Contratos e invariantes: [../contracts/DO-NOT-BREAK.md](../contracts/DO-NOT-BREAK.md)
- Editor em detalhe: [EDITOR-VISUAL-SYSTEM.md](EDITOR-VISUAL-SYSTEM.md)
- Thumbnails: [../thumbnail-system/README.md](../thumbnail-system/README.md)
