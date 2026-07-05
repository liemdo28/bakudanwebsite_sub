"""SSH diagnostic - check remote server config"""
import paramiko, sys
from _deploy_static_pages import HOST, PORT, USER, PASS, REMOTE_WR

if not PASS:
    raise RuntimeError('Set BAKUDAN_SFTP_PASS before connecting.')
REMOTE = REMOTE_WR

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
print(f'Connecting to {HOST}...')
sys.stdout.flush()
ssh.connect(HOST, port=PORT, username=USER, password=PASS, timeout=30)

cmds = [
    f'cat {REMOTE}/.htaccess',
    f'ls {REMOTE}/',
    'ps aux | grep node | grep -v grep | head -5',
]

for c in cmds:
    print(f'\n=== {c[:80]} ===')
    sys.stdout.flush()
    _, o, e = ssh.exec_command(c)
    out = o.read().decode('utf-8', errors='replace')
    err = e.read().decode('utf-8', errors='replace')
    sys.stdout.buffer.write((out or '(empty)').encode('utf-8'))
    sys.stdout.buffer.write(b'\n')
    if err.strip():
        sys.stdout.buffer.write(('[stderr] ' + err).encode('utf-8'))
    sys.stdout.flush()

ssh.close()
print('\nDone.')
