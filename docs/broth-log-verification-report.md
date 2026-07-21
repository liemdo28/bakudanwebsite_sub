# Broth Log Dashboard Verification Report

Generated: 2026-07-21

## Verification Method

The dashboard was served locally from `D:\Project\Master\Bakudan\bakudanramen.com-current` at `http://127.0.0.1:8087`.

Route tested in a real Chromium browser via Playwright:

- `http://127.0.0.1:8087/broth-log`

The top store selector was used to switch between B1, B2, and B3.

The Codex in-app browser could not reach the local loopback server, so functional and visual verification was completed in Playwright-launched Chromium. This still exercised the actual rendered HTML, CSS, JavaScript, Google Sheets JSONP requests, controls, downloads, console, network, and responsive viewports.

Raw verification artifacts:

- `work/broth-verification/verification-results.json`
- `work/broth-verification/verification-output.txt`
- `work/broth-verification/b1-desktop.png`
- `work/broth-verification/b1-tablet.png`
- `work/broth-verification/b1-mobile.png`
- `work/broth-verification/b2-desktop.png`
- `work/broth-verification/b2-tablet.png`
- `work/broth-verification/b2-mobile.png`
- `work/broth-verification/b3-desktop.png`
- `work/broth-verification/b3-tablet.png`
- `work/broth-verification/b3-mobile.png`

## Google Sheet Verification

| Branch | Workbook ID | Visible tab | Real sheet rows | Dashboard Total Logs | Duplicate keys | Status |
|---|---|---|---:|---:|---:|---|
| B1 | `1-T9WLdHI1MWp0kX7U2SNPOnc7nDBnrrc0njFxBUKnqo` | `Form Responses 1` | 6 | 6 | 0 | Verified working |
| B2 | `1qk78Spg8GmyP4RCjQYwU8Nm0bXdoyl240iUDcSkK3MQ` | `Form Responses 1` | 2 | 2 | 0 | Verified working |
| B3 | `1odx4Xq94kz50aJBuE2Q-WcZbvXdfeVFOksOeAxn4Kxw` | `Form Responses 1` | 15 | 15 | 0 | Verified working |

Headers were fetched directly from each Google Visualization export and compared against the canonical dashboard mapping.

Observed branch-specific header:

- B3 uses `Congelador trasero / Back Freezer`.
- The dashboard maps it into canonical `Walk-In Freezer`.
- Verified in the B3 detail drawer with back-freezer values such as `-7F`, `-10F`, and `-20F`.

## Data Rendering Verification

| Feature | B1 | B2 | B3 | Notes |
|---|---|---|---|---|
| Correct route loads | Verified working | Verified working | Verified working | Clean local routes matched intended deployed paths. |
| Google Sheet data loads | Verified working | Verified working | Verified working | No visible sync errors. |
| Total row counts | Verified working | Verified working | Verified working | 6, 2, 15. |
| Date parsing | Verified working | Verified working | Verified working | Business dates and submitted timestamps render correctly. |
| Employee names | Verified working | Verified working | Verified working | Examples: Omar, Yenci, Sol, Sol Angie, Amayrani. |
| Temperature values | Verified working | Verified working | Verified working | Values render in journal and detail drawer. |
| Branch mapping | Verified working | Verified working | Verified working | B1/B2/B3 store codes render correctly. |
| B3 back-freezer mapping | Not applicable | Not applicable | Verified working | Bilingual header normalizes to Walk-In Freezer. |
| Duplicate handling | Verified working | Verified working | Verified working | No duplicate keys found in current data. |
| Issue detection | Verified working | Verified working | Verified working | Cold/hot/freezer/fryer/boiler range issues and missing readings surfaced. |
| Compliance calculations | Verified working | Verified working | Verified working | Dashboard compliance: B1 `89%`, B2 `79%`, B3 `53%`. |

## KPI Results

| Branch | Total Logs | Open Issues | Critical Alerts | Compliance | Average Temp | Active Employees |
|---|---:|---:|---:|---:|---:|---:|
| B1 | 6 | 12 | 3 | 89% | 102F | 2 |
| B2 | 2 | 8 | 0 | 79% | 101F | 2 |
| B3 | 15 | 138 | 18 | 53% | 98F | 3 |

