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

## Required GitHub Configuration

Create the GitHub `production` environment and configure required reviewers before releasing.

Required production-only secrets:

- `PRODUCTION_HOST`
- `PRODUCTION_USERNAME`
- `PRODUCTION_PASSWORD`
- `PRODUCTION_PORT`
- `PRODUCTION_TARGET_DIR`

Do not use generic deployment secrets for production deployment.

`PRODUCTION_TARGET_DIR` must point to the `www.bakudanramen.com` document root. The workflow refuses any target directory that does not end with `www.bakudanramen.com`.

## Release Verification

After deployment, verify these public routes:

- `https://www.bakudanramen.com/broth-log-b1`
- `https://www.bakudanramen.com/broth-log-b2`
- `https://www.bakudanramen.com/broth-log-b3`

Required checks:

- Clean route refresh returns 200.
- Google Sheets data loads.
- Expected row counts are B1=6, B2=2, B3=15.
- No browser console errors.
- No failed Google Sheets requests.
- No mixed-content warnings.
- No missing CSS, JavaScript, font, icon, or image assets.
- Mobile and tablet layouts remain usable.
- CSV, Excel-compatible export, and Print/PDF controls work.
