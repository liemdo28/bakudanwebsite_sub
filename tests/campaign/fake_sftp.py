"""
A minimal fake SFTP/SSH client for exercising scheduler.deploy_scp()'s real
control flow (backup-then-upload, manifest phases, close-on-exit) without any
real network call. Supports configurable failure points so tests can prove
exactly what happens when the Nth get()/put() call fails.
"""
import io
import os


class FakeSFTPAttr:
    def __init__(self, size=123, mtime=0):
        self.st_size = size
        self.st_mtime = mtime
        self.st_mode = 0o100644


class FakeSFTPClient:
    def __init__(self, existing_remote_files=None, fail_get_at=None, fail_put_at=None, fail_stat_dirs=False):
        """
        existing_remote_files: set of remote paths that "exist" on the fake
          server (so .get()/.stat() succeed for them; anything else raises
          FileNotFoundError, matching real SFTP semantics for a new file).
        fail_get_at: 1-based call index at which .get() should raise (None = never).
        fail_put_at: 1-based call index at which .put() should raise (None = never).
        fail_stat_dirs: if True, .stat() on a directory always raises
          FileNotFoundError (forces the mkdir() branch to run).
        """
        self.existing_remote_files = existing_remote_files or set()
        self.fail_get_at = fail_get_at
        self.fail_put_at = fail_put_at
        self.fail_stat_dirs = fail_stat_dirs
        self.get_calls = []
        self.put_calls = []
        self.mkdir_calls = []
        self.stat_calls = []
        self.closed = False

    def get(self, remote_path, local_path):
        self.get_calls.append(remote_path)
        if self.fail_get_at is not None and len(self.get_calls) == self.fail_get_at:
            raise ConnectionResetError('simulated: connection reset during download')
        if remote_path not in self.existing_remote_files:
            raise FileNotFoundError(remote_path)
        with open(local_path, 'w', encoding='utf-8') as f:
            f.write('fake pre-existing remote content')

    def put(self, local_path, remote_path):
        self.put_calls.append(remote_path)
        if self.fail_put_at is not None and len(self.put_calls) == self.fail_put_at:
            raise OSError('simulated: SFTP put failed partway (connection lost)')
        self.existing_remote_files.add(remote_path)

    def stat(self, path):
        self.stat_calls.append(path)
        if path.endswith('.html') or path.endswith('.xml') or path.endswith('.webp'):
            if path not in self.existing_remote_files and (self.fail_get_at or path not in self.existing_remote_files):
                if path not in self.existing_remote_files:
                    raise FileNotFoundError(path)
            return FakeSFTPAttr()
        if self.fail_stat_dirs:
            raise FileNotFoundError(path)
        return FakeSFTPAttr()

    def mkdir(self, path):
        self.mkdir_calls.append(path)

    def close(self):
        self.closed = True


class FakeSSHClient:
    def __init__(self, sftp_client):
        self._sftp_client = sftp_client
        self.closed = False

    def open_sftp(self):
        return self._sftp_client

    def close(self):
        self.closed = True
