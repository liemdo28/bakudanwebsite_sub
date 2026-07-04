# LINK HUB 2.0 - Feature Matrix

Evidence sources: `evidence/api/live-api-smoke.json`, `evidence/tests/admin-e2e-temp-page.json`, `evidence/tests/browser-route-qa.json`, `evidence/database/db-integrity.json`.

| Feature | UI | API | DB | Public | Error Handling | Test | Status |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Create Page | Yes | Yes | Yes | N/A | Yes | PASS | PASS |
| Create Section | Yes | Yes | Yes | Yes | Yes | PASS | PASS |
| Create Button | Yes | Yes | Yes | Yes | Yes | PASS | PASS |
| External URL | Yes | Yes | Yes | Yes | Yes | PASS | PASS |
| YouTube | Yes | Yes | Yes | Yes | Yes | PASS | PASS |
| Staff Training | Yes | Yes | Yes | Yes | Yes | PASS | PASS |
| Marketing Signup | Yes | Yes | Yes | Yes | Partial | PASS | PASS |
| Preview | Yes | Yes | Yes | N/A | Yes | PASS | PASS |
| Publish | Yes | Yes | Yes | Yes | Yes | PASS | PASS |
| Rollback | Yes | Yes | Yes | Yes | Yes | PASS | PASS |
| Scheduling | Yes | Partial | Partial | Partial | Partial | PARTIAL | PARTIAL |
| Analytics | Yes | Yes | Yes | Yes | Partial | PASS | PASS |
| QR | Yes | Yes | Yes | Yes | Yes | PASS | PASS |
| Link Health | Yes | Yes | Yes | N/A | Yes | PASS | PASS |

## Key Notes

- Core page/section/button CRUD passed on a temporary audit page and was cleaned up after test.
- The 20-save session test passed with 0 forced logout and 0 lost title updates.
- Draft did not appear on public API before publish.
- Publish created versions; rollback restored button snapshot.
- Staff Training is now `page_type=staff_training`, `visibility=staff_only`; unauthenticated public access returns 403 and staff-token access works.
- QR/shortlink admin UI now exists at `#/shortlinks`; create/update/disable/delete plus `/go/{code}` redirect passed.
- Link Health has stored results after the manual checker ran; Toast 403s are classified as `needs_review` instead of false broken.
