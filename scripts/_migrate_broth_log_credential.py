"""
One-off migration: move the Broth Log Apache credential file from inside the
public document root (where it was merely blocked by dotfile rules) to a
directory outside the docroot entirely, and stop tracking it in git. Reuses
the existing bcrypt hash -- no password rotation, per explicit instruction.

Steps:
  1. Create /home/hoale24new/.protected-htpasswd/ (outside REMOTE_WR)
  2. Upload the existing .htpasswd-broth-log content there as 'broth-log'
  3. Deploy the updated .htaccess (already points AuthUserFile at the new path)
  4. Delete the old copy from inside the docroot
  5. Verify: broth-log.html still 401 unauthenticated
"""
import os
import sys
import paramiko

HOST = os.environ.get('BAKUDAN_SFTP_HOST', 'pdx1-shared-a3-05.dreamhost.com')
PORT = int(os.environ.get('BAKUDAN_SFTP_PORT', '22'))
USER = os.environ.get('BAKUDAN_SFTP_USER', 'hoale24new')
PASS = os.environ.get('BAKUDAN_SFTP_PASS')
REMOTE_WR = '/home/hoale24new/bakudanramen.com'
PROTECTED_DIR = '/home/hoale24new/.protected-htpasswd'
LOCAL_SRC = os.path.dirname(os.path.abspath(__file__)) + '/..'


def main():
    if not PASS:
        raise RuntimeError('BAKUDAN_SFTP_PASS not set')

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, port=PORT, username=USER, password=PASS, timeout=30)
    sftp = ssh.open_sftp()

    try:
        sftp.stat(PROTECTED_DIR)
        print(f'{PROTECTED_DIR} already exists')
    except FileNotFoundError:
        sftp.mkdir(PROTECTED_DIR)
        print(f'created {PROTECTED_DIR}')

    local_htpasswd = os.path.join(LOCAL_SRC, '.htpasswd-broth-log')
    remote_new = f'{PROTECTED_DIR}/broth-log'
    sftp.put(local_htpasswd, remote_new)
    print(f'uploaded credential to {remote_new}')

    local_htaccess = os.path.join(LOCAL_SRC, '.htaccess')
    remote_htaccess = f'{REMOTE_WR}/.htaccess'
    sftp.put(local_htaccess, remote_htaccess)
    print(f'deployed updated .htaccess -> {remote_htaccess}')

    old_remote = f'{REMOTE_WR}/.htpasswd-broth-log'
    try:
        sftp.remove(old_remote)
        print(f'removed old in-docroot copy: {old_remote}')
    except FileNotFoundError:
        print('old in-docroot copy already absent')

    sftp.close()
    ssh.close()
    print('\nMigration complete. Verify manually:')
    print('  curl -I https://www.bakudanramen.com/broth-log.html   -> expect 401')
    print('  curl -u bakudan-staff:<pw> https://www.bakudanramen.com/broth-log.html -> expect 200')


if __name__ == '__main__':
    main()
