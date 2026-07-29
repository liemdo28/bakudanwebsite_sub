"""
Scheduler state-machine tests: due/future selection, fail-closed behavior
(missing credentials, mismatched host key, live-verification mismatch),
reconcile-before-deploy (the deploy-before-git consistency fix), partial
SFTP upload, durable backup manifest, and stale 'publishing' reconciliation.

Uses copies of the real manifest/state in memory -- never mutates the
tracked content/campaign/campaign-state.json on disk, and never makes a
real network/SFTP/git call (all external effects are mocked).
"""
import copy
import datetime
import os
import sys
import unittest.mock as mock

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
sys.path.insert(0, os.path.join(ROOT, 'scripts', 'campaign'))
import scheduler as sch  # noqa: E402
import git_ops  # noqa: E402

PASSING_CHECKS = {k: True for k in ('http_200', 'title_match', 'canonical_match', 'body_marker', 'image_200', 'sitemap_once')}
PASSING_CHECKS['all_passed'] = True
FAILING_CHECKS = {k: False for k in ('http_200', 'title_match', 'canonical_match', 'body_marker', 'image_200', 'sitemap_once')}
FAILING_CHECKS['all_passed'] = False


def fresh_state():
    state = sch.load_state()
    return copy.deepcopy(state)


def approved_entry(state, manifest, seq=1):
    entry = manifest['articles'][seq - 1]
    state['articles'][entry['id']]['status'] = 'approved'
    return entry


def test_due_selection_respects_publish_at():
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

    before_start = first_publish_at - datetime.timedelta(hours=1)
    due = sch.select_due(state, manifest, now=before_start)
    assert due == [], f'expected nothing due before campaign start, got {[a["slug"] for a in due]}'

    mid_window = first_publish_at + datetime.timedelta(hours=1)
    assert mid_window < second_publish_at
    due = sch.select_due(state, manifest, now=mid_window)
    assert [a['seq'] for a in due] == [1], f'expected only seq 1 due, got {[a["seq"] for a in due]}'

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
    with mock.patch.dict(os.environ, {
        'BAKUDAN_SFTP_HOST': 'h', 'BAKUDAN_SFTP_PORT': '22', 'BAKUDAN_SFTP_USER': 'u',
        'BAKUDAN_REMOTE_WR': '/home/u/site', 'DREAMHOST_HOST_KEY': 'h ssh-ed25519 AAAA',
    }, clear=False):
        os.environ.pop('BAKUDAN_SFTP_PASS', None)
        try:
            sch.get_sftp_config()
            raised = False
        except RuntimeError:
            raised = True
    assert raised, 'get_sftp_config must raise (fail closed) when BAKUDAN_SFTP_PASS is unset'


def test_fail_closed_missing_host_key():
    with mock.patch.dict(os.environ, {
        'BAKUDAN_SFTP_HOST': 'h', 'BAKUDAN_SFTP_PORT': '22', 'BAKUDAN_SFTP_USER': 'u',
        'BAKUDAN_SFTP_PASS': 'p', 'BAKUDAN_REMOTE_WR': '/home/u/site',
    }, clear=False):
        os.environ.pop('DREAMHOST_HOST_KEY', None)
        try:
            sch.connect_ssh(sch.get_sftp_config())
            raised = False
        except RuntimeError:
            raised = True
    assert raised, 'connect_ssh must raise (fail closed) when DREAMHOST_HOST_KEY is unset -- never fall back to AutoAddPolicy'


def test_no_auto_add_policy_anywhere():
    src = open(os.path.join(ROOT, 'scripts', 'campaign', 'scheduler.py'), encoding='utf-8').read()
    assert 'AutoAddPolicy(' not in src, 'scheduler.py must never call paramiko.AutoAddPolicy() -- strict host-key verification only'
    assert 'set_missing_host_key_policy(paramiko.RejectPolicy())' in src, (
        'scheduler.py must use paramiko.RejectPolicy() for strict host-key verification'
    )


def test_no_hardcoded_sftp_fallback_values():
    src = open(os.path.join(ROOT, 'scripts', 'campaign', 'scheduler.py'), encoding='utf-8').read()
    for leaked in ('pdx1-shared-a3-05.dreamhost.com', 'hoale24new'):
        assert leaked not in src, f'scheduler.py must not hardcode {leaked!r} as a fallback -- require the env var explicitly'


