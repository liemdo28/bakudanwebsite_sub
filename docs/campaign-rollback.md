# Campaign publisher — rollback & recovery procedure

## Where backups live

Every scheduler run that deploys via SFTP (`scripts/campaign/scheduler.py`'s
`deploy_scp()`) first downloads the **current remote copy** of each file it's
about to overwrite (the article HTML, `blog.html`, `sitemap.xml`, the hero
image) into `scripts/_deploy_backups/<timestamp>-<slug>/`, alongside a
`manifest.json` recording, per file: `remote_path`, `checksum_sha256`,
`was_new_file`, and the run's `created_at` timestamp.

- **Not committed to git** (`scripts/_deploy_backups/` is gitignored) and
  **never contains secrets** — only the four public HTML/image files being
  replaced, never `campaign-state.json`, credentials, or the full site.
- **Not servable publicly** — the directory lives inside the git working
  tree on the CI runner / your local machine, never uploaded to the DreamHost
  document root.
- **Durable copy**: the GitHub Actions workflow uploads the whole
  `scripts/_deploy_backups/` directory as a build artifact
  (`campaign-deploy-backup-<run-id>`, 90-day retention) after every run,
  win or lose (`if: always()`), so it survives after the ephemeral runner is
  destroyed. Download it from the workflow run's **Artifacts** section in
  the GitHub Actions UI, or via `gh run download <run-id>`.

## Rolling back a bad deploy

1. Find the run: GitHub → Actions → "SEO campaign publish" → the run that
   deployed the article you want to revert.
2. Download its artifact: `gh run download <run-id> -n campaign-deploy-backup-<run-id>`
   (or via the UI).
3. Inspect `manifest.json` in that download to confirm which remote paths
   were touched and their pre-deploy checksums.
4. Re-upload the backed-up copies to their `remote_path` values via SFTP
   (the same connection details the workflow uses: DreamHost host, the
   `BAKUDAN_SFTP_USER` account, target dir from `BAKUDAN_REMOTE_WR`/
   `PRODUCTION_TARGET_DIR`).
5. Revert the corresponding git commit on `main` (`git revert <sha>`) so the
   repository and production agree again.
6. If the article's `campaign-state.json` status was advanced to
   `published` incorrectly, correct it by hand in a reviewed commit (this is
   the one legitimate case for hand-editing state — see
   `tests/campaign/test_state_authority.py` for the invariants that must
   still hold afterward: state is the sole status authority, ids stay
   stable, `publish_at` is untouched).

## If a deploy partially succeeded (some files uploaded, not others)

You don't need to manually diagnose which files landed. Re-running the
scheduler (`python scripts/campaign/scheduler.py --publish-one <seq>` for a
controlled single run, or letting the scheduled workflow pick it up again)
is safe: `deploy_scp()` always uploads the full, deterministically-rendered
set of files, so a retry converges to the correct final state regardless of
exactly where the previous attempt stopped. If the article is *already*
fully live and correct by the time of the retry, `check_already_live()`
detects that and skips re-deploying entirely (see the "deploy-before-git"
fix in the hardening PR) — it only reconciles git.

## If SFTP succeeded but the git commit/push never landed

This is the specific failure mode the hardening pass targeted. No manual
action is needed: the next scheduler run (scheduled or manually triggered)
re-checks whether the due article is already genuinely live via
`verify_live()` (real identity checks — title, canonical, body marker,
image, sitemap — never HTTP 200 alone) before doing anything else. If it's
live, that run commits and pushes the already-correct local state without
re-deploying or duplicating the blog/sitemap entry.

## Emergency full stop

Disable the workflow (`gh workflow disable "SEO campaign publish"`), or set
`content/campaign/campaign-state.json`'s `automation_enabled` to `false` and
push — the scheduler checks this flag before doing anything and the
scheduled workflow will run but do nothing until it's flipped back on.
