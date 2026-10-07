<?php
declare(strict_types=1);

// Level-aware alert routing admin tool (CLI only).
//
//   php scripts/broth-log-routing.php report [--branches=B1,B2,B3]
//       Read-only. Prints, per branch and escalation level, exactly who would receive an alert
//       (masked Telegram ids), whether the Ops group would also get it, and who is pending onboarding.
//       Sends nothing, writes nothing.
//
//   php scripts/broth-log-routing.php configure --branches=B1[,B2,B3] [--apply]
//       Installs the approved store/level recipient plan below for the listed branches and switches
//       them to mode 'level_routing'. Dry-run unless --apply is given. People are looked up by
//       display name in broth_log_authorized_users and the run aborts if a name is missing or
//       ambiguous; it never invents a Telegram id. Anyone listed as pending (Omar) is recorded
//       without an id and stays unresolved until `attach` is run with his verified id.
//       Never changes roles, incidents, ACKs, deliveries or any history.
//
//   php scripts/broth-log-routing.php attach --name="Omar" --telegram-user-id=<verified id> [--apply]
//       Binds a pending recipient to a verified Telegram user id. The user must already exist in
//       broth_log_authorized_users (authorize them first) and have a registered private chat.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const PRIVATE_TELEGRAM_ENV_PATH = '/home/hoale24new/bakudan-app/config/broth-log-telegram.env';

function load_private_env_file_routing(string $path): void {
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

load_private_env_file_routing(PRIVATE_TELEGRAM_ENV_PATH);
define('DB_PATH', getenv('BAKUDAN_DB_PATH') ?: '/home/hoale24new/bakudan-app/data/bakudan.db');
require_once __DIR__ . '/../api/broth-log-copilot.php';

// Dry-run support: an in-memory SQLite copy of the few tables routing reads, filled from the real
// database opened READ-ONLY. Every simulated write lands here and is discarded with the process, so
// a dry run can show the exact resolved matrix without ever writing to the real database.
function routing_enable_preview(): void {
    $src = new SQLite3(DB_PATH, SQLITE3_OPEN_READONLY);
    $src->enableExceptions(true);
    $src->busyTimeout(3000);
    $mem = new SQLite3(':memory:');
    $mem->enableExceptions(true);
    broth_log_copilot_migrate($mem);
    foreach (['broth_log_authorized_users', 'broth_log_private_chat_registrations', 'broth_log_manager_branch_authorizations', 'broth_log_routing_rules', 'broth_log_branch_alert_mode', 'broth_log_alert_recipients'] as $table) {
        $res = $src->query("SELECT * FROM {$table}");
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $cols = array_keys($row);
            $stmt = $mem->prepare('INSERT OR REPLACE INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')');
            foreach (array_values($row) as $i => $v) $stmt->bindValue($i + 1, $v);
            $stmt->execute();
        }
    }
    $src->close();
    $GLOBALS['ROUTING_PREVIEW_DB'] = $mem;
}

function db(): SQLite3 {
    static $db = null;
    if (isset($GLOBALS['ROUTING_PREVIEW_DB'])) return $GLOBALS['ROUTING_PREVIEW_DB'];
    if ($db) return $db;
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
    return q($sql, $params)[0] ?? null;
}

function run(string $sql, array $params = []): int {
    $stmt = db()->prepare($sql);
    foreach ($params as $i => $v) $stmt->bindValue($i + 1, $v);
    $stmt->execute();
    return db()->lastInsertRowID();
}

function routing_arg(array $argv, string $name): ?string {
    foreach ($argv as $arg) {
        if (str_starts_with($arg, $name . '=')) return substr($arg, strlen($name) + 1);
    }
    return null;
}

function routing_mask(string $id): string {
    return $id === '' ? '(none)' : substr(hash('sha256', $id), 0, 6);
}

