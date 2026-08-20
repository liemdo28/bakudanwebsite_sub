<?php
declare(strict_types=1);

require_once __DIR__ . '/../../api/broth-log-copilot.php';

$dbPath = sys_get_temp_dir() . '/broth-log-copilot-gate-' . bin2hex(random_bytes(4)) . '.sqlite';
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

function expect_true(bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException("FAIL $label");
    echo "PASS $label\n";
}

function expect_eq($actual, $expected, string $label): void {
    if ($actual !== $expected) {
        throw new RuntimeException("FAIL $label expected=" . var_export($expected, true) . " actual=" . var_export($actual, true));
    }
    echo "PASS $label\n";
}

function find_processed(array $processed, string $updateId): ?array {
    foreach ($processed as $row) {
        if ((string)($row['update_id'] ?? '') === $updateId) return $row;
    }
    return null;
}

try {
    putenv('TELEGRAM_COPILOT_ENABLED=false');
    broth_log_copilot_migrate(db());
    broth_log_copilot_migrate(db());
    expect_true((bool)q1("SELECT name FROM sqlite_master WHERE type='table' AND name='broth_log_incidents'"), 'migration apply twice creates incident table');

    $disabledEnqueue = broth_log_copilot_enqueue_webhook([
        'update_id' => 100,
        'message' => [
            'text' => 'today B1',
            'from' => ['id' => 123],
            'chat' => ['id' => 456],
        ],
    ]);
    expect_eq($disabledEnqueue['reason'] ?? '', 'disabled', 'feature flag blocks inbound enqueue');
    expect_eq(count(q("SELECT * FROM broth_log_bot_inbox")), 0, 'feature flag prevents inbox writes');
    expect_eq(count(broth_log_copilot_due_escalations(new DateTimeImmutable('2026-08-20 00:10:00 UTC'))), 0, 'feature flag blocks escalation planning');
    expect_eq(broth_log_copilot_ack('missing', ['telegram_user_id' => '123', 'allowed_branch_list' => ['B1']])['reason'], 'disabled', 'feature flag blocks ACK mutation');
    expect_eq(broth_log_copilot_resolve('missing', ['telegram_user_id' => '123', 'allowed_branch_list' => ['B1']], 38, 'fixed')['reason'], 'disabled', 'feature flag blocks resolve mutation');
    expect_eq(broth_log_copilot_create_incident(['branch' => 'B1']), '', 'feature flag blocks incident creation');

    putenv('TELEGRAM_COPILOT_ENABLED' . '=true');
    putenv('TELEGRAM_CALLBACK_SECRET=unit-callback-secret');
    putenv('TELEGRAM_BOT_TOKEN=unit-test-token');
    putenv('BROTH_LOG_COPILOT_ENV=staging');
    broth_log_copilot_migrate(db());
    db()->exec('DROP INDEX IF EXISTS idx_broth_log_incidents_open');
    broth_log_copilot_migrate(db());
    expect_true((bool)q1("SELECT name FROM sqlite_master WHERE type='index' AND name='idx_broth_log_incidents_open'"), 'migration recovery recreates dropped index');

    run("INSERT INTO broth_log_authorized_users (telegram_user_id,display_name,role,allowed_branches,active) VALUES (?,?,?,?,1)", ['101', 'B1 Manager', 'manager', json_encode(['B1'])]);
    run("INSERT INTO broth_log_authorized_users (telegram_user_id,display_name,role,allowed_branches,active) VALUES (?,?,?,?,0)", ['202', 'Inactive', 'manager', json_encode(['B1'])]);
    $user = broth_log_copilot_authorized_user('101');
    expect_true($user !== null, 'active numeric Telegram ID is authorized');
    expect_true(broth_log_copilot_authorized_user('202') === null, 'inactive Telegram user is denied');
    expect_true(broth_log_copilot_authorized_user('username-only') === null, 'username-only lookup is denied');
    expect_true(broth_log_copilot_query_response(['branch' => 'B2', 'business_date' => '2026-08-20'], $user)['forbidden'] ?? false, 'cross-branch query is rejected before data fetch');
    run("INSERT INTO broth_log_routing_rules (branch,stage,level,telegram_user_ids,active) VALUES (?,?,?,?,1)", ['B1', 'staging', 1, json_encode(['101'])]);
    run("INSERT INTO broth_log_routing_rules (branch,stage,level,telegram_user_ids,active) VALUES (?,?,?,?,1)", ['B1', 'staging', 2, json_encode(['101'])]);
    putenv('TELEGRAM_CHAT_ID=999');

    $sentMessages = [];
    $GLOBALS['BROTH_LOG_COPILOT_TELEGRAM_TRANSPORT'] = function (string $method, array $payload, string $token) use (&$sentMessages): array {
        $sentMessages[] = ['method' => $method, 'payload' => $payload, 'token' => $token];
        return ['sent' => true, 'mock' => true];
    };
    $outbound = broth_log_copilot_send_telegram_message('999', 'hello from staging');
    expect_true($outbound['sent'], 'outbound helper sends through mock transport');
    expect_eq($sentMessages[0]['method'], 'sendMessage', 'outbound helper uses sendMessage method');
    expect_eq((string)$sentMessages[0]['payload']['chat_id'], '999', 'outbound helper sends to explicit chat');
    expect_true(str_starts_with($sentMessages[0]['payload']['text'], 'TEST'), 'staging outbound helper applies TEST prefix');
    expect_true(!str_contains(json_encode($outbound), 'unit-test-token'), 'outbound result does not leak token');
    putenv('BROTH_LOG_COPILOT_ENV=production');
    broth_log_copilot_send_telegram_message('999', 'hello from production');
    expect_true(!str_starts_with($sentMessages[1]['payload']['text'], 'TEST'), 'non-staging outbound omits TEST prefix');
    putenv('BROTH_LOG_COPILOT_ENV=staging');
    $GLOBALS['BROTH_LOG_COPILOT_TELEGRAM_TRANSPORT'] = function (): array {
        return ['sent' => false, 'reason' => 'telegram_api_error token=123456:ABCdefGhijkLMNOPqrstUVwxYZ'];
    };
    $failedUpdate = [
        'update_id' => 1009,
        'message' => [
            'text' => '/help B1',
            'from' => ['id' => 101],
            'chat' => ['id' => 999],
            'message_id' => 76,
        ],
    ];
    expect_true(broth_log_copilot_enqueue_webhook($failedUpdate)['queued'], 'worker reply failure test enqueues inbox row');
    $failedProcessed = broth_log_copilot_process_inbox(1, new DateTimeImmutable('2026-08-20 00:00:00 UTC'));
    $failedInbox = q1("SELECT status,outbound_status,outbound_error FROM broth_log_bot_inbox WHERE update_id='1009'");
    expect_eq($failedProcessed[0]['status'] ?? '', 'send_failed', 'failed outbound does not appear processed');
    expect_eq($failedInbox['outbound_status'] ?? '', 'failed', 'failed outbound status is recorded');
    expect_true(str_contains((string)$failedInbox['outbound_error'], '[redacted-token]'), 'failed outbound error is redacted');
    $GLOBALS['BROTH_LOG_COPILOT_TELEGRAM_TRANSPORT'] = function (string $method, array $payload, string $token) use (&$sentMessages): array {
        $sentMessages[] = ['method' => $method, 'payload' => $payload, 'token' => $token];
        return ['sent' => true, 'mock' => true];
    };

    $fakeToken = '1234567890:' . 'ABCdefGhijkLMNOPqrstUVwxYZ';
    $update = [
        'update_id' => 1010,
        'message' => [
            'text' => '/help token ' . $fakeToken . ' B1 today 38F corrected by closing lid',
            'from' => ['id' => 101],
            'chat' => ['id' => 999, 'title' => 'Ops token ' . $fakeToken],
            'message_id' => 77,
            'caption' => 'caption ' . $fakeToken,
            'photo' => [['file_id' => 'raw-photo-file-id']],
        ],
    ];
    expect_true(broth_log_copilot_enqueue_webhook($update)['queued'], 'webhook enqueue accepts first update');
    expect_true(!broth_log_copilot_enqueue_webhook($update)['queued'], 'webhook retry duplicate update_id is suppressed');
    $inbox = q1("SELECT payload_json,message_text FROM broth_log_bot_inbox WHERE update_id='1010'");
    expect_true(!str_contains((string)$inbox['payload_json'], $fakeToken), 'payload JSON redacts token-shaped text');
    expect_true(!str_contains((string)$inbox['payload_json'], 'raw-photo-file-id'), 'payload JSON excludes raw Telegram update data');
    expect_true(str_contains((string)$inbox['message_text'], '[redacted-token]'), 'message text redacts token-shaped text');
    expect_true(str_contains((string)$inbox['message_text'], 'B1 today 38F corrected by closing lid'), 'ordinary Broth Log content is preserved');
    $sentBeforeWorker = count($sentMessages);
    expect_eq(count(broth_log_copilot_process_inbox(10, new DateTimeImmutable('2026-08-20 00:00:00 UTC'))), 1, 'worker processes authorized inbox');
    $processedInbox = q1("SELECT status,outbound_status FROM broth_log_bot_inbox WHERE update_id='1010'");
    expect_eq($processedInbox['outbound_status'] ?? '', 'sent', 'worker records successful outbound reply');
    expect_eq(count($sentMessages), $sentBeforeWorker + 1, 'worker sends exactly one outbound reply for inbox row');

    $captionUpdate = [
        'update_id' => 1011,
        'message' => [
            'caption' => 'B1 photo note token=' . $fakeToken,
            'from' => ['id' => 101],
            'chat' => ['id' => 999],
            'message_id' => 78,
            'photo' => [['file_id' => 'raw-photo-token-' . $fakeToken]],
        ],
    ];
    expect_true(broth_log_copilot_enqueue_webhook($captionUpdate)['queued'], 'caption enqueue accepts supported message update');
    $captionInbox = q1("SELECT payload_json,message_text FROM broth_log_bot_inbox WHERE update_id='1011'");
    expect_true(!str_contains((string)$captionInbox['payload_json'], $fakeToken), 'caption payload redacts token-shaped text');
    expect_true(str_contains((string)$captionInbox['message_text'], '[redacted-token]'), 'caption text redacts token-shaped text');
    expect_true(str_contains((string)$captionInbox['message_text'], 'B1 photo note'), 'caption keeps ordinary operational text');

    $callbackUpdate = [
        'update_id' => 1012,
        'callback_query' => [
            'id' => 'callback-id-' . $fakeToken,
            'data' => 'ack:bl-test:' . $fakeToken,
            'from' => ['id' => 101, 'username' => 'manager_' . $fakeToken],
            'message' => [
                'message_id' => 79,
                'chat' => ['id' => 999, 'title' => 'TEST ' . $fakeToken],
            ],
        ],
    ];
    expect_true(broth_log_copilot_enqueue_webhook($callbackUpdate)['queued'], 'callback enqueue accepts first callback update');
    $callbackInbox = q1("SELECT payload_json,message_text FROM broth_log_bot_inbox WHERE update_id='1012'");
    expect_true(!str_contains((string)$callbackInbox['payload_json'], $fakeToken), 'callback payload redacts token-shaped data');
    expect_true(str_contains((string)$callbackInbox['message_text'], '[redacted-token]'), 'callback data redacts token-shaped text');

    $callback = broth_log_copilot_sign_callback('ack', 'bl-test', time() + 60);
    expect_eq(broth_log_copilot_consume_callback($callback)['incident_id'] ?? '', 'bl-test', 'callback validates and consumes once');
    expect_true(broth_log_copilot_consume_callback($callback) === null, 'callback replay is rejected');
    $expired = broth_log_copilot_sign_callback('ack', 'bl-expired', time() - 1);
    expect_true(broth_log_copilot_consume_callback($expired) === null, 'expired callback is rejected');

    $alert = [
        'branch' => 'B1',
        'responseId' => 'resp-1',
        'stationKey' => 'prepAreaCooler',
        'station' => 'Prep Area Cooler',
        'severity' => 'critical',
        'businessDate' => '2026-08-20',
        'businessTime' => '08:00',
        'temperature' => '45F',
        'target' => '<= 40F',
        'correctiveAction' => 'Move product and re-temp',
    ];
    $incidentId = broth_log_copilot_create_incident($alert);
    expect_eq(broth_log_copilot_create_incident($alert), $incidentId, 'duplicate open incident returns same id');
    $sentBeforeIncident = count($sentMessages);
    expect_true(broth_log_copilot_notify_incident($incidentId)['sent'], 'new routed incident sends Telegram notification');
    expect_eq(count($sentMessages), $sentBeforeIncident + 1, 'incident notification sends once');
    $notification = $sentMessages[$sentBeforeIncident]['payload'];
    expect_true(str_starts_with($notification['text'], 'TEST'), 'incident notification uses TEST prefix in staging');
    expect_true(str_contains($notification['text'], 'Store: B1'), 'incident notification includes store');
    expect_true(str_contains($notification['text'], 'Prep Area Cooler'), 'incident notification includes station');
    expect_true(isset($notification['reply_markup']['inline_keyboard'][0][0]['callback_data']), 'incident notification includes inline ACK button');
    $ackData = $notification['reply_markup']['inline_keyboard'][0][0]['callback_data'];
    $resolveData = $notification['reply_markup']['inline_keyboard'][0][1]['callback_data'];
    expect_true(strlen($ackData) <= 64, 'ACK callback payload fits Telegram limit');
    expect_true(strlen($resolveData) <= 64, 'resolve callback payload fits Telegram limit');
    broth_log_copilot_notify_incident($incidentId);
    expect_eq(count($sentMessages), $sentBeforeIncident + 1, 'duplicate incident notification is suppressed');

    expect_true(broth_log_copilot_enqueue_webhook([
        'update_id' => 1020,
        'callback_query' => [
            'data' => $ackData,
            'from' => ['id' => 101],
            'message' => ['message_id' => 80, 'chat' => ['id' => 999]],
        ],
    ])['queued'], 'ACK callback enqueues');
    $ackProcessed = find_processed(broth_log_copilot_process_inbox(10, new DateTimeImmutable('2026-08-20 00:01:00 UTC')), '1020');
    expect_eq($ackProcessed['intent'] ?? '', 'ack', 'ACK callback mutates incident');
    expect_eq(q1("SELECT state FROM broth_log_incidents WHERE incident_id=?", [$incidentId])['state'] ?? '', 'acknowledged', 'ACK callback records acknowledged state');
    expect_true(str_contains($sentMessages[count($sentMessages) - 1]['payload']['text'], 'Incident acknowledged'), 'ACK callback sends confirmation');
    expect_true(broth_log_copilot_enqueue_webhook([
        'update_id' => 1021,
        'callback_query' => [
            'data' => $ackData,
            'from' => ['id' => 101],
            'message' => ['message_id' => 81, 'chat' => ['id' => 999]],
        ],
    ])['queued'], 'ACK callback replay enqueues as separate Telegram retry');
    $replayProcessed = find_processed(broth_log_copilot_process_inbox(10, new DateTimeImmutable('2026-08-20 00:02:00 UTC')), '1021');
    expect_eq($replayProcessed['intent'] ?? '', 'callback_rejected', 'ACK replay is rejected');
    expect_true(!str_contains($sentMessages[count($sentMessages) - 1]['payload']['text'], 'Incident acknowledged'), 'ACK replay does not send second ACK confirmation');

    expect_eq(broth_log_copilot_ack($incidentId, ['telegram_user_id' => '999', 'allowed_branch_list' => ['B2']])['reason'], 'forbidden', 'cross-branch ACK is rejected');
    expect_eq(broth_log_copilot_resolve($incidentId, $user, null, 'fixed')['reason'], 'missing_resolution_evidence', 'resolve requires recheck temperature');
    expect_eq(broth_log_copilot_resolve($incidentId, $user, 45, 'fixed')['reason'], 'recheck_still_unsafe', 'resolve rejects unsafe recheck');
    expect_true(broth_log_copilot_enqueue_webhook([
        'update_id' => 1022,
        'message' => [
            'text' => '/resolve #' . $incidentId . ' 45F closed door',
            'from' => ['id' => 101],
            'chat' => ['id' => 999],
            'message_id' => 82,
        ],
    ])['queued'], 'invalid resolve message enqueues');
    $invalidResolve = find_processed(broth_log_copilot_process_inbox(10, new DateTimeImmutable('2026-08-20 00:03:00 UTC')), '1022');
    expect_eq($invalidResolve['intent'] ?? '', 'resolve_rejected', 'invalid resolve sends rejection');
    expect_true(broth_log_copilot_enqueue_webhook([
        'update_id' => 1023,
        'message' => [
            'text' => '/resolve #' . $incidentId . ' 38F closed door and moved product',
            'from' => ['id' => 101],
            'chat' => ['id' => 999],
            'message_id' => 83,
        ],
    ])['queued'], 'valid resolve message enqueues');
    $validResolve = find_processed(broth_log_copilot_process_inbox(10, new DateTimeImmutable('2026-08-20 00:04:00 UTC')), '1023');
    expect_eq($validResolve['intent'] ?? '', 'resolve', 'valid resolve message succeeds');
    expect_eq(q1("SELECT state FROM broth_log_incidents WHERE incident_id=?", [$incidentId])['state'] ?? '', 'resolved', 'resolve message records resolved state');
    expect_true(str_contains($sentMessages[count($sentMessages) - 1]['payload']['text'], 'Incident resolved'), 'resolve message sends confirmation');
    expect_true(broth_log_copilot_enqueue_webhook([
        'update_id' => 1024,
        'callback_query' => [
            'data' => $resolveData,
            'from' => ['id' => 101],
            'message' => ['message_id' => 84, 'chat' => ['id' => 999]],
        ],
    ])['queued'], 'stale resolve callback enqueues');
    $staleResolve = find_processed(broth_log_copilot_process_inbox(10, new DateTimeImmutable('2026-08-20 00:05:00 UTC')), '1024');
    expect_eq($staleResolve['intent'] ?? '', 'callback_stale', 'stale resolve callback is rejected after resolution');
    expect_true(broth_log_copilot_create_incident($alert) !== $incidentId, 'critical-safe-critical creates a new incident after resolve');

    $raceId = broth_log_copilot_create_incident(array_replace($alert, ['responseId' => 'resp-race']));
    run("UPDATE broth_log_incidents SET created_at='2026-08-20 00:00:00', last_reminder_at=NULL, reminder_count=0, state='detected', current_level=1 WHERE incident_id=?", [$raceId]);
    $now = new DateTimeImmutable('2026-08-20 00:03:00 UTC');
    $due = broth_log_copilot_due_escalations($now);
    expect_true(count($due) >= 1, 'fake-clock finds due reminder');
    $target = array_values(array_filter($due, fn($d) => $d['incident']['incident_id'] === $raceId))[0];
    $escalationSentBefore = count($sentMessages);
    $reminderResult = broth_log_copilot_apply_escalation_action_with_notification($target, $now);
    expect_eq($reminderResult['action'], 'reminded', 'first worker applies reminder');
    expect_eq($reminderResult['outbound_sent'] ?? -1, 1, 'reminder escalation sends outbound Telegram message');
    expect_eq(count($sentMessages), $escalationSentBefore + 1, 'reminder uses outbound helper once');
    expect_eq(broth_log_copilot_apply_escalation_action($target, $now)['reason'], 'stale_action', 'second worker stale snapshot is rejected');

    run("UPDATE broth_log_incidents SET created_at='2026-08-20 00:00:00', last_reminder_at='2026-08-20 00:06:00', reminder_count=3, state='notified_level_1', current_level=1 WHERE incident_id=?", [$raceId]);
    $dueEscalate = array_values(array_filter(broth_log_copilot_due_escalations(new DateTimeImmutable('2026-08-20 00:09:00 UTC')), fn($d) => $d['incident']['incident_id'] === $raceId))[0];
    expect_eq($dueEscalate['action'], 'escalate', 'fake-clock escalates at minute nine');

    echo "\nAll PHP Phase 1 gate tests passed.\n";
} finally {
    @unlink(TEST_DB_PATH);
    @unlink(TEST_DB_PATH . '-wal');
    @unlink(TEST_DB_PATH . '-shm');
}
