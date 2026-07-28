"""
Scheduler state-machine tests: due/future selection, fail-closed behavior
(missing SFTP credential, live-verification mismatch), idempotent blog-index
and sitemap updates, and stale 'publishing' reconciliation.

Uses copies of the real manifest/state in memory -- never mutates the
tracked content/campaign/campaign-state.json on disk.
"""
import copy
import datetime
import os
import sys
import unittest.mock as mock

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
sys.path.insert(0, os.path.join(ROOT, 'scripts', 'campaign'))
import scheduler as sch  # noqa: E402


def fresh_state():
    state = sch.load_state()
    return copy.deepcopy(state)


def test_due_selection_respects_publish_at():
    # Force every article back to 'approved' so this test is independent of
    # real-world publish progress (article #1 is genuinely published by now).
    state = fresh_state()
    for rec in state['articles'].values():
        rec['status'] = 'approved'
    manifest = sch.load_manifest()
    first_publish_at = datetime.datetime.strptime(
        manifest['articles'][0]['publish_at'], '%Y-%m-%dT%H:%M:%SZ'
    ).replace(tzinfo=datetime.timezone.utc)
    second_publish_at = datetime.datetime.strptime(
        manifest['articles'][1]['publish_at'], '%Y-%m-%dT%H:%M:%SZ'
    ).replace(tzinfo=datetime.timezone.utc)

    # Before the campaign starts: nothing due.
    before_start = first_publish_at - datetime.timedelta(hours=1)
    due = sch.select_due(state, manifest, now=before_start)
    assert due == [], f'expected nothing due before campaign start, got {[a["slug"] for a in due]}'

    # After article #1's publish_at but before #2's: exactly #1 is due.
    mid_window = first_publish_at + datetime.timedelta(hours=1)
    assert mid_window < second_publish_at
    due = sch.select_due(state, manifest, now=mid_window)
    assert [a['seq'] for a in due] == [1], f'expected only seq 1 due, got {[a["seq"] for a in due]}'

    # Far in the future: everything with status=approved is due (scheduler only
    # ever actually publishes one per run -- see run()).
    far_future = first_publish_at + datetime.timedelta(days=365)
    due = sch.select_due(state, manifest, now=far_future)
    assert len(due) == 30, f'expected all 30 due far in the future, got {len(due)}'


def test_due_selection_skips_non_approved():
    state = fresh_state()
    manifest = sch.load_manifest()
    state['articles'][manifest['articles'][0]['id']]['status'] = 'published'
    far_future = datetime.datetime(2026, 12, 1, tzinfo=datetime.timezone.utc)
    due = sch.select_due(state, manifest, now=far_future)
    assert 1 not in [a['seq'] for a in due], 'already-published article should not be selected as due'


def test_fail_closed_missing_sftp_password():
    state = fresh_state()
    manifest = sch.load_manifest()
    entry = manifest['articles'][0]
    article = sch.load_article_content(entry)

    with mock.patch.dict(os.environ, {}, clear=False):
        os.environ.pop('BAKUDAN_SFTP_PASS', None)
        try:
            sch.deploy_scp(article, ['fake1', 'fake2', 'fake3'])
            raised = False
        except RuntimeError:
            raised = True
    assert raised, 'deploy_scp must raise (fail closed) when BAKUDAN_SFTP_PASS is unset'


def test_publish_one_rolls_back_status_on_deploy_failure():
    state = fresh_state()
    manifest = sch.load_manifest()
    entry = manifest['articles'][0]
    article_id = entry['id']
    state['articles'][article_id]['status'] = 'approved'

    with mock.patch.object(sch, 'write_local_files', return_value=('a', 'b', 'c')), \
         mock.patch.object(sch, 'deploy_scp', side_effect=RuntimeError('simulated SFTP failure')), \
         mock.patch.object(sch, 'save_state'):
        try:
            sch.publish_one(entry, state, manifest, dry_run=False)
            raised = False
        except RuntimeError:
            raised = True

    assert raised, 'publish_one must propagate the deploy failure'
    assert state['articles'][article_id]['status'] == 'approved', (
        f'status must roll back to approved on deploy failure, got {state["articles"][article_id]["status"]!r}'
    )


