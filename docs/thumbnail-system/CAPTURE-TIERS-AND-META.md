# Thumbnail — tiers de captura e metadados

> Nome do ficheiro: **tiers** (níveis de captura), não “tears”.

## Ordem de decisão (`resolveThumbnailCaptureSourceForUpload`)

1. Se `meta.thumbnail_capture_source` não vazio → usado tal qual (auditoria / telemetria).
2. Senão, se `meta.liveCanvasRoot` está ligado ao `document.body` → `live_editor`.
3. Caso contrário → `client_dom` (montagem a partir de payload no host offscreen).

## Adaptadores (`RasterAdapters`)

- **html2canvas** — caminho principal; ramifica entre `captureLiveEditorCanvasToJpeg` e `capturePayloadToJpegDom`.
- **server** — fallback quando o cliente falha (ver fluxo em `captureAndUpload` / `exportThumbnailAndUpload`).

## `captureWithRetry`

- Classifica erros (`classifyError`) — rede vs fatal vs abort.
- Retries apenas quando `retryable`.

## Propriedades de contexto críticas

| Campo | Uso |
|-------|-----|
| `liveCanvasRoot` | Elemento raiz do canvas no editor |
| `signal` | `AbortController` do slot |
| `timeoutMs` / `fontReadyTimeoutMs` | Limites de espera |
| `liveDomFilter` | Filtro extra opcional |

## “Stale prevention”

- Incremento de `runId` em nova aquisição + abort da corrida anterior evita commit de JPEG de captura antiga.
- Antes de upload, verificar `isRunCurrent` onde aplicável no fluxo.

## Backfill

- Regeneração servidor / reparos de ficheiro em disco são responsabilidade PHP (`Eko_Sampa_Template_Thumbnail` e rotas REST) — não duplicar lógica de raster no cliente para “consertar” disco sem critério.

## Leitura de código

- `captureLiveEditorCanvasToJpeg` — timeout + fontes + html2canvas.
- `captureAndUpload` / `exportThumbnailAndUpload` — orquestração e metadados enviados ao REST.
