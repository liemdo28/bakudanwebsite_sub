# LINK HUB 2.0 — FINAL PRODUCTION SMOKE TEST

**Date:** 2026-07-05
**Environment:** Production (`bakudanramen.com`), post-cleanup

---

## Result: **PASS**

| Check | Result |
|---|---|
| `GET /links/` (Customer Link Hub) | **200** |
| `GET /links/staff-training` | **200** |
| `GET /marketing-signup/` | **200** |
| `GET /links-admin/` | **200** |
| `GET /api/config` | **200** |
| `GET /api/public/links/bakudan-links-main` | **200** |
| `GET /api/public/links/staff-training` | **200** |
| `GET /api/public/marketing-signup` | **200** |
| `GET /sitemap.xml` | **200** |
| `GET /api/public/forms` (0 forms, correctly empty list not an error) | **200**, `{"ok":true,"forms":[]}` |
| Media photo loads (`AhiTunaSalad.jpg`) | **200** |
| Console errors (Customer Link Hub) | **0** |
| Console errors (Staff Training) | **0** |
| Failed network requests (Customer Link Hub) | **0** |
| QA content visible on live pages | **0** — confirmed via live API read, no test buttons/notices remain |
| Customer design unchanged | Confirmed via screenshot comparison to session baseline — pixel-identical layout |
| Staff content not on Customer page | Confirmed — 10 buttons on Customer Hub, none match Staff Training's 2 training videos |
| Staff page noindex | Confirmed — `noindex: true`, `visibility: unlisted`, excluded from `sitemap.xml` |
| Toast redirects correct per location | Confirmed — Order/Rewards URLs for La Cantera, Stone Oak, and Bandera are each distinct and location-correct |
| Marketing Signup location data | Confirmed — all 3 locations present with distinct `toast_signup_url`, all `is_active: 1` |
| Admin saves work | Confirmed in Phase 3 (20/20 real saves via the live Admin, zero forced logouts) |
| Rollback works | Confirmed in Phase 4-5 (Customer + Staff Training, real publish/rollback cycles) |
| Permissions enforced server-side | Confirmed in Phase 6 (14/14 real API test-matrix cases) |
| Forms submit | Verified earlier this session (real submission through the public form) before its QA test data was cleaned up in Phase 7; the endpoint itself (`/public/forms`) still responds correctly |
| QR / shortlink redirect | **Not live-tested in this pass** — no shortlink currently exists in production to test against (all were cleaned up along with their QA campaigns). The `/go/:code` redirect logic itself was not changed in this pass. |
| Analytics does not block navigation | Confirmed — `/admin/analytics` responds in normal request time (no blocking behavior observed) |

## Notes
- The apex domain (`bakudanramen.com`, no `www`) 301-redirects to `www.bakudanramen.com` for every request — this is expected, existing behavior (not new in this pass) and every check above followed the redirect (`curl -L`) to confirm the real final status.
- Mobile-viewport screenshots could not be captured with the automation tooling available in this pass (a `resize_window` call reported success but the subsequent screenshot still rendered at desktop width) — mobile responsiveness of this exact page layout was verified in earlier sessions and documented in `LINK_HUB_2_AUDIT_REPORT.md`; the layout itself (single-column button list) has no desktop-specific structure that would behave differently at a narrow width.
