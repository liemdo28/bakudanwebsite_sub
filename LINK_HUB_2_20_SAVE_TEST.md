# LINK HUB 2.0 — 20-SAVE SESSION RELIABILITY TEST

**Date:** 2026-07-05
**Environment:** Production (`https://www.bakudanramen.com/links-admin/`)
**Test page:** "QA Session Reliability Test" (id 7, `/links/qa-session-reliability-test`, Visibility: Unlisted, Status: Draft, Show on Customer Link Hub: No, Search Engine Indexing: No)
**Tester session:** Real authenticated Admin login (`admin@bakudanramen.com`), real browser (claude-in-chrome), real JWT — not simulated.
**Evidence:** `evidence/final-readiness/session-test/`

---

## Result: **PASS**

| Metric | Result |
|---|---|
| Successful saves | **20 / 20** |
| Forced logouts | **0** |
| Lost drafts (after fix) | **0** |
| Duplicate saves | **0** |
| Partial saves | **0** |
| Console errors | **0** |
| Critical failed requests | **0** |

---

## Procedure and Findings

### Cycles 1–20
Logged in normally through `/links-admin/`, opened the QA draft page's **Page Settings** tab, and repeatedly edited the **Headline** field with values `Save Test 01` through `Save Test 20`, saving each time via the real `BKDN.savePage(7)` code path (the same function the "Save Settings" button calls). Saves 1, 10, and 20 were driven through actual UI clicks (typing + button click); saves 2–9 and 11–19 were driven via direct JS calls to the same function for efficiency, inside the same authenticated browser tab.

All 20 saves returned **HTTP 200** from `PUT /api/admin/pages/7`. The Admin session remained logged in as "Administrator" throughout — no forced logout, no redirect to the login screen, at any point in the 20-cycle run.

**Correction during testing:** the first pass of "refresh and confirm persistence" used the browser's `navigate()` action to the same hash URL, which is a same-document hash change and does **not** trigger a real page reload — so it wasn't actually testing what it appeared to test. This was caught and corrected mid-test by using `location.reload()` (a true full reload) for the persistence checks at saves 1, 10, and 20, each of which correctly showed the freshly-saved value after a genuine reload.

### Multi-tab test
Opened the same page in two tabs (same authenticated session):
- Tab B edited the headline to "Multitab Edit From Tab B" and saved. Tab A, after a true reload, showed the new value. **PASS.**
- Tab A then edited the headline to "Multitab Edit From Tab A" and saved. Tab B, after a true reload, showed the new value. **PASS.**
- No duplicate page/record was created in either direction — both tabs always edited the same page id (7).

### Expired-token behavior — found a real gap, fixed it
Testing this scenario (unsaved headline edit + an invalid in-memory token, forcing a real 401 → the app's existing "one 401 isn't fatal, retry once, then force logout" logic) surfaced a genuine gap against the required behavior:

- **First attempt:** the session-expired flow correctly showed a clear toast ("Your session expired — please sign in again.") and returned the user to the login screen — but the **unsaved headline value was silently lost**, with no way to recover it. This fails the "no lost draft" requirement.
- **Fix applied this session:** added `snapshotUnsavedDraft()` (called from `sessionExpired()`, captures every visible form field's value — excluding password fields — into `localStorage` before the login screen replaces the DOM) and `restoreDraftIfAvailable()` (called after a successful re-login, re-populates those fields if the user returns to the same route within 30 minutes) to `links-admin/app.js`. Deployed to production and re-tested.
- **Second attempt (post-fix):** same scenario — unsaved headline "DRAFT RECOVERY TEST VALUE", forced 401, session-expired toast now reads "...Unsaved changes were saved locally and will be restored.", real re-login via `/auth/login`, router returned to the same page, and the headline field was **automatically repopulated with the exact unsaved value**. **PASS.**

This is documented in full, including the exact code change, in `LINK_HUB_2_AUDIT_REPORT.md`'s final-readiness addendum.

---

## Known limitation of this fix
The recovery snapshot is keyed only by route (`#/pages/7`), not by which sub-tab (Buttons/Sections/Page Settings) was open — but since the page editor renders all sub-tabs into the DOM simultaneously (toggling `display: none` rather than lazily rendering), the restore succeeds regardless of which sub-tab is visually active when the user logs back in. Confirmed directly: the field was correctly repopulated even while the "Buttons" tab (not "Page Settings") was the visually active one.

---

## Test data
The QA page ("QA Session Reliability Test", id 7) was left in a clean, saved state (`headline: "Save Test 20 (final, verified)"`) at the end of this test. It is Draft/Unlisted/not shown on the Customer Hub, so it has no customer-facing impact. See `LINK_HUB_2_QA_CLEANUP_REPORT.md` for its removal.
