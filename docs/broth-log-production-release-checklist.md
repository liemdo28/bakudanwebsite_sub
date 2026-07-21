# Broth Log Production Release Checklist

Generated: 2026-07-21

Current release decision: **GO after production workflow succeeds and `/broth-log` browser verification passes**.

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
| Confirm production clean route | Required before release | `/broth-log` is the single manager-facing route. |
| Confirm legacy route redirects | Required before release | `/broth-log-b1`, `/broth-log-b2`, and `/broth-log-b3` redirect to `/broth-log`. |
| Confirm clean-route refresh avoids 404 | Required before release | Hard-refresh each clean URL after deployment. |
| Confirm Google Sheets row counts | Required before release | Expected counts: B1=6, B2=2, B3=15. |
| Confirm no console/network errors | Required before release | Browser verification on the public domain. |
| Confirm mobile/tablet layout | Required before release | Verify usable layouts on real devices or browser emulation. |
| Confirm exports | Required before release | CSV, Excel-compatible export, and Print/PDF. |

## Release Steps

1. Confirm all dashboard changes are committed.
2. Confirm `.github/workflows/deploy-production.yml` is the only deployment workflow.
3. Confirm production-only secrets exist:
   - `PRODUCTION_HOST`
   - `PRODUCTION_USERNAME`
   - `PRODUCTION_PASSWORD`
   - `PRODUCTION_PORT`
   - `PRODUCTION_TARGET_DIR`
4. Confirm `PRODUCTION_TARGET_DIR` points to the production document root; on DreamHost this path must contain `bakudanramen.com`.
5. Create a version tag such as `v2026.07.21-broth-log`.
6. Publish a GitHub Release or push the version tag.
7. Approve the GitHub `production` environment deployment.
8. Verify the public route in a browser:
   - `https://www.bakudanramen.com/broth-log`
9. Confirm the top store selector switches:
   - B1 Bandera
   - B2 Stone Oak
   - B3 La Cantera
10. Hard-refresh each route and confirm no 404.
11. Confirm live row counts:
   - B1: 6
   - B2: 2
   - B3: 15
12. Test filters:
   - Reset filters
   - Min F / Max F
   - Date range
   - Employee
   - Issue
   - Shift
13. Test exports:
   - CSV
   - Excel-compatible `.xls`
   - Print/PDF
14. Verify console/network:
   - No JavaScript errors
   - No failed Google Sheet requests
   - No mixed-content warnings
   - No CORS/CSP errors
   - No missing assets

## Go Gate

Production can be marked **GO** only when:

- Public clean-route refresh returns 200 for all three Broth Log URLs.
- Public row counts match B1=6, B2=2, B3=15.
- HTTPS works without certificate warnings.
- Browser console/network checks are clean.
- Static dashboard controls and exports pass on the public domain.
- No production-facing file contains localhost, development ports, Windows paths, secrets, private config, or removed deployment references.
