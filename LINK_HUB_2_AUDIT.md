# Link Hub 2.0 — Phase 0 Audit

Date: 2026-07-04
Scope: `/links/`, `/links-admin/`, and every backend that currently serves them.

## 1. Headline finding: there are FOUR competing backend implementations, not one

| # | Implementation | Language | DB target | Status |
|---|---|---|---|---|
| 1 | `api/index.php` (1153 lines) | PHP | `/home/hoale24new/bakudan-app/data/bakudan.db` | **Live in production** for all `/api/*` traffic |
| 2 | `api/index-lite.php` (136 lines) | PHP | same `bakudan.db` (assumes tables already exist) | **Live** — this is what both frontends actually call |
| 3 | `server/routes/links.js` + `server/server.js` | Node/Express | `data/bkdn.db` (separate SQLite file) | Built, mounted at `/api/links`, but not reachable in production |
| 4 | `server/server-passenger-lite.js` | Node/Express | in-memory hardcoded arrays | Standalone fallback, separate from #3 |

**Why #1/#2 win in production:** `api/.htaccess` hard-rewrites every request under `/api/` straight to `index.php` (`RewriteRule ^ index.php [QSA,L]`). On this Apache/DreamHost host that happens before Node/Passenger ever sees the request, so it doesn't matter that `package.json`'s `main`/`start` script points at `server/server.js` — for `/api/*` paths, PHP wins unconditionally.

**Confirmation:** `links/index.html:275` and `links-admin/app.js:65` both call `/api/index-lite.php?r=...`. Neither frontend calls `/api/links/*` (the Node route). `scripts/_patch_php_api.py` and siblings SFTP directly into the live host to hand-patch `api/index.php` — i.e., the actual deployment workflow today is "edit PHP, re-upload it," not "build and deploy the Node server."

**Implication for the spec's "one authoritative backend" requirement (Rule/Architecture section 4):** the rebuild is not a matter of connecting Admin and Public to the same existing API — it means picking ONE of these four to keep and retiring the other three, then migrating the real data. This is an architecture decision, not just a bugfix, and should be made explicitly before Phase 1 work starts (see §7 below).

## 2. Database schema comparison

Three independent schemas exist, two of them pointed at genuinely different SQLite files:

- **`server/db.js`** (Node, `data/bkdn.db`): `link_pages`, `link_buttons`, `link_publish_snapshots` (versioned publish, closest thing to the spec's draft/publish/rollback model), `analytics_events`, `settings`. **No `sections` table. No `locations` table.** `link_buttons.url` is a flat `TEXT` — no column distinguishing external URL / internal page / phone / YouTube / Toast, etc. (spec's `link_type` requirement, §5/§9). Only a loose `kind`/`platform` pair exists, which doesn't encode that distinction either.
- **`api/index.php`** (PHP, `bakudan.db`): `users`, `pages`, **`link_sections`** (the only schema with real sections), `buttons` (again flat `url TEXT`), `redirects`, `shortlinks`, `analytics`, `subscribers`, `settings`, `blog_posts`. Sections are populated once via string-matching heuristics (`seed_link_sections()`: `toasttab.com`→order, `reward`→rewards, `merch`→merchandise) — not a real Admin-managed section model, and not re-run on new content.
- **`api/index-lite.php`**: no schema of its own; assumes `api/index.php`'s tables already exist in the same file.

None of the three has a `locations` table, an `audit_logs` table, or a `link_type`/destination-type enum on buttons. This means **Problem A** (external URL becoming an internal slug) has no representation to fix at the schema level yet — the fix has to start with adding the column, not just changing app logic.

## 3. Public rendering — confirmed dynamic, with a dangerous fallback layer (root cause candidate for Problem C)

`links/index.html` is not static HTML — it fetches `/api/index-lite.php?r=public/links/{slug}` at runtime (`load()`, lines 270–401) and renders from that. **But** if the fetch fails or returns non-OK, it falls back to a hardcoded `fallbackLinksData()` (lines 411–441) baked into the page itself. That hardcoded list overlaps almost exactly with the separate hardcoded array in `server-passenger-lite.js`, including **colliding numeric IDs (2/4/5/10/12/18/1/19 appear in both)**.

This is the most concrete lead on **Problem C (duplicate buttons)**: if the live PHP API is slow/erroring intermittently, or if the DB genuinely contains a button that also exists in the hardcoded fallback (by coincidence of seed data), a user could see the same link twice, or Admin edits could appear to have "no effect" because the page silently fell back to stale hardcoded content instead of the DB.

No hardcoded "Coming Soon" merchandise block exists in `links/index.html` itself — that pattern shows up properly built (as an admin-controlled `enabled:false` + click-intercept popup) only in `links-temp/index.html`, which is described below.

## 4. Admin session handling — Problem B confirmed exactly as described

`links-admin/app.js`, `apiFetch()` (lines 67–87):

```js
const res  = await fetch(...);
const data = await res.json();
if (res.status === 401) { logout(); return null; }   // line 75
```

Every single API call goes through this one function. **Any 401, from any cause, immediately destroys the session** — no check of whether the token is actually expired, no retry, no preservation of in-progress form state. Given the 4-backend split above, a request that happens to hit the wrong backend/shape is itself a plausible source of spurious 401s, compounding the logout problem.

## 5. External→internal URL conversion — not reproduced, but the risk surface is real

