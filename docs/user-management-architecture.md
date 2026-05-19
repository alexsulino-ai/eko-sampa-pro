# User management architecture

Eko Sampa treats **WordPress as the identity system**. There is no parallel login table, no duplicate user store, and no custom ACL graph outside WordPress roles and capabilities.

## Layers

| Layer | Responsibility |
|--------|------------------|
| **WordPress users + roles** | Authentication, session cookies, `current_user_can()`. |
| **`Eko_Sampa_Roles`** | Registers Eko-specific roles (`eko_manager`, `eko_designer`, `eko_operator`) and grants capability sets. Idempotent sync on activation and on plugin version bumps (`sync_roles_from_codebase`). |
| **`Eko_Sampa_Capabilities`** | Operational **status** (`eko_user_status` user meta), **per-user quotas** (user meta + global option fallbacks), **usage snapshots** (short-lived transients), REST `rest_pre_dispatch` gate for `blocked` / `paused`, profile UI hooks, and the **frontend capability map** for JS. |
| **`Eko_Sampa_User_Admin`** | POST handlers + query helpers for **Eko Sampa → Users** (list/detail views under `views/`). |
| **`Eko_Sampa_Frontend_Router`** | HTTP surface for the SPA; enforces dashboard access and CRUD write policies (paused + template view-only). |
| **`Eko_Sampa_Rest_Api`** | Route-level permission callbacks; quota checks on create routes. |

## Operational status (`eko_user_status`)

Stored as user meta (not WP `user_status`). Values: `active`, `pending`, `blocked`, `paused`.

- **blocked** — Authenticated REST under `eko-sampa/v1` returns 403 (public routes excluded). Frontend app access is denied for non-rescue accounts.
- **paused** — Authenticated REST blocks mutating verbs except `GET` and `POST /me` (read-only platform).
- **pending** — Login allowed; same capability checks as active; UI may show banners using `/me` payload fields.

WordPress **administrators** (`manage_options`) bypass operational REST blocks for rescue operations.

## Admin UI

- **Eko Sampa → Users** — Custom screens (`views/admin-users-list.php`, `views/admin-user-detail.php`), not a fork of `users.php`.
- **Users → Profile** — Section rendered by `views/partials/admin-user-profile-eko.php` for self-view or when the viewer may manage Eko users.

## Extension (future billing / teams)

- `eko_sampa_effective_quotas_for_user` — adjust computed limits without duplicating storage conventions.
- `eko_sampa_usage_snapshot_for_user` — attach derived metrics when snapshots are built.
- `eko_sampa_frontend_crud_write_block` — extra frontend write gates.
- `eko_sampa_user_admin_detail_panel` — inject panels on the user detail admin page.
- `eko_sampa_user_admin_impersonate_requested` — stub for a future impersonation flow.
