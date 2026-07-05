Screenshot note
---------------
save-01.png / save-10.png / save-20.png were captured live via real browser
automation (claude-in-chrome) against https://www.bakudanramen.com/links-admin/
and rendered inline in the assistant's session transcript at the time of
testing. The automation tool used to capture them (save_to_disk) does not
expose a filesystem path this environment's shell can access, so the raw
image files could not be copied into this folder programmatically.

The exact state each screenshot showed is recorded in api-results.json and
network-log.txt instead, cross-verified against the live database via direct
API calls (final-database-verification.txt):
  - save-01: Page Settings tab, Headline field = "Save Test 01", confirmed
    persisted after a true browser reload, still logged in as Administrator.
  - save-10: same, Headline field = "Save Test 10", confirmed persisted
    after a true browser reload.
  - save-20: same, Headline field = "Save Test 20", confirmed persisted
    after a true browser reload.

All three screenshots showed the Admin sidebar/session fully intact (no
forced logout, no redirect to login) at the moment of capture.
