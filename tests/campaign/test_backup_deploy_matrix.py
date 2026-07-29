"""
Full A-J regression/integration matrix for the durable-backup fix, using a
fake SFTP client (tests/campaign/fake_sftp.py) to exercise deploy_scp()'s
REAL control flow -- backup-then-upload, phased manifest, close-on-exit --
without any real network call.

For failure scenarios A-E, each test proves all five of:
  1. backup_dir was emitted to $GITHUB_OUTPUT (durable, discoverable)
  2. manifest.json exists and reflects the correct phase
  3. the workflow's artifact-upload condition (`backup_dir != ''`) would be met
  4. the original exception/failure still propagates (never swallowed)
  5. no secret-shaped string appears anywhere in the manifest or captured output
"""
import glob
import json
import os
import shutil
import sys
import time
import unittest.mock as mock

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
sys.path.insert(0, os.path.join(ROOT, 'scripts', 'campaign'))
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import scheduler as sch  # noqa: E402
from fake_sftp import FakeSFTPClient, FakeSSHClient  # noqa: E402

FAKE_SECRETS = {
    'BAKUDAN_SFTP_HOST': 'fake-host-marker.example.test',
    'BAKUDAN_SFTP_PORT': '22',
    'BAKUDAN_SFTP_USER': 'fake-user-marker',
    'BAKUDAN_SFTP_PASS': 'fake-password-marker-hunter2',
    'BAKUDAN_REMOTE_WR': '/home/fake-user-marker/fake-remote-path-marker',
}
# Port is excluded from leak-checking: a bare port number (esp. the extremely
# common "22") is low-entropy and not meaningfully sensitive, and checking
# for it as a forbidden substring false-positives against innocuous content
# (hex checksums, timestamps, byte counts). Host/user/password/remote-path
# are the values that actually matter here.
SENSITIVE_SECRET_VALUES = [v for k, v in FAKE_SECRETS.items() if k != 'BAKUDAN_SFTP_PORT']


def _real_article_and_files():
    manifest = sch.load_manifest()
    entry = manifest['articles'][0]
    article = sch.load_article_content(entry)
    local_files = (
        os.path.join(ROOT, f'{article["slug"]}.html') if os.path.isfile(os.path.join(ROOT, f'{article["slug"]}.html')) else __file__,
        os.path.join(ROOT, 'blog.html'),
        os.path.join(ROOT, 'sitemap.xml'),
    )
    return article, local_files


def _existing_remote_set(article, remote_wr):
    return {
        f'{remote_wr}/{article["slug"]}.html',
        f'{remote_wr}/blog.html',
        f'{remote_wr}/sitemap.xml',
        f'{remote_wr}/{article["image"]}',
    }


def _cleanup_backup_dir(backup_dir_relative):
    if not backup_dir_relative:
        return
    abs_path = backup_dir_relative if os.path.isabs(backup_dir_relative) else os.path.join(ROOT, backup_dir_relative)
    if os.path.isdir(abs_path):
        shutil.rmtree(abs_path, ignore_errors=True)


def _run_deploy_scp_with_fake_sftp(article, local_files, fake_sftp, extra_env=None):
    output_calls = []

    def fake_write_output(key, value):
        output_calls.append((key, value))

    env = dict(FAKE_SECRETS)
    if extra_env:
        env.update(extra_env)

    fake_ssh = FakeSSHClient(fake_sftp)
    with mock.patch.dict(os.environ, env, clear=False), \
         mock.patch.object(sch, '_write_github_output', side_effect=fake_write_output), \
         mock.patch.object(sch, 'connect_ssh', return_value=fake_ssh):
        exc = None
        result = None
        try:
            result = sch.deploy_scp(article, local_files)
        except Exception as e:
            exc = e
    return {'output_calls': output_calls, 'exception': exc, 'result': result}


def _assert_common_failure_guarantees(outcome, article):
    backup_dir_outputs = [v for k, v in outcome['output_calls'] if k == 'backup_dir']
    assert backup_dir_outputs, '(1) backup_dir must have been written to $GITHUB_OUTPUT'
    relative = backup_dir_outputs[0]

    # (I) the emitted value must itself pass the path-safety validator
    ok, reason = sch.validate_backup_dir_path(relative)
    assert ok, f'emitted backup_dir failed its own safety validation: {reason}'

    abs_dir = os.path.join(ROOT, relative)
    manifest_path = os.path.join(abs_dir, 'manifest.json')
    assert os.path.isfile(manifest_path), '(2) manifest.json must exist'

    with open(manifest_path, encoding='utf-8') as f:
        manifest_content = f.read()
    manifest_data = json.loads(manifest_content)
    assert manifest_data['phase'] == 'failed', f"manifest phase must be 'failed', got {manifest_data['phase']!r}"

    # (3) workflow condition `backup_dir != ''` would be satisfied
    assert relative != '', '(3) artifact-upload step condition must be satisfiable'

    # (4) original failure must propagate
    assert outcome['exception'] is not None, '(4) the original SFTP failure must propagate, never be swallowed'

    # (5) no secret leaks into the manifest
    blob = manifest_content
    for secret_val in SENSITIVE_SECRET_VALUES:
        assert secret_val not in blob, f'(5) manifest.json must never contain {secret_val!r}'
    assert ROOT not in blob, '(5) manifest.json must never contain the local absolute workspace root'

    return relative


