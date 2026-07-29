"""
Campaign publishing state machine and pipeline (hardened v2).

States: draft -> approved -> scheduled -> publishing -> published
                                        \\-> failed (retryable)

Flow for each due article:
  1. select_due()        -- publish_at <= now, status == 'approved' (state.json
                             is the ONLY status authority -- see test_state_authority.py)
  2. render_and_validate() -- deterministic HTML, checked against required fields
  3. check_already_live()  -- BEFORE any deploy: is the exact artifact already
                             genuinely live (real identity checks, never HTTP
                             200 alone)? If yes, this is a stale-git recovery
                             from a prior run whose SFTP deploy succeeded but
                             whose git push failed -- reconcile state and
                             commit, WITHOUT re-deploying or duplicating the
                             blog/sitemap entry.
  4. write_local_files()   -- idempotent blog.html/sitemap.xml insertion
  5. deploy_scp()          -- scoped SFTP upload with durable backup manifest,
                             strict host-key verification, required env vars
  6. verify_live()         -- real HTTP identity checks against the live URL
  7. mark_published()      -- only after verify_live() passes; else status
                             stays 'publishing' for reconcile_stale_publishing()
  8. commit_and_push()     -- explicit scoped path list (git_ops.py), fetch +
                             drift check + bounded retry, never force-push.
                             A push failure here does NOT roll back a
                             successful live deploy -- the next run's
                             check_already_live() reconciles it instead of
                             blindly redeploying.

Fails closed at every stage: missing credential, unset/mismatched SSH host
key, SFTP error, partial upload, or live-verification mismatch all leave the
article NOT published (never a false-positive "published" status), and never
force-push or overwrite a human commit.
"""
import base64
import datetime
import hashlib
import json
import os
import posixpath
import re
import sys
import tempfile
import time
import xml.sax.saxutils

import paramiko

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
sys.path.insert(0, os.path.join(ROOT, 'scripts', 'campaign'))
import render_template as rt  # noqa: E402
import git_ops  # noqa: E402

STATE_PATH = os.path.join(ROOT, 'content', 'campaign', 'campaign-state.json')
MANIFEST_PATH = os.path.join(ROOT, 'content', 'campaign', 'campaign-manifest.json')
BLOG_HTML_PATH = os.path.join(ROOT, 'blog.html')
SITEMAP_PATH = os.path.join(ROOT, 'sitemap.xml')

SITE_URL = 'https://www.bakudanramen.com'

BLOG_MARKER_START = '<!-- SEO-CAMPAIGN-2026-START -->'
BLOG_MARKER_END = '<!-- SEO-CAMPAIGN-2026-END -->'

MAX_PUBLISHING_AGE_MINUTES = 30  # beyond this, a 'publishing' article is considered stale


# ---------------------------------------------------------------------------
# State I/O -- atomic writes so a hard kill (workflow cancellation) mid-write
# can never leave campaign-state.json truncated/corrupted.
# ---------------------------------------------------------------------------

def load_state():
    with open(STATE_PATH, encoding='utf-8') as f:
        return json.load(f)


def save_state(state):
    fd, tmp_path = tempfile.mkstemp(dir=os.path.dirname(STATE_PATH), suffix='.tmp')
    try:
        with os.fdopen(fd, 'w', encoding='utf-8') as f:
            json.dump(state, f, indent=2, ensure_ascii=False)
            f.write('\n')
            f.flush()
            os.fsync(f.fileno())
        os.replace(tmp_path, STATE_PATH)  # atomic on POSIX and Windows
    except BaseException:
        if os.path.exists(tmp_path):
            os.unlink(tmp_path)
        raise


def load_manifest():
    with open(MANIFEST_PATH, encoding='utf-8') as f:
        return json.load(f)


def _log(state, article_id, event, **kw):
    entry = {'event': event, 'at': datetime.datetime.now(datetime.timezone.utc).isoformat(), **kw}
    state['articles'][article_id].setdefault('history', []).append(entry)


def published_slugs(state, manifest):
    # state.json only -- never reads status off a manifest entry (manifest has none).
    id_to_slug = {a['id']: a['slug'] for a in manifest['articles']}
    return {id_to_slug[aid] for aid, rec in state['articles'].items() if rec['status'] == 'published'}


