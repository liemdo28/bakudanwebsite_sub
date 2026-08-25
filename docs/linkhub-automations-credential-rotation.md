# Link Hub Automations — Credential Rotation & Deployment Runbook

**Status: NOT YET EXECUTED.** This is a prepared procedure only. Nothing in
this doc has been run against production. Do not execute any step below
without explicit approval, and do not paste the current or new password
into chat, commit messages, logs, or this file at any point.

## What's wrong

Production crontab for `hoale24new` on `pdx1-shared-a3-05.dreamhost.com`
currently contains:

```
*/5 * * * * LINKHUB_ADMIN_EMAIL=admin@bakudanramen.com LINKHUB_ADMIN_PASSWORD=<weak, plaintext> /usr/bin/php /home/hoale24new/bakudanramen.com/api/run_linkhub_automations.php >> /home/hoale24new/bakudan-app/data/automations_cron.log 2>&1
```

Two problems: the password is weak, and it's plaintext in a crontab line,
which `crontab -l` exposes to the account owner and which may be captured
in backups/logs outside our control.

## What's fixed already (code, not yet deployed)

Branch `fix/linkhub-automations-credential-file` (off `main`):

- [api/run_linkhub_automations.php](../api/run_linkhub_automations.php) now calls
  `load_private_env_file()` before reading `LINKHUB_ADMIN_EMAIL` /
  `LINKHUB_ADMIN_PASSWORD`, loading them from
  `/home/hoale24new/bakudan-app/config/linkhub-automations.env` (overridable
  via `LINKHUB_AUTOMATIONS_ENV_FILE` for testing). Same pattern already used
  in production by [scripts/broth-log-telegram-cron.php](../scripts/broth-log-telegram-cron.php)
  for Telegram secrets (see [docs/broth-log-telegram-alerts.md](broth-log-telegram-alerts.md)).
  `getenv()` still wins if the variable is already set, so nothing breaks if
  someone still sets it inline.
- [LINK_HUB_2_FINAL_PRODUCTION_READINESS.md](../LINK_HUB_2_FINAL_PRODUCTION_READINESS.md)'s
  "Cron Setup" section no longer tells the site owner to embed the password
  in the crontab line.

Not committed yet — waiting on review.

## Rotation + deployment procedure (run only after approval)

Do this as one non-interactive SSH session per step so the new password is
never displayed, typed by a human, or written anywhere outside the target
file. `openssl rand` runs on the remote host itself, not locally.

### Lockout-risk analysis

The one sequencing rule that matters here: **the private env file must
contain the new password before the API password is actually rotated to
that value, and the new password must be confirmed working before the old
one is ever assumed dead.** Step 2 below does all of this in a single SSH
session, in this exact order:

1. Generate `NEWPASS` privately (server-side, never displayed).
2. Secure the private env file directory (`700`).
3. Write `NEWPASS` into the private env file (`chmod 600` immediately after).
4. Rotate the live account password to `NEWPASS` via `/auth/change-password`.
5. **Immediately verify the new credential**: log in again with `NEWPASS`
   and confirm a token comes back, in the same session, before the old
   password is assumed rotated away.

This exact order — generate → secure file → rotate → verify new credential
— matches the required conceptual order. Two deliberate deviations from a
strict literal reading of "verify new credential, then verify old
rejected, then verify automation runner, then remove inline cron, then
verify cron":
- Steps 1-5 above are collapsed into one SSH session (step 2 below) instead
  of separate round-trips, specifically so `NEWPASS` never has to be
  re-entered, re-derived, or persisted between steps — the fewer times a
  secret crosses a session boundary, the fewer places it can leak. This is
  strictly safer, not a shortcut.
- "Verify old credential rejected" (step 3 below) is checked before
  "verify automation runner" (step 4 below) — the reverse of collapsing
  them — because a still-valid old password is the actual security defect
  being fixed; confirming it's dead takes priority over confirming the
  cron path works, and neither check depends on the other's result.