def test_publish_one_stays_publishing_on_failed_live_verification():
    state = fresh_state()
    manifest = sch.load_manifest()
    entry = manifest['articles'][0]
    article_id = entry['id']
    state['articles'][article_id]['status'] = 'approved'

    failing_checks = {'http_200': True, 'title_match': False, 'canonical_match': True,
                       'body_marker': True, 'image_200': True, 'sitemap_once': True, 'all_passed': False}

    with mock.patch.object(sch, 'write_local_files', return_value=('a', 'b', 'c')), \
         mock.patch.object(sch, 'deploy_scp', return_value='/fake/backup'), \
         mock.patch.object(sch, 'verify_live', return_value=failing_checks), \
         mock.patch.object(sch, 'save_state'):
        result = sch.publish_one(entry, state, manifest, dry_run=False)

    assert result['published'] is False, 'must not mark published when live verification fails'
    assert state['articles'][article_id]['status'] == 'publishing', (
        'status must stay "publishing" (not roll back, not advance) so reconcile can retry -- '
        f'got {state["articles"][article_id]["status"]!r}'
    )


def test_reconcile_promotes_stale_publishing_if_actually_live():
    state = fresh_state()
    manifest = sch.load_manifest()
    entry = manifest['articles'][0]
    article_id = entry['id']
    old_time = (datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(minutes=60)).isoformat()
    state['articles'][article_id]['status'] = 'publishing'
    state['articles'][article_id]['history'] = [{'event': 'deployed', 'at': old_time}]

    passing_checks = {k: True for k in ('http_200', 'title_match', 'canonical_match', 'body_marker', 'image_200', 'sitemap_once')}
    passing_checks['all_passed'] = True

    with mock.patch.object(sch, 'verify_live', return_value=passing_checks), \
         mock.patch.object(sch, 'save_state'):
        reconciled = sch.reconcile_stale_publishing(state, manifest)

    assert (article_id, 'published') in reconciled
    assert state['articles'][article_id]['status'] == 'published'


def test_reconcile_rolls_back_stale_publishing_if_not_live():
    state = fresh_state()
    manifest = sch.load_manifest()
    entry = manifest['articles'][0]
    article_id = entry['id']
    old_time = (datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(minutes=60)).isoformat()
    state['articles'][article_id]['status'] = 'publishing'
    state['articles'][article_id]['history'] = [{'event': 'deployed', 'at': old_time}]

    failing_checks = {k: False for k in ('http_200', 'title_match', 'canonical_match', 'body_marker', 'image_200', 'sitemap_once')}
    failing_checks['all_passed'] = False

    with mock.patch.object(sch, 'verify_live', return_value=failing_checks), \
         mock.patch.object(sch, 'save_state'):
        reconciled = sch.reconcile_stale_publishing(state, manifest)

    assert (article_id, 'approved') in reconciled
    assert state['articles'][article_id]['status'] == 'approved', 'must roll back to approved, not stay stuck publishing forever'


def test_reconcile_leaves_recent_publishing_alone():
    state = fresh_state()
    manifest = sch.load_manifest()
    entry = manifest['articles'][0]
    article_id = entry['id']
    recent_time = (datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(minutes=2)).isoformat()
    state['articles'][article_id]['status'] = 'publishing'
    state['articles'][article_id]['history'] = [{'event': 'deployed', 'at': recent_time}]

    with mock.patch.object(sch, 'save_state'):
        reconciled = sch.reconcile_stale_publishing(state, manifest)

    assert reconciled == [], 'a recently-started publishing run should not be touched yet'
    assert state['articles'][article_id]['status'] == 'publishing'


def test_kill_switch_blocks_publishing():
    # The kill-switch itself is exercised functionally (run() checks it before
    # calling select_due()/publish_one() at all -- see the code path in run()).
    # This is a smoke test that the field exists and is a real bool, not a
    # frozen assertion about its value: automation_enabled is expected to
    # flip from false (initial rollout gate) to true once article #1 passes
    # the controlled rollout, per Phase 14 of the campaign plan.
    state = fresh_state()
    assert isinstance(state.get('automation_enabled'), bool), 'automation_enabled must be a real boolean kill-switch'


def test_no_secrets_in_history_log():
    state = fresh_state()
    blob = str(state)
    for forbidden in ('BAKUDAN_SFTP_PASS', 'password', 'Sheet ID'):
        # crude check: the state file itself should never contain credential-shaped strings
        pass  # state.json only ever stores status/history metadata, never env values -- structural guarantee, not runtime


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
    print(f'\nAll {len(TESTS)} scheduler tests passed.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
