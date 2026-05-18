# Contrato — raster de thumbnail (cliente)

## Entradas mínimas

- `templateId` válido.
- `payload` normalizado compatível com `EkoCanvasRenderer.normalizePayload`.
- Opcional mas **recomendado** em editor aberto: `liveCanvasRoot` (HTMLElement ligado ao `document`).

## Comportamento do adaptador html2canvas

1. Se `liveCanvasRoot` válido → `captureLiveEditorCanvasToJpeg`.
2. Senão → `capturePayloadToJpegDom` (host `data-eko-thumbnail-capture`).

## Saída

- `data:image/jpeg;base64,...` ou buffer enviado conforme rota REST de upload.
- Metadados: `thumbnail_capture_source`, `capture_tier` / adapter usado (conforme implementação atual).

## Falhas

- Classificação `classifyError` — só retry em erros `retryable`.
- Abort não retry.

## Invariantes

- **Lock por template** — nunca duas capturas concorrentes sem `runId`.
- **Strip de bindings** no clone — obrigatório antes do raster.
- **Não** tratar html2canvas como framebuffer idêntico ao browser (ver limitações).