Because the file is already correct *before* the rotation call fires,
there is no window where the account's real password has changed but the
cron script would read something else. If the rotation call in step 2
fails outright (non-2xx / no token), the script aborts before the account
password changes at all — the file will contain an unused `NEWPASS` that
doesn't match any real account password yet, which is harmless (the cron
job continues authenticating with the *old* password via the still-inline
crontab line, since crontab isn't touched until step 5). If the SSH
session is interrupted between the file write and the rotation call
succeeding, re-run step 2 from scratch (regenerating `NEWPASS` is fine —
the old password is still valid until rotation actually succeeds). If the
rotation call succeeds but the immediate re-login with `NEWPASS` in the
same step fails, treat that as a hard stop and investigate before doing
anything else — see Rollback.

### 1. Deploy the updated script

Use the existing deploy pattern (`scripts/_deploy_linkhub2.py`, which
already includes `api/run_linkhub_automations.php` in `FILES_TO_DEPLOY`) or
SFTP the single file manually to
`/home/hoale24new/bakudanramen.com/api/run_linkhub_automations.php`.

This is safe to deploy immediately, independent of rotation: the script
still works with `getenv()`-only credentials until the private file exists.

### 2. Create the private env file with a fresh strong password

Run on the server (single SSH command, password never leaves the host):

```bash
ssh hoale24new@pdx1-shared-a3-05.dreamhost.com '
set -e
umask 077
mkdir -p /home/hoale24new/bakudan-app/config
chmod 700 /home/hoale24new/bakudan-app/config
NEWPASS=$(openssl rand -base64 24)
printf "LINKHUB_ADMIN_EMAIL=admin@bakudanramen.com\nLINKHUB_ADMIN_PASSWORD=%s\n" "$NEWPASS" > /home/hoale24new/bakudan-app/config/linkhub-automations.env
chmod 600 /home/hoale24new/bakudan-app/config/linkhub-automations.env

# Rotate it live via the same API the runner script itself calls, using the
# OLD password (already known/exposed) to authenticate once, then never
# touching the old password again.
TOKEN=$(curl -s -X POST https://www.bakudanramen.com/api/auth/login \
  -H "Content-Type: application/json" \
  -d "{\"email\":\"admin@bakudanramen.com\",\"password\":\"<OLD_PASSWORD>\"}" \
  | php -r "echo json_decode(file_get_contents(\"php://stdin\"), true)[\"token\"] ?? \"\";")
if [ -z "$TOKEN" ]; then echo "LOGIN FAILED, aborting rotation" >&2; exit 1; fi

RESULT=$(curl -s -X POST https://www.bakudanramen.com/api/auth/change-password \
  -H "Content-Type: application/json" -H "Authorization: Bearer $TOKEN" \
  -d "{\"current_password\":\"<OLD_PASSWORD>\",\"new_password\":\"$NEWPASS\"}")
echo "$RESULT"

# Immediately verify the new password actually works, in this same
# session, before assuming rotation succeeded. Only the presence/absence
# of a token is checked -- the token value itself is never printed.
NEWTOKEN=$(curl -s -X POST https://www.bakudanramen.com/api/auth/login \
  -H "Content-Type: application/json" \
  -d "{\"email\":\"admin@bakudanramen.com\",\"password\":\"$NEWPASS\"}" \
  | php -r "echo json_decode(file_get_contents(\"php://stdin\"), true)[\"token\"] ?? \"\";")
if [ -z "$NEWTOKEN" ]; then
  echo "NEW PASSWORD DOES NOT AUTHENTICATE -- STOP, do not proceed, see Rollback" >&2
  unset NEWPASS TOKEN NEWTOKEN
  exit 1
fi
echo "OK: new password authenticates successfully"
unset NEWPASS TOKEN NEWTOKEN
'
```

Notes:
- `<OLD_PASSWORD>` still has to be supplied once to authenticate the
  rotation — it is already known/compromised (it's the one currently
  sitting in plaintext in the crontab), so this doesn't newly expose
  anything. It should still not be pasted into chat; the operator running
  this should fill it in locally in their own terminal/editor, not send it
  to me.
- `/auth/change-password` requires `current_password` + `new_password`
  (min 8 chars) and returns `{"success":true}` — see
  [api/index.php:1189](../api/index.php:1189).
- If this command is run non-interactively from a script, prefer feeding
  `<OLD_PASSWORD>` via a local-only env var / secrets store rather than
  inlining it in shell history.

### 3. Verify the old password no longer authenticates

Confirms the rotation in step 2 actually took effect, without printing
either password. Checked first because a still-valid old password is the
actual vulnerability being fixed here:

```bash
ssh hoale24new@pdx1-shared-a3-05.dreamhost.com '
CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST https://www.bakudanramen.com/api/auth/login \
  -H "Content-Type: application/json" \
  -d "{\"email\":\"admin@bakudanramen.com\",\"password\":\"<OLD_PASSWORD>\"}")
if [ "$CODE" = "200" ]; then
  echo "UNEXPECTED: old password still authenticates (HTTP $CODE) -- rotation did not take effect"
  exit 1
else
  echo "OK: old password rejected (HTTP $CODE)"
fi
'
```

Only the HTTP status code is inspected/printed — never the response body,
which is where a valid login would otherwise place a live token. Do not
proceed until this prints `OK`.

### 4. Verify the automation runner end-to-end

```bash
ssh hoale24new@pdx1-shared-a3-05.dreamhost.com \
  '/usr/bin/php /home/hoale24new/bakudanramen.com/api/run_linkhub_automations.php --dry-run'
```

Expect: `DRY RUN: credentials present, lock acquired successfully. ...` — no
"environment variables are not set" error. This confirms the script picked
the new credentials up from the private file (the crontab line at this
point is still the old inline one — untouched until step 5 — so this
specifically exercises the private-file loading path, not the crontab
path). Note this only confirms the file is populated and readable, not
that its contents authenticate — that was already independently confirmed
in step 2's immediate re-login check and reconfirmed by step 3 rejecting
the old password.

### 5. Rewrite the crontab line to drop inline credentials

```bash
ssh hoale24new@pdx1-shared-a3-05.dreamhost.com 'crontab -l' > /tmp/hoale24new-crontab.bak
# Edit locally: replace the old inline-credentials LINKHUB_ADMIN_EMAIL/LINKHUB_ADMIN_PASSWORD line with:
#   */5 * * * * /usr/bin/php /home/hoale24new/bakudanramen.com/api/run_linkhub_automations.php >> /home/hoale24new/bakudan-app/data/automations_cron.log 2>&1
ssh hoale24new@pdx1-shared-a3-05.dreamhost.com 'crontab -' < /tmp/hoale24new-crontab.new
ssh hoale24new@pdx1-shared-a3-05.dreamhost.com 'crontab -l'   # confirm no password appears
rm /tmp/hoale24new-crontab.bak /tmp/hoale24new-crontab.new
```

Every other line in the existing crontab (the Broth Log Telegram cron, and
anything else) must be preserved verbatim — only the one LinkHub line
changes.

### 6. Final check

Wait for one real 5-minute tick and confirm
`/home/hoale24new/bakudan-app/data/automations_cron.log` shows a normal
`OK:` / no-active-rules line, not a credentials error.

## Rollback

This rollback plan exists before any step above is executed, per the
hard-stop requirement that a rollback path be defined ahead of time.

- **If step 2 fails before the rotation call fires** (e.g. can't write the
  env file): nothing changed on the account side. Just fix the underlying
  issue (permissions, disk space) and retry step 2 from scratch.
- **If step 2's rotation call fails** (non-2xx, no token): the account
  password was never changed (see "Lockout-risk analysis" above) — the
  crontab's old inline password still works. Safe to retry step 2.
- **If step 2 succeeds but its own immediate re-login with `NEWPASS`
  fails**: the account password may be in an inconsistent state — stop and
  investigate directly (e.g. via the Admin login page with the new
  password) rather than proceeding or retrying blindly.
- **If step 2 succeeds but step 3 shows the old password still
  authenticates**: that's a genuine anomaly — stop and investigate rather
  than proceeding; do not touch the crontab yet.
- **If anything fails after step 5** (crontab already rewritten): restore
  the crontab from `/tmp/hoale24new-crontab.bak` via `crontab -`. The
  account password has already been rotated to the new value by this
  point, so a restored crontab must reference the *new* password if it's
  going to use inline credentials again even temporarily — never the old
  one, which no longer works per step 3.
- In every case, `/home/hoale24new/bakudan-app/data/automations_cron.log`
  is the fastest signal something is wrong: a run of
  `ERROR: LINKHUB_ADMIN_EMAIL / LINKHUB_ADMIN_PASSWORD environment
  variables are not set` or a login failure means the private env file
  and/or crontab are out of sync — re-check both before assuming the
  account password itself is the problem.