// The approved plan. Each entry: [name, kind, min_level]. 'pending' names have no Telegram id yet.
// CEO/Admin are test observers from the first alert; there is deliberately NO CEO Level-3 entry
// during testing (a min_level=3 row would be all it takes later).
const ROUTING_PLAN = [
    'B1' => [['David', 'manager', 1], ['Hoang Le', 'test_observer', 1], ['Liem Do', 'test_observer', 1]],
    'B2' => [['Edgar', 'manager', 1], ['David', 'manager', 2], ['Omar', 'manager', 2], ['Hoang Le', 'test_observer', 1], ['Liem Do', 'test_observer', 1]],
    'B3' => [['Miles', 'manager', 1], ['David', 'manager', 2], ['Omar', 'manager', 2], ['Hoang Le', 'test_observer', 1], ['Liem Do', 'test_observer', 1]],
];
const ROUTING_PENDING_NAMES = ['Omar'];
const ROUTING_TITLES = ['Hoang Le' => 'CEO', 'Liem Do' => 'Back Office Admin'];

function routing_find_user(string $name): array {
    $rows = q("SELECT telegram_user_id, display_name, role, active FROM broth_log_authorized_users WHERE lower(display_name) = lower(?) OR lower(display_name) LIKE lower(?) || ' %'", [$name, $name]);
    $rows = array_values(array_filter($rows, fn($r) => (int)$r['active'] === 1));
    if (count($rows) !== 1) {
        throw new RuntimeException("Cannot resolve '{$name}' to exactly one active authorized user (found " . count($rows) . ')');
    }
    return $rows[0];
}

function routing_print_report(array $branches): void {
    foreach (broth_log_copilot_routing_matrix($branches) as $branch => $info) {
        echo "== {$branch} (mode: {$info['mode']}) ==\n";
        foreach ($info['levels'] as $level => $row) {
            $names = array_map(fn($r) => $r['display_name'] . ' [' . routing_mask($r['telegram_user_id']) . '; ' . implode('+', $r['kinds']) . ']', $row['recipients']);
            echo "  L{$level}: " . ($names ? implode(', ', $names) : '(no direct recipient)') . " | Ops group: {$row['ops_group']}\n";
        }
        foreach ($info['pending'] as $p) {
            echo "  PENDING onboarding: {$p['display_name']} ({$p['kind']}, from L{$p['min_level']}) - no verified Telegram id, not receiving alerts\n";
        }
        if ($info['mode'] !== BROTH_LOG_COPILOT_LEVEL_ROUTING_MODE) {
            echo "  NOTE: branch is not in level_routing mode - the table above is what WOULD apply once switched; live delivery still uses the legacy path.\n";
        }
    }
}

