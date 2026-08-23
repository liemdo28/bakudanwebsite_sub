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
    expect_eq(broth_log_copilot_resolve($incidentId, $user, 45, 'fixed')['reason'], 'recheck_still_unsafe', 'resolve rejects unsafe recheck');

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
    $pilotUnauthorizedId = '778899';
    $opsGroupChatId = 'ops-group-chat-for-pilotid-test';
    foreach (['B1', 'B2', 'B3'] as $pilotBranch) {
        for ($pilotLevel = 1; $pilotLevel <= 3; $pilotLevel++) {
            run("INSERT OR REPLACE INTO broth_log_routing_rules (branch,stage,level,telegram_user_ids,chat_id,active) VALUES (?,?,?,?,?,1)",
                [$pilotBranch, 'staging', $pilotLevel, json_encode(['999']), $opsGroupChatId]);
        }
    }
    expect_true(broth_log_copilot_is_production_ops_chat($opsGroupChatId), 'the configured routing destination is recognized as the production Ops chat');
    expect_true(!broth_log_copilot_is_production_ops_chat('some-unrelated-chat'), 'an arbitrary chat id is not recognized as the production Ops chat');

    // Regression: real Telegram group chat ids are numeric-looking strings (e.g. "-5367135326").
    // Using a chat id as a PHP array key (as an earlier draft did, to dedupe) silently coerces a
    // numeric-string key to a real int, which then fails hash_equals()'s strict string-only type
    // check with a TypeError - crashing the production worker on every tick as long as any
    // /pilotid message stayed queued, and starving the escalation-reminder loop that runs after
    // it in the same script. Caught only by testing with a genuinely numeric chat id, not the
    // non-numeric one used everywhere else in this test file.
    $numericOpsGroupChatId = '-5367135399';
    run("INSERT OR REPLACE INTO broth_log_routing_rules (branch,stage,level,telegram_user_ids,chat_id,active) VALUES (?,?,?,?,?,1)",
        ['B1', 'staging', 1, json_encode(['999']), $numericOpsGroupChatId]);
    expect_true(broth_log_copilot_is_production_ops_chat($numericOpsGroupChatId), 'a numeric-string chat id is matched correctly, with no TypeError from array-key coercion');
    broth_log_copilot_enqueue_webhook([
        'update_id' => 5099,
        'message' => ['text' => '/pilotid', 'from' => ['id' => (int)$pilotUnauthorizedId], 'chat' => ['id' => $numericOpsGroupChatId], 'message_id' => 599],
    ]);
    $pilotNumericChatProcessed = broth_log_copilot_process_inbox(10, new DateTimeImmutable('2026-08-22 00:00:00 UTC'));
    expect_eq(find_processed($pilotNumericChatProcessed, '5099')['status'] ?? '', 'processed', '/pilotid through a real numeric-shaped chat id is processed without crashing the worker');
    run("INSERT OR REPLACE INTO broth_log_routing_rules (branch,stage,level,telegram_user_ids,chat_id,active) VALUES (?,?,?,?,?,1)",
        ['B1', 'staging', 1, json_encode(['999']), $opsGroupChatId]);

    $authorizedUsersBefore = count(q("SELECT * FROM broth_log_authorized_users"));
    $routingSnapshotBefore = q("SELECT branch,stage,level,telegram_user_ids,chat_id,active FROM broth_log_routing_rules ORDER BY branch,stage,level");

    // 1 + 5: an unauthorized sender can run /pilotid, and only inside the real production Ops chat.
    expect_true(broth_log_copilot_authorized_user($pilotUnauthorizedId) === null, 'the pilotid test sender starts out unauthorized');
    broth_log_copilot_enqueue_webhook([
        'update_id' => 5001,
        'message' => ['text' => '/pilotid', 'from' => ['id' => (int)$pilotUnauthorizedId], 'chat' => ['id' => $opsGroupChatId], 'message_id' => 501],
    ]);
    $pilotProcessed1 = broth_log_copilot_process_inbox(10, new DateTimeImmutable('2026-08-22 00:00:00 UTC'));
    $pilotRow1 = find_processed($pilotProcessed1, '5001');
    expect_eq($pilotRow1['status'] ?? '', 'processed', 'unauthorized sender in the real Ops chat: /pilotid is processed, not denied');
    expect_eq($pilotRow1['intent'] ?? '', 'pilot_id', 'the processed row is tagged with the pilot_id intent');
    expect_eq($sentMessages[count($sentMessages) - 1]['payload']['text'], 'TEST - ' . broth_log_copilot_tr('pilot_id_received', 'en'), 'unauthorized /pilotid gets the identity-received waiting-for-approval reply');

    // 9 + 10: numeric sender id is captured internally in the inbox row, but never appears in the reply text.
    $pilotInboxRow1 = q1("SELECT telegram_user_id FROM broth_log_bot_inbox WHERE update_id='5001'");
    expect_eq($pilotInboxRow1['telegram_user_id'] ?? '', $pilotUnauthorizedId, 'the numeric sender id is captured internally in the inbox row');
    expect_true(!str_contains($sentMessages[count($sentMessages) - 1]['payload']['text'], $pilotUnauthorizedId), 'the numeric sender id never appears in the Telegram reply text');

    // 11 + 12: no authorization or routing mutation happened.
    expect_eq(count(q("SELECT * FROM broth_log_authorized_users")), $authorizedUsersBefore, 'no authorized-user row was created by /pilotid');
    expect_true(q("SELECT branch,stage,level,telegram_user_ids,chat_id,active FROM broth_log_routing_rules ORDER BY branch,stage,level") === $routingSnapshotBefore, 'no routing row was changed by /pilotid');

    // 2, 3, 4: the same still-unauthorized sender remains fully denied for every real command.
    broth_log_copilot_enqueue_webhook(['update_id' => 5002, 'message' => ['text' => 'today B1', 'from' => ['id' => (int)$pilotUnauthorizedId], 'chat' => ['id' => $opsGroupChatId], 'message_id' => 502]]);
    broth_log_copilot_enqueue_webhook(['update_id' => 5003, 'message' => ['text' => '/ack', 'from' => ['id' => (int)$pilotUnauthorizedId], 'chat' => ['id' => $opsGroupChatId], 'message_id' => 503]]);
    broth_log_copilot_enqueue_webhook(['update_id' => 5004, 'message' => ['text' => '/resolve', 'from' => ['id' => (int)$pilotUnauthorizedId], 'chat' => ['id' => $opsGroupChatId], 'message_id' => 504]]);
    $pilotProcessed2 = broth_log_copilot_process_inbox(10, new DateTimeImmutable('2026-08-22 00:00:00 UTC'));
    expect_eq(find_processed($pilotProcessed2, '5002')['status'] ?? '', 'denied', 'the same unauthorized sender still cannot run a real query command (today B1)');
    expect_eq(find_processed($pilotProcessed2, '5003')['status'] ?? '', 'denied', 'the same unauthorized sender still cannot ACK');
    expect_eq(find_processed($pilotProcessed2, '5004')['status'] ?? '', 'denied', 'the same unauthorized sender still cannot Resolve');

    // 6, 7, 8: /pilotid from any chat other than the real production Ops chat gets no special
    // treatment - it falls straight through to the same deny-by-default path as everything else.
    broth_log_copilot_enqueue_webhook(['update_id' => 5005, 'message' => ['text' => '/pilotid', 'from' => ['id' => (int)$pilotUnauthorizedId], 'chat' => ['id' => 'some-other-group-chat'], 'message_id' => 505]]);
    broth_log_copilot_enqueue_webhook(['update_id' => 5006, 'message' => ['text' => '/pilotid', 'from' => ['id' => (int)$pilotUnauthorizedId], 'chat' => ['id' => $pilotUnauthorizedId], 'message_id' => 506]]);
    broth_log_copilot_enqueue_webhook(['update_id' => 5007, 'message' => ['text' => '/pilotid', 'from' => ['id' => (int)$pilotUnauthorizedId], 'chat' => ['id' => 'other-staging-bot-chat'], 'message_id' => 507]]);
    $pilotProcessed3 = broth_log_copilot_process_inbox(10, new DateTimeImmutable('2026-08-22 00:00:00 UTC'));
    expect_eq(find_processed($pilotProcessed3, '5005')['status'] ?? '', 'denied', 'wrong (unknown) group is rejected for /pilotid, no special reply');
    expect_eq(find_processed($pilotProcessed3, '5006')['status'] ?? '', 'denied', 'a private DM (chat id == sender id) is rejected for /pilotid');
    expect_eq(find_processed($pilotProcessed3, '5007')['status'] ?? '', 'denied', 'an unrelated staging/other chat is rejected for /pilotid');

    // 13: repeated /pilotid from the same sender in the real Ops chat is idempotent.
    broth_log_copilot_enqueue_webhook(['update_id' => 5008, 'message' => ['text' => '/pilotid', 'from' => ['id' => (int)$pilotUnauthorizedId], 'chat' => ['id' => $opsGroupChatId], 'message_id' => 508]]);
    $pilotProcessed4 = broth_log_copilot_process_inbox(10, new DateTimeImmutable('2026-08-22 00:00:00 UTC'));
    $pilotRow4 = find_processed($pilotProcessed4, '5008');
    expect_eq($pilotRow4['status'] ?? '', 'processed', 'a second /pilotid from the same still-unauthorized sender is processed the same way');
    expect_eq($pilotRow4['intent'] ?? '', 'pilot_id', 'the repeated /pilotid is still tagged pilot_id');
    expect_eq($sentMessages[count($sentMessages) - 1]['payload']['text'], 'TEST - ' . broth_log_copilot_tr('pilot_id_received', 'en'), 'repeated /pilotid gets the identical waiting-for-approval reply, not a different one');
    expect_eq(count(q("SELECT * FROM broth_log_authorized_users")), $authorizedUsersBefore, 'repeating /pilotid still creates no authorized-user row');

    // 14: the bot-username-suffixed form also works.
    broth_log_copilot_enqueue_webhook(['update_id' => 5009, 'message' => ['text' => '/pilotid@brothlog_bot', 'from' => ['id' => (int)$pilotUnauthorizedId], 'chat' => ['id' => $opsGroupChatId], 'message_id' => 509]]);
    $pilotProcessed5 = broth_log_copilot_process_inbox(10, new DateTimeImmutable('2026-08-22 00:00:00 UTC'));
    expect_eq(find_processed($pilotProcessed5, '5009')['status'] ?? '', 'processed', '/pilotid@brothlog_bot (group-suffixed form) is recognized the same as /pilotid');
    expect_eq(find_processed($pilotProcessed5, '5009')['intent'] ?? '', 'pilot_id', '/pilotid@brothlog_bot is tagged with the pilot_id intent');

    // 15: arbitrary text mentioning "pilotid" does not trigger onboarding.
    expect_true(!broth_log_copilot_is_pilot_id_text('please run pilotid for me'), 'arbitrary text containing "pilotid" is not recognized as the onboarding command');
    expect_true(!broth_log_copilot_is_pilot_id_text('/pilotid now please'), 'trailing text after /pilotid is not recognized as the anchored onboarding command');
    broth_log_copilot_enqueue_webhook(['update_id' => 5010, 'message' => ['text' => 'please run pilotid for me', 'from' => ['id' => (int)$pilotUnauthorizedId], 'chat' => ['id' => $opsGroupChatId], 'message_id' => 510]]);
    $pilotProcessed6 = broth_log_copilot_process_inbox(10, new DateTimeImmutable('2026-08-22 00:00:00 UTC'));
    expect_eq(find_processed($pilotProcessed6, '5010')['status'] ?? '', 'denied', 'arbitrary text mentioning pilotid from an unauthorized sender is denied like any other unrecognized command, not treated as onboarding');

    // 16: an already-authorized user gets a harmless "already registered" reply, with zero mutation.
    expect_true(broth_log_copilot_authorized_user('101') !== null, 'sanity: telegram id 101 is the already-authorized B1 manager fixture');
    $existingUserBefore = q1("SELECT telegram_user_id,display_name,role,allowed_branches,active FROM broth_log_authorized_users WHERE telegram_user_id='101'");
    broth_log_copilot_enqueue_webhook(['update_id' => 5011, 'message' => ['text' => '/pilotid', 'from' => ['id' => 101], 'chat' => ['id' => $opsGroupChatId], 'message_id' => 511]]);
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
    $unsafeResolve = broth_log_copilot_resolve($dmAckIncidentId, $bobUser, 45.0, 'checked', $dmAckNow); // still above the <=40F cooler target
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

    // Cross-cutting: across every message sent by any test in this run (queries, incident
    // notifications, ACK/resolve confirmations, reminders, escalations), none was ever addressed
    // to the one-way-alert sentinel chat - proving Copilot's destination selection never leaks
    // into the legacy one-way critical-alert chat under any code path exercised above.
    $leakedToOneWayChat = array_filter($sentMessages, fn($m) => ($m['payload']['chat_id'] ?? '') === 'one-way-alert-chat-should-never-be-used-by-copilot');
    expect_eq(count($leakedToOneWayChat), 0, 'no message sent anywhere in this test run was ever addressed to the one-way-alert chat sentinel');

    echo "\nAll PHP Phase 1 gate tests passed.\n";
} finally {
    @unlink(TEST_DB_PATH);
    @unlink(TEST_DB_PATH . '-wal');
    @unlink(TEST_DB_PATH . '-shm');
}
