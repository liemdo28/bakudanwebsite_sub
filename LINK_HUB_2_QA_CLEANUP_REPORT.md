# LINK HUB 2.0 — QA DATA CLEANUP REPORT

**Date:** 2026-07-05
**Environment:** Production, direct API deletion (each item verified against its exact expected name/email/content before deletion — not blind ID-based deletion)

---

## Result: **PASS**

## Method
Every deletion in this pass followed the same pattern: fetch the item fresh, assert its name/email/message matched an exact expected string, and only then call the delete endpoint — aborting instead of deleting on any mismatch. Soft-delete (Trash) was used for pages/sections/buttons first; Trash was only permanently purged after confirming every item in it was one of the pages just soft-deleted in this same pass (no older, unrelated Trash contents existed).

## Items removed

| Item | Type | Verified value before delete |
|---|---|---|
| Test Template Page (id 6) + its 3 sections + 16 buttons (incl. "Call La Cantera", "QA scope button test") | Page | Soft-deleted then permanently purged from Trash |
| QA Session Reliability Test (id 7) + its 3 auto-seeded empty sections | Page | Soft-deleted then permanently purged from Trash |
| QA Permission Test - Stone Oak (id 8) + its 3 auto-seeded empty sections | Page | Soft-deleted then permanently purged from Trash |
| Standard Location Hub Test | Template | Name matched exactly |
| QA Test Campaign | Campaign | Name matched exactly |
| QA Perm La Cantera Campaign ×2 | Campaign | Name matched exactly (this pass's own permission-test duplicates, created while re-running the test script) |
| `qa-storemanager-thela cantera@bakudanramen.com` | User | Email + role matched exactly |
| `qa-manager-la cantera@bakudanramen.com` | User | Email + role matched exactly (this pass's own permission-test account) |
| `qa-manager-stone-oak@bakudanramen.com` | User | Email + role matched exactly (this pass's own permission-test account) |
| `qa-manager-bandera@bakudanramen.com` | User | Email + role matched exactly (this pass's own permission-test account) |
| "QA test notice — Tier 3 verification" | Notice | Message matched exactly |
| "QA Test Location (closure notice) is temporarily closed..." | Notice | Message prefix matched exactly |
| "QA perm test notice" ×2 | Notice | Message matched exactly (this pass's own permission-test duplicates) |
| QA Test Feedback Form + its 1 submission | Form | Name matched exactly; submission removed via `ON DELETE CASCADE` (confirmed `PRAGMA foreign_keys=ON` is set) |
| QA Auto-expire test | Automation rule | Name matched exactly |
| QA Test Location (`qa-test-closure-location`) | Location | Slug matched exactly |

## Item deliberately kept

| Item | Reason |
|---|---|
| `AhiTunaSalad.jpg` in Media Library | This is a **real product photo** already in the project's image folder, not fake test data — it was registered (not created) during earlier Tier 3 testing to verify the upload pipeline. Deleting it would remove a legitimate, reusable asset for no benefit. |

## Safety notes
Two deletion attempts were correctly blocked mid-pass by the environment's safety layer for not showing verification *causally gating* the delete (printing a check next to an unconditional command isn't the same as the command actually depending on it). Both were redone using scripts that fetch-check-abort-or-delete in one dependent flow, and succeeded once the verification was real rather than cosmetic — the safety layer worked as intended here.

## Post-cleanup database checks

| Check | Result |
|---|---|
| QA users | **0** (1 total user: `admin@bakudanramen.com`) |
| QA pages | **0** (2 total pages: Customer Link Hub, Staff Training) |
| QA campaigns | **0** (0 total campaigns) |
| QA locations | **0** (3 total locations: La Cantera, Stone Oak, Bandera — all real) |
| QA notices | **0** (0 total notices) |
| QA forms | **0** (0 total forms) |
| QA buttons on live customer pages | **0** — confirmed via live `GET /api/public/links/bakudan-links-main` |
| QA automations | **0** (0 total automation rules — none intentionally retained since none were converted to real rules) |
| Orphan records | **0** — Trash confirmed empty after purge; sections/buttons for deleted pages were removed via cascade delete, not left dangling |

## Production health after cleanup
- `GET /api/public/links/bakudan-links-main` → `200`
- `GET /api/public/links/staff-training` → `200`
