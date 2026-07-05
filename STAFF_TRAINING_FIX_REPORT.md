# Staff Training Fix — Implementation Report

## Problem

Two YouTube Shorts ("YouTube Short: Bakudan Ramen 1" and "2") were sitting as
buttons on the public customer Link Hub (`bakudan-links-main`, served at
`/links/`). Per the site owner, these are staff training content and should
never have been customer-facing.

A "Staff Training Videos" page already existed (slug `staff-training-videos`)
but was misconfigured: `page_type=link_hub`, `visibility=public` (i.e., not
actually locked down), and contained only two leftover placeholder buttons
("Training Videos Coming Soon" / a duplicate copy) — no real content.

## What was built

### 1. Schema (`api/index.php`)
- Added `pages.show_on_hub` (default 1) — a page-level flag distinct from
  `visibility`, so Admin has an explicit "Show on Customer Link Hub: Yes/No"
  control matching the spec's wording, independent of the existing
  public/unlisted/staff_only/password_protected/inactive visibility enum.
- `POST /admin/pages` now defaults `visibility=unlisted` and `show_on_hub=0`
  automatically when `page_type=staff_training`, unless the caller overrides
  them explicitly.
- `GET /public/pages/all` (the public page directory) now filters on
  `visibility='public' AND show_on_hub=1`, so non-hub pages never appear in
  any public listing.

### 2. One-time data migrations (idempotent, guarded by `settings` flags)
- **`migrate_staff_training_v1`**: corrected the existing Staff Training
  page's classification, removed its two placeholder buttons, replaced its
  (incorrectly auto-seeded) Order/Rewards/Merchandise sections with a single
  "Training Videos" section, and moved any button matching the two confirmed
  video URLs onto that page.
- **`migrate_staff_training_v2`**: a follow-up fix (see
  `STAFF_TRAINING_DEPLOYMENT_REPORT.md` for why v2 was needed) that restores
  `visibility=staff_only` (the site owner's own choice, made live in
  production between the two deploys in this session) and re-inserts the two
  confirmed YouTube videos, since the originals were already gone from the
  database by the time v1 ran.

### 3. Public rendering (`links/index.html`)
- Non-public pages (`page.visibility !== 'public'`) now force
  `<meta name="robots" content="noindex, nofollow">` on load, not just
  preview mode. Applies automatically to the Staff Training page and any
  future unlisted/staff-only/password-protected page.

### 4. Admin UI (`links-admin/app.js`)
- Page creation modal and the existing page editor's "Page Type &
  Visibility" card both now expose Page Type, Visibility, and **Show on
  Customer Link Hub** as explicit controls.
- Selecting Page Type = Staff Training auto-sets Visibility = Unlisted and
  Show on Hub = No (spec's stated default), both at creation time and via
  live `onchange` in the Add Page modal.
- A page's classification can be changed after creation (previously only
  settable at creation time) via the new "Save Type & Visibility" action in
  the page editor.

## Access model for the Staff Training page

The page's `visibility` is `staff_only`, which the existing (already-built)
`page_visibility_check()` gate enforces: a request without a matching
`?token=` query parameter gets `403 This page requires a staff access link.`
The page's `preview_token` (already generated, from prior Admin activity) is
the shareable staff link:

```
https://bakudanramen.com/links/staff-training-videos?token=17c2eb3a60f84a51c6c0918d444b319697c7b9955a38a7ac
```

This reuses the existing preview-token mechanism rather than inventing a new
one — no new routing, no `.htaccess` changes, lower risk. Admin can rotate
this token at any time via the existing "Generate Preview Token" button in
the page editor, which immediately invalidates the old link.

## URL choice

Used the existing, already-live slug `staff-training-videos` under the
generic `/links/<slug>` renderer rather than introducing a new URL pattern
(`/staff-training/`) — this needed zero Apache/`.htaccess` changes and reused
proven, already-working routing.

See `STAFF_TRAINING_DEPLOYMENT_REPORT.md` for what happened during
deployment (including a bug this session introduced and then fixed) and
`STAFF_TRAINING_TEST_RESULTS.md` / `evidence/staff-training/` for
verification.
