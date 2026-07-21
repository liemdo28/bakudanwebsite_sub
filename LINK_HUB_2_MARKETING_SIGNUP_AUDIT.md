# LINK HUB 2.0 Marketing Signup Audit

Audit Date: 2026-07-04

## Audit Item

Status: PASS
Severity: P1
Page: Marketing Signup
Page Type: marketing_signup
Source File: `marketing-signup/index.html`, `api/index.php`
API Endpoint: `/api/public/marketing-signup`, `/api/public/locations`
Database Table: `locations`, `settings`
Test Method: Production API, screenshot, source inspection
Expected Result: Landing page redirects users to configured Toast-hosted signup URLs by location; no Toast API or Toast browser automation
Actual Result: API returns Toast-hosted signup URLs for La Cantera, Stone Oak, and Bandera from `locations.toast_signup_url`. No Toast API routes or automation were used for signup.
Evidence: `evidence/link-hub-2/api/marketing-signup.json`, `evidence/link-hub-2/screenshots/marketing-selector.png`, `evidence/link-hub-2/screenshots/toast-redirect-proof.png`, `evidence/link-hub-2/screenshots/toast-redirect-la-cantera.png`, `evidence/link-hub-2/screenshots/toast-redirect-stone-oak.png`, `evidence/link-hub-2/screenshots/toast-redirect-bandera.png`
Root Cause: None for current production behavior.
Required Fix: None for required Toast redirect flow.
Retest Result: PASS

## Location Mapping

| Location | Configured Toast Signup URL | Status |
| --- | --- | --- |
| La Cantera | `https://www.toasttab.com/bakudanramen/rewardsSignup` | PASS |
| Stone Oak | `https://www.toasttab.com/bakudan-ramen-stone-oak/rewardsSignup` | PASS |
| Bandera | `https://www.toasttab.com/bakudan-bandera/rewardsSignup` | PASS |

## Notes

Inactive-location and missing-URL warning UI are not covered by current live data because all three production locations are active and configured.
