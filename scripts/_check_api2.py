"""Check api/ directory htaccess and routing"""
import paramiko, sys
from _deploy_static_pages import HOST, PORT, USER, PASS, REMOTE_WR

if not PASS:
    raise RuntimeError('Set BAKUDAN_SFTP_PASS before connecting.')
REMOTE = REMOTE_WR

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, port=PORT, username=USER, password=PASS, timeout=30)

cmds = [
    f'cat {REMOTE}/api/.htaccess 2>/dev/null || echo NO-HTACCESS-IN-API',
    f'ls -la {REMOTE}/api/api/ 2>/dev/null',
    f'cat {REMOTE}/api/api/.htaccess 2>/dev/null || echo NO-HTACCESS-IN-API-API',
    # Test PHP directly
    f'curl -s http://localhost/api/admin/dashboard 2>/dev/null | head -50 || echo no-local-curl',
    f'ls -la /home/hoale24new/bakudan-app/data/ 2>/dev/null',
]

for c in cmds:
    print(f'\n=== {c[:80]} ===')
    sys.stdout.flush()
    _, o, e = ssh.exec_command(c)
    out = o.read().decode('utf-8', errors='replace')
    sys.stdout.buffer.write(out.encode('utf-8', errors='replace') or b'(empty)')
    sys.stdout.buffer.write(b'\n')
    sys.stdout.flush()

ssh.close()
