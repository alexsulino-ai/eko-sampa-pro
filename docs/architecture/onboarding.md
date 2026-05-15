# Onboarding técnico

## Pré-requisitos

- WordPress 6.0+, PHP 8.0+
- Node não obrigatório (assets committed)
- Opcional: WooCommerce para bridge admin

## Instalação local

1. Copiar plugin para `wp-content/plugins/eko-sampa/`
2. Ativar no admin WP
3. **Settings → Permalinks → Save** (rewrites)
4. Confirmar `eko_sampa_db_version` = `1.0.5` (ferramentas ou Diagnostics)
5. Login como utilizador com role Eko (ou `manage_options`)

Ver `INSTALL.md` para detalhes de utilizador.

## Primeiro smoke test

| Passo | URL / ação |
|-------|------------|
| Dashboard | `/eko-sampa_dashboard/` |
| Templates list | `/eko-sampa_templates/` |
| Template view | `/eko-sampa_templates/{id}/` |
| Create order | Botão na view (cap `order.create`) |
| Diagnostics | WP Admin → **Eko Sampa → Diagnostics** |

## Ficheiros que vai editar com frequência

| Área | Ficheiro |
|------|----------|
| Regra order/template | `includes/class-order.php` |
| REST | `includes/class-rest-api.php` |
| Schema | `includes/class-database.php` |
| Integridade | `includes/class-database-integrity.php` |
| UI templates/orders | `views/crud/*.php`, `assets/js/frontend-app.js` |

## Debug create order

1. Browser: `window.ekoSampaRest.debugRest = true` → log do payload
2. Resposta 400: ler `failed_at` e `debug` no JSON
3. Admin: Diagnostics → orphan template→service
4. `WP_DEBUG` + log integrity para órfãos em check automático

## Leitura obrigatória (ordem)

1. [domain-contracts.md](domain-contracts.md)
2. [../business-rules/orders.md](../business-rules/orders.md)
3. [../tutorials/create-order-step-by-step.md](../tutorials/create-order-step-by-step.md)
4. [lessons-learned.md](lessons-learned.md)
5. [../MAINTENANCE.md](../MAINTENANCE.md)

## CLI repair (opcional)

`bin/eko-sampa-repair-db.php` chama `ensure_schema()` — útil em deploy script, não substitui Diagnostics UI.
