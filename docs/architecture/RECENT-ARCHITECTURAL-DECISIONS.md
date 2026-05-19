# Decisões arquiteturais recentes (registo explícito)

Documento curto para **rastreabilidade**: decisões tomadas para reduzir regressões. Detalhes técnicos nos ficheiros ligados.

---

## Preview e thumbnails

1. **Não usar geração sintética** (reconstruir “de cabeça” no servidor) como substituto de **preview real** alinhado ao que o utilizador vê no editor — a divergência WYSIWYG não compensa.
2. O **snapshot** preferencial para raster deve vir do **canvas / DOM vivo** (clone de apresentação), com tipografia sincronizada a partir de **computed styles** reais.
3. **Invariante de produto:** o sistema de thumbnails deve **refletir o estado visual real** do editor quando o caminho ao vivo é válido. Ver [../thumbnail-system/README.md](../thumbnail-system/README.md).

## Impressão e jobs

4. **Jobs de impressão (Quick Print)** seguem um fluxo **transacional** no sentido de produto: criar job com snapshot → `browser-handoff` → `window.print()` → `complete`, com cleanup de listeners e estilos no fecho do modal.
5. O **renderer** (e o cliente antes de captura) deve **aguardar estabilização visual** (fontes, decode de imagens, frames de layout) antes de raster ou POST de snapshot — evita JPEG em branco e payloads inconsistentes.

## Fluxos de negócio

6. **Quick Print não substitui Orders/OS** — continua a ser o fluxo oficial para produção rastreada; Quick Print é atalho operacional com semântica própria.

## Onde está implementado

| Decisão | Código / doc |
|---------|----------------|
| Live snapshot + clone | `assets/js/eko-thumbnail-export.js`, `captureLiveEditorCanvasToJpeg` |
| Lock / stale | `acquireSlot`, `isRunCurrent`, `queue.enqueue` no mesmo ficheiro |
| Quick Print sequence | `assets/js/editor-canvas.js` |
| Print CSS isolation | `_injectQuickPrintPrintStyle` em `editor-canvas.js` |
| Anti-regressão checklist | [../contracts/DO-NOT-BREAK.md](../contracts/DO-NOT-BREAK.md) |
