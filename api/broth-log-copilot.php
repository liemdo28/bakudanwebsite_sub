<?php
declare(strict_types=1);

require_once __DIR__ . '/broth-log-core.php';

const BROTH_LOG_COPILOT_STATES = ['detected','notified_level_1','acknowledged','escalated_level_2','escalated_level_3','resolved','closed','reopened','unacknowledged_critical'];
const BROTH_LOG_COPILOT_RETENTION_RAW_DAYS = 30;
const BROTH_LOG_COPILOT_CONTEXT_TTL_HOURS = 24;
const BROTH_LOG_COPILOT_INCIDENT_RETENTION_MONTHS = 12;
const BROTH_LOG_COPILOT_ESCALATION_LOCK_SECONDS = 120;

// Balanced escalation cadence (approved business decision, replaces the prior 9-min-escalate /
// 3-min-reminder-at-every-level schedule). Target real-world timeline, given the production
// worker runs every 5 minutes: T+0 initial alert, T+5 reminder, T+10 escalate to L2, T+15
// escalate to L3 (URGENT), then a reminder every 15 minutes indefinitely until ACK - no cap,
// no silent stop. L1's escalate threshold is 10 minutes (not 9) so the T+5 reminder still lands
// before escalation; L2's is only 5 minutes (time already spent in L1 doesn't count - level
// timing is always relative to level_entered_at) so L2 sends its own alert at T+10 and escalates
// to L3 at T+15 without an intermediate L2 reminder. L3's reminder interval governs every
// reminder at level 3, indefinitely - the first one included, since escalating into level 3
// already sets last_reminder_at to the escalation moment (see broth_log_copilot_apply_escalation_action()),
// so the first L3 reminder naturally waits a full interval rather than firing on the very next tick.
const BROTH_LOG_COPILOT_L1_ESCALATE_SECONDS = 600;
const BROTH_LOG_COPILOT_L2_ESCALATE_SECONDS = 300;
const BROTH_LOG_COPILOT_REMINDER_SECONDS = 300;
const BROTH_LOG_COPILOT_L3_REMINDER_SECONDS = 900;

function broth_log_copilot_enabled(): bool {
    return in_array(strtolower(trim((string)(getenv('TELEGRAM_COPILOT_ENABLED') ?: 'false'))), ['1','true','yes','on'], true);
}

function broth_log_copilot_env(string $key, string $default = ''): string {
    $value = getenv($key);
    return is_string($value) && $value !== '' ? trim($value) : $default;
}

function broth_log_copilot_is_staging(): bool {
    return strtolower(broth_log_copilot_env('BROTH_LOG_COPILOT_ENV', 'production')) === 'staging';
}

