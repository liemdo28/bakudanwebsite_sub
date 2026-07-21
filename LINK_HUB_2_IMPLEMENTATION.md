# Link Hub 2.0 — Implementation Summary (Phases 1–7)

Companion to [LINK_HUB_2_AUDIT.md](LINK_HUB_2_AUDIT.md). This covers what was actually built in this pass, organized by the spec's phases, what's still open, and how to verify before deploying.

**Environment note:** this sandbox has no PHP runtime and no access to the production SQLite database. Every change below was verified by static analysis (Node `--check` on all JS, a Python-based brace/placeholder/bind-count auditor on the PHP, route-uniqueness checks) and by exercising the two rebuilt frontends (`links-admin/`, `links/`, `marketing-signup/`) against a local static server to confirm no runtime JS errors and correct fallback/error behavior. **It has not been run against live PHP + the real `bakudan.db`.** Treat this as code-reviewed, not production-verified — see the QA checklist at the bottom before deploying.

## Architecture decision made

Consolidated on **`api/index.php`** as the one authoritative backend, per the audit's recommendation (it's what's actually live and holds real data). `server/routes/links.js`, `server/routes/admin.js`, `server/server-passenger-lite.js`, and `api/index-lite.php` are now unused by both frontends — not yet deleted from the repo (left in place in case rollback is needed), but no longer in the request path. Both `links-admin/app.js` and `links/index.html` now call clean paths like `/api/admin/pages` and `/api/public/links/{slug}` directly instead of the old `/api/index-lite.php?r=...` convention.

## Phase 1 — Unified backend

- **Removed the hardcoded `/public/links/*` payload interceptor** in `api/index.php` (was dead code reachable only via a path some future caller might hit — cleaned up regardless since it silently ignored the database).
- **Removed the blanket `?r=` compatibility shim** (`bkdn_lite_response()`), including a hardcoded `admin@bakudanramen.com` / `admin123` password bypass that lived in it.
- **Added the missing `GET /auth/me`** — this did not exist in `index.php` at all. The admin SPA's boot sequence called it on every page load to validate the session; getting a 404 back looked identical to "session invalid" and logged the admin out **on every refresh**. This is likely the single biggest contributor to the "logged out constantly" complaint.
- **Fixed Admin session/401 handling** (`links-admin/app.js`): a 401 no longer immediately ends the session. The client decodes the JWT's `exp` locally; if the token still looks valid, it retries the request once before treating the session as dead. Boot-time session validation only clears the token on an explicit 401, not on any other failure (network hiccup, 5xx).
- **Removed the client-side hardcoded login bypass** (`fallbackAdminLogin`) and the hardcoded fallback dashboard data (`fallbackDashboardData`) — both silently faked a working session/UI instead of surfacing real backend failures, which directly undermines "no silent failures" and made session bugs harder to notice.
- Renamed all ~27 admin API call sites from `/links/...` to `/admin/...` to match `index.php`'s real routes (the SPA was calling routes that only ever existed in the never-deployed Node router).

## Phase 2 — Data model

Added to `api/index.php`'s schema (idempotent `ALTER TABLE`/`CREATE TABLE IF NOT EXISTS`, safe to run against the existing production DB):
- `buttons.link_type` (enum: external/internal_page/youtube/phone/email/maps/pdf/download/toast_order/toast_signup/instagram/facebook/website/custom) + `buttons.internal_page_id` — the actual fix for "external URL becomes internal slug": there is now an explicit type per button, and destination validation/normalization branches on it (phone → `tel:`, email → `mailto:`, everything else stored verbatim, never slugified).
- `pages.page_type`, `pages.visibility`, `pages.status`, `pages.preview_token`, `pages.scheduled_publish_at`, `pages.staff_password_hash`.
- `link_sections.status`, `.start_at`, `.end_at` (section-level scheduling/hide/coming-soon).
- New tables: `locations`, `audit_logs`, `page_versions` (draft/publish/rollback snapshots), `link_health`.
- Seeded `locations` (La Cantera / Stone Oak / Bandera) from the existing hardcoded Toast URLs in `settings`, so nothing regresses on first run.

## Phase 3 — Admin CMS

