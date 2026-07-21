# LINK HUB 2.0 - API Audit

## Public Links API

Status: PASS
Severity: P0
Source File: `api/index.php`
API Endpoint: `GET /api/public/links/bakudan-links-main`
Database Table: `pages`, `link_sections`, `buttons`, `analytics`
Test Method: Live API request.
Expected Result: Public page reads the production DB, returns only currently visible buttons/sections, and records pageview without blocking render.
Actual Result: Returned HTTP 200, page `Bakudan links Main`, 12 public buttons, 3 sections. Admin page has 15 buttons because hidden/scheduled/admin-only rows are included there.
Evidence: `evidence/api/live-api-smoke.json`, `evidence/api/public-links-current.json`
Root Cause: N/A
Required Fix: None.
Retest Result: PASS

## Admin Authentication

Status: PASS
Severity: P0
Source File: `api/index.php`
API Endpoint: `POST /api/auth/login`, `GET /api/auth/me`, `GET /api/admin/dashboard`
Database Table: `users`
Test Method: Live unauthenticated and authenticated requests.
Expected Result: Admin endpoints reject missing token; login returns token; token can read current user and dashboard.
Actual Result: Missing token returned 401 Unauthorized. Admin login and `/auth/me` returned super_admin identity. Dashboard returned 200.
Evidence: `evidence/api/live-api-smoke.json`
Root Cause: N/A
Required Fix: None.
Retest Result: PASS

## CRUD and Validation

Status: PASS
Severity: P0
Source File: `api/index.php`
API Endpoint: `/api/admin/pages`, `/api/admin/pages/:id/sections`, `/api/admin/pages/:id/buttons`, `/api/admin/buttons/:id`
Database Table: `pages`, `link_sections`, `buttons`, `audit_logs`
Test Method: Temporary page E2E test.
Expected Result: Create/edit/delete page, section, and buttons; reject duplicate URL and unsupported protocol; reject bad YouTube URL.
Actual Result: Temporary page/section/buttons created; external, YouTube, phone, email, and internal-page button types saved; duplicate URL and invalid URL were rejected.
Evidence: `evidence/tests/admin-e2e-temp-page.json`, `evidence/api/live-api-smoke.json`
Root Cause: N/A
Required Fix: None.
Retest Result: PASS

## Draft, Preview, Publish, Rollback API

Status: PASS
Severity: P0
Source File: `api/index.php`
API Endpoint: `/api/admin/pages/:id/generate-preview-token`, `/api/admin/pages/:id/publish`, `/api/admin/pages/:id/versions`, `/api/admin/pages/:id/rollback/:version`
Database Table: `pages`, `page_versions`, `buttons`, `link_sections`
Test Method: Temporary page E2E test.
Expected Result: Draft is not public; preview token is generated; publish creates versions; rollback restores prior content snapshot.
Actual Result: Draft public API returned 404; preview token generated; two publish versions created; rollback to version 1 restored original button label/URL; temp page cleanup succeeded.
Evidence: `evidence/tests/admin-e2e-temp-page.json`
Root Cause: N/A
Required Fix: None for tested content rollback. Note: rollback pla canteraarily restores sections/buttons; page metadata restoration is limited.
Retest Result: PASS with caveat.

## Marketing Signup API

Status: PASS
Severity: P1
Source File: `api/index.php`
API Endpoint: `GET /api/public/marketing-signup`, `GET /api/public/locations`, `GET/PUT /api/admin/locations`
Database Table: `locations`
Test Method: Live API smoke.
Expected Result: Active locations expose configured Toast signup URLs; no Toast API/browser automation.
Actual Result: Three active locations returned with Toast signup URLs. No Toast API integration code was found.
Evidence: `evidence/api/live-api-smoke.json`, `evidence/database/db-integrity.json`
Root Cause: N/A
Required Fix: Add missing warning UX for locations with missing URLs if not already covered in admin UI.
Retest Result: PASS with caveat.

## Analytics API

Status: PASS
Severity: P2
Source File: `api/index.php`, `links/index.html`
API Endpoint: `GET /api/admin/analytics?period=30`, `POST /api/public/analytics/view`, `POST /api/public/analytics/click`
Database Table: `analytics`
Test Method: Live admin analytics request; public page load.
Expected Result: Admin analytics loads and public analytics failures do not block navigation/render.
Actual Result: Admin analytics returned views, clicks, CTR, and top button list. Public browser smoke had no console/network failures.
Evidence: `evidence/api/live-api-smoke.json`, `evidence/tests/browser-route-qa.json`
Root Cause: N/A
Required Fix: Add richer device/UTM/location attribution if required by policy.
Retest Result: PASS for current schema.

## Link Health API

Status: PASS
Severity: P2
Source File: `api/index.php`, `links-admin/app.js`
API Endpoint: `GET /api/admin/link-health`, `POST /api/admin/link-health/check`
Database Table: `link_health`
Test Method: Admin route QA and DB count.
Expected Result: Checker records status, timestamp, HTTP code; admin warning visible.
Actual Result: Manual checker ran and stored 14 latest results. Toast 403 responses are now `needs_review`; public/admin smoke still passes.
Evidence: `evidence/tests/fix-all-live-actions.json`, `evidence/tests/fix-all-regression.json`, `evidence/tests/fix-all-current-state.json`
Root Cause: Manual-only checker; no host cron was configured in this pass.
Required Fix: Optional: add host cron if automatic checks are required.
Retest Result: PASS.

## QR / Shortlinks API

Status: PASS
Severity: P2
Source File: `api/index.php`
API Endpoint: `/api/admin/shortlinks`, `/api/public/shortlinks/:code`
Database Table: `shortlinks`, `analytics`
Test Method: Source audit, admin UI route QA, and shortlink lifecycle regression.
Expected Result: Create/update/disable shortlink, stable QR, click counts, invalid code 404.
Actual Result: Admin UI added at `#/shortlinks`. Create, update destination, stable `/go/{code}` redirect, disable 404, validation, and cleanup all passed.
Evidence: `evidence/tests/go-shortlink-regression.json`, `evidence/tests/fix-all-browser-qa.json`
Root Cause: N/A
Required Fix: None for current lifecycle.
Retest Result: PASS.
