# Resolução de `preview_image` e URL pública

## Armazenamento

- Valor na base de dados: caminho **relativo ao `wp_upload_dir()['basedir']`**, sem barra inicial (ex.: `eko-sampa/users/user-3/templates/12.jpg` ou legado `eko-sampa/templates/12.jpg`).
- Nunca usar esse valor cru como `href` no browser: o URL seria relativo à página atual e quebra.

## Resolver centralizado

`Eko_Sampa_Storage_Manager`:

- `normalize_upload_relative()` — rejeita `..`, URLs com scheme, normaliza `\` → `/`.
- `resolve_upload_relative_to_abs()` — só devolve caminho se passar `safe_path_guard` sob **uploads** (leitura).
- `public_url_for_upload_relative()` — devolve URL pública só se o ficheiro existir e for legível.

## API enriquecida

`Eko_Sampa_Template_Thumbnail::enrich_row()` acrescenta:

| Campo | Significado |
|-------|-------------|
| `preview_image_public_url` | URL pública do ficheiro referido por `preview_image`, ou fallback para `thumbnail_url` se existir thumbnail canónico |
| `preview_image_resolved` | `true` se o valor guardado em `preview_image` corresponde a um ficheiro existente sob uploads |

## UI

O detalhe do template deve mostrar o link com `preview_image_public_url` e, opcionalmente, o path relativo apenas como texto informativo (não como `href`).
