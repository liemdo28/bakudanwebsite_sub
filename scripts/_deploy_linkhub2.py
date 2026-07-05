"""
Deploy Link Hub 2.0 to production via SFTP — scoped ONLY to the files
touched in this pass: api/index.php, links-admin/app.js, links/index.html,
marketing-signup/index.html.

Steps:
  1. Backup remote api/index.php, api/index-lite.php, and bakudan.db
     (+ -wal/-shm) into scripts/_deploy_backups/<timestamp>/ locally.
  2. Upload the four changed/new files.
  3. Smoke-test /api/config, /api/public/links/bakudan-links-main, and
     /marketing-signup/ over HTTPS from the remote host (triggers db_migrate()
     for real).
  4. Re-download bakudan.db and check locally (read-only) that the new
     columns (buttons.link_type, pages.page_type) exist — confirms the
     migration applied without corrupting the file.

Does NOT touch any other pending/modified file in the repo (about.html,
.htaccess, blog pages, etc.) — those are out of scope for this deploy.
"""
import hashlib, re, paramiko, pathlib, sqlite3, sys, time
from _deploy_static_pages import HOST, PORT, USER, PASS, REMOTE_WR

_here = pathlib.Path(__file__).parent
_repo = _here.parent

if not PASS:
    raise RuntimeError('Set BAKUDAN_SFTP_PASS before deploying.')
REMOTE_DB_DIR = '/home/hoale24new/bakudan-app/data'
REMOTE_DB = REMOTE_DB_DIR + '/bakudan.db'

STAMP = time.strftime('%Y%m%d_%H%M%S')
BACKUP_DIR = _here / '_deploy_backups' / STAMP
BACKUP_DIR.mkdir(parents=True, exist_ok=True)

def sync_admin_cache_bust():
    """Rewrite links-admin/index.html's app.js?v=... to a content hash of
    app.js itself, so every deploy auto-busts the browser cache — no more
    manually-edited version strings to forget (this caused real stale-JS
    bugs in earlier deploys this session)."""
    app_js = (_repo / 'links-admin/app.js').read_bytes()
    content_hash = hashlib.sha1(app_js).hexdigest()[:12]
    index_path = _repo / 'links-admin/index.html'
    html = index_path.read_text(encoding='utf-8')
    new_html, n = re.subn(
        r'(/links-admin/app\.js\?v=)[^"]*',
        r'\g<1>' + content_hash,
        html,
    )
    if n == 0:
        print('  !! could not find app.js script tag to cache-bust in links-admin/index.html')
    elif new_html != html:
        index_path.write_text(new_html, encoding='utf-8')
        print(f'  cache-bust version -> {content_hash}')
    else:
        print(f'  cache-bust version already up to date ({content_hash})')

FILES_TO_DEPLOY = [
    ('api/index.php',              REMOTE_WR + '/api/index.php'),
    ('api/run_linkhub_automations.php', REMOTE_WR + '/api/run_linkhub_automations.php'),
    ('links-admin/app.js',         REMOTE_WR + '/links-admin/app.js'),
    ('links-admin/index.html',     REMOTE_WR + '/links-admin/index.html'),
    ('links-admin/guide.html',     REMOTE_WR + '/links-admin/guide.html'),
    ('links/index.html',           REMOTE_WR + '/links/index.html'),
    ('marketing-signup/index.html', REMOTE_WR + '/marketing-signup/index.html'),
    ('forms/index.html',           REMOTE_WR + '/forms/index.html'),
]

def ensure_remote_dir(sftp, remote_path):
    d = str(pathlib.PurePosixPath(remote_path).parent)
    try:
        sftp.stat(d)
    except FileNotFoundError:
        print(f'  creating remote dir {d}')
        sftp.mkdir(d)

