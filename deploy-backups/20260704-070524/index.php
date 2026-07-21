<?php
/**
 * Bakudan Ramen — PHP API Backend v3
 * Response format: FLAT JSON — no {"ok":true,"data":{}} wrapper.
 * The compiled links-admin SPA wraps every response itself via its api() function.
 * Errors return {"message":"..."} + HTTP 4xx/5xx.
 */
declare(strict_types=1);

// ── Config ────────────────────────────────────────────────────────────
define('DB_PATH',      '/home/hoale24new/bakudan-app/data/bakudan.db');
define('UPLOAD_DIR',   '/home/hoale24new/bakudanramen.com/uploads/blogs/');
define('UPLOAD_URL',   '/uploads/blogs/');
define('JWT_SECRET',   getenv('JWT_SECRET') ?: 'bakudan-dev-secret-change-in-production');
define('JWT_TTL',      7 * 24 * 3600);
define('SITE_URL',     'https://bakudanramen.com');

// Suppress PHP warnings that would corrupt JSON output
error_reporting(0);
ini_set('display_errors', '0');

// CORS headers first — before any Content-Type decision
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Content-Type: skip for file upload (multipart) and sitemap/xml responses
$_rawPath = rtrim(preg_replace('#^/api#', '', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)), '/') ?: '/';
$_isUpload  = ($_rawPath === '/upload');
$_isSitemap = ($_rawPath === '/sitemap.xml');

if (!$_isUpload && !$_isSitemap) {
    header('Content-Type: application/json; charset=utf-8');
}

// ── JWT ───────────────────────────────────────────────────────────────
function base64url(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function base64url_decode(string $data): string {
    $pad = strlen($data) % 4;
    if ($pad) $data .= str_repeat('=', 4 - $pad);
    return base64_decode(strtr($data, '-_', '+/'));
}
function jwt_encode(array $payload): string {
    $h = base64url(json_encode(['alg'=>'HS256','typ'=>'JWT']));
    $p = base64url(json_encode($payload));
    $s = base64url(hash_hmac('sha256', "$h.$p", JWT_SECRET, true));
    return "$h.$p.$s";
}
function jwt_decode(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$h, $p, $s] = $parts;
    $expected = base64url(hash_hmac('sha256', "$h.$p", JWT_SECRET, true));
    if (!hash_equals($expected, $s)) return null;
    $payload = json_decode(base64url_decode($p), true);
    if (!$payload || ($payload['exp'] ?? 0) < time()) return null;
    return $payload;
}