def select_due(state, manifest, now=None):
    now = now or datetime.datetime.now(datetime.timezone.utc)
    due = []
    for entry in manifest['articles']:
        rec = state['articles'].get(entry['id'])
        if not rec or rec['status'] != 'approved':
            continue
        publish_at = datetime.datetime.strptime(entry['publish_at'], '%Y-%m-%dT%H:%M:%SZ').replace(tzinfo=datetime.timezone.utc)
        if publish_at <= now:
            due.append(entry)
    return sorted(due, key=lambda e: e['seq'])


def load_article_content(entry):
    path = os.path.join(ROOT, 'content', 'campaign', 'articles', f'{entry["seq"]:02d}-{entry["slug"]}.json')
    with open(path, encoding='utf-8') as f:
        return json.load(f)


def render_and_validate(entry, state, manifest):
    article = load_article_content(entry)
    pubs = published_slugs(state, manifest)
    html = rt.render_article_html(article, published_slugs=pubs)
    marker = f'<!-- ARTICLE-ID: {article["id"]} -->'
    if marker not in html:
        raise ValueError(f'{entry["slug"]}: rendered HTML missing body marker')
    if f'<link rel="canonical" href="{SITE_URL}/{article["slug"]}.html">' not in html:
        raise ValueError(f'{entry["slug"]}: rendered HTML missing correct canonical')
    return article, html


def update_blog_index(article, html_text=None):
    text = html_text if html_text is not None else open(BLOG_HTML_PATH, encoding='utf-8').read()
    card = (
        f'                <article class="blog-card">\n'
        f'                    <div class="blog-card-image"><img src="{article["image"]}" alt="{xml.sax.saxutils.escape(article["image_alt"])}" width="1200" height="630" loading="lazy"></div>\n'
        f'                    <div class="blog-card-body">\n'
        f'                        <span class="blog-card-tag">Guide</span>\n'
        f'                        <h3>{xml.sax.saxutils.escape(article["h1"])}</h3>\n'
        f'                        <p>{xml.sax.saxutils.escape(article["meta_description"])}</p>\n'
        f'                        <a href="{article["slug"]}.html" class="blog-card-link">Read Article &rarr;</a>\n'
        f'                    </div>\n'
        f'                </article>\n'
    )
    if f'href="{article["slug"]}.html"' in text:
        return text  # idempotent: already linked, never insert a duplicate card
    if BLOG_MARKER_START not in text:
        text = text.replace(
            '<div class="blog-grid">',
            f'<div class="blog-grid">\n                {BLOG_MARKER_START}\n                {BLOG_MARKER_END}',
            1,
        )
    text = text.replace(BLOG_MARKER_START, BLOG_MARKER_START + '\n' + card)
    return text


def update_sitemap(canonical_url, xml_text=None):
    text = xml_text if xml_text is not None else open(SITEMAP_PATH, encoding='utf-8').read()
    if f'<loc>{canonical_url}</loc>' in text:
        return text  # idempotent: never insert a duplicate <url> entry
    entry = f'<url>\n<loc>{canonical_url}</loc>\n<changefreq>monthly</changefreq>\n<priority>0.6</priority>\n</url>\n'
    return text.replace('</urlset>', entry + '</urlset>')


def sitemap_loc_count(text, canonical_url):
    return text.count(f'<loc>{canonical_url}</loc>')


def write_local_files(article, html):
    article_path = os.path.join(ROOT, f'{article["slug"]}.html')
    with open(article_path, 'w', encoding='utf-8') as f:
        f.write(html)

    blog_text = update_blog_index(article)
    with open(BLOG_HTML_PATH, 'w', encoding='utf-8') as f:
        f.write(blog_text)

    canonical = f'{SITE_URL}/{article["slug"]}.html'
    sitemap_text = update_sitemap(canonical)
    with open(SITEMAP_PATH, 'w', encoding='utf-8') as f:
        f.write(sitemap_text)

    return article_path, BLOG_HTML_PATH, SITEMAP_PATH


# ---------------------------------------------------------------------------
# SFTP: required env vars (no hardcoded fallback host/user/path), strict
# host-key verification (no AutoAddPolicy), durable backup manifest.
# ---------------------------------------------------------------------------

REQUIRED_SFTP_ENV_VARS = (
    'BAKUDAN_SFTP_HOST', 'BAKUDAN_SFTP_PORT', 'BAKUDAN_SFTP_USER',
    'BAKUDAN_SFTP_PASS', 'BAKUDAN_REMOTE_WR', 'DREAMHOST_HOST_KEY',
)


