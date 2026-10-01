<?php
declare(strict_types=1);
// Link Hub 2.0 — scheduled automation runner.
//
// Calls the exact same POST /admin/automations/run endpoint the Admin UI's
// "Run Automations Now" button calls, so there is exactly one code path for
// what an automation run actually does — this script never re-implements
// rule logic, it only decides when to trigger the existing one.
//
// CLI-only. See LINK_HUB_2_AUDIT_REPORT.md for the exact crontab line to
// add (a cron schedule expression can't be written literally inside a PHP
// block comment — the "star slash" sequence closes the comment early).
//
// Credentials are read from environment variables — never hardcode them
// here or commit them, and never put them directly in the crontab line
// (crontab -l is readable by the account owner and may be logged/backed up
// elsewhere in plaintext). Instead, set LINKHUB_ADMIN_EMAIL and
// LINKHUB_ADMIN_PASSWORD in a private env file outside the web root — see
// PRIVATE_ENV_PATH below, same pattern as
// scripts/broth-log-telegram-cron.php's BAKUDAN_TELEGRAM_ENV_FILE.
//
// Usage:
//   php run_linkhub_automations.php             (real run)
//   php run_linkhub_automations.php --dry-run   (checks lock + credentials only, never calls /run)

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'This script is CLI-only.';
    exit(1);
}

const DATA_DIR = '/home/hoale24new/bakudan-app/data';
const LOCK_FILE = DATA_DIR . '/automations_cron.lock';
const LOG_FILE  = DATA_DIR . '/automations_cron.log';
const MAX_RUNTIME_SECONDS = 60;
const PRIVATE_ENV_PATH = '/home/hoale24new/bakudan-app/config/linkhub-automations.env';

function load_private_env_file(string $path): void {
    $override = getenv('LINKHUB_AUTOMATIONS_ENV_FILE');
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

// Must be the canonical www host directly — the apex domain 301-redirects
// here, and PHP's stream wrapper doesn't reliably replay a POST body across
// that redirect (confirmed: it silently degrades to a GET, which this API
// only accepts for GET-safe endpoints, causing a misleading "Not found").
const API_BASE = 'https://www.bakudanramen.com/api';

function log_line(string $msg): void {
    $line = '[' . date('c') . '] ' . $msg . "\n";
    @file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    fwrite(STDOUT, $line);
}

// Guards the side-effecting execution below (lock file, live HTTP calls)
// so this script can be require_once'd from a test — e.g. to exercise
// load_private_env_file() in isolation — without acquiring the production
// lock file or making network requests. Same pattern as the main-guard in
// scripts/broth-log-telegram-cron.php.
if (realpath($argv[0] ?? '') === __FILE__) {
    load_private_env_file(PRIVATE_ENV_PATH);
    $isDryRun = in_array('--dry-run', $argv, true);

    // ── Execution lock ──────────────────────────────────────────────────
    // Prevents a slow run and the next scheduled tick from overlapping. A
    // lock file older than 3x the max runtime is assumed to be from a
    // crashed previous run (the process died without releasing it) —
    // logged loudly and skipped rather than silently overridden, since
    // forcing past a lock we can't explain is exactly the kind of "silent
    // side effect" this system is designed to avoid.
    if (!is_dir(DATA_DIR)) {
        log_line('ERROR: data directory does not exist: ' . DATA_DIR);
        exit(1);
    }
    $lockHandle = fopen(LOCK_FILE, 'c+');
    if (!$lockHandle) {
        log_line('ERROR: could not open lock file ' . LOCK_FILE);
        exit(1);
    }
    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        $age = file_exists(LOCK_FILE) ? (time() - (int)filemtime(LOCK_FILE)) : 0;
        if ($age > MAX_RUNTIME_SECONDS * 3) {
            log_line("WARNING: stale lock detected (age {$age}s, likely a crashed previous run). Skipping this run — investigate " . LOCK_FILE . " manually before the next scheduled tick.");
        } else {
            log_line('SKIPPED: another run is already in progress.');
        }
        exit(0);
    }
    ftruncate($lockHandle, 0);
    fwrite($lockHandle, (string)getmypid());
    fflush($lockHandle);
    touch(LOCK_FILE);

    set_time_limit(MAX_RUNTIME_SECONDS);
    $startedAt = microtime(true);
    $exitCode = 0;

    try {
        $email = getenv('LINKHUB_ADMIN_EMAIL');
        $password = getenv('LINKHUB_ADMIN_PASSWORD');
        if (!$email || !$password) {
            log_line('ERROR: LINKHUB_ADMIN_EMAIL / LINKHUB_ADMIN_PASSWORD environment variables are not set. Aborting — see the crontab setup instructions in LINK_HUB_2_AUDIT_REPORT.md.');
            $exitCode = 1;
        } elseif ($isDryRun) {
            log_line('DRY RUN: credentials present, lock acquired successfully. Would call POST /admin/automations/run now. No request made.');
        } else {
            // Login
            $loginCtx = stream_context_create(['http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => json_encode(['email' => $email, 'password' => $password]),
                'timeout' => 15,
                'ignore_errors' => true,
            ]]);
            $loginRaw = @file_get_contents(API_BASE . '/auth/login', false, $loginCtx);
            if ($loginRaw === false) {
                log_line('ERROR: login request failed (network/DNS error).');
                $exitCode = 1;
            } else {
                $loginData = json_decode($loginRaw, true);
                $token = $loginData['token'] ?? null;
                if (!$token) {
                    log_line('ERROR: login did not return a token. Response: ' . substr($loginRaw, 0, 300));
                    $exitCode = 1;
                } else {
                    // Run automations — idempotency and per-rule error
                    // handling are already implemented inside
                    // run_automation_rules() in api/index.php (each rule is
                    // evaluated independently; a failure in one rule type
                    // doesn't stop the others).
                    $runCtx = stream_context_create(['http' => [
                        'method' => 'POST',
                        'header' => "Content-Type: application/json\r\nAuthorization: Bearer $token\r\n",
                        'content' => '{}',
                        'timeout' => MAX_RUNTIME_SECONDS,
                        'ignore_errors' => true,
                    ]]);
                    $runRaw = @file_get_contents(API_BASE . '/admin/automations/run', false, $runCtx);
                    $httpCode = 0;
                    foreach ($http_response_header ?? [] as $h) {
                        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $httpCode = (int)$m[1];
                    }
                    if ($runRaw === false) {
                        log_line('ERROR: automations/run request failed (network error).');
                        $exitCode = 1;
                    } else {
                        $runData = json_decode($runRaw, true);
                        if ($httpCode !== 200 || !($runData['ok'] ?? false)) {
                            log_line('ERROR: automations/run returned HTTP ' . $httpCode . ': ' . substr($runRaw, 0, 500));
                            $exitCode = 1;
                        } else {
                            $results = $runData['results'] ?? $runData['data']['results'] ?? [];
                            if (!$results) {
                                log_line('OK: no active automation rules to run.');
                            } else {
                                foreach ($results as $r) {
                                    log_line('OK: rule "' . ($r['name'] ?? '?') . '" -> ' . ($r['summary'] ?? ''));
                                }
                            }
                        }
                    }
                }
            }
        }
    } catch (Throwable $e) {
        log_line('FATAL: ' . $e->getMessage());
        $exitCode = 1;
    } finally {
        $elapsed = round(microtime(true) - $startedAt, 2);
        log_line("Run finished in {$elapsed}s (exit code $exitCode).");
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
    exit($exitCode);
}
