# Limitações conhecidas (honestas)

Objetivo: **não esconder** fragilidades inerentes a rasterização DOM, impressão do browser e dependências de rede. Estas limitações **não são bugs a “corrigir” com um if** — são fronteiras do modelo.

---

## html2canvas / raster DOM

- **Não é um framebuffer do browser**: o resultado pode divergir do que o utilizador vê no ecrã (sub-pixel, compositing, layers GPU).
- **CSS avançado**: `mix-blend-mode`, filtros complexos, máscaras e algumas sombras podem rasterizar de forma diferente ou ser omitidas.
- **Taint / CORS**: `allowTaint: false` e políticas de imagem — URLs cross-origin sem CORS adequado falham ou são omitidas.
- **Performance**: páginas muito densas ou muitas imagens grandes aproximam-se de timeout (`GENERATION_TIMEOUT_MS`).

---

## `window.print()`

- **Não expõe cancelamento**: o browser não fornece API fiável “utilizador cancelou”; `afterprint` pode comportar-se de forma inconsistente entre motores.
- **Raster do SO/driver**: o mesmo HTML pode produzir PDFs ligeiramente diferentes entre máquinas (margens, substituição de fontes).

---

## Tipografia

- **Fontes web**: até `document.fonts.ready`, métricas podem mudar; por isso existem esperas explícitas no pipeline de thumbnail e quick print.
- **Fallback de fonte**: se a fonte final difere entre preview e impressão, o layout pode reflow.

---

## Quick Print vs Orders

- Quick Print é **fluxo rápido** com snapshot cliente; **não** substitui OS/Orders para rastreio operacional completo, histórico de produção nem regras de negócio do fluxo oficial.

---

## Sincronização e corrida

- Múltiplos separadores no mesmo template podem gerar corridas; o lock por `templateId` mitiga no **mesmo** browser, não entre dispositivos.

---

## Leitura relacionada

- [../contracts/DO-NOT-BREAK.md](../contracts/DO-NOT-BREAK.md)
- [../thumbnail-system/README.md](../thumbnail-system/README.md)
- [../quick-print/README.md](../quick-print/README.md)