def _require_env(name):
    val = os.environ.get(name)
    if not val:
        # never echo which OTHER vars are/aren't set, or any partial value --
        # just the name of the missing one.
        raise RuntimeError(f'{name} is required and not set -- failing closed, not deploying')
    return val


def get_sftp_config():
    return {
        'host': _require_env('BAKUDAN_SFTP_HOST'),
        'port': int(_require_env('BAKUDAN_SFTP_PORT')),
        'user': _require_env('BAKUDAN_SFTP_USER'),
        'password': _require_env('BAKUDAN_SFTP_PASS'),
        'remote_wr': _require_env('BAKUDAN_REMOTE_WR'),
    }


def connect_ssh(config):
    """
    Strict host-key verification: DREAMHOST_HOST_KEY holds one or more
    known_hosts-format lines (e.g. from `ssh-keyscan`). RejectPolicy means
    paramiko raises immediately on any host key it can't match against that
    pinned set -- no silent trust-on-first-use, no AutoAddPolicy.
    """
    host_key_data = _require_env('DREAMHOST_HOST_KEY')
    ssh = paramiko.SSHClient()
    fd, known_hosts_path = tempfile.mkstemp(suffix='_known_hosts')
    try:
        with os.fdopen(fd, 'w', encoding='utf-8') as f:
            f.write(host_key_data.strip() + '\n')
        ssh.load_host_keys(known_hosts_path)
    finally:
        os.unlink(known_hosts_path)
    ssh.set_missing_host_key_policy(paramiko.RejectPolicy())
    ssh.connect(config['host'], port=config['port'], username=config['user'],
                password=config['password'], timeout=30)
    return ssh


def host_key_fingerprints():
    """
    Sanitized SHA256 fingerprint(s) of the currently PINNED host key(s) --
    safe to print (a fingerprint is not a secret; publishing it is the whole
    point of host-key verification). Never returns the raw key blob.

    IMPORTANT HONESTY NOTE: DREAMHOST_HOST_KEY was populated via
    `ssh-keyscan`, which is trust-on-first-use (TOFU) -- this function
    reports what is currently pinned and being enforced by RejectPolicy, it
    does NOT prove that value is authentic. Treat it as "pinned, not
    independently verified" unless someone has separately cross-checked it
    against DreamHost's own panel/support or a fingerprint the site owner
    confirmed through a channel other than this same SSH connection.
    """
    host_key_data = _require_env('DREAMHOST_HOST_KEY')
    fingerprints = []
    for line in host_key_data.strip().splitlines():
        parts = line.split()
        if len(parts) < 3:
            continue
        _host, key_type, key_b64 = parts[0], parts[1], parts[2]
        key_bytes = base64.b64decode(key_b64)
        digest = hashlib.sha256(key_bytes).digest()
        fp = 'SHA256:' + base64.b64encode(digest).decode().rstrip('=')
        fingerprints.append({'key_type': key_type, 'fingerprint': fp})
    return fingerprints


_DANGEROUS_TARGET_PATHS = {'/', '/etc', '/bin', '/usr', '/root', '/var', '/sbin', '/boot', '/dev', '/proc', '/sys', '/home'}


def is_target_path_safe(path):
    """
    Bounds-check the configured remote target directory. Refuses root,
    known system directories, anything containing a literal '..' segment,
    or anything too shallow to plausibly be a specific site's document root
    (e.g. just '/home' rather than '/home/<user>/<site>'). This does not
    guarantee the path is *correct*, only that it isn't obviously dangerous.

    Uses posixpath explicitly, NOT os.path -- the target is always a remote
    Unix/SFTP path regardless of what OS this script runs on, and os.path
    resolves to ntpath (backslash semantics) on Windows, which silently
    breaks this check when developing/testing locally on Windows.
    """
    if not path or not path.startswith('/'):
        return False, 'not an absolute path'
    if '..' in path.split('/'):
        return False, 'contains a path traversal segment'
    normalized = posixpath.normpath(path)
    if normalized in _DANGEROUS_TARGET_PATHS:
        return False, 'resolves to a system directory, refusing'
    depth = len([p for p in normalized.split('/') if p])
    if depth < 3:
        return False, 'target directory is too shallow to be a bounded site directory'
    return True, None


