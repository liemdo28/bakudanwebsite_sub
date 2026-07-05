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
// here or commit them. Set LINKHUB_ADMIN_EMAIL and LINKHUB_ADMIN_PASSWORD
// in the crontab entry itself (crontab -e).
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
// Must be the canonical www host directly — the apex domain 301-redirects
// here, and PHP's stream wrapper doesn't reliably replay a POST body across
// that redirect (confirmed: it silently degrades to a GET, which this API
// only accepts for GET-safe endpoints, causing a misleading "Not found").
const API_BASE = 'https://www.bakudanramen.com/api';

$isDryRun = in_array('--dry-run', $argv, true);

function log_line(string $msg): void {
    $line = '[' . date('c') . '] ' . $msg . "\n";
    @file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    fwrite(STDOUT, $line);
}

// ── Execution lock ──────────────────────────────────────────────────────
// Prevents a slow run and the next scheduled tick from overlapping. A lock
// file older than 3x the max runtime is assumed to be from a crashed
// previous run (the process died without releasing it) — logged loudly and
// skipped rather than silently overridden, since forcing past a lock we
// can't explain is exactly the kind of "silent side effect" this system is
// designed to avoid.
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
                // Run automations — idempotency and per-rule error handling
                // are already implemented inside run_automation_rules() in
                // api/index.php (each rule is evaluated independently; a
                // failure in one rule type doesn't stop the others).
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