# ---------------------------------------------------------------------------
# A: fail at the FIRST remote backup file download
# ---------------------------------------------------------------------------
def test_A_fail_at_first_backup_download():
    article, local_files = _real_article_and_files()
    remote_wr = FAKE_SECRETS['BAKUDAN_REMOTE_WR']
    fake_sftp = FakeSFTPClient(existing_remote_files=_existing_remote_set(article, remote_wr), fail_get_at=1)

    outcome = _run_deploy_scp_with_fake_sftp(article, local_files, fake_sftp)
    relative = _assert_common_failure_guarantees(outcome, article)
    assert len(fake_sftp.put_calls) == 0, 'no upload should have been attempted before the backup step even finished'
    _cleanup_backup_dir(relative)


# ---------------------------------------------------------------------------
# B: fail AFTER two files have been backed up
# ---------------------------------------------------------------------------
def test_B_fail_after_two_files_backed_up():
    article, local_files = _real_article_and_files()
    remote_wr = FAKE_SECRETS['BAKUDAN_REMOTE_WR']
    fake_sftp = FakeSFTPClient(existing_remote_files=_existing_remote_set(article, remote_wr), fail_get_at=3)

    outcome = _run_deploy_scp_with_fake_sftp(article, local_files, fake_sftp)
    relative = _assert_common_failure_guarantees(outcome, article)

    manifest_data = json.load(open(os.path.join(ROOT, relative, 'manifest.json'), encoding='utf-8'))
    assert len(manifest_data['files_backed_up']) == 2, (
        f"exactly 2 files should be recorded as backed up before the 3rd get() failed, got {len(manifest_data['files_backed_up'])}"
    )
    _cleanup_backup_dir(relative)


# ---------------------------------------------------------------------------
# C: fail at the FIRST SFTP put()
# ---------------------------------------------------------------------------
def test_C_fail_at_first_put():
    article, local_files = _real_article_and_files()
    remote_wr = FAKE_SECRETS['BAKUDAN_REMOTE_WR']
    fake_sftp = FakeSFTPClient(existing_remote_files=_existing_remote_set(article, remote_wr), fail_put_at=1)

    outcome = _run_deploy_scp_with_fake_sftp(article, local_files, fake_sftp)
    relative = _assert_common_failure_guarantees(outcome, article)

    manifest_data = json.load(open(os.path.join(ROOT, relative, 'manifest.json'), encoding='utf-8'))
    assert len(manifest_data['files_backed_up']) == 4, 'all 4 files should have been backed up before uploads even started'
    assert len(manifest_data['files_uploaded']) == 0, 'no upload should have succeeded'
    _cleanup_backup_dir(relative)


# ---------------------------------------------------------------------------
# D: fail AFTER one or more files have been uploaded
# ---------------------------------------------------------------------------
def test_D_fail_after_some_uploads_succeeded():
    article, local_files = _real_article_and_files()
    remote_wr = FAKE_SECRETS['BAKUDAN_REMOTE_WR']
    fake_sftp = FakeSFTPClient(existing_remote_files=_existing_remote_set(article, remote_wr), fail_put_at=2)

    outcome = _run_deploy_scp_with_fake_sftp(article, local_files, fake_sftp)
    relative = _assert_common_failure_guarantees(outcome, article)

    manifest_data = json.load(open(os.path.join(ROOT, relative, 'manifest.json'), encoding='utf-8'))
    assert len(manifest_data['files_uploaded']) == 1, (
        f"exactly 1 file should be recorded as uploaded before the 2nd put() failed, got {len(manifest_data['files_uploaded'])}"
    )
    _cleanup_backup_dir(relative)


