"""
Scoped, concurrency-safe git operations for the campaign publisher.

Design goals (see security/campaign-hardening PR):
  - the scheduler must stage an EXPLICIT list of paths it intends to change,
    never a glob like `git add "*.html"` -- refuses anything outside a small
    allowlist (the one article file being published, blog.html, sitemap.xml,
    campaign-state.json, and the one hero image if newly required)
  - never force-push, never overwrite a human commit
  - before committing: fetch origin/main and refuse to proceed if the local
    base has drifted in a way that isn't a clean fast-forward (bounded retry
    via rebase only when there's no conflict; fail closed otherwise)
  - if push ultimately fails after retries, the caller must NOT treat this as
    a fatal deploy failure -- the SFTP deploy already succeeded and is real;
    the NEXT scheduler run's check_already_live() reconciliation is what
    catches git back up, not a same-run infinite retry loop
"""
import hashlib
import os
import re
import subprocess

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

# Directories/files that must NEVER be staged, no matter what a caller asks for.
FORBIDDEN_PATH_SEGMENTS = (
    'node_modules', '.git', '_archive', 'archive', 'scripts/_deploy_backups',
    'data/', '.env', '.htpasswd', 'credential', 'secrets', '.db', '.sqlite',
    'Bakudan Photo', 'Bakudan Photos 2026',  # raw media dumps, not campaign assets
)

FIXED_ALLOWED_PATHS = {'blog.html', 'sitemap.xml', 'content/campaign/campaign-state.json'}


# Exactly what a campaign publish run is allowed to touch. expected_slug, when
# given, is the ONE article being published this run -- article HTML and hero
# image paths are only allowed if they match that exact slug, not any
# lowercase-slug .html file (an earlier version of this check accepted any
# such file by shape, which would have let an unrelated existing page like
# menu.html slip through if the caller ever passed it by mistake).
def is_allowed_path(rel_path, expected_slug=None):
    rel_path = rel_path.replace('\\', '/')

    if rel_path.startswith('..') or '/../' in rel_path or rel_path.startswith('/'):
        return False, 'path traversal or absolute path'

    abs_path = os.path.normpath(os.path.join(ROOT, rel_path))
    if not abs_path.startswith(os.path.normpath(ROOT)):
        return False, 'resolves outside repository root'

    for forbidden in FORBIDDEN_PATH_SEGMENTS:
        if forbidden.lower() in rel_path.lower():
            return False, f'matches forbidden segment {forbidden!r}'

    if rel_path in FIXED_ALLOWED_PATHS:
        return True, None

    if expected_slug:
        if rel_path == f'{expected_slug}.html':
            return True, None
        if rel_path == f'images/campaign/{expected_slug}-hero.webp':
            return True, None
        return False, f'not blog.html/sitemap.xml/state, and does not match expected article slug {expected_slug!r}'

    # No expected_slug given: only allow generically-shaped campaign paths.
    # Callers publishing a specific article MUST pass expected_slug for the
    # tighter, exact-match check above -- this branch exists only for
    # tooling that legitimately doesn't know a specific slug in advance.
    if re.fullmatch(r'[a-z0-9-]+\.html', rel_path):
        return True, None
    if re.fullmatch(r'images/campaign/[a-z0-9-]+-hero\.webp', rel_path):
        return True, None

    return False, 'not on the campaign publish allowlist'


def validate_paths(paths, expected_slug=None):
    """Raises ValueError on the first disallowed path. Returns the validated list."""
    for p in paths:
        ok, reason = is_allowed_path(p, expected_slug=expected_slug)
        if not ok:
            raise ValueError(f'refusing to stage {p!r}: {reason}')
    return list(paths)


def _run(args, check=True):
    return subprocess.run(['git'] + args, cwd=ROOT, capture_output=True, text=True, check=check)


def current_branch():
    return _run(['rev-parse', '--abbrev-ref', 'HEAD']).stdout.strip()


def current_head_sha():
    return _run(['rev-parse', 'HEAD']).stdout.strip()


def fetch_origin(branch='main'):
    _run(['fetch', 'origin', branch])


def is_fast_forwardable(branch='main'):
    """True if local HEAD is an ancestor of origin/<branch> (i.e. no local-only
    divergence that would need a real merge) OR origin/<branch> is an ancestor
    of local HEAD (nothing new upstream). False means real divergence."""
    local = current_head_sha()
    remote = _run(['rev-parse', f'origin/{branch}']).stdout.strip()
    if local == remote:
        return True, 'up to date'
    ahead_behind = _run(['rev-list', '--left-right', '--count', f'{local}...{remote}']).stdout.strip()
    behind, ahead = (int(x) for x in ahead_behind.split())
    if ahead == 0:
        return True, 'local is behind or equal, safe to rebase'
    return False, f'local has {ahead} commit(s) not on origin/{branch} -- real divergence'


def scoped_add(paths, expected_slug=None):
    validated = validate_paths(paths, expected_slug=expected_slug)
    existing = [p for p in validated if os.path.isfile(os.path.join(ROOT, p))]
    if existing:
        _run(['add', '--'] + existing)
    return existing


def has_staged_changes():
    result = _run(['diff', '--cached', '--quiet'], check=False)
    return result.returncode != 0


def commit(message):
    _run(['-c', 'user.name=seo-campaign-bot', '-c', 'user.email=actions@github.com', 'commit', '-m', message])
    return current_head_sha()


def push_with_bounded_retry(branch='main', max_retries=3):
    """
    Never force-pushes. On rejection, fetches + attempts a rebase ONLY if
    is_fast_forwardable() confirms no real divergence (i.e. purely a
    behind-by-N-commits situation, not conflicting local work); fails closed
    (raises) on any real divergence or unresolved conflict, leaving the
    commit local for a human/next-run to inspect rather than forcing.
    """
    for attempt in range(1, max_retries + 1):
        result = _run(['push', 'origin', f'HEAD:{branch}'], check=False)
        if result.returncode == 0:
            return {'pushed': True, 'attempt': attempt}

        fetch_origin(branch)
        ok, reason = is_fast_forwardable(branch)
        if not ok:
            raise RuntimeError(
                f'push rejected and cannot safely reconcile (attempt {attempt}/{max_retries}): {reason}. '
                f'Refusing to force-push or overwrite. stderr={result.stderr.strip()!r}'
            )

        rebase = _run(['rebase', f'origin/{branch}'], check=False)
        if rebase.returncode != 0:
            _run(['rebase', '--abort'], check=False)
            raise RuntimeError(
                f'rebase onto origin/{branch} failed (attempt {attempt}/{max_retries}), aborted cleanly. '
                f'stderr={rebase.stderr.strip()!r}'
            )

    return {'pushed': False, 'attempt': max_retries,
            'reason': 'exhausted retries -- commit remains local, next run will reconcile via check_already_live()'}
