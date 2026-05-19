# Frontend: create order

**Símbolos:** `canCreateOrderFrom`, `buildCreateOrderPayload`, `createOrderFromTemplate`  
**Ficheiro:** `assets/js/frontend-app.js` (~1400+)

## canCreateOrderFrom(row)

- Retorna `templateIdFromRow(row) > 0`
- **Não** verifica service no row local — GET fresh resolve

## buildCreateOrderPayload

1. `GET templates/{id}`
2. `normalizeTemplateRow`
3. Exige `service_id > 0` ou toast e abort
4. Retorna payload com **`client_id: 0`** fixo (regra anónimo)

## createOrderFromTemplate

- Busy guard
- POST `orders`
- Redirect para order se `created.id`

## Debug

```javascript
window.ekoSampaRest.debugRest = true;
```

Log: `[eko] createOrder payload`

## Falhas só no frontend

| Mensagem | Causa |
|----------|-------|
| Could not load template | GET falhou |
| Link a service... | service_id 0 no template |

Falhas de integridade (órfão) só aparecem no POST — ver troubleshooting.

Tutorial completo: [../tutorials/create-order-step-by-step.md](../tutorials/create-order-step-by-step.md).