def _sanitize_error(msg):
    """Strips any configured SFTP credential/host/path/host-key value, and
    this process's own local absolute workspace root, out of an error
    message before it's ever printed, returned, or written to a manifest
    that ends up in an uploaded artifact."""
    for var in ('BAKUDAN_SFTP_HOST', 'BAKUDAN_SFTP_USER', 'BAKUDAN_SFTP_PASS',
                'BAKUDAN_REMOTE_WR', 'DREAMHOST_HOST_KEY'):
        val = os.environ.get(var)
        if val:
            msg = msg.replace(val, f'[REDACTED:{var}]')
    if ROOT:
        msg = msg.replace(ROOT, '[REDACTED:local-workspace-root]')
    return msg


def _categorize_error(e):
    """Coarse, safe category for an exception -- used instead of ever
    trusting a raw exception message not to contain something sensitive."""
    if isinstance(e, paramiko.AuthenticationException):
        return 'authentication_failed'
    if isinstance(e, paramiko.SSHException):
        return 'ssh_protocol_error'
    if isinstance(e, FileNotFoundError):
        return 'remote_path_not_found'
    if isinstance(e, PermissionError):
        return 'permission_denied'
    if isinstance(e, (TimeoutError, ConnectionResetError, ConnectionRefusedError, ConnectionAbortedError)):
        return 'connection_error'
    if isinstance(e, OSError):
        return 'os_error'
    return f'unknown_error:{type(e).__name__}'


def _sanitize_manifest_error(e):
    """Structured, sanitized error record for a backup manifest: category +
    operation-scoped sanitized message. Never the raw str(e) directly, and
    never any of the values _sanitize_error() redacts."""
    return {'category': _categorize_error(e), 'message': _sanitize_error(str(e))}


def validate_backup_dir_path(relative_path):
    """
    Bounds-check a backup_dir path BEFORE it is ever written to $GITHUB_OUTPUT
    or handed to the workflow's artifact-upload step. Must be: relative (not
    absolute, not a Windows drive path), free of '..' traversal, inside
    scripts/_deploy_backups/, and -- after resolving symlinks via realpath --
    still physically inside that directory (defends against a symlink swapped
    in for a backup subdirectory pointing somewhere else).
    """
    if not relative_path:
        return False, 'empty path'
    normalized_input = relative_path.replace('\\', '/')
    if normalized_input.startswith('/') or (len(normalized_input) > 1 and normalized_input[1] == ':'):
        return False, 'must be a relative path, not absolute'
    if '..' in normalized_input.split('/'):
        return False, 'contains a path traversal segment'
    normalized = posixpath.normpath(normalized_input)
    if not (normalized == 'scripts/_deploy_backups' or normalized.startswith('scripts/_deploy_backups/')):
        return False, 'must be inside scripts/_deploy_backups/'
    abs_path = os.path.realpath(os.path.join(ROOT, normalized.replace('/', os.sep)))
    allowed_root = os.path.realpath(os.path.join(ROOT, 'scripts', '_deploy_backups'))
    try:
        common = os.path.commonpath([abs_path, allowed_root])
    except ValueError:
        return False, 'resolves to a different filesystem root, refusing'
    if common != allowed_root:
        return False, 'resolves outside scripts/_deploy_backups/ (possible symlink escape)'
    return True, None


def sftp_preflight():
    """
    Fully read-only diagnostic. Connects with the exact same connect_ssh()
    and RejectPolicy() used by a real deploy, verifies the host key,
    confirms the target directory exists and passes is_target_path_safe(),
    and reads ONLY metadata (size, mtime) for blog.html and sitemap.xml --
    never their content. NEVER calls sftp.put / sftp.mkdir / sftp.rename /
    sftp.remove / sftp.rmdir anywhere in this function (locked by
    tests/campaign/test_sftp_preflight.py's source-inspection check).

    Returns a fully sanitized dict: no secrets, no raw host-key blob, no raw
    remote path strings -- only booleans, fingerprints (safe), sizes, and
    ISO timestamps.
    """
    result = {
        'connected': False,
        'host_key_verified': False,
        'host_key_fingerprints': [],
        'target_dir_within_safe_bounds': False,
        'target_dir_exists': False,
        'blog_html': None,
        'sitemap_xml': None,
        'mutating_operations_performed': False,  # structurally guaranteed -- see docstring
        'error': None,
    }
    try:
        result['host_key_fingerprints'] = host_key_fingerprints()
        config = get_sftp_config()

        path_ok, path_reason = is_target_path_safe(config['remote_wr'])
        result['target_dir_within_safe_bounds'] = path_ok
        if not path_ok:
            result['error'] = f'target directory failed safety check: {path_reason}'
            return result

        ssh = connect_ssh(config)
        result['connected'] = True
        result['host_key_verified'] = True  # connect_ssh raises via RejectPolicy otherwise

        sftp = ssh.open_sftp()
        try:
            sftp.stat(config['remote_wr'])
            result['target_dir_exists'] = True

            for fname, key in (('blog.html', 'blog_html'), ('sitemap.xml', 'sitemap_xml')):
                try:
                    st = sftp.stat(config['remote_wr'] + '/' + fname)
                    result[key] = {
                        'exists': True,
                        'size_bytes': st.st_size,
                        'mtime_utc': datetime.datetime.fromtimestamp(
                            st.st_mtime, tz=datetime.timezone.utc).isoformat(),
                    }
                except FileNotFoundError:
                    result[key] = {'exists': False}
        finally:
            sftp.close()
            ssh.close()
    except Exception as e:
        result['error'] = _sanitize_error(str(e))
    return result


