<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const PRIVATE_TELEGRAM_ENV_PATH = '/home/hoale24new/bakudan-app/config/broth-log-telegram.env';
define('DB_PATH', getenv('BAKUDAN_DB_PATH') ?: '/home/hoale24new/bakudan-app/data/bakudan.db');

function load_private_env_file_worker(string $path): void {
    $override = getenv('BAKUDAN_TELEGRAM_ENV_FILE');
    if (is_string($override) && trim($override) !== '') $path = trim($override);
    if ($path === '' || !is_readable($path)) return;
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) return;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (!preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $m)) continue;
        $key = $m[1];
        $value = trim($m[2]);
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }
        if (getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

load_private_env_file_worker(PRIVATE_TELEGRAM_ENV_PATH);
require_once __DIR__ . '/../api/broth-log-copilot.php';

function db(): SQLite3 {
    static $db = null;
    if ($db) return $db;
    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $db = new SQLite3(DB_PATH);
    $db->enableExceptions(true);
    $db->exec('PRAGMA journal_mode=WAL;');
    $db->exec('PRAGMA foreign_keys=ON;');
    $db->busyTimeout(3000);
    broth_log_copilot_migrate($db);
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

function run(string $sql, array $params = []): int {
    $stmt = db()->prepare($sql);
    foreach ($params as $i => $v) $stmt->bindValue($i + 1, $v);
    $stmt->execute();
    return db()->lastInsertRowID();
}

function worker_arg(array $argv, string $name): ?string {
    foreach ($argv as $arg) {
        if (str_starts_with($arg, $name . '=')) return substr($arg, strlen($name) + 1);
    }
    return null;
}

try {
    $dryRun = in_array('--dry-run', $argv, true);
    $nowArg = worker_arg($argv, '--now');
    $now = $nowArg ? new DateTimeImmutable($nowArg, new DateTimeZone('UTC')) : new DateTimeImmutable('now', new DateTimeZone('UTC'));
    if (!broth_log_copilot_enabled()) {
        echo json_encode(['enabled' => false, 'processed' => 0], JSON_PRETTY_PRINT) . PHP_EOL;
        exit(0);
    }
    $inbox = [];
    $due = broth_log_copilot_due_escalations($now);
    $results = [];
    if (!$dryRun) {
        $inbox = broth_log_copilot_process_inbox(25, $now);
        foreach ($due as $action) $results[] = broth_log_copilot_apply_escalation_action($action, $now);
        run("DELETE FROM broth_log_bot_inbox WHERE received_at < datetime('now', '-" . BROTH_LOG_COPILOT_RETENTION_RAW_DAYS . " days')");
        run("DELETE FROM broth_log_conversation_context WHERE expires_at < datetime('now')");
    }
    echo json_encode([
        'enabled' => true,
        'dry_run' => $dryRun,
        'inbox_processed' => count($inbox),
        'due' => count($due),
        'results' => $dryRun ? array_map(fn($a) => ['action' => $a['action'], 'incident_id' => $a['incident']['incident_id']], $due) : $results,
    ], JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
    $message = preg_replace('/[0-9]{8,12}:AA[A-Za-z0-9_-]{20,}/', '[redacted-token]', $e->getMessage()) ?: 'worker failed';
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
