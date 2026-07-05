# LINK HUB 2.0 Architecture Audit

Audit Date: 2026-07-04

## Audit Item

Status: PASS  
Severity: P0  
Page: Customer Link Hub, Staff Training, Marketing Signup  
Page Type: link_hub, staff_training, marketing_signup  
Source File: `links-admin/app.js`, `links/index.html`, `staff-training/index.html`, `marketing-signup/index.html`, `api/index.php`  
API Endpoint: `/api/admin/pages`, `/api/public/links/{slug}`, `/api/public/marketing-signup`  
Database Table: `pages`, `link_sections`, `buttons`, `locations`, `page_versions`, `audit_logs`  
Test Method: Source inspection, Admin API, public API, production screenshots  
Expected Result: One Admin CMS, one API, one database, shared page/content/version model  
Actual Result: `/links-admin/` manages Customer and Staff pages through the same API and tables. Marketing signup uses the shared `locations` model for Toast signup URLs.  
Evidence: `evidence/link-hub-2/api/admin-pages-before-staff-fix.json`, `evidence/link-hub-2/screenshots/admin-pages-list.png`, `evidence/link-hub-2/api/marketing-signup.json`  
Root Cause: Earlier Staff Training URL was still represented by the old slug `staff-training-videos`.  
Required Fix: Correct Staff page slug/visibility/noindex and provide separate `/staff-training/` renderer.  
Retest Result: PASS

## Source Mapping

| Item | Result |
| --- | --- |
| Production repository | `D:\Project\Master\Bakudan\bakudanramen.com-current` |
| Production branch | Current worktree branch; live deploy is file/SFTP based |
| Public customer renderer | `links/index.html` |
| Staff renderer | `staff-training/index.html` |
| Admin source | `links-admin/index.html`, `links-admin/app.js` |
| Main API | `api/index.php` |
| Legacy API checked | `index-lite.php` exists in backups only; active Admin/public routes use `api/index.php` |
| Database path | `/home/hoale24new/bakudan-app/data/bakudan.db` |
| Upload path | `/home/hoale24new/bakudanramen.com/uploads/blogs/` |
| Production web root | `/home/hoale24new/bakudanramen.com` |
| Deployment flow | Backup then SFTP upload; Admin API used for final page-state publish |
| Rollback | Remote backup folders plus `page_versions` rollback API |

## Notes

Local source now contains a fixed idempotent migration for `staff-training`; the live page state was corrected through Admin API because `BAKUDAN_SFTP_PASS` was not available in this shell after the latest source patch.