// ── Database ──────────────────────────────────────────────────────────
function db(): SQLite3 {
    static $db = null;
    if ($db) return $db;
    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $db = new SQLite3(DB_PATH);
    $db->enableExceptions(true);
    $db->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;');
    db_migrate($db);
    return $db;
}
function db_migrate(SQLite3 $db): void {
    $db->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT UNIQUE NOT NULL, password_hash TEXT NOT NULL,
        name TEXT, role TEXT NOT NULL DEFAULT 'viewer',
        store_slug TEXT, is_active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS pages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL, slug TEXT UNIQUE NOT NULL,
        headline TEXT, store_slug TEXT, is_active INTEGER NOT NULL DEFAULT 0,
        sort_order INTEGER NOT NULL DEFAULT 0, theme TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS link_sections (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        page_id INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
        title TEXT NOT NULL,
        section_key TEXT,
        sort_order INTEGER NOT NULL DEFAULT 0,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS buttons (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        page_id INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
        section_id INTEGER REFERENCES link_sections(id) ON DELETE SET NULL,
        label TEXT NOT NULL, url TEXT NOT NULL, icon TEXT,
        subtitle TEXT, style_variant TEXT, custom_icon_svg TEXT,
        opens_in_new_tab INTEGER NOT NULL DEFAULT 1,
        sort_order INTEGER NOT NULL DEFAULT 0,
        is_active INTEGER NOT NULL DEFAULT 1,
        is_featured INTEGER NOT NULL DEFAULT 0,
        enabled INTEGER NOT NULL DEFAULT 1,
        start_at TEXT, end_at TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS redirects (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        page_id INTEGER REFERENCES pages(id) ON DELETE CASCADE,
        source TEXT NOT NULL, destination TEXT NOT NULL,
        is_permanent INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS shortlinks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT UNIQUE NOT NULL, destination TEXT NOT NULL,
        label TEXT, utm_source TEXT, utm_medium TEXT, utm_campaign TEXT,
        clicks INTEGER NOT NULL DEFAULT 0, is_active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS analytics (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        page_id INTEGER, button_id INTEGER, shortlink_id INTEGER,
        event_type TEXT NOT NULL DEFAULT 'click',
        referrer TEXT, user_agent TEXT, ip TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS subscribers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT UNIQUE NOT NULL, name TEXT, source TEXT,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY, value TEXT NOT NULL,
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS blog_posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL, slug TEXT UNIQUE NOT NULL,
        status TEXT NOT NULL DEFAULT 'draft',
        content TEXT, excerpt TEXT, cover_image TEXT,
        author_id INTEGER, published_at TEXT, scheduled_at TEXT, archived_at TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    ");
    // Add blog columns (idempotent)
    foreach ([
        "ALTER TABLE buttons ADD COLUMN section_id INTEGER REFERENCES link_sections(id) ON DELETE SET NULL",
        "ALTER TABLE buttons ADD COLUMN subtitle TEXT",
        "ALTER TABLE buttons ADD COLUMN style_variant TEXT",
        "ALTER TABLE buttons ADD COLUMN custom_icon_svg TEXT",
        "ALTER TABLE buttons ADD COLUMN opens_in_new_tab INTEGER NOT NULL DEFAULT 1",
        "ALTER TABLE blog_posts ADD COLUMN category TEXT",
        "ALTER TABLE blog_posts ADD COLUMN tags TEXT",
        "ALTER TABLE blog_posts ADD COLUMN seo_title TEXT",
        "ALTER TABLE blog_posts ADD COLUMN seo_description TEXT",
        "ALTER TABLE blog_posts ADD COLUMN og_image TEXT",
        "ALTER TABLE blog_posts ADD COLUMN tiptap_json TEXT",
        "ALTER TABLE blog_posts ADD COLUMN reading_time INTEGER NOT NULL DEFAULT 0",
    ] as $sql) {
        try { $db->exec($sql); } catch (Exception $e) {}
    }
    // Seed admin
    if ($db->querySingle("SELECT COUNT(*) FROM users") == 0) {
        $hash = password_hash('admin123', PASSWORD_BCRYPT);
        $s = $db->prepare("INSERT INTO users (email,password_hash,name,role) VALUES (?,?,?,?)");
        $s->bindValue(1, 'admin@bakudanramen.com');
        $s->bindValue(2, $hash);
        $s->bindValue(3, 'Administrator');
        $s->bindValue(4, 'super_admin');
        $s->execute();
    }
    // Seed settings
    if ($db->querySingle("SELECT COUNT(*) FROM settings") == 0) {
        $ins = $db->prepare("INSERT OR IGNORE INTO settings (key,value) VALUES (?,?)");
        foreach ([
            ['site_name','Bakudan Ramen'],['site_url',SITE_URL],
            ['theme_pla canteraary','#dc2626'],['theme_bg','#0f172a'],
            ['footer_text','© Bakudan Ramen. All rights reserved.'],
            ['show_subscriber_form','0'],
            ['order_url_la cantera','https://order.toasttab.com/online/bakudanramen'],
            ['order_url_stone_oak','https://order.toasttab.com/online/bakudan-ramen-stone-oak'],
            ['order_url_bandera','https://order.toasttab.com/online/bakudan-bandera'],
        ] as [$k,$v]) { $ins->bindValue(1,$k); $ins->bindValue(2,$v); $ins->execute(); }
    }
    seed_link_sections($db);
}

function seed_link_sections(SQLite3 $db): void {
    $pageRows = $db->query("SELECT id, slug FROM pages");
    while ($page = $pageRows->fetchArray(SQLITE3_ASSOC)) {
        if ((int)$db->querySingle("SELECT COUNT(*) FROM link_sections WHERE page_id=" . (int)$page['id']) > 0) continue;
        $defaults = [
            ['Order Online', 'order', 10],
            ['Rewards & Loyalty', 'rewards', 20],
            ['Merchandise', 'merchandise', 30],
        ];
        foreach ($defaults as [$title, $key, $sort]) {
            $stmt = $db->prepare("INSERT INTO link_sections (page_id,title,section_key,sort_order,is_active) VALUES (?,?,?,?,1)");
            $stmt->bindValue(1, (int)$page['id']);
            $stmt->bindValue(2, $title);
            $stmt->bindValue(3, $key);
            $stmt->bindValue(4, $sort);
            $stmt->execute();
        }
    }

    $buttonRows = $db->query("SELECT id, page_id, label, url FROM buttons WHERE section_id IS NULL");
    while ($button = $buttonRows->fetchArray(SQLITE3_ASSOC)) {
        $haystack = strtolower(($button['label'] ?? '') . ' ' . ($button['url'] ?? ''));
        $key = null;
        if (str_contains($haystack, 'order.toasttab.com')) $key = 'order';
        elseif (str_contains($haystack, 'rewardssignup') || str_contains($haystack, 'reward')) $key = 'rewards';
        elseif (str_contains($haystack, 'merch')) $key = 'merchandise';
        if (!$key) continue;
        $sectionStmt = $db->prepare("SELECT id FROM link_sections WHERE page_id=? AND section_key=?");
        $sectionStmt->bindValue(1, (int)$button['page_id']);
        $sectionStmt->bindValue(2, $key);
        $section = $sectionStmt->execute()->fetchArray(SQLITE3_ASSOC);
        if (!$section) continue;
        $updateStmt = $db->prepare("UPDATE buttons SET section_id=? WHERE id=?");
        $updateStmt->bindValue(1, (int)$section['id']);
        $updateStmt->bindValue(2, (int)$button['id']);
        $updateStmt->execute();
    }
}
function q(string $sql, array $params = []): array {
    $stmt = db()->prepare($sql);
    foreach ($params as $i => $v) $stmt->bindValue($i+1, $v);
    $res = $stmt->execute();
    $rows = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;
    return $rows;
}
function q1(string $sql, array $params = []): ?array {
    $rows = q($sql, $params);
    return $rows[0] ?? null;
}
function run(string $sql, array $params = []): int {
    $stmt = db()->prepare($sql);
    foreach ($params as $i => $v) $stmt->bindValue($i+1, $v);
    $stmt->execute();
    return db()->lastInsertRowID();
}
function section_id_from_body(array $body): ?int {
    if (!array_key_exists('section_id', $body) || $body['section_id'] === '' || $body['section_id'] === null) return null;
    return (int)$body['section_id'];
}
function button_label_from_body(array $body, ?array $fallback = null): string {
    return trim((string)($body['label'] ?? $body['title'] ?? ($fallback['label'] ?? '')));
}
function button_icon_from_body(array $body, ?array $fallback = null): ?string {
    return $body['icon'] ?? $body['icon_key'] ?? ($fallback['icon'] ?? null);
}
function button_visible_from_body(array $body, ?array $fallback = null): int {
    if (array_key_exists('is_active', $body)) return (int)$body['is_active'];
    if (array_key_exists('visible', $body)) return (int)$body['visible'];
    return (int)($fallback['is_active'] ?? 1);
}
function button_select_sql(string $where): string {
    return "SELECT b.*, b.label AS title, b.icon AS icon_key, b.is_active AS visible,
            s.title AS section_title, s.section_key, s.sort_order AS section_sort_order,
            s.is_active AS section_is_active
            FROM buttons b
            LEFT JOIN link_sections s ON s.id=b.section_id
            WHERE $where
            ORDER BY COALESCE(s.sort_order, 9999) ASC, b.sort_order ASC, b.id ASC";
}

// ── Request ───────────────────────────────────────────────────────────
$METHOD = $_SERVER['REQUEST_METHOD'];
$URI    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$BODY   = json_decode(file_get_contents('php://input'), true) ?? [];
$QUERY  = $_GET;

// Strip /api prefix
$path = preg_replace('#^/api#', '', $URI);
$path = rtrim($path, '/') ?: '/';
if (str_starts_with($path, '/links/pages')) $path = '/admin' . substr($path, 6);
if (str_starts_with($path, '/links/buttons')) $path = '/admin' . substr($path, 6);
if (str_starts_with($path, '/links/sections')) $path = '/admin' . substr($path, 6);
if (str_starts_with($path, '/links/settings')) $path = '/admin' . substr($path, 6);
if (str_starts_with($path, '/links/dashboard')) $path = '/admin' . substr($path, 6);

// ── Response helpers ──────────────────────────────────────────────────
function ok(array $data = [], int $code = 200): void {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    if (!array_key_exists('ok', $data)) $data = ['ok' => true] + $data;
    echo json_encode($data);
    exit;
}
function err(string $msg, int $code = 400): void {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $msg, 'error' => $msg]);
    exit;
}

// ── Auth middleware ───────────────────────────────────────────────────
function auth(): array {
    $header = $_SERVER['HTTP_AUTHORIZATION']
           ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
           ?? (function_exists('apache_request_headers') ? (apache_request_headers()['Authorization'] ?? '') : '');
    $token = str_starts_with($header, 'Bearer ') ? substr($header, 7) : null;
    if (!$token) err('Unauthorized', 401);
    $payload = jwt_decode($token);
    if (!$payload) err('Session expired. Please sign in again.', 401);
    $user = q1("SELECT * FROM users WHERE id=? AND is_active=1", [$payload['id']]);
    if (!$user) err('Account inactive or not found.', 401);
    return $user;
}
function role_check(array $user, array $roles): void {
    if (!in_array($user['role'], $roles)) err('You do not have permission for this action.', 403);
}
$MGR  = ['super_admin','marketing_manager'];
$EDIT = ['super_admin','marketing_manager','store_manager'];

// ─────────────────────────────────────────────────────────────────────
// ── AUTH ─────────────────────────────────────────────────────────────
// ─────────────────────────────────────────────────────────────────────

if ($path === '/auth/login' && $METHOD === 'POST') {
    $email = strtolower(trim($BODY['email'] ?? ''));
    $pass  = $BODY['password'] ?? '';
    if (!$email || !$pass) err('Email and password are required.');
    $user = q1("SELECT * FROM users WHERE email=? AND is_active=1", [$email]);
    if (!$user || !password_verify($pass, $user['password_hash']))
        err('Invalid email or password.', 401);
    $token = jwt_encode(['id'=>$user['id'],'email'=>$user['email'],'role'=>$user['role'],'exp'=>time()+JWT_TTL]);
    // SPA checks: res.data.success, res.data.token, res.data.user
    ok([
        'success' => true,
        'token'   => $token,
        'user'    => [
            'id'         => $user['id'],
            'email'      => $user['email'],
            'name'       => $user['name'],
            'role'       => $user['role'],
            'store_slug' => $user['store_slug'],
        ],
    ]);
}

if ($path === '/auth/change-password' && $METHOD === 'POST') {
    $user = auth();
    $cur  = $BODY['current_password'] ?? '';
    $new  = $BODY['new_password'] ?? '';
    if (!$cur || !$new) err('Both passwords are required.');
    if (strlen($new) < 8) err('New password must be at least 8 characters.');
    $fresh = q1("SELECT * FROM users WHERE id=?", [$user['id']]);
    if (!password_verify($cur, $fresh['password_hash'])) err('Current password is incorrect.');
    run("UPDATE users SET password_hash=?, updated_at=datetime('now') WHERE id=?",
        [password_hash($new, PASSWORD_BCRYPT), $user['id']]);
    ok(['success' => true]);
}

// ── CONFIG ────────────────────────────────────────────────────────────
if ($path === '/config' && $METHOD === 'GET') {
    ok([
        'version'  => '3.0.0',
        'siteUrl'  => SITE_URL,
        'iconKeys' => ['order','website','email','events','instagram','facebook','directions','phone','menu','gift','ticket','external','blog','social'],
    ]);
}

// ── DASHBOARD ─────────────────────────────────────────────────────────
// SPA (links-admin/app.js viewDashboard) accesses a FLAT payload:
// total, live, hidden, scheduled, expired, featured, views_24h, clicks_24h, pages[]
if ($path === '/admin/dashboard' && $METHOD === 'GET') {
    auth();
    $now = (new DateTime())->format('Y-m-d H:i:s');
    $clicks24h = db()->querySingle("SELECT COUNT(*) FROM analytics WHERE event_type='click' AND created_at>=datetime('now','-1 day')");
    $views24h  = db()->querySingle("SELECT COUNT(*) FROM analytics WHERE event_type='pageview' AND created_at>=datetime('now','-1 day')");
    $total     = db()->querySingle("SELECT COUNT(*) FROM buttons");
    $live      = db()->querySingle("SELECT COUNT(*) FROM buttons WHERE is_active=1 AND enabled=1 AND (start_at IS NULL OR start_at<='$now') AND (end_at IS NULL OR end_at>='$now')");
    $hidden    = db()->querySingle("SELECT COUNT(*) FROM buttons WHERE is_active=0");
    $scheduled = db()->querySingle("SELECT COUNT(*) FROM buttons WHERE start_at IS NOT NULL AND start_at>'$now'");
    $expired   = db()->querySingle("SELECT COUNT(*) FROM buttons WHERE end_at IS NOT NULL AND end_at<'$now'");
    $featured  = db()->querySingle("SELECT COUNT(*) FROM buttons WHERE is_featured=1");
    $pages     = q("SELECT p.*, (SELECT COUNT(*) FROM buttons b WHERE b.page_id=p.id) AS button_count
                    FROM pages p ORDER BY p.sort_order ASC, p.id ASC");
    ok([
        'total'      => (int)$total,
        'live'       => (int)$live,
        'hidden'     => (int)$hidden,
        'scheduled'  => (int)$scheduled,
        'expired'    => (int)$expired,
        'featured'   => (int)$featured,
        'views_24h'  => (int)$views24h,
        'clicks_24h' => (int)$clicks24h,
        'pages'      => $pages,
    ]);
}

// ── PAGES ─────────────────────────────────────────────────────────────
if ($path === '/admin/pages' && $METHOD === 'GET') {
    auth();
    ok(['pages' => q("SELECT p.*, (SELECT COUNT(*) FROM buttons b WHERE b.page_id=p.id) AS button_count
                       FROM pages p ORDER BY p.sort_order ASC, p.id ASC")]);
}
if ($path === '/admin/pages' && $METHOD === 'POST') {
    $user = auth(); role_check($user, $MGR);
    $title = $BODY['title'] ?? ''; $slug = $BODY['slug'] ?? '';
    if (!$title || !$slug) err('Title and slug are required.');
    $slug = strtolower(preg_replace('/[^a-z0-9-]+/', '-', $slug));
    try {
        $id = run("INSERT INTO pages (title,slug,headline,store_slug) VALUES (?,?,?,?)",
            [$title, $slug, $BODY['headline']??null, $BODY['store_slug']??null]);
        // SPA uses res.data.id to navigate
        $page = q1("SELECT * FROM pages WHERE id=?", [$id]);
        ok(array_merge(['id' => $id], $page));
    } catch (Exception $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) err('That slug is already in use.', 409);
        throw $e;
    }
}
if (preg_match('#^/admin/pages/(\d+)$#', $path, $m)) {
    $user = auth(); $pid = (int)$m[1];
    $page = q1("SELECT * FROM pages WHERE id=?", [$pid]);
    if (!$page) err('Page not found.', 404);
    if ($METHOD === 'GET') ok([
        'page' => $page,
        'sections' => q("SELECT * FROM link_sections WHERE page_id=? ORDER BY sort_order ASC, id ASC", [$pid]),
        'buttons' => q(button_select_sql("b.page_id=?"), [$pid]),
    ]);
    if ($METHOD === 'PUT') {
        role_check($user, $EDIT);
        $slug = strtolower(preg_replace('/[^a-z0-9-]+/', '-', $BODY['slug'] ?? $page['slug']));
        try {
            run("UPDATE pages SET title=?,slug=?,headline=?,store_slug=?,is_active=?,theme=?,updated_at=datetime('now') WHERE id=?",
                [$BODY['title']??$page['title'], $slug, $BODY['headline']??$page['headline'],
                 $BODY['store_slug']??$page['store_slug'], $BODY['is_active']??$page['is_active'],
                 $BODY['theme']??$page['theme'], $pid]);
            ok(['page' => q1("SELECT * FROM pages WHERE id=?", [$pid])]);
        } catch (Exception $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) err('That slug is already in use.', 409);
            throw $e;
        }
    }
    if ($METHOD === 'DELETE') {
        role_check($user, $MGR);
        run("DELETE FROM pages WHERE id=?", [$pid]);
        ok(['success' => true]);
    }
}
if (preg_match('#^/admin/pages/(\d+)/duplicate$#', $path, $m) && $METHOD === 'POST') {
    $user = auth(); role_check($user, $MGR); $pid = (int)$m[1];
    $src = q1("SELECT * FROM pages WHERE id=?", [$pid]);
    if (!$src) err('Page not found.', 404);
    $newSlug = $src['slug'] . '-copy-' . time();
    $newId = run("INSERT INTO pages (title,slug,headline,store_slug,theme) VALUES (?,?,?,?,?)",
        [$src['title'] . ' (Copy)', $newSlug, $src['headline'], $src['store_slug'], $src['theme']]);
    $sectionMap = [];
    foreach (q("SELECT * FROM link_sections WHERE page_id=? ORDER BY sort_order", [$pid]) as $s) {
        $sectionMap[(int)$s['id']] = run("INSERT INTO link_sections (page_id,title,section_key,sort_order,is_active) VALUES (?,?,?,?,?)",
            [$newId,$s['title'],$s['section_key'],$s['sort_order'],$s['is_active']]);
    }
    foreach (q("SELECT * FROM buttons WHERE page_id=? ORDER BY sort_order", [$pid]) as $b) {
        $newSectionId = $b['section_id'] ? ($sectionMap[(int)$b['section_id']] ?? null) : null;
        run("INSERT INTO buttons (page_id,section_id,label,url,icon,subtitle,style_variant,custom_icon_svg,opens_in_new_tab,sort_order,is_active,is_featured,enabled,start_at,end_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [$newId,$newSectionId,$b['label'],$b['url'],$b['icon'],$b['subtitle']??null,$b['style_variant']??null,$b['custom_icon_svg']??null,$b['opens_in_new_tab']??1,$b['sort_order'],$b['is_active'],$b['is_featured'],$b['enabled'],$b['start_at'],$b['end_at']]);
    }
    $newPage = q1("SELECT * FROM pages WHERE id=?", [$newId]);
    ok(array_merge(['id' => $newId], $newPage));
}
if (preg_match('#^/admin/pages/(\d+)/publish$#', $path, $m) && $METHOD === 'POST') {
    $user = auth(); role_check($user, $EDIT); $pid = (int)$m[1];
    if (!q1("SELECT id FROM pages WHERE id=?", [$pid])) err('Page not found.', 404);
    run("UPDATE pages SET is_active=1,updated_at=datetime('now') WHERE id=?", [$pid]);
    ok(['page' => q1("SELECT * FROM pages WHERE id=?", [$pid])]);
}
if (preg_match('#^/admin/pages/(\d+)/unpublish$#', $path, $m) && $METHOD === 'POST') {
    $user = auth(); role_check($user, $EDIT); $pid = (int)$m[1];
    if (!q1("SELECT id FROM pages WHERE id=?", [$pid])) err('Page not found.', 404);
    run("UPDATE pages SET is_active=0,updated_at=datetime('now') WHERE id=?", [$pid]);
    ok(['page' => q1("SELECT * FROM pages WHERE id=?", [$pid])]);
}

// ── LINK SECTIONS ────────────────────────────────────────────────────
if (preg_match('#^/admin/pages/(\d+)/sections$#', $path, $m)) {
    $user = auth(); $pid = (int)$m[1];
    if (!q1("SELECT id FROM pages WHERE id=?", [$pid])) err('Page not found.', 404);
    if ($METHOD === 'GET') {
        ok(['sections' => q("SELECT * FROM link_sections WHERE page_id=? ORDER BY sort_order ASC, id ASC", [$pid])]);
    }
    if ($METHOD === 'POST') {
        role_check($user, $EDIT);
        $title = trim((string)($BODY['title'] ?? ''));
        if (!$title) err('Section title is required.');
        $max = db()->querySingle("SELECT COALESCE(MAX(sort_order),-10) FROM link_sections WHERE page_id=$pid");
        $key = $BODY['section_key'] ?? strtolower(preg_replace('/[^a-z0-9-]+/', '-', $title));
        $id = run("INSERT INTO link_sections (page_id,title,section_key,sort_order,is_active) VALUES (?,?,?,?,?)",
            [$pid,$title,$key,$BODY['sort_order']??$max+10,$BODY['is_active']??1]);
        ok(['section' => q1("SELECT * FROM link_sections WHERE id=?", [$id])]);
    }
}
if (preg_match('#^/admin/sections/(\d+)$#', $path, $m)) {
    $user = auth(); $sid = (int)$m[1];
    $section = q1("SELECT * FROM link_sections WHERE id=?", [$sid]);
    if (!$section) err('Section not found.', 404);
    if ($METHOD === 'PUT') {
        role_check($user, $EDIT);
        $title = trim((string)($BODY['title'] ?? $section['title']));
        if (!$title) err('Section title is required.');
        $key = $BODY['section_key'] ?? $section['section_key'];
        run("UPDATE link_sections SET title=?,section_key=?,sort_order=?,is_active=?,updated_at=datetime('now') WHERE id=?",
            [$title,$key,$BODY['sort_order']??$section['sort_order'],$BODY['is_active']??$section['is_active'],$sid]);
        ok(['section' => q1("SELECT * FROM link_sections WHERE id=?", [$sid])]);
    }
    if ($METHOD === 'DELETE') {
        role_check($user, $EDIT);
        run("UPDATE buttons SET section_id=NULL,updated_at=datetime('now') WHERE section_id=?", [$sid]);
        run("DELETE FROM link_sections WHERE id=?", [$sid]);
        ok(['success' => true]);
    }
}

// ── BUTTONS ───────────────────────────────────────────────────────────
if (preg_match('#^/admin/pages/(\d+)/buttons$#', $path, $m)) {
    $user = auth(); $pid = (int)$m[1];
    if ($METHOD === 'GET') {
        ok(['buttons' => q(button_select_sql("b.page_id=?"), [$pid])]);
    }
    if ($METHOD === 'POST') {
        role_check($user, $EDIT);
        $label = button_label_from_body($BODY); $url = $BODY['url'] ?? '';
        if (!$label || !$url) err('Label and URL are required.');
        $max = db()->querySingle("SELECT COALESCE(MAX(sort_order),-1) FROM buttons WHERE page_id=$pid");
        $id = run("INSERT INTO buttons (page_id,section_id,label,url,icon,subtitle,style_variant,custom_icon_svg,opens_in_new_tab,sort_order,is_active,is_featured,enabled,start_at,end_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [$pid,section_id_from_body($BODY),$label,$url,button_icon_from_body($BODY),$BODY['subtitle']??null,$BODY['style_variant']??null,$BODY['custom_icon_svg']??null,$BODY['opens_in_new_tab']??1,$BODY['sort_order']??$max+1,
             button_visible_from_body($BODY),$BODY['is_featured']??0,$BODY['enabled']??1,
             $BODY['start_at']??null,$BODY['end_at']??null]);
        // SPA uses res.data.id
        ok(['id' => $id, 'button' => q1(button_select_sql("b.id=?"), [$id])] + (q1("SELECT * FROM buttons WHERE id=?", [$id]) ?? []));
    }
}
if (preg_match('#^/admin/pages/(\d+)/buttons/reorder$#', $path, $m) && $METHOD === 'PATCH') {
    $user = auth(); role_check($user, $EDIT);
    $order = $BODY['order'] ?? [];
    if (!is_array($order)) err('order must be an array.');
    $stmt = db()->prepare("UPDATE buttons SET sort_order=?,updated_at=datetime('now') WHERE id=? AND page_id=?");
    $pid = (int)$m[1];
    foreach ($order as $idx => $bid) {
        $stmt->bindValue(1, $idx); $stmt->bindValue(2, $bid); $stmt->bindValue(3, $pid); $stmt->execute();
    }
    ok(['success' => true]);
}
if (preg_match('#^/admin/buttons/(\d+)/duplicate$#', $path, $m) && $METHOD === 'POST') {
    $user = auth(); role_check($user, $EDIT); $bid = (int)$m[1];
    $btn = q1("SELECT * FROM buttons WHERE id=?", [$bid]);
    if (!$btn) err('Button not found.', 404);
    $id = run("INSERT INTO buttons (page_id,section_id,label,url,icon,subtitle,style_variant,custom_icon_svg,opens_in_new_tab,sort_order,is_active,is_featured,enabled,start_at,end_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
        [$btn['page_id'],$btn['section_id'],$btn['label'].' (Copy)',$btn['url'],$btn['icon'],$btn['subtitle']??null,$btn['style_variant']??null,$btn['custom_icon_svg']??null,$btn['opens_in_new_tab']??1,$btn['sort_order']+1,
         $btn['is_active'],$btn['is_featured'],$btn['enabled'],$btn['start_at'],$btn['end_at']]);
    ok(['id' => $id, 'button' => q1(button_select_sql("b.id=?"), [$id])] + (q1("SELECT * FROM buttons WHERE id=?", [$id]) ?? []));
}
if (preg_match('#^/admin/buttons/(\d+)$#', $path, $m)) {
    $user = auth(); $bid = (int)$m[1];
    $btn = q1("SELECT * FROM buttons WHERE id=?", [$bid]);
    if (!$btn) err('Button not found.', 404);
    if ($METHOD === 'PUT') {
        role_check($user, $EDIT);
        run("UPDATE buttons SET section_id=?,label=?,url=?,icon=?,subtitle=?,style_variant=?,custom_icon_svg=?,opens_in_new_tab=?,sort_order=?,is_active=?,is_featured=?,enabled=?,start_at=?,end_at=?,updated_at=datetime('now') WHERE id=?",
            [array_key_exists('section_id', $BODY) ? section_id_from_body($BODY) : $btn['section_id'],
             button_label_from_body($BODY, $btn),$BODY['url']??$btn['url'],button_icon_from_body($BODY, $btn),
             $BODY['subtitle']??($btn['subtitle']??null),$BODY['style_variant']??($btn['style_variant']??null),
             $BODY['custom_icon_svg']??($btn['custom_icon_svg']??null),$BODY['opens_in_new_tab']??($btn['opens_in_new_tab']??1),
             $BODY['sort_order']??$btn['sort_order'],button_visible_from_body($BODY, $btn),
             $BODY['is_featured']??$btn['is_featured'],$BODY['enabled']??$btn['enabled'],
             $BODY['start_at']??$btn['start_at'],$BODY['end_at']??$btn['end_at'],$bid]);
        ok(['button' => q1(button_select_sql("b.id=?"), [$bid])]);
    }
    if ($METHOD === 'POST') { // duplicate
        role_check($user, $EDIT);
        $id = run("INSERT INTO buttons (page_id,section_id,label,url,icon,subtitle,style_variant,custom_icon_svg,opens_in_new_tab,sort_order,is_active,is_featured,enabled,start_at,end_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [$btn['page_id'],$btn['section_id'],$btn['label'].' (Copy)',$btn['url'],$btn['icon'],$btn['subtitle']??null,$btn['style_variant']??null,$btn['custom_icon_svg']??null,$btn['opens_in_new_tab']??1,$btn['sort_order']+1,
             $btn['is_active'],$btn['is_featured'],$btn['enabled'],$btn['start_at'],$btn['end_at']]);
        ok(['id' => $id, 'button' => q1(button_select_sql("b.id=?"), [$id])] + (q1("SELECT * FROM buttons WHERE id=?", [$id]) ?? []));
    }
    if ($METHOD === 'DELETE') {
        role_check($user, $EDIT);
        run("DELETE FROM buttons WHERE id=?", [$bid]);
        ok(['success' => true]);
    }
}

// ── REDIRECTS ─────────────────────────────────────────────────────────
// SPA accesses res?.data?.rules
if (preg_match('#^/admin/pages/(\d+)/redirects$#', $path, $m)) {
    $user = auth(); role_check($user, $MGR); $pid = (int)$m[1];
    if ($METHOD === 'GET') {
        ok(['rules' => q("SELECT * FROM redirects WHERE page_id=? ORDER BY id DESC", [$pid])]);
    }
    if ($METHOD === 'POST') {
        $src = $BODY['source'] ?? ''; $dst = $BODY['destination'] ?? '';
        if (!$src || !$dst) err('Source and destination are required.');
        $id = run("INSERT INTO redirects (page_id,source,destination,is_permanent) VALUES (?,?,?,?)",
            [$pid, $src, $dst, $BODY['is_permanent']??0]);
        ok(q1("SELECT * FROM redirects WHERE id=?", [$id]) ?? []);
    }
}
if (preg_match('#^/admin/redirects/(\d+)$#', $path, $m) && $METHOD === 'DELETE') {
    $user = auth(); role_check($user, $MGR);
    run("DELETE FROM redirects WHERE id=?", [(int)$m[1]]);
    ok(['success' => true]);
}

// ── SHORTLINKS ────────────────────────────────────────────────────────
// SPA accesses res?.data?.shortlinks with l.slug field
if ($path === '/admin/shortlinks') {
    $user = auth(); role_check($user, $MGR);
    if ($METHOD === 'GET') {
        // Alias code→slug so SPA can use /go/{slug}
        ok(['shortlinks' => q("SELECT id, code AS slug, destination, label, utm_source, utm_medium, utm_campaign, clicks, is_active, created_at, updated_at FROM shortlinks ORDER BY created_at DESC")]);
    }
    if ($METHOD === 'POST') {
        $code = $BODY['code'] ?? $BODY['slug'] ?? '';
        $dst  = $BODY['destination'] ?? '';
        if (!$code || !$dst) err('Code and destination are required.');
        try {
            $id = run("INSERT INTO shortlinks (code,destination,label,utm_source,utm_medium,utm_campaign) VALUES (?,?,?,?,?,?)",
                [$code,$dst,$BODY['label']??null,$BODY['utm_source']??null,$BODY['utm_medium']??null,$BODY['utm_campaign']??null]);
            ok(q1("SELECT id, code AS slug, destination, label, clicks, is_active, created_at FROM shortlinks WHERE id=?", [$id]) ?? []);
        } catch (Exception $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) err('That shortlink code is already in use.', 409);
            throw $e;
        }
    }
}
if (preg_match('#^/admin/shortlinks/(\d+)$#', $path, $m) && $METHOD === 'DELETE') {
    $user = auth(); role_check($user, $MGR);
    run("DELETE FROM shortlinks WHERE id=?", [(int)$m[1]]);
    ok(['success' => true]);
}

// ── ANALYTICS ─────────────────────────────────────────────────────────
// SPA accesses res.data.analytics with fields: total_views, total_clicks, top_pages, views
if ($path === '/admin/analytics' && $METHOD === 'GET') {
    $user = auth(); role_check($user, $MGR);
    $days = min(max((int)($QUERY['period'] ?? 7), 1), 365);
    $totalClicks = (int)db()->querySingle("SELECT COUNT(*) FROM analytics WHERE event_type='click' AND created_at>=datetime('now','-{$days} days')");
    $totalViews  = (int)db()->querySingle("SELECT COUNT(*) FROM analytics WHERE event_type='pageview' AND created_at>=datetime('now','-{$days} days')");
    $topPages = q("SELECT a.page_id, p.title, p.slug, COUNT(*) AS views FROM analytics a JOIN pages p ON a.page_id=p.id WHERE a.event_type='pageview' AND a.created_at>=datetime('now','-{$days} days') GROUP BY a.page_id ORDER BY views DESC LIMIT 10");
    $viewsByDay = q("SELECT DATE(created_at) AS date, COUNT(*) AS count FROM analytics WHERE event_type='pageview' AND created_at>=datetime('now','-{$days} days') GROUP BY DATE(created_at) ORDER BY date ASC");
    ok([
        'analytics' => [
            'total_clicks' => $totalClicks,
            'total_views'  => $totalViews,
            'top_pages'    => $topPages,
            'views'        => $viewsByDay,
            'period'       => $days,
        ],
    ]);
}
if (preg_match('#^/admin/pages/(\d+)/analytics$#', $path, $m) && $METHOD === 'GET') {
    $user = auth(); $pid = (int)$m[1];
    $days = min(max((int)($QUERY['period'] ?? 7), 1), 365);
    $clicks  = (int)db()->querySingle("SELECT COUNT(*) FROM analytics WHERE page_id=$pid AND event_type='click' AND created_at>=datetime('now','-{$days} days')");
    $views   = (int)db()->querySingle("SELECT COUNT(*) FROM analytics WHERE page_id=$pid AND event_type='pageview' AND created_at>=datetime('now','-{$days} days')");
    $byBtn   = q("SELECT b.label, COUNT(*) AS clicks FROM analytics a JOIN buttons b ON a.button_id=b.id WHERE a.page_id=? AND a.event_type='click' AND a.created_at>=datetime('now','-{$days} days') GROUP BY a.button_id ORDER BY clicks DESC", [$pid]);
    ok([
        'analytics' => [
            'clicks'   => $clicks,
            'views'    => $views,
            'byButton' => $byBtn,
            'period'   => $days,
        ],
    ]);
}

// ── SUBSCRIBERS ───────────────────────────────────────────────────────
// SPA accesses res?.data?.rows with fields: email, first_name, store_slug
if ($path === '/admin/subscribers' && $METHOD === 'GET') {
    $user = auth(); role_check($user, $MGR);
    ok(['rows' => q("SELECT id, email, name AS first_name, source, is_active, created_at FROM subscribers ORDER BY created_at DESC")]);
}
if ($path === '/admin/subscribers/export' && $METHOD === 'GET') {
    $user = auth(); role_check($user, $MGR);
    $rows = q("SELECT email, name AS first_name, source, created_at FROM subscribers WHERE is_active=1 ORDER BY created_at DESC");
    $csv = "email,first_name,source,subscribed_at\n";
    foreach ($rows as $r) $csv .= "\"{$r['email']}\",\"{$r['first_name']}\",\"{$r['source']}\",\"{$r['created_at']}\"\n";
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="subscribers.csv"');
    echo $csv;
    exit;
}

// ── USERS ─────────────────────────────────────────────────────────────
// SPA accesses res?.data?.users
if ($path === '/admin/users') {
    $user = auth(); role_check($user, $MGR);
    if ($METHOD === 'GET') {
        ok(['users' => q("SELECT id, email, name, role, store_slug, is_active, created_at FROM users ORDER BY created_at DESC")]);
    }
    if ($METHOD === 'POST') {
        role_check($user, ['super_admin']);
        $email = $BODY['email'] ?? ''; $role = $BODY['role'] ?? ''; $pass = $BODY['password'] ?? '';
        if (!$email || !$role || !$pass) err('Email, role, and password are required.');
        $valid = ['super_admin','marketing_manager','store_manager','viewer'];
        if (!in_array($role, $valid)) err('Invalid role.');
        try {
            $id = run("INSERT INTO users (email,password_hash,name,role,store_slug) VALUES (?,?,?,?,?)",
                [strtolower(trim($email)), password_hash($pass, PASSWORD_BCRYPT), $BODY['name']??null, $role, $BODY['store_slug']??null]);
            ok(q1("SELECT id, email, name, role, store_slug, is_active, created_at FROM users WHERE id=?", [$id]) ?? []);
        } catch (Exception $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) err('That email is already in use.', 409);
            throw $e;
        }
    }
}
if (preg_match('#^/admin/users/(\d+)$#', $path, $m)) {
    $user = auth(); role_check($user, ['super_admin']); $uid = (int)$m[1];
    $target = q1("SELECT * FROM users WHERE id=?", [$uid]);
    if (!$target) err('User not found.', 404);
    if ($METHOD === 'PUT') {
        run("UPDATE users SET name=?,role=?,store_slug=?,is_active=?,updated_at=datetime('now') WHERE id=?",
            [$BODY['name']??$target['name'],$BODY['role']??$target['role'],
             $BODY['store_slug']??$target['store_slug'],$BODY['is_active']??$target['is_active'],$uid]);
        ok(q1("SELECT id, email, name, role, store_slug, is_active, created_at FROM users WHERE id=?", [$uid]) ?? []);
    }
    if ($METHOD === 'DELETE') {
        if ($uid === $user['id']) err('You cannot delete your own account.');
        run("DELETE FROM users WHERE id=?", [$uid]);
        ok(['success' => true]);
    }
}

// ── SETTINGS ──────────────────────────────────────────────────────────
// SPA accesses res?.data?.settings as a flat key→value object
if ($path === '/admin/settings') {
    $user = auth(); role_check($user, $MGR);
    if ($METHOD === 'GET') {
        $rows = q("SELECT key, value FROM settings");
        $settings = [];
        foreach ($rows as $r) $settings[$r['key']] = $r['value'];
        ok(['settings' => $settings]);
    }
    if ($METHOD === 'PUT') {
        $stmt = db()->prepare("INSERT OR REPLACE INTO settings (key,value,updated_at) VALUES (?,?,datetime('now'))");
        foreach ($BODY as $k => $v) {
            $stmt->bindValue(1, $k);
            $stmt->bindValue(2, (string)$v);
            $stmt->execute();
        }
        ok(['success' => true]);
    }
}

// ── BLOG (admin — used by blog-extension.js, not compiled SPA) ───────
if ($path === '/blog' || $path === '/blog/') {
    $user = auth();
    if ($METHOD === 'GET') {
        $status = $QUERY['status'] ?? null;
        $search = $QUERY['q'] ?? null;
        $limit  = (int)($QUERY['limit'] ?? 50);
        $offset = (int)($QUERY['offset'] ?? 0);
        $where  = ['archived_at IS NULL']; $params = [];
        if ($status && $status !== 'all') { $where[] = 'status=?'; $params[] = $status; }
        if ($search) { $where[] = "(title LIKE ? OR excerpt LIKE ? OR category LIKE ?)"; $s = "%$search%"; $params[] = $s; $params[] = $s; $params[] = $s; }
        $wSql = 'WHERE ' . implode(' AND ', $where);
        $cStmt = db()->prepare("SELECT COUNT(*) FROM blog_posts $wSql");
        foreach ($params as $i => $p) $cStmt->bindValue($i+1, $p);
        $total = $cStmt->execute()->fetchArray()[0];
        $posts = q("SELECT id,title,slug,status,category,tags,excerpt,cover_image,og_image,author_id,reading_time,published_at,scheduled_at,created_at,updated_at FROM blog_posts $wSql ORDER BY created_at DESC LIMIT $limit OFFSET $offset", $params);
        ok(['posts' => $posts, 'total' => $total]);
    }
    if ($METHOD === 'POST') {
        role_check($user, ['super_admin','marketing_manager']);
        $title = $BODY['title'] ?? ''; if (!$title) err('Title is required.');
        $slug  = $BODY['slug'] ?? preg_replace('/[^a-z0-9]+/', '-', strtolower($title)) . '-' . time();
        if (!empty($BODY['slug'])) $slug = preg_replace('/[^a-z0-9-]+/', '-', strtolower($BODY['slug']));
        $st  = $BODY['status'] ?? 'draft';
        $pub = $st === 'published' ? (new DateTime())->format('Y-m-d H:i:s') : null;
        $rt  = max(1, (int)round(str_word_count(strip_tags($BODY['content'] ?? '')) / 200));
        $tjson = is_array($BODY['tiptap_json'] ?? null) ? json_encode($BODY['tiptap_json']) : ($BODY['tiptap_json'] ?? null);
        $id = run("INSERT INTO blog_posts (title,slug,status,content,tiptap_json,excerpt,cover_image,og_image,category,tags,seo_title,seo_description,author_id,published_at,scheduled_at,reading_time) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [$title,$slug,$st,$BODY['content']??null,$tjson,$BODY['excerpt']??null,
             $BODY['cover_image']??null,$BODY['og_image']??null,$BODY['category']??null,
             $BODY['tags']??null,$BODY['seo_title']??null,$BODY['seo_description']??null,
             $user['id'],$pub,$BODY['scheduled_at']??null,$rt]);
        ok(['post' => q1("SELECT * FROM blog_posts WHERE id=?", [$id])]);
    }
}
if (preg_match('#^/blog/(\d+)$#', $path, $m)) {
    $user = auth(); $bid = (int)$m[1];
    $post = q1("SELECT * FROM blog_posts WHERE id=?", [$bid]);
    if (!$post) err('Post not found.', 404);
    if ($METHOD === 'GET') ok(['post' => $post]);
    if ($METHOD === 'PUT') {
        role_check($user, ['super_admin','marketing_manager']);
        $st  = $BODY['status'] ?? $post['status'];
        $pub = $post['published_at'];
        if ($st === 'published' && !$pub) $pub = (new DateTime())->format('Y-m-d H:i:s');
        $rt  = max(1, (int)round(str_word_count(strip_tags($BODY['content'] ?? $post['content'] ?? '')) / 200));
        $slug = $post['slug'];
        if (!empty($BODY['slug'])) $slug = preg_replace('/[^a-z0-9-]+/', '-', strtolower($BODY['slug']));
        $tjson = is_array($BODY['tiptap_json'] ?? null) ? json_encode($BODY['tiptap_json']) : ($BODY['tiptap_json'] ?? $post['tiptap_json']);
        try {
            run("UPDATE blog_posts SET title=?,slug=?,content=?,tiptap_json=?,excerpt=?,cover_image=?,og_image=?,category=?,tags=?,seo_title=?,seo_description=?,status=?,scheduled_at=?,published_at=?,reading_time=?,updated_at=datetime('now') WHERE id=?",
                [$BODY['title']??$post['title'],$slug,$BODY['content']??$post['content'],$tjson,
                 $BODY['excerpt']??$post['excerpt'],$BODY['cover_image']??$post['cover_image'],
                 $BODY['og_image']??$post['og_image'],$BODY['category']??$post['category'],
                 $BODY['tags']??$post['tags'],$BODY['seo_title']??$post['seo_title'],
                 $BODY['seo_description']??$post['seo_description'],
                 $st,$BODY['scheduled_at']??$post['scheduled_at'],$pub,$rt,$bid]);
        } catch (Exception $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) err('That slug is already in use.', 409);
            throw $e;
        }
        ok(['post' => q1("SELECT * FROM blog_posts WHERE id=?", [$bid])]);
    }
    if ($METHOD === 'DELETE') {
        role_check($user, ['super_admin','marketing_manager']);
        run("UPDATE blog_posts SET status='archived',archived_at=datetime('now'),updated_at=datetime('now') WHERE id=?", [$bid]);
        ok(['success' => true]);
    }
}

