# Fluxos de orders

## Manual (sem template)

1. `/eko-sampa_orders/new/`
2. Utilizador escolhe client, service, template opcional
3. `POST /orders` com payload do form
4. `relations_validate` para cada FK > 0

## Via template (principal)

Ver [../tutorials/create-order-step-by-step.md](../tutorials/create-order-step-by-step.md).

## Duplicar order

- REST `POST /orders/{id}/duplicate`
- Model copia row + relações

## Render / print

- `POST /orders/{id}/render`
- Frontend print route: `/eko-sampa_print/{order_id}/`

## Status workflow

Valores em `schema.md`: `pending` → `in_progress` → `print_queue` → `completed`.

Regras de transição: implementação em `class-order.php` `update` / UI — não há state machine separada.

## WooCommerce

- Campo `woo_order_id` na order
- Bridge admin opcional — não altera `relations_validate`
