# LINK HUB 2.0 Gap List

Audit Date: 2026-07-04

## Hard Blockers

None remaining in live production behavior.

## P1

None remaining.

## P2

| Gap | Status | Evidence | Next Action |
| --- | --- | --- | --- |
| Full scheduling edge-case matrix not exhaustively tested | PARTIAL | Source/API support exists | Test weekday, overnight, DST cases before expanding scheduling use |
| Bulk actions are partial | PARTIAL | Feature matrix | Build confirmation/impact workflow later |
| Dedicated content-type renderers for PDF/text/image/download are partial | PARTIAL | Staff audit | Add richer renderers after core page isolation is stable |

## P3

| Gap | Status | Evidence | Next Action |
| --- | --- | --- | --- |
| Admin version history UI is not a full screen | PARTIAL | Versions API screenshot generated | Add UI history table later |
| Some button labels remain generic | PARTIAL | Admin screenshots | Add context-specific copy in Admin polish pass |
| Missing-location warning not tested with live inactive data | PARTIAL | Marketing audit | Test with a temporary inactive/production location |

## Closed Gaps

| Gap | Closure Evidence |
| --- | --- |
| Staff page redirect to `/links/` | `/staff-training/` headers show 200, no redirect |
| Staff page not found | `staff-public-after-fix.json` |
| Staff noindex missing | API `noindex=true` and HTML robots noindex |
| Staff videos on Customer page | Functional test shows customer training video count 0 |
| Staff videos missing from Staff page | Staff public API returns both supplied YouTube Shorts |
| Admin save reliability | 20 consecutive saves, 0 failures |
| Dashboard warnings | Final dashboard warnings empty after baseline publish |
| API/Admin source deploy pending | `evidence/link-hub-2/deployment/final-admin-api-deploy.json`, `admin-boot-fix-deploy.json`, `admin-icon-boot-fix-deploy.json` |
| Admin screenshot recut pending | `evidence/link-hub-2/screenshots/OFFICIAL_22_SCREENSHOTS_MANIFEST.json` status COMPLETE |