// ── IMAGE UPLOAD ──────────────────────────────────────────────────────
if ($path === '/upload' && $METHOD === 'POST') {
    $user = auth(); role_check($user, $EDIT);
    if (empty($_FILES['file'])) err('No file provided.');
    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) err('Upload error: ' . $file['error']);
    if ($file['size'] > 10 * 1024 * 1024) err('File too large (max 10 MB).');
    $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowed)) err('Invalid file type. Allowed: JPEG, PNG, GIF, WEBP.');
    $extMap = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
    $ext    = $extMap[$mime];
    $subdir = date('Y/m') . '/';
    $dir    = UPLOAD_DIR . $subdir;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $safe   = preg_replace('/[^a-z0-9\-]/', '', strtolower(pathinfo($file['name'], PATHINFO_FILENAME)));
    $safe   = trim($safe, '-') ?: 'image';
    $name   = uniqid() . '_' . $safe . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . $name)) err('Failed to save file.');
    header('Content-Type: application/json; charset=utf-8');
    ok(['url' => UPLOAD_URL . $subdir . $name, 'filename' => $name, 'size' => $file['size'], 'mime' => $mime]);
}

// ── SITEMAP ───────────────────────────────────────────────────────────
if ($path === '/sitemap.xml' && $METHOD === 'GET') {
    $posts = q("SELECT slug, updated_at FROM blog_posts WHERE status='published' AND archived_at IS NULL ORDER BY published_at DESC");
    $static = ['','menu.html','locations.html','order.html','about.html','happy-hour.html','stories/'];
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($static as $s) {
        echo "  <url><loc>" . SITE_URL . "/$s</loc><changefreq>weekly</changefreq><priority>0.8</priority></url>\n";
    }
    foreach ($posts as $p) {
        $mod = date('Y-m-d', strtotime($p['updated_at']));
        echo "  <url><loc>" . SITE_URL . "/stories/{$p['slug']}</loc><lastmod>$mod</lastmod><changefreq>monthly</changefreq><priority>0.7</priority></url>\n";
    }
    echo '</urlset>';
    exit;
}

