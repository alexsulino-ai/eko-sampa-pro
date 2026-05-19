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
- Campos opcionais (DB ≥ 1.0.11): `template_type` (`user`|`master`|`session`), `is_public`, `allow_personalization`, … — ver [../template-derivation-system.md](../template-derivation-system.md). Utilizadores sem `manage_options` não podem definir metadados de sessão/master via REST.

## Derivação (DB ≥ 1.0.11; hardening ≥ 1.0.12)

- `POST /public/templates/{id}/session` — cria linha `session` (cópia de trabalho); **sem login**; só se catálogo público (`is_public_catalog` ou `is_public` legado), `allow_personalization=1`, e tipo ≠ `session`. Corpo JSON opcional `{ "reuse": { "template_id": <sessão>, "session_token": "<hex>" } }` — se a sessão ainda for válida para o mesmo master, resposta **200** com `reused: true` (sem novo fork; não dispara `eko_sampa_template_session_forked`). Caso contrário **201** como antes. Ver [../session-hardening.md](../session-hardening.md).
- `POST /templates/{sessionId}/persist-to-mine` — utilizador com sessão iniciada (`require_app_user`); corpo/query com `session_token`; converte sessão em `user` e apaga a sessão.
- `GET /templates?catalog_public=1` — catálogo público (tipos `master` + `user` com flags públicas).
- `GET|PATCH /templates/{id}` (e thumbnails / placeholders): suporta `session_token` (query ou JSON) para editar uma **sessão** sem `user_id`; gate `request_can_use_session_row` (inclui `session_fingerprint` quando preenchido).

## Internals (admin)

- `GET /internals/derivation-stats` — `manage_options`; actualiza e devolve o snapshot `eko_sampa_derivation_observability` (contagens de sessão / quick print).

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