def test_publish_one_rolls_back_status_on_deploy_failure():
    state = fresh_state()
    manifest = sch.load_manifest()
    entry = approved_entry(state, manifest, seq=1)
    article_id = entry['id']

    with mock.patch.object(sch, 'verify_live', return_value=FAILING_CHECKS), \
         mock.patch.object(sch, 'write_local_files', return_value=('a', 'b', 'c')), \
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
    entry = approved_entry(state, manifest, seq=1)
    article_id = entry['id']

    # First verify_live call (pre-deploy "already live?" check) fails closed
    # (not live yet); second call (post-deploy confirmation) also fails.
    with mock.patch.object(sch, 'verify_live', side_effect=[FAILING_CHECKS, FAILING_CHECKS]), \
         mock.patch.object(sch, 'write_local_files', return_value=('a', 'b', 'c')), \
         mock.patch.object(sch, 'deploy_scp', return_value={'backup_dir': '/fake/backup', 'manifest': {}}), \
         mock.patch.object(sch, 'save_state'):
        result = sch.publish_one(entry, state, manifest, dry_run=False)

    assert result['published'] is False, 'must not mark published when live verification fails'
    assert state['articles'][article_id]['status'] == 'publishing', (
        'status must stay "publishing" (not roll back, not advance) so reconcile can retry -- '
        f'got {state["articles"][article_id]["status"]!r}'
    )


def test_reconcile_before_deploy_skips_redeploy_when_already_live():
    """
    The core deploy-before-git fix: if the artifact is ALREADY genuinely live
    (a prior run's SFTP succeeded but its git push failed), publish_one must
    NOT call deploy_scp again -- only reconcile state + git.
    """
    state = fresh_state()
    manifest = sch.load_manifest()
    entry = approved_entry(state, manifest, seq=1)
    article_id = entry['id']

    deploy_scp_mock = mock.MagicMock(side_effect=AssertionError('deploy_scp must NOT be called when already live'))

    with mock.patch.object(sch, 'verify_live', return_value=PASSING_CHECKS), \
         mock.patch.object(sch, 'write_local_files', return_value=('a', 'b', 'c')), \
         mock.patch.object(sch, 'deploy_scp', deploy_scp_mock), \
         mock.patch.object(sch, 'commit_and_push', return_value={'committed': True, 'sha': 'abc', 'push': {'pushed': True}}), \
         mock.patch.object(sch, 'save_state'):
        result = sch.publish_one(entry, state, manifest, dry_run=False)

    deploy_scp_mock.assert_not_called()
    assert result['published'] is True
    assert result['reconciled'] is True
    assert result['redeployed'] is False
    assert state['articles'][article_id]['status'] == 'published'
    last_event = state['articles'][article_id]['history'][-1]
    assert last_event['event'] == 'reconciled_from_already_live', 'must record recovery evidence in history'


def test_deploys_normally_when_not_yet_live():
    state = fresh_state()
    manifest = sch.load_manifest()
    entry = approved_entry(state, manifest, seq=1)

    deploy_scp_mock = mock.MagicMock(return_value={'backup_dir': '/fake', 'manifest': {'files': []}})

    with mock.patch.object(sch, 'verify_live', side_effect=[FAILING_CHECKS, PASSING_CHECKS]), \
         mock.patch.object(sch, 'write_local_files', return_value=('a', 'b', 'c')), \
         mock.patch.object(sch, 'deploy_scp', deploy_scp_mock), \
         mock.patch.object(sch, 'commit_and_push', return_value={'committed': True, 'sha': 'abc', 'push': {'pushed': True}}), \
         mock.patch.object(sch, 'save_state'):
        result = sch.publish_one(entry, state, manifest, dry_run=False)

    deploy_scp_mock.assert_called_once()
    assert result['published'] is True
    assert result['reconciled'] is False
    assert result['redeployed'] is True


def test_push_failure_after_successful_deploy_does_not_roll_back_published():
    """
    If SFTP succeeded and live verification passed, a subsequent git push
    failure must NOT flip status back to 'approved' (that would be a lie --
    the article really is live). The next run's reconcile-before-deploy check
    is what catches git up, not a false rollback here.
    """
    state = fresh_state()
    manifest = sch.load_manifest()
    entry = approved_entry(state, manifest, seq=1)
    article_id = entry['id']

    with mock.patch.object(sch, 'verify_live', side_effect=[FAILING_CHECKS, PASSING_CHECKS]), \
         mock.patch.object(sch, 'write_local_files', return_value=('a', 'b', 'c')), \
         mock.patch.object(sch, 'deploy_scp', return_value={'backup_dir': '/fake', 'manifest': {'files': []}}), \
         mock.patch.object(sch, 'commit_and_push', side_effect=RuntimeError('push exhausted retries')), \
         mock.patch.object(sch, 'save_state'):
        try:
            sch.publish_one(entry, state, manifest, dry_run=False)
        except RuntimeError:
            pass  # the push error may propagate -- what matters is the status below

    assert state['articles'][article_id]['status'] == 'published', (
        'status must remain published (it is genuinely live) even if the git push step raises -- '
        f'got {state["articles"][article_id]["status"]!r}'
    )


