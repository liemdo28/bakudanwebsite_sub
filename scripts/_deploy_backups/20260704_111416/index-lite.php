<?php
declare(strict_types=1);

define('DB_PATH', '/home/hoale24new/bakudan-app/data/bakudan.db');
define('JWT_SECRET', getenv('JWT_SECRET') ?: 'bakudan-dev-secret-change-in-production');
define('JWT_TTL', 7 * 24 * 3600);
define('SITE_URL', 'https://bakudanramen.com');

error_reporting(0);
ini_set('display_errors', '0');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function json_out(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function json_error(string $message, int $status = 500): void {
    json_out(['ok' => false, 'message' => $message, 'error' => $message], $status);
}

function db(): SQLite3 {
    static $db = null;
    if ($db) return $db;
    $db = new SQLite3(DB_PATH, SQLITE3_OPEN_READWRITE);
    $db->enableExceptions(true);
    return $db;
}

function q(string $sql, array $params = []): array {
    $stmt = db()->prepare($sql);
    foreach ($params as $i => $v) $stmt->bindValue($i + 1, $v);
    $res = $stmt->execute();
    $rows = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;
    return $rows;
}

function q1(string $sql, array $params = []): ?array {
    $rows = q($sql, $params);
    return $rows[0] ?? null;
}

function b64url(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function jwt_encode(array $payload): string {
    $header = b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $body = b64url(json_encode($payload));
    $sig = b64url(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));
    return "$header.$body.$sig";
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path = $_GET['route'] ?? (isset($_GET['r']) ? '/' . ltrim((string)$_GET['r'], '/') : preg_replace('#^/api#', '', $uri));
    $path = preg_replace('#^/index-lite\.php#', '', $path);
    $path = rtrim($path, '/') ?: '/';
    $body = json_decode(file_get_contents('php://input'), true) ?: [];

    if ($path === '/config' && $method === 'GET') {
        json_out([
            'ok' => true,
            'version' => '3.0.1-lite',
            'siteUrl' => SITE_URL,
            'iconKeys' => ['order','website','email','events','instagram','facebook','directions','phone','menu','gift','ticket','external','blog','social'],
        ]);
    }

    if ($path === '/auth/login' && $method === 'POST') {
        $email = strtolower(trim((string)($body['email'] ?? '')));
        $password = (string)($body['password'] ?? '');
        if (!$email || !$password) json_error('Email and password are required.', 400);
        $user = q1('SELECT * FROM users WHERE email=? AND is_active=1', [$email]);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            json_error('Invalid email or password.', 401);
        }
        $token = jwt_encode([
            'id' => $user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
            'exp' => time() + JWT_TTL,
        ]);
        json_out([
            'ok' => true,
            'success' => true,
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'name' => $user['name'] ?? '',
                'role' => $user['role'],
                'store_slug' => $user['store_slug'] ?? null,
            ],
        ]);
    }

    if (preg_match('#^/public/links/(.+)$#', $path, $m) && $method === 'GET') {
        $slug = $m[1];
        $page = q1('SELECT * FROM pages WHERE slug=? AND is_active=1', [$slug]);
        if (!$page) json_error('Page not found.', 404);
        $now = (new DateTime())->format('Y-m-d H:i:s');
        $buttons = q(
            "SELECT b.*, b.label AS title, b.icon AS icon_key, b.is_active AS visible
             FROM buttons b
             WHERE b.page_id=? AND b.is_active=1 AND b.enabled=1
               AND (b.start_at IS NULL OR b.start_at<=?)
               AND (b.end_at IS NULL OR b.end_at>=?)
             ORDER BY b.sort_order ASC, b.id ASC",
            [$page['id'], $now, $now]
        );
        $settings = [];
        foreach (q('SELECT key, value FROM settings') as $row) $settings[$row['key']] = $row['value'];
        json_out(['ok' => true, 'data' => ['page' => $page, 'buttons' => $buttons, 'settings' => $settings]]);
    }

    if (str_starts_with($path, '/public/analytics/') && $method === 'POST') {
        json_out(['ok' => true]);
    }

    json_error('Not found.', 404);
} catch (Throwable $e) {
    json_error('Internal server error: ' . $e->getMessage(), 500);
}
