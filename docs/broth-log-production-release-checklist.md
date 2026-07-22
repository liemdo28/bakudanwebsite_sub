# Broth Log Production Release Checklist

Generated: 2026-07-21

Current release decision: **NO-GO for production until the controlled release unblocks `/broth-log`, deploys from a tag/release, and browser verification passes**.

## CI/CD Gate

Only one deployment workflow exists in source:

- `.github/workflows/deploy-production.yml`

Production deployment is allowed only from:

- version tags matching `v*`
- published GitHub Releases

Ordinary branch pushes do not deploy.

## Required Before Production

| Item | Status | Notes |
|---|---|---|
| Configure GitHub `production` environment | Complete | Production environment exists in GitHub. |
| Configure required reviewers for `production` | Complete | Repository owner configured production protection. |
| Configure `PRODUCTION_*` secrets | Complete | Required by the production workflow. |
| Remove or rotate legacy generic deployment secrets | Not complete | Old generic secrets should not be used by deployment. |
| Confirm production HTTPS | Required before release | Verify `https://www.bakudanramen.com`. |
| Confirm production route block remains before release | Complete | `.htaccess` blocks `/broth-log` until a controlled release. |
| Confirm production clean route after unblocking | Required before release | `/broth-log` is the single manager-facing route. |
| Confirm removed branch routes are unavailable | Required before release | Branch-specific Broth Log URLs are no longer deployed or routed. |
| Confirm clean-route refresh avoids 404 | Required before release | Hard-refresh each clean URL after deployment. |
| Confirm Google Sheets row counts | Required before release | Expected counts: B1=6, B2=2, B3=15. |
| Confirm no console/network errors | Required before release | Browser verification on the public domain. |
| Confirm mobile/tablet layout | Required before release | Verify usable layouts on real devices or browser emulation. |
| Confirm exports | Required before release | CSV, Excel-compatible export, and Print/PDF. |

## Release Steps

1. Confirm all dashboard changes are committed.
2. Confirm `.github/workflows/deploy-production.yml` is the only deployment workflow.
3. For the controlled release commit only, remove the temporary `.htaccess` Broth Log block and enable `/broth-log`.
4. Confirm production-only secrets exist:
   - `PRODUCTION_HOST`
   - `PRODUCTION_USERNAME`
   - `PRODUCTION_PASSWORD`
   - `PRODUCTION_PORT`
   - `PRODUCTION_TARGET_DIR`
5. Confirm `PRODUCTION_TARGET_DIR` points to the production document root; on DreamHost this path must contain `bakudanramen.com`.
6. Create a version tag such as `v2026.07.21-broth-log`.
7. Publish a GitHub Release or push the version tag.
8. Approve the GitHub `production` environment deployment.
9. Verify the public route in a browser:
   - `https://www.bakudanramen.com/broth-log`
10. Confirm the top store selector switches:
   - B1 The Rim
   - B2 Stone Oak
   - B3 Bandera
11. Hard-refresh each route and confirm no 404.
12. Confirm live row counts:
   - B1: 6
   - B2: 2
   - B3: 15
13. Test filters:
   - Reset filters
   - Min F / Max F
   - Date range
   - Employee
   - Issue
   - Shift
14. Test exports:
   - CSV
   - Excel-compatible `.xls`
   - Print/PDF
15. Verify console/network:
   - No JavaScript errors
   - No failed Google Sheet requests
   - No mixed-content warnings
   - No CORS/CSP errors
   - No missing assets

## Go Gate

Production can be marked **GO** only when:

- Public clean-route refresh returns 200 for `/broth-log`.
- Public row counts match B1=6, B2=2, B3=15.
- HTTPS works without certificate warnings.
- Browser console/network checks are clean.
- Static dashboard controls and exports pass on the public domain.
- No production-facing file contains localhost, development ports, Windows paths, secrets, private config, or removed deployment references.
