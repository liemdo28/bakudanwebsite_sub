"""
Tests for the read-only SFTP preflight diagnostic:
  - sftp_preflight() never contains a mutating SFTP call (source inspection --
    the strongest guarantee available without a live server)
  - is_target_path_safe() correctly accepts the real production path shape
    and rejects system directories / traversal / shallow paths (this locks
    in a real bug found and fixed during development: the original
    implementation used os.path.normpath, which uses backslash/ntpath
    semantics on Windows and silently mis-evaluated every POSIX remote path)
  - host_key_fingerprints() returns a sanitized fingerprint, never the raw
    key blob or anything resembling a credential
  - the preflight result never contains the raw remote path or credentials
"""
import inspect
import os
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
sys.path.insert(0, os.path.join(ROOT, 'scripts', 'campaign'))
import scheduler as sch  # noqa: E402

MUTATING_SFTP_CALLS = ('.put(', '.mkdir(', '.rename(', '.remove(', '.rmdir(', '.posix_rename(', '.symlink(', '.chmod(', '.truncate(')


def test_preflight_never_calls_mutating_sftp_methods():
    src = inspect.getsource(sch.sftp_preflight)
    offenders = [call for call in MUTATING_SFTP_CALLS if call in src]
    assert not offenders, f'sftp_preflight() must be strictly read-only, found: {offenders}'


def test_preflight_only_uses_stat():
    src = inspect.getsource(sch.sftp_preflight)
    assert '.stat(' in src, 'preflight should use stat() to check existence/metadata'
    assert '.open(' not in src, 'preflight must not open/read file CONTENTS, only stat() metadata'
    assert '.get(' not in src, 'preflight must not download file contents'


def test_target_path_accepts_real_production_shape():
    # This is the actual production target directory shape. Locks in the fix
    # for a real bug: os.path.normpath silently broke this check on Windows
    # (ntpath backslash semantics) during local development.
    ok, reason = sch.is_target_path_safe('/home/hoale24new/bakudanramen.com')
    assert ok, f'the real production target path shape must pass, got reason={reason!r}'


def test_target_path_rejects_system_directories():
    for dangerous in ('/', '/etc', '/bin', '/usr', '/root', '/var', '/home'):
        ok, reason = sch.is_target_path_safe(dangerous)
        assert not ok, f'{dangerous} must be refused as a deploy target'


def test_target_path_rejects_traversal():
    for bad in ('/home/../etc/passwd', '../relative', 'relative/path'):
        ok, reason = sch.is_target_path_safe(bad)
        assert not ok, f'{bad} must be refused'


def test_target_path_rejects_shallow_paths():
    ok, reason = sch.is_target_path_safe('/home/hoale24new')
    assert not ok, 'a two-segment path is too shallow to be a specific site document root'


def test_host_key_fingerprint_matches_independent_ssh_keygen_computation():
    os.environ['DREAMHOST_HOST_KEY'] = (
        'pdx1-shared-a3-05.dreamhost.com ssh-ed25519 '
        'AAAAC3NzaC1lZDI1NTE5AAAAIPa5+IX4bwHJnNvhO0hll6+uYM7Zc0kzEXiZ2OyzXPxQ'
    )
    try:
        fps = sch.host_key_fingerprints()
    finally:
        os.environ.pop('DREAMHOST_HOST_KEY', None)
    assert len(fps) == 1
    assert fps[0]['key_type'] == 'ssh-ed25519'
    # Independently computed via `ssh-keygen -lf` against the same key material
    # (see the hardening report) -- this is a cross-check, not a live network call.
    assert fps[0]['fingerprint'] == 'SHA256:IuO3r8KWrx3xcCM3nVBWk2eNvbFIKI0exWIJrrtd76Y'


def test_fingerprint_output_never_contains_raw_key_material():
    os.environ['DREAMHOST_HOST_KEY'] = (
        'pdx1-shared-a3-05.dreamhost.com ssh-ed25519 '
        'AAAAC3NzaC1lZDI1NTE5AAAAIPa5+IX4bwHJnNvhO0hll6+uYM7Zc0kzEXiZ2OyzXPxQ'
    )
    try:
        fps = sch.host_key_fingerprints()
    finally:
        os.environ.pop('DREAMHOST_HOST_KEY', None)
    blob = str(fps)
    assert 'AAAAC3NzaC1lZDI1NTE5' not in blob, 'fingerprint output must never contain the raw base64 key material'


def test_sanitize_error_redacts_configured_values():
    os.environ['BAKUDAN_SFTP_HOST'] = 'example-host.dreamhost.com'
    os.environ['BAKUDAN_REMOTE_WR'] = '/home/exampleuser/example.com'
    try:
        msg = sch._sanitize_error('connection to example-host.dreamhost.com failed, path /home/exampleuser/example.com not found')
    finally:
        os.environ.pop('BAKUDAN_SFTP_HOST', None)
        os.environ.pop('BAKUDAN_REMOTE_WR', None)
    assert 'example-host.dreamhost.com' not in msg
    assert '/home/exampleuser/example.com' not in msg
    assert 'REDACTED' in msg


def test_preflight_fails_closed_on_unsafe_target_without_connecting():
    """If the configured target path is unsafe, preflight must refuse before
    ever attempting to connect (no network call, no credential use)."""
    import unittest.mock as mock

    env = {
        'BAKUDAN_SFTP_HOST': 'h', 'BAKUDAN_SFTP_PORT': '22', 'BAKUDAN_SFTP_USER': 'u',
        'BAKUDAN_SFTP_PASS': 'p', 'BAKUDAN_REMOTE_WR': '/etc',  # unsafe on purpose
        'DREAMHOST_HOST_KEY': 'h ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIPa5+IX4bwHJnNvhO0hll6+uYM7Zc0kzEXiZ2OyzXPxQ',
    }
    connect_mock = mock.MagicMock(side_effect=AssertionError('must not connect when target path is unsafe'))
    with mock.patch.dict(os.environ, env, clear=False), \
         mock.patch.object(sch, 'connect_ssh', connect_mock):
        result = sch.sftp_preflight()

    connect_mock.assert_not_called()
    assert result['connected'] is False
    assert result['target_dir_within_safe_bounds'] is False
    assert result['error'] is not None


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
    print(f'\nAll {len(TESTS)} sftp_preflight tests passed.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
