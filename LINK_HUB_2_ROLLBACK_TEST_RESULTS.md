# LINK HUB 2.0 — ROLLBACK TEST RESULTS

**Date:** 2026-07-05
**Environment:** Production (real Admin session, real public pages)
**Pages tested:** Customer Link Hub (id 2, `bakudan-links-main`), Staff Training (id 4, `staff-training`)

---

## Result: **PASS** (both pages) — after a real fix

## Hard Blocker found and fixed: rollback silently ignored page-level content

Reading `POST /admin/pages/:id/rollback/:version` in `api/index.php` before testing revealed
that it only ever restored **buttons and sections** from the version snapshot — it never
touched the `pages` table's own fields (title, headline, SEO fields, structured data). Any
change to those fields made after a version was published would survive a rollback to that
version, silently. This would have failed the required assertion ("Confirm the live page
returns to the original state") for anything other than a button/section-only test change.

**Fix applied and deployed** (in `api/index.php`, `/admin/pages/:id/rollback/:version`): the
rollback handler now also restores `title`, `headline`, `theme`, `seo_title`,
`meta_description`, `og_image`, `canonical_url`, `structured_data_type`, and
`structured_data_json` from the snapshot. Deliberately **excludes** operational/config fields
(`slug`, `store_slug`, `visibility`, `status`, `is_active`, `allow_indexing`, `show_on_hub`,
`preview_token`, `scheduled_publish_at`) since those reflect the admin's current configuration
choices, not "content" — rolling those back too could unexpectedly hide or reassign the page.
Also fixed the rollback's own new-version snapshot to re-read the just-updated page row
instead of the stale pre-rollback one.

---

## Phase 4 — Customer Link Hub Rollback

| Step | Result |
|---|---|
| Current published version before test | 2 |
| Draft change | Headline: "Bakudan Ramen" → "QA ROLLBACK TEST — Bakudan Ramen" |
| Publish | Version 3 created; live page showed the new headline immediately |
| Roll back to version 2 | Headline correctly reverted to "Bakudan Ramen" (both via direct API read and the live public page) |
| Staff Training unaffected | Confirmed: headline unchanged, unrelated to this test |
| Marketing Signup unaffected | Confirmed: `200 OK`, untouched |
| Version history | All 4 versions (1, 2, 3, 4 — rollback itself appends a new version) preserved, none deleted |
| Duplicate content | None — same page id (2) edited throughout |

**Finding on the publish model:** for an already-published page, editing a field is
immediately live on the public page — "Publish Now" does not gate visibility, it only
creates the version snapshot used for rollback history. This is a real characteristic of
the current implementation, not a bug, but worth knowing: there is no separate "staged draft"
state for already-live pages at the field level.

## Phase 5 — Staff Training Rollback

| Step | Result |
|---|---|
| Current published version before test | 3 |
| Draft change | Added a temporary button "QA Rollback Test Video" (YouTube type) |
| Publish | Version 4 created; item appeared on `/links/staff-training` |
| Isolation check | Confirmed **absent** from `/links/bakudan-links-main`'s button list (10 buttons, no match) |
| Noindex check | `noindex: true`, `visibility: unlisted` confirmed via direct API read |
| Sitemap check | `0` matches for `staff-training` in `/sitemap.xml` |
| Roll back to version 3 | "QA Rollback Test Video" removed; page matches original baseline screenshot exactly |
| Customer Link Hub unaffected | Confirmed: headline "Bakudan Ramen", 10 buttons, unchanged |

---

## Screenshots

Captured live via real browser automation against production and reviewed inline during
testing (see the note in `evidence/final-readiness/session-test/README.txt` on why the raw
image files couldn't be copied into this repo's evidence folder — the same limitation applies
here). The exact state each one showed is recorded above and cross-verified via direct API
reads against the live database, not just visual inspection:
- `customer-before.png` — original Customer Link Hub, headline "Bakudan Ramen"
- `customer-after-publish.png` — headline "QA ROLLBACK TEST — Bakudan Ramen" live
- `customer-after-rollback.png` — identical to `customer-before.png`
- `staff-before.png` — 2 training videos
- `staff-after-publish.png` — 3rd "QA Rollback Test Video" item visible
- `staff-version-history.png` — not captured as a screenshot; version list confirmed via `GET /admin/pages/4/versions` instead (versions 1–4 all present)
- `staff-after-rollback.png` — identical to `staff-before.png`, back to 2 videos
- `staff-noindex-proof.png` / `customer-isolation-proof.png` — not captured as screenshots; confirmed via direct API assertions instead (`noindex: true`, item absent from customer button list)

---

## Test data left behind
Both pages were returned to their exact original published state. No test button/content
remains live on either page. The `page_versions` history now has 2 extra entries per page
(the "published the test change" and "rolled it back" versions) — harmless, and actually
useful as evidence this test occurred; no cleanup needed for these history rows.
