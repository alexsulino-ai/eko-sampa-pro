# Guest conversion flow

End-user oriented summary of how a **guest** becomes a **logged-in user** without losing work, and where each step lives in code.

## Entry points that show the conversion modal

| User action | Behaviour |
|-------------|-----------|
| **Salvar em Meus templates** | Requires login; guest sees modal with benefits + link to frontend login (`redirect_to` preserves `template_id` + `session_token`). |
| **Create order** (toolbar) | Blocked for guests with the same modal (orders require an authenticated app context). |
| Telemetry | Opening the modal triggers `POST /public/telemetry` with `{ "event": "conversion_modal_open" }` (nonce, rate-limited) to increment a counter for admins. |

The modal markup lives in `views/partials/public-experience/conversion-modal.php` (included from `editor-canvas.php`). Alpine state: `guestConversionModal`.

## After login

1. User signs in via **`/eko-sampa_login/`** (or `wp_login_url` when already in WP auth context — see `class-assets.php` localization).
2. `redirect_to` targets the **same guest editor URL** (`template_id` + `session_token`).
3. Server-side `request_can_use_session_row` must still pass (TTL, lifecycle, fingerprint). If it fails, the user lands on **`frontend-guest-session-expired.php`** with clear copy and a **`?fork=`** deep link back to public home when `parent_template_id` is known.

## Recovery banner

- Query flag `eko_recover_session=1` together with a valid `session_token` shows the in-editor banner (`showSessionRecoveryBanner` in localized config).
- A metric bump runs once per editor bootstrap when those query params are present (see `class-assets.php`).
- **Continuar edição** dismisses the banner; **Descartar aviso** clears `eko_recover_session` from the URL (session remains until TTL — user can still save or print).

## What we intentionally did not do

- No automatic account creation.
- No destructive reload of the editor on modal dismiss.
- No new authenticated analytics pipeline; only coarse counters and admin SQL snapshots.

See also: [public-experience-architecture.md](public-experience-architecture.md), [guest-session-lifecycle.md](guest-session-lifecycle.md).

## Quick print from public home

When the visitor chooses **Imprimir rápido**, the browser opens the guest editor with `eko_open_qp=1`. The editor strips the flag from the URL and opens the quick-print manager (guest only). Core quick-print validation (`waitVisualRenderStable`, preview mount, job POST) is unchanged; an extra guard blocks creating a **new** job while an existing job is still `queued` or `sent_to_browser`.

## Session reuse (same device)

The public catalog script may send `reuse` on `POST …/session` so a valid guest session for the same **master** is returned with HTTP **200** instead of creating another fork. This reduces orphan sessions while keeping server-side validation (`request_can_use_session_row`, parent match, `user_id = 0`).
