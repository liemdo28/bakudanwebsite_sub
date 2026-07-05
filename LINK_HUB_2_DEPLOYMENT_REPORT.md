# LINK HUB 2.0 Deployment Report

Audit Date: 2026-07-04

## Audit Item

Status: PASS  
Severity: P1  
Page: Customer Link Hub, Staff Training  
Page Type: link_hub, staff_training  
Source File: `.htaccess`, `staff-training/index.html`, `api/index.php`  
API Endpoint: `/api/admin/pages/{id}`, `/api/admin/pages/{id}/publish`, `/api/public/links/{slug}`  
Database Table: `pages`, `page_versions`  
Test Method: Remote backup/deploy evidence, Admin API production publish, smoke tests  
Expected Result: Backup before deploy, production smoke pass, rollback path documented  
Actual Result: Earlier deployment backed up and uploaded `.htaccess`, `api/index.php`, and `staff-training/index.html`. Final page-state fix was applied through Admin API and published live. Final `api/index.php` and `links-admin/app.js` patches were backed up, uploaded, smoke-tested, and screenshot evidence was recut.
Evidence: `evidence/link-hub-2/deployment/staff-route-deploy.json`, `evidence/link-hub-2/api/staff-page-fix-update-publish.json`, `evidence/link-hub-2/tests/customer-baseline-publish.json`, `evidence/link-hub-2/tests/production-functional-tests.json`  
Root Cause: PHP migration v3 used a placeholder with `SQLite3::querySingle()`, preventing v3/v4 migrations from running.  
Required Fix: Local source patch changed v3 to a prepared statement; production DB was corrected via Admin API. Upload patched API when SFTP secret is available.  
Retest Result: Live production behavior PASS

## Deployment Facts

| Item | Result |
| --- | --- |
| Backup before route deploy | PASS |
| Staff redirect removed | PASS |
| `/staff-training/` file deployed | PASS |
| Production Staff page state fixed | PASS |
| Staff page published | PASS, version 3 |
| Customer baseline published | PASS, version 2 |
| Dashboard warnings | PASS, no broken links, no misplaced staff content, no duplicates, no draft changes |
| Latest local API source patch uploaded | PASS |
| Latest local Admin UI source patch uploaded | PASS |
| Official 22 screenshots captured | PASS |
| Admin screenshots recut after deploy | PASS |

## Rollback

Remote deploy backup exists for route deployment. Page-level rollback API is available through `/api/admin/pages/{id}/rollback/{version}` and page versions now exist for both Customer and Staff.
