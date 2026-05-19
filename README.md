# Eko Sampa (MVP)

WordPress plugin: **frontend-first** app for print templates (JSON layout), clients, services with dynamic fields, templates, and orders. **Stack (frozen for MVP):** PHP 8+, Tailwind CDN, Alpine.js, Interact.js, Sortable.js, REST API `eko-sampa/v1`.

Authoritative install and user flow: **`INSTALL.md`**, **`MANUAL.md`**. Pre-production QA: **`CHECKLIST-MVP.md`**. Release notes: **`CHANGELOG.md`**.

**Technical documentation (source of truth):** [`docs/README.md`](docs/README.md) — architecture, database integrity, business rules, REST, tutorials, troubleshooting. Legacy top-level files in `docs/*.md` redirect into that tree.

---

## Requirements (actual code)

- **WordPress** 6.0+
- **PHP** 8.0+ (see `eko-sampa.php`; PHP 8 is enforced at load)
- **WooCommerce** — **optional**. If active, a small admin panel links a WC order to an Eko order when `woo_order_id` matches (`includes/class-wc-bridge.php`).
- Writable **`wp-content/uploads/`** (gallery: `eko-sampa/galeria/user-{id}/`)

---

## How the app is reached

1. **Virtual rewrites** (no theme chrome): `/eko-sampa/` (public catalog, optional), `/eko-sampa_dashboard/`, `/eko-sampa_login/`, `/eko-sampa_clients/`, `/eko-sampa_services/`, `/eko-sampa_templates/`, `/eko-sampa_editor/`, `/eko-sampa_orders/`, `/eko-sampa_profile/`, `/eko-sampa_print/{id}/`  
   After install or rewrite changes: **Settings → Permalinks → Save** once.

2. **Shortcodes** (optional, inside theme pages):  
   `[eko_sampa_shell view="dashboard|clients|services|templates|editor|orders|profile"]`, `[eko_sampa_login]`

The plugin **does not** auto-create WordPress pages on activation (activation runs DB migrate, roles, and `flush_rewrite_rules`).

---

## Repository layout (real)

```
eko-sampa.php
includes/
  class-plugin.php
  class-database.php
  class-model-base.php
  class-client.php
  class-service.php
  class-service-field.php
  class-template.php
  class-order.php
  class-roles.php
  class-router.php
  class-frontend-router.php
  class-admin-redirect.php
  class-shortcodes.php
  class-assets.php
  class-rest-api.php
  class-upload-service.php
  class-template-renderer.php
  class-wc-bridge.php          # optional WooCommerce admin link
views/
  frontend-shell.php, frontend-login.php, editor-canvas.php, frontend-print.php
  frontend-public-home.php, frontend-guest-editor-wrap.php, frontend-guest-session-expired.php
  partials/public-experience/*.php
  frontend-partial-*.php, admin-dashboard-shell.php
assets/
  css/frontend.css
  js/frontend-app.js, editor-canvas.js, public-home.js
templates/frontend-blank.php
docs/*.md
INSTALL.md, MANUAL.md
languages/                     # .gitkeep; add .po/.mo as needed
```

---

## Capabilities & roles

Custom capabilities: `access_eko_dashboard`, `manage_eko_clients`, `manage_eko_services`, `manage_eko_templates`, `manage_eko_orders`. Roles: `eko_operator`, `eko_designer`, `eko_manager`. Administrators use the same frontend with extra filters (`filter_user_id` in REST/UI).

---

## REST

Namespace **`/wp-json/eko-sampa/v1/`**. Cookie session + `X-WP-Nonce` (`wp_rest`) as enqueued for `ekoSampaRest` in `class-assets.php`.

**Limits (MVP):** raw JSON body for mutating requests is rejected above **512 KiB**. Template `json_data` updates are rejected above **384 KiB** (encoded). List endpoints default to **50** rows per request if `limit` is omitted; the UI uses **offset pagination** (page size 30) on clients, services, templates, and orders. **`GET /lookups/order-form`** returns clients, services, and templates (up to 500 each) in one response for the order form.

**Field slugs** must be unique per service; duplicates return HTTP **409**.

---

## Deferred / not in this MVP codebase

Documented in **`docs/architecture.md`** (appendix *MVP congelado*): separate AJAX classes, dedicated `class-layer.php`, thumbnail service, `wp_eko_sampa_layers` usage (table exists; layout lives in `templates.json_data`), product automation beyond optional `product_id` field, full WC order sync, compiled Tailwind build pipeline described in older README drafts.

For questions or contributions, keep changes aligned with the frozen architecture above.
