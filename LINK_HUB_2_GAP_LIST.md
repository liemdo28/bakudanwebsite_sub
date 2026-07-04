# LINK HUB 2.0 - Gap List

## P0 Issues

None found in this audit pass.

## P1 Issues

None remaining after fix pass.

Fixed evidence:
- Deploy scripts now require `BAKUDAN_SFTP_PASS` instead of storing the password literal; syntax checks passed.
- Staff Training is `page_type=staff_training`, `visibility=staff_only`; public no-token access returns 403 and staff-token access returns 200.
- Production pages 2 and 4 now have version 1 baselines.
- Restore test against a local staging copy passed `PRAGMA integrity_check=ok`.

Evidence: `evidence/tests/fix-all-live-actions.json`, `evidence/tests/fix-all-current-state.json`, `evidence/database/restore-test.json`

## P2 Issues

1. Buttons without section exist.
   - Status: PARTIAL
   - Evidence: `evidence/database/db-integrity.json`
   - Required fix: Add an explicit default/social section or document loose-button behavior.

2. Scheduling lacks day-of-week recurring rule coverage.
   - Status: PARTIAL
   - Evidence: `api/index.php`, `links-admin/app.js`
   - Required fix: Add America/Chicago timezone handling and recurring schedule model/tests if required.

3. Advanced admin workflows are incomplete or untested.
   - Status: PARTIAL
   - Evidence: `LINK_HUB_2_ADMIN_TEST_RESULTS.md`
   - Required fix: Add tests for page duplicate, section reorder/duplicate/move/copy, button bulk/copy/move page.

4. Host-level cron for link health is not configured.
   - Status: PARTIAL
   - Evidence: `evidence/tests/fix-all-current-state.json`
   - Required fix: Add DreamHost cron if automatic checks are required.

Fixed P2 evidence:
- QR/shortlinks admin UI and `/go/{code}` redirect lifecycle passed: `evidence/tests/go-shortlink-regression.json`.
- Link Health has 14 stored results after rerun: `evidence/tests/fix-all-current-state.json`.

## P3 Issues

1. In-app Browser plugin failed, requiring fallback Chrome screenshots.
   - Status: PARTIAL
   - Evidence: `evidence/console/browser-plugin-fallback.json`
   - Required fix: Repair Browser plugin runtime for smoother future QA.

2. Audit logs lack request IDs/IP/user-agent.
   - Status: PARTIAL
   - Evidence: `evidence/api/audit-log-endpoint.json`
   - Required fix: Add request reference IDs for incident triage.

## Hard Blockers

None confirmed.

Spec hard blockers checked:
- Admin/public use same API and DB: PASS.
- Publish/rollback API operates: PASS on temp page.
- Public duplicate active buttons: PASS, none found.
- External URL converted to local link: PASS, not reproduced.
- Admin logout during save: PASS, 20-save test passed.
- Database backup exists: PASS.
- Production source identified: PASS.
- Draft public exposure: PASS, draft temp page returned 404.
