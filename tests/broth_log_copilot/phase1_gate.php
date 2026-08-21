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
    expect_true(str_contains($notification['text'], 'Store: B1'), 'incident notification includes store');
    expect_true(str_contains($notification['text'], 'Prep Area Cooler'), 'incident notification includes station');
    expect_true(str_contains($notification['text'], 'Recorded: 120F'), 'incident notification shows the correct recorded temperature (regression: was truncated to 12F)');
    expect_true(str_contains($notification['text'], 'Level: 1'), 'incident notification includes the escalation level');
    expect_true(str_contains($notification['text'], 'Employee: Unassigned'), 'incident notification falls back to Unassigned when no employee is known');
    expect_true(isset($notification['reply_markup']['inline_keyboard'][0][0]['callback_data']), 'incident notification includes inline ACK button');
    $ackData = $notification['reply_markup']['inline_keyboard'][0][0]['callback_data'];
    $resolveData = $notification['reply_markup']['inline_keyboard'][0][1]['callback_data'];
    expect_true(strlen($ackData) <= 64, 'ACK callback payload fits Telegram limit');
    expect_true(strlen($resolveData) <= 64, 'resolve callback payload fits Telegram limit');
    broth_log_copilot_notify_incident($incidentId);
    expect_eq(count($sentMessages), $sentBeforeIncident + 1, 'duplicate incident notification is suppressed');

    $employeeIncidentId = broth_log_copilot_create_incident(array_replace($alert, ['responseId' => 'resp-with-employee', 'employee' => 'Jordan Rivera']));
    broth_log_copilot_notify_incident($employeeIncidentId);
    expect_true(str_contains($sentMessages[count($sentMessages) - 1]['payload']['text'], 'Employee: Jordan Rivera'), 'incident notification shows the real employee name when known');

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

    $raceId = broth_log_copilot_create_incident(array_replace($alert, ['responseId' => 'resp-race']));
    run("UPDATE broth_log_incidents SET created_at='2026-08-20 00:00:00', level_entered_at='2026-08-20 00:00:00', last_reminder_at=NULL, reminder_count=0, state='detected', current_level=1 WHERE incident_id=?", [$raceId]);
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

    run("UPDATE broth_log_incidents SET created_at='2026-08-20 00:00:00', level_entered_at='2026-08-20 00:00:00', last_reminder_at='2026-08-20 00:06:00', reminder_count=3, state='notified_level_1', current_level=1 WHERE incident_id=?", [$raceId]);
    $dueEscalate = array_values(array_filter(broth_log_copilot_due_escalations(new DateTimeImmutable('2026-08-20 00:09:00 UTC')), fn($d) => $d['incident']['incident_id'] === $raceId))[0];
    expect_eq($dueEscalate['action'], 'escalate', 'fake-clock escalates level 1 to level 2 at minute nine');
    $escalate2Before = count($sentMessages);
    $escalate2Result = broth_log_copilot_apply_escalation_action_with_notification($dueEscalate, new DateTimeImmutable('2026-08-20 00:09:00 UTC'));
    expect_eq($escalate2Result['action'], 'escalated', 'worker applies the level 1 -> 2 escalation');
    expect_eq($escalate2Result['level'], 2, 'escalation moves the incident to level 2');

    // Regression: before level_entered_at existed, this check used total age-since-created, so once
    // that passed 9 minutes it always chose "escalate" over "remind" - level 2/3 never got their own
    // documented 0/3/6-minute reminder cadence and raced straight through to the next escalation.
    $level2Row = q1("SELECT level_entered_at FROM broth_log_incidents WHERE incident_id=?", [$raceId]);
    expect_eq($level2Row['level_entered_at'], '2026-08-20 00:09:00', 'entering level 2 resets level_entered_at to the escalation time');
    $dueAtLevel2Min3 = broth_log_copilot_due_escalations(new DateTimeImmutable('2026-08-20 00:12:00 UTC')); // 3 min into level 2, 12 min total age
    $level2Target = array_values(array_filter($dueAtLevel2Min3, fn($d) => $d['incident']['incident_id'] === $raceId));
    expect_true(!empty($level2Target), 'an action is due 3 minutes into level 2');
    expect_eq($level2Target[0]['action'] ?? null, 'remind', 'level 2 gets its own minute-3 reminder instead of immediately escalating again just because total age exceeds 9 minutes');
    expect_eq($level2Target[0]['level'] ?? null, 2, 'the level-2 reminder is issued at level 2, not level 1');

    $dueAtLevel2Min9 = broth_log_copilot_due_escalations(new DateTimeImmutable('2026-08-20 00:18:00 UTC')); // 9 min into level 2, 18 min total age
    $level3Target = array_values(array_filter($dueAtLevel2Min9, fn($d) => $d['incident']['incident_id'] === $raceId));
    expect_eq($level3Target[0]['action'] ?? null, 'escalate', 'level 2 escalates to level 3 nine minutes after entering level 2 (not nine minutes after original creation)');
    expect_eq($level3Target[0]['to_level'] ?? null, 3, 'level 2 escalates specifically to level 3');

    // Regression: Level 3 must never cap out and go silent. The old behavior switched the
    // incident to 'unacknowledged_critical' after 10 reminders, which is excluded from the
    // due-escalations query - Telegram pushes stopped permanently. Level 3 must keep reminding
    // every 3 minutes indefinitely until ACK, with a one-time 'fallback_required' audit marker
    // recorded in parallel (not instead of) the ongoing reminders.
    run("UPDATE broth_log_incidents SET state='escalated_level_3', current_level=3, level_entered_at='2026-08-20 00:00:00', last_reminder_at='2026-08-20 00:27:00', reminder_count=10 WHERE incident_id=?", [$raceId]);
    $dueAtTenReminders = array_values(array_filter(broth_log_copilot_due_escalations(new DateTimeImmutable('2026-08-20 00:30:00 UTC')), fn($d) => $d['incident']['incident_id'] === $raceId));
    expect_eq($dueAtTenReminders[0]['action'] ?? null, 'fallback_reminder', 'crossing 10 reminders at level 3 is flagged for a one-time fallback audit marker');
    $fallbackResult = broth_log_copilot_apply_escalation_action_with_notification($dueAtTenReminders[0], new DateTimeImmutable('2026-08-20 00:30:00 UTC'));
    expect_eq($fallbackResult['action'], 'reminded', 'the fallback-marker crossing still results in a normal reminder being sent, not a terminal fallback action');
    expect_eq(q1("SELECT state FROM broth_log_incidents WHERE incident_id=?", [$raceId])['state'] ?? '', 'escalated_level_3', 'incident remains escalated_level_3, not moved to a terminal unacknowledged_critical state');
    expect_eq(q1("SELECT reminder_count FROM broth_log_incidents WHERE incident_id=?", [$raceId])['reminder_count'] ?? -1, 11, 'reminder count keeps incrementing past the old cap of 10');
    expect_eq(q1("SELECT COUNT(*) AS c FROM broth_log_incident_events WHERE incident_id=? AND event_type='fallback_required'", [$raceId])['c'] ?? -1, 1, 'exactly one fallback_required audit event is recorded at the crossing');

    // Well beyond the old cap: still due, still reminding, no silent stop, no duplicate fallback marker.
    run("UPDATE broth_log_incidents SET last_reminder_at='2026-08-20 01:00:00', reminder_count=25 WHERE incident_id=?", [$raceId]);
    $dueAt25Reminders = array_values(array_filter(broth_log_copilot_due_escalations(new DateTimeImmutable('2026-08-20 01:03:00 UTC')), fn($d) => $d['incident']['incident_id'] === $raceId));
    expect_eq($dueAt25Reminders[0]['action'] ?? null, 'remind', 'far beyond the old 10-reminder cap, level 3 is still due for an ordinary reminder - no cap, no silent stop');
    broth_log_copilot_apply_escalation_action_with_notification($dueAt25Reminders[0], new DateTimeImmutable('2026-08-20 01:03:00 UTC'));
    expect_eq(q1("SELECT state FROM broth_log_incidents WHERE incident_id=?", [$raceId])['state'] ?? '', 'escalated_level_3', 'still escalated_level_3 at reminder 26, long-running scenario well beyond the previous cap');
    expect_eq(q1("SELECT COUNT(*) AS c FROM broth_log_incident_events WHERE incident_id=? AND event_type='fallback_required'", [$raceId])['c'] ?? -1, 1, 'fallback_required audit marker does not duplicate on later reminders');

    // ACK still immediately stops the infinite level-3 cadence.
    $level3Ack = broth_log_copilot_ack($raceId, $user, new DateTimeImmutable('2026-08-20 01:05:00 UTC'));
    expect_true($level3Ack['ok'] ?? false, 'ACK succeeds even deep into the infinite level-3 reminder cadence');
    $dueAfterLevel3Ack = array_values(array_filter(broth_log_copilot_due_escalations(new DateTimeImmutable('2026-08-20 01:10:00 UTC')), fn($d) => $d['incident']['incident_id'] === $raceId));
    expect_true(empty($dueAfterLevel3Ack), 'ACK stops all future level-3 reminders immediately, infinite cadence or not');

    // Backward compatibility: a pre-migration row with no level_entered_at falls back to created_at.
    run("UPDATE broth_log_incidents SET created_at='2026-08-20 00:00:00', level_entered_at=NULL, last_reminder_at=NULL, reminder_count=0, state='detected', current_level=1 WHERE incident_id=?", [$raceId]);
    $legacyDue = broth_log_copilot_due_escalations(new DateTimeImmutable('2026-08-20 00:09:00 UTC'));
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
