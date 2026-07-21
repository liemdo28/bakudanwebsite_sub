# Broth Log Sync And CI/CD Hardening

Generated: 2026-07-21

## Continuous Google Sheets Synchronization

The dashboard still treats the three Google Sheets as the single source of truth. No spreadsheet data is stored in the website source.

Implemented synchronization behavior:

| Requirement | Status | Implementation |
|---|---|---|
| Read latest Google Sheets data without deployment | Complete | Browser reads Google Visualization JSONP directly from the three sheet IDs. |
| Configurable refresh intervals | Complete | `SYNC_CONFIG.intervals` in `js/broth-log-dashboard.js` supports `30 sec`, `1 min`, `2 min`, and `5 min`. |
| Configure refresh in one place | Complete | `SYNC_CONFIG` controls interval choices, default interval, cache TTL, and request timeout. |
| Last successful sync time | Complete | Displayed in the sync status bar and KPI card. |
| Current sync status | Complete | Shows syncing, success, warning, or error status. |
| Loading indicator while refreshing | Complete | Syncing state shows a spinner and status text. |
| Error indicator on failure | Complete | Failed/partial syncs keep last good data and show warnings/errors. |
| Automatic retry | Complete | The scheduled interval continues after failures. |
| Keep last good data on temporary failure | Complete | Failed branch refreshes do not clear `recordsByBranch`. |
| Detect new records | Complete | Per-branch record indexes compare stable IDs each sync. |
| Detect updated records | Complete | A per-record revision hash detects field/reading changes. |
| Detect deleted records | Complete | Missing IDs from the previous branch index are counted as deleted. |
| Ignore duplicate records | Complete | Duplicate IDs within a sync are counted and ignored. |
| Validate required fields | Complete | Missing branch, date, time, submitted timestamp, and employee are recorded as validation warnings. |
| Normalize all branch schemas | Complete | B3 `Congelador trasero / Back Freezer` normalizes to canonical `walkInFreezer`. |
| Preserve stable IDs | Complete | Uses `responseId` when present, then branch/date/time/employee/submitted timestamp fallback. |
| Live UI updates | Complete | Successful sync rebuilds records once and all KPIs/tables/charts/analytics rerender from canonical state. |
| Brief cache | Complete | Adapter cache avoids duplicate requests inside the configured short cache TTL. |
| Future backend compatibility | Complete | UI calls a `dataSource` adapter; current adapter is `googleSheetsDataSource`. |

Notes:

- True incremental Google Sheets API deltas are not available from the public JSONP endpoint. The static implementation performs cache-aware polling and compares canonical records after each fetch.
- The adapter can later be swapped for Google Sheets API, database, REST API, Firebase, or Supabase without changing UI analytics modules.

## Local Browser Verification After Sync Changes

Verified locally in Chromium through the static route server after the sync refactor:

| Branch | Live rows | Dashboard rows | Auto refresh | Console/network failures |
|---|---:|---:|---|---:|
| B1 | 6 | 6 | Verified working | 0 |
| B2 | 2 | 2 | Verified working | 0 |
| B3 | 15 | 15 | Verified working | 0 |

## CI/CD Audit

Previous state:

- Only one workflow existed: `.github/workflows/deploy.yml`.
- It was named `Deploy rim.bakudanramen.com`.
- It ran on every push to `main`.
- It used generic secrets: `HOST`, `USERNAME`, `PASSWORD`, `PORT`, `TARGET_DIR`.
- Post-deploy checks showed it published to `www.bakudanramen.com`, not a verified staging domain.

New workflow structure:

| Workflow | Trigger | Secrets | Environment | Purpose |
|---|---|---|---|---|
| `.github/workflows/deploy-staging.yml` | `push` to `main` and manual `workflow_dispatch` | `STAGING_HOST`, `STAGING_USERNAME`, `STAGING_PASSWORD`, `STAGING_PORT`, `STAGING_TARGET_DIR` | `staging` | Deploy to staging only. |
| `.github/workflows/deploy-production.yml` | Published GitHub release only | `PRODUCTION_HOST`, `PRODUCTION_USERNAME`, `PRODUCTION_PASSWORD`, `PRODUCTION_PORT`, `PRODUCTION_TARGET_DIR` | `production` | Deploy production only from `v*` releases. |

Safety checks added:

- Staging refuses a target that looks like the production `/bakudanramen.com` document root.
- Production refuses a target that does not look like the expected `/bakudanramen.com` document root.
- Production no longer deploys on push to `main`.
- Production deployment references the GitHub `production` environment.

Repository configuration still required:

1. Add GitHub environment `staging`.
2. Add GitHub environment `production`.
3. Add required reviewers to the `production` environment.
4. Move existing generic secrets into the correct environment-scoped names.
5. Remove or rotate old generic secrets after confirming the new workflows work.

Current GitHub API check:

- Existing environments: `0`
- Repository variables: none observed

This means the workflow files are hardened, but GitHub Environment approval is not active until the repository environments are configured in GitHub settings.
