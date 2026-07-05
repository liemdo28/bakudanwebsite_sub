"""Deploy links/.htaccess to fix QR code 404"""
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

sftp.put(LOCAL + '/links/.htaccess', REMOTE + '/links/.htaccess')
stat = sftp.stat(REMOTE + '/links/.htaccess')
print(f'OK  links/.htaccess  ({stat.st_size} bytes)')

sftp.close()

# Test QR URL
print('\nTesting /links/bakudan-links-main ...')
_stdin, o, _stderr = ssh.exec_command(
    'curl -s -o /dev/null -w "%{http_code}" https://www.bakudanramen.com/links/bakudan-links-main'
)
code = o.read().decode().strip()
print(f'HTTP status: {code}')
if code == '200':
    print('  -> 200 OK. QR codes FIXED!')
else:
    print(f'  -> Status: {code}')

ssh.close()
