"""
Deploy links-temp/index.html to bakudanramen.com production via SFTP.
Creates /links-temp/ directory on the server and uploads the static file.
"""
import paramiko, os
from pathlib import Path

HOST   = os.environ.get('BAKUDAN_SFTP_HOST', 'pdx1-shared-a3-05.dreamhost.com')
PORT   = int(os.environ.get('BAKUDAN_SFTP_PORT', '22'))
USER   = os.environ.get('BAKUDAN_SFTP_USER', 'hoale24new')
PASS   = os.environ.get('BAKUDAN_SFTP_PASS')
LOCAL  = str(Path(__file__).resolve().parents[1] / 'links-temp' / 'index.html')
REMOTE_DIR = '/home/hoale24new/bakudanramen.com/links-temp'
REMOTE_FILE = REMOTE_DIR + '/index.html'

def main():
    if not PASS:
        raise RuntimeError('Set BAKUDAN_SFTP_PASS before deploying.')
    print(f'Connecting to {HOST}:{PORT} as {USER}...')
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, port=PORT, username=USER, password=PASS, timeout=30)
    sftp = ssh.open_sftp()
    print('Connected.')

    # Create remote directory if it doesn't exist
    try:
        sftp.stat(REMOTE_DIR)
        print(f'Directory {REMOTE_DIR} already exists.')
    except FileNotFoundError:
        sftp.mkdir(REMOTE_DIR)
        print(f'Created directory {REMOTE_DIR}')

    # Upload the file
    print(f'Uploading {LOCAL} -> {REMOTE_FILE} ...')
    sftp.put(LOCAL, REMOTE_FILE)
    print('Upload complete.')

    # Verify
    stat = sftp.stat(REMOTE_FILE)
    size_kb = round(stat.st_size / 1024, 1)
    print(f'Remote file size: {size_kb} KB')

    sftp.close()
    ssh.close()
    print('\nDone. URL: https://bakudanramen.com/links-temp/')

if __name__ == '__main__':
    main()
