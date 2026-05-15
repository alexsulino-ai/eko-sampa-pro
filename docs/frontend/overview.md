# Frontend

## Stack

- Tailwind (CDN MVP)
- Alpine.js 3 — `assets/js/frontend-app.js`
- Editor: `editor-canvas.js` + Interact/Sortable

## Rotas virtuais

`Eko_Sampa_Frontend_Router` — slugs `eko-sampa_*`:

| Slug | view |
|------|------|
| `eko-sampa_dashboard` | dashboard |
| `eko-sampa_login` | login |
| `eko-sampa_clients` | clients CRUD |
| `eko-sampa_services` | services CRUD |
| `eko-sampa_templates` | templates CRUD |
| `eko-sampa_orders` | orders CRUD |
| `eko-sampa_editor` | editor canvas |
| `eko-sampa_profile` | profile |
| `eko-sampa_print/{id}` | print view |

CRUD pattern: `/slug/`, `/slug/new/`, `/slug/{id}/`, `/slug/{id}/edit/`

## Shell

- `views/frontend-shell.php` + partials
- Shortcode `[eko_sampa_shell view="..."]`

## REST no browser

`window.ekoSampaRest`:

- `root`, `nonce`, `capabilities`, `debugRest`

`window.ekoSampaCan('order.create')` — fallback true se função ausente (legado).

## Factories Alpine (principais)

| Factory | Módulo |
|---------|--------|
| `templateCrud` | templates list/view/edit |
| `orderCrud` | orders |
| `clientCrud` | clients |
| `serviceCrud` | services |

Detalhe CRUD: [alpine-crud.md](alpine-crud.md).  
Create order: [create-order.md](create-order.md).
