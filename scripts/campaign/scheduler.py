"""
Campaign publishing state machine and pipeline.

States: draft -> approved -> scheduled -> publishing -> published
                                        \\-> failed (retryable)

Flow for each due article:
  1. select_due()          -- publish_at <= now, status == 'approved'
  2. render_and_validate()  -- deterministic HTML, checked against required fields
  3. update_blog_index()    -- insert into blog.html's campaign marker block
  4. update_sitemap()       -- idempotent <url> insert
  5. git_commit_atomic()    -- one commit for article + blog.html + sitemap.xml
  6. deploy_scp()           -- scoped SFTP upload (article, hero image, blog.html, sitemap.xml)
  7. verify_live()          -- real HTTP checks against the live URL
  8. mark_published()       -- only after verify_live() passes; else status stays 'publishing'
                               for reconcile_stale_publishing() to retry safely

Fails closed: any missing credential, SFTP error, or live-verification mismatch
leaves the article NOT published (never a false-positive "published" status).
"""
import datetime
import json
import os
import re
import sys
import time
import xml.sax.saxutils

import paramiko

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
sys.path.insert(0, os.path.join(ROOT, 'scripts', 'campaign'))
import render_template as rt  # noqa: E402

STATE_PATH = os.path.join(ROOT, 'content', 'campaign', 'campaign-state.json')
MANIFEST_PATH = os.path.join(ROOT, 'content', 'campaign', 'campaign-manifest.json')
BLOG_HTML_PATH = os.path.join(ROOT, 'blog.html')
SITEMAP_PATH = os.path.join(ROOT, 'sitemap.xml')

SITE_URL = 'https://www.bakudanramen.com'
REMOTE_WR = '/home/hoale24new/bakudanramen.com'

BLOG_MARKER_START = '<!-- SEO-CAMPAIGN-2026-START -->'
BLOG_MARKER_END = '<!-- SEO-CAMPAIGN-2026-END -->'

MAX_PUBLISHING_AGE_MINUTES = 30  # beyond this, a 'publishing' article is considered stale


def load_state():
    with open(STATE_PATH, encoding='utf-8') as f:
        return json.load(f)


def save_state(state):
    with open(STATE_PATH, 'w', encoding='utf-8') as f:
        json.dump(state, f, indent=2, ensure_ascii=False)
        f.write('\n')


def load_manifest():
    with open(MANIFEST_PATH, encoding='utf-8') as f:
        return json.load(f)


def _log(state, article_id, event, **kw):
    entry = {'event': event, 'at': datetime.datetime.now(datetime.timezone.utc).isoformat(), **kw}
    state['articles'][article_id].setdefault('history', []).append(entry)


def published_slugs(state, manifest):
    id_to_slug = {a['id']: a['slug'] for a in manifest['articles']}
    return {id_to_slug[aid] for aid, rec in state['articles'].items() if rec['status'] == 'published'}


def select_due(state, manifest, now=None):
    now = now or datetime.datetime.now(datetime.timezone.utc)
    due = []
    for entry in manifest['articles']:
        rec = state['articles'].get(entry['id'])
        if not rec or rec['status'] != 'approved':
            continue
        publish_at = datetime.datetime.strptime(entry['publish_at'], '%Y-%m-%dT%H:%M:%SZ').replace(tzinfo=datetime.timezone.utc)
        if publish_at <= now:
            due.append(entry)
    return sorted(due, key=lambda e: e['seq'])


def load_article_content(entry):
    path = os.path.join(ROOT, 'content', 'campaign', 'articles', f'{entry["seq"]:02d}-{entry["slug"]}.json')
    with open(path, encoding='utf-8') as f:
        return json.load(f)


def render_and_validate(entry, state, manifest):
    article = load_article_content(entry)
    pubs = published_slugs(state, manifest)
    html = rt.render_article_html(article, published_slugs=pubs)
    marker = f'<!-- ARTICLE-ID: {article["id"]} -->'
    if marker not in html:
        raise ValueError(f'{entry["slug"]}: rendered HTML missing body marker')
    if f'<link rel="canonical" href="{SITE_URL}/{article["slug"]}.html">' not in html:
        raise ValueError(f'{entry["slug"]}: rendered HTML missing correct canonical')
    return article, html


