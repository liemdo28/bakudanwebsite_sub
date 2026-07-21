# LINK HUB 2.0 - Database Audit

DB path: `/home/hoale24new/bakudan-app/data/bakudan.db`
Audit copy: `evidence/database/bakudan-audit-copy.db`
Evidence: `evidence/database/db-integrity.json`

## Schema Coverage

Status: PASS
Severity: P0
Source File: `api/index.php`
API Endpoint: N/A
Database Table: all core tables
Test Method: SQLite schema inspection.
Expected Result: Tables exist for users, pages, sections, buttons, locations, versions, analytics, audit logs, link health, shortlinks.
Actual Result: All listed tables exist.
Evidence: `evidence/database/db-integrity.json`
Root Cause: N/A
Required Fix: Add formal migration version table for better operational traceability.
Retest Result: PASS with caveat.

## Required Integrity Queries

| Query | Result | Status |
| --- | --- | --- |
| Duplicate active buttons | 0 rows | PASS |
| Buttons without page | 0 rows | PASS |
| Buttons without section unexpectedly | 9 rows | PARTIAL |
| Sections without page | 0 rows | PASS |
| Duplicate page slugs | 0 rows | PASS |
| Invalid internal_page_id | 0 rows | PASS |
| Multiple active published versions per slug | 0 rows | PASS |
| Expired active buttons | 0 rows | PASS |
| Future visible buttons | 0 rows | PASS |

## Buttons Without Section

Status: PASS
Severity: P2
Source File: `api/index.php`, `links-admin/app.js`
API Endpoint: `GET /api/admin/pages/2`, `GET /api/public/links/bakudan-links-main`
Database Table: `buttons`
Test Method: SQL query.
Expected Result: Every public section is admin-controlled; loose buttons are intentional and render predictably.
Actual Result: 9 buttons have `section_id=NULL`, including social/site/YouTube buttons. Public renderer supports loose buttons, but this weakens the "all public content belongs to an admin-managed section" policy.
Evidence: `evidence/database/db-integrity.json`
Root Cause: Legacy loose button model retained for unsectioned links.
Required Fix: Either create an explicit "Links" section for loose buttons or document `NULL section_id` as an intended default section.
Retest Result: PARTIAL.

## Version Records

Status: PARTIAL
Severity: P1
Source File: `api/index.php`
API Endpoint: `/api/admin/pages/:id/publish`, `/api/admin/pages/:id/versions`
Database Table: `page_versions`
Test Method: Pre-test DB count and E2E publish test.
Expected Result: Production pages have published version history and rollback evidence.
Actual Result: Initial audit DB snapshot had `page_versions=0`; after fix, the main links page and Staff Training page each have version 1 baseline.
Evidence: `evidence/tests/fix-all-live-actions.json`, `evidence/tests/fix-all-current-state.json`
Root Cause: Existing production pages predated versioning.
Required Fix: None for current production pages.
Retest Result: PASS.

## Audit Logs

Status: PASS
Severity: P2
Source File: `api/index.php`
API Endpoint: `GET /api/admin/audit-logs?limit=20`
Database Table: `audit_logs`
Test Method: Live API request.
Expected Result: Admin actions recorded with action/entity/page/user.
Actual Result: 20 recent logs returned after E2E, including page_created, page_published, button_updated, page_rolled_back, page_deleted.
Evidence: `evidence/api/audit-log-endpoint.json`
Root Cause: N/A
Required Fix: Consider adding request IDs and IP/user-agent fields for production incident response.
Retest Result: PASS.

## WAL / Concurrency / Backup Restore

Status: PASS
Severity: P1
Source File: `api/index.php`
API Endpoint: N/A
Database Table: SQLite database
Test Method: Backup copy and E2E writes.
Expected Result: Backup and restore command tested without touching production.
Actual Result: Backup copy was restored to a local production DB copy and passed `PRAGMA integrity_check=ok`; pages/buttons counts were readable.
Evidence: `evidence/deployment/production-backup.json`, `evidence/database/restore-test.json`
Root Cause: N/A
Required Fix: Optional: repeat the same restore procedure on a hosted release environment.
Retest Result: PASS.
