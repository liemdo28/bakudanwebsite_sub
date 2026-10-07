<?php
declare(strict_types=1);

// Daily Broth Log Report - cron entrypoint (CLI only).
//
//   php scripts/broth-log-daily-report.php
//       One scheduler tick. Run it every 5 minutes from the server crontab at ANY server timezone:
//       it decides from the America/Chicago wall clock whether the 11:00 PM Texas report is due
//       (so the schedule stays correct across daylight-saving changes), generates it once from real
//       production data, emails it once, and records the outcome. Exits 0 when nothing is due.
//   php scripts/broth-log-daily-report.php --status
//       Prints the most recent execution records (never prints report bodies or secrets).
//   php scripts/broth-log-daily-report.php --next
//       Prints the next scheduled Texas run and the business date it will report.
//   php scripts/broth-log-daily-report.php --dry-run --date=YYYY-MM-DD [--out=/path/report]
//       Builds the report for a date from real data and writes <out>.html / <out>.txt (or prints the
//       text). Sends nothing and writes nothing to the database.
//
// Environment (private env file, same mechanism as the Telegram scripts):
//   BROTH_LOG_DAILY_REPORT_ENABLED   must be true for a tick to generate/send anything (default off)
//   BROTH_LOG_DAILY_REPORT_TO        recipient (default liem.dt0208@gmail.com)
//   BROTH_LOG_DAILY_REPORT_FROM      sender address (default broth-log-reports@bakudanramen.com)
// No credentials are required for the default transport (the host's local MTA).

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const PRIVATE_TELEGRAM_ENV_PATH = '/home/hoale24new/bakudan-app/config/broth-log-telegram.env';

function load_private_env_file_report(string $path): void {
    $override = getenv('BAKUDAN_TELEGRAM_ENV_FILE');
    if (is_string($override) && trim($override) !== '') $path = trim($override);
    if ($path === '' || !is_readable($path)) return;
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) return;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (!preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $m)) continue;
        $value = trim($m[2]);
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }
        if (getenv($m[1]) === false) {
            putenv($m[1] . '=' . $value);
            $_ENV[$m[1]] = $value;
        }
    }
}

load_private_env_file_report(PRIVATE_TELEGRAM_ENV_PATH);
define('DB_PATH', getenv('BAKUDAN_DB_PATH') ?: '/home/hoale24new/bakudan-app/data/bakudan.db');
require_once __DIR__ . '/../api/broth-log-daily-report.php';

function db(): SQLite3 {
    static $db = null;
    if ($db) return $db;
    // --dry-run opens the database READ-ONLY at the SQLite level, so it cannot write even by mistake.
    $readOnly = in_array('--dry-run', $GLOBALS['argv'] ?? [], true);
    $db = $readOnly ? new SQLite3(DB_PATH, SQLITE3_OPEN_READONLY) : new SQLite3(DB_PATH);
    $db->enableExceptions(true);
    if (!$readOnly) $db->exec('PRAGMA journal_mode=WAL;');
    $db->busyTimeout(5000);
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
    return q($sql, $params)[0] ?? null;
}

function run(string $sql, array $params = []): int {
    $stmt = db()->prepare($sql);
    foreach ($params as $i => $v) $stmt->bindValue($i + 1, $v);
    $stmt->execute();
    return db()->lastInsertRowID();
}

function report_arg(array $argv, string $name): ?string {
    foreach ($argv as $arg) {
        if (str_starts_with($arg, $name . '=')) return substr($arg, strlen($name) + 1);
    }
    return null;
}

function report_log(string $message): void {
    echo '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message . PHP_EOL;
}

try {
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    if (in_array('--next', $argv, true)) {
        echo json_encode(bldr_next_run($now) + ['recipient' => broth_log_copilot_env('BROTH_LOG_DAILY_REPORT_TO', BLDR_DEFAULT_RECIPIENT), 'enabled' => bldr_enabled()], JSON_PRETTY_PRINT) . PHP_EOL;
        exit(0);
    }

    if (in_array('--status', $argv, true)) {
        bldr_migrate(db());
        foreach (q("SELECT business_date, recipient, report_status, email_status, email_attempts, generate_attempts, started_at, completed_at, email_accepted_at, error_summary FROM broth_log_daily_report_runs ORDER BY business_date DESC LIMIT 10") as $row) {
            echo json_encode($row) . PHP_EOL;
        }
        exit(0);
    }

    if (in_array('--dry-run', $argv, true)) {
        $date = report_arg($argv, '--date');
        if ($date === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new RuntimeException('--dry-run requires --date=YYYY-MM-DD');
        $report = bldr_build_report($date, bldr_fetch_tables(), $now);
        $out = report_arg($argv, '--out');
        if ($out !== null) {
            file_put_contents($out . '.html', bldr_render_html($report));
            file_put_contents($out . '.txt', bldr_render_text($report));
            report_log('dry run written to ' . $out . '.html / .txt (nothing sent, nothing stored)');
        } else {
            echo bldr_render_text($report);
        }
        exit(0);
    }

    $result = bldr_run($now);
    if ($result['action'] !== 'not_due') report_log(json_encode($result));
    exit(in_array($result['action'], ['report_failed', 'email_failed', 'needs_review', 'gave_up'], true) ? 1 : 0);
} catch (Throwable $e) {
    $message = function_exists('broth_log_copilot_sanitize_error') ? broth_log_copilot_sanitize_error($e->getMessage()) : 'daily report failed';
    fwrite(STDERR, '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message . PHP_EOL);
    exit(1);
}