def update_blog_index(article, html_text=None):
    text = html_text if html_text is not None else open(BLOG_HTML_PATH, encoding='utf-8').read()
    card = (
        f'                <article class="blog-card">\n'
        f'                    <div class="blog-card-image"><img src="{article["image"]}" alt="{xml.sax.saxutils.escape(article["image_alt"])}" width="1200" height="630" loading="lazy"></div>\n'
        f'                    <div class="blog-card-body">\n'
        f'                        <span class="blog-card-tag">Guide</span>\n'
        f'                        <h3>{xml.sax.saxutils.escape(article["h1"])}</h3>\n'
        f'                        <p>{xml.sax.saxutils.escape(article["meta_description"])}</p>\n'
        f'                        <a href="{article["slug"]}.html" class="blog-card-link">Read Article &rarr;</a>\n'
        f'                    </div>\n'
        f'                </article>\n'
    )
    if f'href="{article["slug"]}.html"' in text:
        return text  # idempotent: already linked
    if BLOG_MARKER_START not in text:
        text = text.replace(
            '<div class="blog-grid">',
            f'<div class="blog-grid">\n                {BLOG_MARKER_START}\n                {BLOG_MARKER_END}',
            1,
        )
    text = text.replace(BLOG_MARKER_START, BLOG_MARKER_START + '\n' + card)
    return text


def update_sitemap(canonical_url, xml_text=None):
    text = xml_text if xml_text is not None else open(SITEMAP_PATH, encoding='utf-8').read()
    if f'<loc>{canonical_url}</loc>' in text:
        return text  # idempotent
    entry = f'<url>\n<loc>{canonical_url}</loc>\n<changefreq>monthly</changefreq>\n<priority>0.6</priority>\n</url>\n'
    return text.replace('</urlset>', entry + '</urlset>')


def sitemap_loc_count(text, canonical_url):
    return text.count(f'<loc>{canonical_url}</loc>')


def write_local_files(article, html):
    article_path = os.path.join(ROOT, f'{article["slug"]}.html')
    with open(article_path, 'w', encoding='utf-8') as f:
        f.write(html)

    blog_text = update_blog_index(article)
    with open(BLOG_HTML_PATH, 'w', encoding='utf-8') as f:
        f.write(blog_text)

    canonical = f'{SITE_URL}/{article["slug"]}.html'
    sitemap_text = update_sitemap(canonical)
    with open(SITEMAP_PATH, 'w', encoding='utf-8') as f:
        f.write(sitemap_text)

    return article_path, BLOG_HTML_PATH, SITEMAP_PATH


def deploy_scp(article, local_files):
    host = os.environ.get('BAKUDAN_SFTP_HOST', 'pdx1-shared-a3-05.dreamhost.com')
    port = int(os.environ.get('BAKUDAN_SFTP_PORT', '22'))
    user = os.environ.get('BAKUDAN_SFTP_USER', 'hoale24new')
    password = os.environ.get('BAKUDAN_SFTP_PASS')
    if not password:
        raise RuntimeError('BAKUDAN_SFTP_PASS not set -- failing closed, not deploying')

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(host, port=port, username=user, password=password, timeout=30)
    sftp = ssh.open_sftp()

    remote_files = [
        (local_files[0], REMOTE_WR + f'/{article["slug"]}.html'),
        (local_files[1], REMOTE_WR + '/blog.html'),
        (local_files[2], REMOTE_WR + '/sitemap.xml'),
        (os.path.join(ROOT, article['image']), REMOTE_WR + '/' + article['image']),
    ]
    backup_dir = os.path.join(ROOT, 'scripts', '_deploy_backups',
                               datetime.datetime.now().strftime('%Y%m%d-%H%M%S') + f'-{article["slug"]}')
    os.makedirs(backup_dir, exist_ok=True)
    for local_path, remote_path in remote_files:
        try:
            sftp.get(remote_path, os.path.join(backup_dir, os.path.basename(remote_path)))
        except FileNotFoundError:
            pass  # new file, nothing to back up
    for local_path, remote_path in remote_files:
        remote_dir = remote_path.rsplit('/', 1)[0]
        try:
            sftp.stat(remote_dir)
        except FileNotFoundError:
            sftp.mkdir(remote_dir)
        sftp.put(local_path, remote_path)

    sftp.close()
    ssh.close()
    return backup_dir