// ── PUBLIC ────────────────────────────────────────────────────────────
if ($path === '/public/pages/all' && $METHOD === 'GET') {
    ok(['pages' => q("SELECT id, title, slug, headline, store_slug FROM pages WHERE is_active=1 ORDER BY sort_order ASC, id ASC")]);
}
if (preg_match('#^/public/pages/(.+)$#', $path, $m) && $METHOD === 'GET') {
    $slug = $m[1];
    $page = q1("SELECT * FROM pages WHERE slug=? AND is_active=1", [$slug]);
    if (!$page) err('Page not found.', 404);
    $now = (new DateTime())->format('Y-m-d H:i:s');
    $buttons = q(button_select_sql("b.page_id=? AND b.is_active=1 AND b.enabled=1 AND (b.start_at IS NULL OR b.start_at<=?) AND (b.end_at IS NULL OR b.end_at>=?) AND (s.id IS NULL OR s.is_active=1)"),
        [$page['id'], $now, $now]);
    run("INSERT INTO analytics (page_id,event_type,referrer,user_agent,ip) VALUES (?,?,?,?,?)",
        [$page['id'],'pageview',$_SERVER['HTTP_REFERER']??null,$_SERVER['HTTP_USER_AGENT']??null,$_SERVER['REMOTE_ADDR']??null]);
    $sections = q("SELECT * FROM link_sections WHERE page_id=? AND is_active=1 ORDER BY sort_order ASC, id ASC", [$page['id']]);
    ok(['page' => $page, 'buttons' => $buttons, 'sections' => $sections]);
}
if ($path === '/public/track' && $METHOD === 'POST') {
    run("INSERT INTO analytics (page_id,button_id,shortlink_id,event_type,referrer,user_agent,ip) VALUES (?,?,?,?,?,?,?)",
        [$BODY['page_id']??null,$BODY['button_id']??null,$BODY['shortlink_id']??null,
         $BODY['event_type']??'click',$_SERVER['HTTP_REFERER']??null,$_SERVER['HTTP_USER_AGENT']??null,$_SERVER['REMOTE_ADDR']??null]);
    ok(['success' => true]);
}
if ($path === '/public/subscribe' && $METHOD === 'POST') {
    $email = strtolower(trim($BODY['email'] ?? ''));
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) err('A valid email address is required.');
    try { run("INSERT INTO subscribers (email,name,source) VALUES (?,?,?)", [$email, $BODY['name']??null, $BODY['source']??null]); } catch (Exception $e) {}
    ok(['success' => true]);
}
if (preg_match('#^/public/shortlinks/(.+)$#', $path, $m) && $METHOD === 'GET') {
    $sl = q1("SELECT * FROM shortlinks WHERE code=? AND is_active=1", [$m[1]]);
    if (!$sl) err('Shortlink not found.', 404);
    run("UPDATE shortlinks SET clicks=clicks+1, updated_at=datetime('now') WHERE id=?", [$sl['id']]);
    run("INSERT INTO analytics (shortlink_id,event_type,referrer,user_agent,ip) VALUES (?,?,?,?,?)",
        [$sl['id'],'click',$_SERVER['HTTP_REFERER']??null,$_SERVER['HTTP_USER_AGENT']??null,$_SERVER['REMOTE_ADDR']??null]);
    header('Content-Type: text/html');
    http_response_code(302);
    header('Location: ' . $sl['destination']);
    exit;
}
// Legacy /public/posts (keep for backwards compat)
if ($path === '/public/posts' && $METHOD === 'GET') {
    ok(['posts' => q("SELECT id,title,slug,excerpt,cover_image,category,tags,reading_time,published_at FROM blog_posts WHERE status='published' AND archived_at IS NULL ORDER BY published_at DESC LIMIT 20")]);
}
if (preg_match('#^/public/posts/(.+)$#', $path, $m) && $METHOD === 'GET') {
    $post = q1("SELECT id,title,slug,content,excerpt,cover_image,og_image,category,tags,seo_title,seo_description,reading_time,published_at FROM blog_posts WHERE slug=? AND status='published' AND archived_at IS NULL", [$m[1]]);
    if (!$post) err('Post not found.', 404);
    ok(['post' => $post]);
}
// Public stories (canonical)
if ($path === '/public/stories' && $METHOD === 'GET') {
    $limit    = (int)($QUERY['limit'] ?? 12);
    $offset   = (int)($QUERY['offset'] ?? 0);
    $category = $QUERY['category'] ?? null;
    $where = ["status='published'", 'archived_at IS NULL']; $params = [];
    if ($category) { $where[] = 'category=?'; $params[] = $category; }
    $wSql   = 'WHERE ' . implode(' AND ', $where);
    $cStmt  = db()->prepare("SELECT COUNT(*) FROM blog_posts $wSql");
    foreach ($params as $i => $p) $cStmt->bindValue($i+1, $p);
    $total  = $cStmt->execute()->fetchArray()[0];
    $posts  = q("SELECT id,title,slug,excerpt,cover_image,og_image,category,tags,reading_time,published_at FROM blog_posts $wSql ORDER BY published_at DESC LIMIT $limit OFFSET $offset", $params);
    $cats   = q("SELECT DISTINCT category FROM blog_posts WHERE status='published' AND archived_at IS NULL AND category IS NOT NULL ORDER BY category ASC");
    ok(['posts' => $posts, 'total' => $total, 'categories' => array_column($cats, 'category')]);
}
if (preg_match('#^/public/stories/(.+)$#', $path, $m) && $METHOD === 'GET') {
    $post = q1("SELECT id,title,slug,content,excerpt,cover_image,og_image,category,tags,seo_title,seo_description,reading_time,published_at,updated_at FROM blog_posts WHERE slug=? AND status='published' AND archived_at IS NULL", [$m[1]]);
    if (!$post) err('Story not found.', 404);
    $related = q("SELECT id,title,slug,excerpt,cover_image,category,reading_time,published_at FROM blog_posts WHERE status='published' AND archived_at IS NULL AND id!=? AND (category=? OR category IS NULL) ORDER BY published_at DESC LIMIT 3",
        [$post['id'], $post['category'] ?? '']);
    ok(['post' => $post, 'related' => $related]);
}

