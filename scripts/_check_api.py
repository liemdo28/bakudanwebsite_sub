"""Check the api/ directory and server directory on production."""
import paramiko, sys
from _deploy_static_pages import HOST, PORT, USER, PASS, REMOTE_WR

if not PASS:
    raise RuntimeError('Set BAKUDAN_SFTP_PASS before connecting.')
REMOTE = REMOTE_WR

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, port=PORT, username=USER, password=PASS, timeout=30)

cmds = [
    f'ls -la {REMOTE}/api/',
    f'cat {REMOTE}/api/index.php 2>/dev/null || cat {REMOTE}/api/index.js 2>/dev/null || echo NO-INDEX',
    f'find {REMOTE}/api/ -type f | head -30',
    f'ls -la {REMOTE}/server/',
    f'cat {REMOTE}/server/server.js | grep "listen\\|PORT\\|app.use" | head -20',
]

for c in cmds:
    print(f'\n=== {c[:80]} ===')
    sys.stdout.flush()
    _, o, e = ssh.exec_command(c)
    out = o.read().decode('utf-8', errors='replace')
    sys.stdout.buffer.write(out.encode('utf-8', errors='replace'))
    sys.stdout.buffer.write(b'\n')
    sys.stdout.flush()

ssh.close()
