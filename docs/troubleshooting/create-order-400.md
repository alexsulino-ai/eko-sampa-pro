# Troubleshooting: Create order 400

## Sintoma

`POST /wp-json/eko-sampa/v1/orders` → **400**  
`code`: `eko_sampa_order_invalid_relations`

## Passo 1 — Ler `failed_at`

No JSON `data.failed_at`:

| Valor | Significado | Ação |
|-------|-------------|------|
| `template_not_visible` | Template outro user ou inexistente | Verificar ID e login |
| `service_missing_for_template` | Template sem service_id | Editar template, associar serviço |
| `service_not_visible_for_order` | Serviço inválido para order | Ver passo 2 |
| `template_client_mismatch` | client_id order ≠ template (ambos >0) | Enviar 0 ou mesmo cliente |
| `client_not_visible` | Cliente de outro user | Escolher cliente válido |

## Passo 2 — Ler `debug` para serviço

```json
"service_exists": false,
"service_visible_in_scope": false,
"service_visible_for_order": false,
"template_service_id": 12
```

**Órfão:** template aponta ID sem row em `wp_eko_sampa_services`.

### Fix

1. Admin → **Eko Sampa → Diagnostics**
2. **Repair orphan template services**
3. Repetir create order

Alternativa: editar template e escolher serviço existente.

## Passo 3 — Schema

Se PATCH template falha em `client_id`:

- Diagnostics → columns `client_id` = false
- Run check (dispara `ensure_schema`)
- Ver [schema-drift.md](schema-drift.md)

## Passo 4 — Frontend

- Hard refresh (Ctrl+F5)
- `debugRest` e confirmar payload `client_id: 0`, `template_id`, `service_id`
- Confirmar GET template antes do POST (network tab)

## Passo 5 — Logs

`WP_DEBUG` → log integrity em check automático.

## Auto-repair REST falhou

Condições em `maybe_repair_orphan_template_service_for_order` — se repair não criou serviço (wpdb error), corrigir manualmente.

Histórico: [../architecture/lessons-learned.md](../architecture/lessons-learned.md).
