# REST: Templates

## GET `/templates/{id}`

- Usado por `buildCreateOrderPayload` antes de create order
- Respeita ownership — 404 se outro user

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

- `POST /templates/{id}/duplicate` — nova row, ownership atual

Regras de domínio: [../business-rules/templates.md](../business-rules/templates.md).
