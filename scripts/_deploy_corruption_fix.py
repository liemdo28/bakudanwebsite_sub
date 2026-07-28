"""
Scoped deploy: pushes only the live, actually-served files that were fixed
for the "rim -> la cantera" text-corruption bug (see commit that added
scripts/check_no_text_corruption.py). Excludes docs, dead backup snapshots,
and files that exist in the repo but are not actually live (verified via
direct HTTP check before this list was finalized).

Usage:
  python scripts/_deploy_corruption_fix.py            # deploy
  python scripts/_deploy_corruption_fix.py --dry-run   # show file list, no network calls
"""
import paramiko, os, sys, datetime
from pathlib import Path

HOST      = os.environ.get('BAKUDAN_SFTP_HOST', 'pdx1-shared-a3-05.dreamhost.com')
PORT      = int(os.environ.get('BAKUDAN_SFTP_PORT', '22'))
USER      = os.environ.get('BAKUDAN_SFTP_USER', 'hoale24new')
PASS      = os.environ.get('BAKUDAN_SFTP_PASS')
LOCAL_SRC = os.environ.get('BAKUDAN_LOCAL_SRC', str(Path(__file__).resolve().parents[1]))
REMOTE_WR = '/home/hoale24new/bakudanramen.com'

FILES = [
    'a-local-guide-to-ramen-near-la-cantera.html',
    'api/index.php',
    'bakudan-ramen-why-we-are-la-cantera-s-top-dining-spot.html',
    'best-ramen-san-antonio-the-definitive-2026-guide.html',
    'best-ramen-san-antonio.html',
    'blog-authentic.html',
    'blog-chashu.html',
    'blog-journey.html',
    'blog-ramen-101.html',
    'blog-tonkotsu.html',
    'chili-oil-101-elevating-your-spicy-ramen-bowl.html',
    'cilantro-lime-chicken-ramen-a-fresh-fusion-favorite.html',
    'craft-cocktails-and-ramen-the-ultimate-pairing.html',
    'css/broth-log.css',
    'css/styles.css',
    'daily-cocktail-specials-in-san-antonio-you-can-t-miss.html',
    'everything-you-need-to-know-about-spicy-umami-miso.html',
    'finding-a-japanese-restaurant-on-bandera-road.html',
    'fundraiser.html',
    'handmade-vs-instant-why-fresh-noodles-matter.html',
    'happy-hour-ramen-san-antonio.html',
    'happy-hour.html',
    'how-to-order-ramen-online-in-san-antonio.html',
    'how-to-perfectly-eat-garlic-tonkotsu-ramen.html',
    'index.html',
    'is-cilantro-lime-chicken-ramen-the-new-comfort-food.html',
    'japanese-craft-cocktails-beyond-the-basic-martini.html',
    'japanese-food-san-antonio.html',
    'lighter-ramen-options-shoyu-and-shio-broth-basics.html',
    'links-admin/app.css',
    'links-admin/app.js',
    'links-temp/index.html',
    'links/index.html',
    'locations.html',
    'locations/bandera.html',
    'locations/la-cantera.html',
    'locations/stone-oak.html',
    'menu.html',
    'modern-japanese-dining-the-bakudan-ramen-experience.html',
    'order-smart/index.html',
    'order.html',
    'ramen-101-what-makes-a-bowl-truly-soul-warming.html',
    'ramen-guide.html',
    'ramen-near-utsa.html',
    'ramen-stone-oak.html',
    'ramen-takeout-san-antonio-how-to-reheat-like-a-pro.html',
    'staff-training/index.html',
    'the-best-happy-hour-in-stone-oak-for-cocktails.html',
    'the-best-lunch-spots-in-san-antonio-for-ramen-lovers.html',
    'the-best-soup-in-san-antonio-for-rainy-days.html',
    'the-bold-science-of-spicy-pork-broth-ramen.html',
    'the-secret-to-our-24-hour-slow-simmered-tonkotsu-broth.html',
    'the-ultimate-guide-to-garlic-tonkotsu-ramen.html',
    'tonkotsu-ramen-san-antonio.html',
    'top-5-spicy-chicken-ramen-spots-in-san-antonio.html',
    'top-rated-japanese-restaurants-in-san-antonio-today.html',
    'vegetarian-ramen-san-antonio.html',
    'where-to-find-authentic-tonkotsu-ramen-in-san-antonio.html',
    'why-bakudan-is-the-perfect-date-night-spot.html',
    'why-bok-choy-is-the-perfect-crunch-for-spicy-ramen.html',
    'why-draft-beer-and-ramen-are-a-match-made-in-heaven.html',
    'why-fried-garlic-chips-are-the-ultimate-ramen-topping.html',
    'your-guide-to-japanese-food-delivery-in-san-antonio.html',
]


def ensure_dir(sftp, path):
    try:
        sftp.stat(path)
    except FileNotFoundError:
        sftp.mkdir(path)


def main():
    dry_run = '--dry-run' in sys.argv
    print(f'Scoped deploy: text-corruption fix ({len(FILES)} files)')

    if dry_run:
        for f in FILES:
            local_path = os.path.join(LOCAL_SRC, f.replace('/', os.sep))
            exists = os.path.isfile(local_path)
            print(f'  [dry-run] {f} (local exists={exists}) -> {REMOTE_WR}/{f}')
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
                               datetime.datetime.now().strftime('%Y%m%d-%H%M%S') + '-corruption-fix')
    os.makedirs(backup_dir, exist_ok=True)

    ok, failed = 0, []
    for f in FILES:
        local_path = os.path.join(LOCAL_SRC, f.replace('/', os.sep))
        remote_path = REMOTE_WR + '/' + f
        if '/' in f:
            ensure_dir(sftp, REMOTE_WR + '/' + f.rsplit('/', 1)[0])
        try:
            backup_path = os.path.join(backup_dir, f.replace('/', '__'))
            try:
                sftp.get(remote_path, backup_path)
            except FileNotFoundError:
                pass
            sftp.put(local_path, remote_path)
            ok += 1
        except Exception as e:
            failed.append((f, str(e)))
            print(f'  FAILED {f}: {e}')

    sftp.close()
    ssh.close()
    print(f'\n{ok}/{len(FILES)} uploaded. Backups of prior remote versions: {backup_dir}')
    if failed:
        print('Failures:', failed)
        sys.exit(1)


if __name__ == '__main__':
    main()
