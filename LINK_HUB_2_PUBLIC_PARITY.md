# LINK HUB 2.0 - Public Parity

Public URL: `https://www.bakudanramen.com/links/`
Evidence: `evidence/screenshots/public-links-desktop.png`, `evidence/screenshots/public-links-iphone.png`, `evidence/screenshots/public-links-android.png`, `evidence/tests/browser-route-qa.json`

## Rendering

Status: PASS
Severity: P0
Source File: `links/index.html`
API Endpoint: `GET /api/public/links/bakudan-links-main`
Database Table: `pages`, `link_sections`, `buttons`
Test Method: Browser smoke across desktop, iPhone viewport, Android viewport.
Expected Result: Page loads; no console errors; no 404/500; no duplicate render; sections/buttons ordered.
Actual Result: Public page loaded on all three viewports with no console errors and no failed non-favicon responses. Visible text included header, order section, rewards section, social links, and YouTube entries lower on the page.
Evidence: `evidence/tests/browser-route-qa.json`, screenshots listed above.
Root Cause: N/A
Required Fix: None.
Retest Result: PASS.

## Design Parity

Status: PASS
Severity: P0
Source File: `links/index.html`
API Endpoint: N/A
Database Table: N/A
Test Method: Screenshot capture after current production behavior was recorded.
Expected Result: No redesign, color, typography, layout, or order changes during audit.
Actual Result: No public source changes were made in this audit pass. Screenshots record current production layout for future diffing.
Evidence: `evidence/screenshots/public-links-desktop.png`, `evidence/screenshots/public-links-iphone.png`, `evidence/screenshots/public-links-android.png`
Root Cause: N/A
Required Fix: Keep these screenshots as baseline before future core changes.
Retest Result: PASS.

## Public Data Source

Status: PASS
Severity: P0
Source File: `links/index.html`, `api/index.php`
API Endpoint: `/api/public/links/{slug}`
Database Table: `pages`, `buttons`, `link_sections`
Test Method: Source inspection and API response.
Expected Result: Public uses same DB/content model as Admin.
Actual Result: Public fetches `/api/public/links/bakudan-links-main`; Admin fetches `/api/admin/pages/2`; both are served by `api/index.php` against `bakudan.db`.
Evidence: `evidence/api/live-api-smoke.json`, `evidence/deployment/production-backup.json`
Root Cause: N/A
Required Fix: None.
Retest Result: PASS.

## Link Behavior Coverage

| Link Type | Evidence | Status |
| --- | --- | --- |
| External website | E2E external button preserved `https://example.com/page` | PASS |
| Internal page | E2E internal button saved `internal_page_id=2` | PASS |
| YouTube | Two live YouTube Shorts visible; invalid YouTube rejected | PASS |
| Toast order | Public Toast order URLs preserved | PASS |
| Toast signup | Marketing signup returns Toast signup URLs | PASS |
| Phone | E2E phone button accepted and normalized | PASS |
| Email | E2E email button accepted and normalized | PASS |
| Google Maps | Location table has `maps_url` but current records are null | PARTIAL |
| Instagram | Live button exists as external URL/link type external | PARTIAL |
| Facebook | Live button exists as external URL/link type external | PARTIAL |
| PDF | API supports type validation path; no production PDF content currently configured | NOT APPLICABLE |
| Download | API supports type validation path; no production download content currently configured | NOT APPLICABLE |
| Custom | API supports custom type path; no production custom content currently configured | NOT APPLICABLE |

## Hidden/Future/Expired Content

Status: PASS
Severity: P1
Source File: `api/index.php`
API Endpoint: `/api/public/links/{slug}`
Database Table: `buttons`
Test Method: API comparison admin vs public and SQL checks.
Expected Result: Hidden, expired, and future buttons do not render publicly.
Actual Result: Admin page has 15 buttons; public response has 12. SQL found 0 expired active and 0 future visible buttons.
Evidence: `evidence/api/live-api-smoke.json`, `evidence/database/db-integrity.json`
Root Cause: N/A
Required Fix: Add explicit automated fixtures for future/expired buttons in production.
Retest Result: PASS.