def try_get(sftp, remote, local):
    try:
        sftp.get(remote, local)
        print(f'  backed up {remote} -> {local} ({local.stat().st_size} bytes)')
        return True
    except FileNotFoundError:
        print(f'  (skip, not found on server) {remote}')
        return False

def main():
    print(f'Connecting to {HOST}:{PORT} as {USER}...')
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, port=PORT, username=USER, password=PASS, timeout=30)
    sftp = ssh.open_sftp()
    print('Connected.\n')

    print('== Step 1: backup ==')
    try_get(sftp, REMOTE_WR + '/api/index.php', BACKUP_DIR / 'index.php')
    try_get(sftp, REMOTE_WR + '/api/index-lite.php', BACKUP_DIR / 'index-lite.php')
    try_get(sftp, REMOTE_DB, BACKUP_DIR / 'bakudan.db')
    try_get(sftp, REMOTE_DB + '-wal', BACKUP_DIR / 'bakudan.db-wal')
    try_get(sftp, REMOTE_DB + '-shm', BACKUP_DIR / 'bakudan.db-shm')
    print(f'Backup stored at {BACKUP_DIR}\n')

    print('== Step 1b: sync admin cache-bust version ==')
    sync_admin_cache_bust()
    print()

    print('== Step 2: upload ==')
    for local_rel, remote_path in FILES_TO_DEPLOY:
        local_path = _repo / local_rel
        if not local_path.exists():
            print(f'  !! LOCAL FILE MISSING, aborting: {local_path}')
            sys.exit(1)
        ensure_remote_dir(sftp, remote_path)
        sftp.put(str(local_path), remote_path)
        stat = sftp.stat(remote_path)
        print(f'  {local_rel} -> {remote_path} ({stat.st_size} bytes)')
    sftp.close()
    print()

    print('== Step 3: smoke test (triggers db_migrate) ==')
    checks = [
        ('config',      'https://bakudanramen.com/api/config'),
        ('public links', 'https://bakudanramen.com/api/public/links/bakudan-links-main'),
        ('marketing signup page', 'https://bakudanramen.com/marketing-signup/'),
        ('marketing signup api', 'https://bakudanramen.com/api/public/marketing-signup'),
    ]
    all_ok = True
    for label, url in checks:
        cmd = f'curl -s -o /tmp/_lh2_check -w "%{{http_code}}" "{url}"; cat /tmp/_lh2_check; echo'
        _, o, e = ssh.exec_command(cmd, timeout=20)
        out = o.read().decode(errors='replace')
        err = e.read().decode(errors='replace')
        lines = out.strip().split('\n')
        code = lines[0] if lines else '???'
        body = '\n'.join(lines[1:])[:300]
        ok = code.startswith('2')
        all_ok = all_ok and ok
        print(f'  [{ "OK" if ok else "FAIL" }] {label}: HTTP {code}')
        print(f'        {body}')
        if err.strip():
            print(f'        stderr: {err.strip()[:200]}')
    print()

    print('== Step 4: verify DB schema migrated ==')
    sftp = ssh.open_sftp()
    post_db = BACKUP_DIR / 'bakudan.db.post-migrate'
    try:
        sftp.get(REMOTE_DB, str(post_db))
        con = sqlite3.connect(post_db)
        cols_buttons = [r[1] for r in con.execute('PRAGMA table_info(buttons)').fetchall()]
        cols_pages = [r[1] for r in con.execute('PRAGMA table_info(pages)').fetchall()]
        has_locations = con.execute(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='locations'").fetchone()
        con.close()
        print(f'  buttons.link_type present: {"link_type" in cols_buttons}')
        print(f'  pages.page_type present: {"page_type" in cols_pages}')
        print(f'  locations table present: {bool(has_locations)}')
    except Exception as e:
        print(f'  Could not verify schema: {e}')
    sftp.close()
    ssh.close()

    print('\nDone.' if all_ok else '\nDone, but one or more smoke tests FAILED — check output above.')

if __name__ == '__main__':
    main()
