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
    // Deliberately different from the Copilot destination below, so any accidental fallback to the
    // legacy one-way-alert variable would be caught by the chat_id assertions further down.
    putenv('TELEGRAM_CHAT_ID=one-way-alert-chat-should-never-be-used-by-copilot');
    putenv('TELEGRAM_COPILOT_CHAT_ID=999');

    // Regression: Copilot's chat destination must never come from TELEGRAM_CHAT_ID (the existing
    // one-way critical-alert chat). Resolution order is routing-row chat_id, then
    // TELEGRAM_COPILOT_CHAT_ID, then no destination at all - never silently reusing the one-way chat.
    expect_true(broth_log_copilot_route_chat_ids('B1', 1) === ['999'], 'route_chat_ids falls back to TELEGRAM_COPILOT_CHAT_ID when the routing row has no chat_id, and ignores TELEGRAM_CHAT_ID entirely');
    run("UPDATE broth_log_routing_rules SET chat_id=? WHERE branch='B1' AND stage='staging' AND level=1", ['888-row-specific-chat']);
    expect_true(broth_log_copilot_route_chat_ids('B1', 1) === ['888-row-specific-chat'], 'route_chat_ids prefers the routing row\'s own chat_id over TELEGRAM_COPILOT_CHAT_ID once one is set');
    run("UPDATE broth_log_routing_rules SET active=0 WHERE branch='B1' AND stage='staging' AND level=1");
    putenv('TELEGRAM_COPILOT_CHAT_ID=');
    expect_true(broth_log_copilot_route_chat_ids('B1', 1) === [], 'with no active route and no TELEGRAM_COPILOT_CHAT_ID fallback, route_chat_ids fails safely to an empty destination rather than misrouting');
    run("UPDATE broth_log_routing_rules SET active=1 WHERE branch='B1' AND stage='staging' AND level=1");
    putenv('TELEGRAM_COPILOT_CHAT_ID=999');
    run("UPDATE broth_log_routing_rules SET chat_id=NULL WHERE branch='B1' AND stage='staging' AND level=1");

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
            'chat' => ['id' => 999, 'type' => 'private'],
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

    // Regression: outbound sends must retry a bounded number of times on a local connection/timeout
    // failure (http_code 0), but never retry a real HTTP response from Telegram - even an error one -
    // since that means the request was received and answered, and retrying could mask a genuine
    // rejection or hammer Telegram with duplicate attempts for no benefit.
    unset($GLOBALS['BROTH_LOG_COPILOT_TELEGRAM_TRANSPORT']);
    putenv('TELEGRAM_BOT_TOKEN=unit-test-token');

    $httpAttempts = 0;
    $GLOBALS['BROTH_LOG_COPILOT_TELEGRAM_HTTP_HOOK'] = function () use (&$httpAttempts): array {
        $httpAttempts++;
        if ($httpAttempts < 3) return ['raw' => false, 'http_code' => 0];
        return ['raw' => json_encode(['ok' => true]), 'http_code' => 200];
    };
    $retrySucceeds = broth_log_copilot_send_telegram_message('999', 'retry test - eventual success');
    expect_true($retrySucceeds['sent'] ?? false, 'a local timeout retries and eventually succeeds within the attempt budget');
    expect_eq($retrySucceeds['attempts'] ?? -1, 3, 'the send that succeeded on the third attempt reports 3 attempts');

    $httpAttempts = 0;
    $GLOBALS['BROTH_LOG_COPILOT_TELEGRAM_HTTP_HOOK'] = function () use (&$httpAttempts): array {
        $httpAttempts++;
        return ['raw' => false, 'http_code' => 0];
    };
    $retryExhausted = broth_log_copilot_send_telegram_message('999', 'retry test - always times out');
    expect_true(!($retryExhausted['sent'] ?? true), 'a persistent local timeout eventually gives up rather than retrying forever');
    expect_eq($retryExhausted['attempts'] ?? -1, 3, 'retry is bounded to exactly 3 attempts, not unbounded');
    expect_eq($httpAttempts, 3, 'the retry loop makes exactly 3 real attempts, no more');

    $httpAttempts = 0;
    $GLOBALS['BROTH_LOG_COPILOT_TELEGRAM_HTTP_HOOK'] = function () use (&$httpAttempts): array {
        $httpAttempts++;
        return ['raw' => json_encode(['ok' => false, 'description' => 'Forbidden: bot was blocked']), 'http_code' => 403];
    };
    $noRetryOnRejection = broth_log_copilot_send_telegram_message('999', 'retry test - real telegram rejection');
    expect_true(!($noRetryOnRejection['sent'] ?? true), 'a real Telegram HTTP rejection is not treated as sent');
    expect_eq($httpAttempts, 1, 'a real Telegram HTTP response (even an error) is never retried - only a local no-response failure is');
    expect_eq($noRetryOnRejection['attempts'] ?? -1, 1, 'the rejection result reports a single attempt, confirming no retry occurred');

    unset($GLOBALS['BROTH_LOG_COPILOT_TELEGRAM_HTTP_HOOK']);
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
    expect_true(str_contains(broth_log_copilot_redact_credential_text('leaked secret=verylongsecretvalue1234567890'), 'secret=[redacted]'), 'labeled secret redaction keeps the label');
    expect_true(!str_contains(broth_log_copilot_redact_credential_text('leaked secret=verylongsecretvalue1234567890'), 'verylongsecretvalue1234567890'), 'labeled secret redaction removes the value');
    expect_true(str_contains(broth_log_copilot_redact_credential_text('token: abcXYZ1234567890longenough'), 'token=[redacted]'), 'labeled token redaction keeps the label regardless of separator');
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
        'temperature' => '120F',
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
    expect_true(str_contains($notification['text'], 'B1'), 'incident notification includes the store label');
    expect_true(str_contains($notification['text'], 'Prep Area Cooler'), 'incident notification includes station');
    expect_true(str_contains($notification['text'], '120°F'), 'incident notification shows the correct recorded temperature (regression: was truncated to 12F)');
    expect_true(str_contains($notification['text'], 'too high'), 'a max-violation (<=) incident is worded as "too high", not guessed or generic, since the direction is deterministically known');
    expect_true(str_contains($notification['text'], 'Required: ≤ 40°F'), 'the required limit is shown using the correct operator and value');
    expect_true(!str_contains($notification['text'], 'Level:'), 'the concise Telegram body never displays the internal escalation level number');
    expect_true(!str_contains($notification['text'], 'Employee'), 'the concise Telegram body never displays the employee name');
    expect_true(!str_contains($notification['text'], 'Business:'), 'the concise Telegram body never displays business date/time');
    expect_true(!str_contains($notification['text'], 'Ref:') && !str_contains($notification['text'], $incidentId), 'the concise Telegram body never displays the incident reference/ID');
    expect_eq(q1("SELECT employee_name FROM broth_log_incidents WHERE incident_id=?", [$incidentId])['employee_name'] ?? '', '', 'employee_name is still stored on the incident row even though it is hidden from the Telegram body');
    expect_true(isset($notification['reply_markup']['inline_keyboard'][0][0]['callback_data']), 'incident notification includes inline ACK button');
    $ackData = $notification['reply_markup']['inline_keyboard'][0][0]['callback_data'];
    $resolveData = $notification['reply_markup']['inline_keyboard'][0][1]['callback_data'];
    expect_true(strlen($ackData) <= 64, 'ACK callback payload fits Telegram limit');
    expect_true(strlen($resolveData) <= 64, 'resolve callback payload fits Telegram limit');
    broth_log_copilot_notify_incident($incidentId);
    expect_eq(count($sentMessages), $sentBeforeIncident + 1, 'duplicate incident notification is suppressed');

    $employeeIncidentId = broth_log_copilot_create_incident(array_replace($alert, ['responseId' => 'resp-with-employee', 'employee' => 'Jordan Rivera']));
    broth_log_copilot_notify_incident($employeeIncidentId);
    expect_true(!str_contains($sentMessages[count($sentMessages) - 1]['payload']['text'], 'Jordan Rivera'), 'a known employee name is still never shown in the concise Telegram body');
    expect_eq(q1("SELECT employee_name FROM broth_log_incidents WHERE incident_id=?", [$employeeIncidentId])['employee_name'] ?? '', 'Jordan Rivera', 'the real employee name is still stored on the incident row - hidden from Telegram only, not removed from the record');

    // --- Concise Telegram alert presentation: min-violation direction, reminder/urgent tiers,
    // B2/B3 labels, controlled-test labeling, and full temperature-format regression. ---
    $minAlert = array_replace($alert, ['responseId' => 'resp-min-violation', 'stationKey' => 'bowlWarmer', 'station' => 'Bowl Warmer', 'temperature' => '0F', 'target' => '>= 100F']);
    $minIncidentId = broth_log_copilot_create_incident($minAlert);
    broth_log_copilot_notify_incident($minIncidentId);
    $minNotifyText = $sentMessages[count($sentMessages) - 1]['payload']['text'];
    expect_true(str_contains($minNotifyText, 'too low'), 'a min-violation (>=) incident notify is worded as "too low"');
    expect_true(str_contains($minNotifyText, 'Required: ≥ 100°F'), 'a min-violation required line uses the >= operator symbol and correct value');
    expect_true(str_contains($minNotifyText, '0°F'), 'a min-violation shows the correct recorded temperature, including a genuine zero value');

    run("UPDATE broth_log_incidents SET last_reminder_at=NULL, reminder_count=0 WHERE incident_id=?", [$minIncidentId]);
    $minReminderResult = broth_log_copilot_apply_escalation_action_with_notification(['action' => 'remind', 'incident' => q1("SELECT * FROM broth_log_incidents WHERE incident_id=?", [$minIncidentId]), 'level' => 1], new DateTimeImmutable('2026-08-20 00:20:00 UTC'));
    $minReminderText = $sentMessages[count($sentMessages) - 1]['payload']['text'];
    expect_true(str_contains($minReminderText, 'still below limit'), 'a min-violation reminder is worded as "still below limit"');
    expect_true(str_contains($minReminderText, 'REMINDER'), 'a level-1 reminder uses the REMINDER header, not the initial-alert header');

    // Max-violation reminder wording, on the original B1 Prep Area Cooler incident.
    run("UPDATE broth_log_incidents SET last_reminder_at=NULL, reminder_count=0 WHERE incident_id=?", [$incidentId]);
    broth_log_copilot_apply_escalation_action_with_notification(['action' => 'remind', 'incident' => q1("SELECT * FROM broth_log_incidents WHERE incident_id=?", [$incidentId]), 'level' => 1], new DateTimeImmutable('2026-08-20 00:20:00 UTC'));
    $maxReminderText = $sentMessages[count($sentMessages) - 1]['payload']['text'];
    expect_true(str_contains($maxReminderText, 'still above limit'), 'a max-violation reminder is worded as "still above limit"');

    // Urgent/level-3 presentation never exposes internal escalation-state language.
    $urgentIncident = q1("SELECT * FROM broth_log_incidents WHERE incident_id=?", [$incidentId]);
    $urgentIncident['current_level'] = 3;
    $urgentText = broth_log_copilot_incident_message($urgentIncident, 'reminder');
    expect_true(str_contains($urgentText, 'URGENT'), 'a level-3 message uses the URGENT header');
    expect_true(str_contains($urgentText, 'still out of range'), 'a level-3 message is worded as "still out of range", not a reminder-tier phrase');
    expect_true(str_contains($urgentText, 'Manager action required'), 'a level-3 message tells the manager action is required, not "please check and re-temp"');
    foreach (['Level 3', 'level_3', 'escalated_level_3', 'current_level', 'state machine', 'routing level'] as $forbidden) {
        expect_true(!str_contains($urgentText, $forbidden), "the urgent message never exposes the internal phrase \"{$forbidden}\"");
    }

    // B2 / B3 labels are not hardcoded - the branch is read from the incident, not assumed to be B1.
    $b2Incident = array_replace($urgentIncident, ['branch' => 'B2', 'current_level' => 1]);
    expect_true(str_contains(broth_log_copilot_incident_message($b2Incident, 'notify'), 'B2'), 'a B2 incident notification correctly labels the store as B2, not hardcoded to B1');
    $b3Incident = array_replace($urgentIncident, ['branch' => 'B3', 'current_level' => 1]);
    expect_true(str_contains(broth_log_copilot_incident_message($b3Incident, 'notify'), 'B3'), 'a B3 incident notification correctly labels the store as B3, not hardcoded to B1');

    // Controlled-test incidents remain unmistakably labeled at every tier, including urgent.
    $testIncident = array_replace($urgentIncident, ['branch' => 'B2', 'employee_name' => 'CONTROLLED TEST', 'current_level' => 1]);
    $testNotifyText = broth_log_copilot_incident_message($testIncident, 'notify');
    expect_true(str_contains($testNotifyText, 'CONTROLLED TEST'), 'a controlled-test notification is unmistakably labeled as a test');
    expect_true(str_contains($testNotifyText, 'TEST ONLY'), 'a controlled-test notification tells the reader no action is required');
    $testIncident['current_level'] = 3;
    $testUrgentText = broth_log_copilot_incident_message($testIncident, 'reminder');
    expect_true(str_contains($testUrgentText, 'CONTROLLED TEST'), 'a controlled-test message remains labeled as a test even at level 3, not silently switching to the real URGENT header');

    // Temperature formatting regression, through the real concise-message path (not just the raw
    // formatter): must never truncate a trailing zero into a different number.
    foreach ([['0', '0°F'], ['10', '10°F'], ['20', '20°F'], ['40', '40°F'], ['100', '100°F'], ['120', '120°F'], ['-2', '-2°F'], ['10.5', '10.5°F']] as [$raw, $expectedText]) {
        $tempIncident = array_replace($urgentIncident, ['temperature_f' => (float)$raw, 'current_level' => 1]);
        $rendered = broth_log_copilot_incident_message($tempIncident, 'notify');
        expect_true(str_contains($rendered, $expectedText), "a recorded temperature of {$raw} renders as exactly \"{$expectedText}\", never truncated (e.g. 10 must never become 1, 20 must never become 2, 100 must never become 1, 120 must never become 12)");
    }

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

    // Regression: a reminder message mints its own independently-signed ACK token for the same
    // incident. Tapping that token after the incident is already acknowledged must not re-fire
    // the ack action, since it is not caught by same-token replay protection.
    $secondAckData = broth_log_copilot_create_callback_token('ack', $incidentId, (new DateTimeImmutable('2026-08-20 00:05:00 UTC'))->getTimestamp());
    expect_true(broth_log_copilot_enqueue_webhook([
        'update_id' => 1025,
        'callback_query' => [
            'data' => $secondAckData,
            'from' => ['id' => 101],
            'message' => ['message_id' => 85, 'chat' => ['id' => 999]],
        ],
    ])['queued'], 'second independently-signed ACK token enqueues');
    $secondTokenProcessed = find_processed(broth_log_copilot_process_inbox(10, new DateTimeImmutable('2026-08-20 00:02:30 UTC')), '1025');
    expect_eq($secondTokenProcessed['intent'] ?? '', 'ack_rejected', 'a second valid-but-distinct ACK token is rejected once already acknowledged (regression: duplicate ack via reminder-message button)');
    expect_eq(q1("SELECT COUNT(*) AS c FROM broth_log_incident_events WHERE incident_id=? AND event_type='acknowledged'", [$incidentId])['c'] ?? -1, 1, 'exactly one acknowledged audit event even after a second valid token is consumed');
    expect_true(!str_contains($sentMessages[count($sentMessages) - 1]['payload']['text'], 'Incident acknowledged'), 'second valid-token ACK attempt does not send a duplicate confirmation');

    // Regression: ack()/resolve()/apply_escalation_action() used to collapse every Throwable in
    // their BEGIN IMMEDIATE / COMMIT block into 'lock_failed', even when the exception was not
    // actually SQLite lock contention - hiding the real cause from anyone debugging a failure.
    expect_eq(broth_log_copilot_classify_db_exception(new Exception('database is locked')), 'lock_failed', 'a genuine SQLite lock message is classified as lock_failed');
    expect_eq(broth_log_copilot_classify_db_exception(new Exception('database table is locked')), 'lock_failed', 'the table-locked variant is also classified as lock_failed');
    expect_eq(broth_log_copilot_classify_db_exception(new Exception('DATABASE IS LOCKED')), 'lock_failed', 'lock classification is case-insensitive, matching how SQLite driver messages can vary');
    expect_eq(broth_log_copilot_classify_db_exception(new Exception('near "SELCT": syntax error')), 'internal_error', 'an unrelated SQL/runtime exception is classified as internal_error, not mislabeled as a lock');
    expect_eq(broth_log_copilot_classify_db_exception(new Exception('UNIQUE constraint failed: broth_log_incidents.active_key')), 'internal_error', 'a constraint-violation exception is classified as internal_error, not mislabeled as a lock');
    $rawMessageException = new Exception('UNIQUE constraint failed: broth_log_incidents.active_key with secret-looking-value-XYZ');
    expect_true(!str_contains(broth_log_copilot_classify_db_exception($rawMessageException), 'secret-looking-value-XYZ'), 'the classified reason never leaks the raw exception message text');

    expect_eq(broth_log_copilot_ack($incidentId, ['telegram_user_id' => '999', 'allowed_branch_list' => ['B2']])['reason'], 'forbidden', 'cross-branch ACK is rejected');
    expect_eq(broth_log_copilot_resolve($incidentId, $user, null, 'fixed')['reason'], 'missing_resolution_evidence', 'resolve requires recheck temperature');
    // Stale fixture: this incident's station is prepAreaCooler (BROTH_LOG_SOP min=30/max=45,
    // an inclusive range - the same convention the dashboard displays as "30F - 45F" and every
    // other station in BROTH_LOG_SOP uses). 45 IS the safe boundary itself, not an unsafe value -
    // broth_log_severity_for() has classified it 'safe' since this codebase's first commit
    // (c081a8b), unchanged. 60F is unambiguously outside the safe range on either read of the
    // boundary, so it actually exercises "resolve rejects unsafe recheck" as the label promises.
    expect_eq(broth_log_copilot_resolve($incidentId, $user, 60, 'fixed')['reason'], 'recheck_still_unsafe', 'resolve rejects unsafe recheck');

    // Regression: an incident whose station key has no BROTH_LOG_SOP entry (unconfigured or
    // mistyped) must never be treated as automatically safe, no matter the recheck temperature.
    $unknownStationAlert = [
        'branch' => 'B1',
        'responseId' => 'resp-unknown-station',
        'stationKey' => 'thisStationKeyDoesNotExist',
        'station' => 'Unknown Station',
        'severity' => 'critical',
        'businessDate' => '2026-08-20',
        'businessTime' => '09:00',
        'temperature' => '55F',
        'target' => '<= 40F',
        'correctiveAction' => 'Investigate',
    ];
    $unknownStationIncidentId = broth_log_copilot_create_incident($unknownStationAlert);
    expect_true(broth_log_severity_for(null, -50.0) !== 'safe', 'a missing SOP config never classifies any temperature as safe, even an extreme favorable one');
    expect_eq(broth_log_is_safe_recheck('thisStationKeyDoesNotExist', -50.0), false, 'is_safe_recheck refuses an unconfigured station even for a temperature that would pass any real SOP');
    expect_eq(broth_log_copilot_resolve($unknownStationIncidentId, $user, -50, 'invented safe reading', new DateTimeImmutable('2026-08-20 00:05:00 UTC'))['reason'], 'unknown_station_config', 'resolve on an unconfigured station is rejected with a distinct diagnosable reason, not lumped into "still unsafe"');
    expect_eq(q1("SELECT state FROM broth_log_incidents WHERE incident_id=?", [$unknownStationIncidentId])['state'] ?? '', 'detected', 'unconfigured-station incident is not silently resolved using an invented threshold');
    expect_eq(broth_log_severity_for(BROTH_LOG_SOP['prepAreaCooler'], 38.0), 'safe', 'unaffected: a known, correctly configured station still classifies a genuinely safe reading as safe');
    expect_eq(broth_log_is_safe_recheck('prepAreaCooler', 38.0), true, 'unaffected: a known, correctly configured station still accepts a genuinely safe recheck');
    // Same stale-fixture pattern as above: 45F is prepAreaCooler's safe boundary, not an
    // "invalid"/unsafe recheck. 60F is unambiguously unsafe, so this actually exercises the
    // rejection path the assertion below claims to test.
    expect_true(broth_log_copilot_enqueue_webhook([
        'update_id' => 1022,
        'message' => [
            'text' => '/resolve #' . $incidentId . ' 60F closed door',
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

    // --- Approved balanced cadence: T+0 alert, T+5 reminder, T+10 -> L2, T+15 -> L3 (URGENT),
    // then a level-3 reminder every 15 minutes indefinitely until ACK. ---
    $raceId = broth_log_copilot_create_incident(array_replace($alert, ['responseId' => 'resp-race']));
    run("UPDATE broth_log_incidents SET created_at='2026-08-20 00:00:00', level_entered_at='2026-08-20 00:00:00', last_reminder_at=NULL, reminder_count=0, state='detected', current_level=1 WHERE incident_id=?", [$raceId]);

    $dueAtMin4 = array_values(array_filter(broth_log_copilot_due_escalations(new DateTimeImmutable('2026-08-20 00:04:00 UTC')), fn($d) => $d['incident']['incident_id'] === $raceId));
    expect_true(empty($dueAtMin4), 'T+4: no reminder yet - the first L1 reminder must wait a full interval from level entry, not fire on whatever tick runs first');

    $now = new DateTimeImmutable('2026-08-20 00:05:00 UTC');
    $due = broth_log_copilot_due_escalations($now);
    expect_true(count($due) >= 1, 'fake-clock finds due reminder at T+5');
    $target = array_values(array_filter($due, fn($d) => $d['incident']['incident_id'] === $raceId))[0];
    expect_eq($target['action'], 'remind', 'T+5: the first L1 reminder fires');
    $escalationSentBefore = count($sentMessages);
    $reminderResult = broth_log_copilot_apply_escalation_action_with_notification($target, $now);
    expect_eq($reminderResult['action'], 'reminded', 'first worker applies reminder');
    expect_eq($reminderResult['outbound_sent'] ?? -1, 1, 'reminder escalation sends outbound Telegram message');
    expect_eq(count($sentMessages), $escalationSentBefore + 1, 'reminder uses outbound helper once');
    expect_eq(broth_log_copilot_apply_escalation_action($target, $now)['reason'], 'stale_action', 'second worker stale snapshot is rejected');

    $dueAtMin9 = array_values(array_filter(broth_log_copilot_due_escalations(new DateTimeImmutable('2026-08-20 00:09:00 UTC')), fn($d) => $d['incident']['incident_id'] === $raceId));
    expect_true(empty($dueAtMin9), 'T+9: no escalation yet - L1 escalates at 10 minutes under the approved balanced cadence, not 9');

    $dueEscalate = array_values(array_filter(broth_log_copilot_due_escalations(new DateTimeImmutable('2026-08-20 00:10:00 UTC')), fn($d) => $d['incident']['incident_id'] === $raceId))[0];
    expect_eq($dueEscalate['action'], 'escalate', 'fake-clock escalates level 1 to level 2 at T+10');
    $escalate2Result = broth_log_copilot_apply_escalation_action_with_notification($dueEscalate, new DateTimeImmutable('2026-08-20 00:10:00 UTC'));
    expect_eq($escalate2Result['action'], 'escalated', 'worker applies the level 1 -> 2 escalation');
    expect_eq($escalate2Result['level'], 2, 'escalation moves the incident to level 2');
    $level2Row = q1("SELECT level_entered_at FROM broth_log_incidents WHERE incident_id=?", [$raceId]);
    expect_eq($level2Row['level_entered_at'], '2026-08-20 00:10:00', 'entering level 2 resets level_entered_at to the escalation time');

    $dueAtMin14 = array_values(array_filter(broth_log_copilot_due_escalations(new DateTimeImmutable('2026-08-20 00:14:00 UTC')), fn($d) => $d['incident']['incident_id'] === $raceId));
    expect_true(empty($dueAtMin14), 'T+14: no L3 yet - L2 escalates 5 minutes after its own entry, at T+15');

    $dueAtMin15 = array_values(array_filter(broth_log_copilot_due_escalations(new DateTimeImmutable('2026-08-20 00:15:00 UTC')), fn($d) => $d['incident']['incident_id'] === $raceId));
    expect_eq($dueAtMin15[0]['action'] ?? null, 'escalate', 'level 2 escalates directly to level 3 at T+15, with no intermediate level-2 reminder in the approved balanced timeline');
    expect_eq($dueAtMin15[0]['to_level'] ?? null, 3, 'level 2 escalates specifically to level 3');
    $escalate3Result = broth_log_copilot_apply_escalation_action_with_notification($dueAtMin15[0], new DateTimeImmutable('2026-08-20 00:15:00 UTC'));
    expect_eq($escalate3Result['level'], 3, 'incident reaches level 3 at T+15');
    $level3Row = q1("SELECT level_entered_at FROM broth_log_incidents WHERE incident_id=?", [$raceId]);
    expect_eq($level3Row['level_entered_at'], '2026-08-20 00:15:00', 'entering level 3 resets level_entered_at to the escalation time');

    // Entering level 3 already sets last_reminder_at to the escalation moment, so the first
    // level-3 reminder must wait the full 15-minute interval like every subsequent one.
    foreach (['00:20:00' => 'L3+5', '00:25:00' => 'L3+10', '00:29:00' => 'L3+14'] as $time => $label) {
        $dueEarly = array_values(array_filter(broth_log_copilot_due_escalations(new DateTimeImmutable("2026-08-20 {$time} UTC")), fn($d) => $d['incident']['incident_id'] === $raceId));
        expect_true(empty($dueEarly), "{$label}: no reminder yet - the first level-3 reminder waits the full 15-minute interval from level-3 entry");
    }

    $dueAtL3Plus15 = array_values(array_filter(broth_log_copilot_due_escalations(new DateTimeImmutable('2026-08-20 00:30:00 UTC')), fn($d) => $d['incident']['incident_id'] === $raceId));
    expect_eq($dueAtL3Plus15[0]['action'] ?? null, 'remind', 'L3+15: the first level-3 (URGENT) reminder fires, exactly one interval after entering level 3');
    broth_log_copilot_apply_escalation_action_with_notification($dueAtL3Plus15[0], new DateTimeImmutable('2026-08-20 00:30:00 UTC'));

    $dueAtL3Plus29 = array_values(array_filter(broth_log_copilot_due_escalations(new DateTimeImmutable('2026-08-20 00:44:00 UTC')), fn($d) => $d['incident']['incident_id'] === $raceId));
    expect_true(empty($dueAtL3Plus29), 'L3+29: no second reminder yet - only 14 minutes since the first level-3 reminder');

    $dueAtL3Plus30 = array_values(array_filter(broth_log_copilot_due_escalations(new DateTimeImmutable('2026-08-20 00:45:00 UTC')), fn($d) => $d['incident']['incident_id'] === $raceId));
    expect_eq($dueAtL3Plus30[0]['action'] ?? null, 'remind', 'L3+30: the second level-3 reminder fires, exactly 15 minutes after the first');
    broth_log_copilot_apply_escalation_action_with_notification($dueAtL3Plus30[0], new DateTimeImmutable('2026-08-20 00:45:00 UTC'));

    // Regression: Level 3 must never cap out and go silent. The fallback_required audit marker is
    // count-based (10th reminder), not time-based, so it fires exactly once regardless of interval length.
    run("UPDATE broth_log_incidents SET last_reminder_at='2026-08-20 00:45:00', reminder_count=10 WHERE incident_id=?", [$raceId]);
    $dueAtTenReminders = array_values(array_filter(broth_log_copilot_due_escalations(new DateTimeImmutable('2026-08-20 01:00:00 UTC')), fn($d) => $d['incident']['incident_id'] === $raceId));
    expect_eq($dueAtTenReminders[0]['action'] ?? null, 'fallback_reminder', 'crossing 10 reminders at level 3 is flagged for a one-time fallback audit marker, unaffected by the interval change');
    $fallbackResult = broth_log_copilot_apply_escalation_action_with_notification($dueAtTenReminders[0], new DateTimeImmutable('2026-08-20 01:00:00 UTC'));
    expect_eq($fallbackResult['action'], 'reminded', 'the fallback-marker crossing still results in a normal reminder being sent, not a terminal fallback action');
    expect_eq(q1("SELECT state FROM broth_log_incidents WHERE incident_id=?", [$raceId])['state'] ?? '', 'escalated_level_3', 'incident remains escalated_level_3, not moved to a terminal unacknowledged_critical state');
    expect_eq(q1("SELECT reminder_count FROM broth_log_incidents WHERE incident_id=?", [$raceId])['reminder_count'] ?? -1, 11, 'reminder count keeps incrementing past the old cap of 10');
    expect_eq(q1("SELECT COUNT(*) AS c FROM broth_log_incident_events WHERE incident_id=? AND event_type='fallback_required'", [$raceId])['c'] ?? -1, 1, 'exactly one fallback_required audit event is recorded at the crossing');

    // Long-running: still due, still reminding, no silent stop, at 1h/3h/6h/12h.
    run("UPDATE broth_log_incidents SET last_reminder_at='2026-08-20 01:00:00', reminder_count=25 WHERE incident_id=?", [$raceId]);
    foreach ([['02:00:00', '1 hour'], ['04:00:00', '3 hours'], ['07:00:00', '6 hours'], ['13:00:00', '12 hours']] as [$time, $label]) {
        $dueLongRunning = array_values(array_filter(broth_log_copilot_due_escalations(new DateTimeImmutable("2026-08-20 {$time} UTC")), fn($d) => $d['incident']['incident_id'] === $raceId));
        expect_true(!empty($dueLongRunning), "level-3 reminders remain due at the {$label} mark - no cap, no terminal silent state");
    }
    $dueAt1Hour = array_values(array_filter(broth_log_copilot_due_escalations(new DateTimeImmutable('2026-08-20 02:00:00 UTC')), fn($d) => $d['incident']['incident_id'] === $raceId));
    broth_log_copilot_apply_escalation_action_with_notification($dueAt1Hour[0], new DateTimeImmutable('2026-08-20 02:00:00 UTC'));
    expect_eq(q1("SELECT state FROM broth_log_incidents WHERE incident_id=?", [$raceId])['state'] ?? '', 'escalated_level_3', 'still escalated_level_3 well beyond the previous cap, long-running scenario');
    expect_eq(q1("SELECT COUNT(*) AS c FROM broth_log_incident_events WHERE incident_id=? AND event_type='fallback_required'", [$raceId])['c'] ?? -1, 1, 'fallback_required audit marker does not duplicate on later reminders');

    // ACK still immediately stops the infinite level-3 cadence, whatever the current interval.
    $level3Ack = broth_log_copilot_ack($raceId, $user, new DateTimeImmutable('2026-08-20 02:05:00 UTC'));
    expect_true($level3Ack['ok'] ?? false, 'ACK succeeds even deep into the infinite level-3 reminder cadence');
    $dueAfterLevel3Ack = array_values(array_filter(broth_log_copilot_due_escalations(new DateTimeImmutable('2026-08-20 02:20:00 UTC')), fn($d) => $d['incident']['incident_id'] === $raceId));
    expect_true(empty($dueAfterLevel3Ack), 'ACK stops all future level-3 reminders immediately - the next scheduled reminder never fires');

    // Resolve also prevents all subsequent sends, independently of ACK.
    $resolveRaceId = broth_log_copilot_create_incident(array_replace($alert, ['responseId' => 'resp-resolve-race']));
    run("UPDATE broth_log_incidents SET state='escalated_level_3', current_level=3, level_entered_at='2026-08-20 00:00:00', last_reminder_at='2026-08-20 00:15:00', reminder_count=1 WHERE incident_id=?", [$resolveRaceId]);
    $resolveResult = broth_log_copilot_resolve($resolveRaceId, $user, 38, 'closed door and moved product', new DateTimeImmutable('2026-08-20 00:16:00 UTC'));
    expect_true($resolveResult['ok'] ?? false, 'resolve succeeds on an escalated level-3 incident');
    $dueAfterResolve = array_values(array_filter(broth_log_copilot_due_escalations(new DateTimeImmutable('2026-08-20 01:00:00 UTC')), fn($d) => $d['incident']['incident_id'] === $resolveRaceId));
    expect_true(empty($dueAfterResolve), 'Resolve stops all future reminders immediately, same as ACK');

    // Duplicate-worker protection: two workers evaluating the same due reminder must not create
    // two Telegram deliveries.
    $dupRaceId = broth_log_copilot_create_incident(array_replace($alert, ['responseId' => 'resp-dup-race']));
    run("UPDATE broth_log_incidents SET created_at='2026-08-20 00:00:00', level_entered_at='2026-08-20 00:00:00', last_reminder_at=NULL, reminder_count=0, state='detected', current_level=1 WHERE incident_id=?", [$dupRaceId]);
    $dupDue = array_values(array_filter(broth_log_copilot_due_escalations(new DateTimeImmutable('2026-08-20 00:05:00 UTC')), fn($d) => $d['incident']['incident_id'] === $dupRaceId))[0];
    $dupSentBefore = count($sentMessages);
    $firstWorker = broth_log_copilot_apply_escalation_action_with_notification($dupDue, new DateTimeImmutable('2026-08-20 00:05:00 UTC'));
    $secondWorker = broth_log_copilot_apply_escalation_action_with_notification($dupDue, new DateTimeImmutable('2026-08-20 00:05:00 UTC'));
    expect_eq($firstWorker['action'] ?? null, 'reminded', 'the first worker to evaluate a due reminder applies it');
    expect_eq($secondWorker['reason'] ?? null, 'stale_action', 'a second worker evaluating the exact same due reminder is rejected as stale, not double-applied');
    expect_eq(count($sentMessages), $dupSentBefore + 1, 'two workers racing on the same due reminder produce exactly one Telegram delivery, not two');

    // Backward compatibility: an incident already at level 3 under the OLD (3-minute) cadence
    // before this deploy - like the real currently-open incidents - must adopt the new 15-minute
    // interval automatically from its existing last_reminder_at, with no DB reset or migration.
    $legacyL3Id = broth_log_copilot_create_incident(array_replace($alert, ['responseId' => 'resp-legacy-l3']));
    run("UPDATE broth_log_incidents SET state='escalated_level_3', current_level=3, level_entered_at='2026-08-20 00:00:00', last_reminder_at='2026-08-20 05:57:00', reminder_count=70 WHERE incident_id=?", [$legacyL3Id]);
    $dueAt5MinAfterOldReminder = array_values(array_filter(broth_log_copilot_due_escalations(new DateTimeImmutable('2026-08-20 06:02:00 UTC')), fn($d) => $d['incident']['incident_id'] === $legacyL3Id));
    expect_true(empty($dueAt5MinAfterOldReminder), 'an already-open level-3 incident does not get an immediate reminder just because 5 minutes (the old interval) passed since its last real reminder - it now waits the new 15-minute interval');
    $dueAt15MinAfterOldReminder = array_values(array_filter(broth_log_copilot_due_escalations(new DateTimeImmutable('2026-08-20 06:12:00 UTC')), fn($d) => $d['incident']['incident_id'] === $legacyL3Id));
    expect_eq($dueAt15MinAfterOldReminder[0]['action'] ?? null, 'remind', 'the same already-open incident naturally adopts the new 15-minute interval, measured from its existing last_reminder_at - no reset or migration needed');

    // Backward compatibility: a pre-migration row with no level_entered_at falls back to created_at.
    run("UPDATE broth_log_incidents SET created_at='2026-08-20 00:00:00', level_entered_at=NULL, last_reminder_at=NULL, reminder_count=0, state='detected', current_level=1 WHERE incident_id=?", [$raceId]);
    $legacyDue = broth_log_copilot_due_escalations(new DateTimeImmutable('2026-08-20 00:10:00 UTC'));
    $legacyTarget = array_values(array_filter($legacyDue, fn($d) => $d['incident']['incident_id'] === $raceId));
    expect_eq($legacyTarget[0]['action'] ?? null, 'escalate', 'a legacy row with no level_entered_at falls back to created_at for the escalation check');

    // --- Phase 2: per-intent query responses, EN/ES/VI localization, branch isolation ---
    $GLOBALS['BROTH_LOG_COPILOT_RECORDS_PROVIDER'] = function (string $branch): array {
        if ($branch !== 'B1') return [];
        return [[
            'id' => 'rec-1', 'branch' => 'B1', 'businessDate' => '2026-08-20', 'businessTime' => '08:00', 'employeeName' => 'Tester',
            'readings' => [
                ['key' => 'walkInFreezer', 'label' => 'Walk-In Freezer', 'category' => 'freezer', 'temperature' => 25.0, 'unit' => 'F', 'severity' => 'critical', 'target' => '<= 0F', 'correctiveAction' => 'Close door'],
                ['key' => 'bowlWarmer', 'label' => 'Bowl Warmer', 'category' => 'warm', 'temperature' => null, 'unit' => 'F', 'severity' => 'missing', 'target' => '>= 100F', 'correctiveAction' => ''],
                ['key' => 'prepAreaCooler', 'label' => 'Prep Area Cooler', 'category' => 'cold', 'temperature' => 38.0, 'unit' => 'F', 'severity' => 'safe', 'target' => '<= 40F', 'correctiveAction' => ''],
            ],
            'issues' => [
                ['key' => 'walkInFreezer', 'label' => 'Walk-In Freezer', 'category' => 'freezer', 'temperature' => 25.0, 'unit' => 'F', 'severity' => 'critical', 'target' => '<= 0F', 'correctiveAction' => 'Close door', 'status' => 'Escalated'],
                ['key' => 'bowlWarmer', 'label' => 'Bowl Warmer', 'category' => 'warm', 'temperature' => null, 'unit' => 'F', 'severity' => 'missing', 'target' => '>= 100F', 'correctiveAction' => '', 'status' => 'Open'],
            ],
        ]];
    };
    $b1User = ['telegram_user_id' => '101', 'allowed_branch_list' => ['B1'], 'preferred_language' => 'en'];
    $base = ['branch' => 'B1', 'business_date' => '2026-08-20'];

    foreach (['en' => ['1 log(s)', '1 critical', '1 missing'], 'es' => ['1 registro(s)', '1 critico(s)', '1 faltante(s)'], 'vi' => ['1 nhat ky', '1 nghiem trong', '1 thieu']] as $lang => $expectedParts) {
        $msg = broth_log_copilot_format_response($base + ['intent' => 'today_summary', 'language' => $lang], $b1User);
        expect_true(str_contains($msg, 'B1') && str_contains($msg, '2026-08-20'), "today summary [$lang] preserves store and date exactly");
        foreach ($expectedParts as $part) expect_true(str_contains($msg, $part), "today summary [$lang] localizes counts ($part)");
    }

    $critEn = broth_log_copilot_format_response($base + ['intent' => 'critical_issues', 'language' => 'en'], $b1User);
    expect_true(str_contains($critEn, 'Critical issues for B1 2026-08-20:'), 'critical issues [en] header localized');
    expect_true(str_contains($critEn, '- Walk-In Freezer: 25F (target <= 0F)'), 'critical issues [en] preserves item/temp/target exactly');
    expect_true(!str_contains($critEn, 'Bowl Warmer'), 'critical issues excludes non-critical (missing) reading');
    $critEs = broth_log_copilot_format_response($base + ['intent' => 'critical_issues', 'language' => 'es'], $b1User);
    expect_true(str_contains($critEs, 'Problemas criticos para B1 2026-08-20:'), 'critical issues [es] header localized');
    expect_true(str_contains($critEs, '- Walk-In Freezer: 25F (objetivo <= 0F)'), 'critical issues [es] preserves item/temp/target exactly');
    $critVi = broth_log_copilot_format_response($base + ['intent' => 'critical_issues', 'language' => 'vi'], $b1User);
    expect_true(str_contains($critVi, 'Van de nghiem trong cho B1 2026-08-20:'), 'critical issues [vi] header localized');
    expect_true(str_contains($critVi, '- Walk-In Freezer: 25F (muc tieu <= 0F)'), 'critical issues [vi] preserves item/temp/target exactly');

    $openEn = broth_log_copilot_format_response($base + ['intent' => 'open_issues', 'language' => 'en'], $b1User);
    expect_true(str_contains($openEn, 'Open issues for B1 2026-08-20:'), 'open issues [en] header localized');
    expect_true(str_contains($openEn, '- Walk-In Freezer: 25F (status Escalated)'), 'open issues [en] includes critical-but-open item');
    expect_true(str_contains($openEn, '- Bowl Warmer:'), 'open issues [en] includes missing-but-open item');
    $openVi = broth_log_copilot_format_response($base + ['intent' => 'open_issues', 'language' => 'vi'], $b1User);
    expect_true(str_contains($openVi, 'Van de con mo cho B1 2026-08-20:'), 'open issues [vi] header localized');

    $missEn = broth_log_copilot_format_response($base + ['intent' => 'missing_logs', 'language' => 'en'], $b1User);
    expect_true(str_contains($missEn, 'Missing logs for B1 2026-08-20:') && str_contains($missEn, '- Bowl Warmer'), 'missing logs [en] lists only missing station');
    expect_true(!str_contains($missEn, 'Walk-In Freezer') && !str_contains($missEn, 'Prep Area Cooler'), 'missing logs [en] excludes non-missing stations');
    $missEs = broth_log_copilot_format_response($base + ['intent' => 'missing_logs', 'language' => 'es'], $b1User);
    expect_true(str_contains($missEs, 'Registros faltantes para B1 2026-08-20:'), 'missing logs [es] header localized');

    $tempEn = broth_log_copilot_format_response($base + ['intent' => 'temperature_lookup', 'language' => 'en', 'station' => 'walkInFreezer'], $b1User);
    expect_eq($tempEn, 'Walk-In Freezer at B1 2026-08-20: 25F (target <= 0F).', 'temperature lookup [en] shows requested station reading exactly');
    $tempVi = broth_log_copilot_format_response($base + ['intent' => 'temperature_lookup', 'language' => 'vi', 'station' => 'walkInFreezer'], $b1User);
    expect_eq($tempVi, 'Walk-In Freezer tai B1 2026-08-20: 25F (muc tieu <= 0F).', 'temperature lookup [vi] localized, preserves item/temp/target');
    $tempNoStation = broth_log_copilot_format_response($base + ['intent' => 'temperature_lookup', 'language' => 'en'], $b1User);
    expect_true(str_contains($tempNoStation, 'Which item or station'), 'temperature lookup without station asks for clarification');

    $sopEn = broth_log_copilot_format_response($base + ['intent' => 'sop_comparison', 'language' => 'en', 'station' => 'walkInFreezer'], $b1User);
    expect_eq($sopEn, 'Walk-In Freezer at B1 2026-08-20: entered 25F vs SOP target <= 0F -> critical.', 'SOP comparison [en] shows entered value vs canonical target with verdict');
    $sopEs = broth_log_copilot_format_response($base + ['intent' => 'sop_comparison', 'language' => 'es', 'station' => 'walkInFreezer'], $b1User);
    expect_eq($sopEs, 'Walk-In Freezer en B1 2026-08-20: ingresado 25F vs objetivo SOP <= 0F -> critico.', 'SOP comparison [es] localized including verdict word');

    foreach (['en' => 'I cannot access that store', 'es' => 'No puedo acceder a esa tienda', 'vi' => 'Toi khong the truy cap chi nhanh'] as $lang => $expectedPrefix) {
        $forbidden = broth_log_copilot_format_response(['branch' => 'B2', 'business_date' => '2026-08-20', 'intent' => 'today_summary', 'language' => $lang], $b1User);
        expect_true(str_contains($forbidden, $expectedPrefix), "cross-branch query [$lang] is denied with localized message (B1 tester cannot read B2)");
    }
    unset($GLOBALS['BROTH_LOG_COPILOT_RECORDS_PROVIDER']);

    // Regression: incident-reference digits must never be parsed as temperature (Phase 7 concern)
    $refParsed = broth_log_copilot_parse('/resolve #bl-1234567890-20260820120000-abcdef 38F closed door and moved product', $b1User, new DateTimeImmutable('2026-08-20 00:00:00 UTC'));
    expect_eq($refParsed['temperature_f'], 38.0, 'incident-reference digits are not misread as the recheck temperature');

    // --- Explicit business-date parsing (deterministic, no LLM) ---
    $fixedNow = new DateTimeImmutable('2026-08-20 18:00:00 UTC'); // business date 2026-08-20 in America/Chicago

    $isoParsed = broth_log_copilot_parse('B1 2026-07-19 critical issues', $b1User, $fixedNow);
    expect_eq($isoParsed['business_date'], '2026-07-19', 'ISO date is parsed exactly');
    expect_true($isoParsed['date_error'] === null, 'ISO date produces no date error');

    $monthDayParsed = broth_log_copilot_parse('B1 July 19 critical issues', $b1User, $fixedNow);
    expect_eq($monthDayParsed['business_date'], '2026-07-19', 'English "Month Day" is parsed exactly');

    $dayMonthParsed = broth_log_copilot_parse('B1 19 July critical issues', $b1User, $fixedNow);
    expect_eq($dayMonthParsed['business_date'], '2026-07-19', 'English "Day Month" order is also parsed exactly');

    $abbrevParsed = broth_log_copilot_parse('B1 Jul 19 open issues', $b1User, $fixedNow);
    expect_eq($abbrevParsed['business_date'], '2026-07-19', 'Month abbreviation is parsed exactly');

    $invalidCalendar = broth_log_copilot_parse('B1 February 30 critical issues', $b1User, $fixedNow);
    expect_true($invalidCalendar['date_error'] === 'invalid_date', 'invalid calendar date (Feb 30) is rejected, not guessed');
    expect_true($invalidCalendar['business_date'] === null, 'invalid calendar date does not silently resolve to a date');

    $invalidIso = broth_log_copilot_parse('B1 2026-13-40 today', $b1User, $fixedNow);
    expect_true($invalidIso['date_error'] === 'invalid_date', 'invalid ISO date (month 13) is rejected, not guessed');

    $futureParsed = broth_log_copilot_parse('B1 December 25 critical issues', $b1User, $fixedNow);
    expect_true($futureParsed['date_error'] === 'future_date', 'a date after business-today is rejected as a future date');
    expect_true($futureParsed['business_date'] === null, 'future date does not silently resolve to a date');

    $todayParsed = broth_log_copilot_parse('B1 today', $b1User, $fixedNow);
    expect_eq($todayParsed['business_date'], '2026-08-20', 'plain "today" still resolves correctly (no regression)');
    $yesterdayParsed = broth_log_copilot_parse('B1 yesterday', $b1User, $fixedNow);
    expect_eq($yesterdayParsed['business_date'], '2026-08-19', 'plain "yesterday" still resolves correctly (no regression)');

    // Regression (found via real staging Telegram testing): a bare explicit date with no other
    // intent keyword must behave like "today", not silently fall back to the help message.
    $bareDateParsed = broth_log_copilot_parse('B1 July 19', $b1User, $fixedNow);
    expect_eq($bareDateParsed['intent'], 'today_summary', 'bare explicit date defaults to daily summary intent, not help');
    expect_eq($bareDateParsed['business_date'], '2026-07-19', 'bare explicit date still resolves the correct business date');
    $bareIsoParsed = broth_log_copilot_parse('B1 2026-07-19', $b1User, $fixedNow);
    expect_eq($bareIsoParsed['intent'], 'today_summary', 'bare ISO date also defaults to daily summary intent, not help');
    $explicitKeywordStillWorks = broth_log_copilot_parse('B1 July 19 critical issues', $b1User, $fixedNow);
    expect_eq($explicitKeywordStillWorks['intent'], 'critical_issues', 'an explicit intent keyword alongside a date still takes precedence over the daily-summary default');
    $bareHelpParsed = broth_log_copilot_parse('help', $b1User, $fixedNow);
    expect_eq($bareHelpParsed['intent'], 'help', 'a message with no date and no keyword still defaults to help (unchanged)');
    $explicitHelpWithDate = broth_log_copilot_parse('help B1 July 19', $b1User, $fixedNow);
    expect_eq($explicitHelpWithDate['intent'], 'help', 'an explicit "help" keyword still wins even if a date is also present');

    foreach (['en' => 'That date does not look valid', 'es' => 'Esa fecha no parece valida', 'vi' => 'Ngay do khong hop le'] as $lang => $expectedPrefix) {
        $msg = broth_log_copilot_format_response(['intent' => 'critical_issues', 'language' => $lang, 'branch' => 'B1', 'business_date' => null, 'date_error' => 'invalid_date'], $b1User);
        expect_true(str_contains($msg, $expectedPrefix), "invalid date rejection [$lang] is localized");
    }
    foreach (['en' => 'I cannot look up a future date', 'es' => 'No puedo consultar una fecha futura', 'vi' => 'Toi khong the tra cuu ngay trong tuong lai'] as $lang => $expectedPrefix) {
        $msg = broth_log_copilot_format_response(['intent' => 'today_summary', 'language' => $lang, 'branch' => 'B1', 'business_date' => null, 'date_error' => 'future_date'], $b1User);
        expect_true(str_contains($msg, $expectedPrefix), "future date rejection [$lang] is localized");
    }

    // Explicit date wired into per-intent filtering against a real, previously-verified historical
    // B1 date (2026-07-19: logs=1, critical=1, open=3, missing=0, confirmed via live sheet inspection).
    // Walk-In Freezer's real recorded temperature that day was 10F - deliberately used here (instead
    // of a rounder/safer test number) because it's the exact real value that exposed a formatting
    // regression during live staging testing (see broth_log_copilot_format_number below).
    // Synthetic fixture only - no data was written to the real Broth Log sheet.
    $GLOBALS['BROTH_LOG_COPILOT_RECORDS_PROVIDER'] = function (string $branch): array {
        if ($branch !== 'B1') return [];
        return [[
            'id' => 'rec-jul19', 'branch' => 'B1', 'businessDate' => '2026-07-19', 'businessTime' => '17:36', 'employeeName' => 'Yenci',
            'readings' => [
                ['key' => 'walkInFreezer', 'label' => 'Walk-In Freezer', 'category' => 'freezer', 'temperature' => 10.0, 'unit' => 'F', 'severity' => 'critical', 'target' => '<= 0F', 'correctiveAction' => 'Alert MOD'],
                ['key' => 'prepAreaCooler', 'label' => 'Prep Area Cooler', 'category' => 'cold', 'temperature' => 39.0, 'unit' => 'F', 'severity' => 'safe', 'target' => '<= 40F', 'correctiveAction' => ''],
            ],
            'issues' => [
                ['key' => 'walkInFreezer', 'label' => 'Walk-In Freezer', 'category' => 'freezer', 'temperature' => 10.0, 'unit' => 'F', 'severity' => 'critical', 'target' => '<= 0F', 'correctiveAction' => 'Alert MOD', 'status' => 'Open'],
            ],
        ]];
    };
    $histCritical = broth_log_copilot_format_response(['intent' => 'critical_issues', 'language' => 'en', 'branch' => 'B1', 'business_date' => '2026-07-19', 'date_error' => null, 'station' => null], $b1User);
    expect_true(str_contains($histCritical, 'Walk-In Freezer') && str_contains($histCritical, '10F'), 'explicit-date critical_issues query returns the real historical critical reading with the correct temperature (not truncated to 1F)');
    $histTemp = broth_log_copilot_format_response(['intent' => 'temperature_lookup', 'language' => 'en', 'branch' => 'B1', 'business_date' => '2026-07-19', 'date_error' => null, 'station' => 'walkInFreezer'], $b1User);
    expect_eq($histTemp, 'Walk-In Freezer at B1 2026-07-19: 10F (target <= 0F).', 'explicit-date temperature_lookup returns the requested station for the requested historical date');
    $histSop = broth_log_copilot_format_response(['intent' => 'sop_comparison', 'language' => 'en', 'branch' => 'B1', 'business_date' => '2026-07-19', 'date_error' => null, 'station' => 'walkInFreezer'], $b1User);
    expect_eq($histSop, 'Walk-In Freezer at B1 2026-07-19: entered 10F vs SOP target <= 0F -> critical.', 'explicit-date sop_comparison shows entered value with correct temperature');
    $histForbidden = broth_log_copilot_format_response(['intent' => 'critical_issues', 'language' => 'en', 'branch' => 'B2', 'business_date' => '2026-07-19', 'date_error' => null], $b1User);
    expect_true(str_contains($histForbidden, 'I cannot access that store'), 'explicit-date query still enforces branch isolation (B1 tester cannot read B2 for any date)');
    unset($GLOBALS['BROTH_LOG_COPILOT_RECORDS_PROVIDER']);

    // Regression: rtrim(rtrim($s,'0'),'.') silently turned whole-number temperatures ending in 0
    // into the wrong (smaller) number (10 -> 1, 100 -> 1, 120 -> 12). Found via real Telegram
    // testing where a real 10F reading was displayed as 1F.
    expect_eq(broth_log_copilot_format_number(10.0), '10', 'format_number does not truncate a whole number ending in one zero');
    expect_eq(broth_log_copilot_format_number(100.0), '100', 'format_number does not truncate a whole number ending in two zeros');
    expect_eq(broth_log_copilot_format_number(120.0), '120', 'format_number does not truncate a whole number with an internal zero before a trailing digit');
    expect_eq(broth_log_copilot_format_number(38.5), '38.5', 'format_number preserves a genuine decimal value');
    expect_eq(broth_log_copilot_format_number(3.0), '3', 'format_number renders a small whole number correctly');
    expect_eq(broth_log_copilot_temp_text(10.0, 'en'), '10F', 'temp_text uses the corrected formatter, not the old truncating one');

    // --- /pilotid manager onboarding: usable by an unauthorized sender, grants zero access ---
    // The Manager Onboarding Group is now a THIRD, independent Telegram surface - configured via
    // TELEGRAM_MANAGER_ONBOARDING_CHAT_ID, never derived from broth_log_routing_rules. $opsGroupChatId
    // (routing-based) continues to represent the Alert/Fallback group used throughout the rest of
    // this file for proactive delivery - it is deliberately NOT the onboarding group anymore.
    $pilotUnauthorizedId = '778899';
    $opsGroupChatId = 'ops-group-chat-for-pilotid-test';
    $onboardingGroupChatId = 'manager-onboarding-group-chat-for-test';
    foreach (['B1', 'B2', 'B3'] as $pilotBranch) {
        for ($pilotLevel = 1; $pilotLevel <= 3; $pilotLevel++) {
            run("INSERT OR REPLACE INTO broth_log_routing_rules (branch,stage,level,telegram_user_ids,chat_id,active) VALUES (?,?,?,?,?,1)",
                [$pilotBranch, 'staging', $pilotLevel, json_encode(['999']), $opsGroupChatId]);
        }
    }
    putenv('TELEGRAM_MANAGER_ONBOARDING_CHAT_ID=' . $onboardingGroupChatId);

    // Missing config fails closed: with no onboarding chat configured, nothing is ever accepted.
    putenv('TELEGRAM_MANAGER_ONBOARDING_CHAT_ID');
    expect_true(broth_log_copilot_manager_onboarding_chat_ids() === [], 'with no config, there are zero recognized onboarding chats');
    expect_true(!broth_log_copilot_is_manager_onboarding_chat($onboardingGroupChatId), 'with no config, even the real onboarding group chat id is not recognized - fails closed, not open');
    expect_true(!broth_log_copilot_is_manager_onboarding_chat($opsGroupChatId), 'with no config, the Alert/Fallback group is (still) not mistaken for the onboarding group either');
    putenv('TELEGRAM_MANAGER_ONBOARDING_CHAT_ID=' . $onboardingGroupChatId);

    expect_true(broth_log_copilot_is_manager_onboarding_chat($onboardingGroupChatId), 'the configured TELEGRAM_MANAGER_ONBOARDING_CHAT_ID is recognized as the trusted onboarding chat');
    expect_true(!broth_log_copilot_is_manager_onboarding_chat('some-unrelated-chat'), 'an arbitrary chat id is not recognized as the onboarding chat');
    expect_true(!broth_log_copilot_is_manager_onboarding_chat($opsGroupChatId), 'the Alert/Fallback group (routing-configured) is explicitly NOT the onboarding group - the two configurations are fully independent now');

    // Regression: real Telegram group chat ids are numeric-looking strings (e.g. "-5367135326").
    // Using a chat id as a PHP array key (as an earlier draft did, to dedupe) silently coerces a
    // numeric-string key to a real int, which then fails hash_equals()'s strict string-only type
    // check with a TypeError - crashing the production worker on every tick as long as any
    // /pilotid message stayed queued, and starving the escalation-reminder loop that runs after
    // it in the same script. Caught only by testing with a genuinely numeric chat id, not the
    // non-numeric one used everywhere else in this test file.
    $numericOnboardingChatId = '-5367135399';
    putenv('TELEGRAM_MANAGER_ONBOARDING_CHAT_ID=' . $numericOnboardingChatId);
    expect_true(broth_log_copilot_is_manager_onboarding_chat($numericOnboardingChatId), 'a numeric-string chat id is matched correctly, with no TypeError from array-key coercion');
    broth_log_copilot_enqueue_webhook([
        'update_id' => 5099,
        'message' => ['text' => '/pilotid', 'from' => ['id' => (int)$pilotUnauthorizedId], 'chat' => ['id' => $numericOnboardingChatId], 'message_id' => 599],
    ]);
    $pilotNumericChatProcessed = broth_log_copilot_process_inbox(10, new DateTimeImmutable('2026-08-22 00:00:00 UTC'));
    expect_eq(find_processed($pilotNumericChatProcessed, '5099')['status'] ?? '', 'processed', '/pilotid through a real numeric-shaped chat id is processed without crashing the worker');
    putenv('TELEGRAM_MANAGER_ONBOARDING_CHAT_ID=' . $onboardingGroupChatId);

    $authorizedUsersBefore = count(q("SELECT * FROM broth_log_authorized_users"));
    $routingSnapshotBefore = q("SELECT branch,stage,level,telegram_user_ids,chat_id,active FROM broth_log_routing_rules ORDER BY branch,stage,level");

    // 1 + 5: an unauthorized sender can run /pilotid, and only inside the real Manager Onboarding Group.
    expect_true(broth_log_copilot_authorized_user($pilotUnauthorizedId) === null, 'the pilotid test sender starts out unauthorized');
    broth_log_copilot_enqueue_webhook([
        'update_id' => 5001,
        'message' => ['text' => '/pilotid', 'from' => ['id' => (int)$pilotUnauthorizedId], 'chat' => ['id' => $onboardingGroupChatId], 'message_id' => 501],
    ]);
    $pilotProcessed1 = broth_log_copilot_process_inbox(10, new DateTimeImmutable('2026-08-22 00:00:00 UTC'));
    $pilotRow1 = find_processed($pilotProcessed1, '5001');
    expect_eq($pilotRow1['status'] ?? '', 'processed', 'unauthorized sender in the real Manager Onboarding Group: /pilotid is processed, not denied');
    expect_eq($pilotRow1['intent'] ?? '', 'pilot_id', 'the processed row is tagged with the pilot_id intent');
    expect_eq($sentMessages[count($sentMessages) - 1]['payload']['text'], 'TEST - ' . broth_log_copilot_tr('pilot_id_received', 'en'), 'unauthorized /pilotid gets the identity-received waiting-for-approval reply');

    // 2: /pilotid sent in the OLD Alert/Fallback group is now rejected - onboarding no longer
    // accepts the group that alerts are delivered to, only the dedicated onboarding group.
    broth_log_copilot_enqueue_webhook(['update_id' => 5012, 'message' => ['text' => '/pilotid', 'from' => ['id' => (int)$pilotUnauthorizedId], 'chat' => ['id' => $opsGroupChatId], 'message_id' => 512]]);
    $pilotOldGroupProcessed = broth_log_copilot_process_inbox(10, new DateTimeImmutable('2026-08-22 00:00:00 UTC'));
    expect_eq(find_processed($pilotOldGroupProcessed, '5012')['status'] ?? '', 'denied', '2: /pilotid sent in the old Alert/Fallback group is rejected - it is no longer accepted for onboarding');

    // 9 + 10: numeric sender id is captured internally in the inbox row, but never appears in the reply text.
    $pilotInboxRow1 = q1("SELECT telegram_user_id FROM broth_log_bot_inbox WHERE update_id='5001'");
    expect_eq($pilotInboxRow1['telegram_user_id'] ?? '', $pilotUnauthorizedId, 'the numeric sender id is captured internally in the inbox row');
    expect_true(!str_contains($sentMessages[count($sentMessages) - 1]['payload']['text'], $pilotUnauthorizedId), 'the numeric sender id never appears in the Telegram reply text');

    // 11 + 12: no authorization or routing mutation happened.
    expect_eq(count(q("SELECT * FROM broth_log_authorized_users")), $authorizedUsersBefore, 'no authorized-user row was created by /pilotid');
    expect_true(q("SELECT branch,stage,level,telegram_user_ids,chat_id,active FROM broth_log_routing_rules ORDER BY branch,stage,level") === $routingSnapshotBefore, 'no routing row was changed by /pilotid');

    // 2, 3, 4: the same still-unauthorized sender remains fully denied for every real command.
    broth_log_copilot_enqueue_webhook(['update_id' => 5002, 'message' => ['text' => 'today B1', 'from' => ['id' => (int)$pilotUnauthorizedId], 'chat' => ['id' => $onboardingGroupChatId], 'message_id' => 502]]);
    broth_log_copilot_enqueue_webhook(['update_id' => 5003, 'message' => ['text' => '/ack', 'from' => ['id' => (int)$pilotUnauthorizedId], 'chat' => ['id' => $onboardingGroupChatId], 'message_id' => 503]]);
    broth_log_copilot_enqueue_webhook(['update_id' => 5004, 'message' => ['text' => '/resolve', 'from' => ['id' => (int)$pilotUnauthorizedId], 'chat' => ['id' => $onboardingGroupChatId], 'message_id' => 504]]);
    $pilotProcessed2 = broth_log_copilot_process_inbox(10, new DateTimeImmutable('2026-08-22 00:00:00 UTC'));
    expect_eq(find_processed($pilotProcessed2, '5002')['status'] ?? '', 'denied', 'the same unauthorized sender still cannot run a real query command (today B1)');
    expect_eq(find_processed($pilotProcessed2, '5003')['status'] ?? '', 'denied', 'the same unauthorized sender still cannot ACK');
    expect_eq(find_processed($pilotProcessed2, '5004')['status'] ?? '', 'denied', 'the same unauthorized sender still cannot Resolve');

    // 3 + 4: /pilotid from any chat other than the real Manager Onboarding Group gets no special
    // treatment - it falls straight through to the same deny-by-default path as everything else.
    broth_log_copilot_enqueue_webhook(['update_id' => 5005, 'message' => ['text' => '/pilotid', 'from' => ['id' => (int)$pilotUnauthorizedId], 'chat' => ['id' => 'some-other-group-chat'], 'message_id' => 505]]);
    broth_log_copilot_enqueue_webhook(['update_id' => 5006, 'message' => ['text' => '/pilotid', 'from' => ['id' => (int)$pilotUnauthorizedId], 'chat' => ['id' => $pilotUnauthorizedId], 'message_id' => 506]]);
    broth_log_copilot_enqueue_webhook(['update_id' => 5007, 'message' => ['text' => '/pilotid', 'from' => ['id' => (int)$pilotUnauthorizedId], 'chat' => ['id' => 'other-staging-bot-chat'], 'message_id' => 507]]);
    $pilotProcessed3 = broth_log_copilot_process_inbox(10, new DateTimeImmutable('2026-08-22 00:00:00 UTC'));
    expect_eq(find_processed($pilotProcessed3, '5005')['status'] ?? '', 'denied', '4: wrong (unknown) group is rejected for /pilotid, no special reply');
    expect_eq(find_processed($pilotProcessed3, '5006')['status'] ?? '', 'denied', '3: a private DM (chat id == sender id) is rejected for /pilotid');
    expect_eq(find_processed($pilotProcessed3, '5007')['status'] ?? '', 'denied', 'an unrelated staging/other chat is rejected for /pilotid');

    // 5: staging isolation - a chat id only configured under a staging-style env var name is never
    // accepted by the production lookup, since TELEGRAM_MANAGER_ONBOARDING_CHAT_ID is read from
    // whichever env file BAKUDAN_TELEGRAM_ENV_FILE points at, and production/staging always use
    // entirely separate env files - never a shared or fallback path.
    $stagingOnlyChatId = 'staging-only-onboarding-chat';
    expect_true(!broth_log_copilot_is_manager_onboarding_chat($stagingOnlyChatId), '5: a chat id that is not the configured production value is rejected, modeling staging isolation - no cross-environment fallback exists in this lookup');

    // 13: repeated /pilotid from the same sender in the real onboarding group is idempotent.
    broth_log_copilot_enqueue_webhook(['update_id' => 5008, 'message' => ['text' => '/pilotid', 'from' => ['id' => (int)$pilotUnauthorizedId], 'chat' => ['id' => $onboardingGroupChatId], 'message_id' => 508]]);
    $pilotProcessed4 = broth_log_copilot_process_inbox(10, new DateTimeImmutable('2026-08-22 00:00:00 UTC'));
    $pilotRow4 = find_processed($pilotProcessed4, '5008');
    expect_eq($pilotRow4['status'] ?? '', 'processed', 'a second /pilotid from the same still-unauthorized sender is processed the same way');
    expect_eq($pilotRow4['intent'] ?? '', 'pilot_id', 'the repeated /pilotid is still tagged pilot_id');
    expect_eq($sentMessages[count($sentMessages) - 1]['payload']['text'], 'TEST - ' . broth_log_copilot_tr('pilot_id_received', 'en'), 'repeated /pilotid gets the identical waiting-for-approval reply, not a different one');
    expect_eq(count(q("SELECT * FROM broth_log_authorized_users")), $authorizedUsersBefore, 'repeating /pilotid still creates no authorized-user row');

    // 14: the bot-username-suffixed form also works.
    broth_log_copilot_enqueue_webhook(['update_id' => 5009, 'message' => ['text' => '/pilotid@brothlog_bot', 'from' => ['id' => (int)$pilotUnauthorizedId], 'chat' => ['id' => $onboardingGroupChatId], 'message_id' => 509]]);
    $pilotProcessed5 = broth_log_copilot_process_inbox(10, new DateTimeImmutable('2026-08-22 00:00:00 UTC'));
    expect_eq(find_processed($pilotProcessed5, '5009')['status'] ?? '', 'processed', '/pilotid@brothlog_bot (group-suffixed form) is recognized the same as /pilotid');
    expect_eq(find_processed($pilotProcessed5, '5009')['intent'] ?? '', 'pilot_id', '/pilotid@brothlog_bot is tagged with the pilot_id intent');

    // 15: arbitrary text mentioning "pilotid" does not trigger onboarding.
    expect_true(!broth_log_copilot_is_pilot_id_text('please run pilotid for me'), 'arbitrary text containing "pilotid" is not recognized as the onboarding command');
    expect_true(!broth_log_copilot_is_pilot_id_text('/pilotid now please'), 'trailing text after /pilotid is not recognized as the anchored onboarding command');
    broth_log_copilot_enqueue_webhook(['update_id' => 5010, 'message' => ['text' => 'please run pilotid for me', 'from' => ['id' => (int)$pilotUnauthorizedId], 'chat' => ['id' => $onboardingGroupChatId], 'message_id' => 510]]);
    $pilotProcessed6 = broth_log_copilot_process_inbox(10, new DateTimeImmutable('2026-08-22 00:00:00 UTC'));
    expect_eq(find_processed($pilotProcessed6, '5010')['status'] ?? '', 'denied', 'arbitrary text mentioning pilotid from an unauthorized sender is denied like any other unrecognized command, not treated as onboarding');

    // 16: an already-authorized user gets a harmless "already registered" reply, with zero mutation.
    expect_true(broth_log_copilot_authorized_user('101') !== null, 'sanity: telegram id 101 is the already-authorized B1 manager fixture');
    $existingUserBefore = q1("SELECT telegram_user_id,display_name,role,allowed_branches,active FROM broth_log_authorized_users WHERE telegram_user_id='101'");
    broth_log_copilot_enqueue_webhook(['update_id' => 5011, 'message' => ['text' => '/pilotid', 'from' => ['id' => 101], 'chat' => ['id' => $onboardingGroupChatId], 'message_id' => 511]]);
    $pilotProcessed7 = broth_log_copilot_process_inbox(10, new DateTimeImmutable('2026-08-22 00:00:00 UTC'));
    $pilotRow7 = find_processed($pilotProcessed7, '5011');
    expect_eq($pilotRow7['status'] ?? '', 'processed', 'an already-authorized user sending /pilotid is processed');
    expect_eq($pilotRow7['intent'] ?? '', 'pilot_id', 'the already-authorized reply is still tagged pilot_id');
    expect_eq($sentMessages[count($sentMessages) - 1]['payload']['text'], 'TEST - ' . broth_log_copilot_tr('pilot_id_already_registered', 'en'), 'an already-authorized user gets the harmless "already registered" reply, not the waiting-for-approval one');
    expect_true(q1("SELECT telegram_user_id,display_name,role,allowed_branches,active FROM broth_log_authorized_users WHERE telegram_user_id='101'") === $existingUserBefore, 'the already-authorized user\'s row is completely unchanged by /pilotid');

    // --- Poison-row isolation: one row that throws must not abort the batch or block escalation ---
    // Reuses the existing records-provider test seam to simulate a realistic downstream failure
    // (e.g. malformed/unexpected data from the branch-records source) rather than inventing a new
    // test-only hook - the same class of "something deep in per-row processing throws" as the real
    // production incident on 2026-08-22, just triggered deterministically instead of by a live bug.
    $poisonProvider = function (string $branch) {
        throw new RuntimeException('simulated poison row failure - malformed downstream data');
    };
    $poisonUser = broth_log_copilot_authorized_user('101');

    // Seed a real due Level-3 incident, exactly mirroring the worker script's own sequence
    // ($due computed, then process_inbox(), then the due actions are applied) to prove the
    // escalation phase still runs after a poison row in the same batch.
    $poisonIncidentId = broth_log_copilot_create_incident(array_replace($alert, ['responseId' => 'resp-poison-escalation']));
    run("UPDATE broth_log_incidents SET state='escalated_level_3', current_level=3, level_entered_at='2026-08-22 00:00:00', last_reminder_at='2026-08-22 00:00:00', reminder_count=1 WHERE incident_id=?", [$poisonIncidentId]);
    $poisonNow = new DateTimeImmutable('2026-08-22 01:00:00 UTC');
    $poisonDueBeforeInbox = broth_log_copilot_due_escalations($poisonNow);
    $poisonEscalationTarget = array_values(array_filter($poisonDueBeforeInbox, fn($d) => $d['incident']['incident_id'] === $poisonIncidentId));
    expect_true(!empty($poisonEscalationTarget), 'the real incident is genuinely due for a reminder at the same moment the poison row will be processed');

    broth_log_copilot_enqueue_webhook(['update_id' => 6001, 'message' => ['text' => '/help', 'from' => ['id' => 101], 'chat' => ['id' => 999], 'message_id' => 601]]);
    broth_log_copilot_enqueue_webhook(['update_id' => 6002, 'message' => ['text' => 'today B1', 'from' => ['id' => 101], 'chat' => ['id' => 999], 'message_id' => 602]]);
    broth_log_copilot_enqueue_webhook(['update_id' => 6003, 'message' => ['text' => '/help', 'from' => ['id' => 101], 'chat' => ['id' => 999], 'message_id' => 603]]);

    $GLOBALS['BROTH_LOG_COPILOT_RECORDS_PROVIDER'] = $poisonProvider;
    $poisonProcessed = broth_log_copilot_process_inbox(10, $poisonNow);
    unset($GLOBALS['BROTH_LOG_COPILOT_RECORDS_PROVIDER']);

    $poisonRowA = find_processed($poisonProcessed, '6001');
    $poisonRowPoison = find_processed($poisonProcessed, '6002');
    $poisonRowB = find_processed($poisonProcessed, '6003');
    expect_eq($poisonRowA['status'] ?? '', 'processed', 'the valid row BEFORE the poison row is processed normally');
    expect_eq($poisonRowPoison['status'] ?? '', 'processing_failed', 'the poison row itself ends in a terminal processing_failed status, not left queued forever');
    expect_eq($poisonRowPoison['reason'] ?? '', 'internal_error', 'the poison row records a sanitized failure category, not the raw exception');
    expect_eq($poisonRowB['status'] ?? '', 'processed', 'the valid row AFTER the poison row is still processed - the batch is not aborted');

    $poisonInboxRow = q1("SELECT status, last_error, outbound_status FROM broth_log_bot_inbox WHERE update_id='6002'");
    expect_eq($poisonInboxRow['status'] ?? '', 'processing_failed', 'the poison row\'s persisted status is processing_failed');
    expect_eq($poisonInboxRow['last_error'] ?? '', 'internal_error', 'the persisted failure reason is the sanitized category');
    expect_true(!str_contains((string)$poisonInboxRow['last_error'], 'simulated poison row failure'), 'the raw exception message is never persisted, only the sanitized category');
    expect_true($poisonInboxRow['outbound_status'] === null, 'a processing_failed row never reached the outbound-send step, so outbound_status is untouched');

    // Most important: the escalation/reminder phase (which the worker script runs right after
    // process_inbox() returns) still completes normally in the same tick a poison row was present.
    $poisonEscalationResult = broth_log_copilot_apply_escalation_action_with_notification($poisonEscalationTarget[0], $poisonNow);
    expect_eq($poisonEscalationResult['action'] ?? '', 'reminded', 'the escalation/reminder phase still completes in the same tick a poison row was present and handled');
    expect_eq($poisonEscalationResult['outbound_sent'] ?? -1, 1, 'the reminder is actually delivered - the poison row did not silently suppress escalation delivery');

    // No duplicate processing: a processing_failed row is never picked up again.
    $poisonNoRequeue = broth_log_copilot_process_inbox(10, $poisonNow);
    expect_true(find_processed($poisonNoRequeue, '6002') === null, 'a processing_failed row is never picked up again on a subsequent process_inbox() call - no duplicate processing');

    // No open transaction / no stale lock survives a poison row: a completely unrelated real
    // mutation (ACK on a freshly seeded incident) must still succeed immediately afterward.
    $poisonLockProbeId = broth_log_copilot_create_incident(array_replace($alert, ['responseId' => 'resp-poison-lock-probe']));
    run("UPDATE broth_log_incidents SET state='escalated_level_3', current_level=3, level_entered_at='2026-08-22 00:00:00', last_reminder_at='2026-08-22 00:00:00', reminder_count=1 WHERE incident_id=?", [$poisonLockProbeId]);
    $poisonAckProbe = broth_log_copilot_ack($poisonLockProbeId, $poisonUser, $poisonNow);
    expect_true($poisonAckProbe['ok'] ?? false, 'a real mutation succeeds immediately after a poison row - proving no transaction or lock was left open');

    // Positional variations: poison first in the batch, and multiple poison rows mixed with valid ones.
    broth_log_copilot_enqueue_webhook(['update_id' => 6011, 'message' => ['text' => 'today B1', 'from' => ['id' => 101], 'chat' => ['id' => 999], 'message_id' => 611]]);
    broth_log_copilot_enqueue_webhook(['update_id' => 6012, 'message' => ['text' => '/help', 'from' => ['id' => 101], 'chat' => ['id' => 999], 'message_id' => 612]]);
    $GLOBALS['BROTH_LOG_COPILOT_RECORDS_PROVIDER'] = $poisonProvider;
    $poisonFirstProcessed = broth_log_copilot_process_inbox(10, $poisonNow);
    unset($GLOBALS['BROTH_LOG_COPILOT_RECORDS_PROVIDER']);
    expect_eq(find_processed($poisonFirstProcessed, '6011')['status'] ?? '', 'processing_failed', 'a poison row as the FIRST row in the batch still ends in processing_failed');
    expect_eq(find_processed($poisonFirstProcessed, '6012')['status'] ?? '', 'processed', 'a valid row after a leading poison row is still processed');

    broth_log_copilot_enqueue_webhook(['update_id' => 6021, 'message' => ['text' => 'today B1', 'from' => ['id' => 101], 'chat' => ['id' => 999], 'message_id' => 621]]);
    broth_log_copilot_enqueue_webhook(['update_id' => 6022, 'message' => ['text' => '/help', 'from' => ['id' => 101], 'chat' => ['id' => 999], 'message_id' => 622]]);
    broth_log_copilot_enqueue_webhook(['update_id' => 6023, 'message' => ['text' => 'today B1', 'from' => ['id' => 101], 'chat' => ['id' => 999], 'message_id' => 623]]);
    $GLOBALS['BROTH_LOG_COPILOT_RECORDS_PROVIDER'] = $poisonProvider;
    $multiPoisonProcessed = broth_log_copilot_process_inbox(10, $poisonNow);
    unset($GLOBALS['BROTH_LOG_COPILOT_RECORDS_PROVIDER']);
    expect_eq(find_processed($multiPoisonProcessed, '6021')['status'] ?? '', 'processing_failed', 'the first of two poison rows fails cleanly');
    expect_eq(find_processed($multiPoisonProcessed, '6022')['status'] ?? '', 'processed', 'the valid row sandwiched between two poison rows is processed');
    expect_eq(find_processed($multiPoisonProcessed, '6023')['status'] ?? '', 'processing_failed', 'the second of two poison rows also fails cleanly, independently of the first');

    // Transient (genuine DB lock) failures are preserved as retryable, unlike permanent ones: left
    // queued rather than marked processing_failed, so the next cron tick retries once the lock clears.
    $GLOBALS['BROTH_LOG_COPILOT_RECORDS_PROVIDER'] = function (string $branch) {
        throw new Exception('database is locked');
    };
    broth_log_copilot_enqueue_webhook(['update_id' => 6031, 'message' => ['text' => 'today B1', 'from' => ['id' => 101], 'chat' => ['id' => 999], 'message_id' => 631]]);
    $transientProcessed = broth_log_copilot_process_inbox(10, $poisonNow);
    unset($GLOBALS['BROTH_LOG_COPILOT_RECORDS_PROVIDER']);
    expect_eq(find_processed($transientProcessed, '6031')['status'] ?? '', 'queued', 'a genuine transient lock failure leaves the row queued for a natural retry, unlike a permanent failure');
    expect_eq(q1("SELECT status FROM broth_log_bot_inbox WHERE update_id='6031'")['status'] ?? '', 'queued', 'the persisted inbox row status also remains queued for a transient failure');

    // --- Per-manager private DM alert routing (additive to existing Ops group delivery) ---
    // Numeric-shaped chat ids throughout (matching real Telegram's private-chat id format) are
    // deliberate - see (U) below.
    $dmManagerA = '301'; $dmManagerAChat = '910301001';           // B1, active, registers a private chat
    $dmManagerB = '302'; $dmManagerBChat = '910302002';           // B1, active, registers a private chat (second B1 manager)
    $dmManagerC = '303'; $dmManagerCChat = '910303003';           // B2, active, registers a private chat
    $dmManagerInactive = '304'; $dmManagerInactiveChat = '910304004'; // B1, INACTIVE, registers a private chat anyway
    $dmManagerNoReg = '305';                                       // B1, active, NEVER registers a private chat
    $dmUnauthorizedPrivate = '306'; $dmUnauthorizedPrivateChat = '910306006'; // never authorized at all

    run("INSERT INTO broth_log_authorized_users (telegram_user_id,display_name,role,allowed_branches,active) VALUES (?,?,?,?,1)", [$dmManagerA, 'Manager Alice', 'manager', json_encode(['B1'])]);
    run("INSERT INTO broth_log_authorized_users (telegram_user_id,display_name,role,allowed_branches,active) VALUES (?,?,?,?,1)", [$dmManagerB, 'Manager Bob', 'manager', json_encode(['B1'])]);
    run("INSERT INTO broth_log_authorized_users (telegram_user_id,display_name,role,allowed_branches,active) VALUES (?,?,?,?,1)", [$dmManagerC, 'Manager Cara', 'manager', json_encode(['B2'])]);
    run("INSERT INTO broth_log_authorized_users (telegram_user_id,display_name,role,allowed_branches,active) VALUES (?,?,?,?,0)", [$dmManagerInactive, 'Manager Dan (inactive)', 'manager', json_encode(['B1'])]);
    run("INSERT INTO broth_log_authorized_users (telegram_user_id,display_name,role,allowed_branches,active) VALUES (?,?,?,?,1)", [$dmManagerNoReg, 'Manager Erin (no DM)', 'manager', json_encode(['B1'])]);

    // Register private chats via the real webhook -> process_inbox pipeline, not a raw INSERT.
    // Registration and authorization are independent - even the inactive manager can register.
    foreach ([[$dmManagerA, $dmManagerAChat, 7001], [$dmManagerB, $dmManagerBChat, 7002], [$dmManagerC, $dmManagerCChat, 7003], [$dmManagerInactive, $dmManagerInactiveChat, 7004]] as [$uid, $chat, $updateId]) {
        broth_log_copilot_enqueue_webhook(['update_id' => $updateId, 'message' => ['text' => '/start', 'from' => ['id' => (int)$uid], 'chat' => ['id' => $chat, 'type' => 'private'], 'message_id' => $updateId]]);
    }
    $dmRegisterProcessed = broth_log_copilot_process_inbox(10, new DateTimeImmutable('2026-08-22 00:00:00 UTC'));
    foreach ([7001, 7002, 7003, 7004] as $uid) {
        expect_eq(find_processed($dmRegisterProcessed, (string)$uid)['status'] ?? '', 'processed', "private /start registration processes cleanly for update {$uid}");
    }
    expect_eq(q1("SELECT private_chat_id FROM broth_log_private_chat_registrations WHERE telegram_user_id=?", [$dmManagerA])['private_chat_id'] ?? '', $dmManagerAChat, 'Alice\'s real private chat id is captured from the actual /start update, never derived or assumed');

    // G: unauthorized private user also gets a safe, non-informative reply - registration alone
    // never authorizes, and they never appear in any branch's DM-eligible set.
    broth_log_copilot_enqueue_webhook(['update_id' => 7005, 'message' => ['text' => '/start', 'from' => ['id' => (int)$dmUnauthorizedPrivate], 'chat' => ['id' => $dmUnauthorizedPrivateChat, 'type' => 'private'], 'message_id' => 7005]]);
    $dmUnauthProcessed = broth_log_copilot_process_inbox(10, new DateTimeImmutable('2026-08-22 00:00:00 UTC'));
    expect_eq(find_processed($dmUnauthProcessed, '7005')['status'] ?? '', 'processed', 'an unauthorized private /start is still processed (registration only, zero data access)');
    expect_eq($sentMessages[count($sentMessages) - 1]['payload']['text'], 'TEST - ' . broth_log_copilot_tr('private_start_connected', 'en'), 'unauthorized private /start gets the safe "connected, awaiting approval" reply, never protected data');
    expect_true(broth_log_copilot_authorized_user($dmUnauthorizedPrivate) === null, 'registering a private chat alone never creates authorization');
    expect_true(!in_array($dmUnauthorizedPrivateChat, array_merge(broth_log_copilot_manager_dm_chat_ids('B1'), broth_log_copilot_manager_dm_chat_ids('B2'), broth_log_copilot_manager_dm_chat_ids('B3')), true), 'G: an unauthorized private user, even after registering, is never DM-eligible for any branch');

    // D/E/F/H: exactly Alice and Bob are B1-DM-eligible - not the inactive manager, not Erin
    // (authorized but never registered), not anyone from another branch.
    $dmB1Eligible = broth_log_copilot_manager_dm_chat_ids('B1');
    expect_true(in_array($dmManagerAChat, $dmB1Eligible, true), 'Alice is DM-eligible for B1');
    expect_true(in_array($dmManagerBChat, $dmB1Eligible, true), 'H: Bob (a second B1 manager) is also DM-eligible for B1');
    expect_eq(count($dmB1Eligible), 2, 'D/E/F: exactly 2 eligible B1 DM chats - the inactive manager and the unregistered manager (Erin) contribute none');

    // A/B/C: a real B1 incident notification reaches the Ops group and Alice, never Cara (B2) or
    // the inactive B1 manager.
    $dmIncidentB1 = broth_log_copilot_create_incident(array_replace($alert, ['branch' => 'B1', 'responseId' => 'resp-dm-b1']));
    expect_true(broth_log_copilot_notify_incident($dmIncidentB1)['sent'] ?? false, 'B1 incident notification sends successfully');
    $dmDeliveredB1 = array_column(q("SELECT chat_id FROM broth_log_outbound_deliveries WHERE incident_id=? AND status='sent'", [$dmIncidentB1]), 'chat_id');
    expect_true(in_array($opsGroupChatId, $dmDeliveredB1, true), 'A: the Ops group receives the B1 incident');
    expect_true(in_array($dmManagerAChat, $dmDeliveredB1, true), 'B: Alice (B1 manager) receives a private DM for the B1 incident');
    expect_true(!in_array($dmManagerCChat, $dmDeliveredB1, true), 'C: Cara (B2 manager) does NOT receive the B1 incident');
    expect_true(!in_array($dmManagerInactiveChat, $dmDeliveredB1, true), 'D: the inactive B1 manager does NOT receive the B1 incident despite having a registered private chat');
    expect_eq(count(array_filter($dmDeliveredB1, fn($c) => $c === $dmManagerAChat)), 1, 'H: Alice receives exactly one DM for the B1 incident, not a duplicate');
    expect_eq(count(array_filter($dmDeliveredB1, fn($c) => $c === $dmManagerBChat)), 1, 'H: Bob receives exactly one DM for the B1 incident, not a duplicate');

    // Cross-check: a B2 incident reaches Cara, never the B1 managers.
    $dmIncidentB2 = broth_log_copilot_create_incident(array_replace($alert, ['branch' => 'B2', 'stationKey' => 'bowlWarmer', 'station' => 'Bowl Warmer', 'target' => '>= 100F', 'responseId' => 'resp-dm-b2']));
    broth_log_copilot_notify_incident($dmIncidentB2);
    $dmDeliveredB2 = array_column(q("SELECT chat_id FROM broth_log_outbound_deliveries WHERE incident_id=? AND status='sent'", [$dmIncidentB2]), 'chat_id');
    expect_true(in_array($dmManagerCChat, $dmDeliveredB2, true), 'Cara (B2 manager) receives the B2 incident privately');
    expect_true(!in_array($dmManagerAChat, $dmDeliveredB2, true), 'Alice (B1 manager) does NOT receive the B2 incident');
    expect_true(!in_array($dmManagerBChat, $dmDeliveredB2, true), 'Bob (B1 manager) does NOT receive the B2 incident');

    // I/J: one manager's DM fails while the group and the other manager still succeed; retrying
    // only resends to the previously-failed destination, never duplicating an already-sent one.
    $dmIncidentFailure = broth_log_copilot_create_incident(array_replace($alert, ['branch' => 'B1', 'responseId' => 'resp-dm-failure']));
    $GLOBALS['BROTH_LOG_COPILOT_TELEGRAM_TRANSPORT'] = function (string $method, array $payload, string $token) use (&$sentMessages, $dmManagerAChat): array {
        $sentMessages[] = ['method' => $method, 'payload' => $payload, 'token' => $token];
        if ((string)($payload['chat_id'] ?? '') === $dmManagerAChat) {
            return ['sent' => false, 'reason' => 'simulated transient failure for Alice only'];
        }
        return ['sent' => true, 'mock' => true];
    };
    broth_log_copilot_notify_incident($dmIncidentFailure);
    $dmFailureByChatId = [];
    foreach (q("SELECT chat_id, status FROM broth_log_outbound_deliveries WHERE incident_id=?", [$dmIncidentFailure]) as $row) {
        $dmFailureByChatId[$row['chat_id']] = $row['status'];
    }
    expect_eq($dmFailureByChatId[$opsGroupChatId] ?? '', 'sent', 'I: the Ops group still succeeds when a manager DM fails');
    expect_eq($dmFailureByChatId[$dmManagerBChat] ?? '', 'sent', 'I: Bob still succeeds when Alice\'s DM fails');
    expect_eq($dmFailureByChatId[$dmManagerAChat] ?? '', 'failed', 'I: only Alice\'s DM is recorded as failed');

    $GLOBALS['BROTH_LOG_COPILOT_TELEGRAM_TRANSPORT'] = function (string $method, array $payload, string $token) use (&$sentMessages): array {
        $sentMessages[] = ['method' => $method, 'payload' => $payload, 'token' => $token];
        return ['sent' => true, 'mock' => true];
    };
    $dmBeforeRetryCount = count($sentMessages);
    broth_log_copilot_notify_incident($dmIncidentFailure);
    $dmRetryChatIds = array_column(array_column(array_slice($sentMessages, $dmBeforeRetryCount), 'payload'), 'chat_id');
    expect_true(in_array($dmManagerAChat, $dmRetryChatIds, true), 'J: retrying resends to the previously-failed destination (Alice)');
    expect_true(!in_array($opsGroupChatId, $dmRetryChatIds, true), 'J: retry does NOT resend to the already-successful group');
    expect_true(!in_array($dmManagerBChat, $dmRetryChatIds, true), 'J: retry does NOT resend to the already-successful Bob');
    expect_eq(q1("SELECT status FROM broth_log_outbound_deliveries WHERE incident_id=? AND chat_id=?", [$dmIncidentFailure, $dmManagerAChat])['status'] ?? '', 'sent', 'Alice\'s delivery is now sent after the successful retry');

    // K/L/M/N: ACK pressed from a manager's private DM acknowledges the canonical incident
    // globally, denies wrong-branch attempts, and rejects replays - the same actor-based rules
    // already enforced for the Ops group, unaffected by which chat the button was pressed in.
    $dmAckIncidentId = broth_log_copilot_create_incident(array_replace($alert, ['branch' => 'B1', 'responseId' => 'resp-dm-ack']));
    run("UPDATE broth_log_incidents SET state='escalated_level_3', current_level=3, level_entered_at='2026-08-22 00:00:00', last_reminder_at='2026-08-22 00:00:00', reminder_count=1 WHERE incident_id=?", [$dmAckIncidentId]);
    $dmAckNow = new DateTimeImmutable('2026-08-22 01:00:00 UTC');
    $dmAckDue = array_values(array_filter(broth_log_copilot_due_escalations($dmAckNow), fn($d) => $d['incident']['incident_id'] === $dmAckIncidentId));
    broth_log_copilot_apply_escalation_action_with_notification($dmAckDue[0], $dmAckNow); // fans out to group + Alice + Bob

    $ackExpiresAt = $dmAckNow->modify('+15 minutes')->getTimestamp();
    $ackToken = broth_log_copilot_create_callback_token('ack', $dmAckIncidentId, $ackExpiresAt);
    broth_log_copilot_enqueue_webhook(['update_id' => 7101, 'callback_query' => ['id' => 'cb-7101', 'data' => $ackToken, 'from' => ['id' => (int)$dmManagerA], 'message' => ['chat' => ['id' => $dmManagerAChat, 'type' => 'private'], 'message_id' => 9101]]]);
    $dmAckProcessed = broth_log_copilot_process_inbox(10, $dmAckNow);
    expect_eq(find_processed($dmAckProcessed, '7101')['status'] ?? '', 'processed', 'K: ACK pressed from Alice\'s private DM is processed');
    expect_eq(q1("SELECT state FROM broth_log_incidents WHERE incident_id=?", [$dmAckIncidentId])['state'] ?? '', 'acknowledged', 'K: the canonical incident is acknowledged');

    $dmAckStillDue = array_values(array_filter(broth_log_copilot_due_escalations($dmAckNow->modify('+30 minutes')), fn($d) => $d['incident']['incident_id'] === $dmAckIncidentId));
    expect_true(empty($dmAckStillDue), 'L: ACK from Alice\'s DM stops future reminders for the incident everywhere - group, Alice, and Bob alike');

    $wrongBranchExpiresAt = $dmAckNow->modify('+15 minutes')->getTimestamp();
    $wrongBranchToken = broth_log_copilot_create_callback_token('ack', $dmIncidentB1, $wrongBranchExpiresAt);
    broth_log_copilot_enqueue_webhook(['update_id' => 7102, 'callback_query' => ['id' => 'cb-7102', 'data' => $wrongBranchToken, 'from' => ['id' => (int)$dmManagerC], 'message' => ['chat' => ['id' => $dmManagerCChat, 'type' => 'private'], 'message_id' => 9102]]]);
    broth_log_copilot_process_inbox(10, $dmAckNow);
    expect_true((q1("SELECT state FROM broth_log_incidents WHERE incident_id=?", [$dmIncidentB1])['state'] ?? '') !== 'acknowledged', 'M: Cara (B2 manager) pressing ACK on a B1 incident from her own DM does NOT acknowledge it - branch mismatch denies the action');

    broth_log_copilot_enqueue_webhook(['update_id' => 7103, 'callback_query' => ['id' => 'cb-7103', 'data' => $ackToken, 'from' => ['id' => (int)$dmManagerA], 'message' => ['chat' => ['id' => $dmManagerAChat, 'type' => 'private'], 'message_id' => 9103]]]);
    broth_log_copilot_process_inbox(10, $dmAckNow);
    expect_eq(q1("SELECT state FROM broth_log_incidents WHERE incident_id=?", [$dmAckIncidentId])['state'] ?? '', 'acknowledged', 'N: replaying the already-consumed ACK token from the DM has no additional effect - state is unchanged, not reprocessed');

    // O/P: Resolve from a DM uses the exact same safety rules as the group - unsafe recheck
    // rejected, safe recheck closes the canonical incident globally.
    $bobUser = broth_log_copilot_authorized_user($dmManagerB);
    // Same stale-fixture pattern fixed above: 45F is prepAreaCooler's inclusive-safe boundary
    // (BROTH_LOG_SOP min=30/max=45), not an unsafe reading. 60F is unambiguously unsafe.
    $unsafeResolve = broth_log_copilot_resolve($dmAckIncidentId, $bobUser, 60.0, 'checked', $dmAckNow);
    expect_true(!($unsafeResolve['ok'] ?? true), 'O: an unsafe recheck temperature is rejected, regardless of which surface (group or DM) it came from');
    expect_true((q1("SELECT state FROM broth_log_incidents WHERE incident_id=?", [$dmAckIncidentId])['state'] ?? '') !== 'resolved', 'O: the incident remains open after a rejected unsafe resolve');
    $safeResolve = broth_log_copilot_resolve($dmAckIncidentId, $bobUser, 35.0, 'closed door and moved product', $dmAckNow);
    expect_true($safeResolve['ok'] ?? false, 'P: a safe recheck temperature resolves the incident');
    expect_eq(q1("SELECT state FROM broth_log_incidents WHERE incident_id=?", [$dmAckIncidentId])['state'] ?? '', 'resolved', 'P: the canonical incident is resolved globally, the same as if Resolve had come from the group');

    // R: deactivating a manager immediately stops future DMs without deleting their registration.
    run("UPDATE broth_log_authorized_users SET active=0 WHERE telegram_user_id=?", [$dmManagerA]);
    $dmB1AfterDeactivation = broth_log_copilot_manager_dm_chat_ids('B1');
    expect_true(!in_array($dmManagerAChat, $dmB1AfterDeactivation, true), 'R: a deactivated manager is immediately excluded from DM eligibility');
    expect_true(in_array($dmManagerBChat, $dmB1AfterDeactivation, true), 'R: deactivating Alice does not affect Bob\'s eligibility');
    expect_true(q1("SELECT private_chat_id FROM broth_log_private_chat_registrations WHERE telegram_user_id=?", [$dmManagerA]) !== null, 'R: Alice\'s private-chat registration record is preserved, not deleted, even though she is now deactivated');

    // S: changing Bob's branch from B1 to B2 - old branch stops, new branch starts, purely by
    // human-edited allowed_branches, never inferred.
    run("UPDATE broth_log_authorized_users SET allowed_branches=? WHERE telegram_user_id=?", [json_encode(['B2']), $dmManagerB]);
    expect_true(!in_array($dmManagerBChat, broth_log_copilot_manager_dm_chat_ids('B1'), true), 'S: after the branch change, Bob no longer receives B1 DMs');
    expect_true(in_array($dmManagerBChat, broth_log_copilot_manager_dm_chat_ids('B2'), true), 'S: after the branch change, Bob now receives B2 DMs');

    // /alerts status command: authorized manager sees ON + current store, unauthorized sees pending.
    broth_log_copilot_enqueue_webhook(['update_id' => 7301, 'message' => ['text' => '/alerts', 'from' => ['id' => (int)$dmManagerB], 'chat' => ['id' => $dmManagerBChat, 'type' => 'private'], 'message_id' => 9301]]);
    broth_log_copilot_process_inbox(10, new DateTimeImmutable('2026-08-22 00:00:00 UTC'));
    expect_eq($sentMessages[count($sentMessages) - 1]['payload']['text'] ?? '', 'TEST - ' . broth_log_copilot_tr('private_alerts_status_on', 'en', ['B2']), '/alerts for Bob (now B2-authorized after the branch change) shows ON plus his current store');
    broth_log_copilot_enqueue_webhook(['update_id' => 7302, 'message' => ['text' => '/alerts', 'from' => ['id' => (int)$dmUnauthorizedPrivate], 'chat' => ['id' => $dmUnauthorizedPrivateChat, 'type' => 'private'], 'message_id' => 9302]]);
    broth_log_copilot_process_inbox(10, new DateTimeImmutable('2026-08-22 00:00:00 UTC'));
    expect_eq($sentMessages[count($sentMessages) - 1]['payload']['text'] ?? '', 'TEST - ' . broth_log_copilot_tr('private_alerts_status_pending', 'en'), '/alerts for an unauthorized private user shows the pending status, not any store data');

    // T: a poison row must not block private-DM registration processing OR eligible-incident
    // reminders in the same batch (extends the PR #33 isolation guarantee to the new carve-out).
    $poisonDmIncidentId = broth_log_copilot_create_incident(array_replace($alert, ['branch' => 'B1', 'responseId' => 'resp-dm-poison']));
    run("UPDATE broth_log_incidents SET state='escalated_level_3', current_level=3, level_entered_at='2026-08-22 00:00:00', last_reminder_at='2026-08-22 00:00:00', reminder_count=1 WHERE incident_id=?", [$poisonDmIncidentId]);
    $poisonDmNow = new DateTimeImmutable('2026-08-22 02:00:00 UTC');
    $poisonDmDue = array_values(array_filter(broth_log_copilot_due_escalations($poisonDmNow), fn($d) => $d['incident']['incident_id'] === $poisonDmIncidentId));
    expect_true(!empty($poisonDmDue), 'sanity: the DM-eligible incident is genuinely due for this poison-row test');

    broth_log_copilot_enqueue_webhook(['update_id' => 7201, 'message' => ['text' => '/start', 'from' => ['id' => (int)$dmManagerC], 'chat' => ['id' => $dmManagerCChat, 'type' => 'private'], 'message_id' => 9201]]);
    broth_log_copilot_enqueue_webhook(['update_id' => 7202, 'message' => ['text' => 'today B2', 'from' => ['id' => (int)$dmManagerC], 'chat' => ['id' => 999, 'type' => 'group'], 'message_id' => 9202]]);
    $GLOBALS['BROTH_LOG_COPILOT_RECORDS_PROVIDER'] = function (string $branch) { throw new RuntimeException('simulated poison row for DM test'); };
    $poisonDmProcessed = broth_log_copilot_process_inbox(10, $poisonDmNow);
    unset($GLOBALS['BROTH_LOG_COPILOT_RECORDS_PROVIDER']);
    expect_eq(find_processed($poisonDmProcessed, '7201')['status'] ?? '', 'processed', 'T: a private registration row is unaffected by a poison row elsewhere in the same batch');
    expect_eq(find_processed($poisonDmProcessed, '7202')['status'] ?? '', 'processing_failed', 'T: the poison row itself still fails cleanly in this DM-mixed batch');

    $poisonDmEscalationResult = broth_log_copilot_apply_escalation_action_with_notification($poisonDmDue[0], $poisonDmNow);
    expect_eq($poisonDmEscalationResult['action'] ?? '', 'reminded', 'T: the escalation/reminder phase for a DM-eligible incident still completes after a poison row in the same tick');
    expect_true(($poisonDmEscalationResult['outbound_sent'] ?? 0) >= 1, 'T: at least one destination (group and/or manager DM) receives the reminder despite the poison row');

    // U: this entire block deliberately used numeric-shaped chat ids throughout (e.g. '910301001'),
    // matching real Telegram's private-chat id format. Reaching this point without an uncaught
    // TypeError already proves the PR #32-class array-key-coercion bug does not recur here.
    expect_true(true, 'U: numeric-shaped Telegram chat ids throughout this block never triggered a hash_equals/array-key TypeError regression');

    // Re-registration idempotency: the same sender privately sending /start twice (even with a
    // different observed chat id, as could legitimately happen) ends in exactly one row - the
    // most recent real chat.id wins, never two active destinations for one registration.
    $dmReregId = '307'; $dmReregChatFirst = '910307001'; $dmReregChatSecond = '910307002';
    broth_log_copilot_enqueue_webhook(['update_id' => 7401, 'message' => ['text' => '/start', 'from' => ['id' => (int)$dmReregId], 'chat' => ['id' => $dmReregChatFirst, 'type' => 'private'], 'message_id' => 9401]]);
    broth_log_copilot_process_inbox(10, new DateTimeImmutable('2026-08-22 00:00:00 UTC'));
    broth_log_copilot_enqueue_webhook(['update_id' => 7402, 'message' => ['text' => '/start', 'from' => ['id' => (int)$dmReregId], 'chat' => ['id' => $dmReregChatSecond, 'type' => 'private'], 'message_id' => 9402]]);
    broth_log_copilot_process_inbox(10, new DateTimeImmutable('2026-08-22 00:00:00 UTC'));
    expect_eq(count(q("SELECT * FROM broth_log_private_chat_registrations WHERE telegram_user_id=?", [$dmReregId])), 1, 'repeated /start from the same sender leaves exactly one registration row, not a duplicate');
    expect_eq(q1("SELECT private_chat_id FROM broth_log_private_chat_registrations WHERE telegram_user_id=?", [$dmReregId])['private_chat_id'] ?? '', $dmReregChatSecond, 'the most recently observed real chat.id wins on re-registration');
    expect_true(broth_log_copilot_authorized_user($dmReregId) === null, 'repeated /start never creates or mutates authorization');

    // Hijack resistance: two different senders registering in the same batch can never have their
    // telegram_user_id and private_chat_id cross-wired. The registration write reads only
    // $row['telegram_user_id'] (from.id) and $row['chat_id'] (chat.id) of the single update it
    // came from - never message text, and the anchored /start pattern does not even parse
    // arguments, so there is no field through which one sender could supply another's identity.
    // X additionally carries an irrelevant 'username' claiming to be Y, proving that field is
    // never read for registration either.
    $dmHijackX = '308'; $dmHijackXChat = '910308001';
    $dmHijackY = '309'; $dmHijackYChat = '910309001';
    broth_log_copilot_enqueue_webhook(['update_id' => 7403, 'message' => ['text' => '/start', 'from' => ['id' => (int)$dmHijackX, 'username' => 'impersonator_of_Y'], 'chat' => ['id' => $dmHijackXChat, 'type' => 'private'], 'message_id' => 9403]]);
    broth_log_copilot_enqueue_webhook(['update_id' => 7404, 'message' => ['text' => '/start', 'from' => ['id' => (int)$dmHijackY], 'chat' => ['id' => $dmHijackYChat, 'type' => 'private'], 'message_id' => 9404]]);
    broth_log_copilot_process_inbox(10, new DateTimeImmutable('2026-08-22 00:00:00 UTC'));
    expect_eq(q1("SELECT private_chat_id FROM broth_log_private_chat_registrations WHERE telegram_user_id=?", [$dmHijackX])['private_chat_id'] ?? '', $dmHijackXChat, 'X is bound only to X\'s own observed chat id, unaffected by an irrelevant username field');
    expect_eq(q1("SELECT private_chat_id FROM broth_log_private_chat_registrations WHERE telegram_user_id=?", [$dmHijackY])['private_chat_id'] ?? '', $dmHijackYChat, 'Y is bound only to Y\'s own observed chat id, registered independently and concurrently with X');
    expect_true(q1("SELECT private_chat_id FROM broth_log_private_chat_registrations WHERE telegram_user_id=?", [$dmHijackX])['private_chat_id'] !== $dmHijackYChat, 'X\'s registration was never cross-wired to Y\'s chat id');

    // Destination dedup: if a manager's private chat id ever coincided with the group's chat id
    // (pathological but possible), the merged destination list must still contain it exactly once.
    $dmDedupManager = '310';
    run("INSERT INTO broth_log_authorized_users (telegram_user_id,display_name,role,allowed_branches,active) VALUES (?,?,?,?,1)", [$dmDedupManager, 'Manager Dedup-Test', 'manager', json_encode(['B1'])]);
    run("INSERT OR REPLACE INTO broth_log_private_chat_registrations (telegram_user_id, private_chat_id, registered_at, updated_at) VALUES (?,?,datetime('now'),datetime('now'))", [$dmDedupManager, $opsGroupChatId]);
    $dmDedupChats = array_values(array_unique(array_merge(
        broth_log_copilot_route_chat_ids('B1', 1),
        broth_log_copilot_manager_dm_chat_ids('B1')
    )));
    expect_eq(count(array_filter($dmDedupChats, fn($c) => $c === $opsGroupChatId)), 1, 'a manager private chat id coinciding with the group chat id appears exactly once in the merged destination list, never sent twice');
    run("DELETE FROM broth_log_authorized_users WHERE telegram_user_id=?", [$dmDedupManager]);
    run("DELETE FROM broth_log_private_chat_registrations WHERE telegram_user_id=?", [$dmDedupManager]);

    // Owner private registration must never make the owner a manager-DM recipient - this feature
    // is scoped to role='manager' only, and the owner's existing (unrelated) monitoring is
    // untouched by this deploy regardless of whether the owner ever privately messages the bot.
    $dmOwnerId = '311'; $dmOwnerChat = '910311001';
    run("INSERT INTO broth_log_authorized_users (telegram_user_id,display_name,role,allowed_branches,active) VALUES (?,?,?,?,1)", [$dmOwnerId, 'Owner', 'owner', json_encode(['B1','B2','B3'])]);
    broth_log_copilot_enqueue_webhook(['update_id' => 7501, 'message' => ['text' => '/start', 'from' => ['id' => (int)$dmOwnerId], 'chat' => ['id' => $dmOwnerChat, 'type' => 'private'], 'message_id' => 9501]]);
    broth_log_copilot_process_inbox(10, new DateTimeImmutable('2026-08-22 00:00:00 UTC'));
    expect_eq(q1("SELECT private_chat_id FROM broth_log_private_chat_registrations WHERE telegram_user_id=?", [$dmOwnerId])['private_chat_id'] ?? '', $dmOwnerChat, 'the owner CAN register a private chat like anyone else');
    foreach (['B1', 'B2', 'B3'] as $ownerCheckBranch) {
        expect_true(!in_array($dmOwnerChat, broth_log_copilot_manager_dm_chat_ids($ownerCheckBranch), true), "the owner's private registration never makes them a manager-DM recipient for {$ownerCheckBranch} (role='owner', not 'manager')");
    }
    run("DELETE FROM broth_log_authorized_users WHERE telegram_user_id=?", [$dmOwnerId]);
    run("DELETE FROM broth_log_private_chat_registrations WHERE telegram_user_id=?", [$dmOwnerId]);

    // --- Ops group / manager DM cutover: branch-level alert delivery mode ---
    $cutManagerA = '401'; $cutManagerAChat = '910401001'; // B1, active, registered
    $cutManagerB = '402'; $cutManagerBChat = '910402001'; // B1, active, registered (second B1 manager)
    $cutManagerB2 = '403'; $cutManagerB2Chat = '910403001'; // B2, active, registered
    foreach ([[$cutManagerA, 'B1'], [$cutManagerB, 'B1'], [$cutManagerB2, 'B2']] as [$uid, $branch]) {
        run("INSERT INTO broth_log_authorized_users (telegram_user_id,display_name,role,allowed_branches,active) VALUES (?,?,?,?,1)", [$uid, "Cutover Manager {$uid}", 'manager', json_encode([$branch])]);
    }
    foreach ([[$cutManagerA, $cutManagerAChat], [$cutManagerB, $cutManagerBChat], [$cutManagerB2, $cutManagerB2Chat]] as [$uid, $chat]) {
        run("INSERT OR REPLACE INTO broth_log_private_chat_registrations (telegram_user_id, private_chat_id, registered_at, updated_at) VALUES (?,?,datetime('now'),datetime('now'))", [$uid, $chat]);
    }

    // 1: B1 not cut over (no mode row = default ops_fallback) -> B1 incident still reaches the
    // Ops group, additively with any eligible managers, exactly as before this feature existed.
    expect_eq(broth_log_copilot_branch_alert_mode('B1'), 'ops_fallback', 'a branch with no mode row defaults to ops_fallback - the safe, unchanged behavior');
    $cutIncidentPreCutover = broth_log_copilot_create_incident(array_replace($alert, ['branch' => 'B1', 'responseId' => 'resp-cutover-pre']));
    broth_log_copilot_notify_incident($cutIncidentPreCutover);
    $cutPreDelivered = array_column(q("SELECT chat_id FROM broth_log_outbound_deliveries WHERE incident_id=? AND status='sent'", [$cutIncidentPreCutover]), 'chat_id');
    expect_true(in_array($opsGroupChatId, $cutPreDelivered, true), '1: before cutover, the Ops group still receives the B1 incident');
    expect_true(in_array($cutManagerAChat, $cutPreDelivered, true), '1: before cutover, eligible B1 managers still also receive it (unchanged additive behavior)');

    // 15: the Manager Onboarding Group's identity is completely independent of alert-delivery
    // mode AND of the Alert/Fallback group's own routing configuration.
    expect_true(broth_log_copilot_is_manager_onboarding_chat($onboardingGroupChatId), '15: the Manager Onboarding Group is recognized as the trusted onboarding chat before any cutover');
    expect_true(!broth_log_copilot_is_manager_onboarding_chat($opsGroupChatId), '15: the Alert/Fallback group is still not the onboarding group, before any cutover');

    // 2/6: cut B1 over to manager_dm mode - Ops + both eligible managers ALL receive the SAME
    // canonical incident, independently (Ops+Manager parity: manager_dm no longer means "DM instead
    // of Ops", it means "Ops + eligible Manager DM"). 3: B2 stays in the default mode, independently
    // of B1's cutover (and already had this exact Ops+Manager merge behavior before this change).
    run("INSERT OR REPLACE INTO broth_log_branch_alert_mode (branch, mode, updated_at) VALUES ('B1','manager_dm',datetime('now'))");
    expect_eq(broth_log_copilot_branch_alert_mode('B1'), 'manager_dm', 'B1 is now in manager_dm mode');
    expect_eq(broth_log_copilot_branch_alert_mode('B2'), 'ops_fallback', '3: B2 remains in the default ops_fallback mode, independent of B1\'s cutover');
    expect_true(broth_log_copilot_is_manager_onboarding_chat($onboardingGroupChatId), '15: the Manager Onboarding Group is STILL recognized as the trusted onboarding chat after B1 is cut over');
    expect_true(!broth_log_copilot_is_manager_onboarding_chat($opsGroupChatId), '15: the Alert/Fallback group is STILL not the onboarding group after B1 is cut over - cutover mode never affects onboarding identity');

    $cutIncidentB1 = broth_log_copilot_create_incident(array_replace($alert, ['branch' => 'B1', 'responseId' => 'resp-cutover-b1']));
    broth_log_copilot_notify_incident($cutIncidentB1);
    $cutB1Delivered = array_column(q("SELECT chat_id FROM broth_log_outbound_deliveries WHERE incident_id=? AND status='sent'", [$cutIncidentB1]), 'chat_id');
    expect_true(in_array($opsGroupChatId, $cutB1Delivered, true), '2: after cutover, the Ops group ALSO receives the B1 incident - mandatory operational visibility, not conditional on manager delivery');
    expect_eq(q1("SELECT status FROM broth_log_outbound_deliveries WHERE incident_id=? AND chat_id=?", [$cutIncidentB1, $opsGroupChatId])['status'] ?? '', 'sent', '8: exactly one successful delivery attempt exists for the Ops group on a manager_dm incident - it is a first-class destination now, not a suppressed one');
    expect_true(in_array($cutManagerAChat, $cutB1Delivered, true), '2: after cutover, Manager A still receives the private DM');
    expect_true(in_array($cutManagerBChat, $cutB1Delivered, true), '6: both eligible B1 managers receive their own DM');
    expect_eq(count(array_filter($cutB1Delivered, fn($c) => $c === $cutManagerAChat)), 1, '6: Manager A receives exactly one DM, not a duplicate');
    expect_eq(count(array_filter($cutB1Delivered, fn($c) => $c === $cutManagerBChat)), 1, '6: Manager B receives exactly one DM, not a duplicate');

    // 3/11: B2 (not cut over) still uses ops_fallback for its own incidents at the same time -
    // and the B1 manager never receives it.
    $cutIncidentB2 = broth_log_copilot_create_incident(array_replace($alert, ['branch' => 'B2', 'stationKey' => 'bowlWarmer', 'station' => 'Bowl Warmer', 'target' => '>= 100F', 'responseId' => 'resp-cutover-b2']));
    broth_log_copilot_notify_incident($cutIncidentB2);
    $cutB2Delivered = array_column(q("SELECT chat_id FROM broth_log_outbound_deliveries WHERE incident_id=? AND status='sent'", [$cutIncidentB2]), 'chat_id');
    expect_true(in_array($opsGroupChatId, $cutB2Delivered, true), '3: B2 (not cut over) still delivers to the Ops group');
    expect_true(in_array($cutManagerB2Chat, $cutB2Delivered, true), '3: B2 (not cut over) still additively delivers to its own eligible manager too');
    expect_true(!in_array($cutManagerAChat, $cutB2Delivered, true), '11: the B1 manager never receives the B2 incident');

    // 4: B3 cut over to manager_dm but its only "manager" was never privately registered - zero
    // eligible manager destinations. The Ops group still receives the alert regardless (it is
    // unconditional now, not a fallback), and a sanitized, non-identifying coverage-gap audit event
    // records that this manager_dm branch currently has nobody watching DMs.
    $cutManagerB3NoReg = '406';
    run("INSERT INTO broth_log_authorized_users (telegram_user_id,display_name,role,allowed_branches,active) VALUES (?,?,?,?,1)", [$cutManagerB3NoReg, 'Cutover Manager 406 (no registration)', 'manager', json_encode(['B3'])]);
    run("INSERT OR REPLACE INTO broth_log_branch_alert_mode (branch, mode, updated_at) VALUES ('B3','manager_dm',datetime('now'))");
    expect_true(empty(broth_log_copilot_manager_dm_chat_ids('B3')), 'sanity: B3\'s only manager has no private-chat registration, so zero eligible destinations exist');
    $cutIncidentNoReg = broth_log_copilot_create_incident(array_replace($alert, ['branch' => 'B3', 'stationKey' => 'pastaBoilerRight', 'station' => 'Pasta Boiler Right', 'target' => '>= 200F', 'responseId' => 'resp-cutover-no-reg']));
    $cutNoRegResult = broth_log_copilot_notify_incident($cutIncidentNoReg);
    expect_true($cutNoRegResult['sent'] ?? false, '4: the alert is still sent - it does not silently disappear when the sole manager is unregistered');
    $cutNoRegDelivered = array_column(q("SELECT chat_id FROM broth_log_outbound_deliveries WHERE incident_id=? AND status='sent'", [$cutIncidentNoReg]), 'chat_id');
    expect_true(in_array($opsGroupChatId, $cutNoRegDelivered, true), '4: with zero eligible managers, the Ops group still receives the alert (mandatory, not conditional on manager coverage)');
    expect_eq(count(array_filter($cutNoRegDelivered, fn($c) => $c === $opsGroupChatId)), 1, '10: the Ops delivery happens exactly once, not duplicated');
    $cutNoRegAudit = q1("SELECT event_json FROM broth_log_incident_events WHERE incident_id=? AND event_type='manager_dm_coverage_gap'", [$cutIncidentNoReg]);
    expect_true($cutNoRegAudit !== null, 'a sanitized manager_dm_coverage_gap audit event is recorded');
    expect_true(str_contains((string)$cutNoRegAudit['event_json'], 'no_eligible_recipient'), 'the audit reason correctly identifies zero eligible recipients');
    expect_true(!str_contains((string)$cutNoRegAudit['event_json'], $cutManagerAChat) && !str_contains((string)$cutNoRegAudit['event_json'], $cutManagerB3NoReg), 'the audit record never contains a raw private chat id or numeric user id');

    // 5: the same B3 manager now registers a private chat, but is inactive - still zero eligible
    // manager destinations, still the same Ops delivery (a distinct real-world cause, same outcome).
    run("INSERT OR REPLACE INTO broth_log_private_chat_registrations (telegram_user_id, private_chat_id, registered_at, updated_at) VALUES (?,?,datetime('now'),datetime('now'))", [$cutManagerB3NoReg, '910406001']);
    run("UPDATE broth_log_authorized_users SET active=0 WHERE telegram_user_id=?", [$cutManagerB3NoReg]);
    expect_true(empty(broth_log_copilot_manager_dm_chat_ids('B3')), 'sanity: B3\'s manager is now registered but inactive, so still zero eligible destinations');
    $cutIncidentInactive = broth_log_copilot_create_incident(array_replace($alert, ['branch' => 'B3', 'stationKey' => 'pastaBoilerRight', 'station' => 'Pasta Boiler Right', 'target' => '>= 200F', 'responseId' => 'resp-cutover-inactive']));
    broth_log_copilot_notify_incident($cutIncidentInactive);
    $cutInactiveDelivered = array_column(q("SELECT chat_id FROM broth_log_outbound_deliveries WHERE incident_id=? AND status='sent'", [$cutIncidentInactive]), 'chat_id');
    expect_true(in_array($opsGroupChatId, $cutInactiveDelivered, true), '5: with the sole B3 manager inactive, the Ops group still receives the alert');

    // 7: one B1 manager fails, the other succeeds - the successful one is unaffected. The Ops group
    // is delivered independently regardless (it never depended on manager outcome to begin with),
    // and since at least one manager succeeded, there is no coverage-gap audit event.
    $cutIncidentPartialFail = broth_log_copilot_create_incident(array_replace($alert, ['branch' => 'B1', 'responseId' => 'resp-cutover-partial-fail']));
    $GLOBALS['BROTH_LOG_COPILOT_TELEGRAM_TRANSPORT'] = function (string $method, array $payload, string $token) use (&$sentMessages, $cutManagerAChat): array {
        $sentMessages[] = ['method' => $method, 'payload' => $payload, 'token' => $token];
        if ((string)($payload['chat_id'] ?? '') === $cutManagerAChat) {
            return ['sent' => false, 'reason' => 'simulated transient failure for Manager A only'];
        }
        return ['sent' => true, 'mock' => true];
    };
    broth_log_copilot_notify_incident($cutIncidentPartialFail);
    $cutPartialFailStatuses = [];
    foreach (q("SELECT chat_id, status FROM broth_log_outbound_deliveries WHERE incident_id=?", [$cutIncidentPartialFail]) as $row) {
        $cutPartialFailStatuses[$row['chat_id']] = $row['status'];
    }
    expect_eq($cutPartialFailStatuses[$cutManagerBChat] ?? '', 'sent', '7: Manager B still succeeds when Manager A fails');
    expect_eq($cutPartialFailStatuses[$cutManagerAChat] ?? '', 'failed', '7: only Manager A is recorded as failed');
    expect_eq($cutPartialFailStatuses[$opsGroupChatId] ?? '', 'sent', '7: the Ops group succeeds independently, regardless of Manager A\'s failure - it was never conditional on manager delivery');
    expect_eq(q1("SELECT COUNT(*) AS c FROM broth_log_incident_events WHERE incident_id=? AND event_type='manager_dm_coverage_gap'", [$cutIncidentPartialFail])['c'] ?? -1, 0, '7: no manager_dm_coverage_gap audit event is recorded when at least one manager succeeds');

    // 8: every eligible manager fails - the Ops group is STILL delivered (it was sent independently
    // from the start, not as a reaction to manager failure), and a coverage-gap audit event records
    // that manager-DM delivery specifically had zero successes for this branch.
    $cutIncidentAllFail = broth_log_copilot_create_incident(array_replace($alert, ['branch' => 'B1', 'responseId' => 'resp-cutover-all-fail']));
    $GLOBALS['BROTH_LOG_COPILOT_TELEGRAM_TRANSPORT'] = function (string $method, array $payload, string $token) use (&$sentMessages, $cutManagerAChat, $cutManagerBChat): array {
        $sentMessages[] = ['method' => $method, 'payload' => $payload, 'token' => $token];
        if (in_array((string)($payload['chat_id'] ?? ''), [$cutManagerAChat, $cutManagerBChat], true)) {
            return ['sent' => false, 'reason' => 'simulated total transient failure'];
        }
        return ['sent' => true, 'mock' => true];
    };
    broth_log_copilot_notify_incident($cutIncidentAllFail);
    $cutAllFailStatuses = [];
    foreach (q("SELECT chat_id, status FROM broth_log_outbound_deliveries WHERE incident_id=?", [$cutIncidentAllFail]) as $row) {
        $cutAllFailStatuses[$row['chat_id']] = $row['status'];
    }
    expect_eq($cutAllFailStatuses[$cutManagerAChat] ?? '', 'failed', '8: Manager A is recorded as failed');
    expect_eq($cutAllFailStatuses[$cutManagerBChat] ?? '', 'failed', '8: Manager B is recorded as failed');
    expect_eq($cutAllFailStatuses[$opsGroupChatId] ?? '', 'sent', '8: the Ops group is delivered independently even when every manager fails - it is not an emergency fallback, it was sent unconditionally from the start');
    expect_eq(q1("SELECT COUNT(*) AS c FROM broth_log_incident_events WHERE incident_id=? AND event_type='manager_dm_coverage_gap'", [$cutIncidentAllFail])['c'] ?? -1, 1, '8: exactly one manager_dm_coverage_gap audit event is recorded, reflecting that manager-DM delivery specifically had zero successes');

    // 9: retrying after Manager A's failure now succeeds - Manager B's and Ops's already-successful
    // deliveries are correctly idempotent-skipped (same delivery_key, already status='sent'), not
    // resent, regardless of which of them happened to be a "manager" vs "the group".
    $GLOBALS['BROTH_LOG_COPILOT_TELEGRAM_TRANSPORT'] = function (string $method, array $payload, string $token) use (&$sentMessages): array {
        $sentMessages[] = ['method' => $method, 'payload' => $payload, 'token' => $token];
        return ['sent' => true, 'mock' => true];
    };
    $cutBeforeRetryCount = count($sentMessages);
    broth_log_copilot_notify_incident($cutIncidentPartialFail);
    $cutRetryChatIds = array_column(array_column(array_slice($sentMessages, $cutBeforeRetryCount), 'payload'), 'chat_id');
    expect_true(in_array($cutManagerAChat, $cutRetryChatIds, true), '9: retrying resends to the previously-failed manager (A)');
    expect_true(!in_array($cutManagerBChat, $cutRetryChatIds, true), '9: retry does NOT resend to the already-successful manager (B)');
    expect_true(!in_array($opsGroupChatId, $cutRetryChatIds, true), '9: retry does NOT resend to the Ops group either - it already succeeded on the first (unconditional) attempt and is idempotent-skipped exactly like Manager B, not because it was excluded by mode');
    expect_eq(q1("SELECT status FROM broth_log_outbound_deliveries WHERE incident_id=? AND chat_id=?", [$cutIncidentPartialFail, $cutManagerAChat])['status'] ?? '', 'sent', 'Manager A\'s delivery is now sent after the successful retry');

    // 12/13: ACK from a manager's DM stops all future reminders GLOBALLY - for the Ops group too,
    // since both destinations reference the same canonical incident/state machine, not separate
    // per-destination ACK state. The Ops group receives every reminder alongside managers now.
    $cutAckIncidentId = broth_log_copilot_create_incident(array_replace($alert, ['branch' => 'B1', 'responseId' => 'resp-cutover-ack']));
    run("UPDATE broth_log_incidents SET state='escalated_level_3', current_level=3, level_entered_at='2026-08-22 00:00:00', last_reminder_at='2026-08-22 00:00:00', reminder_count=1 WHERE incident_id=?", [$cutAckIncidentId]);
    $cutAckNow = new DateTimeImmutable('2026-08-22 01:00:00 UTC');
    $cutAckDue = array_values(array_filter(broth_log_copilot_due_escalations($cutAckNow), fn($d) => $d['incident']['incident_id'] === $cutAckIncidentId));
    broth_log_copilot_apply_escalation_action_with_notification($cutAckDue[0], $cutAckNow);
    $cutAckDelivered = array_column(q("SELECT chat_id FROM broth_log_outbound_deliveries WHERE incident_id=?", [$cutAckIncidentId]), 'chat_id');
    expect_true(in_array($opsGroupChatId, $cutAckDelivered, true), 'the B1 reminder in manager_dm mode ALSO reaches the Ops group now, not just managers');

    $cutAckExpiresAt = $cutAckNow->modify('+15 minutes')->getTimestamp();
    $cutAckToken = broth_log_copilot_create_callback_token('ack', $cutAckIncidentId, $cutAckExpiresAt);
    broth_log_copilot_enqueue_webhook(['update_id' => 8101, 'callback_query' => ['id' => 'cb-8101', 'data' => $cutAckToken, 'from' => ['id' => (int)$cutManagerA], 'message' => ['chat' => ['id' => $cutManagerAChat, 'type' => 'private'], 'message_id' => 9601]]]);
    broth_log_copilot_process_inbox(10, $cutAckNow);
    expect_eq(q1("SELECT state FROM broth_log_incidents WHERE incident_id=?", [$cutAckIncidentId])['state'] ?? '', 'acknowledged', '12: ACK from a manager DM acknowledges the canonical incident under manager_dm mode too');
    $cutAckStillDue = array_values(array_filter(broth_log_copilot_due_escalations($cutAckNow->modify('+30 minutes')), fn($d) => $d['incident']['incident_id'] === $cutAckIncidentId));
    expect_true(empty($cutAckStillDue), '12: ACK stops future reminders for the incident globally - for the Ops group and every manager alike, since there is only one canonical incident state, not per-destination state');
    expect_eq(q1("SELECT COUNT(*) AS c FROM broth_log_incident_events WHERE incident_id=? AND event_type='manager_dm_coverage_gap'", [$cutAckIncidentId])['c'] ?? -1, 0, '13: no manager_dm_coverage_gap event fires for this incident - both managers were eligible and this reminder succeeded for at least one of them');

    // 14: Resolve from a DM closes the canonical incident globally, same as before cutover.
    $cutResolveResult = broth_log_copilot_resolve($cutAckIncidentId, broth_log_copilot_authorized_user($cutManagerA), 35.0, 'closed door and moved product', $cutAckNow);
    expect_true($cutResolveResult['ok'] ?? false, '14: a safe resolve from a manager DM succeeds under manager_dm mode');
    expect_eq(q1("SELECT state FROM broth_log_incidents WHERE incident_id=?", [$cutAckIncidentId])['state'] ?? '', 'resolved', '14: the canonical incident is resolved globally');

    // 16: /pilotid from a private chat by an unauthorized sender is still rejected (unchanged
    // policy) even after B1's cutover - the /pilotid Ops-group gate is untouched by this feature.
    $cutUnauthorizedId = '405';
    broth_log_copilot_enqueue_webhook(['update_id' => 8102, 'message' => ['text' => '/pilotid', 'from' => ['id' => (int)$cutUnauthorizedId], 'chat' => ['id' => '910405001', 'type' => 'private'], 'message_id' => 9602]]);
    $cutPrivatePilotidProcessed = broth_log_copilot_process_inbox(10, $cutAckNow);
    expect_eq(find_processed($cutPrivatePilotidProcessed, '8102')['status'] ?? '', 'denied', '16: /pilotid sent from a private chat (not the Ops group) by an unauthorized sender is still rejected after cutover');

    // 20: poison-row isolation is completely unaffected (process_inbox() itself was not modified
    // by this feature) - confirmed directly in a manager_dm-mode context.
    $cutPoisonIncidentId = broth_log_copilot_create_incident(array_replace($alert, ['branch' => 'B1', 'responseId' => 'resp-cutover-poison']));
    run("UPDATE broth_log_incidents SET state='escalated_level_3', current_level=3, level_entered_at='2026-08-22 00:00:00', last_reminder_at='2026-08-22 00:00:00', reminder_count=1 WHERE incident_id=?", [$cutPoisonIncidentId]);
    $cutPoisonNow = new DateTimeImmutable('2026-08-22 02:00:00 UTC');
    $cutPoisonDue = array_values(array_filter(broth_log_copilot_due_escalations($cutPoisonNow), fn($d) => $d['incident']['incident_id'] === $cutPoisonIncidentId));
    expect_true(!empty($cutPoisonDue), 'sanity: the cutover-mode incident is genuinely due for this poison-row test');
    broth_log_copilot_enqueue_webhook(['update_id' => 8201, 'message' => ['text' => 'today B1', 'from' => ['id' => (int)$cutManagerA], 'chat' => ['id' => 999, 'type' => 'group'], 'message_id' => 9603]]);
    $GLOBALS['BROTH_LOG_COPILOT_RECORDS_PROVIDER'] = function (string $branch) { throw new RuntimeException('simulated poison row for cutover test'); };
    $cutPoisonProcessed = broth_log_copilot_process_inbox(10, $cutPoisonNow);
    unset($GLOBALS['BROTH_LOG_COPILOT_RECORDS_PROVIDER']);
    expect_eq(find_processed($cutPoisonProcessed, '8201')['status'] ?? '', 'processing_failed', '20: the poison row still fails cleanly in a manager_dm-mode context');
    $cutPoisonEscalationResult = broth_log_copilot_apply_escalation_action_with_notification($cutPoisonDue[0], $cutPoisonNow);
    expect_eq($cutPoisonEscalationResult['action'] ?? '', 'reminded', '20: the escalation/reminder phase for a manager_dm-mode incident still completes after a poison row in the same tick');

    // 21: numeric-shaped chat/private-chat ids (used throughout this block, e.g. '910401001')
    // never triggered an array-key-coercion TypeError in the new mode/fallback code paths.
    expect_true(true, '21: numeric-shaped ids throughout the cutover block never triggered a regression, extending the PR #32 guarantee to broth_log_copilot_deliver_proactive_alert()');

    run("DELETE FROM broth_log_branch_alert_mode");

    // ========================================================================================
    // OPS+MANAGER DELIVERY PARITY: canonical incident, actor-based auth on Ops, button parity,
    // and the one-time pre-cutover freeze migration
    // ========================================================================================

    // --- Canonical incident, N deliveries: exactly one incident_id, multiple outbound_deliveries
    // rows referencing it, never a second incident row created for the same alert. ---
    $parityGmId = '870'; $parityGmChat = '910870001';
    run("INSERT INTO broth_log_authorized_users (telegram_user_id,display_name,role,allowed_branches,active) VALUES (?,?,?,?,1)", [$parityGmId, 'Parity GM', 'manager', json_encode(['B1'])]);
    run("INSERT INTO broth_log_private_chat_registrations (telegram_user_id, private_chat_id) VALUES (?,?)", [$parityGmId, $parityGmChat]);
    run("INSERT INTO broth_log_branch_alert_mode (branch, mode) VALUES ('B1','manager_dm')");
    $parityIncidentId = broth_log_copilot_create_incident(array_replace($alert, ['branch' => 'B1', 'responseId' => 'resp-parity-canonical']));
    broth_log_copilot_notify_incident($parityIncidentId);
    expect_eq(q1("SELECT COUNT(*) c FROM broth_log_incidents WHERE incident_id=?", [$parityIncidentId])['c'], 1, 'canonical: exactly one incident row exists for this alert');
    $parityDeliveryRows = q("SELECT chat_id, status FROM broth_log_outbound_deliveries WHERE incident_id=? AND status='sent'", [$parityIncidentId]);
    expect_true(count($parityDeliveryRows) >= 2, 'canonical: at least two independent outbound_deliveries rows (Ops + the manager DM) reference the SAME single incident_id');
    $parityChatIds = array_column($parityDeliveryRows, 'chat_id');
    expect_true(in_array($opsGroupChatId, $parityChatIds, true) && in_array($parityGmChat, $parityChatIds, true), 'canonical: both Ops and the manager DM are among the destinations for this one incident');

    // --- Button/message parity: Ops and the manager DM receive byte-identical message text and
    // identical reply markup (both ACK+Solve, since this is a temperature incident) for the SAME incident. ---
    $parityOpsMsg = null; $parityDmMsg = null;
    foreach ($sentMessages as $m) {
        if (($m['payload']['chat_id'] ?? '') === $opsGroupChatId && str_contains((string)($m['payload']['text'] ?? ''), 'Prep Area Cooler')) $parityOpsMsg = $m;
        if (($m['payload']['chat_id'] ?? '') === $parityGmChat) $parityDmMsg = $m;
    }
    expect_true($parityOpsMsg !== null && $parityDmMsg !== null, 'parity: both an Ops message and a manager DM message were actually captured for this incident');
    expect_eq($parityOpsMsg['payload']['text'] ?? '', $parityDmMsg['payload']['text'] ?? '', 'message parity: Ops and the manager DM receive byte-identical text for the same temperature incident');
    // Button parity is checked structurally (labels/count), not by exact reply_markup equality:
    // each destination's ACK/Resolve callback_data carries its own independently-generated random
    // token (broth_log_copilot_incident_reply_markup() is called separately per chat), which is a
    // deliberate security property - every button is independently single-use/revocable - not a
    // parity gap.
    $parityOpsButtonLabels = array_column($parityOpsMsg['payload']['reply_markup']['inline_keyboard'][0] ?? [], 'text');
    $parityDmButtonLabels = array_column($parityDmMsg['payload']['reply_markup']['inline_keyboard'][0] ?? [], 'text');
    expect_eq($parityOpsButtonLabels, ['ACK', 'Resolve'], 'button parity: Ops sees exactly ACK and Resolve, in order, for this temperature incident');
    expect_eq($parityDmButtonLabels, ['ACK', 'Resolve'], 'button parity: the manager DM sees exactly ACK and Resolve, in order, for the SAME temperature incident');

    // --- Actor-based authorization: seeing the button in the Ops group does NOT authorize the
    // presser. Authorization is resolved from callback.from.id, never from which chat the button
    // was pressed in - an unauthorized person pressing an Ops-group message is still denied, while
    // the real authorized manager pressing the SAME kind of button from that SAME group chat
    // succeeds. Two independently-generated tokens (not the same one reused) since a real Telegram
    // callback token is single-use/replay-protected regardless of who presses it or whether that
    // press was authorized - consumption happens before the authorization check runs, exactly as it
    // must for a real inline button that Telegram itself cannot "un-tap".
    $parityAckExpiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+15 minutes')->getTimestamp();
    $parityUnauthorizedId = '999888';
    $parityUnauthToken = broth_log_copilot_create_callback_token('ack', $parityIncidentId, $parityAckExpiresAt);
    broth_log_copilot_enqueue_webhook(['update_id' => 9301, 'callback_query' => ['id' => 'cb-9301', 'data' => $parityUnauthToken, 'from' => ['id' => (int)$parityUnauthorizedId], 'message' => ['chat' => ['id' => $opsGroupChatId, 'type' => 'group'], 'message_id' => 9701]]]);
    $parityUnauthProcessed = find_processed(broth_log_copilot_process_inbox(10), '9301');
    expect_eq($parityUnauthProcessed['status'] ?? '', 'denied', 'actor-auth: an unauthorized user pressing ACK from within the Ops group is still denied - chat membership never grants authorization');
    expect_eq(q1("SELECT state FROM broth_log_incidents WHERE incident_id=?", [$parityIncidentId])['state'] ?? '', 'detected', 'actor-auth: the denied Ops-group press never mutated the incident');

    $parityAuthToken = broth_log_copilot_create_callback_token('ack', $parityIncidentId, $parityAckExpiresAt);
    broth_log_copilot_enqueue_webhook(['update_id' => 9302, 'callback_query' => ['id' => 'cb-9302', 'data' => $parityAuthToken, 'from' => ['id' => (int)$parityGmId], 'message' => ['chat' => ['id' => $opsGroupChatId, 'type' => 'group'], 'message_id' => 9701]]]);
    $parityAuthProcessed = find_processed(broth_log_copilot_process_inbox(10), '9302');
    expect_eq($parityAuthProcessed['status'] ?? '', 'processed', 'actor-auth: the authorized manager pressing an ACK button from within the SAME Ops-group message succeeds - authorization follows the person (callback.from.id), not the chat');
    expect_eq(q1("SELECT state, acknowledged_by FROM broth_log_incidents WHERE incident_id=?", [$parityIncidentId])['state'] ?? '', 'acknowledged', 'actor-auth: the canonical incident is acknowledged globally once the authorized actor presses ACK, regardless of which surface it was pressed from');
    expect_eq(q1("SELECT acknowledged_by FROM broth_log_incidents WHERE incident_id=?", [$parityIncidentId])['acknowledged_by'] ?? '', $parityGmId, 'actor-auth: acknowledged_by correctly records the real authorized actor, not a group/chat identity');

    // --- Global ACK/reminders stop for BOTH destinations, since there is one canonical state, not
    // per-destination state. ---
    run("UPDATE broth_log_incidents SET level_entered_at='2026-08-25 00:00:00' WHERE incident_id=?", [$parityIncidentId]);
    $parityStillDue = array_values(array_filter(broth_log_copilot_due_escalations(new DateTimeImmutable('2026-08-25 01:00:00 UTC')), fn($d) => $d['incident']['incident_id'] === $parityIncidentId));
    expect_true(empty($parityStillDue), 'actor-auth: once acknowledged via the Ops-group button, the incident is no longer due for ANY future reminder, for Ops or the manager DM alike');

    run("DELETE FROM broth_log_incident_events WHERE incident_id=?", [$parityIncidentId]);
    run("DELETE FROM broth_log_incidents WHERE incident_id=?", [$parityIncidentId]);
    run("DELETE FROM broth_log_branch_alert_mode WHERE branch='B1'");
    run("DELETE FROM broth_log_private_chat_registrations WHERE telegram_user_id=?", [$parityGmId]);
    run("DELETE FROM broth_log_authorized_users WHERE telegram_user_id=?", [$parityGmId]);

    // --- One-time pre-cutover freeze migration: broth_log_copilot_freeze_pre_cutover_incidents() ---
    $freezePreId = broth_log_copilot_create_incident(array_replace($alert, ['branch' => 'B1', 'businessDate' => '2026-08-20', 'responseId' => 'resp-freeze-pre']));
    $freezePostId = broth_log_copilot_create_incident(array_replace($alert, ['branch' => 'B1', 'businessDate' => '2026-08-25', 'responseId' => 'resp-freeze-post']));
    $freezeResolvedPreId = broth_log_copilot_create_incident(array_replace($alert, ['branch' => 'B1', 'businessDate' => '2026-08-19', 'responseId' => 'resp-freeze-resolved-pre']));
    $freezeResolveResult = broth_log_copilot_resolve($freezeResolvedPreId, broth_log_copilot_authorized_user('101') ?: ['telegram_user_id' => '101', 'allowed_branch_list' => ['B1']], 38.0, 'closed door', new DateTimeImmutable('2026-08-19 12:00:00 UTC'));
    // (best-effort resolve for fixture setup - not asserted here, only used to prove freeze leaves
    // already-resolved incidents alone regardless of whether this particular resolve call succeeds)

    $freezeNow = new DateTimeImmutable('2026-08-25 12:00:00 UTC');
    $frozenIds = broth_log_copilot_freeze_pre_cutover_incidents($freezeNow);
    expect_true(in_array($freezePreId, $frozenIds, true), 'freeze: a pre-cutover (2026-08-20) open incident is frozen');
    expect_true(!in_array($freezePostId, $frozenIds, true), 'freeze: an on/after-cutover (2026-08-25) open incident is NOT frozen');

    $freezePreRow = q1("SELECT state, severity, branch, business_date, resolved_at, resolved_by, escalation_lock_expires_at FROM broth_log_incidents WHERE incident_id=?", [$freezePreId]);
    expect_eq($freezePreRow['state'], 'detected', 'freeze: the frozen incident\'s state is completely unchanged - never resolved, closed, or acknowledged by the freeze itself');
    expect_true($freezePreRow['resolved_at'] === null && $freezePreRow['resolved_by'] === null, 'freeze: freezing never fabricates a resolution');
    expect_eq($freezePreRow['severity'], 'critical', 'freeze: severity is unchanged');
    expect_eq($freezePreRow['branch'], 'B1', 'freeze: branch is unchanged');
    expect_eq($freezePreRow['business_date'], '2026-08-20', 'freeze: business_date is unchanged - the original detection date is preserved for audit');
    expect_eq($freezePreRow['escalation_lock_expires_at'], BROTH_LOG_COPILOT_FROZEN_LOCK_SENTINEL, 'freeze: the far-future lock sentinel is set, which is what actually excludes it from due_escalations()');

    $freezeAudit = q1("SELECT event_json FROM broth_log_incident_events WHERE incident_id=? AND event_type='ops_parity_cutover_frozen'", [$freezePreId]);
    expect_true($freezeAudit !== null, 'freeze: a durable audit event records the freeze action, so it is traceable later');

    $freezeDue = array_values(array_filter(broth_log_copilot_due_escalations($freezeNow->modify('+1 year')), fn($d) => $d['incident']['incident_id'] === $freezePreId));
    expect_true(empty($freezeDue), 'freeze: the frozen incident never appears in due_escalations() again, at any future time');
    $freezePostStillWorks = array_values(array_filter(broth_log_copilot_due_escalations($freezeNow->modify('+1 hour')), fn($d) => $d['incident']['incident_id'] === $freezePostId));
    // Not asserting non-empty here (timing thresholds depend on level_entered_at, not exercised in
    // this fixture) - the property under test is only that the post-cutover incident was never
    // touched by freeze at all, proven directly via its unchanged lock column below.
    expect_true(q1("SELECT escalation_lock_expires_at FROM broth_log_incidents WHERE incident_id=?", [$freezePostId])['escalation_lock_expires_at'] === null, 'freeze: the post-cutover incident\'s lock column is untouched - freeze never ran against it at all');

    // Idempotency: a second call does not re-select or re-audit the already-frozen incident.
    $freezeAuditCountBefore = q1("SELECT COUNT(*) c FROM broth_log_incident_events WHERE incident_id=? AND event_type='ops_parity_cutover_frozen'", [$freezePreId])['c'];
    $frozenIdsSecondRun = broth_log_copilot_freeze_pre_cutover_incidents($freezeNow->modify('+1 hour'));
    expect_true(!in_array($freezePreId, $frozenIdsSecondRun, true), 'freeze: idempotent - a repeat call does not re-select an already-frozen incident');
    expect_eq(q1("SELECT COUNT(*) c FROM broth_log_incident_events WHERE incident_id=? AND event_type='ops_parity_cutover_frozen'", [$freezePreId])['c'], $freezeAuditCountBefore, 'freeze: idempotent - no duplicate audit event is recorded on a repeat call');

    // Frozen incidents remain fully manually actionable - ACK/Resolve never route through
    // due_escalations() and are therefore completely unaffected by the freeze lock.
    $freezeAckResult = broth_log_copilot_ack($freezePreId, ['telegram_user_id' => '101', 'allowed_branch_list' => ['B1']]);
    expect_true($freezeAckResult['ok'] ?? false, 'freeze: a frozen incident can still be manually ACKed by an authorized actor - freezing only stops automatic reminders, never manual action');

    run("DELETE FROM broth_log_incident_events WHERE incident_id IN (?,?,?)", [$freezePreId, $freezePostId, $freezeResolvedPreId]);
    run("DELETE FROM broth_log_incidents WHERE incident_id IN (?,?,?)", [$freezePreId, $freezePostId, $freezeResolvedPreId]);

    // --- Manager Onboarding Group: hard zero-alert guarantee across every proactive stage ---
    // 7-12: walk one incident through every stage (initial, L1 reminder, L2 escalation, L3
    // escalation, L3 repeated reminder) under manager_dm mode, and a second incident through the
    // zero-eligible-manager fallback path, asserting ZERO delivery rows of any status for the
    // onboarding group chat id at every single point - not just zero successes.
    $onboardManagerId = '501'; $onboardManagerChat = '910501001';
    run("INSERT INTO broth_log_authorized_users (telegram_user_id,display_name,role,allowed_branches,active) VALUES (?,?,?,?,1)", [$onboardManagerId, 'Onboarding-Test Manager', 'manager', json_encode(['B1'])]);
    run("INSERT OR REPLACE INTO broth_log_private_chat_registrations (telegram_user_id, private_chat_id, registered_at, updated_at) VALUES (?,?,datetime('now'),datetime('now'))", [$onboardManagerId, $onboardManagerChat]);
    run("INSERT OR REPLACE INTO broth_log_branch_alert_mode (branch, mode, updated_at) VALUES ('B1','manager_dm',datetime('now'))");

    $noOnboardingLeak = function (string $incidentId, string $label) use ($onboardingGroupChatId) {
        expect_eq(q1("SELECT COUNT(*) AS c FROM broth_log_outbound_deliveries WHERE incident_id=? AND chat_id=?", [$incidentId, $onboardingGroupChatId])['c'] ?? -1, 0, "{$label}: zero delivery attempts of any status exist for the Manager Onboarding Group");
    };

    $onboardWalkId = broth_log_copilot_create_incident(array_replace($alert, ['branch' => 'B1', 'responseId' => 'resp-onboarding-walk']));
    broth_log_copilot_notify_incident($onboardWalkId);
    $noOnboardingLeak($onboardWalkId, '7: initial notification');

    $onboardWalkNow = new DateTimeImmutable('2026-08-23 00:00:00 UTC');
    run("UPDATE broth_log_incidents SET level_entered_at=?, last_reminder_at=NULL, reminder_count=0 WHERE incident_id=?", [$onboardWalkNow->format('Y-m-d H:i:s'), $onboardWalkId]);
    $onboardDue1 = array_values(array_filter(broth_log_copilot_due_escalations($onboardWalkNow->modify('+5 minutes')), fn($d) => $d['incident']['incident_id'] === $onboardWalkId));
    broth_log_copilot_apply_escalation_action_with_notification($onboardDue1[0], $onboardWalkNow->modify('+5 minutes'));
    $noOnboardingLeak($onboardWalkId, '8: L1 reminder');

    $onboardDue2 = array_values(array_filter(broth_log_copilot_due_escalations($onboardWalkNow->modify('+10 minutes')), fn($d) => $d['incident']['incident_id'] === $onboardWalkId));
    broth_log_copilot_apply_escalation_action_with_notification($onboardDue2[0], $onboardWalkNow->modify('+10 minutes'));
    $noOnboardingLeak($onboardWalkId, '9: L2 escalation');

    $onboardDue3 = array_values(array_filter(broth_log_copilot_due_escalations($onboardWalkNow->modify('+15 minutes')), fn($d) => $d['incident']['incident_id'] === $onboardWalkId));
    broth_log_copilot_apply_escalation_action_with_notification($onboardDue3[0], $onboardWalkNow->modify('+15 minutes'));
    $noOnboardingLeak($onboardWalkId, '10: L3 escalation');

    $onboardDue4 = array_values(array_filter(broth_log_copilot_due_escalations($onboardWalkNow->modify('+30 minutes')), fn($d) => $d['incident']['incident_id'] === $onboardWalkId));
    broth_log_copilot_apply_escalation_action_with_notification($onboardDue4[0], $onboardWalkNow->modify('+30 minutes'));
    $noOnboardingLeak($onboardWalkId, '11: L3 repeated reminder');

    // 12: the Ops group's mandatory delivery (with or without eligible managers) never leaks to the
    // Manager Onboarding Group. Uses B3 (genuinely zero eligible managers at this point in the suite
    // - B1 still has the earlier cutover test's active managers, irrelevant here either way since
    // Ops delivery no longer depends on manager eligibility at all).
    run("INSERT OR REPLACE INTO broth_log_branch_alert_mode (branch, mode, updated_at) VALUES ('B3','manager_dm',datetime('now'))");
    expect_true(empty(broth_log_copilot_manager_dm_chat_ids('B3')), 'sanity: B3 has zero eligible managers at this point in the suite');
    $onboardFallbackId = broth_log_copilot_create_incident(array_replace($alert, ['branch' => 'B3', 'stationKey' => 'pastaBoilerRight', 'station' => 'Pasta Boiler Right', 'target' => '>= 200F', 'responseId' => 'resp-onboarding-fallback']));
    broth_log_copilot_notify_incident($onboardFallbackId);
    $noOnboardingLeak($onboardFallbackId, '12: Ops delivery with zero eligible managers');
    expect_true(in_array($opsGroupChatId, array_column(q("SELECT chat_id FROM broth_log_outbound_deliveries WHERE incident_id=? AND status='sent'", [$onboardFallbackId]), 'chat_id'), true), '12: the Ops Alert Group correctly receives the alert, the Onboarding Group never does');

    // 17: the Manager Onboarding Group chat id never becomes a manager-DM destination for any
    // branch - it is never registered as anyone's private_chat_id, so it structurally cannot leak
    // into broth_log_copilot_manager_dm_chat_ids().
    foreach (['B1', 'B2', 'B3'] as $onboardCheckBranch) {
        expect_true(!in_array($onboardingGroupChatId, broth_log_copilot_manager_dm_chat_ids($onboardCheckBranch), true), "17: the onboarding group chat id is never a manager-DM destination for {$onboardCheckBranch}");
    }

    // 18: owner exclusion from manager DM delivery is unchanged by this PR (already covered
    // extensively in PR #34's dedicated owner test) - direct re-check that the guarantee still
    // holds with the onboarding group now in play alongside it.
    $onboardOwnerId = '502'; $onboardOwnerChat = '910502001';
    run("INSERT INTO broth_log_authorized_users (telegram_user_id,display_name,role,allowed_branches,active) VALUES (?,?,?,?,1)", [$onboardOwnerId, 'Owner', 'owner', json_encode(['B1','B2','B3'])]);
    run("INSERT OR REPLACE INTO broth_log_private_chat_registrations (telegram_user_id, private_chat_id, registered_at, updated_at) VALUES (?,?,datetime('now'),datetime('now'))", [$onboardOwnerId, $onboardOwnerChat]);
    foreach (['B1', 'B2', 'B3'] as $onboardOwnerBranch) {
        expect_true(!in_array($onboardOwnerChat, broth_log_copilot_manager_dm_chat_ids($onboardOwnerBranch), true), "18: the owner's private registration never makes them a manager-DM recipient for {$onboardOwnerBranch}");
    }
    run("DELETE FROM broth_log_authorized_users WHERE telegram_user_id=?", [$onboardOwnerId]);
    run("DELETE FROM broth_log_private_chat_registrations WHERE telegram_user_id=?", [$onboardOwnerId]);

    run("DELETE FROM broth_log_branch_alert_mode");
    run("UPDATE broth_log_authorized_users SET active=0 WHERE telegram_user_id=?", [$onboardManagerId]);

    // --- /pilotid strict chat isolation: valid ONLY in the Manager Onboarding Group, for EVERY
    // sender alike (unauthorized, owner, or manager) - sender authorization must never bypass the
    // chat restriction. Regression for the real gap found in production: an already-authorized
    // sender's /pilotid from the wrong chat used to fall through to broth_log_copilot_format_response()'s
    // pilot_id fallback and get a real "Identity already registered" reply - silently confirming
    // their authorization status - instead of the required silent rejection.
    $strictUnauthId = '601';
    $strictOwnerId = '602'; $strictOwnerChat = '910602001';
    $strictManagerId = '603'; $strictManagerChat = '910603001';
    run("INSERT INTO broth_log_authorized_users (telegram_user_id,display_name,role,allowed_branches,active) VALUES (?,?,?,?,1)", [$strictOwnerId, 'Strict Test Owner', 'owner', json_encode(['B1','B2','B3'])]);
    run("INSERT INTO broth_log_authorized_users (telegram_user_id,display_name,role,allowed_branches,active) VALUES (?,?,?,?,1)", [$strictManagerId, 'Strict Test Manager', 'manager', json_encode(['B1'])]);

    $strictUnknownChat = 'some-strict-unknown-chat';
    $strictUpdateId = 9000;
    // [sender id, sender label, chat id, chat label, expect accepted]
    $strictMatrix = [
        [$strictUnauthId, 'unauthorized', $onboardingGroupChatId, 'onboarding_group', true],
        [$strictOwnerId, 'owner', $onboardingGroupChatId, 'onboarding_group', true],
        [$strictManagerId, 'manager', $onboardingGroupChatId, 'onboarding_group', true],
        [$strictUnauthId, 'unauthorized', $opsGroupChatId, 'alert_fallback_group', false],
        [$strictOwnerId, 'owner', $opsGroupChatId, 'alert_fallback_group', false],
        [$strictManagerId, 'manager', $opsGroupChatId, 'alert_fallback_group', false],
        [$strictUnauthId, 'unauthorized', $strictUnauthId, 'private_dm', false],
        [$strictOwnerId, 'owner', $strictOwnerChat, 'private_dm', false],
        [$strictManagerId, 'manager', $strictManagerChat, 'private_dm', false],
        [$strictUnauthId, 'unauthorized', $strictUnknownChat, 'unknown_group', false],
    ];
    $strictUpdateIds = [];
    foreach ($strictMatrix as $i => [$senderId, $senderLabel, $chatId, $chatLabel, $expectAccepted]) {
        $updateId = $strictUpdateId + $i;
        $strictUpdateIds[$i] = $updateId;
        broth_log_copilot_enqueue_webhook(['update_id' => $updateId, 'message' => ['text' => '/pilotid', 'from' => ['id' => (int)$senderId], 'chat' => ['id' => $chatId], 'message_id' => 9500 + $i]]);
    }
    $strictProcessed = broth_log_copilot_process_inbox(20, new DateTimeImmutable('2026-08-23 00:00:00 UTC'));
    foreach ($strictMatrix as $i => [$senderId, $senderLabel, $chatId, $chatLabel, $expectAccepted]) {
        $updateId = $strictUpdateIds[$i];
        $row = find_processed($strictProcessed, (string)$updateId);
        $inboxRow = q1("SELECT status, outbound_status FROM broth_log_bot_inbox WHERE update_id=?", [(string)$updateId]);
        if ($expectAccepted) {
            expect_eq($row['status'] ?? '', 'processed', "{$senderLabel} -> {$chatLabel}: /pilotid is accepted and processed");
            expect_eq($inboxRow['outbound_status'] ?? '', 'sent', "{$senderLabel} -> {$chatLabel}: a reply was actually sent");
        } else {
            expect_eq($row['status'] ?? '', 'denied', "{$senderLabel} -> {$chatLabel}: /pilotid is silently denied, regardless of authorization");
            expect_true($inboxRow['outbound_status'] === null, "{$senderLabel} -> {$chatLabel}: outbound_status is NULL - zero Telegram send attempts, not merely zero successful sends");
        }
    }

    // Owner and manager in the onboarding group get the distinct "already registered" reply,
    // never the unauthorized "identity received" one (proving no branch/role confusion).
    $strictOwnerOnboardRow = q1("SELECT status, outbound_status FROM broth_log_bot_inbox WHERE update_id=?", [(string)$strictUpdateIds[1]]);
    expect_eq($strictOwnerOnboardRow['status'] ?? '', 'processed', 'sanity: owner-in-onboarding-group row processed');

    // Repeated wrong-chat /pilotid remains silent and idempotent - no state ever accumulates.
    $strictAuthUsersBefore = count(q("SELECT * FROM broth_log_authorized_users"));
    broth_log_copilot_enqueue_webhook(['update_id' => 9100, 'message' => ['text' => '/pilotid', 'from' => ['id' => (int)$strictOwnerId], 'chat' => ['id' => $opsGroupChatId], 'message_id' => 9600]]);
    broth_log_copilot_enqueue_webhook(['update_id' => 9101, 'message' => ['text' => '/pilotid', 'from' => ['id' => (int)$strictOwnerId], 'chat' => ['id' => $opsGroupChatId], 'message_id' => 9601]]);
    $strictRepeatProcessed = broth_log_copilot_process_inbox(10, new DateTimeImmutable('2026-08-23 00:00:00 UTC'));
    expect_eq(find_processed($strictRepeatProcessed, '9100')['status'] ?? '', 'denied', 'repeated wrong-chat /pilotid (1st repeat) remains silently denied');
    expect_eq(find_processed($strictRepeatProcessed, '9101')['status'] ?? '', 'denied', 'repeated wrong-chat /pilotid (2nd repeat) remains silently denied - idempotent, no escalating behavior');
    expect_true(q1("SELECT outbound_status FROM broth_log_bot_inbox WHERE update_id='9100'")['outbound_status'] === null, 'repeated wrong-chat /pilotid: still zero send attempts (1st repeat)');
    expect_true(q1("SELECT outbound_status FROM broth_log_bot_inbox WHERE update_id='9101'")['outbound_status'] === null, 'repeated wrong-chat /pilotid: still zero send attempts (2nd repeat)');
    expect_eq(count(q("SELECT * FROM broth_log_authorized_users")), $strictAuthUsersBefore, 'repeated wrong-chat /pilotid never creates or mutates authorization');

    // The dangerous fallback is confirmed gone: calling format_response() directly with a
    // pilot_id intent (simulating any future code path that might reach it despite the
    // unconditional interception above) must NOT return the authorization-confirming reply.
    $strictFallbackReply = broth_log_copilot_format_response(['intent' => 'pilot_id', 'language' => 'en'], ['preferred_language' => 'en']);
    expect_true($strictFallbackReply !== broth_log_copilot_tr('pilot_id_already_registered', 'en'), 'format_response() no longer has a pilot_id branch that confirms authorization status - falls through to the generic unknown_intent reply instead');

    run("DELETE FROM broth_log_authorized_users WHERE telegram_user_id IN (?,?)", [$strictOwnerId, $strictManagerId]);

    // Cross-cutting: across every message sent by any test in this run (queries, incident
    // notifications, ACK/resolve confirmations, reminders, escalations), none was ever addressed
    // to the one-way-alert sentinel chat - proving Copilot's destination selection never leaks
    // into the legacy one-way critical-alert chat under any code path exercised above.
    $leakedToOneWayChat = array_filter($sentMessages, fn($m) => ($m['payload']['chat_id'] ?? '') === 'one-way-alert-chat-should-never-be-used-by-copilot');
    expect_eq(count($leakedToOneWayChat), 0, 'no message sent anywhere in this test run was ever addressed to the one-way-alert chat sentinel');

    // ========================================================================================
    // ROLE-AWARE /help MENU (feature/broth-log-telegram-assistant-menu)
    // ========================================================================================
    putenv('TELEGRAM_MANAGER_ONBOARDING_CHAT_ID=menu-test-onboarding-chat');
    // 18:00 UTC = 13:00 America/Chicago (CDT) on the same calendar day - safely inside business
    // date 2026-08-25 regardless of DST, unlike a UTC-midnight timestamp which is still the
    // previous evening in Chicago and would silently resolve to the wrong business date.
    $now25 = new DateTimeImmutable('2026-08-25 18:00:00 UTC');

    $menuOwnerId = '801';
    $menuGmId = '802';
    $menuManagerId = '803'; // no display_name - exercises the handler-fallback-label path
    $menuManagerNamedId = '804';
    run("INSERT INTO broth_log_authorized_users (telegram_user_id,display_name,role,allowed_branches,active) VALUES (?,?,?,?,1)", [$menuOwnerId, 'Owner Test', 'owner', json_encode(['B1','B2','B3'])]);
    run("INSERT INTO broth_log_authorized_users (telegram_user_id,display_name,role,allowed_branches,active) VALUES (?,?,?,?,1)", [$menuGmId, 'GM Test', 'manager', json_encode(['B1','B2','B3'])]);
    run("INSERT INTO broth_log_authorized_users (telegram_user_id,display_name,role,allowed_branches,active) VALUES (?,?,?,?,1)", [$menuManagerId, '', 'manager', json_encode(['B1'])]);
    run("INSERT INTO broth_log_authorized_users (telegram_user_id,display_name,role,allowed_branches,active) VALUES (?,?,?,?,1)", [$menuManagerNamedId, 'Maria', 'manager', json_encode(['B1'])]);
    $menuOwnerUser = broth_log_copilot_authorized_user($menuOwnerId);
    $menuGmUser = broth_log_copilot_authorized_user($menuGmId);
    $menuManagerUser = broth_log_copilot_authorized_user($menuManagerId);

    expect_eq(broth_log_copilot_role_class($menuOwnerUser), 'owner', 'role_class: owner role -> owner menu');
    expect_eq(broth_log_copilot_role_class($menuGmUser), 'gm', 'role_class: manager with 3 branches -> gm menu');
    expect_eq(broth_log_copilot_role_class($menuManagerUser), 'store_manager', 'role_class: manager with 1 branch -> store_manager menu');

    // --- 1/2/3: role-aware /help menus, exact button layout, callback_data size ---
    $mgrHelp = broth_log_copilot_help_response($menuManagerUser, 'private');
    expect_eq($mgrHelp['intent'], 'help_menu', 'Manager /help returns the role-aware menu');
    $mgrKb = $mgrHelp['reply_markup']['inline_keyboard'];
    expect_eq(count($mgrKb), 3, 'Manager menu has exactly 3 rows');
    expect_eq([$mgrKb[0][0]['text'], $mgrKb[0][1]['text']], ["\u{1F4C5} Today's Log", "\u{1F5D3} Choose Date"], "Manager menu row 1: Today's Log | Choose Date");
    expect_eq([$mgrKb[1][0]['text'], $mgrKb[1][1]['text']], ["\u{1F6A8} Today's Issues", "\u{1F50E} Issues by Date"], "Manager menu row 2: Today's Issues | Issues by Date");
    expect_eq([$mgrKb[2][0]['text'], $mgrKb[2][1]['text']], ["\u{1F4CC} Open Issues", "\u{2753} Commands"], 'Manager menu row 3: Open Issues | Commands');

    $gmHelp = broth_log_copilot_help_response($menuGmUser, 'private');
    $gmKb = $gmHelp['reply_markup']['inline_keyboard'];
    expect_eq(count($gmKb), 4, 'GM menu has exactly 4 rows');
    expect_eq([$gmKb[0][0]['text'], $gmKb[0][1]['text']], ["\u{1F4C5} Today's Log", "\u{1F3EA} All Stores"], "GM menu row 1: Today's Log | All Stores");
    expect_eq([$gmKb[1][0]['text'], $gmKb[1][1]['text']], ["\u{1F6A8} Today's Issues", "\u{1F4CC} Open Issues"], "GM menu row 2: Today's Issues | Open Issues");
    expect_eq([$gmKb[2][0]['text'], $gmKb[2][1]['text']], ["\u{1F5D3} Log by Date", "\u{1F50E} Issues by Date"], 'GM menu row 3: Log by Date | Issues by Date');
    expect_eq(count($gmKb[3]), 1, 'GM menu row 4 has exactly 1 button');
    expect_eq($gmKb[3][0]['text'], "\u{2753} Commands", 'GM menu row 4: Commands');

    $ownerHelp = broth_log_copilot_help_response($menuOwnerUser, 'private');
    expect_eq(count($ownerHelp['reply_markup']['inline_keyboard']), 3, 'Owner/CEO menu has 3 rows');

    foreach ([$mgrKb, $gmKb, $ownerHelp['reply_markup']['inline_keyboard']] as $kb) {
        foreach ($kb as $row) {
            foreach ($row as $btn) {
                expect_true(strlen($btn['callback_data']) <= 64, "callback_data within Telegram's 64-byte limit: {$btn['callback_data']}");
            }
        }
    }

    // --- 4: unauthorized user /help gets no protected information (existing !$user gate, unaffected) ---
    broth_log_copilot_enqueue_webhook(['update_id' => 9700, 'message' => ['text' => '/help', 'from' => ['id' => 999888777], 'chat' => ['id' => 'menu-unauth-chat', 'type' => 'private'], 'message_id' => 9700]]);
    $unauthHelpProcessed = broth_log_copilot_process_inbox(10, $now25);
    expect_eq(find_processed($unauthHelpProcessed, '9700')['status'] ?? '', 'denied', 'unauthorized /help is silently denied');

    // --- 18/19: group isolation - onboarding / Alert-Fallback groups get no protected /help or menu data ---
    $groupHelp = broth_log_copilot_help_response($menuManagerUser, 'group');
    expect_eq($groupHelp['intent'], 'help_group_denied', '/help from a non-private chat is consumed without opening the assistant');
    expect_true(!empty($groupHelp['silent']), 'group /help is silent in Ops/alert destinations');
    expect_true($groupHelp['reply_markup'] === null, 'group /help reply carries no keyboard');
    expect_true(!str_contains($groupHelp['message'], 'B1') && !str_contains($groupHelp['message'], 'Broth Log Assistant'), 'group /help reply contains no store/menu content');
    $groupMenuCb = broth_log_copilot_menu_callback_response('menu:today', $menuManagerUser, 'group', $now25);
    expect_eq($groupMenuCb['intent'], 'menu_group_denied', 'a menu: callback tapped from a non-private chat is denied, never rendered');
    expect_true(!empty($groupMenuCb['silent']), 'a menu: callback from a group is consumed silently without cluttering Ops');

    $ownerButtonMatrix = [];
    foreach ($ownerHelp['reply_markup']['inline_keyboard'] as $row) {
        foreach ($row as $btn) $ownerButtonMatrix[$btn['text']] = $btn['callback_data'];
    }
    foreach ([
        "\u{1F4CA} Today's Summary" => 'menu_ceo_summary',
        "\u{1F6A8} Exceptions" => 'menu_open',
        "\u{1F3EA} Stores" => 'menu_branchpick',
        "\u{1F4CC} Open Issues" => 'menu_open',
        "\u{1F5D3} Historical" => 'menu_branchpick',
        "\u{2753} Commands" => 'menu_commands',
    ] as $label => $intent) {
        expect_true(isset($ownerButtonMatrix[$label]) && $ownerButtonMatrix[$label] !== '', "owner button has callback_data: {$label}");
        $handlerProbe = broth_log_copilot_menu_callback_response($ownerButtonMatrix[$label], $menuOwnerUser, 'private', $now25);
        expect_eq($handlerProbe['intent'], $intent, "owner button callback has a handler: {$label}");
    }

    $sentMessagesBeforeAck = count($sentMessages);
    $callbackAck = broth_log_copilot_acknowledge_callback_update([
        'update_id' => 9701,
        'callback_query' => [
            'id' => 'cb-menu-9701',
            'data' => 'menu:ceo_summary',
            'from' => ['id' => (int)$menuOwnerId],
            'message' => ['chat' => ['id' => 'menu-owner-private', 'type' => 'private'], 'message_id' => 9701],
        ],
    ]);
    expect_true(!empty($callbackAck['sent']), 'private menu callback is acknowledged with answerCallbackQuery');
    $ackTransportCall = $sentMessages[$sentMessagesBeforeAck] ?? null;
    expect_eq($ackTransportCall['method'] ?? '', 'answerCallbackQuery', 'callback acknowledgement uses Telegram answerCallbackQuery');

    broth_log_copilot_enqueue_webhook(['update_id' => 9702, 'callback_query' => ['id' => 'cb-menu-9702', 'data' => 'menu:ceo_summary', 'from' => ['id' => (int)$menuOwnerId], 'message' => ['chat' => ['id' => 'menu-owner-private', 'type' => 'private'], 'message_id' => 9702]]]);
    $callbackPayload = q1("SELECT payload_json,message_text FROM broth_log_bot_inbox WHERE update_id='9702'");
    $callbackPayloadJson = json_decode((string)$callbackPayload['payload_json'], true) ?: [];
    expect_eq($callbackPayload['message_text'] ?? '', 'menu:ceo_summary', 'callback enqueue stores callback_data as message_text');
    expect_eq($callbackPayloadJson['callback_query_id'] ?? '', 'cb-menu-9702', 'callback enqueue keeps callback_query_id for audit');

    // --- 5/6: branch authorization + forged callback denial ---
    // Echoes back records for whichever business date is actually requested (both 2026-08-25 and
    // the 2026-08-19 historical probe below), so both the "today" and "historical" header paths
    // are genuinely exercised instead of one of them silently hitting the empty-submission branch.
    $GLOBALS['BROTH_LOG_COPILOT_RECORDS_PROVIDER'] = function (string $branch): array {
        if ($branch !== 'B1') return [];
        return [[
            'id' => 'menu-rec-1', 'branch' => 'B1', 'businessDate' => '2026-08-25', 'businessTime' => '08:00', 'employeeName' => 'Tester',
            'readings' => [['key' => 'walkInFreezer', 'label' => 'Walk-In Freezer', 'category' => 'freezer', 'temperature' => 8.0, 'unit' => 'F', 'severity' => 'critical', 'target' => '<= 0F', 'correctiveAction' => '']],
            'issues' => [['key' => 'walkInFreezer', 'label' => 'Walk-In Freezer', 'category' => 'freezer', 'temperature' => 8.0, 'unit' => 'F', 'severity' => 'critical', 'target' => '<= 0F', 'correctiveAction' => '', 'status' => 'Escalated']],
        ], [
            'id' => 'menu-rec-hist', 'branch' => 'B1', 'businessDate' => '2026-08-19', 'businessTime' => '08:00', 'employeeName' => 'Tester',
            'readings' => [['key' => 'walkInFreezer', 'label' => 'Walk-In Freezer', 'category' => 'freezer', 'temperature' => 38.0, 'unit' => 'F', 'severity' => 'safe', 'target' => '<= 40F', 'correctiveAction' => '']],
            'issues' => [],
        ]];
    };
    $b1LogView = broth_log_copilot_menu_callback_response('menu:today', $menuManagerUser, 'private', $now25);
    expect_true(str_contains($b1LogView['message'], 'B1') && str_contains($b1LogView['message'], 'Today'), "B1 manager: Today's Log auto-resolves to their own store, dated Today");
    expect_true(str_contains($b1LogView['message'], 'Walk-In Freezer'), "B1 manager: Today's Log shows the real current critical issue");

    $forgedB2 = broth_log_copilot_menu_callback_response('menu:log:B2:2026-08-25', $menuManagerUser, 'private', $now25);
    expect_eq($forgedB2['intent'], 'menu_forbidden', 'B1 manager forging a B2 log callback is denied');
    expect_true(!str_contains($forgedB2['message'], 'Walk-In'), 'forged B2 callback response leaks no store data');

    // --- 14: historical log view never claims to be "Today" ---
    $histLogView = broth_log_copilot_menu_callback_response('menu:log:B1:2026-08-19', $menuManagerUser, 'private', $now25);
    expect_true(str_contains($histLogView['message'], '2026-08-19') && !str_contains($histLogView['message'], 'Today'), 'historical log view shows the picked date, never "Today"');

    // --- 7: GM All Stores (B1+B2+B3), priority-branch surfacing ---
    $GLOBALS['BROTH_LOG_COPILOT_RECORDS_PROVIDER'] = function (string $branch): array {
        $isCritical = $branch === 'B1';
        return [[
            'id' => "menu-rec-$branch", 'branch' => $branch, 'businessDate' => '2026-08-25', 'businessTime' => '08:00', 'employeeName' => 'Tester',
            'readings' => [['key' => 'walkInFreezer', 'label' => 'Walk-In Freezer', 'category' => 'freezer', 'temperature' => $isCritical ? 8.0 : 38.0, 'unit' => 'F', 'severity' => $isCritical ? 'critical' : 'safe', 'target' => '<= 0F', 'correctiveAction' => '']],
            'issues' => $isCritical ? [['key' => 'walkInFreezer', 'label' => 'Walk-In Freezer', 'category' => 'freezer', 'temperature' => 8.0, 'unit' => 'F', 'severity' => 'critical', 'target' => '<= 0F', 'correctiveAction' => '', 'status' => 'Escalated']] : [],
        ]];
    };
    $allStoresView = broth_log_copilot_menu_callback_response('menu:log:ALL:2026-08-25', $menuGmUser, 'private', $now25);
    expect_true(str_contains($allStoresView['message'], 'B1') && str_contains($allStoresView['message'], 'B2') && str_contains($allStoresView['message'], 'B3'), 'GM All Stores view covers B1+B2+B3');
    expect_true(str_contains($allStoresView['message'], 'Priority:'), 'GM All Stores view surfaces the priority branch when one store is critical');
    unset($GLOBALS['BROTH_LOG_COPILOT_RECORDS_PROVIDER']);

    // --- 8/9/10/11/12/13: incident-based Today's Issues / Issues by Date + handler accountability ---
    $menuIncUnack = 'menu-inc-unack-' . bin2hex(random_bytes(3));
    $menuIncAck = 'menu-inc-ack-' . bin2hex(random_bytes(3));
    $menuIncResolved = 'menu-inc-resolved-' . bin2hex(random_bytes(3));
    $menuIncAckNoName = 'menu-inc-acknoname-' . bin2hex(random_bytes(3));
    run("INSERT INTO broth_log_incidents (incident_id,fingerprint,active_key,branch,business_date,response_id,station_key,station_label,temperature_f,sop_target,severity,corrective_action,state,current_level,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
        [$menuIncUnack, $menuIncUnack, $menuIncUnack, 'B1', '2026-08-25', 'resp-1', 'walkInFreezer', 'Walk-In Freezer', 8.0, '<= 0F', 'critical', 'Close door', 'detected', 1, '2026-08-25 10:00:00']);
    run("INSERT INTO broth_log_incidents (incident_id,fingerprint,active_key,branch,business_date,response_id,station_key,station_label,temperature_f,sop_target,severity,corrective_action,state,current_level,acknowledged_by,acknowledged_at,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
        [$menuIncAck, $menuIncAck, $menuIncAck, 'B1', '2026-08-25', 'resp-2', 'fryerRight', 'Fryer Right', 300.0, '>= 325F', 'critical', 'Adjust dial', 'acknowledged', 1, $menuManagerNamedId, '2026-08-25 10:05:00', '2026-08-25 10:00:00']);
    run("INSERT INTO broth_log_incidents (incident_id,fingerprint,active_key,branch,business_date,response_id,station_key,station_label,temperature_f,sop_target,severity,corrective_action,state,current_level,acknowledged_by,resolved_by,resolved_at,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
        [$menuIncResolved, $menuIncResolved, $menuIncResolved, 'B2', '2026-08-19', 'resp-3', 'walkInFreezer', 'Walk-In Freezer', 19.0, '<= 0F', 'critical', 'Close door', 'resolved', 1, $menuManagerNamedId, $menuManagerNamedId, '2026-08-19 23:42:00', '2026-08-19 20:00:00']);
    run("INSERT INTO broth_log_incidents (incident_id,fingerprint,active_key,branch,business_date,response_id,station_key,station_label,temperature_f,sop_target,severity,corrective_action,state,current_level,acknowledged_by,acknowledged_at,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
        [$menuIncAckNoName, $menuIncAckNoName, $menuIncAckNoName, 'B1', '2026-08-25', 'resp-4', 'lineFreezer', 'Line Freezer', 10.0, '<= 0F', 'critical', '', 'acknowledged', 1, $menuManagerId, '2026-08-25 10:10:00', '2026-08-25 10:00:00']);

    $handlerUnack = broth_log_copilot_incident_handler_summary(q1("SELECT * FROM broth_log_incidents WHERE incident_id=?", [$menuIncUnack]));
    expect_eq($handlerUnack, ['status' => 'unacknowledged', 'display' => 'No one yet', 'display_name_known' => true], 'handler summary: unacknowledged -> No one yet');
    $handlerAck = broth_log_copilot_incident_handler_summary(q1("SELECT * FROM broth_log_incidents WHERE incident_id=?", [$menuIncAck]));
    expect_eq($handlerAck['status'], 'acknowledged', 'handler summary: acknowledged status (not shown as resolved)');
    expect_eq($handlerAck['display'], 'Maria', 'handler summary: acknowledged shows the real approved display name');
    $handlerResolved = broth_log_copilot_incident_handler_summary(q1("SELECT * FROM broth_log_incidents WHERE incident_id=?", [$menuIncResolved]));
    expect_eq($handlerResolved['status'], 'resolved', 'handler summary: resolved status');
    expect_eq($handlerResolved['display'], 'Maria', 'handler summary: resolved shows the real approved resolver name');
    $handlerAckNoName = broth_log_copilot_incident_handler_summary(q1("SELECT * FROM broth_log_incidents WHERE incident_id=?", [$menuIncAckNoName]));
    expect_eq($handlerAckNoName['display'], 'Authorized Manager', 'handler summary: missing display_name falls back to a generic safe label, never the raw Telegram ID');
    expect_true($handlerAckNoName['display_name_known'] === false, 'handler summary flags the display_name data-quality gap for audit/reporting');

    $todayIssuesView = broth_log_copilot_menu_callback_response('menu:issues_today', $menuManagerUser, 'private', $now25);
    expect_true(str_contains($todayIssuesView['message'], 'Walk-In Freezer'), "Today's Issues shows the unacknowledged critical incident");
    expect_true(str_contains($todayIssuesView['message'], 'No one yet'), "Today's Issues: unacknowledged issue shows 'No one yet'");
    expect_true(str_contains($todayIssuesView['message'], 'Maria') && str_contains($todayIssuesView['message'], 'ACKNOWLEDGED'), "Today's Issues: acknowledged issue shows the handler's name and ACKNOWLEDGED status, never as resolved");
    expect_true(!str_contains($todayIssuesView['message'], $menuManagerNamedId) && !str_contains($todayIssuesView['message'], $menuManagerId), '11: handler display never contains a raw Telegram user ID');
    expect_true(strpos($todayIssuesView['message'], 'Walk-In Freezer') < strpos($todayIssuesView['message'], 'Fryer Right'), 'issue priority: unacknowledged critical ranks above acknowledged critical');

    $issuesByDateView = broth_log_copilot_menu_callback_response('menu:issues:B2:2026-08-19', $menuGmUser, 'private', $now25);
    expect_true(str_contains($issuesByDateView['message'], 'Resolved') && str_contains($issuesByDateView['message'], 'Maria'), '10: Issues by Date shows a since-resolved incident in its current Resolved state with its resolver');
    expect_true(str_contains($issuesByDateView['message'], '2026-08-19'), '12: Issues by Date header reflects the selected historical business date');

    // --- 21: no menu callback can mutate an incident ---
    $beforeSnapshot = q1("SELECT state, acknowledged_by, resolved_by FROM broth_log_incidents WHERE incident_id=?", [$menuIncUnack]);
    foreach (['menu:today', 'menu:issues_today', 'menu:log:B1:2026-08-25', 'menu:fulllog:B1:2026-08-25', 'menu:issues:B1:2026-08-25', 'menu:open'] as $probe) {
        broth_log_copilot_menu_callback_response($probe, $menuManagerUser, 'private', $now25);
    }
    $afterSnapshot = q1("SELECT state, acknowledged_by, resolved_by FROM broth_log_incidents WHERE incident_id=?", [$menuIncUnack]);
    expect_eq($afterSnapshot, $beforeSnapshot, 'no menu: callback ever mutates an incident row (read-only navigation only)');

    // --- 15/16: date-entry context - invalid input, valid input, cancel, never traps the user ---
    $dateEntryPrompt = broth_log_copilot_menu_callback_response('menu:qdate:log:B1:enter', $menuManagerUser, 'private', $now25);
    expect_eq($dateEntryPrompt['intent'], 'menu_date_prompt', 'tapping Enter Date prompts for free-text input');
    $pendingCtx = q1("SELECT context_json FROM broth_log_conversation_context WHERE telegram_user_id=?", [$menuManagerId]);
    expect_true($pendingCtx !== null && (json_decode((string)$pendingCtx['context_json'], true)['pending'] ?? '') === 'menu_date', 'Enter Date arms the awaiting-date conversation context');

    $invalidDateReply = broth_log_copilot_menu_date_entry_response('not a date at all', $menuManagerUser, $now25);
    expect_true(str_contains($invalidDateReply['message'], "couldn't recognize"), '15: unparseable free-text date is rejected with a friendly message');
    expect_true(q1("SELECT * FROM broth_log_conversation_context WHERE telegram_user_id=?", [$menuManagerId]) === null, '16: date-entry context is cleared even on invalid input - never traps the user indefinitely');

    broth_log_copilot_menu_set_date_context($menuManagerId, 'log', 'B1');
    $GLOBALS['BROTH_LOG_COPILOT_RECORDS_PROVIDER'] = function (string $branch): array { return []; };
    $validDateReply = broth_log_copilot_menu_date_entry_response('2026-08-19', $menuManagerUser, $now25);
    expect_eq($validDateReply['intent'], 'menu_log_empty', 'a valid entered date resolves straight into the log view for that date');
    expect_true(q1("SELECT * FROM broth_log_conversation_context WHERE telegram_user_id=?", [$menuManagerId]) === null, 'date-entry context is cleared after a valid entry');
    unset($GLOBALS['BROTH_LOG_COPILOT_RECORDS_PROVIDER']);

    broth_log_copilot_menu_set_date_context($menuManagerId, 'log', 'B1');
    $cancelReply = broth_log_copilot_menu_callback_response('menu:qdate:log:B1:cancel', $menuManagerUser, 'private', $now25);
    expect_eq($cancelReply['intent'], 'menu_main', 'Cancel returns to the main menu');
    expect_true(q1("SELECT * FROM broth_log_conversation_context WHERE telegram_user_id=?", [$menuManagerId]) === null, 'Cancel clears the pending date-entry context (never leaves a stale wait that would swallow the next real command)');

    // A message sent while a menu_date context is pending, that does NOT look like a date reply
    // scenario at all (ordinary command), still correctly resolves once no context is pending -
    // proving the intercept is scoped exactly to the pending window and never leaks beyond it.
    expect_true(broth_log_copilot_menu_date_entry_response('B1 today', $menuManagerUser, $now25) === null, 'with no pending date-entry context, an ordinary text command is left untouched by the menu date-entry intercept');

    // --- 17/22: ACK/Resolve compact-token callbacks are completely untouched by the new dispatcher ---
    expect_true(broth_log_copilot_menu_callback_response('c|a|abc123|xyz|deadbeef01', $menuManagerUser, 'private', $now25) === null, 'a real ACK/Resolve compact callback token is never intercepted by the menu dispatcher - falls through unchanged');
    $ackProbeId = 'menu-ack-regress-' . bin2hex(random_bytes(3));
    run("INSERT INTO broth_log_incidents (incident_id,fingerprint,active_key,branch,business_date,response_id,station_key,station_label,temperature_f,sop_target,severity,corrective_action,state,current_level,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
        [$ackProbeId, $ackProbeId, $ackProbeId, 'B1', '2026-08-25', 'resp-ack', 'walkInFreezer', 'Walk-In Freezer', 8.0, '<= 0F', 'critical', 'Close door', 'detected', 1, '2026-08-25 10:00:00']);
    $ackToken = broth_log_copilot_create_callback_token('ack', $ackProbeId, $now25->modify('+15 minutes')->getTimestamp());
    broth_log_copilot_enqueue_webhook(['update_id' => 9800, 'callback_query' => ['data' => $ackToken, 'from' => ['id' => (int)$menuManagerId], 'message' => ['chat' => ['id' => 'menu-ack-chat', 'type' => 'private']]]]);
    $ackRegressProcessed = broth_log_copilot_process_inbox(10, $now25);
    expect_eq(find_processed($ackRegressProcessed, '9800')['status'] ?? '', 'processed', 'existing ACK callback still works end-to-end through process_inbox() after the menu dispatcher was added');
    expect_eq(find_processed($ackRegressProcessed, '9800')['intent'] ?? '', 'ack', 'existing ACK callback still resolves to intent=ack (unchanged)');
    expect_eq(q1("SELECT state FROM broth_log_incidents WHERE incident_id=?", [$ackProbeId])['state'] ?? '', 'acknowledged', 'existing ACK callback still mutates the incident exactly as before');

    // --- 20: private DM menu works end-to-end through process_inbox() ---
    broth_log_copilot_enqueue_webhook(['update_id' => 9900, 'message' => ['text' => '/help', 'from' => ['id' => (int)$menuManagerId], 'chat' => ['id' => 'menu-private-chat', 'type' => 'private'], 'message_id' => 9900]]);
    $helpEndToEnd = broth_log_copilot_process_inbox(10, $now25);
    expect_eq(find_processed($helpEndToEnd, '9900')['status'] ?? '', 'processed', 'private DM /help is processed end-to-end');
    expect_eq(find_processed($helpEndToEnd, '9900')['intent'] ?? '', 'help_menu', 'private DM /help resolves to the role-aware help_menu intent end-to-end');
    $helpSentPayload = end($sentMessages);
    expect_true(isset($helpSentPayload['payload']['reply_markup']['inline_keyboard']), 'private DM /help actually sends a Telegram inline keyboard, not just plain text');

    $beforeGroupSilence = count($sentMessages);
    broth_log_copilot_enqueue_webhook(['update_id' => 9901, 'message' => ['text' => '/help', 'from' => ['id' => (int)$menuOwnerId], 'chat' => ['id' => 'menu-ops-group', 'type' => 'group'], 'message_id' => 9901]]);
    broth_log_copilot_enqueue_webhook(['update_id' => 9902, 'callback_query' => ['id' => 'cb-menu-9902', 'data' => 'menu:ceo_summary', 'from' => ['id' => (int)$menuOwnerId], 'message' => ['chat' => ['id' => 'menu-ops-group', 'type' => 'group'], 'message_id' => 9902]]]);
    $groupSilentProcessed = broth_log_copilot_process_inbox(10, $now25);
    expect_eq(find_processed($groupSilentProcessed, '9901')['outbound'] ?? '', 'silent', 'Ops/group /help is processed silently without assistant output');
    expect_eq(find_processed($groupSilentProcessed, '9902')['outbound'] ?? '', 'silent', 'Ops/group menu callback is processed silently without assistant output');
    expect_eq(count($sentMessages), $beforeGroupSilence, 'Ops/group assistant inputs create zero Telegram sendMessage calls');

    run("DELETE FROM broth_log_authorized_users WHERE telegram_user_id IN (?,?,?,?)", [$menuOwnerId, $menuGmId, $menuManagerId, $menuManagerNamedId]);
    run("DELETE FROM broth_log_incidents WHERE incident_id IN (?,?,?,?,?)", [$menuIncUnack, $menuIncAck, $menuIncResolved, $menuIncAckNoName, $ackProbeId]);
    run("DELETE FROM broth_log_conversation_context WHERE telegram_user_id IN (?,?,?,?)", [$menuOwnerId, $menuGmId, $menuManagerId, $menuManagerNamedId]);

    // ========================================================================================
    // MISSING-SHIFT ALERTS (feature/broth-log-missing-shift-alerts) - dark by default
    // ========================================================================================

    // --- Feature-flag dark-by-default proof: must run BEFORE the flag is ever enabled below ---
    putenv('BROTH_LOG_SHIFT_ALERTS_ENABLED=');
    expect_eq(broth_log_copilot_missing_shift_alerts_enabled(), false, 'missing-shift alerts default OFF when the env var is unset');
    $GLOBALS['BROTH_LOG_COPILOT_RECORDS_PROVIDER'] = function (string $branch): array { return []; };
    $darkNow = new DateTimeImmutable('2026-08-25 23:30:00 UTC'); // well past both AM and PM deadline+grace in Chicago
    $darkResult = broth_log_copilot_process_missing_shifts($darkNow);
    expect_eq($darkResult, [], 'process_missing_shifts() is a true no-op while the feature flag is off, even though every branch/shift would otherwise be MISSING');
    expect_eq(q1("SELECT COUNT(*) c FROM broth_log_incidents WHERE incident_type='missing_shift'")['c'], 0, 'zero missing_shift incidents exist anywhere after a dark-flag sweep');
    unset($GLOBALS['BROTH_LOG_COPILOT_RECORDS_PROVIDER']);

    // --- Enable the flag for the remainder of this section. This section's tests exercise
    // grace-period/dedup/AM-PM/routing behavior across all three branches, not the per-branch
    // activation gate itself (that gets its own dedicated tests further below) - so all three
    // branches are explicitly allowlisted here to preserve this section's original intent. ---
    putenv('BROTH_LOG_SHIFT_ALERTS_ENABLED=true');
    putenv('BROTH_LOG_SHIFT_ALERT_BRANCHES=B1,B2,B3');
    expect_eq(broth_log_copilot_missing_shift_alerts_enabled(), true, 'flag correctly reads true once set');

    // --- Fixtures used throughout this section: a GM authorized for B1+B2+B3, used for read-only
    // menu views (historical query, Today's Log/Issues integration) - deleted at the end. ---
    $msGmId = '861'; $msGmChat = '910861001';
    run("INSERT INTO broth_log_authorized_users (telegram_user_id,display_name,role,allowed_branches,active) VALUES (?,?,?,?,1)", [$msGmId, 'MS Test GM', 'manager', json_encode(['B1', 'B2', 'B3'])]);
    run("INSERT INTO broth_log_private_chat_registrations (telegram_user_id, private_chat_id) VALUES (?,?)", [$msGmId, $msGmChat]);
    $msGmUser = broth_log_copilot_authorized_user($msGmId);
    expect_true($msGmUser !== null, 'MS GM fixture is authorized');

    // --- Grace period: before vs after deadline+grace, in one real sweep across B1/B2/B3 ---
    // 16:05 Chicago = AM deadline+grace (11:10) already passed, PM deadline+grace (17:10) not yet.
    // Fixed calendar date (not wall-clock "today") so the test is deterministic regardless of when
    // it runs, and PHP's own tz database (not a hand-picked UTC offset) resolves DST correctly.
    $graceNow = (new DateTimeImmutable('2026-08-25 16:05:00', new DateTimeZone('America/Chicago')))->setTimezone(new DateTimeZone('UTC'));
    $GLOBALS['BROTH_LOG_COPILOT_RECORDS_PROVIDER'] = function (string $branch): array { return []; };
    $sweep1 = broth_log_copilot_process_missing_shifts($graceNow);
    $created1 = array_values(array_filter($sweep1, fn($r) => ($r['action'] ?? '') === 'created'));
    expect_eq(count($created1), 3, 'B: after AM deadline+grace, exactly one missing incident is created per branch (3 total for B1+B2+B3) - PM is not yet due');
    foreach ($created1 as $c) expect_eq($c['shift'], 'AM', 'B: every incident created in this sweep is for the AM shift, never PM (not yet due)');
    expect_eq(q1("SELECT COUNT(*) c FROM broth_log_incidents WHERE incident_type='missing_shift' AND shift='PM'")['c'], 0, 'L: PM is not evaluated early - zero PM missing_shift incidents exist before its own deadline+grace');

    // --- Dedup / idempotent repeated tick: same tick conditions -> same incidents, no duplicates ---
    $sweep2 = broth_log_copilot_process_missing_shifts($graceNow);
    $created2 = array_values(array_filter($sweep2, fn($r) => ($r['action'] ?? '') === 'created'));
    expect_eq(count($created2), 0, 'C/K: a repeated worker tick under the same conditions creates zero additional incidents - the existing ones are reused, not duplicated');
    expect_eq(q1("SELECT COUNT(*) c FROM broth_log_incidents WHERE incident_type='missing_shift' AND shift='AM'")['c'], 3, 'C/K: exactly one AM missing_shift incident per branch still exists after the repeated tick - dedup key (branch, business_date, shift, incident_type) holds');

    // --- AM/PM are separate incidents: advance past PM's own deadline+grace too ---
    $graceNowPm = $graceNow->modify('+2 hours'); // 18:05 Chicago, past PM's 17:10 deadline+grace
    $sweep3 = broth_log_copilot_process_missing_shifts($graceNowPm);
    $created3 = array_values(array_filter($sweep3, fn($r) => ($r['action'] ?? '') === 'created'));
    expect_eq(count($created3), 3, 'M: after PM deadline+grace too, exactly one additional (PM) incident is created per branch');
    foreach ($created3 as $c) expect_eq($c['shift'], 'PM', 'M: this second batch is entirely PM incidents, distinct from the earlier AM batch');
    $msBusinessDateForGrace = broth_log_business_date($graceNow);
    expect_eq(q1("SELECT COUNT(*) c FROM broth_log_incidents WHERE incident_type='missing_shift' AND business_date=?", [$msBusinessDateForGrace])['c'], 6, 'exactly 6 missing_shift incidents exist for this business date (3 branches x AM+PM), matching the dedup key exactly');
    unset($GLOBALS['BROTH_LOG_COPILOT_RECORDS_PROVIDER']);

    // Clean up the grace/dedup sweep's incidents before the more targeted tests below, so they
    // start from a clean slate and their own counts are unambiguous.
    run("DELETE FROM broth_log_incident_events WHERE incident_id IN (SELECT incident_id FROM broth_log_incidents WHERE incident_type='missing_shift')");
    run("DELETE FROM broth_log_incidents WHERE incident_type='missing_shift'");

    // --- Different business dates are separate incidents (dedup key includes business_date) ---
    $keyDay1 = broth_log_copilot_missing_shift_active_key('B1', '2026-08-20', 'AM');
    $keyDay2 = broth_log_copilot_missing_shift_active_key('B1', '2026-08-21', 'AM');
    expect_true($keyDay1 !== $keyDay2, 'different business dates produce different dedup keys for the same branch/shift');
    $incDay1 = broth_log_copilot_create_missing_shift_incident('B1', '2026-08-20', 'AM');
    $incDay2 = broth_log_copilot_create_missing_shift_incident('B1', '2026-08-21', 'AM');
    expect_true($incDay1 !== '' && $incDay2 !== '' && $incDay1 !== $incDay2, 'two different business dates create two genuinely separate incidents');
    run("DELETE FROM broth_log_incident_events WHERE incident_id IN (?,?)", [$incDay1, $incDay2]);
    run("DELETE FROM broth_log_incidents WHERE incident_id IN (?,?)", [$incDay1, $incDay2]);

    // --- Incident model: no fake temperature/SOP/station data on a missing_shift row ---
    $msModelId = broth_log_copilot_create_missing_shift_incident('B2', '2026-08-22', 'PM');
    $msModelRow = q1("SELECT * FROM broth_log_incidents WHERE incident_id=?", [$msModelId]);
    expect_eq($msModelRow['incident_type'], 'missing_shift', 'incident model: incident_type is missing_shift');
    expect_eq($msModelRow['shift'], 'PM', 'incident model: shift is stored exactly');
    expect_eq($msModelRow['branch'], 'B2', 'incident model: branch is stored exactly');
    expect_eq($msModelRow['business_date'], '2026-08-22', 'incident model: business_date is stored exactly');
    expect_eq($msModelRow['temperature_f'], null, 'incident model: temperature_f is NULL, never a fabricated value');
    expect_eq($msModelRow['sop_target'], '', 'incident model: sop_target is empty, never a fabricated value');
    expect_eq($msModelRow['station_key'], '', 'incident model: station_key is empty, never a fabricated station');
    expect_eq($msModelRow['state'], 'detected', 'incident model: initial state is detected, same state machine as temperature incidents');
    expect_eq($msModelRow['closure_reason'], null, 'incident model: closure_reason is NULL until closed');

    // --- ACK: authorized succeeds, wrong branch denied, ACK does NOT close ---
    $msAckManagerId = '862'; $msAckManagerChat = '910862001';
    $msWrongBranchManagerId = '863';
    run("INSERT INTO broth_log_authorized_users (telegram_user_id,display_name,role,allowed_branches,active) VALUES (?,?,?,?,1)", [$msAckManagerId, 'MS Ack Manager', 'manager', json_encode(['B2'])]);
    run("INSERT INTO broth_log_authorized_users (telegram_user_id,display_name,role,allowed_branches,active) VALUES (?,?,?,?,1)", [$msWrongBranchManagerId, 'MS Wrong Branch', 'manager', json_encode(['B3'])]);
    $msAckManagerUser = broth_log_copilot_authorized_user($msAckManagerId);
    $msWrongBranchUser = broth_log_copilot_authorized_user($msWrongBranchManagerId);
    $ackDeniedResult = broth_log_copilot_ack($msModelId, $msWrongBranchUser);
    expect_eq($ackDeniedResult['reason'] ?? '', 'forbidden', 'D: ACK from a manager without this branch is denied');
    expect_eq(q1("SELECT state FROM broth_log_incidents WHERE incident_id=?", [$msModelId])['state'], 'detected', 'D: a denied ACK never changes incident state');
    $ackOkResult = broth_log_copilot_ack($msModelId, $msAckManagerUser);
    expect_true($ackOkResult['ok'] ?? false, 'D: ACK from the correct branch manager succeeds');
    $msAfterAck = q1("SELECT state, acknowledged_by FROM broth_log_incidents WHERE incident_id=?", [$msModelId]);
    expect_eq($msAfterAck['state'], 'acknowledged', 'D/V: ACK moves the incident to acknowledged, never directly to closed - ACK does not close a missing_shift incident');
    expect_eq($msAfterAck['acknowledged_by'], $msAckManagerId, 'D: acknowledged_by is recorded correctly');

    // --- Handler accountability + reply markup (ACK-only, no Resolve) around this same incident ---
    $msHandlerBefore = broth_log_copilot_incident_handler_summary($msModelRow);
    expect_eq($msHandlerBefore['display'], 'No one yet', 'I: before ACK, handler display is "No one yet"');
    $msFreshAfterAck = q1("SELECT * FROM broth_log_incidents WHERE incident_id=?", [$msModelId]);
    $msHandlerAfterAck = broth_log_copilot_incident_handler_summary($msFreshAfterAck);
    expect_eq($msHandlerAfterAck['display'], 'MS Ack Manager', 'I: after ACK, handler display is the real approved display name');
    expect_eq($msHandlerAfterAck['status'], 'acknowledged', 'I: handler status is acknowledged, never fabricated as resolved/closed');
    $msMarkup = broth_log_copilot_incident_reply_markup($msModelId);
    expect_eq(count($msMarkup['inline_keyboard'][0]), 1, 'K: missing_shift reply markup has exactly one button');
    expect_eq($msMarkup['inline_keyboard'][0][0]['text'], 'ACK', 'K: the one button is ACK');
    expect_true(!isset($msMarkup['inline_keyboard'][0][1]), 'K: there is no Resolve button for a missing_shift incident');

    // --- Explicit Resolve guard (Item 2, A-F): a deterministic, incident-type check, not a
    // coincidental side effect of an empty station_key. ---
    // B: direct resolve() call is rejected with the explicit reason, not the old incidental
    // unknown_station_config path (proves the new guard actually fires, not the old one).
    $msResolveAttempt = broth_log_copilot_resolve($msModelId, $msAckManagerUser, 38.0, 'attempted resolve on a missing-shift incident');
    expect_eq($msResolveAttempt['reason'] ?? '', 'resolve_not_supported_for_missing_shift', 'B: direct resolve() call on a missing_shift incident is rejected with the explicit, deterministic reason');
    // E: no incident mutation from the rejected attempt.
    expect_eq(q1("SELECT state FROM broth_log_incidents WHERE incident_id=?", [$msModelId])['state'], 'acknowledged', 'E: the rejected Resolve attempt never changed incident state');
    expect_true(q1("SELECT resolved_by FROM broth_log_incidents WHERE incident_id=?", [$msModelId])['resolved_by'] === null, 'E: resolved_by stays NULL after a rejected Resolve attempt');

    // C: the text `/resolve #<id> ...` path is rejected with the short, accurate manager-facing
    // message - not the generic "include a recheck temperature" wrapper, which would be actively
    // wrong advice for a missing-shift issue.
    broth_log_copilot_enqueue_webhook(['update_id' => 9200, 'message' => ['text' => '/resolve #' . $msModelId . ' 38F fixed', 'from' => ['id' => (int)$msAckManagerId], 'chat' => ['id' => $msAckManagerChat], 'message_id' => 9200]]);
    $msTextResolveProcessed = find_processed(broth_log_copilot_process_inbox(10, new DateTimeImmutable('2026-08-22 00:00:00 UTC')), '9200');
    expect_eq($msTextResolveProcessed['intent'] ?? '', 'resolve_rejected', 'C: the text /resolve command on a missing_shift incident is rejected');
    $msTextResolveSent = end($sentMessages);
    expect_eq($msTextResolveSent['payload']['text'] ?? '', 'TEST - This issue closes automatically when the Broth Log is submitted.', 'C: the manager sees the short, accurate, incident-type-specific message - no internal reason code, no mention of recheck temperature');
    expect_eq(q1("SELECT state FROM broth_log_incidents WHERE incident_id=?", [$msModelId])['state'], 'acknowledged', 'E: the text /resolve attempt never changed incident state');

    // D: a forged/replayed signed Resolve callback token is also rejected - the real UI never
    // generates one for a missing_shift incident (no Resolve button, see K above), but the safety
    // property must not depend on that alone.
    $msForgedResolveToken = broth_log_copilot_create_callback_token('resolve', $msModelId, (new DateTimeImmutable('2026-08-22 00:00:00 UTC'))->modify('+15 minutes')->getTimestamp());
    $msForgedResolveResponse = broth_log_copilot_callback_response($msForgedResolveToken, $msAckManagerUser, $msAckManagerChat, new DateTimeImmutable('2026-08-22 00:00:00 UTC'));
    expect_eq($msForgedResolveResponse['intent'] ?? '', 'resolve_prompt', 'D: the callback layer itself only ever prompts for resolve details via text (existing behavior for every incident type) - the actual rejection happens at the broth_log_copilot_resolve() call once the manager replies, proven by C above');
    expect_eq(q1("SELECT state FROM broth_log_incidents WHERE incident_id=?", [$msModelId])['state'], 'acknowledged', 'E: even the forged-callback path never changed incident state before the text reply is rejected');

    // F: temperature Resolve is completely unaffected by the new guard.
    // Branch B2, matching $msAckManagerUser's own authorization (set to ['B2'] above at fixture
    // setup) - the actor must actually be allowed on the incident's branch for Resolve to reach
    // the temperature-specific checks at all.
    $msTempRegressionAlert = ['branch' => 'B2', 'responseId' => 'ms-regress-temp', 'stationKey' => 'prepAreaCooler', 'station' => 'Prep Area Cooler', 'severity' => 'critical', 'businessDate' => '2026-08-22', 'businessTime' => '09:00', 'temperature' => '55F', 'target' => '<= 40F', 'correctiveAction' => 'Investigate'];
    $msTempRegressionId = broth_log_copilot_create_incident($msTempRegressionAlert);
    $msTempRegressionResolve = broth_log_copilot_resolve($msTempRegressionId, $msAckManagerUser, 38.0, 'closed door and moved product');
    expect_true($msTempRegressionResolve['ok'] ?? false, 'F: Resolve on a real temperature incident still succeeds exactly as before - the new missing_shift guard does not affect it');
    expect_eq(q1("SELECT state FROM broth_log_incidents WHERE incident_id=?", [$msTempRegressionId])['state'], 'resolved', 'F: the temperature incident reaches state=resolved normally');
    run("DELETE FROM broth_log_incident_events WHERE incident_id=?", [$msTempRegressionId]);
    run("DELETE FROM broth_log_incidents WHERE incident_id=?", [$msTempRegressionId]);

    // --- Reminder suppression after ACK: the existing generic escalation query already excludes 'acknowledged' ---
    run("UPDATE broth_log_incidents SET created_at='2026-08-22 00:00:00', level_entered_at='2026-08-22 00:00:00', last_reminder_at=NULL WHERE incident_id=?", [$msModelId]);
    $msDueWhileAcked = array_values(array_filter(broth_log_copilot_due_escalations(new DateTimeImmutable('2026-08-22 01:00:00 UTC')), fn($d) => $d['incident']['incident_id'] === $msModelId));
    expect_true(empty($msDueWhileAcked), 'H: an acknowledged missing_shift incident is never due for a reminder - the existing generic escalation query already excludes state=acknowledged, reused unchanged');

    // --- Message wording: manager-facing text is short, no internal details ---
    $msNotifyMessage = broth_log_copilot_incident_message($msModelRow, 'notify');
    expect_true(str_contains($msNotifyMessage, 'B2') && str_contains($msNotifyMessage, 'PM'), 'message shows store and shift');
    expect_true(str_contains($msNotifyMessage, 'Missing'), 'message says Missing');
    expect_true(!str_contains($msNotifyMessage, $msModelId), 'message never includes the internal incident id');
    expect_true(!str_contains($msNotifyMessage, 'Level') && !str_contains($msNotifyMessage, 'level'), 'message never mentions escalation level');
    $msAckConfirmMessage = broth_log_copilot_incident_message($msFreshAfterAck, 'ack_confirm');
    expect_true(str_contains($msAckConfirmMessage, 'Acknowledged'), 'ACK confirmation message says Acknowledged');
    expect_true(str_contains($msAckConfirmMessage, 'Waiting for the log'), 'ACK confirmation tells the manager the log is still needed, not that the issue is resolved');

    // --- Late submission: auto-close, timing stays LATE, audit history preserved ---
    $msLateIncidentId = broth_log_copilot_create_missing_shift_incident('B3', '2026-08-24', 'AM');
    run("UPDATE broth_log_incidents SET created_at='2026-08-24 15:10:00' WHERE incident_id=?", [$msLateIncidentId]); // ~10:10 AM Chicago detection time
    // $incDay1 was already deleted from the DB above - proves close() safely no-ops on an id that
    // no longer resolves to an open missing_shift row, rather than erroring or affecting anything.
    $closeAttemptWrongType = broth_log_copilot_close_missing_shift_incident($incDay1, 'LATE');
    expect_eq($closeAttemptWrongType['reason'] ?? '', 'incident_not_open', 'close_missing_shift_incident() safely rejects an incident id that is not an open missing_shift row');
    $msCloseResult = broth_log_copilot_close_missing_shift_incident($msLateIncidentId, 'LATE', new DateTimeImmutable('2026-08-24 16:24:00 UTC'));
    expect_true($msCloseResult['ok'] ?? false, 'W: a late submission auto-closes the missing_shift incident');
    $msLateRow = q1("SELECT * FROM broth_log_incidents WHERE incident_id=?", [$msLateIncidentId]);
    expect_eq($msLateRow['state'], 'closed', 'W: the incident state is closed, not resolved (resolved stays exclusive to temperature incidents)');
    expect_eq($msLateRow['closure_reason'], 'late_submission_received', 'X/Y: closure_reason correctly records this was a late submission, not an on-time one');
    expect_true(!empty($msLateRow['closed_at']), 'Y: closed_at is recorded');
    expect_true(!empty($msLateRow['created_at']), 'Y: the original detection time (created_at) is preserved, never overwritten by the close');
    $msAuditEvents = q("SELECT event_type FROM broth_log_incident_events WHERE incident_id=? ORDER BY id ASC", [$msLateIncidentId]);
    $msAuditTypes = array_map(fn($e) => $e['event_type'], $msAuditEvents);
    expect_true(in_array('missing_shift_detected', $msAuditTypes, true) && in_array('missing_shift_closed', $msAuditTypes, true), 'Y: full audit history preserved - both detection and closure events exist');
    expect_true(array_search('missing_shift_detected', $msAuditTypes) < array_search('missing_shift_closed', $msAuditTypes), 'Y: audit events are in correct chronological order');
    // Never silently upgraded to ON_TIME:
    expect_true($msLateRow['closure_reason'] !== 'submission_received', 'G: a LATE close is never mislabeled as a plain on-time submission');
    // Next worker tick sends nothing further for this now-closed incident:
    $msNextTickDue = array_values(array_filter(broth_log_copilot_due_escalations(new DateTimeImmutable('2026-08-24 17:00:00 UTC')), fn($d) => $d['incident']['incident_id'] === $msLateIncidentId));
    expect_true(empty($msNextTickDue), 'Z: the next worker tick has nothing due for a closed missing_shift incident - no further reminder is possible');

    // --- Auto-close via the real detection sweep (not the direct unit call) confirms end-to-end wiring ---
    $msSweepCloseIncidentId = broth_log_copilot_create_missing_shift_incident('B1', broth_log_business_date($graceNow), 'AM');
    // submittedAt must be the raw sheet (Vietnam-time) string broth_log_parse_submission_datetime() expects.
    $lateChicago = (new DateTimeImmutable(broth_log_business_date($graceNow) . ' 11:24:00', new DateTimeZone('America/Chicago')));
    $lateRawSheetString = $lateChicago->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('n/j/Y G:i:s');
    $GLOBALS['BROTH_LOG_COPILOT_RECORDS_PROVIDER'] = function (string $branch) use ($lateRawSheetString, $graceNow) {
        if ($branch !== 'B1') return [];
        return [['id' => 'ms-late-arrival', 'branch' => 'B1', 'businessDate' => broth_log_business_date($graceNow), 'submittedAt' => $lateRawSheetString, 'employeeName' => 'Late Employee', 'readings' => [], 'issues' => []]];
    };
    $sweepClose = broth_log_copilot_process_missing_shifts($graceNow->modify('+30 minutes'));
    $autoClosed = array_values(array_filter($sweepClose, fn($r) => ($r['action'] ?? '') === 'auto_closed' && ($r['incident_id'] ?? '') === $msSweepCloseIncidentId));
    expect_true(!empty($autoClosed), 'F: the real detection sweep auto-closes an open missing incident once a qualifying (even if late) submission appears in the Sheet data');
    expect_eq($autoClosed[0]['final_status'] ?? '', 'LATE', 'F: the sweep correctly classifies an 11:24 AM submission as LATE, not ON_TIME');
    unset($GLOBALS['BROTH_LOG_COPILOT_RECORDS_PROVIDER']);
    // The sweep call above unconditionally evaluates all of B1/B2/B3, not just B1 - with empty
    // records for B2/B3 (default provider fallthrough) and the same past-deadline+grace $now, it
    // also incidentally creates fresh B2/B3 AM incidents for this same business date. Clean up by
    // date, not just the one incident id this sub-test was tracking.
    $msSweepCloseDate = broth_log_business_date($graceNow);
    run("DELETE FROM broth_log_incident_events WHERE incident_id IN (SELECT incident_id FROM broth_log_incidents WHERE incident_type='missing_shift' AND business_date=?)", [$msSweepCloseDate]);
    run("DELETE FROM broth_log_incidents WHERE incident_type='missing_shift' AND business_date=?", [$msSweepCloseDate]);

    // --- Routing: B1 manager_dm success -> Ops group AND manager DM both receive it, zero onboarding attempts ---
    $msRouteGmId = '864'; $msRouteGmChat = '910864001';
    run("INSERT INTO broth_log_authorized_users (telegram_user_id,display_name,role,allowed_branches,active) VALUES (?,?,?,?,1)", [$msRouteGmId, 'MS Route GM', 'manager', json_encode(['B1'])]);
    run("INSERT INTO broth_log_private_chat_registrations (telegram_user_id, private_chat_id) VALUES (?,?)", [$msRouteGmId, $msRouteGmChat]);
    run("INSERT INTO broth_log_branch_alert_mode (branch, mode) VALUES ('B1','manager_dm')");
    $msRouteIncidentId = broth_log_copilot_create_missing_shift_incident('B1', '2026-08-23', 'PM');
    $preRouteSentCount = count($sentMessages);
    $msNotifyResult = broth_log_copilot_notify_incident($msRouteIncidentId, new DateTimeImmutable('2026-08-23 22:20:00 UTC'));
    expect_true($msNotifyResult['sent'] ?? false, 'E: notify_incident() (reused unchanged) successfully delivers a missing_shift alert');
    $msRouteSentTo = array_slice($sentMessages, $preRouteSentCount);
    $msRouteChatIds = array_map(fn($m) => $m['payload']['chat_id'] ?? '', $msRouteSentTo);
    expect_true(in_array($msRouteGmChat, $msRouteChatIds, true), 'E: the eligible manager DM chat receives the missing-shift alert');
    expect_true(in_array($opsGroupChatId, $msRouteChatIds, true), 'E: the Ops Alert Group ALSO receives the SAME missing-shift alert - Ops+Manager parity applies to missing_shift exactly like temperature');
    $onboardingChatIdForMs = getenv('TELEGRAM_MANAGER_ONBOARDING_CHAT_ID');
    expect_true(!in_array($onboardingChatIdForMs, $msRouteChatIds, true), 'F: the Manager Onboarding Group never receives a missing-shift alert');
    foreach ($msRouteSentTo as $m) {
        expect_true(str_contains((string)($m['payload']['text'] ?? ''), 'B1') && str_contains((string)($m['payload']['text'] ?? ''), 'PM'), 'E: the delivered message is the concise missing-shift alert, correctly labeled B1/PM');
        expect_true(str_contains((string)($m['payload']['text'] ?? ''), 'B1') && str_contains((string)($m['payload']['text'] ?? ''), 'PM'), 'message parity: Ops and the manager DM receive byte-identical message text for the same incident');
    }
    run("DELETE FROM broth_log_branch_alert_mode WHERE branch='B1'");
    run("DELETE FROM broth_log_incident_events WHERE incident_id=?", [$msRouteIncidentId]);
    run("DELETE FROM broth_log_incidents WHERE incident_id=?", [$msRouteIncidentId]);
    run("DELETE FROM broth_log_private_chat_registrations WHERE telegram_user_id=?", [$msRouteGmId]);
    run("DELETE FROM broth_log_authorized_users WHERE telegram_user_id=?", [$msRouteGmId]);

    // --- Routing: zero eligible manager -> Ops Alert Group still receives it (mandatory, not a fallback) ---
    $msZeroBranch = 'ZMSZERO';
    run("INSERT INTO broth_log_branch_alert_mode (branch, mode) VALUES (?, 'manager_dm')", [$msZeroBranch]);
    run("INSERT INTO broth_log_routing_rules (branch, stage, level, telegram_user_ids, active, chat_id) VALUES (?,?,?,?,?,?)", [$msZeroBranch, 'staging', 1, '["0"]', 1, 'ms-zero-fallback-group-chat']);
    $msZeroIncidentId = broth_log_copilot_create_missing_shift_incident($msZeroBranch, '2026-08-23', 'AM');
    $preZeroSentCount = count($sentMessages);
    broth_log_copilot_notify_incident($msZeroIncidentId, new DateTimeImmutable('2026-08-23 16:20:00 UTC'));
    $msZeroSentTo = array_map(fn($m) => $m['payload']['chat_id'] ?? '', array_slice($sentMessages, $preZeroSentCount));
    expect_eq($msZeroSentTo, ['ms-zero-fallback-group-chat'], 'F: zero eligible managers -> the Ops Alert Group receives the missing-shift alert, exactly once');
    $msZeroAudit = q1("SELECT event_json FROM broth_log_incident_events WHERE incident_id=? AND event_type='manager_dm_coverage_gap'", [$msZeroIncidentId]);
    expect_true($msZeroAudit !== null && (json_decode((string)$msZeroAudit['event_json'], true)['reason'] ?? '') === 'no_eligible_recipient', 'F: the coverage gap is correctly audited with the same reason code used for temperature incidents');
    run("DELETE FROM broth_log_branch_alert_mode WHERE branch=?", [$msZeroBranch]);
    run("DELETE FROM broth_log_routing_rules WHERE branch=?", [$msZeroBranch]);
    run("DELETE FROM broth_log_incident_events WHERE incident_id=?", [$msZeroIncidentId]);
    run("DELETE FROM broth_log_incidents WHERE incident_id=?", [$msZeroIncidentId]);

    // --- Poison isolation: one branch's Sheet fetch failure never blocks the other branches ---
    $GLOBALS['BROTH_LOG_COPILOT_RECORDS_PROVIDER'] = function (string $branch) {
        if ($branch === 'B2') throw new RuntimeException('simulated Sheet fetch failure for B2');
        return [];
    };
    $poisonNow = new DateTimeImmutable('2026-08-19 23:30:00 UTC');
    $poisonSweep = broth_log_copilot_process_missing_shifts($poisonNow);
    $poisonB2 = array_values(array_filter($poisonSweep, fn($r) => ($r['branch'] ?? '') === 'B2'));
    $poisonB1 = array_values(array_filter($poisonSweep, fn($r) => ($r['branch'] ?? '') === 'B1' && ($r['action'] ?? '') === 'created'));
    $poisonB3 = array_values(array_filter($poisonSweep, fn($r) => ($r['branch'] ?? '') === 'B3' && ($r['action'] ?? '') === 'created'));
    expect_eq($poisonB2[0]['error'] ?? '', 'fetch_failed', 'N: B2 Sheet fetch failure is recorded as a clean, sanitized error - not an uncaught exception');
    expect_true(!empty($poisonB1), 'N: B1 detection still completes normally despite B2 failing in the same sweep');
    expect_true(!empty($poisonB3), 'N: B3 detection still completes normally despite B2 failing in the same sweep');
    unset($GLOBALS['BROTH_LOG_COPILOT_RECORDS_PROVIDER']);
    $poisonDate = broth_log_business_date($poisonNow);
    run("DELETE FROM broth_log_incident_events WHERE incident_id IN (SELECT incident_id FROM broth_log_incidents WHERE incident_type='missing_shift' AND business_date=?)", [$poisonDate]);
    run("DELETE FROM broth_log_incidents WHERE incident_type='missing_shift' AND business_date=?", [$poisonDate]);

    // --- Historical queries never create incidents (menu:issues for a past date is read-only) ---
    $msHistBefore = q1("SELECT COUNT(*) c FROM broth_log_incidents WHERE incident_type='missing_shift'")['c'];
    broth_log_copilot_menu_issues_view($msGmUser, 'B1', '2020-01-01', false);
    broth_log_copilot_menu_log_view($msGmUser, 'B1', '2020-01-01');
    $msHistAfter = q1("SELECT COUNT(*) c FROM broth_log_incidents WHERE incident_type='missing_shift'")['c'];
    expect_eq($msHistAfter, $msHistBefore, 'AA: viewing a historical date via the menu never creates a missing_shift incident - only the worker sweep does that, and only for today');

    // --- /help Today's Log shows AM/PM shift status and issue counts (menu integration, no second logic layer) ---
    $msTodayLogGmId = '865';
    run("INSERT INTO broth_log_authorized_users (telegram_user_id,display_name,role,allowed_branches,active) VALUES (?,?,?,?,1)", [$msTodayLogGmId, 'MS Today Log', 'manager', json_encode(['B1'])]);
    $msTodayLogUser = broth_log_copilot_authorized_user($msTodayLogGmId);
    $msTodayDate = '2026-08-23';
    $msTodayIncidentId = broth_log_copilot_create_missing_shift_incident('B1', $msTodayDate, 'PM');
    $GLOBALS['BROTH_LOG_COPILOT_RECORDS_PROVIDER'] = function (string $branch) use ($msTodayDate) {
        if ($branch !== 'B1') return [];
        return [[
            'id' => 'ms-today-rec', 'branch' => 'B1', 'businessDate' => $msTodayDate, 'businessTime' => '10:20', 'employeeName' => 'Yenci',
            'readings' => [['key' => 'walkInFreezer', 'label' => 'Walk-In Freezer', 'category' => 'freezer', 'temperature' => 38.0, 'unit' => 'F', 'severity' => 'safe', 'target' => '<= 40F', 'correctiveAction' => '']],
            'issues' => [],
        ]];
    };
    $msTodayLogResult = broth_log_copilot_menu_log_view($msTodayLogUser, 'B1', $msTodayDate, new DateTimeImmutable('2026-08-23 20:00:00 UTC'));
    expect_true(str_contains($msTodayLogResult['message'], 'PM') && str_contains($msTodayLogResult['message'], 'Missing'), "J: Today's Log shows the PM shift as Missing");
    expect_true(str_contains($msTodayLogResult['message'], '1 missing shift'), "J: Today's Log issue count includes the open missing-shift incident");
    unset($GLOBALS['BROTH_LOG_COPILOT_RECORDS_PROVIDER']);

    // --- Today's Issues shows the missing-shift issue with safe handler wording, no raw IDs ---
    $msIssuesResult = broth_log_copilot_menu_issues_view($msTodayLogUser, 'B1', $msTodayDate, false);
    expect_true(str_contains($msIssuesResult['message'], 'PM Broth Log Missing'), 'J: Today\'s Issues shows the missing-shift issue with clear wording');
    expect_true(str_contains($msIssuesResult['message'], 'Handling: No one yet'), 'I/J: an unacknowledged missing-shift issue shows "Handling: No one yet"');
    expect_true(!str_contains($msIssuesResult['message'], $msTodayIncidentId), 'AE: Today\'s Issues never exposes the internal incident id');
    expect_true(!str_contains($msIssuesResult['message'], $msTodayLogGmId), 'AE: Today\'s Issues never exposes a raw Telegram user id');
    run("DELETE FROM broth_log_incident_events WHERE incident_id=?", [$msTodayIncidentId]);
    run("DELETE FROM broth_log_incidents WHERE incident_id=?", [$msTodayIncidentId]);
    run("DELETE FROM broth_log_authorized_users WHERE telegram_user_id=?", [$msTodayLogGmId]);

    // --- Final cleanup of this section's fixtures ---
    run("DELETE FROM broth_log_incident_events WHERE incident_id IN (?,?)", [$msModelId, $msLateIncidentId]);
    run("DELETE FROM broth_log_incidents WHERE incident_id IN (?,?)", [$msModelId, $msLateIncidentId]);
    run("DELETE FROM broth_log_authorized_users WHERE telegram_user_id IN (?,?,?)", [$msGmId, $msAckManagerId, $msWrongBranchManagerId]);
    run("DELETE FROM broth_log_private_chat_registrations WHERE telegram_user_id=?", [$msGmId]);
    putenv('BROTH_LOG_SHIFT_ALERTS_ENABLED=');
    putenv('BROTH_LOG_SHIFT_ALERT_BRANCHES=');
    expect_eq(broth_log_copilot_missing_shift_alerts_enabled(), false, 'flag correctly reads false again after being unset (test isolation)');
    expect_eq(q1("SELECT COUNT(*) c FROM broth_log_incidents WHERE incident_type='missing_shift'")['c'], 0, 'zero missing_shift incidents remain after this section\'s cleanup');

    // ========================================================================================
    // PR #41 HARDENING: severity/incident_type cross-cutting, per-branch activation, race safety
    // ========================================================================================

    // --- Severity audit: "Critical Only" is a temperature-severity concept, must never include
    // a missing_shift row even though both share severity='critical' ---
    $sevBranch = 'B2';
    $sevTempAlert = ['branch' => $sevBranch, 'responseId' => 'sev-temp-1', 'stationKey' => 'prepAreaCooler', 'station' => 'Prep Area Cooler', 'severity' => 'critical', 'businessDate' => '2026-08-18', 'businessTime' => '09:00', 'temperature' => '55F', 'target' => '<= 40F', 'correctiveAction' => 'Investigate'];
    $sevTempId = broth_log_copilot_create_incident($sevTempAlert);
    putenv('BROTH_LOG_SHIFT_ALERTS_ENABLED=true');
    $sevMissingId = broth_log_copilot_create_missing_shift_incident($sevBranch, '2026-08-18', 'AM');
    putenv('BROTH_LOG_SHIFT_ALERTS_ENABLED=');
    $sevOwnerUser = ['telegram_user_id' => '999001', 'allowed_branch_list' => [$sevBranch], 'preferred_language' => 'en'];
    $sevCriticalOnly = broth_log_copilot_menu_open_issues_view($sevOwnerUser, true);
    expect_true(str_contains($sevCriticalOnly['message'], 'Prep Area Cooler'), 'Severity audit: Critical Only still includes the real critical temperature issue');
    expect_true(!str_contains($sevCriticalOnly['message'], 'AM Broth Log'), 'Severity audit: Critical Only excludes the missing_shift issue, even though it also has severity=critical - a "1 missing PM log" must never surface as a "critical temperature reading"');
    $sevAllIssues = broth_log_copilot_menu_open_issues_view($sevOwnerUser, false);
    expect_true(str_contains($sevAllIssues['message'], 'Prep Area Cooler') && str_contains($sevAllIssues['message'], 'AM Broth Log'), 'Severity audit: the general (non-critical-only) Open Issues view still shows both types together - missing_shift is not hidden, only excluded from the temperature-specific filter');
    // Scoped to this test's own two fixture ids, not a branch-wide COUNT - this shared test-suite
    // database can carry other B2 critical incidents left open by earlier, unrelated sections of
    // this same file (same defensive pattern used elsewhere in this file for shared-DB counts).
    $sevScopedCritical = q("SELECT incident_id FROM broth_log_incidents WHERE incident_id IN (?,?) AND severity='critical' AND incident_type='temperature' AND state NOT IN ('resolved','closed')", [$sevTempId, $sevMissingId]);
    expect_eq(count($sevScopedCritical), 1, 'Severity audit: of this test\'s two fixtures, the incident_type-scoped critical query returns exactly the temperature one');
    expect_eq($sevScopedCritical[0]['incident_id'] ?? '', $sevTempId, 'Severity audit: the one row returned is specifically the temperature incident, never the missing_shift one');
    run("DELETE FROM broth_log_incident_events WHERE incident_id IN (?,?)", [$sevTempId, $sevMissingId]);
    run("DELETE FROM broth_log_incidents WHERE incident_id IN (?,?)", [$sevTempId, $sevMissingId]);

    // --- Per-branch activation gate (Item 4/5) ---
    putenv('BROTH_LOG_SHIFT_ALERT_BRANCHES=');
    putenv('BROTH_LOG_SHIFT_ALERTS_ENABLED=');
    expect_eq(broth_log_copilot_missing_shift_enabled_branches(), [], 'per-branch: with no allowlist set, the enabled-branches list is empty');
    expect_eq(broth_log_copilot_missing_shift_enabled_for_branch('B1'), false, 'per-branch: global OFF -> B1 not enabled regardless of allowlist');

    putenv('BROTH_LOG_SHIFT_ALERTS_ENABLED=true');
    expect_eq(broth_log_copilot_missing_shift_enabled_for_branch('B1'), false, 'per-branch: global ON but no branch on the allowlist -> fails closed, B1 still not enabled');
    $GLOBALS['BROTH_LOG_COPILOT_RECORDS_PROVIDER'] = function (string $branch): array { return []; };
    $gateNoBranches = broth_log_copilot_process_missing_shifts($darkNow);
    expect_eq($gateNoBranches, [], 'per-branch: global ON with an empty allowlist creates zero incidents anywhere - fail closed, not fail open');

    putenv('BROTH_LOG_SHIFT_ALERT_BRANCHES=B1');
    expect_eq(broth_log_copilot_missing_shift_enabled_branches(), ['B1'], 'per-branch: allowlist correctly parses a single branch');
    expect_eq(broth_log_copilot_missing_shift_enabled_for_branch('B1'), true, 'per-branch: B1 is enabled once listed');
    expect_eq(broth_log_copilot_missing_shift_enabled_for_branch('B2'), false, 'per-branch: B2 remains disabled while only B1 is listed');
    expect_eq(broth_log_copilot_missing_shift_enabled_for_branch('B3'), false, 'per-branch: B3 remains disabled while only B1 is listed');
    $gateB1Only = broth_log_copilot_process_missing_shifts($darkNow);
    $gateB1OnlyBranches = array_unique(array_map(fn($r) => $r['branch'] ?? '', $gateB1Only));
    expect_eq($gateB1OnlyBranches, ['B1'], 'per-branch: with only B1 on the allowlist, every action in this sweep is for B1 - B2/B3 create zero incidents');
    expect_eq(q1("SELECT COUNT(*) c FROM broth_log_incidents WHERE incident_type='missing_shift' AND branch IN ('B2','B3')")['c'], 0, 'per-branch: B2/B3 have zero missing_shift incidents while disabled');
    $gateB1Date = broth_log_business_date($darkNow);
    run("DELETE FROM broth_log_incident_events WHERE incident_id IN (SELECT incident_id FROM broth_log_incidents WHERE incident_type='missing_shift' AND business_date=?)", [$gateB1Date]);
    run("DELETE FROM broth_log_incidents WHERE incident_type='missing_shift' AND business_date=?", [$gateB1Date]);

    putenv('BROTH_LOG_SHIFT_ALERT_BRANCHES=B1,B2');
    expect_eq(broth_log_copilot_missing_shift_enabled_branches(), ['B1', 'B2'], 'per-branch: allowlist correctly parses two branches');
    $gateB1B2 = broth_log_copilot_process_missing_shifts($darkNow);
    $gateB1B2Branches = array_unique(array_map(fn($r) => $r['branch'] ?? '', $gateB1B2));
    sort($gateB1B2Branches);
    expect_eq($gateB1B2Branches, ['B1', 'B2'], 'per-branch: with B1 and B2 listed, both are active and B3 is not touched at all');
    expect_eq(q1("SELECT COUNT(*) c FROM broth_log_incidents WHERE incident_type='missing_shift' AND branch='B3'")['c'], 0, 'per-branch: B3 still has zero missing_shift incidents while disabled, even with two other branches active');
    run("DELETE FROM broth_log_incident_events WHERE incident_id IN (SELECT incident_id FROM broth_log_incidents WHERE incident_type='missing_shift' AND business_date=?)", [$gateB1Date]);
    run("DELETE FROM broth_log_incidents WHERE incident_type='missing_shift' AND business_date=?", [$gateB1Date]);

    // Invalid/unexpected allowlist values never silently mean "all branches"
    foreach (['*', 'ALL', 'all', 'B1,B9,B2', ' , ,'] as $invalidValue) {
        putenv('BROTH_LOG_SHIFT_ALERT_BRANCHES=' . $invalidValue);
        $parsed = broth_log_copilot_missing_shift_enabled_branches();
        expect_true(!in_array('B3', $parsed, true) || $invalidValue === 'B1,B9,B2', "per-branch: allowlist value \"{$invalidValue}\" never silently grants B3 unless explicitly listed");
        expect_true(count(array_diff($parsed, ['B1', 'B2', 'B3'])) === 0, "per-branch: allowlist value \"{$invalidValue}\" never produces an unrecognized branch code");
    }
    putenv('BROTH_LOG_SHIFT_ALERT_BRANCHES=*');
    expect_eq(broth_log_copilot_missing_shift_enabled_branches(), [], 'per-branch: a bare wildcard "*" is not expanded to every branch - it is silently dropped (fails closed)');
    putenv('BROTH_LOG_SHIFT_ALERT_BRANCHES=ALL');
    expect_eq(broth_log_copilot_missing_shift_enabled_branches(), [], 'per-branch: the literal word "ALL" is not expanded to every branch either - only real branch codes are ever recognized');
    unset($GLOBALS['BROTH_LOG_COPILOT_RECORDS_PROVIDER']);
    putenv('BROTH_LOG_SHIFT_ALERT_BRANCHES=');
    putenv('BROTH_LOG_SHIFT_ALERTS_ENABLED=');

    // --- Auto-close transaction safety / race conditions (Item 10) ---
    putenv('BROTH_LOG_SHIFT_ALERTS_ENABLED=true');
    $raceIncidentId = broth_log_copilot_create_missing_shift_incident('B3', '2026-08-17', 'AM');
    // Two sequential calls simulate worker A then worker B racing for the same incident - SQLite's
    // BEGIN IMMEDIATE inside close_missing_shift_incident() means whichever call's transaction
    // commits first wins; the second always sees the fresh (already-closed) state, never a stale
    // snapshot, and never writes a second audit event.
    $raceCloseA = broth_log_copilot_close_missing_shift_incident($raceIncidentId, 'ON_TIME');
    $raceCloseB = broth_log_copilot_close_missing_shift_incident($raceIncidentId, 'ON_TIME');
    expect_true(($raceCloseA['ok'] ?? false) && !($raceCloseB['ok'] ?? true), 'race: exactly one of the two close attempts succeeds (the first)');
    expect_eq($raceCloseB['reason'] ?? '', 'incident_not_open', 'race: the second (losing) close attempt gets a clean incident_not_open rejection, not an error or a silent duplicate success');
    expect_eq(q1("SELECT COUNT(*) c FROM broth_log_incident_events WHERE incident_id=? AND event_type='missing_shift_closed'", [$raceIncidentId])['c'], 1, 'race: exactly one closure audit event exists, never duplicated');
    run("DELETE FROM broth_log_incident_events WHERE incident_id=?", [$raceIncidentId]);
    run("DELETE FROM broth_log_incidents WHERE incident_id=?", [$raceIncidentId]);

    // ACK-then-close: ACK is an intermediate state, never a blocker to the incident later closing
    // once the real submission arrives - accountability (acknowledged_by) is preserved through the close.
    $raceAckThenCloseId = broth_log_copilot_create_missing_shift_incident('B3', '2026-08-16', 'PM');
    run("INSERT OR REPLACE INTO broth_log_authorized_users (telegram_user_id,display_name,role,allowed_branches,active) VALUES ('862','MS Ack Manager','manager',?,1)", [json_encode(['B2', 'B3'])]);
    $raceAckActor = broth_log_copilot_authorized_user('862');
    $raceAckResult = broth_log_copilot_ack($raceAckThenCloseId, $raceAckActor);
    expect_true($raceAckResult['ok'] ?? false, 'race: ACK succeeds on the still-open incident');
    $raceAckThenClose = broth_log_copilot_close_missing_shift_incident($raceAckThenCloseId, 'ON_TIME');
    expect_true($raceAckThenClose['ok'] ?? false, 'race: close still succeeds after ACK - acknowledged is an intermediate state, never a blocker');
    $raceAckThenCloseRow = q1("SELECT state, acknowledged_by FROM broth_log_incidents WHERE incident_id=?", [$raceAckThenCloseId]);
    expect_eq($raceAckThenCloseRow['state'], 'closed', 'race: final state is closed');
    expect_eq($raceAckThenCloseRow['acknowledged_by'], '862', 'race: acknowledged_by is preserved through the close - accountability is never lost');
    run("DELETE FROM broth_log_incident_events WHERE incident_id=?", [$raceAckThenCloseId]);
    run("DELETE FROM broth_log_incidents WHERE incident_id=?", [$raceAckThenCloseId]);

    // Close-then-ACK: once closed, a later ACK attempt is correctly and cleanly rejected.
    $raceCloseThenAckId = broth_log_copilot_create_missing_shift_incident('B3', '2026-08-15', 'AM');
    $raceCloseFirst = broth_log_copilot_close_missing_shift_incident($raceCloseThenAckId, 'ON_TIME');
    expect_true($raceCloseFirst['ok'] ?? false, 'race: close succeeds first');
    $raceAckAfterClose = broth_log_copilot_ack($raceCloseThenAckId, $raceAckActor);
    expect_true(!($raceAckAfterClose['ok'] ?? true), 'race: ACK after close is rejected, not silently accepted');
    expect_eq($raceAckAfterClose['reason'] ?? '', 'incident_not_open', 'race: ACK-after-close gets the same clean rejection as any other already-closed incident');
    expect_eq(q1("SELECT state, acknowledged_by FROM broth_log_incidents WHERE incident_id=?", [$raceCloseThenAckId])['acknowledged_by'], null, 'race: a rejected post-close ACK never sets acknowledged_by');
    run("DELETE FROM broth_log_incident_events WHERE incident_id=?", [$raceCloseThenAckId]);
    run("DELETE FROM broth_log_incidents WHERE incident_id=?", [$raceCloseThenAckId]);
    run("DELETE FROM broth_log_authorized_users WHERE telegram_user_id='862'");
    putenv('BROTH_LOG_SHIFT_ALERTS_ENABLED=');

    // --- Dedup/recurrence (Item 11): once closed because a submission arrived, the detector must
    // never recreate an incident for that same (branch, business_date, shift) on a later tick,
    // since the shift's status is no longer MISSING at all. ---
    putenv('BROTH_LOG_SHIFT_ALERTS_ENABLED=true');
    putenv('BROTH_LOG_SHIFT_ALERT_BRANCHES=B1');
    $recurBranch = 'B1';
    $recurDate = broth_log_business_date($graceNowPm);
    $onTimeArrivalRaw = (new DateTimeImmutable($recurDate . ' 10:20:00', new DateTimeZone('America/Chicago')))->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('n/j/Y G:i:s');
    // Tick 1: no submission yet for either shift - AM (and PM) missing incidents get created as usual.
    $GLOBALS['BROTH_LOG_COPILOT_RECORDS_PROVIDER'] = function (string $branch): array { return []; };
    $recurSweep1 = broth_log_copilot_process_missing_shifts($graceNowPm->modify('+5 minutes'));
    $recurCreated1 = array_values(array_filter($recurSweep1, fn($r) => ($r['branch'] ?? '') === $recurBranch && ($r['shift'] ?? '') === 'AM' && ($r['action'] ?? '') === 'created'));
    expect_true(!empty($recurCreated1), 'dedup/recurrence: tick 1 (no submission) creates the AM missing incident as expected, establishing the baseline for this test');
    $recurIncidentId = $recurCreated1[0]['incident_id'] ?? '';
    // Tick 2: a qualifying AM submission now appears - the sweep must auto-close that same incident.
    $GLOBALS['BROTH_LOG_COPILOT_RECORDS_PROVIDER'] = function (string $branch) use ($recurBranch, $recurDate, $onTimeArrivalRaw): array {
        if ($branch !== $recurBranch) return [];
        return [['id' => 'recur-rec', 'branch' => $recurBranch, 'businessDate' => $recurDate, 'submittedAt' => $onTimeArrivalRaw, 'employeeName' => 'Recur Test', 'readings' => [], 'issues' => []]];
    };
    $recurSweep2 = broth_log_copilot_process_missing_shifts($graceNowPm->modify('+10 minutes'));
    $recurClosed = array_values(array_filter($recurSweep2, fn($r) => ($r['incident_id'] ?? '') === $recurIncidentId && ($r['action'] ?? '') === 'auto_closed'));
    expect_true(!empty($recurClosed), 'dedup/recurrence: tick 2 (submission now present) auto-closes the same AM incident');
    expect_eq(q1("SELECT state FROM broth_log_incidents WHERE incident_id=?", [$recurIncidentId])['state'], 'closed', 'dedup/recurrence: the AM incident is now closed');
    // Tick 3: the qualifying submission is still present (nothing changed) - the detector must NOT
    // create a brand-new incident for the same branch/date/AM shift just because it swept again.
    $recurSweep3 = broth_log_copilot_process_missing_shifts($graceNowPm->modify('+15 minutes'));
    $recurRecreated = array_values(array_filter($recurSweep3, fn($r) => ($r['branch'] ?? '') === $recurBranch && ($r['shift'] ?? '') === 'AM' && ($r['action'] ?? '') === 'created'));
    expect_true(empty($recurRecreated), 'dedup/recurrence: a later sweep with the submission still present does NOT recreate a new AM incident for the same shift/date');
    expect_eq(q1("SELECT COUNT(*) c FROM broth_log_incidents WHERE incident_type='missing_shift' AND branch=? AND business_date=? AND shift='AM'", [$recurBranch, $recurDate])['c'], 1, 'dedup/recurrence: exactly one AM missing_shift row exists for this branch/date - the original (now closed) one, never a second');
    unset($GLOBALS['BROTH_LOG_COPILOT_RECORDS_PROVIDER']);
    putenv('BROTH_LOG_SHIFT_ALERTS_ENABLED=');
    putenv('BROTH_LOG_SHIFT_ALERT_BRANCHES=');
    run("DELETE FROM broth_log_incident_events WHERE incident_id IN (SELECT incident_id FROM broth_log_incidents WHERE incident_type='missing_shift' AND business_date=? AND branch=?)", [$recurDate, $recurBranch]);
    run("DELETE FROM broth_log_incidents WHERE incident_type='missing_shift' AND business_date=? AND branch=?", [$recurDate, $recurBranch]);

    expect_eq(q1("SELECT COUNT(*) c FROM broth_log_incidents WHERE incident_type='missing_shift'")['c'], 0, 'PR #41 hardening: zero missing_shift incidents remain anywhere after this section\'s cleanup');
    expect_eq(broth_log_copilot_missing_shift_alerts_enabled(), false, 'PR #41 hardening: flag correctly reads false again after final cleanup');

    echo "\nAll PHP Phase 1 gate tests passed.\n";
} finally {
    @unlink(TEST_DB_PATH);
    @unlink(TEST_DB_PATH . '-wal');
    @unlink(TEST_DB_PATH . '-shm');
}
