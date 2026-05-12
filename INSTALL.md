# Eko Sampa — Installation

## Requirements

- WordPress 6.0 or newer  
- PHP 8.0 or newer  
- MySQL / MariaDB (InnoDB, utf8mb4)  
- Permalink structure other than “Plain” (recommended: Post name)

## Install

1. Copy the `eko-sampa` plugin folder into `wp-content/plugins/`.
2. In **Plugins**, activate **Eko Sampa**.
3. On activation, the plugin creates database tables, registers custom roles/capabilities, and flushes rewrite rules once.
4. If virtual URLs such as `/eko-sampa_dashboard/` return 404, open **Settings → Permalinks** and click **Save** once (no need to change the option).

## Roles

- **Administrator**: full access in the frontend app and optional wp-admin; can filter all data by user (`filter_user_id` via REST/UI).
- **Customer**: receives `access_eko_dashboard` so they can open the app shell after login.
- **Eko Operator / Designer / Manager**: custom roles with scoped capabilities (see `docs/architecture.md`).

## Optional: shortcodes

Instead of (or in addition to) virtual routes, you can embed the app in a theme page:

- `[eko_sampa_shell view="dashboard"]` — valid `view` values: `dashboard`, `clients`, `services`, `templates`, `editor`, `orders`, `profile`.
- `[eko_sampa_login]` — login box for the frontend flow.

## REST API

Authenticated requests use the WordPress REST API under the namespace `eko-sampa/v1` with the `X-WP-Nonce` header set to `wp_create_nonce( 'wp_rest' )` (handled automatically for bundled scripts via `ekoSampaRest`).

**Payload limits:** requests with a JSON body larger than **512 KiB** are rejected (**413**). Template layout updates (`json_data`) are rejected if the encoded payload exceeds **384 KiB**. Omitting `limit` on list routes defaults to **50** rows.

**Order form data:** `GET /eko-sampa/v1/lookups/order-form` returns `clients`, `services`, and `templates` (capped at 500 each) in one call; supports `filter_user_id` for administrators (same semantics as other list routes).

Ensure the web server can create directories and write files under `wp-content/uploads/`.

User uploads are stored under:

`wp-content/uploads/eko-sampa/galeria/user-{ID}/`

Ensure the web server can create directories and write files under `wp-content/uploads/`.

## Optional: WooCommerce

If **WooCommerce** is installed, the plugin registers a small panel on the **order edit screen** in wp-admin (`WooCommerce → Orders`). When an Eko order row has `woo_order_id` equal to that WC order ID, the panel links to the in-app orders list and the isolated print URL. WooCommerce is **not** required for the Eko Sampa app to work.
