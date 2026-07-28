"""
Scoped emergency deploy: pushes ONLY the two files needed to gate Broth Log
behind Basic Auth (.htaccess + .htpasswd-broth-log). Does not touch any other
remote file. Backs up the remote .htaccess before overwriting it.

Usage:
  python scripts/_deploy_broth_log_containment.py            # deploy
  python scripts/_deploy_broth_log_containment.py --dry-run  # show what would happen, no network calls
"""
import paramiko, os, sys, datetime
from pathlib import Path

HOST      = os.environ.get('BAKUDAN_SFTP_HOST', 'pdx1-shared-a3-05.dreamhost.com')
PORT      = int(os.environ.get('BAKUDAN_SFTP_PORT', '22'))
USER      = os.environ.get('BAKUDAN_SFTP_USER', 'hoale24new')
PASS      = os.environ.get('BAKUDAN_SFTP_PASS')
LOCAL_SRC = os.environ.get('BAKUDAN_LOCAL_SRC', str(Path(__file__).resolve().parents[1]))
REMOTE_WR = '/home/hoale24new/bakudanramen.com'

FILES = ['.htaccess', '.htpasswd-broth-log']

def main():
    dry_run = '--dry-run' in sys.argv

    print('Scoped deploy: Broth Log containment (Basic Auth gate)')
    print(f'  Files: {FILES}')
    print(f'  Target: {USER}@{HOST}:{PORT} -> {REMOTE_WR}')

    if dry_run:
        for f in FILES:
            local_path = os.path.join(LOCAL_SRC, f)
            exists = os.path.isfile(local_path)
            size = os.path.getsize(local_path) if exists else 0
            print(f'  [dry-run] would upload {f} ({size} bytes, local exists={exists}) -> {REMOTE_WR}/{f}')
        print('[dry-run] no network calls made, no remote changes.')
        return

    if not PASS:
        raise RuntimeError('Set BAKUDAN_SFTP_PASS before deploying.')

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, port=PORT, username=USER, password=PASS, timeout=30)
    sftp = ssh.open_sftp()
    print('Connected.\n')

    backup_dir = os.path.join(LOCAL_SRC, 'scripts', '_deploy_backups',
                               datetime.datetime.now().strftime('%Y%m%d-%H%M%S'))
    os.makedirs(backup_dir, exist_ok=True)
    try:
        sftp.get(REMOTE_WR + '/.htaccess', os.path.join(backup_dir, '.htaccess.bak'))
        print(f'  Backed up remote .htaccess -> {backup_dir}/.htaccess.bak')
    except FileNotFoundError:
        print('  (no existing remote .htaccess to back up)')

    for f in FILES:
        local_path = os.path.join(LOCAL_SRC, f)
        remote_path = REMOTE_WR + '/' + f
        sftp.put(local_path, remote_path)
        stat = sftp.stat(remote_path)
        print(f'  OK {f} ({round(stat.st_size / 1024, 2)} KB) -> {remote_path}')

    sftp.close()
    ssh.close()
    print('\nDeployed. Verify: an unauthenticated request to https://www.bakudanramen.com/broth-log.html')
    print('should now return 401, and the correct staff credentials should return 200.')

if __name__ == '__main__':
    main()