def _sha256(path):
    h = hashlib.sha256()
    with open(path, 'rb') as f:
        for chunk in iter(lambda: f.read(65536), b''):
            h.update(chunk)
    return h.hexdigest()


def _write_github_output(key, value):
    """
    Writes a step output for the GitHub Actions workflow to consume. No-op
    outside Actions (GITHUB_OUTPUT unset), so safe to call from local/manual
    runs too. Defined here (used by deploy_scp below) rather than only near
    run() -- see the ordering fix in deploy_scp's docstring.
    """
    output_path = os.environ.get('GITHUB_OUTPUT')
    if not output_path:
        return
    with open(output_path, 'a', encoding='utf-8') as f:
        f.write(f'{key}={value}\n')


def _new_backup_dir_relative(slug):
    name = datetime.datetime.now().strftime('%Y%m%d-%H%M%S') + f'-{slug}'
    return f'scripts/_deploy_backups/{name}'


def _write_backup_manifest(backup_dir, manifest):
    """Atomic write (temp file + os.replace) so a hard kill mid-write can
    never leave a truncated/corrupted manifest.json -- same pattern as
    save_state()."""
    manifest_path = os.path.join(backup_dir, 'manifest.json')
    fd, tmp_path = tempfile.mkstemp(dir=backup_dir, suffix='.tmp')
    try:
        with os.fdopen(fd, 'w', encoding='utf-8') as f:
            json.dump(manifest, f, indent=2, ensure_ascii=False)
            f.write('\n')
            f.flush()
            os.fsync(f.fileno())
        os.replace(tmp_path, manifest_path)
    except BaseException:
        if os.path.exists(tmp_path):
            os.unlink(tmp_path)
        raise


