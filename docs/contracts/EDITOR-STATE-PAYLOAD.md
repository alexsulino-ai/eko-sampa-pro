# Contrato — estado e payload do editor

## Estado Alpine (editor)

Campos centrais (não exaustivo):

- `elements: Array` — `{ id, type, x, y, width, height, content?, src?, styles?, … }`
- `widthMm`, `heightMm` — tamanho de página
- `templateBackgroundColor` — fundo da página
- `canvasWidth`, `canvasHeight` — px lógicos (VRC)
- `hasUnsavedChanges`, `lastOkFingerprint`, `lastOkElementsJson`

## Payload para renderer / quick print

Após `buildLiveQuickPrintPayload()` + `normalizePayload`:

- `width_mm`, `height_mm` — inteiros ≥ 1
- `background_color` — cor CSS segura
- `elements` — array 1…400 (limite de validação quick print)
- `schema_version`, `units` — preenchidos pelo normalizador

## Invariantes

1. **Imagem** (`type === 'image'`) deve ter `src` ou `content` não vazio para validação quick print.
2. **Clone profundo** antes de enviar — evitar mutação partilhada durante async.
3. **Não** enviar `javascript:` em URLs de imagem (`resolveImageSrc` no renderer bloqueia).

## Quick Print REST

`POST quick-print/jobs` exige `editor_preview` como objeto (não opcional) validado por `validate_quick_print_client_snapshot` em PHP.

## Ficheiros

- `assets/js/editor-canvas.js` — `buildLiveQuickPrintPayload`, `validateQuickPrintLivePayload`
- `includes/class-rest-api.php` — validação servidor
- `includes/class-template-renderer.php` / `Eko_Sampa_Render_Schema` — envelope canónico onde aplicável
