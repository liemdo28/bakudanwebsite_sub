# Production Deployment

Generated: 2026-07-21

The only supported deployment target for this project is:

- `https://www.bakudanramen.com`

## Workflow

Production deployment is handled by:

- `.github/workflows/deploy-production.yml`

The workflow runs only when:

- a GitHub Release is published.

Ordinary branch pushes and tag pushes do not deploy. A tag can identify a release commit, but production deployment starts only when the GitHub Release is published.

## Duplicate Deployment Protection

Production deployment intentionally uses one canonical trigger:

```yaml
on:
  release:
    types:
      - published
```

Do not combine `push.tags` and `release.published` triggers unless strict deduplication logic guarantees exactly one deployment. Publishing a Release for a newly created tag can emit both events, which previously caused duplicate production deployment runs.

The workflow also uses production-level concurrency:

```yaml
concurrency:
  group: production-deployment
  cancel-in-progress: false
```

This prevents overlapping production deployments. A second production deployment waits for the first one to finish instead of canceling an in-progress upload.

## Source Verification

Before upload, the workflow reports:

- trigger event;
- Release tag;
- checked-out commit SHA;
- Release tag commit SHA;
- current `origin/main` SHA;
- production environment.

The workflow fails if the checked-out commit does not match the Release tag or if the Release tag does not point at current `origin/main`. It does not print secrets or the unmasked production target path.

To confirm what was deployed, compare the workflow log values:

- `Checked-out commit SHA`
- `Tag commit SHA`
- `Main branch SHA`

All three should match for a normal production release.

## Broth Log Route Gate

The Broth Log dashboard is built as a single-page dashboard. The only tracked HTML file is:

- `broth-log.html`

Do not recreate separate branch files such as `broth-log-b1.html`, `broth-log-b2.html`, or `broth-log-b3.html`.

The only intended manager-facing dashboard address is:

- `/broth-log`

Managers choose B1, B2, or B3 inside the dashboard. Do not promote separate store URLs.

Before controlled production launch, `.htaccess` keeps Broth Log routes blocked:

- `/broth-log`
- `/broth-log.html`
- legacy branch-specific paths such as `/broth-log-b1`, `/broth-log-b2`, and `/broth-log-b3`

Do not remove the temporary Broth Log route block until an approved production launch release.

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

After deployment, verify the public dashboard route:

- `https://www.bakudanramen.com/broth-log`

Required checks:

- Remove the temporary Broth Log route block only as part of the controlled release.
- Clean route refresh returns 200 for `/broth-log`.
- Store selector switches B1, B2, and B3 without requiring separate HTML files.
- Google Sheets data loads.
- Expected row counts are B1=6, B2=2, B3=15.
- No browser console errors.
- No failed Google Sheets requests.
- No mixed-content warnings.
- No missing CSS, JavaScript, font, icon, or image assets.
- Mobile and tablet layouts remain usable.
- CSV, Excel-compatible export, and Print/PDF controls work.

## How To Create A Production Release

1. Confirm `main` contains the exact commit intended for production.
2. Confirm `.github/workflows/deploy-production.yml` uses only `release.published`.
3. Confirm `PRODUCTION_*` secrets are configured in the GitHub `production` environment.
4. Create a version tag pointing at the approved `main` commit.
5. Publish a GitHub Release for that tag.
6. Monitor the single `Deploy production` workflow run triggered by the Release publication.
7. Confirm the source verification step reports matching checked-out, tag, and `origin/main` SHAs.
8. Verify production routes and core pages after deployment.

Pushing a tag alone does not deploy. This is intentional: production deployment requires the explicit act of publishing a GitHub Release.

## Rollback Process

1. Identify the last known-good behavior.
2. Create a rollback commit on `main`, usually by reverting the faulty change.
3. Confirm `origin/main` points at the rollback commit.
4. Create a rollback tag pointing at the current `origin/main` rollback commit.
5. Publish a GitHub Release with clear rollback notes.
6. Monitor the single release-triggered production deployment.
7. Verify the source SHA and production behavior after deployment.

Do not re-enable tag-push deployments for rollback. Rollbacks must use the same published-Release gate as normal production releases.
