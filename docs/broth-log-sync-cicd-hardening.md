# Broth Log Sync And Production Deployment Hardening

Generated: 2026-07-21

## Continuous Google Sheets Synchronization

The Broth Log Dashboard treats the three Google Sheets as the single source of truth. Spreadsheet rows are not copied into the website source, and no deployment is required when spreadsheet data changes.

Implemented synchronization behavior:

| Requirement | Status | Implementation |
|---|---|---|
| Read latest Google Sheets data without deployment | Complete | Browser reads Google Visualization JSONP directly from the three sheet IDs. |
| Configurable refresh intervals | Complete | `SYNC_CONFIG.intervals` in `js/broth-log-dashboard.js` supports `30 sec`, `1 min`, `2 min`, and `5 min`. |
| Configure refresh in one place | Complete | `SYNC_CONFIG` controls interval choices, default interval, cache TTL, and request timeout. |
| Last successful sync time | Complete | Displayed in the sync status bar and KPI card. |
| Current sync status | Complete | Shows syncing, success, warning, or error status. |
| Loading indicator while refreshing | Complete | Syncing state shows a spinner and status text. |
| Error indicator on failure | Complete | Failed or partial syncs keep last good data and show warnings/errors. |
| Automatic retry | Complete | The scheduled interval continues after failures. |
| Keep last good data on temporary failure | Complete | Failed branch refreshes do not clear `recordsByBranch`. |
| Detect new records | Complete | Per-branch record indexes compare stable IDs each sync. |
| Detect updated records | Complete | A per-record revision hash detects field and reading changes. |
| Detect deleted records | Complete | Missing IDs from the previous branch index are counted as deleted. |
| Ignore duplicate records | Complete | Duplicate IDs within a sync are counted and ignored. |
| Validate required fields | Complete | Missing branch, date, time, submitted timestamp, and employee are recorded as validation warnings. |
| Normalize all branch schemas | Complete | B3 `Congelador trasero / Back Freezer` normalizes to canonical `walkInFreezer`. |
| Preserve stable IDs | Complete | Uses `responseId` when present, then branch/date/time/employee/submitted timestamp fallback. |
| Live UI updates | Complete | Successful sync rebuilds records once and all KPIs/tables/charts/analytics rerender from canonical state. |
| Brief cache | Complete | Adapter cache avoids duplicate requests inside the configured short cache TTL. |
| Future backend compatibility | Complete | UI calls a `dataSource` adapter; current adapter is `googleSheetsDataSource`. |

## Production CI/CD

Only production deployment is supported.

| Workflow | Trigger | Secrets | Environment | Purpose |
|---|---|---|---|---|
| `.github/workflows/deploy-production.yml` | Version tags matching `v*` or published GitHub Releases | `PRODUCTION_HOST`, `PRODUCTION_USERNAME`, `PRODUCTION_PASSWORD`, `PRODUCTION_PORT`, `PRODUCTION_TARGET_DIR` | `production` | Deploy to `https://www.bakudanramen.com`. |

Deployment does not run on ordinary branch pushes.

Repository configuration still required:

1. Create the GitHub `production` environment.
2. Add required reviewers to the `production` environment.
3. Add production-only deployment secrets.
4. Remove or rotate legacy generic deployment secrets after confirming the production-only workflow is configured.
