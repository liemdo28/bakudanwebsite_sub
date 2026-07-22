# Broth Log UI Verification

Generated: 2026-07-21

## Verification Scope

Routes:

- `/broth-log`
- `/broth-log-b1`
- `/broth-log-b2`
- `/broth-log-b3`

Architecture:

- `broth-log.html` is the only Broth Log HTML page.
- `broth-log-b1.html`, `broth-log-b2.html`, and `broth-log-b3.html` are intentionally absent and must not be recreated.
- Clean branch routes rewrite internally to `broth-log.html`.
- JavaScript reads the current URL path before query parameters or stored selector state.

Path-driven branch detection:

- `/broth-log-b1` selects B1 The Rim.
- `/broth-log-b2` selects B2 Stone Oak.
- `/broth-log-b3` selects B3 Bandera.
- `/broth-log` opens the shared selector-based dashboard.
- Unsupported Broth Log-looking paths show an unsupported-route message instead of silently loading the wrong branch.

Stores verified:

- B1 The Rim
- B2 Stone Oak
- B3 Bandera

## Automated Checks

| Check | Result |
|---|---|
| JavaScript syntax validation | Pass |
| Static build command | Pass |
| Playwright desktop verification | Pass |
| Playwright tablet verification | Pass |
| Playwright mobile verification | Pass |

## UI Requirements Verified

The verification script checks:

- master-detail layout renders
- journal item selection updates the selected detail panel
- selected journal item is highlighted
- station cards render
- SOP and Current markers render
- desktop/tablet/mobile have no horizontal overflow
- B1/B2/B3 row counts match Google Sheets
- browser console and network are clean
- direct navigation and browser refresh work for `/broth-log-b1`, `/broth-log-b2`, and `/broth-log-b3` when the local server reproduces the intended clean-route rewrites
- branch-specific HTML files are not required

## Visual QA Findings

Issues found during screenshot and live Playwright review:

- zero-value temperature KPIs competed visually with high/critical counts
- the most severe issue was visible but not strong enough for a 3-second manager scan
- safe station cards created repeated green visual noise across long logs
- action-required stations were mixed into the full 19-card list, slowing triage

## Fixes Applied

- Added an `Action Required` strip above station cards for non-safe readings.
- Muted zero-value KPI cards so active warnings/high/critical states carry more visual weight.
- Reduced Safe-card background color strength.
- Increased High/Critical card contrast and border emphasis.
- Preserved the master-detail layout and did not change sync, SOP rules, severity logic, filters, or exports.

## Before / After

Before:

- the detail panel worked but forced managers to scan many visually similar cards
- zero-count KPI cards looked as important as active issue counts
- safe cards created more repeated color than necessary

After:

- the selected log is still shown in a clear master-detail layout
- non-safe readings are summarized immediately above the station list
- critical data stands out sooner while all station cards remain available
- station cards retain Current, Target, Deviation, Trend, Recorded, By, SOP marker, and Current marker

## Results

| Viewport | Width | Horizontal overflow | Clipped checked text | Console/network errors |
|---|---:|---:|---:|---:|
| Desktop | 1440 | No | 0 | 0 |
| Tablet | 900 | No | 0 | 0 |
| Mobile | 390 | No | 0 | 0 |

| Store | Rows | Selected log | Station cards | SOP markers | Current markers |
|---|---:|---:|---:|---:|---:|
| B1 `/broth-log-b1` | 6 | 1 | 19 | 19 | 19 |
| B2 `/broth-log-b2` | 2 | 1 | 19 | 19 | 19 |
| B3 `/broth-log-b3` | 15 | 1 | 19 | 19 | 19 |

## Screenshots

- `work/layout-verification/broth-log-desktop.png`
- `work/layout-verification/broth-log-tablet.png`
- `work/layout-verification/broth-log-mobile.png`

## Remaining Manual Checks

- Real-device touch feel
- Browser print dialog completion
- Final production domain verification after deployment

## Remaining UI Limitations

- Mobile still has a long vertical scroll because each selected log contains 19 station cards plus issue/timeline/compliance sections.
- The long scroll is intentional for now so every station remains inspectable without hiding data.
- Further shortening would require collapsible groups or a default "show action-needed first" mode, which should be a separate product decision.

## Recommendation

Final local visual QA recommendation: **GO for push** after repository owner approves release timing.
