<?php
declare(strict_types=1);

require_once __DIR__ . '/broth-log-core.php';

const BROTH_LOG_COPILOT_STATES = ['detected','notified_level_1','acknowledged','escalated_level_2','escalated_level_3','resolved','closed','reopened','unacknowledged_critical'];
const BROTH_LOG_COPILOT_RETENTION_RAW_DAYS = 30;
const BROTH_LOG_COPILOT_CONTEXT_TTL_HOURS = 24;
const BROTH_LOG_COPILOT_INCIDENT_RETENTION_MONTHS = 12;
const BROTH_LOG_COPILOT_ESCALATION_LOCK_SECONDS = 120;

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
        "ALTER TABLE broth_log_routing_rules ADD COLUMN chat_id TEXT",
        "ALTER TABLE broth_log_bot_inbox ADD COLUMN outbound_status TEXT",
        "ALTER TABLE broth_log_bot_inbox ADD COLUMN outbound_error TEXT",
        "ALTER TABLE broth_log_bot_inbox ADD COLUMN outbound_sent_at TEXT",
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
    run("INSERT OR IGNORE INTO broth_log_bot_inbox (update_id,telegram_user_id,chat_id,message_id,update_type,payload_json,message_text)
         VALUES (?,?,?,?,?,?,?)", [
        $meta['update_id'],
        $meta['telegram_user_id'],
        $meta['chat_id'],
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

function broth_log_copilot_parse(string $text, ?array $user = null, ?DateTimeImmutable $now = null): array {
    $preferred = $user['preferred_language'] ?? null;
    $lang = broth_log_copilot_detect_language($text, $preferred);
    $n = broth_log_norm($text);
    $explicitDate = broth_log_copilot_extract_explicit_date($n, $now ?: new DateTimeImmutable('now', new DateTimeZone('UTC')));

    $intent = null;
    if (preg_match('#^/(start|help)#i', $text) || str_contains($n, 'help') || str_contains($n, 'ayuda') || str_contains($n, 'giup')) $intent = 'help';
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
        $sinceLast = $last ? $now->getTimestamp() - $last->getTimestamp() : PHP_INT_MAX;
        $level = (int)$incident['current_level'];
        if ($level < 3 && $ageInLevel >= 9 * 60) {
            $due[] = ['action' => 'escalate', 'incident' => $incident, 'to_level' => $level + 1];
        } elseif ($sinceLast >= 3 * 60) {
            // Level 3 has no reminder cap and never goes silent: it keeps reminding every 3
            // minutes indefinitely until ACK. The one-time crossing into "MOD fallback should
            // now engage" is recorded as a parallel audit marker (fallback_reminder), not as a
            // terminal state that would stop the Telegram pushes.
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
        // reminder logic below, so Telegram pushes keep going every 3 minutes until ACK.
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

function broth_log_copilot_incident_message(array $incident, string $kind, string $lang = 'en'): string {
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

function broth_log_copilot_incident_reply_markup(string $incidentId, ?DateTimeImmutable $now = null): array {
    $expiresAt = ($now ?: new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+15 minutes')->getTimestamp();
    $ack = broth_log_copilot_create_callback_token('ack', $incidentId, $expiresAt);
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
    $chats = broth_log_copilot_route_chat_ids((string)$incident['branch'], 1);
    if (!$chats) return ['sent' => false, 'reason' => 'no_active_route'];
    $results = [];
    foreach ($chats as $chatId) {
        $results[] = broth_log_copilot_send_idempotent(
            'incident:' . $incidentId . ':notify:' . $chatId,
            $incidentId,
            $chatId,
            'incident_notification',
            broth_log_copilot_incident_message($incident, 'notify'),
            broth_log_copilot_incident_reply_markup($incidentId, $now)
        );
    }
    if (count(array_filter($results, fn($r) => !empty($r['sent']))) > 0) {
        broth_log_copilot_audit($incidentId, 'telegram_notified', null, ['level' => 1]);
    }
    return ['sent' => count(array_filter($results, fn($r) => !empty($r['sent']))) > 0, 'results' => $results];
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
    return ['message' => broth_log_copilot_tr('resolve_rejected', $lang, [broth_log_copilot_reason_word((string)($result['reason'] ?? ''), $lang)]), 'intent' => 'resolve_rejected'];
}

function broth_log_copilot_apply_escalation_action_with_notification(array $action, ?DateTimeImmutable $now = null): array {
    $result = broth_log_copilot_apply_escalation_action($action, $now);
    if (empty($result['ok'])) return $result;
    $incident = broth_log_copilot_incident_from_result((string)$result['incident_id']);
    if (!$incident) return $result + ['outbound' => 'incident_missing'];
    $kind = $result['action'] === 'fallback' ? 'fallback' : ($result['action'] === 'escalated' ? 'escalation' : 'reminder');
    $level = (int)($result['level'] ?? $incident['current_level'] ?? 1);
    $chats = broth_log_copilot_route_chat_ids((string)$incident['branch'], $level);
    $sent = 0;
    foreach ($chats as $chatId) {
        $deliveryKey = 'incident:' . $incident['incident_id'] . ':' . $kind . ':' . ($incident['reminder_count'] ?? 0) . ':' . $level . ':' . $chatId;
        $send = broth_log_copilot_send_idempotent($deliveryKey, (string)$incident['incident_id'], $chatId, $kind, broth_log_copilot_incident_message($incident, $kind), broth_log_copilot_incident_reply_markup((string)$incident['incident_id'], $now));
        if (!empty($send['sent'])) $sent++;
    }
    return $result + ['outbound_sent' => $sent];
}

function broth_log_copilot_process_inbox(int $limit = 10, ?DateTimeImmutable $now = null): array {
    if (!broth_log_copilot_enabled()) return [];
    $now = $now ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $processed = [];
    foreach (q("SELECT * FROM broth_log_bot_inbox WHERE status='queued' ORDER BY received_at ASC LIMIT ?", [$limit]) as $row) {
        $telegramUserId = (string)($row['telegram_user_id'] ?? '');
        $user = broth_log_copilot_authorized_user($telegramUserId);
        if (!$user) {
            run("UPDATE broth_log_bot_inbox SET status='denied', processed_at=datetime('now') WHERE update_id=?", [$row['update_id']]);
            $processed[] = ['update_id' => $row['update_id'], 'status' => 'denied'];
            continue;
        }
        if (($row['update_type'] ?? '') === 'callback_query') {
            $response = broth_log_copilot_callback_response((string)$row['message_text'], $user, (string)$row['chat_id'], $now);
            $send = broth_log_copilot_send_telegram_message((string)$row['chat_id'], (string)$response['message']);
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
        $parsed = broth_log_copilot_parse((string)$row['message_text'], $user, $now);
        run("INSERT OR REPLACE INTO broth_log_conversation_context (telegram_user_id,context_json,expires_at,updated_at)
             VALUES (?,?,datetime('now', '+24 hours'),datetime('now'))", [
            $telegramUserId,
            json_encode(['last_parse' => $parsed, 'chat_id' => $row['chat_id']]),
        ]);
        $actionResponse = broth_log_copilot_message_action_response((string)$row['message_text'], $parsed, $user, (string)$row['chat_id'], $now);
        $message = $actionResponse ? (string)$actionResponse['message'] : broth_log_copilot_format_response($parsed, $user);
        $send = broth_log_copilot_send_telegram_message((string)$row['chat_id'], $message);
        if (!empty($send['sent'])) {
            run("UPDATE broth_log_bot_inbox SET status='processed', processed_at=datetime('now'), outbound_status='sent', outbound_error=NULL, outbound_sent_at=datetime('now') WHERE update_id=?", [$row['update_id']]);
            $processed[] = ['update_id' => $row['update_id'], 'status' => 'processed', 'intent' => $actionResponse['intent'] ?? $parsed['intent'], 'language' => $parsed['language'], 'outbound' => 'sent'];
            continue;
        }
        $reason = broth_log_copilot_sanitize_error((string)($send['reason'] ?? $send['error'] ?? 'send_failed'));
        run("UPDATE broth_log_bot_inbox SET status='send_failed', processed_at=datetime('now'), outbound_status='failed', outbound_error=? WHERE update_id=?", [$reason, $row['update_id']]);
        $processed[] = ['update_id' => $row['update_id'], 'status' => 'send_failed', 'intent' => $parsed['intent'], 'language' => $parsed['language'], 'outbound' => 'failed', 'reason' => $reason];
    }
    return $processed;
}
