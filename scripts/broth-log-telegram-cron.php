<?php
declare(strict_types=1);

/**
 * Cron entrypoint for server-side Broth Log Telegram alerts.
 *
 * Required env on hosting:
 * - TELEGRAM_WEBHOOK_SECRET or TELEGRAM_CRON_SECRET
 * - BROTH_LOG_TELEGRAM_ALERT_ENDPOINT, optional, defaults to https://www.bakudanramen.com/api/broth-log/telegram/alerts
 *
 * Telegram bot token/chat id are read only by api/index.php.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../api/broth-log-core.php';

const PRIVATE_TELEGRAM_ENV_PATH = '/home/hoale24new/bakudan-app/config/broth-log-telegram.env';
const LOCK_TTL_SECONDS = 240;

function load_private_env_file(string $path): void {
    $override = getenv('BAKUDAN_TELEGRAM_ENV_FILE');
    if (is_string($override) && trim($override) !== '') {
        $path = trim($override);
    }
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

load_private_env_file(PRIVATE_TELEGRAM_ENV_PATH);

function post_alerts(array $alerts): array {
    $endpoint = trim((string)(getenv('BROTH_LOG_TELEGRAM_ALERT_ENDPOINT') ?: 'https://www.bakudanramen.com/api/broth-log/telegram/alerts'));
    $parts = parse_url($endpoint);
    if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
        throw new RuntimeException('BROTH_LOG_TELEGRAM_ALERT_ENDPOINT must be an https URL');
    }
    $secret = trim((string)(getenv('TELEGRAM_WEBHOOK_SECRET') ?: getenv('TELEGRAM_CRON_SECRET') ?: getenv('BROTH_LOG_TELEGRAM_CRON_SECRET') ?: ''));
    if ($secret === '') throw new RuntimeException('TELEGRAM_WEBHOOK_SECRET is required');
    if (preg_match('/[\r\n]/', $secret)) throw new RuntimeException('Telegram webhook secret is invalid');
    $payload = json_encode(['alerts' => $alerts]);
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'timeout' => 20,
        'ignore_errors' => true,
        'header' => "Content-Type: application/json\r\nX-Broth-Log-Cron-Secret: $secret\r\n",
        'content' => $payload,
    ]]);
    $raw = @file_get_contents($endpoint, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) $code = (int)$m[1];
    if ($raw === false || $code < 200 || $code >= 300) throw new RuntimeException("alert endpoint failed with HTTP $code");
    $json = json_decode($raw, true);
    if (!is_array($json)) throw new RuntimeException('alert endpoint returned invalid JSON');
    return $json;
}

function acquire_lock() {
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bakudan-broth-log-telegram.lock';
    if (is_file($path) && time() - (int)filemtime($path) > LOCK_TTL_SECONDS) {
        @unlink($path);
    }
    $fh = fopen($path, 'c');
    if (!$fh) throw new RuntimeException('Unable to open cron lock');
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another broth Telegram cron run is active');
    }
    ftruncate($fh, 0);
    fwrite($fh, (string)getmypid());
    return $fh;
}

function option_enabled(array $argv, string $option): bool {
    return in_array($option, $argv, true);
}

if (realpath($argv[0] ?? '') === __FILE__) {
    try {
        $lock = acquire_lock();
        $dryRun = option_enabled($argv, '--dry-run');
        $date = broth_log_business_date();
        foreach (array_slice($argv, 1) as $arg) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $arg)) {
                $date = $arg;
                break;
            }
        }
        $alerts = [];
        foreach (array_keys(BROTH_LOG_BRANCHES) as $branch) {
            $alerts = array_merge($alerts, broth_log_critical_alerts_for_branch($branch, $date));
        }
        if ($dryRun) {
            echo json_encode(['dry_run' => true, 'date' => $date, 'critical_alerts' => count($alerts)], JSON_PRETTY_PRINT) . PHP_EOL;
            exit(0);
        }
        $result = post_alerts($alerts);
        echo json_encode(['date' => $date, 'critical_alerts' => count($alerts), 'result' => $result], JSON_PRETTY_PRINT) . PHP_EOL;
        flock($lock, LOCK_UN);
        fclose($lock);
    } catch (Throwable $e) {
        $message = preg_replace('/[0-9]{8,12}:AA[A-Za-z0-9_-]{20,}/', '[redacted-token]', $e->getMessage()) ?: 'cron failed';
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}
