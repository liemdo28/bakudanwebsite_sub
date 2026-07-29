"""
Proves the deploy-before-git fix survives the actual failure scenario it was
built for: SFTP succeeds, but the git push never lands (ephemeral runner is
destroyed before/during the push). The next run must NOT reuse run 1's
in-memory state or working tree -- it must start from an independent copy of
the files representing what origin/main actually has (i.e. WITHOUT run 1's
uncommitted local changes, since those were lost), detect the artifact is
already genuinely live, and reconcile without a duplicate deploy.

Two genuinely separate temporary directories stand in for two separate
checkouts. scheduler.py's module-level path constants are repointed at
whichever workspace is "current" for each run -- run 2 never sees run 1's
Python objects or filesystem state, only a fresh copy of pre-run-1 content
(STATE_PATH/BLOG_HTML_PATH/SITEMAP_PATH), while a single shared fake "live
server" dict persists across both runs (since the real DreamHost server and
Google-visible site don't get reset just because a runner does).
"""
import copy
import json
import os
import shutil
import sys
import tempfile
import unittest.mock as mock

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
sys.path.insert(0, os.path.join(ROOT, 'scripts', 'campaign'))
import scheduler as sch  # noqa: E402

TEST_SEQ = 2  # article #2: genuinely 'approved' and not yet live in the real repo, a clean subject
TEST_SLUG = 'bakudans-three-locations-compared'


def make_workspace(base_dir, blog_html_content, sitemap_xml_content, state_dict):
    """A fresh, independent copy of exactly the mutable files scheduler.py touches."""
    os.makedirs(base_dir, exist_ok=True)
    blog_path = os.path.join(base_dir, 'blog.html')
    sitemap_path = os.path.join(base_dir, 'sitemap.xml')
    state_path = os.path.join(base_dir, 'campaign-state.json')
    with open(blog_path, 'w', encoding='utf-8') as f:
        f.write(blog_html_content)
    with open(sitemap_path, 'w', encoding='utf-8') as f:
        f.write(sitemap_xml_content)
    with open(state_path, 'w', encoding='utf-8') as f:
        json.dump(state_dict, f, indent=2)
    return blog_path, sitemap_path, state_path


