# LINK HUB 2.0 — STORE MANAGER PERMISSION AUDIT

**Date:** 2026-07-05
**Environment:** Production, direct API testing with real accounts (not simulated)
**Policy:** Store Managers may only manage content associated with their own assigned location. Every check is enforced server-side in `api/index.php` — never relies on the Admin UI hiding a button.

---

## Result: **PASS** — after finding and fixing 6 real gaps

## Endpoint-by-endpoint audit

| Endpoint group | Before this pass | After this pass |
|---|---|---|
| `/admin/pages`, `/admin/pages/:id` | Scoped | Unchanged (already correct) |
| `/admin/pages/:id/publish`, `/unpublish` | Scoped | Unchanged (already correct) |
| **`/admin/pages/:id/rollback/:version`** | **No scope check at all** | **Fixed** — asserts scope on the page's `store_slug` |
| **`/admin/sections/:id/move`** | **No scope check at all** | **Fixed** — asserts scope on both source and target page |
| **`/admin/buttons/:id/move`, `/copy-to-page`** | **No scope check at all** | **Fixed** — asserts scope on both source and target page |
| `/admin/templates` | `$MGR`-only (Store Managers blocked) | Unchanged — documented as intentional "global resource → read-only" policy |
| **`/admin/campaigns`** (list/create/edit/delete) | `$MGR`-only — Store Managers **fully blocked**, even from their own location's campaigns | **Fixed** — widened to `$EDIT` + real scope check derived from the linked page's `store_slug`; unlinked/global campaigns remain blocked for Store Managers |
| **`/admin/locations/:id`** (edit) | `$MGR`-only — Store Managers **could not edit their own location** | **Fixed** — widened to `$EDIT` + scope check against the location's own `slug`. Create/delete locations remain `$MGR`-only. |
| **`/admin/shortlinks`** | `$MGR`-only — Store Managers fully blocked | **Fixed** — same pattern as campaigns, scope derived via `campaign_id → page_id → store_slug` |
| `/admin/forms` | `$EDIT` (Store Managers had **unrestricted** write access — no location field exists on forms at all) | **Fixed to `$MGR`-only** — Forms are a global resource with no per-location concept; Store Managers are read-only, consistent with Templates |
| `/admin/media` | `$EDIT`, no location field | Left unchanged — shared asset library, no location concept, no security benefit to restricting |
| **`/admin/notices`** | `$EDIT`, **no scope check at all** | **Fixed** — scope derived from `location_slug` or linked page's `store_slug`; read list filtered too |
| `/admin/automations` (create/edit/delete/run) | `$MGR`-only | Unchanged — documented as intentional: "Run Now" executes every active rule sitewide, not scoped, so partial access would be misleading |
| **`/admin/analytics`** (sitewide) | `$MGR`-only — Store Managers **fully blocked** | **Fixed** — widened to `$EDIT`, results filtered to the manager's own location's pages/buttons |
| **`/admin/pages/:id/analytics`** | **No scope check at all**, inconsistent with the sitewide endpoint being fully blocked | **Fixed** — asserts scope on the target page |
| **`/admin/trash`** (list + restore) | **No scope check at all** — a Store Manager could see and restore any location's deleted content | **Fixed** — list filtered by the deleted item's owning page; restore asserts scope. Permanent delete remains `$MGR`-only (unchanged, already safe). |
| `/admin/settings` | `$MGR`-only | Unchanged — already correct |
| `/admin/users` | `super_admin`-only for writes | Unchanged — already correct |
| SEO fields | No dedicated endpoint — set via `PUT /admin/pages/:id` | Already covered by that endpoint's existing scope check |

## Read-scoping policy (documented, applied consistently)
Per the completion gate's instruction to "choose one documented policy and apply it consistently": **global resources with no per-location field (Templates, Forms) are read-only for Store Managers; resources with a real or derivable location (Pages, Campaigns, Shortlinks, Notices, Locations, Analytics, Trash) are scoped by that location, both for reads and writes.**

---

## Bug found and fixed during testing
The first attempt at scoping `/admin/analytics` used `db()->quote($scope)` to safely inline the location value into a raw SQL string — but this project's `db()` returns a native PHP `SQLite3` instance, which has no `quote()` method (that's a PDO method, not SQLite3's). This caused a `500 Internal Server Error` for every Store Manager (and would have for anyone in production). Fixed by using `SQLite3::escapeString()` and building the quoted literal manually. Found and fixed before this document was written — verified working with a real API call afterward.

---

## Test accounts and matrix

Created three real Store Manager accounts (removed after testing — see `LINK_HUB_2_QA_CLEANUP_REPORT.md`):
- `qa-manager-la cantera@bakudanramen.com` → `la-cantera`
- `qa-manager-stone-oak@bakudanramen.com` → `stone-oak`
- `qa-manager-bandera@bakudanramen.com` → `bandera`

Two real test pages: page 6 (`Test Template Page`, `store_slug=la-cantera`) and a new page 8 (`QA Permission Test - Stone Oak`, `store_slug=stone-oak`, Unlisted/Draft).

| Action | La Cantera Manager | Result |
|---|---|---|
| Edit La Cantera page | Allowed | **PASS** (200) |
| Edit Stone Oak page | 403 | **PASS** (403) |
| Rollback La Cantera page | Allowed | **PASS** (200) |
| Rollback Stone Oak page | 403 | **PASS** (403) |
| Create La Cantera campaign | Allowed | **PASS** (200) |
| Create Stone Oak campaign | 403 | **PASS** (403) |
| Edit global settings | 403 | **PASS** (403) |
| Manage users | 403 | **PASS** (403) |
| Edit own location (La Cantera) | Allowed | **PASS** (200) |
| Edit Stone Oak location | 403 | **PASS** (403) |
| Create La Cantera notice | Allowed | **PASS** (200) |
| Create Stone Oak notice | 403 | **PASS** (403) |
| Stone Oak manager edits Stone Oak page | Allowed | **PASS** (200) |
| Stone Oak manager edits La Cantera page | 403 | **PASS** (403) |

**14 / 14 passed.** Full raw results: `evidence/final-readiness/permissions/api-test-matrix.json`.

**Read-scoping checks:**
- `GET /admin/campaigns` as La Cantera manager → only `QA Perm La Cantera Campaign` visible (not any Stone Oak campaign).
- `GET /admin/analytics` as La Cantera manager → 7 views / 0 clicks (scoped to La Cantera's own pages), vs. 261 views / 54 clicks sitewide.
- `GET /admin/trash` as La Cantera manager → 0 items (correctly empty; no La Cantera-scoped trash existed at test time).

All 403 responses returned the documented message: *"Store Managers can only manage \[content/campaigns/shortlinks/notices\] assigned to their own location."*
