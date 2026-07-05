# LINK HUB 2.0 Page Isolation Results

Audit Date: 2026-07-04

## Audit Item

Status: PASS  
Severity: P0  
Page: Customer Link Hub, Staff Training  
Page Type: link_hub, staff_training  
Source File: `api/index.php`  
API Endpoint: `/api/public/links/bakudan-links-main`, `/api/public/links/staff-training`, `/api/admin/pages/{id}/publish`  
Database Table: `pages`, `link_sections`, `buttons`, `page_versions`  
Test Method: Production API assertions, Admin publish, screenshots  
Expected Result: Staff content does not appear on Customer; customer content does not appear on Staff; publish histories independent  
Actual Result: Staff API has two YouTube Shorts. Customer API has zero matching staff video URLs. Staff API has no Order/Rewards/Instagram/Facebook/Email Club content. Customer version 2 and Staff version 3 are independent.  
Evidence: `evidence/link-hub-2/tests/production-functional-tests.json`, `evidence/link-hub-2/tests/post-20-save-staff-publish.json`, `evidence/link-hub-2/tests/customer-baseline-publish.json`, `evidence/link-hub-2/api/customer-versions-final.json`, `evidence/link-hub-2/api/staff-versions-final.json`  
Root Cause: Staff page was already page-scoped, but stale slug/visibility prevented direct Staff public proof.  
Required Fix: Correct Staff page record, publish Staff, publish Customer baseline.  
Retest Result: PASS

## Isolation Tests

| Test | Expected | Actual | Status |
| --- | --- | --- | --- |
| A: Staff YouTube videos | Visible on Staff, absent from Customer | Staff has two Shorts; Customer has 0 matching URLs | PASS |
| B: Customer rewards/order | Visible on Customer, absent from Staff | Customer has order/rewards links; Staff has 0 customer labels | PASS |
| C: Publish Staff only | Staff version increments, Customer version unchanged | Staff version 3 after Staff publish | PASS |
| D: Publish Customer baseline | Customer version increments, Staff unchanged | Customer version 2; Staff remains version 3 | PASS |

Rollback API exists and was previously tested in the earlier Link Hub evidence set. A destructive rollback was not re-run after final baseline publish because current production state is clean.
