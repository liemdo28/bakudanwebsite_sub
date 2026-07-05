# LINK HUB 2.0 - Admin Test Results

Admin URL: `https://www.bakudanramen.com/links-admin/`
Evidence: `evidence/tests/browser-route-qa.json`, `evidence/tests/admin-e2e-temp-page.json`, `evidence/screenshots/admin-dashboard.png`, `evidence/screenshots/admin-blog.png`, `evidence/screenshots/admin-page-editor-youtube.png`, `evidence/screenshots/admin-audit-log.png`

## Browser Runner

Status: PARTIAL
Severity: P3
Source File: N/A
API Endpoint: N/A
Database Table: N/A
Test Method: Attempted in-app Browser, then fallback Playwright/Chrome.
Expected Result: Use in-app Browser plugin for screenshots and DOM checks.
Actual Result: In-app Browser failed with `incrementalAriaSnapshot` runtime error. Fallback used Playwright with installed Google Chrome.
Evidence: `evidence/console/browser-plugin-fallback.json`
Root Cause: Browser plugin/runtime mismatch.
Required Fix: Repair Browser plugin runtime for future in-app screenshot capture.
Retest Result: Fallback QA completed.

## Admin Route Smoke

Status: PASS
Severity: P0
Source File: `links-admin/app.js`
API Endpoint: multiple `/api/admin/*`
Database Table: multiple
Test Method: Authenticated route navigation.
Expected Result: All main admin routes load without red failure banners, console errors, or page errors.
Actual Result: Initial 12/12 routes passed. After fixes, focused regression passed Dashboard, Pages, Page Editor, QR & Shortlinks, Link Health, Audit Log, Locations, and Blog.
Evidence: `evidence/tests/browser-route-qa.json`, `evidence/tests/fix-all-browser-qa.json`
Root Cause: N/A
Required Fix: None.
Retest Result: PASS.

## Login and Session

Status: PASS
Severity: P0
Source File: `links-admin/app.js`, `api/index.php`
API Endpoint: `/api/auth/login`, `/api/auth/me`, `/api/admin/pages/:id`
Database Table: `users`, `pages`
Test Method: Browser login and 20 consecutive authenticated page saves.
Expected Result: Login succeeds; token survives refresh/API usage; 20 saves do not force logout or lose draft.
Actual Result: Login succeeded; 20 API saves on temp page completed with 0 forced logout and 0 lost title update.
Evidence: `evidence/tests/admin-e2e-temp-page.json`
Root Cause: N/A
Required Fix: None.
Retest Result: PASS.

## Pages Management

Status: PASS
Severity: P0
Source File: `links-admin/app.js`, `api/index.php`
API Endpoint: `/api/admin/pages`, `/api/admin/pages/:id`, `/api/admin/pages/:id/duplicate`, `/api/admin/pages/:id/publish`, `/api/admin/pages/:id/rollback/:version`
Database Table: `pages`, `page_versions`
Test Method: Temp page E2E and source inspection.
Expected Result: Create/edit/preview/publish/rollback/delete available and tested.
Actual Result: Create/edit/preview/publish/rollback/delete tested. Production version baselines are now seeded for the main and Staff Training pages. Duplicate endpoint exists but was not exercised in temp flow. Schedule endpoint exists; day-of-week scheduling is not implemented.
Evidence: `evidence/tests/admin-e2e-temp-page.json`, `evidence/tests/fix-all-current-state.json`, `api/index.php`
Root Cause: Partial advanced workflow coverage.
Required Fix: Add automated tests for duplicate, schedule, redirect old slug, archive semantics.
Retest Result: PASS core, PARTIAL advanced recurring/bulk workflows.

## Sections Management

Status: PARTIAL
Severity: P2
Source File: `links-admin/app.js`, `api/index.php`
API Endpoint: `/api/admin/pages/:id/sections`, `/api/admin/sections/:id`
Database Table: `link_sections`
Test Method: Temp page section create and source inspection.
Expected Result: Create/rename/reorder/duplicate/hide/show/schedule/archive/delete/move/copy.
Actual Result: Create/update/delete/hide/status/schedule fields are present. Reorder/duplicate/move/copy workflows were not verified and no bulk location copy UI evidence was found.
Evidence: `evidence/tests/admin-e2e-temp-page.json`, `links-admin/app.js`
Root Cause: Advanced section operations incomplete or untested.
Required Fix: Implement/test section reorder, duplicate, move/copy workflows if required.
Retest Result: PARTIAL.

## Buttons Management

Status: PASS
Severity: P1
Source File: `links-admin/app.js`, `api/index.php`
API Endpoint: `/api/admin/pages/:id/buttons`, `/api/admin/buttons/:id`, `/api/admin/buttons/:id/duplicate`, `/api/admin/pages/:id/buttons/reorder`
Database Table: `buttons`
Test Method: Temp button create/update/delete through page cleanup; source inspection for duplicate/reorder.
Expected Result: Create/edit/duplicate/delete/hide/show/reorder/move section/page/featured/icon/subtitle/type/schedule/copy/bulk.
Actual Result: Create/edit/delete/type validation and schedule fields pass. Duplicate/reorder endpoints exist. Bulk update/copy selected locations/move page were not verified.
Evidence: `evidence/tests/admin-e2e-temp-page.json`, `links-admin/app.js`
Root Cause: Bulk/move workflows are not complete evidence.
Required Fix: Add admin UI tests for duplicate/reorder/bulk/move-page.
Retest Result: PASS core, PARTIAL advanced.

## Form Validation

Status: PASS
Severity: P1
Source File: `api/index.php`
API Endpoint: `/api/admin/pages/:id/buttons`
Database Table: `buttons`
Test Method: Live negative API tests.
Expected Result: Reject invalid URL, unsupported protocol, duplicate URL, bad YouTube URL.
Actual Result: Unsupported external URL and non-YouTube URL were rejected with 400; duplicate URL rejected in temp flow.
Evidence: `evidence/api/live-api-smoke.json`, `evidence/tests/admin-e2e-temp-page.json`
Root Cause: N/A
Required Fix: Add tests for duplicate label, end-before-start, missing location URL, empty slug, duplicate slug.
Retest Result: PASS for tested cases.
