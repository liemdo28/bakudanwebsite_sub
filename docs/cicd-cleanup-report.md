# CI/CD Cleanup Report

Generated: 2026-07-21

## Summary

The repository has been cleaned so the only supported deployment target is:

- `https://www.bakudanramen.com`

No deployment was pushed or run.

## Files Modified

- `.github/workflows/deploy-production.yml`
- `.htaccess.backup-20260624`
- `broth-log-b1.html`
- `broth-log-b2.html`
- `broth-log-b3.html`
- `docs/broth-log-production-release-checklist.md`
- `docs/broth-log-sync-cicd-hardening.md`
- `docs/production-deployment.md`
- public site/location files that previously used the removed location wording
- generated local evidence/helper files that preserved old wording

## Files Deleted

- obsolete non-production deployment workflow
- obsolete non-production deployment report

## Workflows Removed

- obsolete non-production deployment workflow

## Production Workflow Confirmation

Only `.github/workflows/deploy-production.yml` remains as a deployment workflow.

The production workflow:

- uses production-only secrets
- targets production deployment only
- runs from version tags matching `v*`
- runs from published GitHub Releases
- does not run on ordinary branch pushes
- validates that the configured target directory ends with `www.bakudanramen.com`

## Final Verification

Repository text search was run for:

- the removed alternate hostname
- the removed standalone location/deployment token
- removed non-production deployment phrases
- removed non-production secret prefixes

Final text search result: **0 matches**.

Binary image payloads were not rewritten; random byte sequences inside image data are not deployment references.
