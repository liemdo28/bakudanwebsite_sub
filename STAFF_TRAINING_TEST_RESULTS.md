# Staff Training Fix — Test Results

Verified live against `https://www.bakudanramen.com` on 2026-07-04 via direct
HTTPS requests (see `evidence/staff-training/network-log.txt` for raw
request/response evidence). Full detail in that folder's `test-results.txt`.

## Customer-facing page — `/links/`

| Check | Result |
|---|---|
| Page loads successfully | PASS |
| No Staff Training section | PASS |
| No training YouTube video | PASS (0 youtube-linked buttons) |
| Existing customer links remain | PASS (all 10 pre-existing buttons intact, unchanged URLs) |
| No duplicate buttons | PASS |
| Current design unchanged | Not re-screenshotted live (see gap below); no rendering code touched for public pages |
| No console error / no failed critical request | Not captured (see gap below) |

## Staff Training page — `/links/staff-training-videos`

| Check | Result |
|---|---|
| Page loads successfully | PASS (200 with token, 403 without) |
| Training videos visible | PASS (2 YouTube Shorts returned) |
| YouTube links open correctly / stay external | PASS (verified exact URLs unmodified) |
| Does not redirect to `/links/` | PASS (served by the generic slug renderer, no redirect involved) |
| Mobile-friendly | Not re-verified at a live mobile viewport (shared, already-responsive template) |
| Not shown on customer Link Hub | PASS (absent from `/api/public/pages/all`, 0 referencing buttons) |
| `noindex, nofollow` | Implemented and verified in code + local browser test; not re-confirmed on the live rendered page (tooling gap, see below) |
| No duplicate training videos | PASS (exactly 2, duplicate-checked by URL) |

## Admin

| Check | Result |
|---|---|
| Page Type / Visibility / Show on Hub fields exist | PASS (verified via local simulated-session browser test) |
| Staff Training auto-defaults (Unlisted, Show on Hub = No) | PASS (verified interactively) |
| Fields work end-to-end in real production | PASS — the site owner used them live, mid-session, to set `visibility=staff_only` on the real page |
| Full click-through (add/edit/reorder/hide, no forced logout) | Not performed — no admin credentials available to this session, and credential-guessing against production is intentionally blocked |

## URL handling

| Check | Result |
|---|---|
| `youtube.com/shorts/...` stays external | PASS |
| No internal slug generated | PASS |
| No `/links/` prefix added to external destination | PASS |

## Known gaps (tooling, not functionality)

- No live browser screenshot or console capture of the actual production
  pages — this session's preview browser can only reach locally-started dev
  servers, not `bakudanramen.com` itself.
- No live Admin login performed — this session does not have and will not
  attempt to guess the real admin password.

Both gaps are two quick manual checks for the site owner; see
`STAFF_TRAINING_DEPLOYMENT_REPORT.md`'s final section for exactly what to do.