No code converts a button's destination URL into an internal slug. The only `slugify()` in the codebase (`links-admin/app.js:600–618`) is for naming a new **page** from its title (e.g., "Staff Training Videos" → `staff-training-videos`), not for button URLs — button URLs are stored as opaque strings everywhere. The spec's Problem A may describe either a bug that's since been papered over, or a behavior that only manifests through the PHP `seed_link_sections()` heuristic misfiring on a partial match (e.g., a YouTube URL containing "training" could plausibly get miscategorized by a future keyword rule). Either way, the real fix is the same: add an explicit `link_type` column and stop inferring type from string content.

## 6. Staff Training — does not exist yet

Only trace in the entire repo: the placeholder text `staff-training-videos` in the "Add Page" modal (`links-admin/app.js:617`). No page, route, table row, or file implements it. Problem E should be read as "build this from scratch," not "fix broken routing."

## 7. Toast — hardcoded URLs only, confirmed no API/automation exists

Real Toast references (`order.toasttab.com/...`, `.../rewardsSignup`) are hardcoded per-location in `locations.html`, `locations/*.html`, `order-smart/index.html`, `server/db.js` seed rows, and `api/index.php` settings keys `order_url_la cantera` / `order_url_stone_oak` / `order_url_bandera`. No Toast API keys, OAuth, or webhook code exists anywhere (`integrations/` only contains an unrelated Linktree/Prisma migration source). This matches the spec's Rule 2 constraints already — there's nothing to rip out, only a proper Admin-configurable URL table to build (`locations.toast_signup_url`, per spec §13).

Note: unrelated `showToast()` / `toastMsg` hits in `links-admin/app.js` and `links-temp/index.html` are generic UI toast-notification popups, not the Toast POS platform — don't confuse the two while grepping.

## 8. `links-temp/` — an untracked, disconnected WIP mockup ("Maria's preview"), not dead code

Untracked in git (never committed), served via `GET /links-temp` in `server/server.js:93` (comment: "Static temporary Linktree for Maria's preview"), deployed by `scripts/_deploy_links_temp.py`. It's fully static — an inline JS array, no API calls at all (opposite rendering model from the real public page). Notably, each entry already carries a `destinationType` / `destinationNote` field (`toast_loyalty_signup`, `smart_delivery_router`, `reservation_provider`, `toast_gift_card_purchase`, etc.) — **this is a hand-built preview of exactly the destination-type model the spec asks for in §5/§9**, and its "Coming Soon" buttons (`enabled:false`, intercepted click → toast popup instead of navigation) are a working reference for how §15 (Merchandise/Coming Soon) should behave. Worth mining for UX reference during Phase 2/3, but it is not connected to any real data source and shouldn't be treated as production code.

## 9. `data/` directory

Contains only `bkdn.db`, `bkdn.db-shm`, and an 811KB `bkdn.db-wal` (i.e., real data is sitting in the WAL, not checkpointed into the main file — back this up as a set, not just the `.db` file). No JSON files back the links content here. The live PHP backend's actual data lives in a **completely different file** on the server (`/home/hoale24new/bakudan-app/data/bakudan.db`), not in this repo's `data/` at all — meaning the authoritative production data has to be pulled from the live host, not assumed to be `data/bkdn.db`.

## 10. `scripts/` and `qa/`

`scripts/*.py` are one-off SFTP deploy/patch scripts that hand-edit the live PHP files directly — further confirming PHP-on-live-host is the real deployment model today, with no build pipeline. `qa/scripts/` contains Playwright/Puppeteer visual-screenshot scripts only (including some that specifically shoot `links-temp`) — no assertions on API correctness, schema, or the specific problems above.

---

## Summary: spec's "Known Problems" (§3), confirmed status

| Problem | Status | Root cause found |
|---|---|---|
| A — external URL becomes internal slug | Not reproduced directly; risk surface confirmed | No `link_type` column anywhere; slug logic only touches page titles today, not button URLs |
| B — logged out after any change | **Confirmed** | `links-admin/app.js:75` — any 401 → immediate `logout()`, no verify/retry |
| C — duplicate public buttons | Plausible root cause found | Hardcoded fallback lists in `links/index.html` and `server-passenger-lite.js` overlap with colliding IDs; PHP is the real data source but two hardcoded shadow copies exist |
| D — sections not admin-manageable | **Confirmed** | Only `api/index.php`'s `link_sections` table has real sections, populated by one-time string heuristics; Node schema has no sections table at all |
| E — Staff Training routing broken | Reframed: **doesn't exist yet**, not broken | Only a placeholder string in a form field |

## Recommended next steps (before any Phase 1 code work)

1. **Decide the surviving backend now** — this can't be deferred. Given PHP (`api/index.php`) is what's actually live and holds the real data, the lowest-risk path is almost certainly: keep PHP as the authoritative store short-term, formalize/extend its schema (add `link_type`, `locations`, proper Admin-writable sections), and retire `server/routes/links.js`, `server-passenger-lite.js`, and `api/index-lite.php` — rather than trying to cut over to the Node implementation, which has never received production traffic and would require a live data migration with more risk. **This is a decision for you, not something to execute silently** — flagging it here rather than picking a side.
2. **Pull and snapshot the real production DB** (`/home/hoale24new/bakudan-app/data/bakudan.db`, checkpointed) before touching anything — this is the actual source of truth, not anything in this repo's `data/` folder.
3. Treat `links-temp/index.html`'s `destinationType` field as a design reference for the `link_type` column, and its Coming-Soon click-intercept pattern as the reference implementation for spec §15.
4. Once the backend decision is made, the spec's own Phase 1 order (unify API → fix session handling → fix link-type handling → fix duplicate rendering → make sections admin-managed → draft/preview/publish/rollback → public migration → Staff Training → Marketing Signup → locations/Toast URLs → analytics/QR/monitoring → production readiness) applies as written.