def deploy_scp(article, local_files):
    """
    Durable-backup-first design: the backup directory is created and its
    backup_dir output is emitted to $GITHUB_OUTPUT BEFORE any operation that
    can still raise (connect, download, upload) -- this is the fix for the
    bug where a failure partway through a deploy left the backup orphaned on
    the ephemeral runner with no way for the workflow to find and upload it
    (run() only wrote the output after publish_one() returned successfully,
    which never happens on a failure path).

    The manifest tracks explicit phases (initialized -> connecting ->
    backing_up -> uploading -> completed, or -> failed at any point),
    written atomically after every state change, so a partial run always
    leaves a manifest reflecting exactly how far it got. File records use a
    logical role + basename only -- never the full remote path (which would
    reveal the DreamHost account's home directory structure).

    try/except/finally wraps the entire SFTP lifecycle so sftp.close() and
    ssh.close() always run best-effort, even on failure.
    """
    config = get_sftp_config()
    remote_wr = config['remote_wr']

    backup_dir_relative = _new_backup_dir_relative(article['slug'])
    ok, reason = validate_backup_dir_path(backup_dir_relative)
    if not ok:
        raise RuntimeError(f'generated backup_dir failed its own safety validation: {reason}')
    backup_dir = os.path.join(ROOT, backup_dir_relative.replace('/', os.sep))
    os.makedirs(backup_dir, exist_ok=True)

    manifest = {
        'article_id': article['id'], 'slug': article['slug'],
        'phase': 'initialized',
        'created_at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
        'updated_at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
        'files_backed_up': [],
        'files_uploaded': [],
        'error': None,
    }
    _write_backup_manifest(backup_dir, manifest)

    # THE FIX: emit the output the instant the directory + manifest exist,
    # before connect/download/upload get a chance to raise. Always a
    # relative path inside scripts/_deploy_backups/ (validated above).
    _write_github_output('backup_dir', backup_dir_relative)

    remote_files = [
        ('article_html', local_files[0], remote_wr + f'/{article["slug"]}.html'),
        ('blog_index', local_files[1], remote_wr + '/blog.html'),
        ('sitemap', local_files[2], remote_wr + '/sitemap.xml'),
        ('hero_image', os.path.join(ROOT, article['image']), remote_wr + '/' + article['image']),
    ]

    def touch(phase=None):
        if phase:
            manifest['phase'] = phase
        manifest['updated_at'] = datetime.datetime.now(datetime.timezone.utc).isoformat()
        _write_backup_manifest(backup_dir, manifest)

    ssh = None
    sftp = None
    try:
        touch('connecting')
        ssh = connect_ssh(config)
        sftp = ssh.open_sftp()

        touch('backing_up')
        for role, local_path, remote_path in remote_files:
            backup_local_path = os.path.join(backup_dir, f'{role}-{os.path.basename(remote_path)}')
            try:
                sftp.get(remote_path, backup_local_path)
                manifest['files_backed_up'].append({
                    'role': role, 'basename': os.path.basename(remote_path),
                    'checksum_sha256': _sha256(backup_local_path), 'was_new_file': False,
                })
            except FileNotFoundError:
                manifest['files_backed_up'].append({
                    'role': role, 'basename': os.path.basename(remote_path), 'was_new_file': True,
                })
            touch()

        touch('uploading')
        for role, local_path, remote_path in remote_files:
            remote_dir = remote_path.rsplit('/', 1)[0]
            try:
                sftp.stat(remote_dir)
            except FileNotFoundError:
                sftp.mkdir(remote_dir)
            sftp.put(local_path, remote_path)
            manifest['files_uploaded'].append({'role': role, 'basename': os.path.basename(remote_path)})
            touch()

        touch('completed')
    except Exception as e:
        manifest['error'] = _sanitize_manifest_error(e)
        touch('failed')
        raise
    finally:
        if sftp is not None:
            try:
                sftp.close()
            except Exception:
                pass
        if ssh is not None:
            try:
                ssh.close()
            except Exception:
                pass

    return {'backup_dir': backup_dir, 'backup_dir_relative': backup_dir_relative, 'manifest': manifest}


def verify_live(article, retries=3, delay_seconds=5):
    """
    Fail-closed live identity verification -- NEVER treats HTTP 200 alone as
    proof. A homepage-fallback / soft-404 response would pass http_200 but
    fail every identity check below, so all_passed would still be False.
    """
    import urllib.request
    import urllib.error

    canonical = f'{SITE_URL}/{article["slug"]}.html'
    marker = f'<!-- ARTICLE-ID: {article["id"]} -->'
    checks = {'http_200': False, 'title_match': False, 'canonical_match': False,
              'body_marker': False, 'image_200': False, 'sitemap_once': False}

    for attempt in range(retries):
        try:
            with urllib.request.urlopen(canonical, timeout=15) as resp:
                if resp.status == 200:
                    checks['http_200'] = True
                    body = resp.read().decode('utf-8', errors='ignore')
                    checks['title_match'] = f'<title>{article["seo_title"]}</title>' in body
                    checks['canonical_match'] = f'<link rel="canonical" href="{canonical}">' in body
                    checks['body_marker'] = marker in body
            break
        except (urllib.error.URLError, TimeoutError):
            if attempt < retries - 1:
                time.sleep(delay_seconds)
                continue

    try:
        image_url = f'{SITE_URL}/{article["image"]}'
        req = urllib.request.Request(image_url, method='HEAD')
        with urllib.request.urlopen(req, timeout=15) as resp:
            checks['image_200'] = resp.status == 200
    except (urllib.error.URLError, TimeoutError):
        pass

    try:
        with urllib.request.urlopen(f'{SITE_URL}/sitemap.xml', timeout=15) as resp:
            sitemap_body = resp.read().decode('utf-8', errors='ignore')
            checks['sitemap_once'] = sitemap_loc_count(sitemap_body, canonical) == 1
    except (urllib.error.URLError, TimeoutError):
        pass

    checks['all_passed'] = all(v for k, v in checks.items() if k != 'all_passed')
    return checks


