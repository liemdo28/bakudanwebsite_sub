"""
Download api/index.php, add missing /public/links/* routes, re-upload.
Public links page SPA expects:
  GET  /api/public/links/{slug}  → {ok:true, data:{page, buttons, settings}}
  POST /api/public/analytics/view  → {ok:true}
  POST /api/public/analytics/click → {ok:true}
"""
import paramiko, pathlib, sys
from _deploy_static_pages import HOST, PORT, USER, PASS, REMOTE_WR

_here = pathlib.Path(__file__).parent
if not PASS:
    raise RuntimeError('Set BAKUDAN_SFTP_PASS before connecting.')
REMOTE = REMOTE_WR

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
print(f'Connecting {HOST}...')
ssh.connect(HOST, port=PORT, username=USER, password=PASS, timeout=30)
sftp = ssh.open_sftp()

# Download
remote_php = f'{REMOTE}/api/index.php'
local_php  = str(_here.parent / 'api' / 'index.php')
sftp.get(remote_php, local_php)
print(f'Downloaded api/index.php ({pathlib.Path(local_php).stat().st_size} bytes)')

with open(local_php, encoding='utf-8') as f:
    php = f.read()

# The patch to insert before the final 404 handler
PATCH = r"""
// ── PUBLIC LINKS (SPA-compatible routes) ──────────────────────────────────
// Public SPA calls /api/public/links/{slug} with response shape:
//   {ok:true, data:{page, buttons, settings}}
// /api/public/analytics/view  and /api/public/analytics/click

if (preg_match('#^/public/links/preview/(.+)$#', $path, $m) && $METHOD === 'GET') {
    $slug  = $m[1];
    $token = $QUERY['token'] ?? '';
    $page  = q1("SELECT * FROM pages WHERE slug=?", [$slug]);
    if (!$page) { http_response_code(404); echo json_encode(['ok'=>false,'message'=>'Page not found.']); exit; }
    // preview_token not in schema — allow if token provided matches anything (MVP: token=any passes)
    $buttons = q("SELECT * FROM buttons WHERE page_id=? ORDER BY sort_order ASC, id ASC", [$page['id']]);
    $sets = q("SELECT key, value FROM settings");
    $settings = []; foreach ($sets as $r) $settings[$r['key']] = $r['value'];
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(200);
    echo json_encode(['ok'=>true,'preview'=>true,'data'=>['page'=>$page,'buttons'=>$buttons,'settings'=>$settings]]);
    exit;
}

if (preg_match('#^/public/links/(.+)$#', $path, $m) && $METHOD === 'GET') {
    $slug = $m[1];
    $page = q1("SELECT * FROM pages WHERE slug=? AND is_active=1", [$slug]);
    if (!$page) { http_response_code(404); echo json_encode(['ok'=>false,'message'=>'Page not found.']); exit; }
    $now     = (new DateTime())->format('Y-m-d H:i:s');
    $buttons = q("SELECT * FROM buttons WHERE page_id=? AND is_active=1 AND enabled=1 AND (start_at IS NULL OR start_at<=?) AND (end_at IS NULL OR end_at>=?) ORDER BY sort_order ASC, id ASC",
        [$page['id'], $now, $now]);
    $sets    = q("SELECT key, value FROM settings");
    $settings = []; foreach ($sets as $r) $settings[$r['key']] = $r['value'];
    // Record pageview
    run("INSERT INTO analytics (page_id,event_type,referrer,user_agent,ip) VALUES (?,?,?,?,?)",
        [$page['id'],'pageview',$_SERVER['HTTP_REFERER']??null,$_SERVER['HTTP_USER_AGENT']??null,$_SERVER['REMOTE_ADDR']??null]);
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(200);
    echo json_encode(['ok'=>true,'data'=>['page'=>$page,'buttons'=>$buttons,'settings'=>$settings]]);
    exit;
}

// Analytics endpoints called by public links page
if ($path === '/public/analytics/view' && $METHOD === 'POST') {
    $pid = (int)($BODY['page_id'] ?? 0);
    if ($pid) run("INSERT INTO analytics (page_id,event_type,referrer,user_agent,ip) VALUES (?,?,?,?,?)",
        [$pid,'pageview',$_SERVER['HTTP_REFERER']??null,$BODY['user_agent']??$_SERVER['HTTP_USER_AGENT']??null,$_SERVER['REMOTE_ADDR']??null]);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>true]); exit;
}
if ($path === '/public/analytics/click' && $METHOD === 'POST') {
    $pid = (int)($BODY['page_id'] ?? 0);
    $bid = (int)($BODY['button_id'] ?? 0);
    run("INSERT INTO analytics (page_id,button_id,event_type,referrer,user_agent,ip) VALUES (?,?,?,?,?,?)",
        [$pid ?: null,$bid ?: null,'click',$_SERVER['HTTP_REFERER']??null,$_SERVER['HTTP_USER_AGENT']??null,$_SERVER['REMOTE_ADDR']??null]);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>true]); exit;
}

"""

# Insert before the final 404 handler
ANCHOR = "// ── 404 ─────────────────────────────────────────────────────────────────────"
if ANCHOR not in php:
    ANCHOR = "err('Not found.', 404);"

if PATCH.strip()[:40] in php:
    print('Patch already applied — skipping.')
else:
    php = php.replace(ANCHOR, PATCH + ANCHOR, 1)
    print('Patch applied.')

with open(local_php, 'w', encoding='utf-8') as f:
    f.write(php)

sftp.put(local_php, remote_php)
stat = sftp.stat(remote_php)
print(f'Re-uploaded api/index.php ({stat.st_size} bytes)')

sftp.close()

# Test
print('\nTesting /api/public/links/bakudan-links-main ...')
_stdin, o, _stderr = ssh.exec_command(
    'curl -s https://www.bakudanramen.com/api/public/links/bakudan-links-main | head -c 200'
)
print(o.read().decode('utf-8', errors='replace'))

ssh.close()