try {
    $command = $argv[1] ?? '';
    $apply = in_array('--apply', $argv, true);
    $branchArg = routing_arg($argv, '--branches');
    $branches = $branchArg !== null
        ? array_values(array_intersect(array_map('trim', explode(',', strtoupper($branchArg))), ['B1', 'B2', 'B3']))
        : ['B1', 'B2', 'B3'];

    if ($command === 'report') {
        routing_print_report($branches);
        exit(0);
    }

    if ($command === 'configure') {
        if ($branchArg === null || empty($branches)) throw new RuntimeException('configure requires --branches=B1[,B2,B3]');
        if (!$apply) routing_enable_preview();
        $planned = [];
        foreach ($branches as $branch) {
            foreach (ROUTING_PLAN[$branch] as [$name, $kind, $minLevel]) {
                if (in_array($name, ROUTING_PENDING_NAMES, true)) {
                    // Already pending OR already attached to a verified id: never add a second row.
                    if (q1("SELECT 1 FROM broth_log_alert_recipients WHERE branch=? AND kind=? AND lower(display_name)=lower(?)", [$branch, $kind, $name])) continue;
                    $planned[] = [$branch, $kind, $minLevel, '', $name];
                    continue;
                }
                $user = routing_find_user($name);
                $planned[] = [$branch, $kind, $minLevel, (string)$user['telegram_user_id'], (string)$user['display_name']];
            }
        }
        echo ($apply ? 'APPLYING' : 'DRY RUN (in-memory preview; the real database is not written)') . " - branches: " . implode(',', $branches) . "\n";
        foreach ($planned as [$branch, $kind, $minLevel, $uid, $name]) {
            echo "  {$branch} {$kind} from L{$minLevel}: {$name} [" . routing_mask($uid) . "]" . ($uid === '' ? ' PENDING' : '') . "\n";
        }
        foreach (ROUTING_TITLES as $name => $title) echo "  title: {$name} => {$title} (role unchanged)\n";
        {
            db()->exec('BEGIN IMMEDIATE');
            try {
                foreach ($planned as [$branch, $kind, $minLevel, $uid, $name]) {
                    run("INSERT INTO broth_log_alert_recipients (branch, kind, min_level, telegram_user_id, display_name, active)
                         VALUES (?,?,?,?,?,1)
                         ON CONFLICT(branch, kind, telegram_user_id, display_name) DO UPDATE SET min_level=excluded.min_level, active=1, updated_at=datetime('now')",
                        [$branch, $kind, $minLevel, $uid, $uid === '' ? $name : '']);
                }
                foreach (ROUTING_TITLES as $name => $title) {
                    $user = routing_find_user($name);
                    run("UPDATE broth_log_authorized_users SET title=? WHERE telegram_user_id=?", [$title, $user['telegram_user_id']]);
                }
                foreach ($branches as $branch) {
                    run("INSERT INTO broth_log_branch_alert_mode (branch, mode, updated_at) VALUES (?, ?, datetime('now'))
                         ON CONFLICT(branch) DO UPDATE SET mode=excluded.mode, updated_at=excluded.updated_at", [$branch, BROTH_LOG_COPILOT_LEVEL_ROUTING_MODE]);
                }
                db()->exec('COMMIT');
            } catch (Throwable $e) {
                db()->exec('ROLLBACK');
                throw $e;
            }
        }
        echo "\n" . ($apply ? '' : "RESULT AFTER THIS PLAN (preview):\n");
        routing_print_report($branches);
        exit(0);
    }

    if ($command === 'attach') {
        $name = routing_arg($argv, '--name');
        $uid = routing_arg($argv, '--telegram-user-id');
        if (!$name || !$uid || !ctype_digit($uid)) throw new RuntimeException('attach requires --name=<pending name> and numeric --telegram-user-id=<verified id>');
        $user = q1("SELECT telegram_user_id, active FROM broth_log_authorized_users WHERE telegram_user_id=?", [$uid]);
        if (!$user || (int)$user['active'] !== 1) throw new RuntimeException('That Telegram user is not an active authorized user - authorize them first');
        if (!q1("SELECT 1 FROM broth_log_private_chat_registrations WHERE telegram_user_id=?", [$uid])) throw new RuntimeException('That user has no registered private chat - they must start the bot privately first');
        $pending = q("SELECT id, branch FROM broth_log_alert_recipients WHERE telegram_user_id='' AND lower(display_name)=lower(?)", [$name]);
        if (empty($pending)) throw new RuntimeException("No pending recipient named '{$name}'");
        echo ($apply ? 'APPLYING' : 'DRY RUN (no changes)') . ": attaching [" . routing_mask($uid) . "] to pending '{$name}' on " . implode(',', array_column($pending, 'branch')) . "\n";
        if ($apply) {
            foreach ($pending as $row) {
                run("UPDATE broth_log_alert_recipients SET telegram_user_id=?, updated_at=datetime('now') WHERE id=?", [$uid, $row['id']]);
            }
        }
        routing_print_report(array_values(array_unique(array_column($pending, 'branch'))));
        exit(0);
    }

    fwrite(STDERR, "Usage: report | configure --branches=... [--apply] | attach --name=... --telegram-user-id=... [--apply]\n");
    exit(2);
} catch (Throwable $e) {
    $message = function_exists('broth_log_copilot_sanitize_error')
        ? broth_log_copilot_sanitize_error($e->getMessage())
        : 'routing tool failed';
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