- Button editor now has a **Destination Type** selector with type-specific fields (YouTube/phone/email/internal-page-picker/etc.), a **Test Link** button, and client + server-side URL validation. Internal pages are referenced by ID, never re-derived from a slug.
- **Duplicate-URL detection** on button create/update (per page) and a **duplicate-button check before publish** (same label + destination), returning a clear 409 message rather than silently allowing dupes.
- **Draft → Publish → Rollback**: publish now writes a versioned snapshot (`page_versions`, capped at 50/page) inside a transaction; new `POST /admin/pages/:id/rollback/:version` restores sections+buttons from any prior snapshot and itself becomes a new version (history stays append-only). `GET /admin/pages/:id/versions` lists history.
- Added `POST /admin/pages/:id/schedule` and `POST /admin/pages/:id/generate-preview-token` — the SPA already called these but `index.php` never implemented them.
- New **Locations** admin view (`#/locations`) — centralized address/phone/Toast order & signup URLs, editable once instead of duplicated across buttons.
- New **Audit Log** view (`#/audit-log`) — every page/section/button/location mutation now calls `audit_log()`, recording user, action, before/after JSON.
- Roles extended to accept `admin`/`marketing` alongside the legacy `super_admin`/`marketing_manager`/`store_manager`/`viewer` values (spec's 5-role model), without breaking the existing seeded account.
- Page create/edit now exposes **Page Type** (link_hub/staff_training/marketing_signup/campaign/location/custom) and **Visibility** (public/unlisted/staff_only/password_protected/inactive).

## Phase 4 — Public page parity

- `links/index.html` now calls the real path-based public API and receives the sections join it was never getting before (the old `index-lite.php` endpoint didn't return `sections` at all, so section headers never rendered on the live page regardless of what Admin configured).
- **Fixed the fallback-masking bug**: the old code fell back to a hardcoded button list on *any* non-OK response, which could silently show stale/duplicate content over real (possibly-empty, possibly-different) API data. Now: a genuine network failure (fetch throws — offline/DNS/CORS) shows the offline snapshot; any real HTTP response, even a malformed one, shows a visible error state instead. Verified via local testing that a non-JSON error response now correctly shows "Page not found" rather than silently rendering fake buttons.
- No visual/CSS changes — same DOM structure, same classes, same layout. Verified via screenshot.

## Phase 5 — Marketing signup + locations/Toast

- New page: **`/marketing-signup/`** — location picker → redirects to that location's `toast_signup_url` in a new tab. No Toast API, no automation, matches Rule 2 exactly.
- New endpoint `GET /public/marketing-signup` returning heading/description/button label (from `settings`, admin-editable) + active locations with a configured signup URL.
- Admin Settings view gained a **Marketing Signup** card (heading/description/button label) linking to the Locations view for per-location Toast URLs.
- **Not yet done**: the public Link Hub's existing "Email Club & Offers" button still points at whatever URL is in the live database today. Per spec, an Admin should repoint that button's destination to `/marketing-signup/` (as a `custom` or `website` link_type) after this deploys — I did not touch existing production button data.

## Phase 6 — Optimization tools

- **Analytics**: fixed a real response-shape bug — `GET /admin/analytics` returned `{analytics:{total_views,total_clicks,top_pages,...}}` but the SPA read `res.data.views/.clicks/.ctr/.top_buttons` directly; this endpoint was also unreachable before the Phase 1 routing fix, so this bug was previously masked. Now returns the flat shape the SPA expects, with a real `top_buttons` (per-button click) breakdown and computed CTR.
- **QR codes**: shortlinks now return `qr_url` (via api.qrserver.com, an external image-generation API — no data is sent beyond the destination URL) and `short_url`.
- **Link health**: new `link_health` table, `POST /admin/link-health/check` (manual HEAD-request sweep of all active button URLs) and `GET /admin/link-health` (latest status per button), plus an admin UI page. **Not automated** — there's no cron mechanism available from this sandbox, so it's manual-trigger only; wiring a 6/12h cron is a host-level change outside this repo.
- **Audit log**: covered above (Phase 3).

## Phase 7 — Production readiness

I could not run PHP or hit the live database in this environment, so "automated tests" per spec §28 were not executed against a live stack. What was done instead:
- Static verification: `node --check` on every JS file touched; a Python script cross-checking every SQL `INSERT`/`UPDATE`'s column list against its placeholder count and bound-value array length (caught nothing wrong after fixes, but this is exactly the class of bug that silently corrupts data at runtime); brace/paren/bracket balance on the full PHP file; route-pattern uniqueness check (no accidental duplicate handlers).
- Manual browser verification (Preview tool + local static server) of `links-admin/` (login page renders, invalid-login error path is clean, no console errors, config-load failure degrades gracefully), `links/` (renders full existing design pixel-identical from a screenshot check, error path fixed and verified), and `marketing-signup/` (renders, error path clean).

### Before deploying — recommended checklist

1. **Back up the production DB** (`/home/hoale24new/bakudan-app/data/bakudan.db`) and current `api/index.php`/`api/index-lite.php` before uploading anything — this session did not touch the live host.
2. Deploy `api/index.php` first, confirm `db_migrate()` runs cleanly against the real DB (it's all `IF NOT EXISTS`/idempotent `ALTER TABLE`, but verify on a copy first).
3. Log in to `/links-admin/` with the real admin account and confirm: dashboard loads, an existing page's buttons/sections load, editing a button shows the new Destination Type field pre-filled correctly for existing rows (they'll default to `external` since `link_type` is a new column).
4. Load `/links/` and diff against a screenshot of the current live page — confirm section headers now appear (they may not have been rendering before) and no duplicate buttons.
5. Do at least 20 consecutive admin edits without a forced logout (spec's own acceptance bar) to confirm the session fix holds under real network conditions, not just this sandbox's static-file test.
6. Once confirmed, repoint the "Email Club & Offers" button to `/marketing-signup/` from the Admin UI.

## Known gaps / explicitly out of scope this pass

- Staff Training doesn't have specialized UI (video/PDF category grouping, employee-role tagging) — it's usable today as a normal page with `page_type=staff_training`, `visibility=staff_only`, and buttons of type `youtube`/`pdf`, but there's no bespoke builder.
- No password-set UI for `password_protected` pages (the API supports `staff_password_hash` verification, but nothing writes that column yet).
- No recurring daily/weekly schedule (days_of_week + daily start/end time) — only start/end datetime ranges exist, on both buttons and sections.
- No bulk actions (copy section to all locations, bulk URL replace, multi-select publish).
- Per-location scoping for the "Store Manager" role isn't enforced — the role can edit if given `$EDIT`-level access, with no restriction to their assigned location's content.
- Blog CMS media upload (`links-admin/app.js` → `/api/blog/media/upload`) is broken against `index.php` (only `/upload` exists) — spun off separately, not part of Link Hub scope.
