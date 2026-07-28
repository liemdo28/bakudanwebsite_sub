"""Tests the scoped git path allowlist: only campaign publish artifacts can ever be staged."""
import os
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
sys.path.insert(0, os.path.join(ROOT, 'scripts', 'campaign'))
import git_ops  # noqa: E402


def test_allows_expected_campaign_paths():
    for path in [
        'blog.html',
        'sitemap.xml',
        'content/campaign/campaign-state.json',
        'beginners-guide-to-ordering-ramen-at-bakudan.html',
        'images/campaign/beginners-guide-to-ordering-ramen-at-bakudan-hero.webp',
    ]:
        ok, reason = git_ops.is_allowed_path(path)
        assert ok, f'{path} should be allowed, got reason={reason!r}'


def test_refuses_unrelated_html_when_expected_slug_given():
    # This is the real guarantee: when the scheduler publishes a specific
    # article, it passes expected_slug, and ANY other .html file --
    # including one that looks like a plausible campaign slug, and
    # including genuinely unrelated existing site pages -- is refused.
    expected = 'beginners-guide-to-ordering-ramen-at-bakudan'
    for unrelated in ['menu.html', 'about.html', 'happy-hour.html', 'some-other-campaign-article.html']:
        ok, reason = git_ops.is_allowed_path(unrelated, expected_slug=expected)
        assert not ok, f'{unrelated} must be refused when expected_slug={expected!r}, got ok=True'
    # the actual due article is still allowed
    ok, reason = git_ops.is_allowed_path(f'{expected}.html', expected_slug=expected)
    assert ok, f'the expected article itself must be allowed, got reason={reason!r}'


def test_scoped_add_requires_expected_slug_for_exact_match():
    # Without expected_slug, any shape-matching .html is accepted (documented
    # fallback for tooling that doesn't know a specific slug) -- but
    # scheduler.py's real publish path must always pass expected_slug (see
    # test_scheduler_never_uses_wildcard_add for the stronger source-level check).
    ok, reason = git_ops.is_allowed_path('menu.html')
    assert ok, 'without expected_slug this is the documented looser fallback, not the production guarantee'


def test_refuses_traversal():
    for bad in ['../secrets.txt', 'content/../../etc/passwd', '/etc/passwd', '..\\..\\windows\\system32']:
        ok, reason = git_ops.is_allowed_path(bad)
        assert not ok, f'{bad} should be refused'


def test_refuses_forbidden_segments():
    for bad in [
        'node_modules/foo/index.js',
        'scripts/_deploy_backups/20260101/blog.html',
        'data/bkdn.db',
        '.env',
        '.htpasswd',
        'Bakudan Photo/some-photo.jpg',
        '_archive/old-site/index.html',
    ]:
        ok, reason = git_ops.is_allowed_path(bad)
        assert not ok, f'{bad} should be refused, got ok=True'


def test_refuses_non_campaign_directories():
    for bad in ['links-admin/app.js', 'api/index.php', 'css/styles.css', 'evidence/foo.json']:
        ok, reason = git_ops.is_allowed_path(bad)
        assert not ok, f'{bad} should be refused (not a campaign artifact)'


def test_validate_paths_raises_on_first_bad_path():
    try:
        git_ops.validate_paths(['blog.html', '../evil.html'])
        raised = False
    except ValueError:
        raised = True
    assert raised, 'validate_paths must raise on any disallowed path in the list'


def test_scheduler_never_uses_wildcard_add():
    """Locks the actual fix: scheduler.py must never call git add with a glob."""
    scheduler_src = open(os.path.join(ROOT, 'scripts', 'campaign', 'scheduler.py'), encoding='utf-8').read()
    assert '"*.html"' not in scheduler_src and "'*.html'" not in scheduler_src, (
        'scheduler.py must not stage files via a wildcard -- use git_ops.scoped_add() with an explicit list'
    )
    workflow_path = os.path.join(ROOT, '.github', 'workflows', 'seo-campaign-publish.yml')
    if os.path.isfile(workflow_path):
        workflow_src = open(workflow_path, encoding='utf-8').read()
        assert '"*.html"' not in workflow_src and "'*.html'" not in workflow_src, (
            'seo-campaign-publish.yml must not stage files via a wildcard'
        )


TESTS = [v for k, v in list(globals().items()) if k.startswith('test_')]


def main():
    failed = []
    for t in TESTS:
        try:
            t()
            print(f'PASS {t.__name__}')
        except AssertionError as e:
            failed.append(t.__name__)
            print(f'FAIL {t.__name__}: {e}')
    if failed:
        print(f'\n{len(failed)}/{len(TESTS)} failed')
        return 1
    print(f'\nAll {len(TESTS)} git_ops tests passed.')
    return 0


if __name__ == '__main__':
    import sys
    sys.exit(main())
