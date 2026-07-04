"""Deploy api/.htaccess to fix /api/admin/* routing"""
import paramiko, pathlib, sys
from _deploy_static_pages import HOST, PORT, USER, PASS, REMOTE_WR

if not PASS:
    raise RuntimeError('Set BAKUDAN_SFTP_PASS before connecting.')
REMOTE = REMOTE_WR
LOCAL  = str(pathlib.Path(__file__).parent.parent)

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
print(f'Connecting {HOST}...')
ssh.connect(HOST, port=PORT, username=USER, password=PASS, timeout=30)
sftp = ssh.open_sftp()

local_file  = LOCAL + '/api/.htaccess'
remote_file = REMOTE + '/api/.htaccess'

sftp.put(local_file, remote_file)
stat = sftp.stat(remote_file)
print(f'OK  api/.htaccess  ({stat.st_size} bytes)')

# Verify
_stdin, o, _stderr = ssh.exec_command(f'cat {remote_file}')
content = o.read().decode('utf-8', errors='replace')
print(f'\nContent on server:\n{content}')

sftp.close()

# Quick test
print('\nTesting /api/admin/dashboard (expect JSON, not 404)...')
_stdin, o, _stderr = ssh.exec_command(
    'curl -s -o /dev/null -w "%{http_code}" https://www.bakudanramen.com/api/admin/dashboard'
)
code = o.read().decode().strip()
print(f'HTTP status: {code}')
if code == '401':
    print('  -> 401 Unauthorized = route EXISTS, auth required. FIXED!')
elif code == '200':
    print('  -> 200 OK. FIXED!')
else:
    print(f'  -> Still broken: {code}')

ssh.close()
