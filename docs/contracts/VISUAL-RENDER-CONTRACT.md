# Contrato — Visual Render Contract (resumo)

## Fonte de verdade

`assets/js/eko-visual-render-contract.js` — função `computeScene(payload, target, opts)`.

## Saídas usadas pelo renderer

- Dimensões em px do canvas de desenho vs thumbnail.
- `elementsForRender` — lista já com geometria adequada ao alvo.

## PHP espelhado

`Eko_Sampa_Render_Schema::CSS_PX_PER_MM` e limites de thumbnail devem manter‑se alinhados (ver tabela no documento longo).

## Documento completo

[../architecture/visual-render-contract.md](../architecture/visual-render-contract.md)

## Padrões a evitar

- Segunda matemática de layout paralela ao VRC (drift print vs thumbnail).
- Mascarar falhas de decode de imagem em vez de falhar cedo ou retry controlado.
