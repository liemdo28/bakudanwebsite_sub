# LINK HUB 2.0 - Source Audit

Audit date: 2026-07-04
Workspace: `D:\Project\Master\Bakudan\bakudanramen.com-current`
Branch: `seo/phase-28-homepage-og-tags`
Commit: `86d05dd`

## Production Baseline

Status: PASS
Severity: P0
Source File: `api/index.php`, `links/index.html`, `links-admin/index.html`, `links-admin/app.js`
API Endpoint: `/api/public/links/bakudan-links-main`, `/api/admin/dashboard`
Database Table: `pages`, `link_sections`, `buttons`, `locations`
Test Method: Remote file backup/hash, API smoke, browser route QA.
Expected Result: Identify the exact production source, API, database, and deployment path before readiness decisions.
Actual Result: Production is served from DreamHost under `/home/hoale24new/bakudanramen.com`; DB is `/home/hoale24new/bakudan-app/data/bakudan.db`; main API is `/home/hoale24new/bakudanramen.com/api/index.php`.
Evidence: `evidence/deployment/production-backup.json`
Root Cause: N/A
Required Fix: None for source identification.
Retest Result: PASS

## Architecture Map

| Item | Result | Evidence |
| --- | --- | --- |
| Public entry file | `links/index.html` renders `/links/` | `evidence/deployment/production-backup.json` |
| Admin entry file | `links-admin/index.html` loads admin app shell | `evidence/deployment/production-backup.json` |
| Admin JavaScript bundle | `links-admin/app.js` | `evidence/deployment/production-backup.json` |
| Public JavaScript bundle | Inline JS in `links/index.html` | `links/index.html` |
| Main API entry | `api/index.php` | `api/index.php` |
| Authentication handler | `POST /api/auth/login`, `GET /api/auth/me` in `api/index.php` | `evidence/api/live-api-smoke.json` |
| Database path | `/home/hoale24new/bakudan-app/data/bakudan.db` | `api/index.php`, `evidence/deployment/production-backup.json` |
| Migration files | Idempotent inline `CREATE TABLE IF NOT EXISTS` + `ALTER TABLE` guards in `api/index.php`; no separate migration version table | `api/index.php` |
| Upload directory | `/home/hoale24new/bakudanramen.com/uploads/blogs/` | `api/index.php` |
| Cache layer | Admin bundle querystring cache bust; no CDN purge workflow found | `links-admin/index.html` |
| Deployment workflow | SFTP scripts under `scripts/`; current audit backup created remotely | `scripts/_deploy_static_pages.py`, `evidence/deployment/production-backup.json` |

## Duplicate Backend Check

Status: PARTIAL
Severity: P1
Source File: `api/index.php`, `api/index-lite.php`, `server/server-passenger-lite.js`, `server/routes/*`
API Endpoint: `/api/*`
Database Table: N/A
Test Method: Source grep and live route hash.
Expected Result: One controlled production API source.
Actual Result: Live traffic uses `api/index.php`; legacy Node/PHP-lite files still exist in repo but are not identified in the live request path.
Evidence: `evidence/deployment/production-backup.json`, `LINK_HUB_2_IMPLEMENTATION.md`
Root Cause: Historical implementations were retained for rollback/reference.
Required Fix: Mark legacy APIs deprecated or move to archive to reduce future accidental deploy risk.
Retest Result: Live production path PASS; repo hygiene PARTIAL.

## Environment/Secret Check

Status: PASS
Severity: P1
Source File: `scripts/_deploy_static_pages.py`
API Endpoint: N/A
Database Table: N/A
Test Method: Source inspection.
Expected Result: No hardcoded production credentials in repo scripts.
Actual Result: Deploy/check scripts now read `BAKUDAN_SFTP_PASS` from the environment and no longer contain the literal SFTP password. Legacy path parsing was replaced with imported config constants; syntax checks passed.
Evidence: `scripts/_deploy_static_pages.py`, `scripts/_deploy_links_temp.py`, `evidence/tests/fix-all-current-state.json`
Root Cause: Fixed.
Required Fix: Optional: rotate the SFTP password if the previous source exposure is treated as credential compromise.
Retest Result: PASS.