# ---------------------------------------------------------------------------
# Git persistence: explicit scoped path list only, fetch + drift check +
# bounded retry, never force-push. Lives in git_ops.py; this is the glue.
# ---------------------------------------------------------------------------

def commit_and_push(article, new_image_uploaded, commit_message):
    paths = [f'{article["slug"]}.html', 'blog.html', 'sitemap.xml', 'content/campaign/campaign-state.json']
    if new_image_uploaded:
        paths.append(article['image'])

    git_ops.fetch_origin('main')
    added = git_ops.scoped_add(paths, expected_slug=article['slug'])
    if not git_ops.has_staged_changes():
        return {'committed': False, 'reason': 'no changes to commit', 'considered_paths': paths}

    sha = git_ops.commit(commit_message)
    push_result = git_ops.push_with_bounded_retry('main')
    return {'committed': True, 'sha': sha, 'staged_paths': added, 'push': push_result}


# ---------------------------------------------------------------------------
# Publish transaction
# ---------------------------------------------------------------------------

def publish_one(entry, state, manifest, dry_run=False):
    article_id = entry['id']
    rec = state['articles'][article_id]

    article, html = render_and_validate(entry, state, manifest)

    if dry_run:
        local_files_would_change = [f'{article["slug"]}.html', 'blog.html (idempotent insert)', 'sitemap.xml (idempotent insert)']
        remote_files_would_change = [f'{article["slug"]}.html', 'blog.html', 'sitemap.xml', article['image']]
        return {'dry_run': True, 'article_id': article_id, 'local_files': local_files_would_change, 'remote_files': remote_files_would_change}

    # Reconcile-before-deploy: if a PRIOR run's SFTP deploy already succeeded
    # but its git commit/push failed (so this article is still 'approved' in
    # git even though it's genuinely live), do NOT redeploy or duplicate the
    # blog/sitemap entry -- just catch git up to reality.
    already_live_checks = verify_live(article, retries=1)
    if already_live_checks['all_passed']:
        local_files = write_local_files(article, html)  # idempotent; ensures local checkout matches live
        rec['status'] = 'published'
        _log(state, article_id, 'reconciled_from_already_live', checks=already_live_checks,
             note='artifact was already genuinely live -- skipped SFTP deploy, only reconciled state+git')
        save_state(state)
        git_result = commit_and_push(article, new_image_uploaded=False,
                                      commit_message=f'chore(campaign): reconcile already-live {article["slug"]}')
        return {'published': True, 'reconciled': True, 'redeployed': False,
                'checks': already_live_checks, 'git': git_result}

    rec['status'] = 'publishing'
    _log(state, article_id, 'publishing_started')
    save_state(state)

    try:
        local_files = write_local_files(article, html)
    except Exception as e:
        _log(state, article_id, 'local_write_failed', error=str(e))
        rec['status'] = 'approved'
        save_state(state)
        raise

    try:
        deploy_result = deploy_scp(article, local_files)
    except Exception as e:
        _log(state, article_id, 'deploy_failed', error=str(e))
        rec['status'] = 'approved'  # nothing (or only a partial upload) is confirmed live -- safe to retry fully
        save_state(state)
        raise

    _log(state, article_id, 'deployed', backup_dir=deploy_result['backup_dir'])
    save_state(state)

    checks = verify_live(article)
    if not checks['all_passed']:
        _log(state, article_id, 'live_verification_failed', checks=checks)
        save_state(state)
        # status stays 'publishing' -- reconcile_stale_publishing() (or the
        # next run's check_already_live() at the top of this function) will
        # retry/investigate; never advances to 'published' on a failed check.
        return {'published': False, 'checks': checks, 'backup_dir': deploy_result['backup_dir']}

    rec['status'] = 'published'
    _log(state, article_id, 'published', checks=checks)
    save_state(state)

    # Git commit/push happens AFTER the live deploy is confirmed. If this
    # fails (commit error, push rejected after bounded retries, workflow
    # cancelled here), the article is genuinely live and state.json correctly
    # says 'published' locally -- we do NOT roll that back, since it's true.
    # The failure only means origin/main hasn't caught up yet; the NEXT run's
    # check_already_live() reconciles that without re-deploying (see above).
    git_result = commit_and_push(article, new_image_uploaded=True,
                                  commit_message=f'chore(campaign): publish {article["slug"]}')
    return {'published': True, 'reconciled': False, 'redeployed': True,
            'checks': checks, 'git': git_result, 'backup_dir': deploy_result['backup_dir']}