// ── 404 ───────────────────────────────────────────────────────────────

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
    $buttons = q(button_select_sql("b.page_id=?"), [$page['id']]);
    $sections = q("SELECT * FROM link_sections WHERE page_id=? ORDER BY sort_order ASC, id ASC", [$page['id']]);
    $sets = q("SELECT key, value FROM settings");
    $settings = []; foreach ($sets as $r) $settings[$r['key']] = $r['value'];
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(200);
    echo json_encode(['ok'=>true,'preview'=>true,'data'=>['page'=>$page,'buttons'=>$buttons,'sections'=>$sections,'settings'=>$settings]]);
    exit;
}

if (preg_match('#^/public/links/(.+)$#', $path, $m) && $METHOD === 'GET') {
    $slug = $m[1];
    $page = q1("SELECT * FROM pages WHERE slug=? AND is_active=1", [$slug]);
    if (!$page) { http_response_code(404); echo json_encode(['ok'=>false,'message'=>'Page not found.']); exit; }
    $now     = (new DateTime())->format('Y-m-d H:i:s');
    $buttons = q(button_select_sql("b.page_id=? AND b.is_active=1 AND b.enabled=1 AND (b.start_at IS NULL OR b.start_at<=?) AND (b.end_at IS NULL OR b.end_at>=?) AND (s.id IS NULL OR s.is_active=1)"),
        [$page['id'], $now, $now]);
    $sections = q("SELECT * FROM link_sections WHERE page_id=? AND is_active=1 ORDER BY sort_order ASC, id ASC", [$page['id']]);
    $sets    = q("SELECT key, value FROM settings");
    $settings = []; foreach ($sets as $r) $settings[$r['key']] = $r['value'];
    // Record pageview
    run("INSERT INTO analytics (page_id,event_type,referrer,user_agent,ip) VALUES (?,?,?,?,?)",
        [$page['id'],'pageview',$_SERVER['HTTP_REFERER']??null,$_SERVER['HTTP_USER_AGENT']??null,$_SERVER['REMOTE_ADDR']??null]);
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(200);
    echo json_encode(['ok'=>true,'data'=>['page'=>$page,'buttons'=>$buttons,'sections'=>$sections,'settings'=>$settings]]);
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

err('Not found.', 404);
