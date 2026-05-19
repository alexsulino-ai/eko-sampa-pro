# Contrato — impressão (média print)

## Standalone (`/eko-sampa_print/…`)

- Corpo com classe `eko-sampa-print-page`.
- Mount `#eko-sampa-print-mount`, payload `window.ekoSampaPrintPayload`.
- CSS `assets/css/eko-print.css` — isolamento UI vs superfície; ver [../architecture/print-isolation.md](../architecture/print-isolation.md).

## Quick Print (modal no editor)

- Não usa a página standalone; usa `window.print()` com:
  - `#eko-sampa-quick-print-layer` visível;
  - resto de `body > *` escondido no `@media print`;
  - `.eko-quick-print-hide-print` para cabeçalho/rodapé/controles;
  - `#eko-sampa-quick-print-mount` contém `.eko-sampa-print-root` (pipeline `EkoCanvasRenderer`).

## Invariantes

- O **mount** nunca pode ficar dentro de um nó que recebe `display:none` por regra acidental de “chrome” partilhada.
- `@page size` deve refletir `width_mm` × `height_mm` do snapshot.
