# Staff Training Fix — Deployment Report

## Environment note

This session has no local PHP runtime and no direct production database
access outside of the SFTP deploy script — all verification is via static
analysis pre-deploy and live HTTPS/API checks post-deploy (see
`evidence/staff-training/network-log.txt`).

## Deploy 3 (2026-07-04 ~11:12) — live login verification + 3 bugs found and fixed

The site owner logged into `/links-admin/` directly (via Chrome, real
credentials — this session never saw or handled the password). This
surfaced real bugs that API-level curl checks had not caught:

1. **Stale Admin data (browser cache).** `GET /api/admin/pages/4` kept
   returning pre-migration data (old placeholder buttons, wrong
   page_type/visibility) even after a hard page reload. Root cause: neither
   `rawFetch()` in `app.js` nor the PHP responses set any cache-control
   directive, so the browser served an already-cached response. Fixed by
   adding `cache: 'no-store'` to every admin/auth `fetch()` call in
   `app.js`, and `Cache-Control: no-store` on every JSON response in
   `api/index.php` (`ok()`, `err()`, and the two public-links handlers that
   bypass those helpers).

2. **Full-site 500 outage, ~5 minutes.** Redeploying the cache-header fix
   broke every single API endpoint, including `/api/config`. Root cause: a
   UTF-8 BOM (`EF BB BF`) at the very start of `api/index.php`, which made
   `declare(strict_types=1)` no longer "the first statement in the script" —
   a PHP fatal error. Diagnosed via `php -l` run directly on the host (PHP
   CLI is available there) rather than the (empty/unwritable)
   `api-error.log`. Fixed by stripping the BOM and re-uploading; confirmed
   with `php -l` before going further. **This session did not verify the
   file was BOM-free before every earlier upload — worth double-checking
   file encoding after any future edit to this file.**

3. **Apache-level cache override.** Even after the PHP-side fix, live
   responses still showed `Cache-Control: max-age=172800` (48h) — a
   DreamHost server-level `mod_expires` default that overrides whatever the
   PHP script sends, unrelated to anything in this repo's tracked
   `.htaccess` files. **Explicitly confirmed with the site owner before
   touching this** (infrastructure change, outside the originally-agreed
   deploy scope): added `ExpiresActive Off` + `Header always unset/set
   Cache-Control` to `api/.htaccess`. Verified via `curl -D -` that the
   response now carries exactly one `Cache-Control: no-store` header.

4. **Broken "View Public Site" / Preview links for Staff Training pages.**
   The already-live `app.js` (from earlier in this same session — initially
   misattributed to a separate tool the site owner also uses in parallel on
   this project, but confirmed by file diff to be this session's own
   code) pointed Staff Training page links at `/staff-training/<slug>`, a
   URL that was never actually wired up (`404` on the live host — no such
   directory exists). Reverted to the working, already-tested
   `/links/<slug>` scheme in all 4 places `app.js` referenced it.

After all four fixes, re-verified live in the browser (real login, hard
refresh): correct Page Type/Visibility/2 videos in Admin, correct
`/links/staff-training-videos` URL everywhere, and a real (non-curl)
screenshot of both the customer `/links/` page and the Staff Training page
via Preview — no console errors on either.

**Process note for next time:** the site owner mentioned a separate AI/tool
is also used on this project. This session did not coordinate with it —
worth setting up a convention (e.g., always `git pull`/diff against a
shared branch before overwriting `api/index.php` or `app.js` on the live
host) so two tools don't blind-overwrite each other's work.

## Backups taken

Two backups were taken (one per deploy in this session), each containing
`api/index.php`, `api/index-lite.php`, and `bakudan.db` as they existed on
the server **before** that deploy's upload:

- `scripts/_deploy_backups/20260704_092436/` — before the first Staff
  Training deploy (schema + `migrate_staff_training_v1`)
- `scripts/_deploy_backups/20260704_093408/` — before the corrective second
  deploy (`migrate_staff_training_v2`)

## Deploy 1 (2026-07-04 ~09:24)

Uploaded `api/index.php` with the `show_on_hub` column, the `/public/pages/all`
filter fix, the Admin UI page-classification fields, and `migrate_staff_training_v1`.

**Unexpected finding during verification:** by the time this deploy's
migration ran, the two YouTube video buttons were **already gone** from the
database — a backup taken 44 minutes earlier (from an unrelated deploy
earlier in the session) still had them; this deploy's own pre-upload backup
did not. Evidence pointed to live Admin activity in that window (a new
"(Copy)" duplicate button appeared that this session never created, and the
Staff Training page's visibility had already been manually changed to
`staff_only` — using the very Admin UI fields shipped in the same deploy).
Confirmed with the site owner: yes, that was them, using the new fields
directly.

**Bug this introduced:** `migrate_staff_training_v1` unconditionally set
`visibility='unlisted'` on the existing Staff Training page, overwriting the
site owner's own `staff_only` choice. Caught during post-deploy verification,
not before — logged here rather than hidden.

## Deploy 2 (2026-07-04 ~09:34) — corrective

Uploaded a fixed `api/index.php`:
- `migrate_staff_training_v1` changed to only fix classification fields that
  are still at wrong defaults, never overwrite an already-valid admin choice
  (defensive fix for future migrations, though v1 itself won't re-run).
- New `migrate_staff_training_v2`: restores `visibility=staff_only` and
  re-inserts the two YouTube videos (URLs re-supplied directly by the site
  owner during this session, since the originals were gone), duplicate-safe
  via URL check.

Verified live immediately after:
- `pages.staff-training-videos.visibility` = `staff_only` (confirmed via
  direct DB read)
- Both videos present on the Staff Training page, correct `link_type=youtube`
- Public access without token → `403`; with the page's `preview_token` → `200`
  with both videos
- Customer hub (`bakudan-links-main`) → 0 youtube-linked buttons, all other
  customer content intact and unchanged (10 buttons: 3x Order, 3x Rewards,
  Instagram, Facebook, Email Club & Offers, Visit Website)

## Files deployed (both rounds)

- `api/index.php`
- `links-admin/app.js`
- `links/index.html`
- `marketing-signup/index.html` (unchanged in this specific fix, re-uploaded
  as part of the same scoped deploy script from earlier work)

No other pending/modified file in the repo (`.htaccess`, `about.html`, blog
pages, etc.) was touched, per the deploy scope agreed at the start of this
work.

## Cache

No CDN in front of this site (DreamHost shared hosting, direct Apache/PHP).
`links/index.html` fetches with `cache: 'no-store'`, so no client-side cache
invalidation step was needed.

## What still needs a human pass

1. **Browser console/visual check** — open `https://bakudanramen.com/links/`
   and the staff URL below in a real browser and confirm no console errors
   and the layout looks right. This session's browser tool can only reach
   local dev servers, not the live domain, so this specific check could not
   be done here.
2. **Live Admin click-through** — log into `/links-admin/` with the real
   admin password (not available to/attempted by this session) and confirm:
   add/edit/reorder/hide a video, Save doesn't force logout, "Show on
   Customer Link Hub" toggle behaves as expected for this page.

Staff Training URL (contains a live access token — share only with staff,
rotate via "Generate Preview Token" in the page editor if it needs to be
invalidated):

```
https://bakudanramen.com/links/staff-training-videos?token=17c2eb3a60f84a51c6c0918d444b319697c7b9955a38a7ac
```