## Section Verification

| Section | Status | Notes |
|---|---|---|
| Home KPIs | Verified working | KPI cards populated from synced records. |
| Daily Journal | Verified working | Table rows render and match branch/filter state. |
| Log detail drawer | Verified working | Quick View opens full log, station readings, issues, notes, and actions. |
| Temperature timeline | Verified working | Line/area charts render from record metrics. |
| Heatmap | Verified working | Station/day compliance heat cells render. |
| Issues | Verified working | Issue list includes type, station, severity, owner, and status. |
| Employee analytics | Verified working | Leaderboard and scores render by employee. |
| Daily analytics | Verified working | Daily rollups render in table. |
| Weekly analytics | Verified working | Weekly rollups render in table. |
| Monthly analytics | Verified working | Monthly executive rollup renders. |
| Branch comparison | Verified working | Branch table and ranking render; all-branch mode loads all sheets. |
| Compliance | Verified working | Per-station compliance bars and recommendations render. |
| Notifications | Verified working | Open issue notification list renders. |

## Control Verification

| Control | Status | Notes |
|---|---|---|
| Global search | Verified working | Search filters records by employee/text. |
| Branch filter | Verified working | `All branches` triggers all three sheet syncs and mixed B1/B2/B3 journal rows. |
| Date filter | Verified working | Date range select updates filtered state. |
| Employee filter | Verified working | Employee select filters to chosen employee. |
| Issue filter | Verified working | Open, critical, and closed status options update filtered state. |
| Shift filter | Verified working | Shift select filters records. |
| Temperature range | Verified working | Numeric `Min F` and `Max F` inputs added and verified. |
| Reset filters | Verified working | Reset button added and verified. |
| Dark/light mode | Verified working | Theme toggles and persists in localStorage. |
| Auto refresh | Verified working | 1-5 minute interval selector updates persisted refresh interval. |
| CSV export | Verified working | Browser download produced `.csv`. |
| Excel-compatible export | Verified working | Browser download produced `.xls`. |
| Print/PDF | Verified working | Print action invoked; browser print-to-PDF remains user/browser controlled. |

## Responsive And Visual Verification

| Viewport | Size | B1 | B2 | B3 | Notes |
|---|---:|---|---|---|---|
| Desktop | 1440 x 1000 | Verified working | Verified working | Verified working | No body-level horizontal overflow. |
| Tablet | 900 x 900 | Verified working | Verified working | Verified working | Layout reflows to two-column sections. |
| Mobile | 390 x 844 | Verified working | Verified working | Verified working | No body-level horizontal overflow; journal table scrolls inside its container. |

Representative screenshots were visually inspected. The UI is readable, controls are reachable, and no overlapping text or broken layout was observed. On mobile, journal date values wrap within table cells because the table is dense, but they remain readable and contained.

## Console And Network Verification

| Branch | JavaScript errors | Failed Google Sheet requests | CORS issues | Invalid JSONP | Missing assets | Broken local route |
|---|---:|---:|---:|---:|---:|---:|
| B1 | 0 | 0 | 0 | 0 | 0 | 0 |
| B2 | 0 | 0 | 0 | 0 | 0 | 0 |
| B3 | 0 | 0 | 0 | 0 | 0 | 0 |

## Bugs Found And Fixed

| Bug | Status | Fix |
|---|---|---|
| Reset filters control was missing. | Fixed | Added `Reset filters` button and reset action. |
| Temperature range control was incomplete. | Fixed | Added numeric `Min F` and `Max F` filters. |
| Local verification needed clean route emulation. | Fixed for testing | Added `work/local-broth-server.js` for local route verification only. |

## Production Readiness

Status: Partially ready for production deployment.

The static dashboard is functionally and visually verified for the current public Google Sheets and can be deployed as a static-site feature. Remaining limitations are documented in `docs/broth-log-known-limitations.md`, especially around auth/permissions, scheduled notifications, and true backend audit logging.