function broth_log_copilot_migrate(SQLite3 $db): void {
    $db->exec("
    CREATE TABLE IF NOT EXISTS broth_log_authorized_users (
        telegram_user_id TEXT PRIMARY KEY,
        display_name TEXT NOT NULL DEFAULT '',
        role TEXT NOT NULL DEFAULT 'inactive',
        allowed_branches TEXT NOT NULL DEFAULT '[]',
        preferred_language TEXT NOT NULL DEFAULT 'en',
        active INTEGER NOT NULL DEFAULT 0,
        escalation_level INTEGER NOT NULL DEFAULT 1,
        backup_telegram_user_id TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS broth_log_routing_rules (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        branch TEXT NOT NULL,
        stage TEXT NOT NULL DEFAULT 'pilot',
        level INTEGER NOT NULL,
        telegram_user_ids TEXT NOT NULL DEFAULT '[]',
        active INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now')),
        UNIQUE(branch, stage, level)
    );
    CREATE TABLE IF NOT EXISTS broth_log_bot_inbox (
        update_id TEXT PRIMARY KEY,
        telegram_user_id TEXT,
        chat_id TEXT,
        message_id TEXT,
        update_type TEXT NOT NULL,
        payload_json TEXT NOT NULL,
        message_text TEXT,
        received_at TEXT NOT NULL DEFAULT (datetime('now')),
        processed_at TEXT,
        status TEXT NOT NULL DEFAULT 'queued',
        outbound_status TEXT,
        outbound_error TEXT,
        outbound_sent_at TEXT,
        last_error TEXT
    );
    CREATE TABLE IF NOT EXISTS broth_log_conversation_context (
        telegram_user_id TEXT PRIMARY KEY,
        context_json TEXT NOT NULL DEFAULT '{}',
        expires_at TEXT NOT NULL,
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS broth_log_incidents (
        incident_id TEXT PRIMARY KEY,
        fingerprint TEXT NOT NULL,
        active_key TEXT UNIQUE,
        branch TEXT NOT NULL,
        business_date TEXT NOT NULL,
        business_time TEXT,
        response_id TEXT NOT NULL,
        station_key TEXT NOT NULL,
        station_label TEXT NOT NULL,
        temperature_f REAL,
        sop_target TEXT NOT NULL,
        severity TEXT NOT NULL,
        corrective_action TEXT NOT NULL,
        state TEXT NOT NULL DEFAULT 'detected',
        current_level INTEGER NOT NULL DEFAULT 1,
        owner_telegram_user_id TEXT,
        acknowledged_by TEXT,
        acknowledged_at TEXT,
        resolved_by TEXT,
        resolved_at TEXT,
        recheck_temperature_f REAL,
        resolution_note TEXT,
        last_reminder_at TEXT,
        reminder_count INTEGER NOT NULL DEFAULT 0,
        escalation_lock_expires_at TEXT,
        escalation_lock_token TEXT,
        source_revision_hash TEXT NOT NULL DEFAULT '',
        closed_at TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now')),
        incident_type TEXT NOT NULL DEFAULT 'temperature',
        shift TEXT,
        closure_reason TEXT,
        CHECK(state IN ('detected','notified_level_1','acknowledged','escalated_level_2','escalated_level_3','resolved','closed','reopened','unacknowledged_critical'))
    );
    CREATE TABLE IF NOT EXISTS broth_log_incident_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        incident_id TEXT NOT NULL,
        event_type TEXT NOT NULL,
        actor_telegram_user_id TEXT,
        event_json TEXT NOT NULL DEFAULT '{}',
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS broth_log_bot_rate_limits (
        bucket_key TEXT PRIMARY KEY,
        window_start TEXT NOT NULL,
        count INTEGER NOT NULL DEFAULT 0,
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS broth_log_callback_replays (
        callback_hash TEXT PRIMARY KEY,
        consumed_at TEXT NOT NULL DEFAULT (datetime('now')),
        expires_at TEXT NOT NULL
    );
    CREATE TABLE IF NOT EXISTS broth_log_callback_actions (
        token_hash TEXT PRIMARY KEY,
        action TEXT NOT NULL,
        incident_id TEXT NOT NULL,
        expires_at TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    -- Deliberately separate from broth_log_authorized_users: a person can register a private bot
    -- chat with zero authorization, and authorization can exist with zero private registration.
    -- Never used to grant access on its own - only joined against active manager authorization to
    -- compute DM eligibility.
    CREATE TABLE IF NOT EXISTS broth_log_private_chat_registrations (
        telegram_user_id TEXT PRIMARY KEY,
        private_chat_id TEXT NOT NULL,
        registered_at TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    -- No row for a branch = 'ops_fallback' (today's exact behavior: Ops group + any registered
    -- managers). A branch is only cut over to 'manager_dm' by a human explicitly inserting a row
    -- here - never automatically, and never as a side effect of this migration running.
    CREATE TABLE IF NOT EXISTS broth_log_branch_alert_mode (
        branch TEXT PRIMARY KEY,
        mode TEXT NOT NULL DEFAULT 'ops_fallback',
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS broth_log_outbound_deliveries (
        delivery_key TEXT PRIMARY KEY,
        incident_id TEXT,
        chat_id TEXT NOT NULL,
        message_kind TEXT NOT NULL,
        message_text TEXT NOT NULL,
        reply_markup_json TEXT,
        status TEXT NOT NULL DEFAULT 'queued',
        send_attempts INTEGER NOT NULL DEFAULT 0,
        outbound_error TEXT,
        sent_at TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    ");
    foreach ([
        "ALTER TABLE broth_log_incidents ADD COLUMN active_key TEXT",
        "ALTER TABLE broth_log_incidents ADD COLUMN escalation_lock_expires_at TEXT",
        "ALTER TABLE broth_log_incidents ADD COLUMN escalation_lock_token TEXT",
        "ALTER TABLE broth_log_incidents ADD COLUMN level_entered_at TEXT",
        "ALTER TABLE broth_log_incidents ADD COLUMN employee_name TEXT",
        "ALTER TABLE broth_log_incidents ADD COLUMN incident_type TEXT NOT NULL DEFAULT 'temperature'",
        "ALTER TABLE broth_log_incidents ADD COLUMN shift TEXT",
        "ALTER TABLE broth_log_incidents ADD COLUMN closure_reason TEXT",
        "ALTER TABLE broth_log_routing_rules ADD COLUMN chat_id TEXT",
        "ALTER TABLE broth_log_bot_inbox ADD COLUMN outbound_status TEXT",
        "ALTER TABLE broth_log_bot_inbox ADD COLUMN outbound_error TEXT",
        "ALTER TABLE broth_log_bot_inbox ADD COLUMN outbound_sent_at TEXT",
        "ALTER TABLE broth_log_bot_inbox ADD COLUMN chat_type TEXT",
        "ALTER TABLE broth_log_outbound_deliveries ADD COLUMN send_attempts INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE broth_log_outbound_deliveries ADD COLUMN outbound_error TEXT",
        "ALTER TABLE broth_log_outbound_deliveries ADD COLUMN sent_at TEXT",
    ] as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) {}
    }
    foreach ([
        "CREATE INDEX IF NOT EXISTS idx_broth_log_bot_inbox_status ON broth_log_bot_inbox(status, received_at)",
        "CREATE INDEX IF NOT EXISTS idx_broth_log_incidents_open ON broth_log_incidents(state, branch, updated_at)",
        "CREATE UNIQUE INDEX IF NOT EXISTS idx_broth_log_incidents_active_key ON broth_log_incidents(active_key)",
        "CREATE INDEX IF NOT EXISTS idx_broth_log_incident_events_incident ON broth_log_incident_events(incident_id, created_at)",
        "CREATE INDEX IF NOT EXISTS idx_broth_log_routing_rules_active ON broth_log_routing_rules(branch, stage, level, active)",
        "CREATE INDEX IF NOT EXISTS idx_broth_log_callback_replays_expires ON broth_log_callback_replays(expires_at)",
        "CREATE INDEX IF NOT EXISTS idx_broth_log_callback_actions_expires ON broth_log_callback_actions(expires_at)",
        "CREATE INDEX IF NOT EXISTS idx_broth_log_outbound_deliveries_status ON broth_log_outbound_deliveries(status, created_at)",
    ] as $sql) {
        $db->exec($sql);
    }
}

function broth_log_copilot_json_response(array $data, int $code = 200): void {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode(['ok' => $code >= 200 && $code < 300] + $data);
    exit;
}

function broth_log_copilot_reject(string $message, int $code): void {
    broth_log_copilot_json_response(['message' => $message], $code);
}

function broth_log_copilot_webhook_authorized(): bool {
    $expected = broth_log_copilot_env('TELEGRAM_INCOMING_SECRET', broth_log_copilot_env('TELEGRAM_WEBHOOK_SECRET'));
    $actual = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
    return $expected !== '' && $actual !== '' && hash_equals($expected, $actual);
}

function broth_log_copilot_extract_update(array $update): array {
    $message = $update['message'] ?? $update['edited_message'] ?? $update['callback_query']['message'] ?? [];
    $callback = $update['callback_query'] ?? null;
    $from = $callback['from'] ?? ($message['from'] ?? []);
    $chat = $message['chat'] ?? [];
    $text = $callback['data'] ?? ($message['text'] ?? ($message['caption'] ?? ''));
    return [
        'update_id' => (string)($update['update_id'] ?? ''),
        'telegram_user_id' => isset($from['id']) ? (string)$from['id'] : '',
        'chat_id' => isset($chat['id']) ? (string)$chat['id'] : '',
        // Telegram's own chat.type field ("private"/"group"/"supergroup"/"channel") - the
        // authoritative signal for whether an update came from a 1:1 bot conversation, never
        // inferred from comparing chat_id to telegram_user_id.
        'chat_type' => isset($chat['type']) ? (string)$chat['type'] : '',
        'message_id' => isset($message['message_id']) ? (string)$message['message_id'] : '',
        'update_type' => $callback ? 'callback_query' : 'message',
        'text' => trim((string)$text),
    ];
}

function broth_log_copilot_enqueue_webhook(array $update): array {
    if (!broth_log_copilot_enabled()) return ['queued' => false, 'reason' => 'disabled'];
    $meta = broth_log_copilot_extract_update($update);
    if ($meta['update_id'] === '' || !in_array($meta['update_type'], ['message','callback_query'], true)) {
        return ['queued' => false, 'reason' => 'unsupported_update'];
    }
    run("INSERT OR IGNORE INTO broth_log_bot_inbox (update_id,telegram_user_id,chat_id,chat_type,message_id,update_type,payload_json,message_text)
         VALUES (?,?,?,?,?,?,?,?)", [
        $meta['update_id'],
        $meta['telegram_user_id'],
        $meta['chat_id'],
        $meta['chat_type'],
        $meta['message_id'],
        $meta['update_type'],
        json_encode(broth_log_copilot_sanitized_update_payload($update, $meta)),
        broth_log_copilot_sanitize_message($meta['text']),
    ]);
    return ['queued' => db()->changes() > 0, 'update_id' => $meta['update_id']];
}

function broth_log_copilot_sanitized_update_payload(array $update, array $meta): array {
    return [
        'update_id' => $meta['update_id'],
        'update_type' => $meta['update_type'],
        'telegram_user_id' => $meta['telegram_user_id'],
        'chat_id' => $meta['chat_id'],
        'chat_type' => $meta['chat_type'],
        'message_id' => $meta['message_id'],
        'message_text' => broth_log_copilot_sanitize_message($meta['text']),
        'received_keys' => array_values(array_intersect(array_keys($update), ['message','edited_message','callback_query'])),
    ];
}

function broth_log_copilot_sanitize_message(string $message): string {
    $message = broth_log_copilot_redact_credential_text($message);
    return substr($message, 0, 1000);
}

function broth_log_copilot_redact_credential_text(string $text): string {
    $patterns = [
        // Telegram bot tokens are numeric IDs followed by a long URL-safe secret.
        '/\b[0-9]{5,16}:[A-Za-z0-9_-]{20,}\b/' => '[redacted-token]',
        '/\b(Bot|Bearer)\s+[A-Za-z0-9._~+\/=-]{20,}\b/i' => '$1 [redacted-token]',
        '/\b(?:sk|rk|pk|ghp|github_pat|xox[baprs])_[A-Za-z0-9_=-]{16,}\b/' => '[redacted-token]',
        '/\b[A-Za-z0-9._%+-]+:[A-Za-z0-9._~+\/=-]{20,}@/' => '[redacted-credential]@',
        '/\b(api[_-]?key|secret|token|password|passwd|pwd)\s*[:=]\s*[A-Za-z0-9._~+\/=-]{8,}\b/i' => '$1=[redacted]',
    ];
    foreach ($patterns as $pattern => $replacement) {
        $text = preg_replace($pattern, $replacement, $text) ?? '';
    }
    return $text;
}

function broth_log_copilot_sanitize_error(string $message): string {
    foreach ([
        broth_log_copilot_env('TELEGRAM_BOT_TOKEN'),
        broth_log_copilot_env('TELEGRAM_CHAT_ID'),
        broth_log_copilot_env('TELEGRAM_COPILOT_CHAT_ID'),
        broth_log_copilot_env('TELEGRAM_INCOMING_SECRET'),
        broth_log_copilot_env('TELEGRAM_CALLBACK_SECRET'),
        broth_log_copilot_env('TELEGRAM_WEBHOOK_SECRET'),
    ] as $secret) {
        if ($secret !== '') $message = str_replace($secret, '[redacted]', $message);
    }
    return substr(broth_log_copilot_redact_credential_text($message), 0, 500);
}

function broth_log_copilot_apply_outbound_policy(string $message): string {
    $message = trim($message);
    if (broth_log_copilot_is_staging() && !preg_match('/^TEST\b/i', $message)) {
        return 'TEST - ' . $message;
    }
    return $message;
}

function broth_log_copilot_send_telegram_message(string $chatId, string $message, ?array $replyMarkup = null): array {
    $chatId = trim($chatId);
    if ($chatId === '') return ['sent' => false, 'reason' => 'missing_chat_id'];
    $token = broth_log_copilot_env('TELEGRAM_BOT_TOKEN');
    if ($token === '') return ['sent' => false, 'reason' => 'missing_token'];
    $message = broth_log_copilot_apply_outbound_policy($message);
    $payload = [
        'chat_id' => $chatId,
        'text' => $message,
        'disable_web_page_preview' => true,
    ];
    if ($replyMarkup !== null) $payload['reply_markup'] = $replyMarkup;

    $transport = $GLOBALS['BROTH_LOG_COPILOT_TELEGRAM_TRANSPORT'] ?? null;
    if (is_callable($transport)) {
        try {
            $result = $transport('sendMessage', $payload, $token);
            return is_array($result) ? $result : ['sent' => (bool)$result, 'mock' => true];
        } catch (Throwable $e) {
            return ['sent' => false, 'reason' => 'transport_exception', 'error' => broth_log_copilot_sanitize_error($e->getMessage())];
        }
    }
    if (in_array(strtolower(broth_log_copilot_env('BROTH_LOG_COPILOT_TELEGRAM_MOCK', 'false')), ['1','true','yes','on'], true)) {
        return ['sent' => true, 'mock' => true, 'message' => $message, 'chat_id_configured' => true];
    }

    $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
    $body = json_encode($payload);
    // Local connection/timeout failures (http_code 0 - no response reached us at all) are worth a
    // bounded retry on shared hosting. A real HTTP response from Telegram, even an error one, means
    // the request was received and answered - retrying it would not help and could mask a genuine
    // rejection, so only the no-response case retries.
    $maxAttempts = 3;
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $result = broth_log_copilot_telegram_http_call($url, $body);
        $raw = $result['raw'];
        $httpCode = $result['http_code'];
        if ($raw !== false && $httpCode > 0) {
            if ($httpCode >= 200 && $httpCode < 300) {
                $decoded = json_decode($raw, true);
                if (!is_array($decoded) || empty($decoded['ok'])) {
                    return ['sent' => false, 'reason' => 'telegram_rejected', 'http_code' => $httpCode, 'error' => broth_log_copilot_sanitize_error($raw), 'attempts' => $attempt];
                }
                return ['sent' => true, 'http_code' => $httpCode, 'attempts' => $attempt];
            }
            return ['sent' => false, 'reason' => 'telegram_api_error', 'http_code' => $httpCode, 'attempts' => $attempt];
        }
        if ($attempt < $maxAttempts) usleep(500000 * $attempt);
    }
    return ['sent' => false, 'reason' => 'telegram_api_error', 'http_code' => 0, 'attempts' => $maxAttempts];
}

function broth_log_copilot_telegram_http_call(string $url, string $body): array {
    $hook = $GLOBALS['BROTH_LOG_COPILOT_TELEGRAM_HTTP_HOOK'] ?? null;
    if (is_callable($hook)) return $hook($url, $body);
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'timeout' => 6,
        'ignore_errors' => true,
        'header' => "Content-Type: application/json\r\n",
        'content' => $body,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    $statusLine = $http_response_header[0] ?? '';
    $httpCode = preg_match('#\s(\d{3})\s#', $statusLine, $m) ? (int)$m[1] : 0;
    return ['raw' => $raw, 'http_code' => $httpCode];
}

function broth_log_copilot_authorized_user(string $telegramUserId): ?array {
    if ($telegramUserId === '') return null;
    $row = q1("SELECT * FROM broth_log_authorized_users WHERE telegram_user_id=? AND active=1", [$telegramUserId]);
    if (!$row) return null;
    $row['allowed_branch_list'] = json_decode((string)$row['allowed_branches'], true) ?: [];
    return $row;
}

function broth_log_copilot_user_can_branch(array $user, string $branch): bool {
    return in_array(strtoupper($branch), array_map('strtoupper', $user['allowed_branch_list'] ?? []), true);
}

function broth_log_copilot_detect_language(string $text, ?string $fallback = null): string {
    $n = broth_log_norm($text);
    $scores = ['en' => 0, 'es' => 0, 'vi' => 0];
    foreach ([' hoy ',' ayer ',' critico ',' recibido ',' resuelto ',' refrigerador ',' congelador ',' manana '] as $needle) {
        if (str_contains(" $n ", trim($needle))) $scores['es']++;
    }
    foreach ([' hom nay ',' hom qua ',' nghiem trong ',' da nhan ',' da xu ly ',' an toan ',' tu dong ',' ca sang '] as $needle) {
        if (str_contains(" $n ", trim($needle))) $scores['vi']++;
    }
    foreach ([' today ',' yesterday ',' critical ',' ack ',' resolved ',' freezer ',' cooler ',' open '] as $needle) {
        if (str_contains(" $n ", trim($needle))) $scores['en']++;
    }
    arsort($scores);
    $lang = array_key_first($scores);
    return ($scores[$lang] ?? 0) > 0 ? $lang : ($fallback ?: 'en');
}

function broth_log_copilot_station_dictionary(): array {
    return [
        'walkInCoolerProduce' => ['walk in cooler','walk-in cooler','cooler produce','refrigerador','cuarto frio','tu mat','tu lanh','walk in'],
        'walkInFreezer' => ['walk in freezer','walk-in freezer','freezer','congelador','tu dong'],
        'prepAreaCooler' => ['prep area cooler','prep cooler','refrigerador de preparacion','tu mat prep','tu lanh prep'],
        'bowlWarmer' => ['bowl warmer','calentador','ham nong to','warmer'],
        'ramenReachInTop' => ['ramen reach in top','ramen top','reach-in top','ramen arriba'],
        'ramenReachInBelow' => ['ramen reach in below','ramen below','reach-in below','ramen abajo'],
        'lineFreezer' => ['line freezer','freezer line','congelador linea','tu dong line'],
        'seasonedEggs' => ['seasoned eggs','eggs','huevos','trung'],
        'slicedPorkHot' => ['sliced pork','pork hot','cerdo rebanado','thit heo cat'],
        'dicedPorkHot' => ['diced pork','cerdo en cubos','thit heo hat luu'],
        'tapasReachInTop' => ['tapas top','tapas reach in top'],
        'chickenCold' => ['chicken cold','pollo frio','ga lanh'],
        'porkCold' => ['pork cold','cerdo frio','heo lanh'],
        'tapasReachInBelow' => ['tapas below','tapas reach in below'],
        'walkInProduceRecheck' => ['produce recheck','recheck cooler','kiem tra lai cooler'],
        'fryerLeft' => ['fryer left','freidora izquierda','bep chien trai'],
        'fryerRight' => ['fryer right','freidora derecha','bep chien phai'],
        'pastaBoilerLeft' => ['pasta boiler left','boiler left','noi pasta trai'],
        'pastaBoilerRight' => ['pasta boiler right','boiler right','noi pasta phai'],
    ];
}

const BROTH_LOG_COPILOT_MONTH_WORDS = [
    'january' => 1, 'jan' => 1,
    'february' => 2, 'feb' => 2,
    'march' => 3, 'mar' => 3,
    'april' => 4, 'apr' => 4,
    'may' => 5,
    'june' => 6, 'jun' => 6,
    'july' => 7, 'jul' => 7,
    'august' => 8, 'aug' => 8,
    'september' => 9, 'sept' => 9, 'sep' => 9,
    'october' => 10, 'oct' => 10,
    'november' => 11, 'nov' => 11,
    'december' => 12, 'dec' => 12,
];

// Deterministic explicit-date extraction: ISO (YYYY-MM-DD) or English "Month Day" / "Day Month".
// Never guesses a missing year forward/backward and never accepts an invalid calendar date or a
// future business date - those are surfaced as an explicit error instead of silently falling back.
function broth_log_copilot_extract_explicit_date(string $normalizedText, DateTimeImmutable $now): array {
    $todayStr = broth_log_business_date($now);

    if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $normalizedText, $m)) {
        [$year, $month, $day] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        if (!checkdate($month, $day, $year)) return ['matched' => true, 'date' => null, 'error' => 'invalid_date'];
        $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
        if ($date > $todayStr) return ['matched' => true, 'date' => null, 'error' => 'future_date'];
        return ['matched' => true, 'date' => $date, 'error' => null];
    }

    $monthPattern = implode('|', array_keys(BROTH_LOG_COPILOT_MONTH_WORDS));
    $month = null;
    $day = null;
    if (preg_match('/\b(' . $monthPattern . ')\.?\s+(\d{1,2})(?:st|nd|rd|th)?\b/', $normalizedText, $m)) {
        $month = BROTH_LOG_COPILOT_MONTH_WORDS[$m[1]];
        $day = (int)$m[2];
    } elseif (preg_match('/\b(\d{1,2})(?:st|nd|rd|th)?\s+(' . $monthPattern . ')\.?\b/', $normalizedText, $m)) {
        $day = (int)$m[1];
        $month = BROTH_LOG_COPILOT_MONTH_WORDS[$m[2]];
    }
    if ($month !== null) {
        $year = (int)broth_log_business_now($now)->format('Y');
        if (!checkdate($month, $day, $year)) return ['matched' => true, 'date' => null, 'error' => 'invalid_date'];
        $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
        if ($date > $todayStr) return ['matched' => true, 'date' => null, 'error' => 'future_date'];
        return ['matched' => true, 'date' => $date, 'error' => null];
    }

    return ['matched' => false, 'date' => null, 'error' => null];
}

// Deterministic, anchored match only - deliberately does not use str_contains, so ordinary text
// that happens to mention "pilotid" never triggers manager onboarding.
function broth_log_copilot_is_pilot_id_text(string $text): bool {
    return (bool)preg_match('#^/pilotid(@\w+)?\s*$#i', trim($text));
}

// Same anchored-match discipline as /pilotid: only the exact command, never inferred from
// surrounding text. Scoped separately for /start vs /alerts so the caller can pick the right reply.
function broth_log_copilot_is_private_start_text(string $text): bool {
    return (bool)preg_match('#^/start(@\w+)?\s*$#i', trim($text));
}

function broth_log_copilot_is_private_alerts_status_text(string $text): bool {
    return (bool)preg_match('#^/alerts(@\w+)?\s*$#i', trim($text));
}

// Whether $user (already-looked-up broth_log_authorized_users row, possibly null) currently
// qualifies for manager private alerts - role + active only. Branch is checked separately by the
// caller wherever a specific branch's eligibility matters (this helper just answers "are private
// alerts on for this person at all", used for the /start and /alerts replies).
function broth_log_copilot_is_active_manager(?array $user): bool {
    return $user !== null && ($user['role'] ?? '') === 'manager' && (int)($user['active'] ?? 0) === 1;
}

// Builds the reply for a private-chat /start or /alerts message. Never reveals whether ANY other
// person is registered/authorized, never reveals numeric ids, never reveals branch data beyond the
// sender's own approved store name. $isStatusCommand distinguishes /alerts (status query) from
// /start (connection message) - both share the same authorization lookup and safe-reply shape.
function broth_log_copilot_private_registration_response(?array $user, string $lang, bool $isStatusCommand): array {
    $isManager = broth_log_copilot_is_active_manager($user);
    if ($isStatusCommand) {
        if ($isManager) {
            $branches = $user['allowed_branch_list'] ?? [];
            $store = $branches ? strtoupper((string)$branches[0]) : '';
            return ['intent' => 'private_alerts_status', 'message' => broth_log_copilot_tr('private_alerts_status_on', $lang, [$store])];
        }
        return ['intent' => 'private_alerts_status', 'message' => broth_log_copilot_tr('private_alerts_status_pending', $lang)];
    }
    if ($isManager) {
        return ['intent' => 'private_start', 'message' => broth_log_copilot_tr('private_start_enabled', $lang)];
    }
    return ['intent' => 'private_start', 'message' => broth_log_copilot_tr('private_start_connected', $lang)];
}

// Manager DM eligibility for a given branch: active manager whose allowed_branches contains the
// branch, AND who has established a real private bot conversation (a row in
// broth_log_private_chat_registrations). Deliberately separate from broth_log_copilot_route_chat_ids()
// (the group-routing lookup /pilotid's Ops-chat gate also depends on) so that adding a manager's
// private chat here can never widen what counts as the production Ops group.
function broth_log_copilot_manager_dm_chat_ids(string $branch): array {
    $branchUpper = strtoupper($branch);
    $chatIds = [];
    foreach (q("SELECT au.allowed_branches, pcr.private_chat_id
                FROM broth_log_authorized_users au
                INNER JOIN broth_log_private_chat_registrations pcr ON pcr.telegram_user_id = au.telegram_user_id
                WHERE au.role='manager' AND au.active=1") as $row) {
        $branches = json_decode((string)$row['allowed_branches'], true) ?: [];
        if (in_array($branchUpper, array_map('strtoupper', $branches), true) && (string)$row['private_chat_id'] !== '') {
            $chatIds[] = (string)$row['private_chat_id'];
        }
    }
    return array_values(array_unique($chatIds));
}

// 'ops_fallback' (default, no row required) = today's exact behavior: Ops group + any registered
// managers, merged. 'manager_dm' = cut over: managers are the sole primary destination, with the
// Ops group used only as an explicit, audited emergency fallback - never silently.
function broth_log_copilot_branch_alert_mode(string $branch): string {
    $row = q1("SELECT mode FROM broth_log_branch_alert_mode WHERE branch=?", [strtoupper($branch)]);
    return (string)($row['mode'] ?? 'ops_fallback');
}

// Resolves destinations for one proactive alert (initial/reminder/escalation/L3) per the branch's
// current cutover mode, sends via the caller-supplied $sendToChat closure, and applies the
// fail-safe fallback rule for manager_dm mode: zero eligible managers, or every eligible manager's
// send failing, falls back to the Ops group and records a sanitized reason - but at least one
// successful manager delivery means no group fallback at all. Returns [chatId => sendResult] for
// every destination actually attempted, so the caller can tally its own success count unchanged.
function broth_log_copilot_deliver_proactive_alert(string $incidentId, string $branch, int $level, callable $sendToChat): array {
    $branch = strtoupper($branch);
    $groupChats = broth_log_copilot_route_chat_ids($branch, $level);
    $managerChats = broth_log_copilot_manager_dm_chat_ids($branch);
    $mode = broth_log_copilot_branch_alert_mode($branch);

    if ($mode !== 'manager_dm') {
        $chats = array_values(array_unique(array_merge($groupChats, $managerChats)));
        $results = [];
        foreach ($chats as $chatId) $results[$chatId] = $sendToChat($chatId);
        return $results;
    }

    if (empty($managerChats)) {
        broth_log_copilot_audit($incidentId, 'alert_fallback', null, ['branch' => $branch, 'reason' => 'manager_dm_no_eligible_recipient']);
        $results = [];
        foreach ($groupChats as $chatId) $results[$chatId] = $sendToChat($chatId);
        return $results;
    }

    $results = [];
    foreach ($managerChats as $chatId) $results[$chatId] = $sendToChat($chatId);
    $anySucceeded = count(array_filter($results, fn($r) => !empty($r['sent']))) > 0;
    if (!$anySucceeded) {
        broth_log_copilot_audit($incidentId, 'alert_fallback', null, ['branch' => $branch, 'reason' => 'manager_dm_all_deliveries_failed']);
        foreach ($groupChats as $chatId) $results[$chatId] = $sendToChat($chatId);
    }
    return $results;
}

function broth_log_copilot_parse(string $text, ?array $user = null, ?DateTimeImmutable $now = null): array {
    $preferred = $user['preferred_language'] ?? null;
    $lang = broth_log_copilot_detect_language($text, $preferred);
    $n = broth_log_norm($text);
    $explicitDate = broth_log_copilot_extract_explicit_date($n, $now ?: new DateTimeImmutable('now', new DateTimeZone('UTC')));

    $intent = null;
    if (broth_log_copilot_is_pilot_id_text($text)) $intent = 'pilot_id';
    elseif (preg_match('#^/(start|help)#i', $text) || str_contains($n, 'help') || str_contains($n, 'ayuda') || str_contains($n, 'giup')) $intent = 'help';
    elseif (preg_match('#^/(today|status)#i', $text) || str_contains($n, 'today') || str_contains($n, 'hoy') || str_contains($n, 'hom nay') || str_contains($n, 'status')) $intent = 'today_summary';
    elseif (preg_match('#^/(critical|issues)#i', $text) || str_contains($n, 'critical') || str_contains($n, 'critico') || str_contains($n, 'nghiem trong')) $intent = 'critical_issues';
    elseif (str_contains($n, 'open') || str_contains($n, 'pending') || str_contains($n, 'pendiente') || str_contains($n, 'con mo') || str_contains($n, 'chua xu ly')) $intent = 'open_issues';
    elseif (preg_match('#^/missing#i', $text) || str_contains($n, 'missing') || str_contains($n, 'incomplete') || str_contains($n, 'faltante') || str_contains($n, 'thieu')) $intent = 'missing_logs';
    elseif (preg_match('#^/ack#i', $text) || preg_match('/\back\b/i', $text) || str_contains($n, 'acknowledged') || str_contains($n, 'received') || str_contains($n, 'got it') || str_contains($n, 'recibido') || str_contains($n, 'entendido') || str_contains($n, 'da nhan') || str_contains($n, 'dang xu ly')) $intent = 'ack';
    elseif (preg_match('#^/resolve#i', $text) || str_contains($n, 'resolved') || str_contains($n, 'fixed') || str_contains($n, 'resuelto') || str_contains($n, 'corregido') || str_contains($n, 'da xu ly') || str_contains($n, 'da sua')) $intent = 'resolve';
    elseif (str_contains($n, 'temperature') || str_contains($n, 'temp') || str_contains($n, 'temperatura') || str_contains($n, 'nhiet do') || str_contains($n, 'safe') || str_contains($n, 'an toan')) $intent = 'temperature_lookup';
    elseif (str_contains($n, 'sop') || str_contains($n, 'corrective') || str_contains($n, 'accion correctiva') || str_contains($n, 'khac phuc')) $intent = 'sop_comparison';

    if ($intent === null) {
        // No keyword matched. A bare explicit business date ("B1 July 19") should behave like
        // "B1 today" (daily summary), not silently fall back to the generic help message.
        $intent = $explicitDate['matched'] ? 'today_summary' : 'help';
    }

    preg_match('/\b(B[123])\b/i', $text, $bm);
    $branch = isset($bm[1]) ? strtoupper($bm[1]) : null;
    $date = null;
    $dateError = null;
    if ($explicitDate['matched']) {
        if ($explicitDate['error']) {
            $dateError = $explicitDate['error'];
        } else {
            $date = $explicitDate['date'];
        }
    } elseif (str_contains($n, 'yesterday') || str_contains($n, 'ayer') || str_contains($n, 'hom qua')) {
        $date = broth_log_business_now($now)->modify('-1 day')->format('Y-m-d');
    } elseif (str_contains($n, 'today') || str_contains($n, 'hoy') || str_contains($n, 'hom nay') || $intent === 'today_summary') {
        $date = broth_log_business_date($now);
    }
    $station = null;
    foreach (broth_log_copilot_station_dictionary() as $key => $terms) {
        foreach ($terms as $term) {
            if (str_contains($n, broth_log_norm($term))) {
                $station = $key;
                break 2;
            }
        }
    }
    preg_match('/(?:#|incident\s+|issue\s+)([A-Za-z0-9_-]{6,64})/i', $text, $im);
    $temperatureText = isset($im[0]) ? str_replace($im[0], '', $text) : $text;
    preg_match_all('/(-?\d+(?:\.\d+)?)\s*(?:°\s*)?(?:f|degrees?\s*f?)/i', $temperatureText, $temps);
    $temperature = null;
    if (!empty($temps[1])) {
        $temperature = (float)end($temps[1]);
    }
    return [
        'language' => $lang,
        'intent' => $intent,
        'branch' => $branch,
        'business_date' => $date,
        'date_error' => $dateError,
        'date_range' => $date ? 'today' : null,
        'shift' => str_contains($n, 'morning') || str_contains($n, 'manana') || str_contains($n, 'ca sang') ? 'AM' : null,
        'station' => $station,
        'employee' => null,
        'severity' => str_contains($n, 'critical') || str_contains($n, 'critico') || str_contains($n, 'nghiem trong') ? 'critical' : null,
        'incident_id' => $im[1] ?? null,
        'temperature_f' => $temperature,
        'confidence' => $intent === 'help' ? 0.6 : 0.82,
    ];
}

function broth_log_copilot_sign_callback(string $action, string $incidentId, int $expiresAt): string {
    $secret = broth_log_copilot_env('TELEGRAM_CALLBACK_SECRET', broth_log_copilot_env('TELEGRAM_INCOMING_SECRET'));
    if ($secret === '') throw new RuntimeException('Missing callback secret');
    $body = implode('|', [$action, $incidentId, $expiresAt]);
    $sig = substr(hash_hmac('sha256', $body, $secret), 0, 16);
    return implode('|', [$action, $incidentId, $expiresAt, $sig]);
}

function broth_log_copilot_validate_callback(string $data, ?int $now = null): ?array {
    $parts = explode('|', $data);
    if (count($parts) !== 4) return null;
    [$action, $incidentId, $expiresAt, $sig] = $parts;
    if ((int)$expiresAt < ($now ?? time())) return null;
    $expected = broth_log_copilot_sign_callback($action, $incidentId, (int)$expiresAt);
    return hash_equals($expected, $data) ? ['action' => $action, 'incident_id' => $incidentId] : null;
}

function broth_log_copilot_consume_callback(string $data, ?int $now = null): ?array {
    if (!broth_log_copilot_enabled()) return null;
    if (str_starts_with($data, 'c|')) return broth_log_copilot_consume_compact_callback($data, $now);
    $validated = broth_log_copilot_validate_callback($data, $now);
    if (!$validated) return null;
    $parts = explode('|', $data);
    $expiresAt = (int)$parts[2];
    run("DELETE FROM broth_log_callback_replays WHERE expires_at < datetime('now')");
    run("INSERT OR IGNORE INTO broth_log_callback_replays (callback_hash,expires_at) VALUES (?,?)", [
        hash('sha256', $data),
        gmdate('Y-m-d H:i:s', $expiresAt),
    ]);
    return db()->changes() > 0 ? $validated : null;
}

function broth_log_copilot_create_callback_token(string $action, string $incidentId, int $expiresAt): string {
    $secret = broth_log_copilot_env('TELEGRAM_CALLBACK_SECRET', broth_log_copilot_env('TELEGRAM_INCOMING_SECRET'));
    if ($secret === '') throw new RuntimeException('Missing callback secret');
    $shortAction = $action === 'resolve' ? 'r' : 'a';
    $expiry36 = base_convert((string)$expiresAt, 10, 36);
    $nonce = bin2hex(random_bytes(6));
    $body = implode('|', ['c', $shortAction, $expiry36, $nonce]);
    $sig = substr(hash_hmac('sha256', $body, $secret), 0, 12);
    $data = implode('|', [$body, $sig]);
    run("INSERT OR REPLACE INTO broth_log_callback_actions (token_hash,action,incident_id,expires_at) VALUES (?,?,?,?)", [
        hash('sha256', $data),
        $action,
        $incidentId,
        gmdate('Y-m-d H:i:s', $expiresAt),
    ]);
    return $data;
}

function broth_log_copilot_consume_compact_callback(string $data, ?int $now = null): ?array {
    $parts = explode('|', $data);
    if (count($parts) !== 5) return null;
    [$prefix, $shortAction, $expiry36, $nonce, $sig] = $parts;
    if ($prefix !== 'c' || !in_array($shortAction, ['a','r'], true) || !preg_match('/^[a-z0-9]{1,10}$/', $expiry36) || !preg_match('/^[a-f0-9]{12}$/', $nonce)) return null;
    $expiresAt = (int)base_convert($expiry36, 36, 10);
    if ($expiresAt < ($now ?? time())) return null;
    $secret = broth_log_copilot_env('TELEGRAM_CALLBACK_SECRET', broth_log_copilot_env('TELEGRAM_INCOMING_SECRET'));
    if ($secret === '') return null;
    $body = implode('|', [$prefix, $shortAction, $expiry36, $nonce]);
    $expectedSig = substr(hash_hmac('sha256', $body, $secret), 0, 12);
    if (!hash_equals($expectedSig, $sig)) return null;
    $hash = hash('sha256', $data);
    $row = q1("SELECT action,incident_id,expires_at FROM broth_log_callback_actions WHERE token_hash=?", [$hash]);
    if (!$row) return null;
    run("DELETE FROM broth_log_callback_replays WHERE expires_at < datetime('now')");
    run("INSERT OR IGNORE INTO broth_log_callback_replays (callback_hash,expires_at) VALUES (?,?)", [$hash, $row['expires_at']]);
    return db()->changes() > 0 ? ['action' => $row['action'], 'incident_id' => $row['incident_id']] : null;
}

function broth_log_copilot_create_incident(array $alert): string {
    if (!broth_log_copilot_enabled()) return '';
    $stationKey = (string)($alert['stationKey'] ?? '');
    $fingerprint = hash('sha256', implode('|', [$alert['branch'] ?? '', $alert['responseId'] ?? '', $stationKey ?: ($alert['station'] ?? ''), $alert['severity'] ?? 'critical', $alert['businessDate'] ?? '']));
    $existing = q1("SELECT incident_id FROM broth_log_incidents WHERE active_key=?", [$fingerprint]);
    if ($existing) return (string)$existing['incident_id'];
    $incidentId = 'bl-' . substr($fingerprint, 0, 10) . '-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
    $nowTs = gmdate('Y-m-d H:i:s');
    run("INSERT OR IGNORE INTO broth_log_incidents
        (incident_id,fingerprint,active_key,branch,business_date,business_time,response_id,station_key,station_label,temperature_f,sop_target,severity,corrective_action,state,current_level,level_entered_at,source_revision_hash,employee_name)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)", [
        $incidentId,
        $fingerprint,
        $fingerprint,
        strtoupper((string)($alert['branch'] ?? '')),
        (string)($alert['businessDate'] ?? ''),
        (string)($alert['businessTime'] ?? ''),
        (string)($alert['responseId'] ?? ''),
        $stationKey,
        (string)($alert['station'] ?? ''),
        broth_log_number_or_null($alert['temperature'] ?? null),
        (string)($alert['target'] ?? ''),
        'critical',
        (string)($alert['correctiveAction'] ?? ''),
        'detected',
        1,
        $nowTs,
        hash('sha256', json_encode($alert)),
        (string)($alert['employee'] ?? ''),
    ]);
    broth_log_copilot_audit($incidentId, 'detected', null, $alert);
    return $incidentId;
}

// ============================================================================
// MISSING-SHIFT INCIDENTS (incident_type='missing_shift')
//
// Deliberately reuses the temperature-incident machinery rather than building a parallel system:
// broth_log_copilot_ack(), broth_log_copilot_due_escalations(), broth_log_copilot_apply_escalation_action(),
// broth_log_copilot_apply_escalation_action_with_notification(), and broth_log_copilot_notify_incident()
// are all called UNCHANGED for missing_shift incidents below - none of them reference
// temperature_f/sop_target/station_key, only state/current_level/timestamps/branch. The only
// incident_type-aware surfaces are broth_log_copilot_incident_message() and
// broth_log_copilot_incident_reply_markup() (ACK-only, no Resolve), plus
// broth_log_copilot_incident_handler_summary() and the menu issue renderers below.
//
// broth_log_copilot_resolve() already safely rejects a missing_shift incident with reason
// 'unknown_station_config' for free (station_key is '', which never matches a BROTH_LOG_SOP key) -
// this is defense-in-depth, not the primary guard. The primary guard is simply never rendering a
// Resolve button for a missing_shift incident in the first place.
// ============================================================================

function broth_log_copilot_missing_shift_alerts_enabled(): bool {
    return in_array(strtolower(trim((string)(getenv('BROTH_LOG_SHIFT_ALERTS_ENABLED') ?: 'false'))), ['1','true','yes','on'], true);
}

// Staged-rollout allowlist, on top of the global master switch above. A comma-separated list of
// branch codes, e.g. "B1" or "B1,B2". Deliberately fails closed: only ever the three known,
// explicit branch codes ever come back - a typo, empty entry, or a wildcard like "*"/"ALL" is
// silently dropped, never expanded into "every branch". Historical non-compliance was high enough
// (see the Phase 4 audit) that global-only activation was judged unsafe for a first rollout.
function broth_log_copilot_missing_shift_enabled_branches(): array {
    $raw = trim((string)(getenv('BROTH_LOG_SHIFT_ALERT_BRANCHES') ?: ''));
    if ($raw === '') return [];
    $requested = array_filter(array_map('trim', explode(',', strtoupper($raw))), fn($b) => $b !== '');
    return array_values(array_intersect($requested, ['B1', 'B2', 'B3']));
}

// The single check every enablement decision must go through: global flag AND this specific
// branch is on the allowlist. Deliberately independent of branch_alert_mode, manager
// authorization, and routing - those answer "where does an alert go", this answers "is the
// detector even allowed to run for this branch at all". Never inferred from any of them.
function broth_log_copilot_missing_shift_enabled_for_branch(string $branch): bool {
    return broth_log_copilot_missing_shift_alerts_enabled() && in_array(strtoupper($branch), broth_log_copilot_missing_shift_enabled_branches(), true);
}

function broth_log_copilot_missing_shift_active_key(string $branch, string $businessDate, string $shift): string {
    return hash('sha256', implode('|', [strtoupper($branch), $businessDate, $shift, 'missing_shift']));
}

// Station-label equivalent for the menu/issue renderers: a missing-shift incident's station_key/
// station_label are intentionally left '' (never populated with a fabricated station), so anything
// that displays "which issue is this" must branch on incident_type rather than trust station_label
// directly.
function broth_log_copilot_incident_display_label(array $incident): string {
    if (($incident['incident_type'] ?? 'temperature') === 'missing_shift') {
        return (string)($incident['shift'] ?? '') . ' Broth Log';
    }
    return (string)($incident['station_label'] ?? '');
}

// "11:00" -> "11:00 AM", "17:00" -> "5:00 PM". Only used for the manager-facing missing-shift
// message text - the machine-readable canonical value stays BROTH_LOG_SHIFT_WINDOWS' 24h form.
function broth_log_copilot_format_window_end_12h(string $shift): string {
    $window = BROTH_LOG_SHIFT_WINDOWS[$shift] ?? null;
    if (!$window) return '';
    [$h, $m] = array_map('intval', explode(':', $window['end']));
    $period = $h >= 12 ? 'PM' : 'AM';
    $h12 = $h % 12;
    if ($h12 === 0) $h12 = 12;
    return $m === 0 ? "{$h12}:00 {$period}" : sprintf('%d:%02d %s', $h12, $m, $period);
}

// Creates (or returns the existing) missing_shift incident for this branch/business_date/shift.
// Dedup reuses the exact same active_key UNIQUE-index mechanism broth_log_copilot_create_incident()
// uses for temperature incidents - no new dedup infrastructure. temperature_f stays NULL (already
// nullable); station_key/station_label/sop_target/corrective_action/response_id are legacy NOT NULL
// text columns with no missing-shift equivalent, so they get '' - never a fabricated realistic-
// looking value, per the explicit instruction not to invent temperature/SOP/station data here.
function broth_log_copilot_create_missing_shift_incident(string $branch, string $businessDate, string $shift): string {
    if (!broth_log_copilot_enabled()) return '';
    $branch = strtoupper($branch);
    $activeKey = broth_log_copilot_missing_shift_active_key($branch, $businessDate, $shift);
    $existing = q1("SELECT incident_id FROM broth_log_incidents WHERE active_key=?", [$activeKey]);
    if ($existing) return (string)$existing['incident_id'];
    // Deliberately "mshift", not "missing": broth_log_copilot_parse()'s keyword router treats any
    // message text containing the substring "missing" as the unrelated missing_logs intent (a
    // manager's "show today's missing logs" query), checked BEFORE the /resolve or /ack prefix
    // match. An incident id containing "missing" would silently hijack a manager's "/resolve
    // #<id>..." or "/ack #<id>" text reply into that wrong intent instead of ever reaching the
    // resolve/ack handler - discovered via PR #41 hardening test C (Item 2).
    $incidentId = 'bl-mshift-' . substr($activeKey, 0, 10) . '-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
    $nowTs = gmdate('Y-m-d H:i:s');
    run("INSERT OR IGNORE INTO broth_log_incidents
        (incident_id,fingerprint,active_key,branch,business_date,response_id,station_key,station_label,sop_target,severity,corrective_action,state,current_level,level_entered_at,source_revision_hash,incident_type,shift)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)", [
        $incidentId, $activeKey, $activeKey, $branch, $businessDate,
        '', '', '', '', 'critical', '',
        'detected', 1, $nowTs, '', 'missing_shift', $shift,
    ]);
    broth_log_copilot_audit($incidentId, 'missing_shift_detected', null, ['branch' => $branch, 'business_date' => $businessDate, 'shift' => $shift]);
    return $incidentId;
}

