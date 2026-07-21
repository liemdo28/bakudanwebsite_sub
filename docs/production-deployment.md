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

## Broth Log Route

The Broth Log dashboard has one manager-facing address:

- `/broth-log`

Managers choose B1, B2, or B3 from the store selector at the top of the dashboard. The removed branch-specific routes are not deployed; `/broth-log.html` redirects to `/broth-log` so there is only one visible address to remember.

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

Required checks:

- Clean route refresh returns 200.
- Store selector switches B1, B2, and B3 without needing separate links.
- Google Sheets data loads.
- Expected row counts are B1=6, B2=2, B3=15.
- No browser console errors.
- No failed Google Sheets requests.
- No mixed-content warnings.
- No missing CSS, JavaScript, font, icon, or image assets.
- Mobile and tablet layouts remain usable.
- CSV, Excel-compatible export, and Print/PDF controls work.
