"""
P0 FIX DEPLOY — Upload server/routes/admin.js + server/server.js to DreamHost
Run: python scripts/_deploy_server_fix.py
"""
import paramiko, os, pathlib, sys
from _deploy_static_pages import HOST, PORT, USER, PASS, REMOTE_WR

_here = pathlib.Path(__file__).parent
LOCAL_SRC = str(_here.parent)

FILES_TO_DEPLOY = [
    (r'server\server.js',            'server/server.js'),
    (r'server\routes\admin.js',      'server/routes/admin.js'),
]

def ensure_remote_dir(sftp, path):
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

    # Ensure routes dir exists
    ensure_remote_dir(sftp, f'{REMOTE_WR}/server')
    ensure_remote_dir(sftp, f'{REMOTE_WR}/server/routes')

    for local_rel, remote_rel in FILES_TO_DEPLOY:
        local_path  = os.path.join(LOCAL_SRC, local_rel)
        remote_path = f'{REMOTE_WR}/{remote_rel}'
        sftp.put(local_path, remote_path)
        stat   = sftp.stat(remote_path)
        size_k = round(stat.st_size / 1024, 1)
        print(f'  OK  {remote_rel}  ({size_k} KB)')

    # Check running node process + attempt graceful restart
    print('\nChecking Node.js process...')
    _, stdout, _ = ssh.exec_command('ps aux | grep "node.*server.js" | grep -v grep')
    procs = stdout.read().decode().strip()
    if procs:
        print('  Running process(es):\n' + procs)
        pids = [line.split()[1] for line in procs.splitlines()]
        print(f'\n  Sending SIGUSR2 (graceful restart) to PID(s): {", ".join(pids)}')
        for pid in pids:
            ssh.exec_command(f'kill -USR2 {pid}')
        print('  Restart signal sent.')
    else:
        print('  No node process found via ps — server may be managed by Passenger.')
        print('  Touching restart.txt to signal Passenger restart...')
        ssh.exec_command(f'mkdir -p {REMOTE_WR}/tmp && touch {REMOTE_WR}/tmp/restart.txt')
        print('  tmp/restart.txt touched.')

    sftp.close()
    ssh.close()
    print('\nDeploy complete. Wait 5–10s then test:')
    print('  https://www.bakudanramen.com/api/admin/dashboard')

if __name__ == '__main__':
    main()
