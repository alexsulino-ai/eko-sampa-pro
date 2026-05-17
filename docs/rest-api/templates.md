# REST: Templates

## GET `/templates/{id}`

- Usado por `buildCreateOrderPayload` antes de create order
- Respeita ownership — 404 se outro user
- Query opcional: `inspect_duplicate=1` — acrescenta `duplicate_inspect` (read-only curto)
- Query opcional: `inspect_duplicate=deep` — acrescenta `duplicate_inspect_deep` (schema, dry-run `try_create`, `insert_diagnostics`, candidatos de falha)
- `GET /templates/{id}/duplicate-diagnostics` — bundle `duplicate_diagnostics_bundle` (readiness, drift servidor, `final_row_meta`, repairs)

## PATCH `/templates/{id}`

- `client_id` opcional (0 permitido)
- Requer coluna física `client_id` (migração 1.0.4 + alignment)
- `json_data` validado por tamanho

## POST `/templates`

- `user_id` definido pelo model (current user)

## Thumbnail routes

- `/templates/{id}/thumbnail`
- `/templates/{id}/thumbnail/generate`

Pipeline separado — não alterar `service_id`.

## Placeholders

- `GET /templates/{id}/placeholders` — lista tokens para preview/print

## Duplicate

- `POST /templates/{id}/duplicate` — `build_duplicate_create_data` + `try_create`; `preview_image` vazio até cópia do JPG
- **Thumbnail:** falha na cópia **não** apaga o template novo; resposta `201` com `duplicate_warnings` (objeto) e audit; regenerar via `/thumbnail*`
- Falha de insert: `eko_sampa_duplicate_failed` com `duplicate_try`, `insert_diagnostics`, `mysql_errno`, `sql_state`, `offending_column`, `db_last_error`

Regras de domínio: [../business-rules/templates.md](../business-rules/templates.md).  
Ciclo de vida: [../templates/duplicate-lifecycle.md](../templates/duplicate-lifecycle.md).