def test_second_run_from_fresh_checkout_reconciles_without_duplicate_deploy():
    real_manifest = sch.load_manifest()
    entry = next(a for a in real_manifest['articles'] if a['seq'] == TEST_SEQ)
    assert entry['slug'] == TEST_SLUG

    real_state = sch.load_state()
    original_status = real_state['articles'][entry['id']]['status']

    # The "pre-run-1" snapshot of origin/main: no campaign card for this
    # article yet, article still 'approved'.
    pristine_blog_html = '<div class="blog-grid">\n</div>\n'
    pristine_sitemap_xml = '<?xml version="1.0"?><urlset></urlset>'
    pristine_state = copy.deepcopy(real_state)
    pristine_state['articles'][entry['id']]['status'] = 'approved'
    pristine_state['articles'][entry['id']]['history'] = []

    # Shared "live world" -- represents the real DreamHost server + what a
    # verify_live() HTTP check would see. Starts NOT live; run 1's successful
    # SFTP deploy flips it. This is the one piece of state that legitimately
    # persists across both runs, because it's not local to any checkout.
    live_world = {'is_live': False}

    def fake_verify_live(article, retries=1, delay_seconds=0):
        passing = {k: True for k in ('http_200', 'title_match', 'canonical_match', 'body_marker', 'image_200', 'sitemap_once')}
        failing = {k: False for k in passing}
        result = dict(passing if live_world['is_live'] else failing)
        result['all_passed'] = live_world['is_live']
        return result

    def fake_deploy_scp(article, local_files):
        # Simulates the real SFTP deploy succeeding and the article going live.
        live_world['is_live'] = True
        return {'backup_dir': '/fake/backup', 'manifest': {'files': []}}

    deploy_scp_calls = []

    def counting_deploy_scp(article, local_files):
        deploy_scp_calls.append(article['slug'])
        return fake_deploy_scp(article, local_files)

    with tempfile.TemporaryDirectory() as tmp:
        workspace1 = os.path.join(tmp, 'run1_ephemeral_checkout')
        workspace2 = os.path.join(tmp, 'run2_fresh_checkout')

        blog1, sitemap1, state1 = make_workspace(workspace1, pristine_blog_html, pristine_sitemap_xml, pristine_state)
        # workspace2 is ALSO built from the pristine pre-run-1 snapshot --
        # simulating that run 1's local commit never reached origin/main.
        blog2, sitemap2, state2 = make_workspace(workspace2, pristine_blog_html, pristine_sitemap_xml, pristine_state)

        # ---------------- RUN 1: deploy succeeds, "push" fails ----------------
        with mock.patch.object(sch, 'BLOG_HTML_PATH', blog1), \
             mock.patch.object(sch, 'SITEMAP_PATH', sitemap1), \
             mock.patch.object(sch, 'STATE_PATH', state1), \
             mock.patch.object(sch, 'ROOT', workspace1), \
             mock.patch.object(sch, 'verify_live', side_effect=fake_verify_live), \
             mock.patch.object(sch, 'deploy_scp', side_effect=counting_deploy_scp), \
             mock.patch.object(sch, 'commit_and_push', side_effect=RuntimeError('simulated: push exhausted retries, runner terminated')):

            state1_obj = sch.load_state()
            manifest_for_run1 = real_manifest  # manifest itself is immutable/shared, fine to reuse
            # write_local_files needs the real article json + hero image on disk;
            # article content lives under the REAL repo, not the temp workspace,
            # so point load_article_content-relative paths correctly by NOT
            # patching content/campaign/articles resolution -- only blog/sitemap/state
            # are workspace-local. To keep write_local_files() working without a
            # real hero image file at the temp ROOT, mock write_local_files'
            # image-independent parts by using the real article path for content
            # but redirecting only blog/sitemap output (already patched above).
            with mock.patch.object(sch, 'load_article_content', side_effect=lambda e: sch_load_article_content_from_real_repo(e)):
                try:
                    sch.publish_one(entry, state1_obj, manifest_for_run1, dry_run=False)
                    raised = False
                except RuntimeError:
                    raised = True

        assert raised, 'the simulated push failure must propagate out of run 1'
        assert live_world['is_live'] is True, 'run 1 must have actually deployed (the real failure mode: SFTP succeeded)'
        assert len(deploy_scp_calls) == 1

        run1_final_state = json.load(open(state1, encoding='utf-8'))
        assert run1_final_state['articles'][entry['id']]['status'] == 'published', (
            'run 1 local state correctly says published (it IS live) -- this is exactly what gets lost when the runner dies'
        )

        # ---------------- RUN 2: fresh checkout, must reconcile, not redeploy ----------------
        with mock.patch.object(sch, 'BLOG_HTML_PATH', blog2), \
             mock.patch.object(sch, 'SITEMAP_PATH', sitemap2), \
             mock.patch.object(sch, 'STATE_PATH', state2), \
             mock.patch.object(sch, 'ROOT', workspace2), \
             mock.patch.object(sch, 'verify_live', side_effect=fake_verify_live), \
             mock.patch.object(sch, 'deploy_scp', side_effect=counting_deploy_scp), \
             mock.patch.object(sch, 'commit_and_push', return_value={'committed': True, 'sha': 'fake2', 'push': {'pushed': True}}), \
             mock.patch.object(sch, 'load_article_content', side_effect=lambda e: sch_load_article_content_from_real_repo(e)):

            state2_obj = sch.load_state()  # loads from PRISTINE state2 -- still 'approved', no memory of run 1
            assert state2_obj['articles'][entry['id']]['status'] == 'approved', 'run 2 must start believing this is still approved (fresh checkout, push never landed)'

            result2 = sch.publish_one(entry, state2_obj, real_manifest, dry_run=False)

        assert len(deploy_scp_calls) == 1, (
            f'deploy_scp must NOT be called again in run 2 (it is already live) -- called {len(deploy_scp_calls)} times total'
        )
        assert result2['published'] is True
        assert result2.get('reconciled') is True
        assert result2.get('redeployed') is False

        final_blog_html = open(blog2, encoding='utf-8').read()
        final_sitemap_xml = open(sitemap2, encoding='utf-8').read()

        blog_card_count = final_blog_html.count(f'href="{TEST_SLUG}.html"')
        sitemap_entry_count = final_sitemap_xml.count(f'<loc>https://www.bakudanramen.com/{TEST_SLUG}.html</loc>')

        assert blog_card_count == 1, f'blog.html must link the article exactly once after reconciliation, found {blog_card_count}'
        assert sitemap_entry_count == 1, f'sitemap.xml must contain the canonical exactly once after reconciliation, found {sitemap_entry_count}'

        final_state2 = json.load(open(state2, encoding='utf-8'))
        assert final_state2['articles'][entry['id']]['status'] == 'published'
        history_events = [h['event'] for h in final_state2['articles'][entry['id']]['history']]
        assert 'reconciled_from_already_live' in history_events, 'recovery evidence must be recorded in history'

    # sanity: we never mutated the real repo's actual state/blog/sitemap files
    assert sch.load_state()['articles'][entry['id']]['status'] == original_status, (
        'this test must not have touched the real, tracked campaign-state.json'
    )


def sch_load_article_content_from_real_repo(entry):
    """Loads the real article JSON content (read-only, never mutated by this test) regardless of which fake ROOT is currently patched in."""
    path = os.path.join(ROOT, 'content', 'campaign', 'articles', f'{entry["seq"]:02d}-{entry["slug"]}.json')
    with open(path, encoding='utf-8') as f:
        return json.load(f)


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
        except Exception as e:
            failed.append(t.__name__)
            print(f'ERROR {t.__name__}: {e!r}')
    if failed:
        print(f'\n{len(failed)}/{len(TESTS)} failed: {failed}')
        return 1
    print(f'\nAll {len(TESTS)} fresh-checkout reconciliation tests passed.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