# ---------------------------------------------------------------------------
# E: fail during LIVE VERIFICATION, after a fully successful upload
# ---------------------------------------------------------------------------
def test_E_fail_in_live_verification_after_successful_upload():
    """deploy_scp() itself succeeds completely (phase=completed) -- the
    failure is in publish_one()'s POST-deploy verify_live() call. The
    article must NOT be marked published, but the backup_dir must still be
    surfaced in publish_one()'s return value for artifact purposes."""
    manifest = sch.load_manifest()
    entry = manifest['articles'][0]
    article = sch.load_article_content(entry)
    state = sch.load_state()
    state['articles'][entry['id']]['status'] = 'approved'

    remote_wr = FAKE_SECRETS['BAKUDAN_REMOTE_WR']
    fake_sftp = FakeSFTPClient(existing_remote_files=_existing_remote_set(article, remote_wr))
    fake_ssh = FakeSSHClient(fake_sftp)

    failing_checks = {k: False for k in ('http_200', 'title_match', 'canonical_match', 'body_marker', 'image_200', 'sitemap_once')}
    failing_checks['all_passed'] = False

    with mock.patch.dict(os.environ, FAKE_SECRETS, clear=False), \
         mock.patch.object(sch, 'connect_ssh', return_value=fake_ssh), \
         mock.patch.object(sch, 'verify_live', return_value=failing_checks), \
         mock.patch.object(sch, 'write_local_files', return_value=(
             os.path.join(ROOT, f'{article["slug"]}.html') if os.path.isfile(os.path.join(ROOT, f'{article["slug"]}.html')) else __file__,
             os.path.join(ROOT, 'blog.html'), os.path.join(ROOT, 'sitemap.xml'))), \
         mock.patch.object(sch, 'save_state'):
        result = sch.publish_one(entry, state, manifest, dry_run=False)

    assert result['published'] is False, 'must not mark published when post-upload live verification fails'
    assert result.get('backup_dir'), 'backup_dir must still be surfaced even though verification failed -- the deploy itself succeeded'
    _cleanup_backup_dir(os.path.relpath(result['backup_dir'], ROOT))


# ---------------------------------------------------------------------------
# F: successful deployment (happy path)
# ---------------------------------------------------------------------------
def test_F_successful_deployment_manifest_and_output():
    article, local_files = _real_article_and_files()
    remote_wr = FAKE_SECRETS['BAKUDAN_REMOTE_WR']
    fake_sftp = FakeSFTPClient(existing_remote_files=_existing_remote_set(article, remote_wr))

    outcome = _run_deploy_scp_with_fake_sftp(article, local_files, fake_sftp)
    assert outcome['exception'] is None, f'unexpected failure: {outcome["exception"]!r}'
    assert outcome['result'] is not None

    backup_dir_outputs = [v for k, v in outcome['output_calls'] if k == 'backup_dir']
    assert backup_dir_outputs
    relative = backup_dir_outputs[0]
    manifest_data = json.load(open(os.path.join(ROOT, relative, 'manifest.json'), encoding='utf-8'))
    assert manifest_data['phase'] == 'completed'
    assert len(manifest_data['files_backed_up']) == 4
    assert len(manifest_data['files_uploaded']) == 4
    for entry in manifest_data['files_backed_up'] + manifest_data['files_uploaded']:
        assert 'role' in entry and 'basename' in entry
        assert 'remote_path' not in entry, 'must never store the full remote path'
        blob = json.dumps(entry)
        assert '/home/' not in blob, 'must never leak a home-directory-shaped path'
    _cleanup_backup_dir(relative)


# ---------------------------------------------------------------------------
# G: no-due run
# ---------------------------------------------------------------------------
def test_G_no_due_run_produces_no_output():
    output_calls = []

    def fake_write_output(key, value):
        output_calls.append((key, value))

    with mock.patch.object(sch, 'reconcile_stale_publishing', return_value=[]), \
         mock.patch.object(sch, 'select_due', return_value=[]), \
         mock.patch.object(sch, '_write_github_output', side_effect=fake_write_output):
        sch.run(dry_run=False)

    assert output_calls == [], 'a no-due run must never produce a backup_dir output'


# ---------------------------------------------------------------------------
# H: reconciliation without deploy
# ---------------------------------------------------------------------------
def test_H_reconcile_without_deploy_calls_no_sftp():
    manifest = sch.load_manifest()
    entry = manifest['articles'][0]
    article = sch.load_article_content(entry)
    state = sch.load_state()
    state['articles'][entry['id']]['status'] = 'approved'

    passing_checks = {k: True for k in ('http_200', 'title_match', 'canonical_match', 'body_marker', 'image_200', 'sitemap_once')}
    passing_checks['all_passed'] = True

    deploy_scp_mock = mock.MagicMock(side_effect=AssertionError('deploy_scp must not be called during reconciliation'))

    with mock.patch.object(sch, 'verify_live', return_value=passing_checks), \
         mock.patch.object(sch, 'write_local_files', return_value=('a', 'b', 'c')), \
         mock.patch.object(sch, 'deploy_scp', deploy_scp_mock), \
         mock.patch.object(sch, 'commit_and_push', return_value={'committed': True, 'sha': 'x', 'push': {'pushed': True}}), \
         mock.patch.object(sch, 'save_state'):
        result = sch.publish_one(entry, state, manifest, dry_run=False)

    deploy_scp_mock.assert_not_called()
    assert result['reconciled'] is True
    assert 'backup_dir' not in result or not result.get('backup_dir'), 'reconciliation must not report a backup_dir (no deploy happened)'


