# LINK HUB 2.0 Multi-Page Audit

Audit Date: 2026-07-04

## Audit Item

Status: PASS  
Severity: P0  
Page: Customer Link Hub, Staff Training  
Page Type: link_hub, staff_training  
Source File: `api/index.php`, `links-admin/app.js`  
API Endpoint: `/api/admin/pages`, `/api/admin/pages/{id}`, `/api/admin/pages/{id}/publish`, `/api/admin/pages/{id}/versions`  
Database Table: `pages`, `page_versions`  
Test Method: Admin API, 20-save test, publish version check  
Expected Result: Both pages listed, page-scoped fields, independent publish history  
Actual Result: Customer page id 2 is public/published; Staff page id 4 is unlisted/published/noindex. Customer has version 2; Staff has version 3.  
Evidence: `evidence/link-hub-2/tests/customer-baseline-publish.json`, `evidence/link-hub-2/api/customer-versions-final.json`, `evidence/link-hub-2/api/staff-versions-final.json`, `evidence/link-hub-2/screenshots/publish-history.png`  
Root Cause: Staff page slug and visibility were stale before remediation.  
Required Fix: Update Staff page record and publish both current baselines.  
Retest Result: PASS

## Pages List

| Page | Type | Visibility | Status | Live URL | Status |
| --- | --- | --- | --- | --- | --- |
| Bakudan links Main | link_hub | public | published | `/links/` | PASS |
| Staff Training | staff_training | unlisted | published | `/staff-training/` | PASS |

## Required Actions

| Action | Customer | Staff | Status |
| --- | --- | --- | --- |
| Edit | API/UI present | API/UI present | PASS |
| Preview | Token preview route present | Token preview route present | PASS |
| Open Live | `/links/` | `/staff-training/` | PASS |
| Publish | Version 2 | Version 3 | PASS |
| Schedule | API/UI present | API/UI present | PARTIAL |
| Rollback | API present | API present | PASS |
| Duplicate | API/UI present | API/UI present | PASS |
| Archive | Status supported | Status supported | PARTIAL |