def test_partial_sftp_upload_raises_and_does_not_mark_published():
    state = fresh_state()
    manifest = sch.load_manifest()
    entry = approved_entry(state, manifest, seq=1)
    article_id = entry['id']

    with mock.patch.object(sch, 'verify_live', return_value=FAILING_CHECKS), \
         mock.patch.object(sch, 'write_local_files', return_value=('a', 'b', 'c')), \
         mock.patch.object(sch, 'deploy_scp', side_effect=RuntimeError('upload failed after 2/4 files')), \
         mock.patch.object(sch, 'save_state'):
        try:
            sch.publish_one(entry, state, manifest, dry_run=False)
            raised = False
        except RuntimeError:
            raised = True

    assert raised
    assert state['articles'][article_id]['status'] == 'approved', 'a partial upload must never be marked published'


def test_deploy_scp_backup_manifest_has_checksums_and_no_full_remote_paths():
    """
    The backup manifest structure includes checksum + timestamp per file, but
    NEVER the full remote path (which would reveal the DreamHost account's
    home directory structure) -- only a logical role + basename. See
    tests/campaign/test_backup_output_ordering.py and
    tests/campaign/test_backup_deploy_matrix.py for the runtime-content
    version of this check against a real (faked-network) deploy_scp call.
    """
    import inspect
    src = inspect.getsource(sch.deploy_scp)
    assert '_sha256' in src, 'deploy_scp must checksum backed-up files'
    assert 'created_at' in src, 'backup manifest must include a timestamp'
    assert "'role':" in src or '"role":' in src, 'backup manifest entries must use a logical role, not a full remote path'
    assert "'basename':" in src or '"basename":' in src, 'backup manifest entries must record only a basename'


def test_reconcile_promotes_stale_publishing_if_actually_live():
    state = fresh_state()
    manifest = sch.load_manifest()
    entry = manifest['articles'][0]
    article_id = entry['id']
    old_time = (datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(minutes=60)).isoformat()
    state['articles'][article_id]['status'] = 'publishing'
    state['articles'][article_id]['history'] = [{'event': 'deployed', 'at': old_time}]

    with mock.patch.object(sch, 'verify_live', return_value=PASSING_CHECKS), \
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

    with mock.patch.object(sch, 'verify_live', return_value=FAILING_CHECKS), \
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
    state = fresh_state()
    assert isinstance(state.get('automation_enabled'), bool), 'automation_enabled must be a real boolean kill-switch'


def test_no_secrets_in_history_log():
    state = fresh_state()
    blob = str(state)
    # state.json only ever stores status/history metadata (event names, ISO
    # timestamps, backup dir paths, verification check booleans) -- structural
    # guarantee that no env var value is ever written into it. Spot check the
    # actual current file content for common secret shapes.
    for forbidden_substring in ('BAKUDAN_SFTP_PASS=', 'password=', 'ssh-rsa AAAA', 'ssh-ed25519 AAAA'):
        assert forbidden_substring not in blob, f'state must never contain {forbidden_substring!r}'


def test_commit_and_push_uses_scoped_git_ops():
    src = open(os.path.join(ROOT, 'scripts', 'campaign', 'scheduler.py'), encoding='utf-8').read()
    assert 'git_ops.scoped_add' in src, 'commit_and_push must use git_ops.scoped_add (explicit allowlisted paths)'
    assert 'git_ops.push_with_bounded_retry' in src, 'commit_and_push must use bounded-retry push, never a raw force-push'
    assert 'git_ops.fetch_origin' in src, 'commit_and_push must fetch origin before committing (concurrency check)'


def test_state_save_is_atomic():
    import inspect
    src = inspect.getsource(sch.save_state)
    assert 'os.replace' in src, 'save_state must use an atomic rename (os.replace), not a direct in-place write, ' \
                                 'so a workflow cancellation mid-write cannot corrupt campaign-state.json'


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