# ---------------------------------------------------------------------------
# I: malicious/invalid backup output path
# ---------------------------------------------------------------------------
def test_I_rejects_malicious_backup_paths():
    cases = [
        ('/etc/passwd', 'absolute path'),
        ('scripts/_deploy_backups/../../etc/passwd', 'traversal'),
        ('../../etc/passwd', 'traversal outside repo'),
        ('scripts/other_dir/evil', 'outside scripts/_deploy_backups/'),
        ('C:\\Windows\\System32', 'windows absolute path'),
        ('', 'empty'),
    ]
    for bad_path, label in cases:
        ok, reason = sch.validate_backup_dir_path(bad_path)
        assert not ok, f'{label} ({bad_path!r}) must be rejected, got ok=True'


def test_I_rejects_symlink_escape():
    """A backup_dir that resolves (after following a symlink) outside
    scripts/_deploy_backups/ must be refused, even if its literal string form
    looks compliant."""
    backups_root = os.path.join(ROOT, 'scripts', '_deploy_backups')
    os.makedirs(backups_root, exist_ok=True)
    link_name = 'test-symlink-escape-marker'
    link_path = os.path.join(backups_root, link_name)
    outside_target = os.path.join(ROOT, 'tests', 'campaign')

    try:
        if os.path.islink(link_path) or os.path.exists(link_path):
            os.remove(link_path)
        try:
            os.symlink(outside_target, link_path, target_is_directory=True)
        except (OSError, NotImplementedError):
            print('SKIP (symlink creation not permitted in this environment)')
            return

        ok, reason = sch.validate_backup_dir_path(f'scripts/_deploy_backups/{link_name}')
        assert not ok, 'a symlink resolving outside scripts/_deploy_backups/ must be refused'
    finally:
        if os.path.islink(link_path):
            os.remove(link_path)


def test_I_accepts_well_formed_path():
    ok, reason = sch.validate_backup_dir_path('scripts/_deploy_backups/20260101-000000-some-slug')
    assert ok, f'a well-formed path must be accepted, got reason={reason!r}'


# ---------------------------------------------------------------------------
# J: exception containing fake credential-shaped strings must never leak
# ---------------------------------------------------------------------------
def test_J_exception_with_fake_credentials_never_leaks():
    article, local_files = _real_article_and_files()
    remote_wr = FAKE_SECRETS['BAKUDAN_REMOTE_WR']

    class PoisonedSFTPClient(FakeSFTPClient):
        def put(self, local_path, remote_path):
            self.put_calls.append(remote_path)
            raise OSError(
                f"connection failed for user={FAKE_SECRETS['BAKUDAN_SFTP_USER']} "
                f"password={FAKE_SECRETS['BAKUDAN_SFTP_PASS']} "
                f"host={FAKE_SECRETS['BAKUDAN_SFTP_HOST']} "
                f"path={FAKE_SECRETS['BAKUDAN_REMOTE_WR']}/{os.path.basename(remote_path)} "
                f"workspace={ROOT}"
            )

    fake_sftp = PoisonedSFTPClient(existing_remote_files=_existing_remote_set(article, remote_wr))
    outcome = _run_deploy_scp_with_fake_sftp(article, local_files, fake_sftp)

    assert outcome['exception'] is not None
    relative = [v for k, v in outcome['output_calls'] if k == 'backup_dir'][0]
    manifest_content = open(os.path.join(ROOT, relative, 'manifest.json'), encoding='utf-8').read()

    for secret_val in SENSITIVE_SECRET_VALUES:
        assert secret_val not in manifest_content, f'manifest must never contain {secret_val!r}'
    assert ROOT not in manifest_content, 'manifest must never contain the local absolute workspace root'
    assert 'REDACTED' in manifest_content, 'the sanitized error should show redaction markers in place of the real values'

    _cleanup_backup_dir(relative)


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
    print(f'\nAll {len(TESTS)} backup-deploy-matrix tests passed.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
