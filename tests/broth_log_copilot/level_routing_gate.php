<?php
declare(strict_types=1);

// Level-aware alert routing: recipient resolution per store x escalation level, duplicate
// prevention, test observers that keep their CEO/Admin role, Ops-group fallback, Omar-pending
// onboarding, and that temperature and missing_shift incidents share one resolver.

require_once __DIR__ . '/../../api/broth-log-copilot.php';

$dbPath = sys_get_temp_dir() . '/broth-log-routing-gate-' . bin2hex(random_bytes(4)) . '.sqlite';
define('TEST_DB_PATH', $dbPath);

function db(): SQLite3 {
    static $db = null;
    if ($db) return $db;
    $db = new SQLite3(TEST_DB_PATH);
    $db->enableExceptions(true);
    $db->busyTimeout(3000);
    $db->exec('PRAGMA journal_mode=WAL;');
    $db->exec('PRAGMA foreign_keys=ON;');
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
function q1(string $sql, array $params = []): ?array { return q($sql, $params)[0] ?? null; }
function run(string $sql, array $params = []): int {
    $stmt = db()->prepare($sql);
    foreach ($params as $i => $v) $stmt->bindValue($i + 1, $v);
    $stmt->execute();
    return db()->lastInsertRowID();
}
function expect_true(bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException("FAIL $label");
    echo "PASS $label\n";
}
function expect_eq($actual, $expected, string $label): void {
    if ($actual !== $expected) throw new RuntimeException("FAIL $label expected=" . var_export($expected, true) . " actual=" . var_export($actual, true));
    echo "PASS $label\n";
}

const DAVID = '710001', EDGAR = '710002', MILES = '710003', HOANG = '710004', LIEM = '710005', ROGUE = '710006', OMAR = '710007';
const OPS_CHAT = 'ops-group-routing-test';

function routing_cli(string $args): string {
    putenv('BAKUDAN_DB_PATH=' . TEST_DB_PATH);
    putenv('BAKUDAN_TELEGRAM_ENV_FILE=' . sys_get_temp_dir() . '/does-not-exist.env');
    $out = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../scripts/broth-log-routing.php') . ' ' . $args . ' 2>&1', $out, $code);
    return $code . "\n" . implode("\n", $out);
}

function names_at(string $branch, int $level, ?string $createdAt = null): array {
    $names = array_column(broth_log_copilot_resolve_recipients($branch, $level, $createdAt), 'display_name');
    sort($names);
    return $names;
}

// Chats that received a given incident (status sent), as display names; Ops shown as 'OPS'.
function receivers(string $incidentId, ?string $messageKind = null): array {
    $rows = $messageKind === null
        ? q("SELECT chat_id FROM broth_log_outbound_deliveries WHERE incident_id=? AND status='sent'", [$incidentId])
        : q("SELECT chat_id FROM broth_log_outbound_deliveries WHERE incident_id=? AND status='sent' AND message_kind=?", [$incidentId, $messageKind]);
    $byChat = [OPS_CHAT => 'OPS'];
    foreach (q("SELECT au.display_name, pcr.private_chat_id FROM broth_log_authorized_users au JOIN broth_log_private_chat_registrations pcr ON pcr.telegram_user_id=au.telegram_user_id") as $u) {
        $byChat[$u['private_chat_id']] = $u['display_name'];
    }
    // Distinct people who got at least one message (per-message counts are asserted separately).
    $names = array_values(array_unique(array_map(fn($r) => $byChat[$r['chat_id']] ?? $r['chat_id'], $rows)));
    sort($names);
    return $names;
}

function fresh_temp_incident(string $branch, string $tag): string {
    run("UPDATE broth_log_incidents SET active_key=NULL WHERE active_key IS NOT NULL");
    $id = broth_log_copilot_create_incident([
        'branch' => $branch, 'responseId' => 'resp-' . $tag, 'stationKey' => 'prepAreaCooler', 'station' => 'Prep Area Cooler',
        'severity' => 'critical', 'businessDate' => '2026-08-20', 'businessTime' => '08:00', 'temperature' => '120F',
        'target' => '<= 40F', 'correctiveAction' => 'Move product and re-temp',
    ]);
    run("UPDATE broth_log_incidents SET created_at='2026-01-01 00:00:00', level_entered_at='2026-01-01 00:00:00' WHERE incident_id=?", [$id]);
    return $id;
}

// Escalate/remind an incident by running every due action at $now (a fixed fake clock far from real time).
function drive(string $incidentId, string $nowUtc): void {
    $now = new DateTimeImmutable($nowUtc, new DateTimeZone('UTC'));
    foreach (broth_log_copilot_due_escalations($now) as $action) {
        if ($action['incident']['incident_id'] !== $incidentId) continue;
        broth_log_copilot_apply_escalation_action_with_notification($action, $now);
    }
}

try {
    putenv('TELEGRAM_COPILOT_ENABLED=true');
    putenv('TELEGRAM_CALLBACK_SECRET=unit-callback-secret');
    putenv('TELEGRAM_BOT_TOKEN=unit-test-token');
    putenv('BROTH_LOG_COPILOT_ENV=production');
    putenv('TELEGRAM_COPILOT_CHAT_ID=');
    putenv('BROTH_LOG_OPS_GROUP_COPY_BRANCHES=');
    putenv('BROTH_LOG_SHIFT_ALERTS_ENABLED=true');
    putenv('BROTH_LOG_SHIFT_ALERT_BRANCHES=B1,B2,B3');
    broth_log_copilot_migrate(db());
    broth_log_copilot_migrate(db());
    expect_true((bool)q1("SELECT name FROM sqlite_master WHERE type='table' AND name='broth_log_alert_recipients'"), 'migration creates the recipients table (idempotent on re-run)');
    expect_true(in_array('title', array_column(q("PRAGMA table_info(broth_log_authorized_users)"), 'name'), true), 'migration adds the additive title column');

    $sent = [];
    $failChats = [];
    $GLOBALS['BROTH_LOG_COPILOT_TELEGRAM_TRANSPORT'] = function (string $method, array $payload, string $token) use (&$sent, &$failChats): array {
        $chat = (string)($payload['chat_id'] ?? '');
        if (in_array($chat, $failChats, true)) return ['sent' => false, 'error' => 'chat unreachable'];
        $sent[] = ['chat' => $chat, 'text' => (string)($payload['text'] ?? '')];
        return ['sent' => true];
    };

    foreach (['B1', 'B2', 'B3'] as $b) {
        for ($l = 1; $l <= 3; $l++) {
            run("INSERT INTO broth_log_routing_rules (branch,stage,level,telegram_user_ids,chat_id,active) VALUES (?,?,?,?,?,1)", [$b, 'pilot', $l, json_encode(['999']), OPS_CHAT]);
        }
    }
    // The production-shaped user set. Hoang is stored with role 'owner' here to prove an observer
    // needs no manager role; the Rogue manager is an unassigned role='manager' on B1.
    $users = [
        [DAVID, 'David', 'manager', ['B1', 'B2', 'B3']],
        [EDGAR, 'Edgar', 'manager', ['B2']],
        [MILES, 'Miles', 'manager', ['B3']],
        [HOANG, 'Hoang Le', 'owner', ['B1', 'B2', 'B3']],
        [LIEM, 'Liem Do', 'owner', ['B1', 'B2', 'B3']],
        [ROGUE, 'Rogue Manager', 'manager', ['B1']],
    ];
    foreach ($users as [$id, $name, $role, $branches]) {
        run("INSERT INTO broth_log_authorized_users (telegram_user_id,display_name,role,allowed_branches,active,created_at) VALUES (?,?,?,?,1,'2025-01-01 00:00:00')", [$id, $name, $role, json_encode($branches)]);
        run("INSERT INTO broth_log_private_chat_registrations (telegram_user_id, private_chat_id) VALUES (?,?)", [$id, 'dm-' . $id]);
    }

    // ---------------------------------------------------------------- legacy is untouched until a branch is switched
    expect_eq(broth_log_copilot_level_routing_enabled('B1'), false, 'a branch with no mode row is not in level_routing');
    expect_eq(broth_log_copilot_manager_dm_chat_ids('B1'), ['dm-' . DAVID, 'dm-' . ROGUE], 'legacy resolver is unchanged: every role=manager on the branch (before switching)');
    $legacyId = fresh_temp_incident('B1', 'legacy');
    broth_log_copilot_notify_incident($legacyId);
    expect_eq(receivers($legacyId), ['David', 'OPS', 'Rogue Manager'], 'legacy branch: Ops group + every manager, exactly as before this change');
    run("UPDATE broth_log_incidents SET state='resolved', active_key=NULL WHERE incident_id=?", [$legacyId]);

    // ---------------------------------------------------------------- configure (dry-run, apply, idempotent)
    $dry = routing_cli('configure --branches=B1,B2,B3');
    expect_true(str_starts_with($dry, "0\nDRY RUN"), 'configure without --apply is a dry run');
    expect_eq((int)q1("SELECT COUNT(*) c FROM broth_log_alert_recipients")['c'], 0, 'dry run writes nothing');
    expect_true(str_contains($dry, 'RESULT AFTER THIS PLAN') && str_contains($dry, 'L2: David') && str_contains($dry, 'PENDING onboarding: Omar') && str_contains($dry, '(mode: level_routing)'), 'dry run previews the full resulting recipient matrix from an in-memory copy');
    expect_eq((int)q1("SELECT COUNT(*) c FROM broth_log_branch_alert_mode")['c'], 0, 'dry run switches no branch');
    $applied = routing_cli('configure --branches=B1,B2,B3 --apply');
    expect_true(str_starts_with($applied, "0\nAPPLYING"), 'configure --apply succeeds');
    $countAfterFirst = (int)q1("SELECT COUNT(*) c FROM broth_log_alert_recipients")['c'];
    routing_cli('configure --branches=B1,B2,B3 --apply');
    expect_eq((int)q1("SELECT COUNT(*) c FROM broth_log_alert_recipients")['c'], $countAfterFirst, 'configure is idempotent: re-applying adds no duplicate rows (including the pending Omar rows)');
    expect_eq($countAfterFirst, 13, 'plan rows: B1 has 3, B2 has 5, B3 has 5 (Omar pending included) = 13');

    expect_eq(q1("SELECT role FROM broth_log_authorized_users WHERE telegram_user_id=?", [HOANG])['role'], 'owner', 'Hoang Le keeps his primary role - not changed to manager');
    expect_eq(q1("SELECT role FROM broth_log_authorized_users WHERE telegram_user_id=?", [LIEM])['role'], 'owner', 'Liem Do keeps his Admin/Owner role - not changed to manager');
    expect_eq(q1("SELECT title FROM broth_log_authorized_users WHERE telegram_user_id=?", [HOANG])['title'], 'CEO', 'Hoang Le is titled CEO');
    expect_eq(q1("SELECT title FROM broth_log_authorized_users WHERE telegram_user_id=?", [LIEM])['title'], 'Back Office Admin', 'Liem Do is titled Back Office Admin');

    // ---------------------------------------------------------------- recipient matrix: every store x every level
    $expectB1 = ['David', 'Hoang Le', 'Liem Do'];
    $expectB2L1 = ['Edgar', 'Hoang Le', 'Liem Do'];
    $expectB2L2 = ['David', 'Edgar', 'Hoang Le', 'Liem Do'];
    $expectB3L1 = ['Hoang Le', 'Liem Do', 'Miles'];
    $expectB3L2 = ['David', 'Hoang Le', 'Liem Do', 'Miles'];
    foreach ([1, 2, 3] as $l) expect_eq(names_at('B1', $l), $expectB1, "B1 L{$l}: David (Level 1, stays) + test observers Hoang/Liem");
    expect_eq(names_at('B2', 1), $expectB2L1, 'B2 L1: Edgar + observers - David is NOT a Level 1 recipient');
    expect_eq(names_at('B2', 2), $expectB2L2, 'B2 L2: David joins (Omar pending, absent) cumulative with Edgar + observers');
    expect_eq(names_at('B2', 3), $expectB2L2, 'B2 L3: no CEO Level 3 delivery during testing - same set as L2');
    expect_eq(names_at('B3', 1), $expectB3L1, 'B3 L1: Miles + observers - David is NOT a Level 1 recipient');
    expect_eq(names_at('B3', 2), $expectB3L2, 'B3 L2: David joins (Omar pending, absent) cumulative with Miles + observers');
    expect_eq(names_at('B3', 3), $expectB3L2, 'B3 L3: same set as L2 (CEO L3 disabled)');
    expect_true(!in_array('Rogue Manager', array_merge(names_at('B1', 1), names_at('B1', 3)), true), 'an unassigned role=manager user is no longer an implicit recipient');
    foreach (['B1', 'B2', 'B3'] as $b) expect_eq(broth_log_copilot_level_routing_enabled($b), true, "{$b} switched to level_routing by configure");

    $matrix = broth_log_copilot_routing_matrix();
    expect_eq($matrix['B2']['pending'], [['display_name' => 'Omar', 'kind' => 'manager', 'min_level' => 2]], 'B2 reports Omar as pending onboarding (Level 2), with no id');
    expect_eq($matrix['B3']['pending'], [['display_name' => 'Omar', 'kind' => 'manager', 'min_level' => 2]], 'B3 reports Omar as pending onboarding (Level 2), with no id');
    expect_eq($matrix['B1']['pending'], [], 'B1 has no pending recipient');
    expect_eq($matrix['B2']['levels'][1]['ops_group'], 'not_sent', 'matrix: Ops group is not sent when direct recipients exist');
    expect_true(str_contains(routing_cli('report'), 'PENDING onboarding: Omar'), 'the read-only report lists Omar as pending onboarding');
    expect_true(!str_contains(routing_cli('report'), OMAR), 'the report never prints a raw Telegram id');
    expect_eq((int)q1("SELECT COUNT(*) c FROM broth_log_authorized_users WHERE lower(display_name) LIKE '%omar%'")['c'], 0, 'no Omar user was invented in authorized_users');

    // ---------------------------------------------------------------- B1: David is Level 1, observers get the same DM, no Ops duplicate
    $sent = [];
    $b1 = fresh_temp_incident('B1', 'b1');
    broth_log_copilot_notify_incident($b1);
    expect_eq(receivers($b1), ['David', 'Hoang Le', 'Liem Do'], 'B1 initial alert: David + Hoang + Liem, Rogue manager and the Ops group excluded');
    expect_eq(count($sent), 3, 'B1 initial alert: exactly one message per person (no duplicates)');
    $davidText = array_values(array_filter($sent, fn($m) => $m['chat'] === 'dm-' . DAVID))[0]['text'];
    $hoangText = array_values(array_filter($sent, fn($m) => $m['chat'] === 'dm-' . HOANG))[0]['text'];
    expect_eq($hoangText, $davidText, 'the observer receives the identical manager-style message');
    drive($b1, '2026-01-01 00:11:00');
    expect_eq(receivers($b1), ['David', 'Hoang Le', 'Liem Do'], 'B1 after escalation to L2: same people, David stays Level 1 owner (no new recipient)');
    expect_eq((int)q1("SELECT current_level FROM broth_log_incidents WHERE incident_id=?", [$b1])['current_level'], 2, 'B1 incident escalated to level 2');
    expect_true(!in_array('OPS', receivers($b1), true), 'B1: the Ops group never received this incident');
    expect_eq(q("SELECT event_type FROM broth_log_incident_events WHERE incident_id=? AND event_type='ops_group_fallback'", [$b1]), [], 'B1: no Ops fallback event when direct recipients exist');

    // ---------------------------------------------------------------- B2: Edgar L1; David joins ONLY after escalation
    $sent = [];
    $b2 = fresh_temp_incident('B2', 'b2');
    broth_log_copilot_notify_incident($b2);
    expect_eq(receivers($b2), ['Edgar', 'Hoang Le', 'Liem Do'], 'B2 initial alert: Edgar + observers only - David has NOT been alerted yet');
    drive($b2, '2026-01-01 00:05:00');
    expect_true(!in_array('David', receivers($b2), true), 'B2 before the L1->L2 threshold: still no David');
    drive($b2, '2026-01-01 00:11:00');
    expect_eq((int)q1("SELECT current_level FROM broth_log_incidents WHERE incident_id=?", [$b2])['current_level'], 2, 'B2 incident escalated to level 2');
    expect_eq(receivers($b2), ['David', 'Edgar', 'Hoang Le', 'Liem Do'], 'B2 after escalation: David joins; Edgar keeps receiving (cumulative); observers unchanged; Omar absent');
    expect_eq(array_count_values(array_column(q("SELECT chat_id FROM broth_log_outbound_deliveries WHERE incident_id=? AND message_kind='escalation'", [$b2]), 'chat_id'))['dm-' . DAVID] ?? 0, 1, 'B2 escalation: David receives exactly one escalation message');
    expect_true(!in_array('OPS', receivers($b2), true), 'B2: Ops group not sent the incident');
    drive($b2, '2026-01-01 00:16:30');
    expect_eq((int)q1("SELECT current_level FROM broth_log_incidents WHERE incident_id=?", [$b2])['current_level'], 3, 'B2 incident escalated on to level 3 (L2 lasts 300s)');
    expect_eq((int)q1("SELECT COUNT(*) c FROM broth_log_outbound_deliveries WHERE incident_id=? AND message_kind='escalation' AND chat_id=?", [$b2, 'dm-' . DAVID])['c'], 2, 'B2: David receives one message per escalation level (L2, L3) - never a duplicate within a level');
    expect_eq(receivers($b2), ['David', 'Edgar', 'Hoang Le', 'Liem Do'], 'B2 at L3: same people, no CEO Level 3 delivery during testing, no Ops copy');

    // ---------------------------------------------------------------- B3
    $sent = [];
    $b3 = fresh_temp_incident('B3', 'b3');
    broth_log_copilot_notify_incident($b3);
    expect_eq(receivers($b3), ['Hoang Le', 'Liem Do', 'Miles'], 'B3 initial alert: Miles + observers only');
    drive($b3, '2026-01-01 00:11:00');
    expect_eq(receivers($b3), ['David', 'Hoang Le', 'Liem Do', 'Miles'], 'B3 after escalation: David joins (cumulative); Omar absent; no Ops copy');

    // ---------------------------------------------------------------- Missing Shift uses the very same resolver
    $sent = [];
    $msId = broth_log_copilot_create_missing_shift_incident('B2', '2026-08-22', 'AM');
    run("UPDATE broth_log_incidents SET created_at='2026-01-01 00:00:00', level_entered_at='2026-01-01 00:00:00' WHERE incident_id=?", [$msId]);
    broth_log_copilot_notify_incident($msId);
    expect_eq(receivers($msId), ['Edgar', 'Hoang Le', 'Liem Do'], 'Missing Shift B2 initial: same recipients as a Temperature alert');
    drive($msId, '2026-01-01 00:11:00');
    expect_eq(receivers($msId), ['David', 'Edgar', 'Hoang Le', 'Liem Do'], 'Missing Shift B2 at L2: David joins exactly like a Temperature alert');
    $msAck = broth_log_copilot_ack($msId, ['telegram_user_id' => EDGAR, 'allowed_branch_list' => ['B2'], 'display_name' => 'Edgar']);
    expect_eq(q1("SELECT state FROM broth_log_incidents WHERE incident_id=?", [$msId])['state'], 'acknowledged', 'Missing Shift ACK is still ownership only (state acknowledged, not closed)');

    // ---------------------------------------------------------------- ACK semantics + broadcast reach only real recipients
    $sent = [];
    $ackId = fresh_temp_incident('B1', 'ack');
    broth_log_copilot_notify_incident($ackId);
    expect_eq(array_map(fn($c) => $c, array_values(array_unique(array_map(fn($c) => $c, broth_log_copilot_incident_known_destinations($ackId))))), ['dm-' . DAVID, 'dm-' . HOANG, 'dm-' . LIEM], 'known destinations for a level-routed incident = the people who actually received it (observers included, Ops/Rogue excluded)');
    $ack = broth_log_copilot_ack($ackId, ['telegram_user_id' => DAVID, 'allowed_branch_list' => ['B1'], 'display_name' => 'David']);
    expect_true(!empty($ack['ok']), 'David ACK succeeds');
    expect_eq(q1("SELECT state FROM broth_log_incidents WHERE incident_id=?", [$ackId])['state'], 'resolved', 'Temperature ACK still fully resolves the incident');
    broth_log_copilot_broadcast_incident_update($ackId, 'resolved', DAVID);
    expect_eq(receivers($ackId, 'resolved_status'), ['Hoang Le', 'Liem Do'], 'resolution broadcast reaches the observers (not the actor, not Ops, not the Rogue manager)');

    // ---------------------------------------------------------------- Ops group fallback
    foreach (['Level 1 recipients deactivated' => 'B1'] as $label => $b) {
        run("UPDATE broth_log_alert_recipients SET active=0 WHERE branch=?", [$b]);
        $sent = [];
        $fb = fresh_temp_incident($b, 'fallback');
        broth_log_copilot_notify_incident($fb);
        expect_eq(receivers($fb), ['OPS'], "Ops fallback: no eligible direct recipient -> the Ops group receives the alert ({$label})");
        expect_eq(q1("SELECT event_type FROM broth_log_incident_events WHERE incident_id=? AND event_type='ops_group_fallback'", [$fb])['event_type'] ?? '', 'ops_group_fallback', 'Ops fallback is audited');
        run("UPDATE broth_log_alert_recipients SET active=1 WHERE branch=?", [$b]);
        run("UPDATE broth_log_incidents SET state='resolved', active_key=NULL WHERE incident_id=?", [$fb]);
    }
    // All direct deliveries failing -> Ops fallback (alert must not be silently lost)
    $failChats = ['dm-' . DAVID, 'dm-' . HOANG, 'dm-' . LIEM];
    $sent = [];
    $failId = fresh_temp_incident('B1', 'allfail');
    broth_log_copilot_notify_incident($failId);
    expect_eq(receivers($failId), ['OPS'], 'Ops fallback: every direct delivery failed -> Ops group receives the alert');
    expect_eq(json_decode(q1("SELECT event_json FROM broth_log_incident_events WHERE incident_id=? AND event_type='ops_group_fallback'", [$failId])['event_json'], true)['reason'] ?? '', 'all_direct_deliveries_failed', 'fallback reason recorded as all_direct_deliveries_failed');
    // One direct recipient reachable is enough: no Ops fallback
    $failChats = ['dm-' . HOANG, 'dm-' . LIEM];
    $partialId = fresh_temp_incident('B1', 'partialfail');
    broth_log_copilot_notify_incident($partialId);
    expect_eq(receivers($partialId), ['David'], 'a partially failing recipient set does not trigger the Ops fallback');
    $failChats = [];
    // Explicit group-copy configuration
    putenv('BROTH_LOG_OPS_GROUP_COPY_BRANCHES=B2');
    $copyId = fresh_temp_incident('B2', 'copy');
    broth_log_copilot_notify_incident($copyId);
    expect_eq(receivers($copyId), ['Edgar', 'Hoang Le', 'Liem Do', 'OPS'], 'explicit group-copy config: the Ops group also receives the alert, in addition to direct recipients');
    expect_eq(broth_log_copilot_routing_matrix(['B2'])['B2']['levels'][1]['ops_group'], 'copy', 'matrix shows Ops group as an explicit copy');
    $b1CopyId = fresh_temp_incident('B1', 'nocopy');
    broth_log_copilot_notify_incident($b1CopyId);
    expect_true(!in_array('OPS', receivers($b1CopyId), true), 'group copy is per-branch: B1 (not listed) still has no Ops copy');
    putenv('BROTH_LOG_OPS_GROUP_COPY_BRANCHES=');
    expect_eq(broth_log_copilot_ops_group_copy_branches(), [], 'no copy allowlist configured by default');
    putenv('BROTH_LOG_OPS_GROUP_COPY_BRANCHES=*,ALL,b9');
    expect_eq(broth_log_copilot_ops_group_copy_branches(), [], 'a wildcard / unknown entry never expands into "every branch"');
    putenv('BROTH_LOG_OPS_GROUP_COPY_BRANCHES=');

    // ---------------------------------------------------------------- Omar: pending until a verified id exists, then Level 2 only
    $preOmarIncident = fresh_temp_incident('B2', 'preomar');
    $attachDeniedUnknown = routing_cli('attach --name=Omar --telegram-user-id=' . OMAR . ' --apply');
    expect_true(str_starts_with($attachDeniedUnknown, '1'), 'attach refuses an id that is not an authorized user');
    expect_eq((int)q1("SELECT COUNT(*) c FROM broth_log_alert_recipients WHERE telegram_user_id=''")['c'], 2, 'Omar rows remain pending after the refused attach');
    run("INSERT INTO broth_log_authorized_users (telegram_user_id,display_name,role,allowed_branches,active,created_at) VALUES (?,?,?,?,1,'2026-06-01 00:00:00')", [OMAR, 'Omar', 'manager', json_encode(['B2', 'B3'])]);
    $attachDeniedNoChat = routing_cli('attach --name=Omar --telegram-user-id=' . OMAR . ' --apply');
    expect_true(str_starts_with($attachDeniedNoChat, '1'), 'attach refuses an authorized user who has not started the bot privately');
    run("INSERT INTO broth_log_private_chat_registrations (telegram_user_id, private_chat_id) VALUES (?,?)", [OMAR, 'dm-' . OMAR]);
    $attachDry = routing_cli('attach --name=Omar --telegram-user-id=' . OMAR);
    expect_true(str_contains($attachDry, 'DRY RUN'), 'attach without --apply is a dry run');
    expect_eq((int)q1("SELECT COUNT(*) c FROM broth_log_alert_recipients WHERE telegram_user_id=''")['c'], 2, 'attach dry run changes nothing');
    $attachOk = routing_cli('attach --name=Omar --telegram-user-id=' . OMAR . ' --apply');
    expect_true(str_starts_with($attachOk, '0'), 'attach succeeds once Omar is authorized and has a private chat');
    expect_eq((int)q1("SELECT COUNT(*) c FROM broth_log_alert_recipients WHERE telegram_user_id=''")['c'], 0, 'no pending rows remain');
    expect_eq(names_at('B2', 1), $expectB2L1, 'after onboarding, Omar is still NOT a Level 1 recipient on B2');
    expect_eq(names_at('B3', 1), $expectB3L1, 'after onboarding, Omar is still NOT a Level 1 recipient on B3');
    expect_eq(names_at('B2', 2), ['David', 'Edgar', 'Hoang Le', 'Liem Do', 'Omar'], 'after onboarding, Omar joins B2 at Level 2 alongside David');
    expect_eq(names_at('B3', 2), ['David', 'Hoang Le', 'Liem Do', 'Miles', 'Omar'], 'after onboarding, Omar joins B3 at Level 2 alongside David');
    expect_eq(names_at('B1', 3), $expectB1, 'Omar is not on B1 at any level');
    expect_eq(names_at('B2', 2, '2026-01-01 00:00:00'), $expectB2L2, 'cutover rule: Omar (authorized 2026-06-01) is not eligible for an incident created before that');
    expect_eq(names_at('B2', 2, '2026-07-01 00:00:00'), ['David', 'Edgar', 'Hoang Le', 'Liem Do', 'Omar'], 'cutover rule: an incident created after his authorization includes Omar at L2');
    $configureAgain = routing_cli('configure --branches=B2,B3 --apply');
    expect_eq((int)q1("SELECT COUNT(*) c FROM broth_log_alert_recipients WHERE branch='B2' AND lower(display_name)='omar'")['c'], 1, 're-running configure after attach does not add a second Omar row');

    // ---------------------------------------------------------------- Future Level 3 support + eligibility rules
    run("INSERT INTO broth_log_alert_recipients (branch, kind, min_level, telegram_user_id, display_name) VALUES ('B3','manager',3,?, '')", [ROGUE]);
    run("UPDATE broth_log_authorized_users SET allowed_branches=? WHERE telegram_user_id=?", [json_encode(['B1', 'B3']), ROGUE]);
    expect_true(!in_array('Rogue Manager', names_at('B3', 2), true) && in_array('Rogue Manager', names_at('B3', 3), true), 'a min_level=3 recipient (the future CEO L3 shape) resolves at Level 3 only');
    run("DELETE FROM broth_log_alert_recipients WHERE telegram_user_id=?", [ROGUE]);
    run("UPDATE broth_log_authorized_users SET active=0 WHERE telegram_user_id=?", [HOANG]);
    expect_true(!in_array('Hoang Le', names_at('B1', 1), true), 'a deactivated observer stops receiving alerts');
    run("UPDATE broth_log_authorized_users SET active=1 WHERE telegram_user_id=?", [HOANG]);
    run("UPDATE broth_log_authorized_users SET allowed_branches=? WHERE telegram_user_id=?", [json_encode(['B1']), HOANG]);
    expect_true(!in_array('Hoang Le', names_at('B2', 1), true) && in_array('Hoang Le', names_at('B1', 1), true), 'branch authorization is still required: an observer removed from B2 no longer receives B2 alerts');
    run("UPDATE broth_log_authorized_users SET allowed_branches=? WHERE telegram_user_id=?", [json_encode(['B1', 'B2', 'B3']), HOANG]);
    run("DELETE FROM broth_log_private_chat_registrations WHERE telegram_user_id=?", [LIEM]);
    expect_true(!in_array('Liem Do', names_at('B1', 1), true), 'an observer without a registered private chat cannot be resolved to a destination');
    run("INSERT INTO broth_log_private_chat_registrations (telegram_user_id, private_chat_id) VALUES (?,?)", [LIEM, 'dm-' . LIEM]);
    // The same person on two kinds of row is still one recipient / one message.
    run("INSERT INTO broth_log_alert_recipients (branch, kind, min_level, telegram_user_id, display_name) VALUES ('B1','manager',1,?, '')", [HOANG]);
    expect_eq(count(broth_log_copilot_resolve_recipients('B1', 1)), 3, 'a person with both a manager and an observer row on a branch is resolved once');
    expect_eq(count(broth_log_copilot_resolve_recipient_chat_ids('B1', 1)), 3, 'duplicate recipient rows never produce a duplicate destination');
    run("DELETE FROM broth_log_alert_recipients WHERE branch='B1' AND kind='manager' AND telegram_user_id=?", [HOANG]);
    // fail-closed when created_at is unresolvable
    expect_eq(names_at('B1', 1, ''), [], 'an unresolvable incident created_at fails closed (no direct recipient)');

    // ---------------------------------------------------------------- /start + /alerts are observer-aware (presentation only)
    $matrixSnapshot = function (): string {
        $out = [];
        foreach (['B1', 'B2', 'B3'] as $b) {
            foreach ([1, 2, 3] as $l) $out[$b][$l] = array_map(fn($r) => $r['telegram_user_id'] . ':' . $r['chat_id'] . ':' . implode('+', $r['kinds']), broth_log_copilot_resolve_recipients($b, $l, '2026-10-07 00:00:00'));
        }
        return json_encode([$out, broth_log_copilot_routing_matrix()]);
    };
    $hoang = broth_log_copilot_authorized_user(HOANG);
    $liem = broth_log_copilot_authorized_user(LIEM);
    $david = broth_log_copilot_authorized_user(DAVID);
    expect_eq(broth_log_copilot_observer_branches($hoang), ['B1', 'B2', 'B3'], 'owner + test_observer: observer branches are B1/B2/B3');
    expect_eq(broth_log_copilot_private_registration_response($hoang, 'en', true)['message'], "Private alerts: ON\nStores: B1, B2, B3", 'owner + test_observer (Hoang): /alerts shows private alerts ON for B1, B2, B3');
    expect_eq(broth_log_copilot_private_registration_response($liem, 'en', true)['message'], "Private alerts: ON\nStores: B1, B2, B3", 'owner + test_observer (Liem): /alerts shows the same');
    expect_eq(broth_log_copilot_private_registration_response($hoang, 'en', false)['message'], broth_log_copilot_tr('private_start_enabled_stores', 'en'), 'owner + test_observer: /start says private alerts are enabled for approved stores');
    expect_true(!str_contains(broth_log_copilot_private_registration_response($hoang, 'en', false)['message'], 'requires approval'), 'an observer is never told manager access still needs approval');
    // manager unchanged
    expect_eq(broth_log_copilot_private_registration_response($david, 'en', true)['message'], broth_log_copilot_tr('private_alerts_status_on', 'en', ['B1']), 'manager behavior unchanged: /alerts shows ON plus the existing single store line');
    expect_eq(broth_log_copilot_private_registration_response($david, 'en', false)['message'], broth_log_copilot_tr('private_start_enabled', 'en'), 'manager behavior unchanged: /start shows the existing enabled message');
    // an owner with NO observer assignment is not shown as a recipient
    run("INSERT INTO broth_log_authorized_users (telegram_user_id,display_name,role,allowed_branches,active,created_at) VALUES ('710008','Plain Owner','owner',?,1,'2025-01-01 00:00:00')", [json_encode(['B1', 'B2', 'B3'])]);
    $plainOwner = broth_log_copilot_authorized_user('710008');
    expect_eq(broth_log_copilot_observer_branches($plainOwner), [], 'owner without observer rows: no observer branches');
    expect_eq(broth_log_copilot_private_registration_response($plainOwner, 'en', true)['message'], broth_log_copilot_tr('private_alerts_status_pending', 'en'), 'owner without observer assignment is NOT shown private alerts ON');
    expect_eq(broth_log_copilot_private_registration_response($plainOwner, 'en', false)['message'], broth_log_copilot_tr('private_start_connected', 'en'), 'owner without observer assignment gets the generic /start message');
    // unauthorized + inactive / revoked observers
    expect_eq(broth_log_copilot_private_registration_response(null, 'en', true)['message'], broth_log_copilot_tr('private_alerts_status_pending', 'en'), 'unauthorized sender still sees the pending status');
    run("UPDATE broth_log_alert_recipients SET active=0 WHERE telegram_user_id=? AND branch='B3'", [HOANG]);
    expect_eq(broth_log_copilot_observer_branches(broth_log_copilot_authorized_user(HOANG)), ['B1', 'B2'], 'a deactivated observer assignment drops that store from the status line');
    run("UPDATE broth_log_alert_recipients SET active=1 WHERE telegram_user_id=? AND branch='B3'", [HOANG]);
    run("UPDATE broth_log_authorized_users SET allowed_branches=? WHERE telegram_user_id=?", [json_encode(['B1']), HOANG]);
    expect_eq(broth_log_copilot_observer_branches(broth_log_copilot_authorized_user(HOANG)), ['B1'], 'an observer row for a store the person is no longer authorized for is not shown');
    run("UPDATE broth_log_authorized_users SET allowed_branches=? WHERE telegram_user_id=?", [json_encode(['B1', 'B2', 'B3']), HOANG]);
    // end to end through the inbox (private chat /alerts from Hoang)
    broth_log_copilot_enqueue_webhook(['update_id' => 88001, 'message' => ['text' => '/alerts', 'from' => ['id' => (int)HOANG], 'chat' => ['id' => 'dm-' . HOANG, 'type' => 'private'], 'message_id' => 1]]);
    $sent = [];
    broth_log_copilot_process_inbox(10);
    expect_true(count($sent) === 1 && $sent[0]['chat'] === 'dm-' . HOANG && str_contains($sent[0]['text'], 'Private alerts: ON') && str_contains($sent[0]['text'], 'B1, B2, B3'), 'inbox end to end: Hoang\'s /alerts reply says ON for B1, B2, B3');

    // The role label is irrelevant to routing: manager vs owner gives a byte-identical matrix and destinations.
    $matrixAsOwner = $matrixSnapshot();
    run("UPDATE broth_log_authorized_users SET role='manager' WHERE telegram_user_id=?", [HOANG]);
    $matrixAsManager = $matrixSnapshot();
    run("UPDATE broth_log_authorized_users SET role='owner' WHERE telegram_user_id=?", [HOANG]);
    expect_eq($matrixAsOwner, $matrixAsManager, 'changing Hoang from manager to owner does not change any resolved recipient set or the matrix');
    expect_eq($matrixSnapshot(), $matrixAsOwner, 'matrix is stable after the role is set back to owner');
    $sent = [];
    $roleIncident = fresh_temp_incident('B1', 'rolecheck');
    broth_log_copilot_notify_incident($roleIncident);
    expect_eq(receivers($roleIncident), ['David', 'Hoang Le', 'Liem Do'], 'with Hoang as owner a B1 alert still reaches David, Hoang and Liem exactly once, Ops excluded');
    expect_eq(count($sent), 3, 'one message per person after the role change');
    $hoangAck = broth_log_copilot_ack($roleIncident, ['telegram_user_id' => HOANG, 'allowed_branch_list' => ['B1', 'B2', 'B3'], 'display_name' => 'Hoang Le']);
    expect_true(!empty($hoangAck['ok']), 'Hoang (owner) keeps ACK authority on his stores');

    // ---------------------------------------------------------------- Existing bookkeeping untouched by a recipient change
    expect_eq(q1("SELECT state FROM broth_log_incidents WHERE incident_id=?", [$legacyId])['state'], 'resolved', 'historical incident rows are untouched by routing configuration');

    echo "\nAll PHP level-routing gate tests passed.\n";
} finally {
    @unlink(TEST_DB_PATH);
    @unlink(TEST_DB_PATH . '-wal');
    @unlink(TEST_DB_PATH . '-shm');
}
