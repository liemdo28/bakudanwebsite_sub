# Broth Log Staging Deployment Report

Generated: 2026-07-21

## Summary

Result: **NO-GO for production**.

The dashboard was committed and pushed to the existing GitHub Actions workflow named `Deploy rim.bakudanramen.com`. The workflow succeeded after one transient SCP timeout, but post-deployment checks showed that the workflow target is currently publishing to `www.bakudanramen.com`, which the project README identifies as production.

Because the request explicitly said not to deploy directly to production, a follow-up safety commit was deployed immediately to block both clean and `.html` Broth Log routes on production through `.htaccess`.

## Deployment Runs

| Step | Run ID | Result | Notes |
|---|---:|---|---|
| Initial staging deploy attempt | `29822146779` | Failed first attempt, succeeded on rerun | First SCP failed with `i/o timeout`; rerun completed successfully. |
| Production route safety block | `29822467262` | Succeeded | Blocks `/broth-log-b1`, `/broth-log-b2`, `/broth-log-b3`, and matching `.html` routes on production. |

## Staging URL Status

Intended staging domain from workflow name:

- `https://rim.bakudanramen.com`

Actual status:

| Check | Result |
|---|---|
| Public DNS for `rim.bakudanramen.com` | Not working. DNS resolution failed locally. |
| Forced HTTP host mapping to DreamHost public web IP | Reached an Apache vhost, but Broth Log clean routes returned 404. |
| Forced HTTPS host mapping | Not working. TLS certificate chain was not trusted. |
| Workflow-published files appeared on `www.bakudanramen.com` | Yes. This indicates the workflow target is not a safe staging-only path. |

## Route Verification

| Route | Expected row count | Staging result | Production safety result |
|---|---:|---|---|
| `/broth-log-b1` | 6 | Not verified on staging; staging domain/DNS invalid | 404 after safety block |
| `/broth-log-b2` | 2 | Not verified on staging; staging domain/DNS invalid | 404 after safety block |
| `/broth-log-b3` | 15 | Not verified on staging; staging domain/DNS invalid | 404 after safety block |

Production safety checks after the block:

- `https://www.bakudanramen.com/broth-log-b1` -> 404
- `https://www.bakudanramen.com/broth-log-b1.html` -> 404
- `https://www.bakudanramen.com/broth-log-b2` -> 404
- `https://www.bakudanramen.com/broth-log-b3.html` -> 404

## Hosted Verification Matrix

| Requirement | Status | Evidence |
|---|---|---|
| Deploy current verified static build to staging | Not working | Existing workflow does not currently provide a reachable staging domain. |
| Clean URL routing on staging | Not working | Forced-host `rim.bakudanramen.com/broth-log-b*` returned 404. |
| Google Sheets data loads on staging | Not testable | No reachable working staging dashboard route. |
| HTTPS does not block requests | Not working | `rim.bakudanramen.com` HTTPS failed certificate trust when forced to the available web IP. |
| Mixed-content warnings | Not testable | No reachable working staging HTTPS route. |
| CORS/CSP errors | Not testable | No reachable working staging dashboard route. |
| Missing CSS/JS/fonts/assets | Not testable on staging | Assets were seen on production before safety block, confirming the workflow upload occurred, but not on staging. |
| Refreshing clean route avoids 404 | Not working | Clean routes returned 404 on the staging vhost check. |
| Back/forward navigation | Not testable | No reachable working staging dashboard route. |
| Mobile/tablet layouts | Not testable on staging | Previously verified locally in Chromium; not verified on hosted staging. |
| Filters and exports on staging | Not testable | No reachable working staging dashboard route. |
| No local-only paths in production-facing dashboard files | Verified working | `broth-log-b*.html`, `css/broth-log.css`, `js/broth-log-dashboard.js`, `.htaccess`, and workflow were scanned for localhost, development ports, Windows paths, secrets, and service-account markers. |
| Cache busting | Partially working | Dashboard HTML references `css/broth-log.css?v=20260721-staging-1` and `js/broth-log-dashboard.js?v=20260721-staging-1`. Hosted HTML cache remains `max-age=600`. |

## Bugs And Deployment Issues Found

| Issue | Severity | Status | Fix/Action |
|---|---|---|---|
| Existing staging workflow target appears to publish to production `www.bakudanramen.com`. | Critical | Mitigated | Deployed `.htaccess` block so Broth Log production routes return 404. |
| `rim.bakudanramen.com` public DNS does not resolve. | Critical | Not fixed | Requires DNS/hosting configuration outside static-site code. |
| `rim.bakudanramen.com` HTTPS certificate is not trusted when forced to the available web IP. | Critical | Not fixed | Requires TLS certificate/hosting configuration. |
| Forced-host staging clean routes returned 404. | Critical | Not fixed | Requires a real staging document root and `.htaccess` deployment target. |
| First SCP attempt timed out. | Medium | Resolved on rerun | GitHub Actions rerun succeeded. |

## Fixes Applied

1. Added cache-busted dashboard asset references:
   - `css/broth-log.css?v=20260721-staging-1`
   - `js/broth-log-dashboard.js?v=20260721-staging-1`
2. Updated the staging workflow source list to include:
   - `broth-log-b1.html`
   - `broth-log-b2.html`
   - `broth-log-b3.html`
3. After discovering the workflow target exposed files on production, deployed an `.htaccess` block:
   - `^broth-log-b[123](\.html)?/?$` -> 404

## Row Counts

Expected from live Google Sheets and previously verified locally:

| Branch | Expected rows | Hosted staging observed |
|---|---:|---|
| B1 | 6 | Not testable |
| B2 | 2 | Not testable |
| B3 | 15 | Not testable |

## Recommendation

Final recommendation: **NO-GO**.

Do not deploy Broth Log Dashboard to production until all of the following are true:

1. `rim.bakudanramen.com` resolves publicly.
2. `https://rim.bakudanramen.com` has a trusted TLS certificate.
3. The GitHub Actions target directory is confirmed to be staging-only, not `www.bakudanramen.com`.
4. Clean refresh of `/broth-log-b1`, `/broth-log-b2`, and `/broth-log-b3` returns 200 on staging.
5. Browser verification on the real staging domain confirms row counts B1=6, B2=2, B3=15.
