# LINK HUB 2.0 Staff Training Audit

Audit Date: 2026-07-04

## Audit Item

Status: PASS  
Severity: P0  
Page: Staff Training  
Page Type: staff_training  
Source File: `staff-training/index.html`, `.htaccess`, `api/index.php`  
API Endpoint: `/api/public/links/staff-training`, `/api/admin/pages/4`  
Database Table: `pages`, `link_sections`, `buttons`  
Test Method: Production API, header check, HTML marker check, screenshots  
Expected Result: Separate Staff URL, no redirect to `/links/`, unlisted/noindex, videos visible only on Staff  
Actual Result: `/staff-training/` returns 200 with no redirect; API returns `visibility=unlisted`, `allow_indexing=0`, `noindex=true`, two YouTube Shorts.  
Evidence: `evidence/link-hub-2/api/staff-public-after-fix.json`, `evidence/link-hub-2/network/staff-training-headers.txt`, `evidence/link-hub-2/network/staff-training-html-markers.txt`, `evidence/link-hub-2/screenshots/staff-live.png`, `evidence/link-hub-2/screenshots/staff-mobile.png`  
Root Cause: Stale slug `staff-training-videos` and old redirect rule conflicted with the required Staff URL.  
Required Fix: Remove redirect, add `/staff-training/` renderer, correct page record, publish.  
Retest Result: PASS

## Current Incident Migration

| Requirement | Result | Evidence |
| --- | --- | --- |
| Training videos found | PASS | User supplied two YouTube Shorts |
| Videos moved to Staff | PASS | `staff-public-after-fix.json` |
| No duplicate | PASS | Dashboard duplicate warnings empty |
| No loss of title/URL/subtitle | PASS | Staff API buttons id 34, 35 |
| Customer page clean | PASS | `production-functional-tests.json` training count 0 |
| Staff page displays videos | PASS | `staff-live.png` |
| Independent publish | PASS | Staff version 3, Customer version 2 |
| Production screenshots | PASS | `evidence/link-hub-2/screenshots/` |

## Staff Content Types

YouTube and external links are verified. PDF, Google Drive, text, heading, image, download and internal resource are supported as generic link/content metadata in source but not all have dedicated production examples; those are marked PARTIAL in the feature matrix.
