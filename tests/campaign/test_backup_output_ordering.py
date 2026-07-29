"""
Reproduces and locks the durable-backup-lost-on-exception bug:

  Pre-fix, run() only calls _write_github_output('backup_dir', ...) AFTER
  publish_one() returns successfully. But deploy_scp() creates the backup
  directory and downloads remote originals BEFORE attempting any risky
  sftp.put() -- if put() (or anything else in the SFTP lifecycle) throws,
  deploy_scp() re-raises, publish_one() re-raises, and run() never reaches
  the _write_github_output() line at all. The workflow's artifact-upload
  step (conditioned on that output) sees an empty value and skips --
  exactly when the backup matters most (a failed deploy), it's lost with
  the ephemeral runner.

  The fix moves output-emission into deploy_scp() itself, immediately after
  the backup directory is created, before any operation that can still raise.
"""
import os
import sys
import unittest.mock as mock

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
sys.path.insert(0, os.path.join(ROOT, 'scripts', 'campaign'))
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import scheduler as sch  # noqa: E402
from fake_sftp import FakeSFTPClient, FakeSSHClient  # noqa: E402


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


def test_backup_dir_output_is_emitted_even_when_put_fails_partway():
    """
    Exercises deploy_scp()'s REAL control flow: connect succeeds, backup
    downloads succeed, and the FIRST sftp.put() fails -- matching the exact
    scenario in the bug report (backup dir already created, remote originals
    downloaded, put() fails partway, deploy_scp raises).
    """
    article, local_files = _real_article_and_files()

    fake_sftp = FakeSFTPClient(
        existing_remote_files={
            f'/home/hoale24new/bakudanramen.com/{article["slug"]}.html',
            '/home/hoale24new/bakudanramen.com/blog.html',
            '/home/hoale24new/bakudanramen.com/sitemap.xml',
            f'/home/hoale24new/bakudanramen.com/{article["image"]}',
        },
        fail_put_at=1,
    )
    fake_ssh = FakeSSHClient(fake_sftp)

    output_calls = []

    def fake_write_output(key, value):
        output_calls.append((key, value))

    with mock.patch.object(sch, '_write_github_output', side_effect=fake_write_output), \
         mock.patch.object(sch, 'get_sftp_config', return_value={
             'host': 'h', 'port': 22, 'user': 'u', 'password': 'p',
             'remote_wr': '/home/hoale24new/bakudanramen.com'}), \
         mock.patch.object(sch, 'connect_ssh', return_value=fake_ssh):
        try:
            sch.deploy_scp(article, local_files)
            raised = False
        except Exception:
            raised = True

    assert raised, 'the original SFTP put failure must still propagate -- the fix must not swallow it'
    assert len(fake_sftp.get_calls) >= 1, 'backup downloads must have been attempted before the put failure'
    assert len(fake_sftp.put_calls) == 1, 'exactly one put() attempt (the failing one) should have happened'

    backup_dir_outputs = [v for k, v in output_calls if k == 'backup_dir']
    assert backup_dir_outputs, (
        'BUG: backup_dir was never written to $GITHUB_OUTPUT even though the backup directory was '
        'created and originals were downloaded -- the artifact-upload step would skip and the backup '
        'is lost with the ephemeral runner on exactly the run where it matters most.'
    )
    assert os.path.isdir(os.path.join(ROOT, backup_dir_outputs[0])) or os.path.isdir(backup_dir_outputs[0]), (
        'the emitted backup_dir path must actually exist on disk'
    )
    # cleanup
    import shutil
    real_dir = backup_dir_outputs[0] if os.path.isabs(backup_dir_outputs[0]) else os.path.join(ROOT, backup_dir_outputs[0])
    if os.path.isdir(real_dir):
        shutil.rmtree(real_dir)


def test_backup_dir_output_written_before_first_network_call():
    """Ordering guarantee: output must be emitted before connect_ssh is even
    attempted, so a failure at the very first network step still leaves a
    durable, discoverable (even if empty) backup_dir."""
    article, local_files = _real_article_and_files()

    call_order = []

    def fake_write_output(key, value):
        call_order.append(('output', key))

    def fake_connect_ssh(config):
        call_order.append(('connect_ssh', None))
        raise RuntimeError('simulated network failure at the very first step')

    with mock.patch.object(sch, '_write_github_output', side_effect=fake_write_output), \
         mock.patch.object(sch, 'get_sftp_config', return_value={
             'host': 'h', 'port': 22, 'user': 'u', 'password': 'p',
             'remote_wr': '/home/hoale24new/bakudanramen.com'}), \
         mock.patch.object(sch, 'connect_ssh', side_effect=fake_connect_ssh):
        try:
            sch.deploy_scp(article, local_files)
        except RuntimeError:
            pass

    output_index = next((i for i, (kind, _) in enumerate(call_order) if kind == 'output'), None)
    connect_index = next((i for i, (kind, _) in enumerate(call_order) if kind == 'connect_ssh'), None)
    assert output_index is not None, 'backup_dir output must be written'
    assert connect_index is not None, 'connect_ssh must have been attempted'
    assert output_index < connect_index, 'backup_dir output must be written BEFORE the first network call, not after'

    # cleanup any directory created during this test
    import glob
    import shutil
    import time
    recent_cutoff = time.time() - 10
    for d in glob.glob(os.path.join(ROOT, 'scripts', '_deploy_backups', f'*-{article["slug"]}')):
        if os.path.getmtime(d) > recent_cutoff:
            shutil.rmtree(d, ignore_errors=True)


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
    print(f'\nAll {len(TESTS)} backup-output-ordering tests passed.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
