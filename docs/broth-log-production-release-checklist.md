# Broth Log Production Release Checklist

Generated: 2026-07-21

Current release decision: **NO-GO**.

## CI/CD Gate

The previous generic workflow has been replaced in source by:

- `.github/workflows/deploy-staging.yml`
- `.github/workflows/deploy-production.yml`

Production deployment is now release-only in source, but repository admins must still configure the GitHub `production` environment with required reviewers before approval gating is actually enforced.

## Required Before Production

| Item | Status | Notes |
|---|---|---|
| Confirm real staging URL | Not complete | `rim.bakudanramen.com` does not resolve publicly. |
| Confirm staging-only deploy target | Not complete | Existing workflow named for staging exposed files on `www.bakudanramen.com`. |
| Configure `STAGING_*` secrets | Not complete | Required for the new staging workflow. |
| Configure `PRODUCTION_*` secrets | Not complete | Required for the new production workflow. |
| Configure GitHub `production` environment approval | Not complete | GitHub API showed no environments yet. |
| Confirm staging HTTPS | Not complete | Forced-host HTTPS check failed certificate trust. |
| Confirm staging clean routes | Not complete | `/broth-log-b1`, `/broth-log-b2`, `/broth-log-b3` were not reachable on staging. |
| Confirm clean-route refresh avoids 404 | Not complete | Must be verified on real staging domain. |
| Confirm Google Sheets row counts on staging | Not complete | Required counts: B1=6, B2=2, B3=15. |
| Confirm no console/network errors on staging | Not complete | Must be verified in browser on real staging domain. |
| Confirm mobile/tablet layout on staging | Not complete | Must be verified on real staging domain. |
| Confirm exports on staging | Not complete | CSV, Excel-compatible export, and Print/PDF must be retested after staging works. |
| Confirm production routes blocked until GO | Complete | Production Broth Log clean and `.html` routes return 404 after safety block. |

## Production Release Steps After Staging Is Fixed

1. Fix DNS so `rim.bakudanramen.com` resolves publicly.
2. Install/verify a trusted TLS certificate for `https://rim.bakudanramen.com`.
3. Confirm the GitHub Actions `STAGING_TARGET_DIR` secret points to the staging document root, not production.
4. Deploy the dashboard to staging again.
5. Verify these staging routes in a browser:
   - `/broth-log-b1`
   - `/broth-log-b2`
   - `/broth-log-b3`
6. Hard-refresh each staging route and confirm no stale HTML/CSS/JS.
7. Confirm live row counts:
   - B1: 6
   - B2: 2
   - B3: 15
8. Test filters:
   - Reset filters
   - Min F / Max F
   - Date range
   - Employee
   - Issue
   - Shift
9. Test exports:
   - CSV
   - Excel-compatible `.xls`
   - Print/PDF
10. Verify console/network:
   - No JavaScript errors
   - No failed Google Sheet requests
   - No mixed-content warnings
   - No CORS/CSP errors
   - No missing assets
11. Verify responsive layouts:
   - Desktop
   - Tablet
   - Mobile
12. Only after every staging item is verified, remove the production `.htaccess` block and restore clean Broth Log rewrites:
   - `^broth-log-b1/?$  /broth-log-b1.html`
   - `^broth-log-b2/?$  /broth-log-b2.html`
   - `^broth-log-b3/?$  /broth-log-b3.html`
13. Deploy to production.
14. Immediately verify production clean-route refresh for all three routes.

## Go/No-Go Gate

Production can be marked **GO** only when:

- Staging route refresh returns 200 for all three clean URLs.
- Staging row counts match B1=6, B2=2, B3=15.
- HTTPS works without certificate warnings.
- Browser console/network checks are clean.
- Static dashboard controls and exports pass on staging.
- No production-facing file contains localhost, development ports, Windows paths, secrets, or private config.

As of this report, the release remains **NO-GO**.
