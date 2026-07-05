"""
Deploy all static Linktree sub-pages to bakudanramen.com via SFTP.
Each page is a standalone HTML file in its own directory, which Apache
serves directly (bypassing WordPress rewrites because the directory is real).

Pages deployed:
  /links-temp/       → links-temp/index.html
  /store-locations/  → store-locations/index.html
  /order-smart/      → order-smart/index.html
  /reservations/     → reservations/index.html
"""
import paramiko, os
from pathlib import Path

HOST      = os.environ.get('BAKUDAN_SFTP_HOST', 'pdx1-shared-a3-05.dreamhost.com')
PORT      = int(os.environ.get('BAKUDAN_SFTP_PORT', '22'))
USER      = os.environ.get('BAKUDAN_SFTP_USER', 'hoale24new')
PASS      = os.environ.get('BAKUDAN_SFTP_PASS')
LOCAL_SRC = os.environ.get('BAKUDAN_LOCAL_SRC', str(Path(__file__).resolve().parents[1]))
REMOTE_WR = '/home/hoale24new/bakudanramen.com'

PAGES = [
    ('links-temp',      'links-temp/index.html'),
    ('store-locations', 'store-locations/index.html'),
    ('order-smart',     'order-smart/index.html'),
    ('reservations',    'reservations/index.html'),
]

def ensure_dir(sftp, path):
    try:
        sftp.stat(path)
    except FileNotFoundError:
        sftp.mkdir(path)
        print(f'  mkdir {path}')

def main():
    if not PASS:
        raise RuntimeError('Set BAKUDAN_SFTP_PASS before deploying.')
    print(f'Connecting to {HOST}:{PORT} as {USER}...')
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, port=PORT, username=USER, password=PASS, timeout=30)
    sftp = ssh.open_sftp()
    print('Connected.\n')

    for (remote_dir, local_rel) in PAGES:
        local_path  = os.path.join(LOCAL_SRC, local_rel.replace('/', os.sep))
        remote_dir_path  = REMOTE_WR + '/' + remote_dir
        remote_file_path = REMOTE_WR + '/' + remote_dir + '/index.html'

        ensure_dir(sftp, remote_dir_path)
        sftp.put(local_path, remote_file_path)

        stat   = sftp.stat(remote_file_path)
        size_k = round(stat.st_size / 1024, 1)
        print(f'  OK /{remote_dir}/  ({size_k} KB)  ->  {remote_file_path}')

    sftp.close()
    ssh.close()
    print('\nAll pages deployed.')
    print()
    for (d, _) in PAGES:
        print(f'  https://bakudanramen.com/{d}/')

if __name__ == '__main__':
    main()
