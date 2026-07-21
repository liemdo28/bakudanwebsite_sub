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

## Broth Log Route Gate

The Broth Log production routes are intentionally blocked in `.htaccess` until release approval:

- `/broth-log-b1`
- `/broth-log-b1.html`
- `/broth-log-b2`
- `/broth-log-b2.html`
- `/broth-log-b3`
- `/broth-log-b3.html`

The block is isolated to `^broth-log-b[123](\.html)?/?$`. Removing it later and uncommenting the prepared Broth Log rewrite rules will not affect unrelated site routes such as `/about`, `/menu`, `/blog`, `/go/...`, or existing missing-page redirects.

## Required GitHub Configuration

Create the GitHub `production` environment and configure required reviewers before releasing.

Required production-only secrets:

- `PRODUCTION_HOST`
- `PRODUCTION_USERNAME`
- `PRODUCTION_PASSWORD`
- `PRODUCTION_PORT`
- `PRODUCTION_TARGET_DIR`

Do not use generic deployment secrets for production deployment.

`PRODUCTION_TARGET_DIR` must point to the production document root. On DreamHost this may end with `bakudanramen.com` or `www.bakudanramen.com`; the workflow refuses any other final directory name.

## Release Verification

After deployment, verify these public routes:

- `https://www.bakudanramen.com/broth-log-b1`
- `https://www.bakudanramen.com/broth-log-b2`
- `https://www.bakudanramen.com/broth-log-b3`

Required checks:

- Remove the temporary Broth Log route block only after release approval.
- Clean route refresh returns 200.
- Google Sheets data loads.
- Expected row counts are B1=6, B2=2, B3=15.
- No browser console errors.
- No failed Google Sheets requests.
- No mixed-content warnings.
- No missing CSS, JavaScript, font, icon, or image assets.
- Mobile and tablet layouts remain usable.
- CSV, Excel-compatible export, and Print/PDF controls work.