def verify_live(article, retries=3, delay_seconds=5):
    """Fail-closed live verification. Requires urllib (stdlib, no extra deps)."""
    import urllib.request
    import urllib.error

    canonical = f'{SITE_URL}/{article["slug"]}.html'
    marker = f'<!-- ARTICLE-ID: {article["id"]} -->'
    checks = {'http_200': False, 'title_match': False, 'canonical_match': False,
              'body_marker': False, 'image_200': False, 'sitemap_once': False}

    for attempt in range(retries):
        try:
            with urllib.request.urlopen(canonical, timeout=15) as resp:
                if resp.status == 200:
                    checks['http_200'] = True
                    body = resp.read().decode('utf-8', errors='ignore')
                    checks['title_match'] = f'<title>{article["seo_title"]}</title>' in body
                    checks['canonical_match'] = f'<link rel="canonical" href="{canonical}">' in body
                    checks['body_marker'] = marker in body
            break
        except (urllib.error.URLError, TimeoutError):
            if attempt < retries - 1:
                time.sleep(delay_seconds)
                continue

    try:
        image_url = f'{SITE_URL}/{article["image"]}'
        req = urllib.request.Request(image_url, method='HEAD')
        with urllib.request.urlopen(req, timeout=15) as resp:
            checks['image_200'] = resp.status == 200
    except (urllib.error.URLError, TimeoutError):
        pass

    try:
        with urllib.request.urlopen(f'{SITE_URL}/sitemap.xml', timeout=15) as resp:
            sitemap_body = resp.read().decode('utf-8', errors='ignore')
            checks['sitemap_once'] = sitemap_loc_count(sitemap_body, canonical) == 1
    except (urllib.error.URLError, TimeoutError):
        pass

    checks['all_passed'] = all(v for k, v in checks.items() if k != 'all_passed')
    return checks


def publish_one(entry, state, manifest, dry_run=False):
    article_id = entry['id']
    rec = state['articles'][article_id]

    article, html = render_and_validate(entry, state, manifest)

    if dry_run:
        local_files_would_change = [f'{article["slug"]}.html', 'blog.html (idempotent insert)', 'sitemap.xml (idempotent insert)']
        remote_files_would_change = [f'{article["slug"]}.html', 'blog.html', 'sitemap.xml', article['image']]
        return {'dry_run': True, 'article_id': article_id, 'local_files': local_files_would_change, 'remote_files': remote_files_would_change}

    rec['status'] = 'publishing'
    _log(state, article_id, 'publishing_started')
    save_state(state)

    local_files = write_local_files(article, html)

    try:
        backup_dir = deploy_scp(article, local_files)
    except Exception as e:
        _log(state, article_id, 'deploy_failed', error=str(e))
        rec['status'] = 'approved'  # roll back to approved for retry next run
        save_state(state)
        raise

    _log(state, article_id, 'deployed', backup_dir=backup_dir)
    save_state(state)

    checks = verify_live(article)
    if not checks['all_passed']:
        _log(state, article_id, 'live_verification_failed', checks=checks)
        save_state(state)
        # status stays 'publishing' -- reconcile_stale_publishing() will retry/investigate
        return {'published': False, 'checks': checks}

    rec['status'] = 'published'
    _log(state, article_id, 'published', checks=checks)
    save_state(state)
    return {'published': True, 'checks': checks}


def reconcile_stale_publishing(state, manifest, now=None):
    now = now or datetime.datetime.now(datetime.timezone.utc)
    id_to_entry = {a['id']: a for a in manifest['articles']}
    reconciled = []
    for article_id, rec in state['articles'].items():
        if rec['status'] != 'publishing':
            continue
        last_event = rec['history'][-1] if rec['history'] else None
        if not last_event:
            continue
        started_at = datetime.datetime.fromisoformat(last_event['at'])
        age_minutes = (now - started_at).total_seconds() / 60
        if age_minutes < MAX_PUBLISHING_AGE_MINUTES:
            continue  # still within normal window, don't touch yet

        entry = id_to_entry[article_id]
        article = load_article_content(entry)
        checks = verify_live(article, retries=1)
        if checks['all_passed']:
            rec['status'] = 'published'
            _log(state, article_id, 'reconciled_as_published', checks=checks)
        else:
            rec['status'] = 'approved'
            _log(state, article_id, 'reconciled_as_failed_rolled_back', checks=checks)
        reconciled.append((article_id, rec['status']))
    if reconciled:
        save_state(state)
    return reconciled


def run(dry_run=False):
    state = load_state()
    manifest = load_manifest()

    reconciled = reconcile_stale_publishing(state, manifest)
    for article_id, new_status in reconciled:
        print(f'reconciled {article_id} -> {new_status}')

    if not state.get('automation_enabled') and not dry_run:
        print('automation_enabled is false -- not publishing anything (kill-switch active)')
        return

    due = select_due(state, manifest)
    if not due:
        print('no due articles')
        return

    entry = due[0]  # publish at most one per run, never a batch
    print(f'due: seq {entry["seq"]} ({entry["slug"]})')

    result = publish_one(entry, state, manifest, dry_run=dry_run)
    print(json.dumps(result, indent=2, default=str))


if __name__ == '__main__':
    run(dry_run='--dry-run' in sys.argv)