// Auto-close on an actual submission arriving - never manager-driven (there is no Resolve button
// for missing_shift). $finalStatus is the shift's real timing status now that a submission exists
// (ON_TIME or LATE, from broth_log_shift_daily_status()) - LATE must never be silently upgraded to
// ON_TIME here or anywhere else; the daily-status function already computed the true answer.
function broth_log_copilot_close_missing_shift_incident(string $incidentId, string $finalStatus, ?DateTimeImmutable $now = null): array {
    if (!broth_log_copilot_enabled()) return ['ok' => false, 'reason' => 'disabled'];
    $now = $now ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $ts = $now->format('Y-m-d H:i:s');
    db()->exec('BEGIN IMMEDIATE');
    try {
        $incident = q1("SELECT * FROM broth_log_incidents WHERE incident_id=?", [$incidentId]);
        if (!$incident || ($incident['incident_type'] ?? '') !== 'missing_shift' || in_array($incident['state'], ['resolved', 'closed'], true)) {
            db()->exec('COMMIT');
            return ['ok' => false, 'reason' => 'incident_not_open'];
        }
        $closureReason = $finalStatus === 'LATE' ? 'late_submission_received' : 'submission_received';
        run("UPDATE broth_log_incidents SET state='closed', active_key=NULL, closed_at=?, closure_reason=?, updated_at=datetime('now') WHERE incident_id=?", [$ts, $closureReason, $incidentId]);
        db()->exec('COMMIT');
    } catch (Throwable $e) {
        try { db()->exec('ROLLBACK'); } catch (Throwable $ignored) {}
        return ['ok' => false, 'reason' => broth_log_copilot_classify_db_exception($e)];
    }
    broth_log_copilot_audit($incidentId, 'missing_shift_closed', null, ['closure_reason' => $closureReason, 'final_timing_status' => $finalStatus]);
    return ['ok' => true, 'incident_id' => $incidentId];
}

// Manager-facing missing-shift message - deliberately the SAME short wording for every kind
// (notify/reminder/escalation/fallback): the business rule specifies one exact format, and this
// incident type gets no escalation-tier-specific copy the way temperature incidents do.
function broth_log_copilot_missing_shift_message(array $incident, string $kind): string {
    $branch = (string)($incident['branch'] ?? '');
    $shift = (string)($incident['shift'] ?? '');
    $deadline = broth_log_copilot_format_window_end_12h($shift);
    if ($kind === 'ack_confirm') {
        return "\u{2705} Acknowledged\n\n{$branch} \u{2014} {$shift} Broth Log\nWaiting for the log.";
    }
    return "\u{26A0}\u{FE0F} {$branch} \u{2014} {$shift} Broth Log Missing\n\nNo log recorded by {$deadline}.";
}

// The core detection/close sweep - called once per worker tick. Poison-isolated per branch: one
// branch's Google Sheet fetch failing must never block detection/closure for the other two, the
// same isolation principle broth_log_copilot_process_inbox() already applies per inbox row.
// Only ever evaluates TODAY's business date - a past date's compliance is already historically
// fixed and this function must never retroactively create or close an incident for it.
function broth_log_copilot_process_missing_shifts(?DateTimeImmutable $now = null): array {
    if (!broth_log_copilot_enabled() || !broth_log_copilot_missing_shift_alerts_enabled()) return [];
    $now = $now ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $businessDate = broth_log_business_date($now);
    $results = [];
    foreach (['B1', 'B2', 'B3'] as $branch) {
        if (!broth_log_copilot_missing_shift_enabled_for_branch($branch)) continue;
        try {
            $records = broth_log_filter_records(broth_log_copilot_branch_records($branch), ['branch' => $branch, 'businessDate' => $businessDate]);
        } catch (Throwable $e) {
            $results[] = ['branch' => $branch, 'error' => 'fetch_failed'];
            continue;
        }
        foreach (['AM', 'PM'] as $shift) {
            $status = broth_log_shift_daily_status($shift, $records, $businessDate, $now);
            $activeKey = broth_log_copilot_missing_shift_active_key($branch, $businessDate, $shift);
            $existing = q1("SELECT incident_id, state FROM broth_log_incidents WHERE active_key=? AND incident_type='missing_shift'", [$activeKey]);

            if ($status['status'] === 'MISSING') {
                if (!$existing && broth_log_shift_alert_deadline_passed($shift, $now)) {
                    $incidentId = broth_log_copilot_create_missing_shift_incident($branch, $businessDate, $shift);
                    if ($incidentId !== '') {
                        $notify = broth_log_copilot_notify_incident($incidentId, $now);
                        $results[] = ['branch' => $branch, 'shift' => $shift, 'action' => 'created', 'incident_id' => $incidentId, 'notified' => !empty($notify['sent'])];
                    }
                }
            } elseif (in_array($status['status'], ['ON_TIME', 'LATE'], true) && $existing && !in_array($existing['state'], ['resolved', 'closed'], true)) {
                broth_log_copilot_close_missing_shift_incident((string)$existing['incident_id'], $status['status'], $now);
                $results[] = ['branch' => $branch, 'shift' => $shift, 'action' => 'auto_closed', 'incident_id' => $existing['incident_id'], 'final_status' => $status['status']];
            }
        }
    }
    return $results;
}

function broth_log_copilot_audit(string $incidentId, string $eventType, ?string $actor, array $event): void {
    run("INSERT INTO broth_log_incident_events (incident_id,event_type,actor_telegram_user_id,event_json) VALUES (?,?,?,?)", [
        $incidentId,
        $eventType,
        $actor,
        json_encode($event),
    ]);
}

// The BEGIN IMMEDIATE / COMMIT blocks in ack(), resolve(), and apply_escalation_action() catch
// every Throwable identically and used to always report 'lock_failed', even when the exception was
// not actually SQLite lock contention (SQLITE_BUSY/SQLITE_LOCKED) - e.g. a genuine SQL/runtime
// error would be mislabeled the same way, hiding the real cause from anyone debugging it.
// SQLite3 (with enableExceptions) reports lock contention with a specific, stable message text;
// anything else is a real internal error and must not be reported as if it were transient.
function broth_log_copilot_classify_db_exception(Throwable $e): string {
    $message = strtolower($e->getMessage());
    if (str_contains($message, 'database is locked') || str_contains($message, 'database table is locked')) {
        return 'lock_failed';
    }
    return 'internal_error';
}