def reconcile_stale_publishing(state, manifest, now=None):
    now = now or datetime.datetime.now(datetime.timezone.utc)
    id_to_entry = {a['id']: a for a in manifest['articles']}
    reconciled = []
    for article_id, rec in state['articles'].items():
        if rec['status'] != 'publishing':
            continue
        last_event = rec['history'][-1] if rec['history'] else None
        if not last_event:
            continue
        started_at = datetime.datetime.fromisoformat(last_event['at'])
        age_minutes = (now - started_at).total_seconds() / 60
        if age_minutes < MAX_PUBLISHING_AGE_MINUTES:
            continue  # still within normal window, don't touch yet

        entry = id_to_entry[article_id]
        article = load_article_content(entry)
        checks = verify_live(article, retries=1)
        if checks['all_passed']:
            rec['status'] = 'published'
            _log(state, article_id, 'reconciled_as_published', checks=checks)
        else:
            rec['status'] = 'approved'
            _log(state, article_id, 'reconciled_as_failed_rolled_back', checks=checks)
        reconciled.append((article_id, rec['status']))
    if reconciled:
        save_state(state)
    return reconciled


def run(dry_run=False):
    """Recurring-automation entrypoint (used by the GitHub Actions workflow).
    Respects the automation_enabled kill-switch -- does nothing while it's false."""
    state = load_state()
    manifest = load_manifest()

    reconciled = reconcile_stale_publishing(state, manifest)
    for article_id, new_status in reconciled:
        print(f'reconciled {article_id} -> {new_status}')

    if not state.get('automation_enabled') and not dry_run:
        print('automation_enabled is false -- not publishing anything (kill-switch active)')
        return

    due = select_due(state, manifest)
    if not due:
        print('no due articles')
        return

    entry = due[0]  # publish at most one per run, never a batch
    print(f'due: seq {entry["seq"]} ({entry["slug"]})')

    # NOTE: the backup_dir $GITHUB_OUTPUT write happens INSIDE deploy_scp(),
    # immediately after the backup directory is created -- not here. Doing it
    # here (only after publish_one() returns) was the bug: a failure anywhere
    # in deploy_scp() means this line is never reached at all, and the
    # already-created backup is silently lost with the runner. See
    # deploy_scp()'s docstring and tests/campaign/test_backup_output_ordering.py.
    result = publish_one(entry, state, manifest, dry_run=dry_run)
    print(json.dumps(result, indent=2, default=str))


def run_one_manual(seq, dry_run=False):
    """
    Controlled, human-authorized single-article publish for the rollout gate
    (Phase 14). Deliberately bypasses the automation_enabled kill-switch --
    unlike run(), this is never invoked by the scheduled workflow, only by an
    operator explicitly running this command for one specific seq. Still
    fails closed the same way run() does.
    """
    state = load_state()
    manifest = load_manifest()
    entry = next((a for a in manifest['articles'] if a['seq'] == seq), None)
    if entry is None:
        raise ValueError(f'no article with seq {seq}')
    rec = state['articles'][entry['id']]
    if rec['status'] not in ('approved', 'scheduled'):
        raise ValueError(f'seq {seq} has status {rec["status"]!r}, expected approved/scheduled -- refusing to publish')

    print(f'MANUAL CONTROLLED PUBLISH: seq {entry["seq"]} ({entry["slug"]}), dry_run={dry_run}')
    # See the note in run() above -- backup_dir output is emitted from
    # inside deploy_scp(), not here.
    result = publish_one(entry, state, manifest, dry_run=dry_run)
    print(json.dumps(result, indent=2, default=str))
    return result


if __name__ == '__main__':
    if '--sftp-preflight' in sys.argv:
        preflight_result = sftp_preflight()
        print(json.dumps(preflight_result, indent=2, default=str))
        ok = preflight_result.get('connected') and preflight_result.get('host_key_verified') \
            and preflight_result.get('target_dir_exists') and not preflight_result.get('error')
        sys.exit(0 if ok else 1)
    elif '--publish-one' in sys.argv:
        idx = sys.argv.index('--publish-one')
        seq_arg = int(sys.argv[idx + 1])
        run_one_manual(seq_arg, dry_run='--dry-run' in sys.argv)
    else:
        run(dry_run='--dry-run' in sys.argv)
