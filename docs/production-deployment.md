# Production Deployment

Generated: 2026-07-21

The only supported deployment target for this project is:

- `https://www.bakudanramen.com`

## Workflow

Production deployment is handled by:

- `.github/workflows/deploy-production.yml`

The workflow runs only when:

- a version tag matching `v*` is pushed, or
- a GitHub Release is published.

Ordinary branch pushes do not deploy.

## Broth Log Single-Page Route Gate

The Broth Log dashboard is a single-page, path-driven dashboard. The only tracked HTML file is:

- `broth-log.html`

Do not recreate separate branch files such as `broth-log-b1.html`, `broth-log-b2.html`, or `broth-log-b3.html`.

The clean branch routes are prepared as internal rewrites to the shared page:

- `/broth-log-b1` -> `/broth-log.html`
- `/broth-log-b2` -> `/broth-log.html`
- `/broth-log-b3` -> `/broth-log.html`
- `/broth-log` -> `/broth-log.html`

JavaScript reads the current path and selects the matching store and workbook. `/broth-log` remains a selector-friendly entry point; `/broth-log-b1`, `/broth-log-b2`, and `/broth-log-b3` deep-link directly to their branch.

Before controlled production release, `.htaccess` keeps Broth Log routes blocked:

- `/broth-log`
- `/broth-log.html`
- branch-specific clean routes such as `/broth-log-b1`, `/broth-log-b2`, and `/broth-log-b3`

The internal rewrite rules are intentionally kept underneath the temporary block. Removing only the temporary block during a controlled release exposes all clean Broth Log routes without requiring new HTML files.

## Required GitHub Configuration

Create the GitHub `production` environment and configure required reviewers before releasing.

Required production-only secrets:

- `PRODUCTION_HOST`
- `PRODUCTION_USERNAME`
- `PRODUCTION_PASSWORD`
- `PRODUCTION_PORT`
- `PRODUCTION_TARGET_DIR`

Do not use generic deployment secrets for production deployment.

`PRODUCTION_TARGET_DIR` must point to the production document root. On DreamHost this path must contain `bakudanramen.com`; the workflow refuses empty targets and paths that look like non-production targets.

## Release Verification

After deployment, verify these public routes:

- `https://www.bakudanramen.com/broth-log`
- `https://www.bakudanramen.com/broth-log-b1`
- `https://www.bakudanramen.com/broth-log-b2`
- `https://www.bakudanramen.com/broth-log-b3`

Required checks:

- Remove the temporary Broth Log route block only as part of the controlled release.
- Clean route refresh returns 200 for each Broth Log route.
- `/broth-log-b1`, `/broth-log-b2`, and `/broth-log-b3` all serve the shared `broth-log.html` page.
- Store selector switches B1, B2, and B3 without requiring separate HTML files.
- Google Sheets data loads.
- Expected row counts are B1=6, B2=2, B3=15.
- No browser console errors.
- No failed Google Sheets requests.
- No mixed-content warnings.
- No missing CSS, JavaScript, font, icon, or image assets.
- Mobile and tablet layouts remain usable.
- CSV, Excel-compatible export, and Print/PDF controls work.