function broth_log_copilot_ack(string $incidentId, array $actor, ?DateTimeImmutable $now = null): array {
    if (!broth_log_copilot_enabled()) return ['ok' => false, 'reason' => 'disabled'];
    $ts = ($now ?: new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    db()->exec('BEGIN IMMEDIATE');
    try {
        $incident = q1("SELECT * FROM broth_log_incidents WHERE incident_id=?", [$incidentId]);
        if (!$incident || in_array($incident['state'], ['resolved','closed'], true)) {
            db()->exec('COMMIT');
            return ['ok' => false, 'reason' => 'incident_not_open'];
        }
        if (!broth_log_copilot_user_can_branch($actor, $incident['branch'])) {
            db()->exec('COMMIT');
            return ['ok' => false, 'reason' => 'forbidden'];
        }
        if ($incident['state'] === 'acknowledged') {
            db()->exec('COMMIT');
            return ['ok' => false, 'reason' => 'already_acknowledged'];
        }
        run("UPDATE broth_log_incidents SET state='acknowledged', owner_telegram_user_id=?, acknowledged_by=?, acknowledged_at=?, last_reminder_at=NULL, updated_at=datetime('now') WHERE incident_id=?", [
            $actor['telegram_user_id'],
            $actor['telegram_user_id'],
            $ts,
            $incidentId,
        ]);
        db()->exec('COMMIT');
    } catch (Throwable $e) {
        try { db()->exec('ROLLBACK'); } catch (Throwable $ignored) {}
        return ['ok' => false, 'reason' => broth_log_copilot_classify_db_exception($e)];
    }
    broth_log_copilot_audit($incidentId, 'acknowledged', (string)$actor['telegram_user_id'], []);
    return ['ok' => true, 'incident_id' => $incidentId];
}

function broth_log_copilot_resolve(string $incidentId, array $actor, ?float $recheckTemp, string $note, ?DateTimeImmutable $now = null): array {
    if (!broth_log_copilot_enabled()) return ['ok' => false, 'reason' => 'disabled'];
    $ts = ($now ?: new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $note = trim($note);
    db()->exec('BEGIN IMMEDIATE');
    try {
        $incident = q1("SELECT * FROM broth_log_incidents WHERE incident_id=?", [$incidentId]);
        if (!$incident || in_array($incident['state'], ['resolved','closed'], true)) {
            db()->exec('COMMIT');
            return ['ok' => false, 'reason' => 'incident_not_open'];
        }
        // Explicit, deterministic guard - not incidental. A missing_shift incident does not support
        // manual Resolve by business design: its lifecycle is detected -> optionally ACKed ->
        // automatically closed only when the actual log submission arrives (see
        // broth_log_copilot_process_missing_shifts()/close_missing_shift_incident()). This must never
        // depend on station_key happening to be empty - that's a coincidental side effect of the
        // incident model, not the real safety property, and a future schema change could silently
        // remove this protection if it were the only guard. Checked before every station/SOP/
        // temperature-specific branch below, which stay in place anyway as defense in depth.
        if (($incident['incident_type'] ?? 'temperature') === 'missing_shift') {
            db()->exec('COMMIT');
            return ['ok' => false, 'reason' => 'resolve_not_supported_for_missing_shift'];
        }
        if (!broth_log_copilot_user_can_branch($actor, $incident['branch'])) {
            db()->exec('COMMIT');
            return ['ok' => false, 'reason' => 'forbidden'];
        }
        if ($note === '' || $recheckTemp === null) {
            db()->exec('COMMIT');
            return ['ok' => false, 'reason' => 'missing_resolution_evidence'];
        }
        if (!isset(BROTH_LOG_SOP[(string)$incident['station_key']])) {
            db()->exec('COMMIT');
            return ['ok' => false, 'reason' => 'unknown_station_config'];
        }
        if (!broth_log_is_safe_recheck((string)$incident['station_key'], $recheckTemp)) {
            db()->exec('COMMIT');
            return ['ok' => false, 'reason' => 'recheck_still_unsafe'];
        }
        run("UPDATE broth_log_incidents SET state='resolved', active_key=NULL, resolved_by=?, resolved_at=?, recheck_temperature_f=?, resolution_note=?, last_reminder_at=NULL, updated_at=datetime('now') WHERE incident_id=?", [
            $actor['telegram_user_id'],
            $ts,
            $recheckTemp,
            $note,
            $incidentId,
        ]);
        db()->exec('COMMIT');
    } catch (Throwable $e) {
        try { db()->exec('ROLLBACK'); } catch (Throwable $ignored) {}
        return ['ok' => false, 'reason' => broth_log_copilot_classify_db_exception($e)];
    }
    broth_log_copilot_audit($incidentId, 'resolved', (string)$actor['telegram_user_id'], ['recheck_temperature_f' => $recheckTemp, 'note' => $note]);
    return ['ok' => true, 'incident_id' => $incidentId];
}

function broth_log_copilot_due_escalations(?DateTimeImmutable $now = null): array {
    if (!broth_log_copilot_enabled()) return [];
    $now = $now ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $due = [];
    $ts = $now->format('Y-m-d H:i:s');
    foreach (q("SELECT * FROM broth_log_incidents
        WHERE state IN ('detected','notified_level_1','escalated_level_2','escalated_level_3')
          AND (escalation_lock_expires_at IS NULL OR escalation_lock_expires_at < ?)", [$ts]) as $incident) {
        $created = new DateTimeImmutable($incident['created_at'] . ' UTC');
        // level_entered_at tracks when the incident entered its *current* level, separate from
        // created_at. Without this, once total incident age passed 9 minutes, every subsequent
        // check kept choosing "escalate" over "remind" regardless of level, so level 2/3 never
        // got their own documented 0/3/6-minute reminder cadence before racing to the next level.
        $levelStart = !empty($incident['level_entered_at']) ? new DateTimeImmutable($incident['level_entered_at'] . ' UTC') : $created;
        $last = $incident['last_reminder_at'] ? new DateTimeImmutable($incident['last_reminder_at'] . ' UTC') : null;
        $ageInLevel = $now->getTimestamp() - $levelStart->getTimestamp();
        // Falls back to level-entry time (not an artificial "infinitely overdue" sentinel) when no
        // reminder has fired yet at this level, so the very first reminder still has to wait a full
        // interval like every subsequent one - not fire on whatever tick happens to run first.
        $sinceLast = $now->getTimestamp() - ($last ?? $levelStart)->getTimestamp();
        $level = (int)$incident['current_level'];
        $escalateThreshold = $level === 1 ? BROTH_LOG_COPILOT_L1_ESCALATE_SECONDS : BROTH_LOG_COPILOT_L2_ESCALATE_SECONDS;
        $reminderThreshold = $level === 3 ? BROTH_LOG_COPILOT_L3_REMINDER_SECONDS : BROTH_LOG_COPILOT_REMINDER_SECONDS;
        if ($level < 3 && $ageInLevel >= $escalateThreshold) {
            $due[] = ['action' => 'escalate', 'incident' => $incident, 'to_level' => $level + 1];
        } elseif ($sinceLast >= $reminderThreshold) {
            // Level 3 has no reminder cap and never goes silent: it keeps reminding every
            // BROTH_LOG_COPILOT_L3_REMINDER_SECONDS indefinitely until ACK. The one-time crossing
            // into "MOD fallback should now engage" is recorded as a parallel audit marker
            // (fallback_reminder), not as a terminal state that would stop the Telegram pushes.
            $action = ($level === 3 && (int)$incident['reminder_count'] === 10) ? 'fallback_reminder' : 'remind';
            $due[] = ['action' => $action, 'incident' => $incident, 'level' => $level];
        }
    }
    return $due;
}

function broth_log_copilot_apply_escalation_action(array $action, ?DateTimeImmutable $now = null): array {
    if (!broth_log_copilot_enabled()) return ['ok' => false, 'reason' => 'disabled'];
    $incident = $action['incident'];
    $ts = ($now ?: new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $lockUntil = ($now ?: new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+' . BROTH_LOG_COPILOT_ESCALATION_LOCK_SECONDS . ' seconds')->format('Y-m-d H:i:s');
    $lockToken = bin2hex(random_bytes(12));
    db()->exec('BEGIN IMMEDIATE');
    try {
        $fresh = q1("SELECT * FROM broth_log_incidents
            WHERE incident_id=?
              AND state IN ('detected','notified_level_1','escalated_level_2','escalated_level_3')
              AND (escalation_lock_expires_at IS NULL OR escalation_lock_expires_at < ?)", [
            $incident['incident_id'],
            $ts,
        ]);
        if (!$fresh) {
            db()->exec('COMMIT');
            return ['ok' => false, 'reason' => 'locked_or_closed', 'incident_id' => $incident['incident_id']];
        }
        if (($fresh['state'] ?? '') !== ($incident['state'] ?? '')
            || (int)($fresh['current_level'] ?? 0) !== (int)($incident['current_level'] ?? 0)
            || (string)($fresh['last_reminder_at'] ?? '') !== (string)($incident['last_reminder_at'] ?? '')
            || (int)($fresh['reminder_count'] ?? 0) !== (int)($incident['reminder_count'] ?? 0)) {
            db()->exec('COMMIT');
            return ['ok' => false, 'reason' => 'stale_action', 'incident_id' => $incident['incident_id']];
        }
        run("UPDATE broth_log_incidents SET escalation_lock_expires_at=?, escalation_lock_token=?, updated_at=datetime('now') WHERE incident_id=?", [$lockUntil, $lockToken, $incident['incident_id']]);
        db()->exec('COMMIT');
        $incident = $fresh;
    } catch (Throwable $e) {
        try { db()->exec('ROLLBACK'); } catch (Throwable $ignored) {}
        return ['ok' => false, 'reason' => broth_log_copilot_classify_db_exception($e), 'incident_id' => $incident['incident_id']];
    }
    if ($action['action'] === 'escalate') {
        $level = (int)$action['to_level'];
        $state = $level === 2 ? 'escalated_level_2' : 'escalated_level_3';
        run("UPDATE broth_log_incidents SET state=?, current_level=?, last_reminder_at=?, reminder_count=0, level_entered_at=?, escalation_lock_expires_at=NULL, escalation_lock_token=NULL, updated_at=datetime('now') WHERE incident_id=? AND escalation_lock_token=?", [$state, $level, $ts, $ts, $incident['incident_id'], $lockToken]);
        broth_log_copilot_audit($incident['incident_id'], $state, null, []);
        return ['ok' => true, 'action' => 'escalated', 'level' => $level, 'incident_id' => $incident['incident_id']];
    }
    if ($action['action'] === 'fallback_reminder') {
        // One-time audit marker that MOD manual fallback should now engage. State stays
        // escalated_level_3 (not a terminal state) and execution falls through to the same
        // reminder logic below, so Telegram pushes keep going every BROTH_LOG_COPILOT_L3_REMINDER_SECONDS
        // (15 minutes) until ACK.
        broth_log_copilot_audit($incident['incident_id'], 'fallback_required', null, ['fallback' => broth_log_copilot_env('TELEGRAM_LEVEL3_FALLBACK', 'operations manual fallback')]);
    }
    run("UPDATE broth_log_incidents SET state=CASE WHEN state='detected' THEN 'notified_level_1' ELSE state END, last_reminder_at=?, reminder_count=reminder_count+1, escalation_lock_expires_at=NULL, escalation_lock_token=NULL, updated_at=datetime('now') WHERE incident_id=? AND escalation_lock_token=?", [$ts, $incident['incident_id'], $lockToken]);
    broth_log_copilot_audit($incident['incident_id'], 'reminder_sent', null, ['level' => (int)$incident['current_level']]);
    return ['ok' => true, 'action' => 'reminded', 'incident_id' => $incident['incident_id']];
}

function broth_log_copilot_branch_records(string $branch): array {
    $provider = $GLOBALS['BROTH_LOG_COPILOT_RECORDS_PROVIDER'] ?? null;
    if (is_callable($provider)) return $provider($branch);
    return broth_log_fetch_branch_records($branch);
}

function broth_log_copilot_query_response(array $parsed, array $user): array {
    $branch = $parsed['branch'];
    if (!$branch && count($user['allowed_branch_list']) === 1) $branch = strtoupper($user['allowed_branch_list'][0]);
    if (!$branch) return ['needs_clarification' => true];
    if (!broth_log_copilot_user_can_branch($user, $branch)) return ['forbidden' => true];
    $date = $parsed['business_date'] ?: broth_log_business_date();
    $records = broth_log_filter_records(broth_log_copilot_branch_records($branch), ['branch' => $branch, 'businessDate' => $date]);
    return ['records' => $records, 'branch' => $branch, 'businessDate' => $date];
}

// Copilot's operational destination is intentionally isolated from the existing one-way critical-alert
// chat: it never reads TELEGRAM_CHAT_ID (that constant is defined and read only in api/index.php's
// legacy one-way send path). Resolution order: (1) the active routing row's own chat_id, so each
// branch/level can eventually have a distinct destination; (2) TELEGRAM_COPILOT_CHAT_ID as a
// Copilot-only fallback; (3) if neither is set, no chat is returned and nothing is sent - failing
// safely rather than silently reusing an unrelated chat.
function broth_log_copilot_active_route(string $branch, int $level): ?array {
    $stage = broth_log_copilot_is_staging() ? 'staging' : 'pilot';
    $row = q1("SELECT telegram_user_ids, chat_id FROM broth_log_routing_rules WHERE branch=? AND stage=? AND level=? AND active=1", [strtoupper($branch), $stage, $level]);
    if (!$row) return null;
    $ids = json_decode((string)$row['telegram_user_ids'], true);
    if (!is_array($ids) || count($ids) === 0) return null;
    return $row;
}

function broth_log_copilot_active_route_exists(string $branch, int $level): bool {
    return broth_log_copilot_active_route($branch, $level) !== null;
}

function broth_log_copilot_route_chat_ids(string $branch, int $level): array {
    $route = broth_log_copilot_active_route($branch, $level);
    if (!$route) return [];
    $chatId = trim((string)($route['chat_id'] ?? ''));
    if ($chatId === '') $chatId = broth_log_copilot_env('TELEGRAM_COPILOT_CHAT_ID');
    return $chatId !== '' ? [$chatId] : [];
}

// MANAGER_ONBOARDING_GROUP_IDENTITY - deliberately independent of ALERT_FALLBACK_DESTINATION
// (broth_log_copilot_route_chat_ids() / broth_log_copilot_deliver_proactive_alert()) and of
// MANAGER_DM_DESTINATION (broth_log_copilot_manager_dm_chat_ids()). This is now a THIRD, separate
// Telegram surface: a dedicated group used only for /pilotid and onboarding admin, never a
// proactive alert or fallback destination. Configured explicitly via TELEGRAM_MANAGER_ONBOARDING_CHAT_ID
// (same env-file mechanism as every other Copilot chat/secret, so staging and production are
// already isolated by using entirely separate env files - never a shared fallback path). No
// hardcoded chat id in source, and no fallback to broth_log_routing_rules or the Alert/Fallback
// group: if the env var is unset, this returns empty and /pilotid fails closed everywhere.
function broth_log_copilot_manager_onboarding_chat_ids(): array {
    $chatId = broth_log_copilot_env('TELEGRAM_MANAGER_ONBOARDING_CHAT_ID');
    return $chatId !== '' ? [$chatId] : [];
}

function broth_log_copilot_is_manager_onboarding_chat(string $chatId): bool {
    if ($chatId === '') return false;
    foreach (broth_log_copilot_manager_onboarding_chat_ids() as $configured) {
        if (hash_equals((string)$configured, $chatId)) return true;
    }
    return false;
}

// /pilotid grants zero access. It exists only so an unauthorized manager's numeric Telegram id -
// already captured by the webhook into broth_log_bot_inbox, the same as every other inbound
// message - becomes visible to a human for approval. It never reads or writes
// broth_log_authorized_users or broth_log_routing_rules.
function broth_log_copilot_pilot_id_response(?array $user, string $lang): array {
    $key = $user ? 'pilot_id_already_registered' : 'pilot_id_received';
    return ['intent' => 'pilot_id', 'message' => broth_log_copilot_tr($key, $lang)];
}

function broth_log_copilot_incident_message_labels(string $lang): array {
    $t = fn(string $en, string $es, string $vi): string => match ($lang) { 'es' => $es, 'vi' => $vi, default => $en };
    return [
        'store' => $t('Store', 'Tienda', 'Chi nhanh'),
        'business' => $t('Business', 'Fecha/hora', 'Ngay gio'),
        'item' => $t('Item', 'Producto', 'Muc'),
        'employee' => $t('Employee', 'Empleado', 'Nhan vien'),
        'recorded' => $t('Recorded', 'Registrado', 'Da ghi'),
        'missing' => $t('missing', 'faltante', 'thieu'),
        'sop' => 'SOP',
        'severity' => $t('Severity', 'Severidad', 'Muc do'),
        'action' => $t('Action', 'Accion', 'Hanh dong'),
        'ref' => $t('Ref', 'Ref', 'Ma'),
        'level' => $t('Level', 'Nivel', 'Muc do leo thang'),
        'kind' => [
            'ack_confirm' => $t('Incident acknowledged', 'Incidente confirmado', 'Su co da duoc xac nhan'),
            'resolve_confirm' => $t('Incident resolved', 'Incidente resuelto', 'Su co da duoc giai quyet'),
            'escalation' => $t('Incident escalated', 'Incidente escalado', 'Su co da duoc nang cap'),
            'fallback' => $t('Emergency fallback required', 'Se requiere accion de emergencia', 'Can hanh dong khan cap'),
            'reminder' => $t('Incident reminder', 'Recordatorio de incidente', 'Nhac nho su co'),
            'default' => $t('Critical Broth Log incident', 'Incidente critico de Broth Log', 'Su co nghiem trong Broth Log'),
        ],
    ];
}

// Full field-by-field message, kept for ack_confirm/resolve_confirm (and any future kind not
// covered by the concise proactive templates below). Not shown to managers for the proactive push
// path (notify/reminder/escalation) - see broth_log_copilot_concise_incident_message() for that.
function broth_log_copilot_verbose_incident_message(array $incident, string $kind, string $lang = 'en'): string {
    $l = broth_log_copilot_incident_message_labels($lang);
    $label = $l['kind'][$kind] ?? $l['kind']['default'];
    $lines = [
        $label,
        $l['store'] . ': ' . (string)$incident['branch'],
        $l['business'] . ': ' . trim((string)$incident['business_date'] . ' ' . (string)($incident['business_time'] ?? '')),
        $l['employee'] . ': ' . ((string)($incident['employee_name'] ?? '') !== '' ? (string)$incident['employee_name'] : 'Unassigned'),
        $l['item'] . ': ' . (string)$incident['station_label'],
        $l['recorded'] . ': ' . (($incident['temperature_f'] ?? null) === null ? $l['missing'] : broth_log_copilot_format_number((float)$incident['temperature_f']) . 'F'),
        $l['sop'] . ': ' . (string)$incident['sop_target'],
        $l['severity'] . ': ' . (string)$incident['severity'],
        $l['action'] . ': ' . (string)$incident['corrective_action'],
        $l['ref'] . ': #' . (string)$incident['incident_id'],
        $l['level'] . ': ' . (string)($incident['current_level'] ?? 1),
    ];
    return implode("\n", array_map(fn($line) => substr($line, 0, 180), $lines));
}

// Direction of violation, derived from the incident's own sop_target string (e.g. "<= 0F" or
// ">= 100F", stored verbatim from the real SOP comparison at detection time - never re-derived
// from the temperature itself, so this can never guess wrong). Returns null when the operator
// cannot be determined (e.g. an unconfigured/unknown station), in which case the message falls
// back to a generic "out of range" phrasing rather than fabricating a direction.
function broth_log_copilot_incident_direction(array $incident): ?string {
    $sopTarget = trim((string)($incident['sop_target'] ?? ''));
    if (str_starts_with($sopTarget, '<=')) return 'max';
    if (str_starts_with($sopTarget, '>=')) return 'min';
    return null;
}

function broth_log_copilot_sop_target_number(array $incident): ?float {
    $sopTarget = (string)($incident['sop_target'] ?? '');
    if (preg_match('/(-?\d+(?:\.\d+)?)/', $sopTarget, $m)) return (float)$m[1];
    return null;
}

// Controlled-test incidents are marked via the same free-text convention used throughout manual
// production validation this session: "CONTROLLED TEST" in the employee or corrective-action
// field. No schema change - presentation only reads an existing, already-audited field.
function broth_log_copilot_incident_is_controlled_test(array $incident): bool {
    return str_contains((string)($incident['employee_name'] ?? ''), 'CONTROLLED TEST')
        || str_contains((string)($incident['corrective_action'] ?? ''), 'CONTROLLED TEST');
}

function broth_log_copilot_concise_alert_labels(string $lang): array {
    $t = fn(string $en, string $es, string $vi): string => match ($lang) { 'es' => $es, 'vi' => $vi, default => $en };
    return [
        'temperature_alert' => $t('Temperature Alert', 'Alerta de Temperatura', 'Canh Bao Nhiet Do'),
        'reminder' => $t('REMINDER', 'RECORDATORIO', 'NHAC NHO'),
        'urgent' => $t('URGENT', 'URGENTE', 'KHAN CAP'),
        'too_high' => $t('too high', 'muy alto', 'qua cao'),
        'too_low' => $t('too low', 'muy bajo', 'qua thap'),
        'out_of_range' => $t('out of range', 'fuera de rango', 'ngoai pham vi'),
        'still_above_limit' => $t('still above limit', 'aun por encima del limite', 'van tren gioi han'),
        'still_below_limit' => $t('still below limit', 'aun por debajo del limite', 'van duoi gioi han'),
        'still_out_of_range' => $t('still out of range', 'aun fuera de rango', 'van ngoai pham vi'),
        'required' => $t('Required', 'Requerido', 'Yeu cau'),
        'sop_not_configured' => $t('SOP target not configured', 'objetivo SOP no configurado', 'chua cau hinh muc tieu SOP'),
        'please_check' => $t('Please check and re-temp.', 'Por favor revisa y vuelve a medir la temperatura.', 'Vui long kiem tra va do lai nhiet do.'),
        'manager_action' => $t('Manager action required.', 'Se requiere accion del gerente.', 'Can quan ly xu ly.'),
        'test_only' => $t('TEST ONLY — no action required.', 'SOLO PRUEBA - no se requiere accion.', 'CHI LA THU NGHIEM - khong can hanh dong.'),
        'not_recorded' => $t('not recorded', 'no registrado', 'chua ghi'),
    ];
}

// Manager-facing proactive push (initial alert, reminder, escalation): short enough to read in
// 2-3 seconds. Deliberately omits business date/time, employee, corrective-action text, severity
// label, incident ref/ID, and escalation-level number - none of that is removed from the incident
// record, audit trail, or dashboard, only from this specific Telegram message body. Escalation
// level still controls presentation tier (urgent styling at level 3+) without ever printing the
// level number or internal state name.
function broth_log_copilot_concise_incident_message(array $incident, string $kind, string $lang = 'en'): string {
    $l = broth_log_copilot_concise_alert_labels($lang);
    $branch = (string)$incident['branch'];
    $station = (string)$incident['station_label'];
    $tempF = $incident['temperature_f'] ?? null;
    $tempText = $tempF === null ? $l['not_recorded'] : broth_log_copilot_format_number((float)$tempF) . '°F';
    $direction = broth_log_copilot_incident_direction($incident);
    $targetNum = broth_log_copilot_sop_target_number($incident);
    $isTest = broth_log_copilot_incident_is_controlled_test($incident);
    $isUrgent = (int)($incident['current_level'] ?? 1) >= 3;

    if ($isTest) {
        $header = '🧪 CONTROLLED TEST – ' . $branch;
    } elseif ($isUrgent) {
        $header = '🔴 ' . $l['urgent'] . ' – ' . $branch;
    } elseif ($kind === 'notify') {
        $header = '🚨 ' . $branch . ' – ' . $l['temperature_alert'];
    } else {
        $header = '⚠️ ' . $l['reminder'] . ' – ' . $branch;
    }

    if ($isUrgent) {
        $statusWord = $l['still_out_of_range'];
    } elseif ($kind === 'notify') {
        $statusWord = $direction === 'max' ? $l['too_high'] : ($direction === 'min' ? $l['too_low'] : $l['out_of_range']);
    } else {
        $statusWord = $direction === 'max' ? $l['still_above_limit'] : ($direction === 'min' ? $l['still_below_limit'] : $l['still_out_of_range']);
    }
    $statusLine = $station . ': ' . $tempText . ' — ' . $statusWord;

    if ($direction === 'max' && $targetNum !== null) {
        $requiredLine = $l['required'] . ': ≤ ' . broth_log_copilot_format_number($targetNum) . '°F';
    } elseif ($direction === 'min' && $targetNum !== null) {
        $requiredLine = $l['required'] . ': ≥ ' . broth_log_copilot_format_number($targetNum) . '°F';
    } else {
        $requiredLine = $l['required'] . ': ' . $l['sop_not_configured'];
    }

    $footer = $isTest ? $l['test_only'] : ($isUrgent ? $l['manager_action'] : $l['please_check']);

    return implode("\n", [$header, '', $statusLine, $requiredLine, '', $footer]);
}

function broth_log_copilot_incident_message(array $incident, string $kind, string $lang = 'en'): string {
    if (($incident['incident_type'] ?? 'temperature') === 'missing_shift') {
        return broth_log_copilot_missing_shift_message($incident, $kind);
    }
    if (in_array($kind, ['notify', 'reminder', 'escalation'], true)) {
        return broth_log_copilot_concise_incident_message($incident, $kind, $lang);
    }
    return broth_log_copilot_verbose_incident_message($incident, $kind, $lang);
}

// Looks up the incident itself to decide whether to show Resolve - missing_shift incidents are
// ACK-only (the manager cannot manually make a missing submission exist by pressing a button; the
// incident only closes when a real submission arrives, via broth_log_copilot_process_missing_shifts()).
// Deliberately keeps the existing (string $incidentId, ?DateTimeImmutable $now) signature so every
// existing call site (notify_incident(), apply_escalation_action_with_notification()) works
// unchanged - this function fetches incident_type itself rather than requiring callers to pass it.
function broth_log_copilot_incident_reply_markup(string $incidentId, ?DateTimeImmutable $now = null): array {
    $expiresAt = ($now ?: new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+15 minutes')->getTimestamp();
    $ack = broth_log_copilot_create_callback_token('ack', $incidentId, $expiresAt);
    $incident = q1("SELECT incident_type FROM broth_log_incidents WHERE incident_id=?", [$incidentId]);
    if (($incident['incident_type'] ?? 'temperature') === 'missing_shift') {
        return ['inline_keyboard' => [[['text' => 'ACK', 'callback_data' => $ack]]]];
    }
    $resolve = broth_log_copilot_create_callback_token('resolve', $incidentId, $expiresAt);
    return ['inline_keyboard' => [[
        ['text' => 'ACK', 'callback_data' => $ack],
        ['text' => 'Resolve', 'callback_data' => $resolve],
    ]]];
}

function broth_log_copilot_send_idempotent(string $deliveryKey, ?string $incidentId, string $chatId, string $kind, string $message, ?array $replyMarkup = null): array {
    run("INSERT OR IGNORE INTO broth_log_outbound_deliveries (delivery_key,incident_id,chat_id,message_kind,message_text,reply_markup_json,status)
         VALUES (?,?,?,?,?,?,?)", [
        $deliveryKey,
        $incidentId,
        $chatId,
        $kind,
        broth_log_copilot_sanitize_message($message),
        $replyMarkup ? json_encode($replyMarkup) : null,
        'queued',
    ]);
    $row = q1("SELECT status FROM broth_log_outbound_deliveries WHERE delivery_key=?", [$deliveryKey]);
    if (($row['status'] ?? '') === 'sent') return ['sent' => false, 'duplicate' => true, 'reason' => 'already_sent'];
    $send = broth_log_copilot_send_telegram_message($chatId, $message, $replyMarkup);
    if (!empty($send['sent'])) {
        run("UPDATE broth_log_outbound_deliveries SET status='sent', send_attempts=send_attempts+1, outbound_error=NULL, sent_at=datetime('now'), updated_at=datetime('now') WHERE delivery_key=?", [$deliveryKey]);
        return ['sent' => true];
    }
    $reason = broth_log_copilot_sanitize_error((string)($send['reason'] ?? $send['error'] ?? 'send_failed'));
    run("UPDATE broth_log_outbound_deliveries SET status='failed', send_attempts=send_attempts+1, outbound_error=?, updated_at=datetime('now') WHERE delivery_key=?", [$reason, $deliveryKey]);
    return ['sent' => false, 'reason' => $reason];
}

function broth_log_copilot_notify_incident(string $incidentId, ?DateTimeImmutable $now = null): array {
    if (!broth_log_copilot_enabled()) return ['sent' => false, 'reason' => 'disabled'];
    $incident = q1("SELECT * FROM broth_log_incidents WHERE incident_id=?", [$incidentId]);
    if (!$incident || in_array($incident['state'], ['resolved','closed'], true)) return ['sent' => false, 'reason' => 'incident_not_open'];
    $message = broth_log_copilot_incident_message($incident, 'notify');
    $sendToChat = function (string $chatId) use ($incidentId, $message, $now): array {
        return broth_log_copilot_send_idempotent(
            'incident:' . $incidentId . ':notify:' . $chatId,
            $incidentId,
            $chatId,
            'incident_notification',
            $message,
            broth_log_copilot_incident_reply_markup($incidentId, $now)
        );
    };
    $results = broth_log_copilot_deliver_proactive_alert($incidentId, (string)$incident['branch'], 1, $sendToChat);
    if (!$results) return ['sent' => false, 'reason' => 'no_active_route'];
    if (count(array_filter($results, fn($r) => !empty($r['sent']))) > 0) {
        broth_log_copilot_audit($incidentId, 'telegram_notified', null, ['level' => 1]);
    }
    return ['sent' => count(array_filter($results, fn($r) => !empty($r['sent']))) > 0, 'results' => array_values($results)];
}

const BROTH_LOG_COPILOT_I18N = [
    'clarification_branch' => ['en' => 'Which store should I check: B1, B2, or B3?', 'es' => 'Que tienda debo revisar: B1, B2 o B3?', 'vi' => 'Toi nen kiem tra chi nhanh nao: B1, B2, hay B3?'],
    'invalid_date' => ['en' => 'That date does not look valid. Try an ISO date like 2026-07-19 or a month and day like July 19.', 'es' => 'Esa fecha no parece valida. Intenta una fecha ISO como 2026-07-19 o un mes y dia como July 19.', 'vi' => 'Ngay do khong hop le. Hay thu dinh dang ISO nhu 2026-07-19 hoac thang va ngay nhu July 19.'],
    'future_date' => ['en' => 'I cannot look up a future date.', 'es' => 'No puedo consultar una fecha futura.', 'vi' => 'Toi khong the tra cuu ngay trong tuong lai.'],
    'forbidden_branch' => ['en' => 'I cannot access that store for this account.', 'es' => 'No puedo acceder a esa tienda con esta cuenta.', 'vi' => 'Toi khong the truy cap chi nhanh do voi tai khoan nay.'],
    'unknown_intent' => ['en' => 'I did not understand that yet. Try: Today B1, critical B1, missing B1, or open B1.', 'es' => 'Aun no entendi eso. Intenta: Today B1, critical B1, missing B1, o open B1.', 'vi' => 'Toi chua hieu yeu cau do. Hay thu: Today B1, critical B1, missing B1, hoac open B1.'],
    'ack_resolve_need_incident' => ['en' => 'Use the signed incident buttons or include an incident id so I can apply this safely.', 'es' => 'Usa los botones firmados del incidente o incluye un ID de incidente para poder aplicarlo de forma segura.', 'vi' => 'Hay dung nut xac nhan cua su co hoac ghi kem ma so su co de toi ap dung an toan.'],
    'ack_resolve_need_incident_ref' => ['en' => 'Include an incident reference like #bl-...', 'es' => 'Incluye una referencia de incidente como #bl-...', 'vi' => 'Hay ghi kem ma tham chieu su co nhu #bl-...'],
    'ack_rejected' => ['en' => 'ACK was rejected: %s', 'es' => 'ACK fue rechazado: %s', 'vi' => 'ACK bi tu choi: %s'],
    'resolve_rejected' => ['en' => 'Resolve was rejected: %s. Include a safe recheck temperature and corrective-action note.', 'es' => 'Resolve fue rechazado: %s. Incluye una temperatura de reverificacion segura y una nota de accion correctiva.', 'vi' => 'Resolve bi tu choi: %s. Hay ghi kem nhiet do kiem tra lai an toan va ghi chu hanh dong khac phuc.'],
    'resolve_prompt' => ['en' => 'Resolve #%s by replying with safe recheck temp and corrective action note. Example: /resolve #%s 38F closed door and moved product.', 'es' => 'Resolve #%s respondiendo con la temperatura segura de reverificacion y una nota de accion correctiva. Ejemplo: /resolve #%s 38F puerta cerrada y producto movido.', 'vi' => 'Resolve #%s bang cach tra loi voi nhiet do kiem tra lai an toan va ghi chu hanh dong khac phuc. Vi du: /resolve #%s 38F da dong cua va di chuyen san pham.'],
    'callback_expired' => ['en' => 'Callback expired, already used, or invalid.', 'es' => 'El boton expiro, ya se uso, o no es valido.', 'vi' => 'Nut bam da het han, da duoc dung, hoac khong hop le.'],
    'callback_stale' => ['en' => 'Incident is not open.', 'es' => 'El incidente no esta abierto.', 'vi' => 'Su co khong con mo.'],
    'callback_forbidden' => ['en' => 'I cannot apply this incident action for your account.', 'es' => 'No puedo aplicar esta accion de incidente para tu cuenta.', 'vi' => 'Toi khong the ap dung hanh dong nay cho tai khoan cua ban.'],
    'unsupported_action' => ['en' => 'Unsupported incident action.', 'es' => 'Accion de incidente no compatible.', 'vi' => 'Hanh dong su co khong duoc ho tro.'],
    'help' => ['en' => 'I can check Today, critical issues, missing logs, and open issues for your authorized stores.', 'es' => 'Puedo revisar Today, critical issues, missing logs y open issues para tus tiendas autorizadas.', 'vi' => 'Toi co the xem Today, critical issues, missing logs va open issues cho chi nhanh ban duoc cap quyen.'],
    'today_summary' => ['en' => '%s %s: %d log(s), %d critical, %d missing.', 'es' => '%s %s: %d registro(s), %d critico(s), %d faltante(s).', 'vi' => '%s %s: %d nhat ky, %d nghiem trong, %d thieu.'],
    'critical_issues_none' => ['en' => 'No critical issues for %s %s.', 'es' => 'No hay problemas criticos para %s %s.', 'vi' => 'Khong co van de nghiem trong cho %s %s.'],
    'critical_issues_header' => ['en' => 'Critical issues for %s %s:', 'es' => 'Problemas criticos para %s %s:', 'vi' => 'Van de nghiem trong cho %s %s:'],
    'critical_issue_line' => ['en' => '- %s: %s (target %s)', 'es' => '- %s: %s (objetivo %s)', 'vi' => '- %s: %s (muc tieu %s)'],
    'open_issues_none' => ['en' => 'No open issues for %s %s.', 'es' => 'No hay problemas abiertos para %s %s.', 'vi' => 'Khong co van de con mo cho %s %s.'],
    'open_issues_header' => ['en' => 'Open issues for %s %s:', 'es' => 'Problemas abiertos para %s %s:', 'vi' => 'Van de con mo cho %s %s:'],
    'open_issue_line' => ['en' => '- %s: %s (status %s)', 'es' => '- %s: %s (estado %s)', 'vi' => '- %s: %s (trang thai %s)'],
    'missing_logs_none' => ['en' => 'No missing logs for %s %s.', 'es' => 'No faltan registros para %s %s.', 'vi' => 'Khong thieu nhat ky nao cho %s %s.'],
    'missing_logs_header' => ['en' => 'Missing logs for %s %s:', 'es' => 'Registros faltantes para %s %s:', 'vi' => 'Nhat ky bi thieu cho %s %s:'],
    'missing_log_line' => ['en' => '- %s', 'es' => '- %s', 'vi' => '- %s'],
    'temperature_lookup_need_station' => ['en' => 'Which item or station should I check?', 'es' => 'Que producto o estacion debo revisar?', 'vi' => 'Toi nen kiem tra mon nao hoac tram nao?'],
    'temperature_lookup_none' => ['en' => 'No reading recorded for %s at %s %s.', 'es' => 'No hay lectura registrada para %s en %s %s.', 'vi' => 'Khong co so do nao duoc ghi cho %s tai %s %s.'],
    'temperature_lookup_result' => ['en' => '%s at %s %s: %s (target %s).', 'es' => '%s en %s %s: %s (objetivo %s).', 'vi' => '%s tai %s %s: %s (muc tieu %s).'],
    'sop_comparison_none' => ['en' => 'No reading recorded for %s at %s %s to compare against SOP.', 'es' => 'No hay lectura registrada para %s en %s %s para comparar con el SOP.', 'vi' => 'Khong co so do nao duoc ghi cho %s tai %s %s de so sanh voi SOP.'],
    'sop_comparison_result' => ['en' => '%s at %s %s: entered %s vs SOP target %s -> %s.', 'es' => '%s en %s %s: ingresado %s vs objetivo SOP %s -> %s.', 'vi' => '%s tai %s %s: nhap %s so voi muc tieu SOP %s -> %s.'],
    'pilot_id_received' => ['en' => "Identity received.\nWaiting for manager access approval.", 'es' => "Identidad recibida.\nEsperando aprobacion de acceso de gerente.", 'vi' => "Da nhan dang danh tinh.\nDang cho quan ly phe duyet quyen truy cap."],
    'pilot_id_already_registered' => ['en' => 'Identity already registered.', 'es' => 'Identidad ya registrada.', 'vi' => 'Danh tinh da duoc dang ky.'],
    'private_start_connected' => ['en' => "Broth Log Alerts\n\nYour Telegram account is connected to the bot. Manager access requires approval.", 'es' => "Broth Log Alertas\n\nTu cuenta de Telegram esta conectada al bot. El acceso de gerente requiere aprobacion.", 'vi' => "Broth Log Canh Bao\n\nTai khoan Telegram cua ban da ket noi voi bot. Quyen truy cap quan ly can duoc phe duyet."],
    'private_start_enabled' => ['en' => "Broth Log Alerts\n\nPrivate alerts are enabled for your approved store.", 'es' => "Broth Log Alertas\n\nLas alertas privadas estan habilitadas para tu tienda aprobada.", 'vi' => "Broth Log Canh Bao\n\nCanh bao rieng tu da duoc bat cho chi nhanh ban duoc duyet."],
    'private_alerts_status_pending' => ['en' => 'Private alerts: Waiting for approval', 'es' => 'Alertas privadas: Esperando aprobacion', 'vi' => 'Canh bao rieng tu: Dang cho phe duyet'],
    'private_alerts_status_on' => ['en' => "Private alerts: ON\nStore: %s", 'es' => "Alertas privadas: ACTIVADAS\nTienda: %s", 'vi' => "Canh bao rieng tu: BAT\nChi nhanh: %s"],
];

const BROTH_LOG_COPILOT_SEVERITY_WORDS = [
    'safe' => ['en' => 'safe', 'es' => 'seguro', 'vi' => 'an toan'],
    'warning' => ['en' => 'warning', 'es' => 'alerta', 'vi' => 'canh bao'],
    'high' => ['en' => 'high', 'es' => 'alto', 'vi' => 'cao'],
    'critical' => ['en' => 'critical', 'es' => 'critico', 'vi' => 'nghiem trong'],
    'missing' => ['en' => 'missing', 'es' => 'sin dato', 'vi' => 'khong co du lieu'],
];

const BROTH_LOG_COPILOT_REASON_WORDS = [
    'forbidden' => ['en' => 'not authorized for this store', 'es' => 'no autorizado para esta tienda', 'vi' => 'khong duoc phep cho chi nhanh nay'],
    'incident_not_open' => ['en' => 'incident is not open', 'es' => 'el incidente no esta abierto', 'vi' => 'su co khong con mo'],
    'already_acknowledged' => ['en' => 'already acknowledged', 'es' => 'ya fue confirmado', 'vi' => 'da duoc xac nhan roi'],
    'missing_resolution_evidence' => ['en' => 'missing recheck temperature or corrective-action note', 'es' => 'falta la temperatura de reverificacion o la nota de accion correctiva', 'vi' => 'thieu nhiet do kiem tra lai hoac ghi chu hanh dong khac phuc'],
    'recheck_still_unsafe' => ['en' => 'recheck temperature is still unsafe', 'es' => 'la temperatura de reverificacion sigue sin ser segura', 'vi' => 'nhiet do kiem tra lai van chua an toan'],
    'unknown_station_config' => ['en' => 'this station has no configured SOP target - contact an admin, this cannot be auto-resolved', 'es' => 'esta estacion no tiene un objetivo SOP configurado - contacta a un administrador, esto no se puede resolver automaticamente', 'vi' => 'tram nay chua co muc tieu SOP - hay lien he quan tri vien, khong the tu dong giai quyet'],
    'lock_failed' => ['en' => 'please try again', 'es' => 'intenta de nuevo', 'vi' => 'vui long thu lai'],
    'internal_error' => ['en' => 'an internal error occurred, please try again or contact an admin', 'es' => 'ocurrio un error interno, intenta de nuevo o contacta a un administrador', 'vi' => 'da xay ra loi he thong, hay thu lai hoac lien he quan tri vien'],
];

function broth_log_copilot_tr(string $key, string $lang, array $args = []): string {
    $set = BROTH_LOG_COPILOT_I18N[$key] ?? null;
    $tpl = $set[$lang] ?? $set['en'] ?? $key;
    return $args ? vsprintf($tpl, $args) : $tpl;
}

function broth_log_copilot_severity_word(string $severity, string $lang): string {
    $set = BROTH_LOG_COPILOT_SEVERITY_WORDS[$severity] ?? null;
    return $set ? ($set[$lang] ?? $set['en']) : $severity;
}

function broth_log_copilot_reason_word(string $reason, string $lang): string {
    $set = BROTH_LOG_COPILOT_REASON_WORDS[$reason] ?? null;
    if ($set) return $set[$lang] ?? $set['en'];
    return ['en' => 'unknown reason', 'es' => 'motivo desconocido', 'vi' => 'ly do khong xac dinh'][$lang] ?? 'unknown reason';
}

function broth_log_copilot_reading_lookup(array $records, string $stationKey): ?array {
    foreach (array_reverse($records) as $record) {
        foreach ($record['readings'] as $reading) {
            if ($reading['key'] === $stationKey) return $reading;
        }
    }
    return null;
}

function broth_log_copilot_station_label(string $stationKey, ?array $reading): string {
    if ($reading) return (string)$reading['label'];
    foreach (BROTH_LOG_READINGS as [$key, $label, $category]) {
        if ($key === $stationKey) return $label;
    }
    return $stationKey;
}

// (string)$float already renders the minimal decimal form (10.0 -> "10", 38.5 -> "38.5"); the old
// rtrim(rtrim($s,'0'),'.') pattern additionally stripped trailing zero *digits* from the integer
// part, silently turning 10 into 1, 100 into 1, 120 into 12, etc. Only strip when a decimal point
// is actually present, and only trailing zeros after it.
function broth_log_copilot_format_number(float $value): string {
    $s = (string)$value;
    return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
}

function broth_log_copilot_temp_text(?float $temperature, string $lang): string {
    if ($temperature === null) return broth_log_copilot_severity_word('missing', $lang);
    return broth_log_copilot_format_number($temperature) . 'F';
}

function broth_log_copilot_format_response(array $parsed, array $user): string {
    $lang = $parsed['language'] ?? ($user['preferred_language'] ?? 'en');
    $intent = $parsed['intent'] ?? 'help';

    if ($intent === 'help') return broth_log_copilot_tr('help', $lang);
    // pilot_id text is now unconditionally intercepted in broth_log_copilot_process_inbox()
    // before this function is ever reached - correct-chat or not, authorized or not - so there is
    // deliberately no pilot_id branch here. An earlier version of this fallback replied
    // "Identity already registered" for any already-authorized sender regardless of chat,
    // silently defeating the Manager Onboarding Group's chat restriction for exactly the senders
    // (owner/managers) who most need it enforced. If intent ever reaches here as 'pilot_id'
    // despite that, it falls through to the generic unknown_intent reply below - never a reply
    // that confirms authorization status.
    if (in_array($intent, ['ack', 'resolve'], true)) return broth_log_copilot_tr('ack_resolve_need_incident', $lang);
    if (!in_array($intent, ['today_summary', 'critical_issues', 'open_issues', 'missing_logs', 'temperature_lookup', 'sop_comparison'], true)) {
        return broth_log_copilot_tr('unknown_intent', $lang);
    }
    if (!empty($parsed['date_error'])) {
        return broth_log_copilot_tr($parsed['date_error'] === 'future_date' ? 'future_date' : 'invalid_date', $lang);
    }

    $response = broth_log_copilot_query_response($parsed, $user);
    if (!empty($response['forbidden'])) return broth_log_copilot_tr('forbidden_branch', $lang);
    if (!empty($response['needs_clarification'])) return broth_log_copilot_tr('clarification_branch', $lang);

    $records = $response['records'] ?? [];
    $branch = $response['branch'] ?? ($parsed['branch'] ?? 'Store');
    $date = $response['businessDate'] ?? ($parsed['business_date'] ?? broth_log_business_date());

    if ($intent === 'today_summary') {
        $summary = broth_log_summary($records);
        return broth_log_copilot_tr('today_summary', $lang, [$branch, $date, (int)$summary['logs'], (int)$summary['criticalIssues'], (int)$summary['missingReadings']]);
    }

    if ($intent === 'critical_issues') {
        $lines = [];
        foreach ($records as $record) {
            foreach ($record['issues'] as $issue) {
                if ($issue['severity'] !== 'critical') continue;
                $lines[] = broth_log_copilot_tr('critical_issue_line', $lang, [$issue['label'], broth_log_copilot_temp_text($issue['temperature'], $lang), $issue['target']]);
            }
        }
        if (!$lines) return broth_log_copilot_tr('critical_issues_none', $lang, [$branch, $date]);
        return broth_log_copilot_tr('critical_issues_header', $lang, [$branch, $date]) . "\n" . implode("\n", array_slice($lines, 0, 10));
    }

    if ($intent === 'open_issues') {
        $lines = [];
        foreach ($records as $record) {
            foreach ($record['issues'] as $issue) {
                if (($issue['status'] ?? '') === 'Closed') continue;
                $lines[] = broth_log_copilot_tr('open_issue_line', $lang, [$issue['label'], broth_log_copilot_temp_text($issue['temperature'], $lang), (string)($issue['status'] ?? '')]);
            }
        }
        if (!$lines) return broth_log_copilot_tr('open_issues_none', $lang, [$branch, $date]);
        return broth_log_copilot_tr('open_issues_header', $lang, [$branch, $date]) . "\n" . implode("\n", array_slice($lines, 0, 10));
    }

    if ($intent === 'missing_logs') {
        $lines = [];
        $seen = [];
        foreach ($records as $record) {
            foreach ($record['readings'] as $reading) {
                if ($reading['severity'] !== 'missing' || isset($seen[$reading['label']])) continue;
                $seen[$reading['label']] = true;
                $lines[] = broth_log_copilot_tr('missing_log_line', $lang, [$reading['label']]);
            }
        }
        if (!$lines) return broth_log_copilot_tr('missing_logs_none', $lang, [$branch, $date]);
        return broth_log_copilot_tr('missing_logs_header', $lang, [$branch, $date]) . "\n" . implode("\n", array_slice($lines, 0, 10));
    }

    // temperature_lookup / sop_comparison
    $stationKey = $parsed['station'] ?? null;
    if (!$stationKey) return broth_log_copilot_tr('temperature_lookup_need_station', $lang);
    $reading = broth_log_copilot_reading_lookup($records, $stationKey);
    $stationLabel = broth_log_copilot_station_label($stationKey, $reading);
    if (!$reading || $reading['temperature'] === null) {
        $key = $intent === 'sop_comparison' ? 'sop_comparison_none' : 'temperature_lookup_none';
        return broth_log_copilot_tr($key, $lang, [$stationLabel, $branch, $date]);
    }
    $tempText = broth_log_copilot_temp_text($reading['temperature'], $lang);
    if ($intent === 'temperature_lookup') {
        return broth_log_copilot_tr('temperature_lookup_result', $lang, [$stationLabel, $branch, $date, $tempText, $reading['target']]);
    }
    return broth_log_copilot_tr('sop_comparison_result', $lang, [$stationLabel, $branch, $date, $tempText, $reading['target'], broth_log_copilot_severity_word($reading['severity'], $lang)]);
}

function broth_log_copilot_incident_from_result(string $incidentId): ?array {
    return q1("SELECT * FROM broth_log_incidents WHERE incident_id=?", [$incidentId]);
}

function broth_log_copilot_callback_response(string $callbackData, array $user, string $chatId, ?DateTimeImmutable $now = null): array {
    $now = $now ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $lang = $user['preferred_language'] ?? 'en';
    $callback = broth_log_copilot_consume_callback($callbackData, $now->getTimestamp());
    if (!$callback) {
        return ['message' => broth_log_copilot_tr('callback_expired', $lang), 'intent' => 'callback_rejected'];
    }
    $incident = broth_log_copilot_incident_from_result((string)$callback['incident_id']);
    if (!$incident || in_array($incident['state'], ['resolved','closed'], true)) {
        return ['message' => broth_log_copilot_tr('callback_stale', $lang), 'intent' => 'callback_stale'];
    }
    if (!broth_log_copilot_user_can_branch($user, (string)$incident['branch'])) {
        return ['message' => broth_log_copilot_tr('callback_forbidden', $lang), 'intent' => 'callback_forbidden'];
    }
    if ($callback['action'] === 'ack') {
        $result = broth_log_copilot_ack((string)$incident['incident_id'], $user, $now);
        if (!empty($result['ok'])) {
            $fresh = broth_log_copilot_incident_from_result((string)$incident['incident_id']) ?: $incident;
            return ['message' => broth_log_copilot_incident_message($fresh, 'ack_confirm', $lang), 'intent' => 'ack'];
        }
        return ['message' => broth_log_copilot_tr('ack_rejected', $lang, [broth_log_copilot_reason_word((string)($result['reason'] ?? ''), $lang)]), 'intent' => 'ack_rejected'];
    }
    if ($callback['action'] === 'resolve') {
        run("INSERT OR REPLACE INTO broth_log_conversation_context (telegram_user_id,context_json,expires_at,updated_at)
             VALUES (?,?,datetime('now', '+24 hours'),datetime('now'))", [
            $user['telegram_user_id'],
            json_encode(['pending' => 'resolve', 'incident_id' => $incident['incident_id'], 'chat_id' => $chatId]),
        ]);
        return ['message' => broth_log_copilot_tr('resolve_prompt', $lang, [$incident['incident_id'], $incident['incident_id']]), 'intent' => 'resolve_prompt'];
    }
    return ['message' => broth_log_copilot_tr('unsupported_action', $lang), 'intent' => 'callback_rejected'];
}

function broth_log_copilot_resolution_note(string $message, array $parsed): string {
    $note = $message;
    $note = preg_replace('#^/resolve\b#i', '', $note) ?? $note;
    $note = preg_replace('/\b(resolve|resolved|fixed|resuelto|corregido|da xu ly|da sua)\b/i', '', $note) ?? $note;
    if (!empty($parsed['incident_id'])) $note = str_replace((string)$parsed['incident_id'], '', $note);
    $note = preg_replace('/#\s*/', '', $note) ?? $note;
    $note = preg_replace('/-?\d+(?:\.\d+)?\s*(?:f|°f|degrees)?/i', '', $note, 1) ?? $note;
    return trim(preg_replace('/\s+/', ' ', $note) ?? '');
}

function broth_log_copilot_message_action_response(string $messageText, array $parsed, array $user, string $chatId, ?DateTimeImmutable $now = null): ?array {
    $intent = $parsed['intent'] ?? '';
    if (!in_array($intent, ['ack','resolve'], true)) return null;
    $lang = $parsed['language'] ?? ($user['preferred_language'] ?? 'en');
    $incidentId = (string)($parsed['incident_id'] ?? '');
    if ($incidentId === '') {
        $context = q1("SELECT context_json,expires_at FROM broth_log_conversation_context WHERE telegram_user_id=? AND expires_at > datetime('now')", [$user['telegram_user_id']]);
        $ctx = $context ? (json_decode((string)$context['context_json'], true) ?: []) : [];
        if (($ctx['pending'] ?? '') === $intent || (($ctx['pending'] ?? '') === 'resolve' && $intent === 'resolve')) {
            $incidentId = (string)($ctx['incident_id'] ?? '');
        }
    }
    if ($incidentId === '') return ['message' => broth_log_copilot_tr('ack_resolve_need_incident_ref', $lang), 'intent' => $intent . '_rejected'];
    if ($intent === 'ack') {
        $result = broth_log_copilot_ack($incidentId, $user, $now);
        if (!empty($result['ok'])) {
            $incident = broth_log_copilot_incident_from_result($incidentId);
            return ['message' => broth_log_copilot_incident_message($incident ?: ['incident_id' => $incidentId], 'ack_confirm', $lang), 'intent' => 'ack'];
        }
        return ['message' => broth_log_copilot_tr('ack_rejected', $lang, [broth_log_copilot_reason_word((string)($result['reason'] ?? ''), $lang)]), 'intent' => 'ack_rejected'];
    }
    $note = broth_log_copilot_resolution_note($messageText, $parsed);
    $result = broth_log_copilot_resolve($incidentId, $user, $parsed['temperature_f'] ?? null, $note, $now);
    if (!empty($result['ok'])) {
        run("DELETE FROM broth_log_conversation_context WHERE telegram_user_id=?", [$user['telegram_user_id']]);
        $incident = broth_log_copilot_incident_from_result($incidentId);
        return ['message' => broth_log_copilot_incident_message($incident ?: ['incident_id' => $incidentId], 'resolve_confirm', $lang), 'intent' => 'resolve'];
    }
    // The generic "%s. Include a safe recheck temperature..." wrapper below is actively wrong
    // advice for a missing_shift incident (there is no recheck temperature concept for a missing
    // log) - a short, accurate, incident-type-specific message instead. No internal reason code or
    // incident id is exposed, matching this codebase's existing manager-facing UX convention.
    if (($result['reason'] ?? '') === 'resolve_not_supported_for_missing_shift') {
        return ['message' => 'This issue closes automatically when the Broth Log is submitted.', 'intent' => 'resolve_rejected'];
    }
    return ['message' => broth_log_copilot_tr('resolve_rejected', $lang, [broth_log_copilot_reason_word((string)($result['reason'] ?? ''), $lang)]), 'intent' => 'resolve_rejected'];
}

function broth_log_copilot_apply_escalation_action_with_notification(array $action, ?DateTimeImmutable $now = null): array {
    $result = broth_log_copilot_apply_escalation_action($action, $now);
    if (empty($result['ok'])) return $result;
    $incident = broth_log_copilot_incident_from_result((string)$result['incident_id']);
    if (!$incident) return $result + ['outbound' => 'incident_missing'];
    $kind = $result['action'] === 'fallback' ? 'fallback' : ($result['action'] === 'escalated' ? 'escalation' : 'reminder');
    $level = (int)($result['level'] ?? $incident['current_level'] ?? 1);
    $incidentId = (string)$incident['incident_id'];
    $reminderCount = $incident['reminder_count'] ?? 0;
    $message = broth_log_copilot_incident_message($incident, $kind);
    // Same destination resolution as broth_log_copilot_notify_incident(): eligible managers get
    // every reminder/escalation the group would, honoring the branch's cutover mode and fail-safe
    // fallback identically - "the same incident" per destination, at every stage.
    $sendToChat = function (string $chatId) use ($incidentId, $kind, $reminderCount, $level, $message, $now): array {
        $deliveryKey = 'incident:' . $incidentId . ':' . $kind . ':' . $reminderCount . ':' . $level . ':' . $chatId;
        return broth_log_copilot_send_idempotent($deliveryKey, $incidentId, $chatId, $kind, $message, broth_log_copilot_incident_reply_markup($incidentId, $now));
    };
    $results = broth_log_copilot_deliver_proactive_alert($incidentId, (string)$incident['branch'], $level, $sendToChat);
    $sent = count(array_filter($results, fn($r) => !empty($r['sent'])));
    return $result + ['outbound_sent' => $sent];
}

// ============================================================================
// ROLE-AWARE /help MENU (Broth Log assistant UX)
//
// Deliberately additive and deterministic - no LLM. Every renderer below reuses the SAME
// data-access functions the existing text commands use (broth_log_copilot_branch_records(),
// broth_log_filter_records(), broth_log_summary(), broth_log_incidents) so buttons and typed
// commands can never disagree. format_response()/broth_log_copilot_parse() are left completely
// untouched - existing text commands ("B1 today", etc.) keep their exact current behavior and
// plain-text (no-keyboard) replies. The menu is a separate, additive presentation layer wired
// into process_inbox() at exactly two points: the intent==='help' branch, and callback_query
// handling for callback_data starting with "menu:".
// ============================================================================

// Store Manager (single branch) vs GM (role=manager, >1 branch) vs Owner/CEO (role=owner).
// Deliberately reuses the existing role model - no new 'gm' role/column. A manager's branch
// COUNT (already the source of truth for what they can access) is also the source of truth for
// which menu they see; nothing here can widen access beyond broth_log_copilot_user_can_branch().
function broth_log_copilot_role_class(array $user): string {
    if (($user['role'] ?? '') === 'owner') return 'owner';
    return count($user['allowed_branch_list'] ?? []) > 1 ? 'gm' : 'store_manager';
}

function broth_log_copilot_menu_main_keyboard(string $roleClass): array {
    if ($roleClass === 'owner') {
        return ['inline_keyboard' => [
            [['text' => "\u{1F4CA} Today's Summary", 'callback_data' => 'menu:ceo_summary'], ['text' => "\u{1F6A8} Exceptions", 'callback_data' => 'menu:ceo_exceptions']],
            [['text' => "\u{1F3EA} Stores", 'callback_data' => 'menu:branchpick:today'], ['text' => "\u{1F4CC} Open Issues", 'callback_data' => 'menu:open']],
            [['text' => "\u{1F5D3} Historical", 'callback_data' => 'menu:branchpick:logdate'], ['text' => "\u{2753} Commands", 'callback_data' => 'menu:commands']],
        ]];
    }
    if ($roleClass === 'gm') {
        return ['inline_keyboard' => [
            [['text' => "\u{1F4C5} Today's Log", 'callback_data' => 'menu:today'], ['text' => "\u{1F3EA} All Stores", 'callback_data' => 'menu:log:ALL:' . broth_log_business_date()]],
            [['text' => "\u{1F6A8} Today's Issues", 'callback_data' => 'menu:issues_today'], ['text' => "\u{1F4CC} Open Issues", 'callback_data' => 'menu:open']],
            [['text' => "\u{1F5D3} Log by Date", 'callback_data' => 'menu:branchpick:logdate'], ['text' => "\u{1F50E} Issues by Date", 'callback_data' => 'menu:branchpick:issuedate']],
            [['text' => "\u{2753} Commands", 'callback_data' => 'menu:commands']],
        ]];
    }
    return ['inline_keyboard' => [
        [['text' => "\u{1F4C5} Today's Log", 'callback_data' => 'menu:today'], ['text' => "\u{1F5D3} Choose Date", 'callback_data' => 'menu:branchpick:logdate']],
        [['text' => "\u{1F6A8} Today's Issues", 'callback_data' => 'menu:issues_today'], ['text' => "\u{1F50E} Issues by Date", 'callback_data' => 'menu:branchpick:issuedate']],
        [['text' => "\u{1F4CC} Open Issues", 'callback_data' => 'menu:open'], ['text' => "\u{2753} Commands", 'callback_data' => 'menu:commands']],
    ]];
}

function broth_log_copilot_menu_back_row(): array {
    return [['text' => "\u{2B05}\u{FE0F} Main Menu", 'callback_data' => 'menu:main']];
}

function broth_log_copilot_help_text(): string {
    return "\u{1F4CB} Broth Log Assistant\n\nI can help you check:\n\n"
        . "\u{2022} Today's Broth Log\n\u{2022} A previous date\n\u{2022} Today's issues\n"
        . "\u{2022} Issues from a previous date\n\u{2022} Open/unresolved issues\n\u{2022} SOP and station status\n\n"
        . "Use the buttons below.";
}

function broth_log_copilot_commands_text(): string {
    return "Broth Log Commands\n\n/help\nOpen this menu.\n\n"
        . "B1 today\nToday's B1 status.\n\n"
        . "B1 July 19\nB1 status for a previous date.\n\n"
        . "B1 today critical issues\nCritical issues today.\n\n"
        . "You can also use the buttons instead of typing commands.";
}

// Every button click re-authorizes from scratch against $user (already loaded by process_inbox()
// from the callback's OWN telegram_user_id, never trusted from callback_data), exactly like the
// existing ACK/Resolve callback path. A branch code inside callback_data is only ever a request,
// never a grant - broth_log_copilot_user_can_branch() below is the actual gate every time.
function broth_log_copilot_menu_can_access_branch(array $user, string $branch): bool {
    return $branch === 'ALL' ? !empty($user['allowed_branch_list']) : broth_log_copilot_user_can_branch($user, $branch);
}

function broth_log_copilot_menu_branch_pick_keyboard(array $user, string $forView, bool $includeAll): array {
    $rows = [];
    $row = [];
    foreach ($user['allowed_branch_list'] ?? [] as $b) {
        $b = strtoupper($b);
        $row[] = ['text' => $b, 'callback_data' => 'menu:branchsel:' . $forView . ':' . $b];
        if (count($row) === 3) { $rows[] = $row; $row = []; }
    }
    if ($row) $rows[] = $row;
    if ($includeAll) $rows[] = [['text' => 'All Stores', 'callback_data' => 'menu:branchsel:' . $forView . ':ALL']];
    $rows[] = broth_log_copilot_menu_back_row();
    return ['inline_keyboard' => $rows];
}

function broth_log_copilot_menu_quick_date_keyboard(string $kind, string $branch): array {
    return ['inline_keyboard' => [
        [['text' => 'Today', 'callback_data' => "menu:qdate:{$kind}:{$branch}:today"]],
        [['text' => 'Yesterday', 'callback_data' => "menu:qdate:{$kind}:{$branch}:yesterday"]],
        [['text' => '2 Days Ago', 'callback_data' => "menu:qdate:{$kind}:{$branch}:2d"]],
        [['text' => 'Enter Date', 'callback_data' => "menu:qdate:{$kind}:{$branch}:enter"]],
        broth_log_copilot_menu_back_row(),
    ]];
}

function broth_log_copilot_menu_date_cancel_keyboard(): array {
    return ['inline_keyboard' => [[['text' => 'Cancel', 'callback_data' => 'menu:main']]]];
}

// ---- LOG DATA views (canonical Google Sheet readings via the existing branch_records path) ----

function broth_log_copilot_menu_log_status_line(array $summary): string {
    if ($summary['criticalIssues'] > 0) return "Status: \u{26A0}\u{FE0F} Needs Attention";
    if ($summary['openIssues'] > 0 || $summary['missingReadings'] > 0) return "Status: \u{1F7E1} Review Needed";
    return "Status: \u{1F7E2} Clear";
}

function broth_log_copilot_menu_shift_status_emoji(string $status): string {
    return ['ON_TIME' => "\u{2705}", 'EARLY' => "\u{26A0}\u{FE0F}", 'LATE' => "\u{26A0}\u{FE0F}", 'MISSING' => "\u{274C}", 'NOT_YET_DUE' => "\u{23F3}"][$status] ?? '';
}
function broth_log_copilot_menu_shift_status_word(string $status): string {
    return ['ON_TIME' => 'On Time', 'EARLY' => 'Early', 'LATE' => 'Late', 'MISSING' => 'Missing', 'NOT_YET_DUE' => 'Not Yet Due'][$status] ?? $status;
}
// Reuses the SAME broth_log_shift_daily_status() the dashboard (PR #39) and the missing-shift
// detector (broth_log_copilot_process_missing_shifts()) both call - one deterministic
// classification, never a second copy for the menu.
function broth_log_copilot_menu_shift_line(string $label, array $status): string {
    $line = "{$label} " . broth_log_copilot_menu_shift_status_emoji($status['status']) . ' ' . broth_log_copilot_menu_shift_status_word($status['status']);
    if (!empty($status['submitted_at'])) {
        $parsed = broth_log_parse_submission_datetime((string)$status['submitted_at']);
        if ($parsed) $line .= "\n" . $parsed->format('g:i A');
    }
    return $line;
}

function broth_log_copilot_menu_log_view(array $user, string $branch, string $date, ?DateTimeImmutable $now = null): array {
    $roleClass = broth_log_copilot_role_class($user);
    $lang = $user['preferred_language'] ?? 'en';
    if (!broth_log_copilot_menu_can_access_branch($user, $branch)) {
        return ['message' => "You don't have access to this store.", 'reply_markup' => broth_log_copilot_menu_main_keyboard($roleClass), 'intent' => 'menu_forbidden'];
    }
    if ($branch === 'ALL') return broth_log_copilot_menu_all_stores_log_view($user, $date, $now);

    try {
        $records = broth_log_filter_records(broth_log_copilot_branch_records($branch), ['branch' => $branch, 'businessDate' => $date]);
    } catch (Throwable $e) {
        return ['message' => "I couldn't load the Broth Log right now.\nPlease try again shortly.", 'reply_markup' => ['inline_keyboard' => [broth_log_copilot_menu_back_row()]], 'intent' => 'menu_error'];
    }
    $am = broth_log_shift_daily_status('AM', $records, $date, $now);
    $pm = broth_log_shift_daily_status('PM', $records, $date, $now);
    $missingShiftCount = q1("SELECT COUNT(*) c FROM broth_log_incidents WHERE branch=? AND business_date=? AND incident_type='missing_shift' AND state NOT IN ('resolved','closed')", [$branch, $date])['c'] ?? 0;

    if (!$records) {
        $lines = ["\u{1F4CB} {$branch} \u{2014} Broth Log " . ($date === broth_log_business_date($now) ? 'Today' : $date), '',
            broth_log_copilot_menu_shift_line('AM', $am), broth_log_copilot_menu_shift_line('PM', $pm), '',
            "No Broth Log submission found for this date."];
        return ['message' => implode("\n", $lines), 'reply_markup' => ['inline_keyboard' => [broth_log_copilot_menu_back_row()]], 'intent' => 'menu_log_empty'];
    }
    $summary = broth_log_summary($records);
    $lines = ["\u{1F4CB} {$branch} \u{2014} Broth Log " . ($date === broth_log_business_date($now) ? 'Today' : $date), '',
        broth_log_copilot_menu_shift_line('AM', $am), broth_log_copilot_menu_shift_line('PM', $pm), '',
        broth_log_copilot_menu_log_status_line($summary), '',
        "Completed logs: {$summary['logs']}", "Missing/incomplete: {$summary['missingReadings']}", "Critical readings: {$summary['criticalIssues']}"];
    if ($missingShiftCount > 0 || $summary['criticalIssues'] > 0) {
        $lines[] = '';
        $lines[] = 'Issues:';
        if ($missingShiftCount > 0) $lines[] = "{$missingShiftCount} missing shift" . ($missingShiftCount === 1 ? '' : 's');
        if ($summary['criticalIssues'] > 0) $lines[] = "{$summary['criticalIssues']} temperature issue" . ($summary['criticalIssues'] === 1 ? '' : 's');
    }
    $currentIssue = null;
    foreach ($records as $record) {
        foreach ($record['issues'] as $issue) {
            if ($issue['severity'] === 'critical') { $currentIssue = $issue; break 2; }
        }
    }
    if ($currentIssue) {
        $lines[] = '';
        $lines[] = 'Current issue:';
        $lines[] = $currentIssue['label'] . ': ' . broth_log_copilot_temp_text($currentIssue['temperature'], $lang);
        $lines[] = 'Required: ' . $currentIssue['target'];
    }
    $keyboard = [];
    if ($summary['criticalIssues'] > 0 || $summary['openIssues'] > 0 || $missingShiftCount > 0) {
        $keyboard[] = [['text' => "\u{1F6A8} View Issues", 'callback_data' => "menu:issues:{$branch}:{$date}"]];
    }
    $keyboard[] = [['text' => "\u{1F4C4} View Full Log", 'callback_data' => "menu:fulllog:{$branch}:{$date}"], ['text' => "\u{1F504} Refresh", 'callback_data' => "menu:log:{$branch}:{$date}"]];
    $keyboard[] = broth_log_copilot_menu_back_row();
    return ['message' => implode("\n", $lines), 'reply_markup' => ['inline_keyboard' => $keyboard], 'intent' => 'menu_log'];
}

function broth_log_copilot_menu_all_stores_log_view(array $user, string $date, ?DateTimeImmutable $now = null): array {
    $lines = ["\u{1F4CB} Broth Log \u{2014} " . ($date === broth_log_business_date($now) ? 'Today' : $date), ''];
    $priorityBranch = null;
    $priorityIssue = null;
    foreach ($user['allowed_branch_list'] ?? [] as $branch) {
        $branch = strtoupper($branch);
        try {
            $records = broth_log_filter_records(broth_log_copilot_branch_records($branch), ['branch' => $branch, 'businessDate' => $date]);
        } catch (Throwable $e) {
            $lines[] = "{$branch} \u{2753} Unavailable";
            continue;
        }
        if (!$records) { $lines[] = "{$branch} \u{2753} No submission"; continue; }
        $summary = broth_log_summary($records);
        if ($summary['criticalIssues'] > 0) {
            $lines[] = "{$branch} \u{1F534} Needs Attention";
            if (!$priorityBranch) {
                foreach ($records as $record) {
                    foreach ($record['issues'] as $issue) {
                        if ($issue['severity'] === 'critical') { $priorityBranch = $branch; $priorityIssue = $issue['label']; break 2; }
                    }
                }
            }
        } elseif ($summary['openIssues'] > 0 || $summary['missingReadings'] > 0) {
            $lines[] = "{$branch} \u{1F7E1} Review Needed";
        } else {
            $lines[] = "{$branch} \u{1F7E2} Clear";
        }
    }
    if ($priorityBranch) { $lines[] = ''; $lines[] = 'Priority:'; $lines[] = "{$priorityBranch} \u{2014} {$priorityIssue} requires attention."; }
    $keyboard = [[['text' => "\u{1F504} Refresh", 'callback_data' => 'menu:log:ALL:' . $date]], broth_log_copilot_menu_back_row()];
    return ['message' => implode("\n", $lines), 'reply_markup' => ['inline_keyboard' => $keyboard], 'intent' => 'menu_log_all'];
}

function broth_log_copilot_menu_full_log_view(array $user, string $branch, string $date): array {
    $lang = $user['preferred_language'] ?? 'en';
    if (!broth_log_copilot_menu_can_access_branch($user, $branch) || $branch === 'ALL') {
        return ['message' => "You don't have access to this store.", 'reply_markup' => broth_log_copilot_menu_main_keyboard(broth_log_copilot_role_class($user)), 'intent' => 'menu_forbidden'];
    }
    try {
        $records = broth_log_filter_records(broth_log_copilot_branch_records($branch), ['branch' => $branch, 'businessDate' => $date]);
    } catch (Throwable $e) {
        return ['message' => "I couldn't load the Broth Log right now.\nPlease try again shortly.", 'reply_markup' => ['inline_keyboard' => [broth_log_copilot_menu_back_row()]], 'intent' => 'menu_error'];
    }
    if (!$records) {
        return ['message' => "No Broth Log submission found for this date.", 'reply_markup' => ['inline_keyboard' => [broth_log_copilot_menu_back_row()]], 'intent' => 'menu_log_empty'];
    }
    $lines = ["\u{1F4C4} {$branch} \u{2014} Full Log", $date, ''];
    foreach ($records[0]['readings'] as $reading) {
        $mark = $reading['severity'] === 'safe' ? "\u{2705}" : ($reading['severity'] === 'missing' ? "\u{2753}" : "\u{1F534}");
        $lines[] = $reading['label'] . ': ' . broth_log_copilot_temp_text($reading['temperature'], $lang) . ' ' . $mark;
    }
    $keyboard = [[['text' => "\u{1F504} Refresh", 'callback_data' => "menu:fulllog:{$branch}:{$date}"]], [['text' => "\u{2B05}\u{FE0F} Back", 'callback_data' => "menu:log:{$branch}:{$date}"]]];
    return ['message' => implode("\n", $lines), 'reply_markup' => ['inline_keyboard' => $keyboard], 'intent' => 'menu_full_log'];
}

// ---- ISSUE DATA views (broth_log_incidents - the ACK/Resolve accountability trail) ----

// Deterministic priority ordering, per spec: (1) critical+unacknowledged, (2) critical+acknowledged
// but unresolved, (3) other open, (4) resolved/closed last. Never left to free-text/LLM ranking.
function broth_log_copilot_menu_incidents_for(string $branch, string $date): array {
    return q("SELECT * FROM broth_log_incidents WHERE branch=? AND business_date=? ORDER BY
        CASE
            WHEN severity='critical' AND state NOT IN ('acknowledged','resolved','closed') THEN 0
            WHEN severity='critical' AND state='acknowledged' THEN 1
            WHEN state NOT IN ('resolved','closed') THEN 2
            ELSE 3
        END, created_at ASC", [strtoupper($branch), $date]);
}

// "Handling" means ACK/Resolve ownership only - never inferred from who received an alert, DM
// recipients, store-manager assignment, or the last Telegram sender. Never surfaces a raw
// Telegram/internal id: an authorized actor with no display_name on file falls back to a generic
// label rather than leaking their numeric identity.
function broth_log_copilot_handler_from_actor(string $actorId, string $status): array {
    if ($actorId === '') return ['status' => $status, 'display' => 'No one yet', 'display_name_known' => true];
    $actor = broth_log_copilot_authorized_user($actorId);
    $name = ($actor && trim((string)$actor['display_name']) !== '') ? trim((string)$actor['display_name']) : 'Authorized Manager';
    return ['status' => $status, 'display' => $name, 'display_name_known' => (bool)($actor && trim((string)$actor['display_name']) !== '')];
}

// state='closed' is exclusively the missing_shift auto-close terminal state (temperature incidents
// only ever reach 'resolved', never 'closed' - see broth_log_copilot_resolve()); accountability for
// a closed missing_shift incident is whoever ACKed it, if anyone, since the closure itself is
// system-driven (the log actually arriving), not a manager action.
function broth_log_copilot_incident_handler_summary(array $incident): array {
    $state = (string)($incident['state'] ?? '');
    if ($state === 'closed') {
        return broth_log_copilot_handler_from_actor((string)($incident['acknowledged_by'] ?? ''), 'closed');
    }
    if ($state === 'resolved' && !empty($incident['resolved_by'])) {
        return broth_log_copilot_handler_from_actor((string)$incident['resolved_by'], 'resolved');
    }
    if (!empty($incident['acknowledged_by'])) {
        return broth_log_copilot_handler_from_actor((string)$incident['acknowledged_by'], 'acknowledged');
    }
    return ['status' => 'unacknowledged', 'display' => 'No one yet', 'display_name_known' => true];
}

function broth_log_copilot_menu_issue_direction_word(array $incident): string {
    $direction = broth_log_copilot_incident_direction($incident);
    return $direction === 'max' ? 'too high' : ($direction === 'min' ? 'too low' : 'out of range');
}

function broth_log_copilot_menu_issue_block(array $incident, int $index, bool $historical): string {
    $lang = 'en';
    $isMissingShift = ($incident['incident_type'] ?? 'temperature') === 'missing_shift';
    if ($isMissingShift) {
        $deadline = broth_log_copilot_format_window_end_12h((string)($incident['shift'] ?? ''));
        $lines = ["{$index}. " . broth_log_copilot_incident_display_label($incident) . ' Missing', "No log recorded by {$deadline}.", ''];
    } else {
        $temp = broth_log_copilot_temp_text($incident['temperature_f'] !== null ? (float)$incident['temperature_f'] : null, $lang);
        $lines = ["{$index}. {$incident['station_label']}", "{$temp} \u{2014} " . broth_log_copilot_menu_issue_direction_word($incident), 'Required: ' . (string)$incident['sop_target'], ''];
    }
    $handler = broth_log_copilot_incident_handler_summary($incident);
    if ($handler['status'] === 'resolved') {
        $lines[] = 'Final state: Resolved';
        $lines[] = 'Handled by: ' . $handler['display'];
        if (!empty($incident['resolved_at'])) $lines[] = 'Resolved: ' . $incident['resolved_at'];
    } elseif ($handler['status'] === 'closed') {
        $lines[] = $isMissingShift && ($incident['closure_reason'] ?? '') === 'late_submission_received' ? 'Final state: Late' : 'Final state: Log received';
        if ($handler['display'] !== 'No one yet') $lines[] = 'Handled by: ' . $handler['display'];
        if (!empty($incident['closed_at'])) $lines[] = 'Closed: ' . $incident['closed_at'];
    } elseif ($handler['status'] === 'acknowledged') {
        $lines[] = "\u{1F7E1} ACKNOWLEDGED \u{2014} BEING HANDLED";
        $lines[] = 'Handling: ' . $handler['display'];
        $lines[] = $isMissingShift ? 'Waiting for the log.' : 'Issue is still open.';
    } else {
        $lines[] = 'Status: WAITING FOR RESPONSE';
        $lines[] = 'Handling: No one yet';
    }
    return implode("\n", $lines);
}

function broth_log_copilot_menu_issues_view(array $user, string $branch, string $date, bool $isToday): array {
    if (!broth_log_copilot_menu_can_access_branch($user, $branch) || $branch === 'ALL') {
        return ['message' => "You don't have access to this store.", 'reply_markup' => broth_log_copilot_menu_main_keyboard(broth_log_copilot_role_class($user)), 'intent' => 'menu_forbidden'];
    }
    $incidents = broth_log_copilot_menu_incidents_for($branch, $date);
    $header = "\u{1F6A8} {$branch} \u{2014} " . ($isToday ? "Today's Issues" : 'Issues') . ($isToday ? '' : "\n{$date}");
    if (!$incidents) {
        $keyboard = [[['text' => "\u{1F504} Refresh", 'callback_data' => "menu:issues:{$branch}:{$date}"]], broth_log_copilot_menu_back_row()];
        return ['message' => $header . "\n\n\u{2705} No Broth Log issues found.", 'reply_markup' => ['inline_keyboard' => $keyboard], 'intent' => 'menu_issues_empty'];
    }
    $blocks = [$header, ''];
    $i = 1;
    foreach (array_slice($incidents, 0, 10) as $incident) {
        $blocks[] = broth_log_copilot_menu_issue_block($incident, $i, !$isToday);
        $blocks[] = '';
        $i++;
    }
    $keyboard = [
        [['text' => "\u{1F504} Refresh", 'callback_data' => "menu:issues:{$branch}:{$date}"], ['text' => "\u{1F4CB} " . ($isToday ? "Today's Log" : 'View Log'), 'callback_data' => "menu:log:{$branch}:{$date}"]],
        broth_log_copilot_menu_back_row(),
    ];
    return ['message' => rtrim(implode("\n", $blocks)), 'reply_markup' => ['inline_keyboard' => $keyboard], 'intent' => 'menu_issues'];
}

// Cross-date, cross-branch: every currently-open incident across the caller's authorized
// branches - independent of "Issues by Date"/"Today's Issues", which are always scoped to one
// specific business date.
function broth_log_copilot_menu_open_issues_view(array $user, bool $criticalOnly = false): array {
    $branches = $user['allowed_branch_list'] ?? [];
    if (!$branches) {
        return ['message' => "You don't have access to this store.", 'reply_markup' => broth_log_copilot_menu_main_keyboard(broth_log_copilot_role_class($user)), 'intent' => 'menu_forbidden'];
    }
    $lines = ["\u{1F4CC} Open Broth Log Issues", ''];
    $any = false;
    foreach ($branches as $branch) {
        $branch = strtoupper($branch);
        // "Critical Only" is explicitly a temperature-severity concept - a missing_shift row is
        // also stored with severity='critical' (it IS urgent), but must never be pulled into a
        // filter whose label/intent is specifically about critical TEMPERATURE readings. The base
        // (non-critical-only) Open Issues view below is intentionally unfiltered by incident_type -
        // missing-shift issues still appear there, just not under this specific filter.
        $sql = "SELECT * FROM broth_log_incidents WHERE branch=? AND state NOT IN ('resolved','closed')" . ($criticalOnly ? " AND severity='critical' AND incident_type='temperature'" : '') . " ORDER BY
            CASE WHEN severity='critical' AND state NOT IN ('acknowledged') THEN 0 WHEN severity='critical' THEN 1 ELSE 2 END, created_at ASC";
        $rows = q($sql, [$branch]);
        if (!$rows) { $lines[] = "{$branch}\nNo open issues."; $lines[] = ''; continue; }
        $any = true;
        $marker = ($rows[0]['severity'] === 'critical' && $rows[0]['state'] === 'detected') ? "\u{1F534}" : "\u{1F7E1}";
        $lines[] = "{$marker} {$branch}";
        foreach ($rows as $incident) {
            $handler = broth_log_copilot_incident_handler_summary($incident);
            $ageMinutes = (int)floor((time() - strtotime((string)$incident['created_at'] . ' UTC')) / 60);
            $statusWord = $handler['status'] === 'acknowledged' ? 'Acknowledged' : 'Unacknowledged';
            $lines[] = broth_log_copilot_incident_display_label($incident) . " \u{2014} {$statusWord}";
            $lines[] = $handler['status'] === 'unacknowledged' ? "Open {$ageMinutes} min" : 'Still unresolved';
            $lines[] = 'Handling: ' . $handler['display'];
        }
        $lines[] = '';
    }
    if (!$any) $lines[] = "\u{2705} No open Broth Log issues.";
    $keyboard = [
        [['text' => "\u{1F6A8} Critical Only", 'callback_data' => 'menu:open_critical'], ['text' => "\u{1F504} Refresh", 'callback_data' => 'menu:open']],
        broth_log_copilot_menu_back_row(),
    ];
    return ['message' => rtrim(implode("\n", $lines)), 'reply_markup' => ['inline_keyboard' => $keyboard], 'intent' => 'menu_open'];
}

function broth_log_copilot_menu_ceo_summary_view(array $user, ?DateTimeImmutable $now = null): array {
    if (($user['role'] ?? '') !== 'owner') {
        return ['message' => "You don't have access to this store.", 'reply_markup' => broth_log_copilot_menu_main_keyboard(broth_log_copilot_role_class($user)), 'intent' => 'menu_forbidden'];
    }
    $date = broth_log_business_date($now);
    $view = broth_log_copilot_menu_all_stores_log_view($user, $date, $now);
    $view['message'] = str_replace("\u{1F4CB} Broth Log \u{2014} Today", "\u{1F4CA} Broth Log \u{2014} Today\n\nStores monitored: " . count($user['allowed_branch_list'] ?? []), $view['message']);
    $view['intent'] = 'menu_ceo_summary';
    return $view;
}

// ---- Date-entry conversation context ("awaiting_log_date" / "awaiting_issue_date") ----

function broth_log_copilot_menu_set_date_context(string $telegramUserId, string $kind, string $branch): void {
    run("INSERT OR REPLACE INTO broth_log_conversation_context (telegram_user_id,context_json,expires_at,updated_at)
         VALUES (?,?,datetime('now', '+24 hours'),datetime('now'))", [
        $telegramUserId,
        json_encode(['pending' => 'menu_date', 'kind' => $kind, 'branch' => $branch]),
    ]);
}

// Returns null when there is no pending menu date-entry context for this sender, so the normal
// text-command parser handles the message exactly as it does today - this never intercepts
// ordinary text commands, only a message that immediately follows an "Enter Date" tap.
function broth_log_copilot_menu_date_entry_response(string $text, array $user, ?DateTimeImmutable $now = null): ?array {
    $context = q1("SELECT context_json FROM broth_log_conversation_context WHERE telegram_user_id=? AND expires_at > datetime('now')", [$user['telegram_user_id']]);
    if (!$context) return null;
    $ctx = json_decode((string)$context['context_json'], true) ?: [];
    if (($ctx['pending'] ?? '') !== 'menu_date') return null;
    run("DELETE FROM broth_log_conversation_context WHERE telegram_user_id=?", [$user['telegram_user_id']]);
    $kind = (string)($ctx['kind'] ?? 'log');
    $branch = (string)($ctx['branch'] ?? '');
    $now = $now ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $parsed = broth_log_copilot_extract_explicit_date(broth_log_norm($text), $now);
    if (!$parsed['matched'] || $parsed['error']) {
        return [
            'message' => "I couldn't recognize that date.\n\nPlease send:\nMM/DD/YYYY\n\nor tap Cancel.",
            'reply_markup' => broth_log_copilot_menu_date_cancel_keyboard(),
            'intent' => 'menu_date_invalid',
        ] + ['_reprompt' => ['kind' => $kind, 'branch' => $branch]];
    }
    $view = $kind === 'issues'
        ? broth_log_copilot_menu_issues_view($user, $branch, $parsed['date'], $parsed['date'] === broth_log_business_date($now))
        : broth_log_copilot_menu_log_view($user, $branch, $parsed['date'], $now);
    return $view;
}

// ---- /help entry point and central "menu:" callback dispatcher ----

// Chat scoping: the assistant menu is a private-DM feature. A group chat (Manager Onboarding or
// Alert/Fallback) gets a safe, static, non-branching reply - never store/incident data, never a
// keyboard that could route into protected views.
function broth_log_copilot_help_response(array $user, string $chatType): array {
    if ($chatType !== 'private') {
        return ['message' => 'The Broth Log assistant menu works in private chat. Message the bot directly to use /help.', 'reply_markup' => null, 'intent' => 'help_group_denied'];
    }
    return ['message' => broth_log_copilot_help_text(), 'reply_markup' => broth_log_copilot_menu_main_keyboard(broth_log_copilot_role_class($user)), 'intent' => 'help_menu'];
}

// Returns null for any callback_data that is not a menu: route, so the caller falls through to
// the existing (unchanged) broth_log_copilot_callback_response() for ACK/Resolve tokens.
function broth_log_copilot_menu_callback_response(string $callbackData, array $user, string $chatType, ?DateTimeImmutable $now = null): ?array {
    if (!str_starts_with($callbackData, 'menu:')) return null;
    if ($chatType !== 'private') {
        return ['message' => 'The Broth Log assistant menu works in private chat.', 'reply_markup' => null, 'intent' => 'menu_group_denied'];
    }
    $now = $now ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $parts = explode(':', $callbackData);
    $route = $parts[1] ?? '';
    $roleClass = broth_log_copilot_role_class($user);
    $today = broth_log_business_date($now);

    // Every menu: tap abandons any pending "Enter Date" free-text wait, except the one action that
    // sets it (qdate:...:enter, handled below). Without this, tapping Cancel (which routes to
    // menu:main) or navigating anywhere else would leave the context armed - the NEXT ordinary
    // text message the user sends (e.g. a normal "B1 today" command) would then be wrongly
    // swallowed as a date reply instead of reaching the real command parser.
    if (!($route === 'qdate' && ($parts[4] ?? '') === 'enter')) {
        run("DELETE FROM broth_log_conversation_context WHERE telegram_user_id=?", [$user['telegram_user_id']]);
    }

    if ($route === 'main') {
        return ['message' => broth_log_copilot_help_text(), 'reply_markup' => broth_log_copilot_menu_main_keyboard($roleClass), 'intent' => 'menu_main'];
    }
    if ($route === 'commands') {
        return ['message' => broth_log_copilot_commands_text(), 'reply_markup' => ['inline_keyboard' => [broth_log_copilot_menu_back_row()]], 'intent' => 'menu_commands'];
    }
    if ($route === 'today') {
        $branches = $user['allowed_branch_list'] ?? [];
        if (count($branches) === 1) return broth_log_copilot_menu_log_view($user, strtoupper($branches[0]), $today, $now);
        return ['message' => "\u{1F4CB} Today's Broth Log", 'reply_markup' => broth_log_copilot_menu_branch_pick_keyboard($user, 'today', true), 'intent' => 'menu_branchpick'];
    }
    if ($route === 'issues_today') {
        $branches = $user['allowed_branch_list'] ?? [];
        if (count($branches) === 1) return broth_log_copilot_menu_issues_view($user, strtoupper($branches[0]), $today, true);
        return ['message' => "\u{1F6A8} Today's Issues", 'reply_markup' => broth_log_copilot_menu_branch_pick_keyboard($user, 'issues_today', false), 'intent' => 'menu_branchpick'];
    }
    if ($route === 'open') return broth_log_copilot_menu_open_issues_view($user, false);
    if ($route === 'open_critical') return broth_log_copilot_menu_open_issues_view($user, true);
    if ($route === 'ceo_summary') return broth_log_copilot_menu_ceo_summary_view($user, $now);
    if ($route === 'ceo_exceptions') return broth_log_copilot_menu_open_issues_view($user, true);

    if ($route === 'branchpick') {
        // menu:branchpick:<forView>
        $forView = $parts[2] ?? '';
        $label = $forView === 'logdate' ? "\u{1F5D3} Choose Date" : ($forView === 'issuedate' ? "\u{1F50E} Issues by Date" : "\u{1F4CB} Today's Broth Log");
        $includeAll = in_array($forView, ['today'], true);
        return ['message' => $label, 'reply_markup' => broth_log_copilot_menu_branch_pick_keyboard($user, $forView, $includeAll), 'intent' => 'menu_branchpick'];
    }
    if ($route === 'branchsel') {
        // menu:branchsel:<forView>:<branch>
        $forView = $parts[2] ?? '';
        $branch = strtoupper($parts[3] ?? '');
        if ($forView === 'today') return broth_log_copilot_menu_log_view($user, $branch, $today, $now);
        if ($forView === 'issues_today') return broth_log_copilot_menu_issues_view($user, $branch, $today, true);
        if ($forView === 'logdate') return ['message' => 'Choose Date', 'reply_markup' => broth_log_copilot_menu_quick_date_keyboard('log', $branch), 'intent' => 'menu_datepick'];
        if ($forView === 'issuedate') return ['message' => 'Choose Date', 'reply_markup' => broth_log_copilot_menu_quick_date_keyboard('issues', $branch), 'intent' => 'menu_datepick'];
        return null;
    }
    if ($route === 'qdate') {
        // menu:qdate:<kind>:<branch>:<key>
        $kind = $parts[2] ?? 'log';
        $branch = strtoupper($parts[3] ?? '');
        $key = $parts[4] ?? '';
        if ($key === 'cancel') return ['message' => broth_log_copilot_help_text(), 'reply_markup' => broth_log_copilot_menu_main_keyboard($roleClass), 'intent' => 'menu_main'];
        if ($key === 'enter') {
            broth_log_copilot_menu_set_date_context($user['telegram_user_id'], $kind, $branch);
            return ['message' => "Please enter the date you want to check.\n\nExamples:\n08/21/2026\n2026-08-21\nAugust 21", 'reply_markup' => broth_log_copilot_menu_date_cancel_keyboard(), 'intent' => 'menu_date_prompt'];
        }
        $date = $key === 'yesterday' ? broth_log_business_now($now)->modify('-1 day')->format('Y-m-d')
            : ($key === '2d' ? broth_log_business_now($now)->modify('-2 days')->format('Y-m-d') : $today);
        return $kind === 'issues' ? broth_log_copilot_menu_issues_view($user, $branch, $date, $date === $today) : broth_log_copilot_menu_log_view($user, $branch, $date, $now);
    }
    if ($route === 'log') {
        $branch = strtoupper($parts[2] ?? '');
        $date = $parts[3] ?? $today;
        return broth_log_copilot_menu_log_view($user, $branch, $date, $now);
    }
    if ($route === 'fulllog') {
        $branch = strtoupper($parts[2] ?? '');
        $date = $parts[3] ?? $today;
        return broth_log_copilot_menu_full_log_view($user, $branch, $date);
    }
    if ($route === 'issues') {
        $branch = strtoupper($parts[2] ?? '');
        $date = $parts[3] ?? $today;
        return broth_log_copilot_menu_issues_view($user, $branch, $date, $date === $today);
    }
    return ['message' => "I didn't understand that action.", 'reply_markup' => ['inline_keyboard' => [broth_log_copilot_menu_back_row()]], 'intent' => 'menu_unknown'];
}

function broth_log_copilot_process_inbox(int $limit = 10, ?DateTimeImmutable $now = null): array {
    if (!broth_log_copilot_enabled()) return [];
    $now = $now ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $processed = [];
    foreach (q("SELECT * FROM broth_log_bot_inbox WHERE status='queued' ORDER BY received_at ASC LIMIT ?", [$limit]) as $row) {
        // Each row gets its own exception boundary. A single malformed/unexpected row (a parser
        // bug, a programming error, anything) must never abort the rest of this batch, and - just
        // as critically - must never prevent the caller (the worker script) from reaching the
        // escalation/reminder loop that runs after this whole function returns. Without this,
        // one poison row retried every cron tick can silently block food-safety reminders
        // indefinitely - confirmed as a real production incident on 2026-08-22.
        try {
            $telegramUserId = (string)($row['telegram_user_id'] ?? '');
            $user = broth_log_copilot_authorized_user($telegramUserId);

            // /pilotid is valid ONLY inside the dedicated Manager Onboarding Group
            // (broth_log_copilot_is_manager_onboarding_chat(), backed by
            // TELEGRAM_MANAGER_ONBOARDING_CHAT_ID - never the Alert/Fallback group, never a
            // private DM, never any other chat) - and this holds regardless of whether the sender
            // is already authorized. Checked before the authorization gate below on purpose (an
            // unauthorized sender must still be able to reach the onboarding chat), but the chat
            // restriction itself is enforced unconditionally here, never left to the !$user gate:
            // an authorized owner/manager does not bypass it just by being authorized. This is why
            // the check is on message text alone first, branching on chat only afterward - a wrong
            // chat is silently denied (zero Telegram send attempt, zero distinguishing reply) for
            // every sender alike, matching the deny-by-default path for every other command. It
            // never creates, modifies, or reads broth_log_authorized_users beyond the read-only
            // lookup above, and never touches broth_log_routing_rules.
            if ((string)($row['update_type'] ?? '') === 'message'
                && broth_log_copilot_is_pilot_id_text((string)$row['message_text'])) {
                if (!broth_log_copilot_is_manager_onboarding_chat((string)$row['chat_id'])) {
                    run("UPDATE broth_log_bot_inbox SET status='denied', processed_at=datetime('now') WHERE update_id=?", [$row['update_id']]);
                    $processed[] = ['update_id' => $row['update_id'], 'status' => 'denied', 'intent' => 'pilot_id'];
                    continue;
                }
                $lang = $user['preferred_language'] ?? broth_log_copilot_detect_language((string)$row['message_text']);
                $response = broth_log_copilot_pilot_id_response($user, $lang);
                $send = broth_log_copilot_send_telegram_message((string)$row['chat_id'], $response['message']);
                if (!empty($send['sent'])) {
                    run("UPDATE broth_log_bot_inbox SET status='processed', processed_at=datetime('now'), outbound_status='sent', outbound_error=NULL, outbound_sent_at=datetime('now') WHERE update_id=?", [$row['update_id']]);
                    $processed[] = ['update_id' => $row['update_id'], 'status' => 'processed', 'intent' => 'pilot_id', 'outbound' => 'sent'];
                    continue;
                }
                $reason = broth_log_copilot_sanitize_error((string)($send['reason'] ?? $send['error'] ?? 'send_failed'));
                run("UPDATE broth_log_bot_inbox SET status='send_failed', processed_at=datetime('now'), outbound_status='failed', outbound_error=? WHERE update_id=?", [$reason, $row['update_id']]);
                $processed[] = ['update_id' => $row['update_id'], 'status' => 'send_failed', 'intent' => 'pilot_id', 'outbound' => 'failed', 'reason' => $reason];
                continue;
            }

            // /start and /alerts in a private bot chat are the other pre-authorization carve-out:
            // any sender may register their private chat for future DM delivery, and check their
            // own status, without gaining any data access now. Scoped to chat_type='private' (the
            // real Telegram field, never inferred from chat_id == telegram_user_id) so this can
            // never be triggered from the Ops group or any other chat. Registration only writes to
            // broth_log_private_chat_registrations - never broth_log_authorized_users - keeping
            // identity/delivery registration and human-approved authorization fully independent.
            if ((string)($row['update_type'] ?? '') === 'message'
                && (string)($row['chat_type'] ?? '') === 'private'
                && (broth_log_copilot_is_private_start_text((string)$row['message_text']) || broth_log_copilot_is_private_alerts_status_text((string)$row['message_text']))) {
                $lang = $user['preferred_language'] ?? broth_log_copilot_detect_language((string)$row['message_text']);
                $isStatusCommand = broth_log_copilot_is_private_alerts_status_text((string)$row['message_text']);
                if (!$isStatusCommand) {
                    run("INSERT OR REPLACE INTO broth_log_private_chat_registrations (telegram_user_id, private_chat_id, registered_at, updated_at) VALUES (?,?,datetime('now'),datetime('now'))", [$telegramUserId, (string)$row['chat_id']]);
                }
                $response = broth_log_copilot_private_registration_response($user, $lang, $isStatusCommand);
                $send = broth_log_copilot_send_telegram_message((string)$row['chat_id'], $response['message']);
                if (!empty($send['sent'])) {
                    run("UPDATE broth_log_bot_inbox SET status='processed', processed_at=datetime('now'), outbound_status='sent', outbound_error=NULL, outbound_sent_at=datetime('now') WHERE update_id=?", [$row['update_id']]);
                    $processed[] = ['update_id' => $row['update_id'], 'status' => 'processed', 'intent' => $response['intent'], 'outbound' => 'sent'];
                    continue;
                }
                $reason = broth_log_copilot_sanitize_error((string)($send['reason'] ?? $send['error'] ?? 'send_failed'));
                run("UPDATE broth_log_bot_inbox SET status='send_failed', processed_at=datetime('now'), outbound_status='failed', outbound_error=? WHERE update_id=?", [$reason, $row['update_id']]);
                $processed[] = ['update_id' => $row['update_id'], 'status' => 'send_failed', 'intent' => $response['intent'], 'outbound' => 'failed', 'reason' => $reason];
                continue;
            }

            if (!$user) {
                run("UPDATE broth_log_bot_inbox SET status='denied', processed_at=datetime('now') WHERE update_id=?", [$row['update_id']]);
                $processed[] = ['update_id' => $row['update_id'], 'status' => 'denied'];
                continue;
            }
            if (($row['update_type'] ?? '') === 'callback_query') {
                $menuResponse = broth_log_copilot_menu_callback_response((string)$row['message_text'], $user, (string)($row['chat_type'] ?? ''), $now);
                $response = $menuResponse ?? broth_log_copilot_callback_response((string)$row['message_text'], $user, (string)$row['chat_id'], $now);
                $send = broth_log_copilot_send_telegram_message((string)$row['chat_id'], (string)$response['message'], $response['reply_markup'] ?? null);
                if (!empty($send['sent'])) {
                    run("UPDATE broth_log_bot_inbox SET status='processed', processed_at=datetime('now'), outbound_status='sent', outbound_error=NULL, outbound_sent_at=datetime('now') WHERE update_id=?", [$row['update_id']]);
                    $processed[] = ['update_id' => $row['update_id'], 'status' => 'processed', 'intent' => $response['intent'], 'outbound' => 'sent'];
                    continue;
                }
                $reason = broth_log_copilot_sanitize_error((string)($send['reason'] ?? $send['error'] ?? 'send_failed'));
                run("UPDATE broth_log_bot_inbox SET status='send_failed', processed_at=datetime('now'), outbound_status='failed', outbound_error=? WHERE update_id=?", [$reason, $row['update_id']]);
                $processed[] = ['update_id' => $row['update_id'], 'status' => 'send_failed', 'intent' => $response['intent'], 'outbound' => 'failed', 'reason' => $reason];
                continue;
            }

            // Pending "Enter Date" reply from the menu takes priority over ordinary parsing -
            // returns null (falls through unchanged) unless this exact sender has an unexpired
            // menu_date context, so it can never intercept a normal text command.
            $menuDateResponse = broth_log_copilot_menu_date_entry_response((string)$row['message_text'], $user, $now);
            if ($menuDateResponse !== null) {
                $intentLabel = $menuDateResponse['intent'];
                unset($menuDateResponse['_reprompt']);
                $send = broth_log_copilot_send_telegram_message((string)$row['chat_id'], (string)$menuDateResponse['message'], $menuDateResponse['reply_markup'] ?? null);
                if (!empty($send['sent'])) {
                    run("UPDATE broth_log_bot_inbox SET status='processed', processed_at=datetime('now'), outbound_status='sent', outbound_error=NULL, outbound_sent_at=datetime('now') WHERE update_id=?", [$row['update_id']]);
                    $processed[] = ['update_id' => $row['update_id'], 'status' => 'processed', 'intent' => $intentLabel, 'outbound' => 'sent'];
                    continue;
                }
                $reason = broth_log_copilot_sanitize_error((string)($send['reason'] ?? $send['error'] ?? 'send_failed'));
                run("UPDATE broth_log_bot_inbox SET status='send_failed', processed_at=datetime('now'), outbound_status='failed', outbound_error=? WHERE update_id=?", [$reason, $row['update_id']]);
                $processed[] = ['update_id' => $row['update_id'], 'status' => 'send_failed', 'intent' => $intentLabel, 'outbound' => 'failed', 'reason' => $reason];
                continue;
            }

            $parsed = broth_log_copilot_parse((string)$row['message_text'], $user, $now);
            run("INSERT OR REPLACE INTO broth_log_conversation_context (telegram_user_id,context_json,expires_at,updated_at)
                 VALUES (?,?,datetime('now', '+24 hours'),datetime('now'))", [
                $telegramUserId,
                json_encode(['last_parse' => $parsed, 'chat_id' => $row['chat_id']]),
            ]);
            $actionResponse = broth_log_copilot_message_action_response((string)$row['message_text'], $parsed, $user, (string)$row['chat_id'], $now);
            if ($actionResponse) {
                $message = (string)$actionResponse['message'];
                $replyMarkup = null;
            } elseif (($parsed['intent'] ?? '') === 'help') {
                $helpResponse = broth_log_copilot_help_response($user, (string)($row['chat_type'] ?? ''));
                $message = (string)$helpResponse['message'];
                $replyMarkup = $helpResponse['reply_markup'] ?? null;
                $actionResponse = ['intent' => $helpResponse['intent']];
            } else {
                $message = broth_log_copilot_format_response($parsed, $user);
                $replyMarkup = null;
            }
            $send = broth_log_copilot_send_telegram_message((string)$row['chat_id'], $message, $replyMarkup);
            if (!empty($send['sent'])) {
                run("UPDATE broth_log_bot_inbox SET status='processed', processed_at=datetime('now'), outbound_status='sent', outbound_error=NULL, outbound_sent_at=datetime('now') WHERE update_id=?", [$row['update_id']]);
                $processed[] = ['update_id' => $row['update_id'], 'status' => 'processed', 'intent' => $actionResponse['intent'] ?? $parsed['intent'], 'language' => $parsed['language'], 'outbound' => 'sent'];
                continue;
            }
            $reason = broth_log_copilot_sanitize_error((string)($send['reason'] ?? $send['error'] ?? 'send_failed'));
            run("UPDATE broth_log_bot_inbox SET status='send_failed', processed_at=datetime('now'), outbound_status='failed', outbound_error=? WHERE update_id=?", [$reason, $row['update_id']]);
            $processed[] = ['update_id' => $row['update_id'], 'status' => 'send_failed', 'intent' => $parsed['intent'], 'language' => $parsed['language'], 'outbound' => 'failed', 'reason' => $reason];
        } catch (Throwable $e) {
            // Roll back defensively in case the exception left a transaction open. Harmless if
            // none was active - every mutating helper this loop calls (ack/resolve/escalation
            // actions) already manages its own BEGIN IMMEDIATE/COMMIT/ROLLBACK internally, so this
            // is a safety net for the unexpected, not the expected path.
            try { db()->exec('ROLLBACK'); } catch (Throwable $ignored) {}
            $reason = broth_log_copilot_classify_db_exception($e);
            if ($reason === 'lock_failed') {
                // Transient: leave the row queued so the next cron tick retries it naturally once
                // whatever held the lock releases it - retrying is exactly the right response.
                $processed[] = ['update_id' => $row['update_id'], 'status' => 'queued', 'reason' => 'lock_failed'];
                continue;
            }
            // Anything else (a programming error, malformed data, an unexpected shape) would
            // throw identically on every future tick if left queued - the exact poison-row
            // failure mode this boundary exists to prevent. Move it out of the queued set
            // immediately, but keep it auditable via last_error - a sanitized category only,
            // never the raw exception message, stack trace, or payload.
            run("UPDATE broth_log_bot_inbox SET status='processing_failed', processed_at=datetime('now'), last_error=? WHERE update_id=?", [$reason, $row['update_id']]);
            $processed[] = ['update_id' => $row['update_id'], 'status' => 'processing_failed', 'reason' => $reason];
        }
    }
    return $processed;
}
