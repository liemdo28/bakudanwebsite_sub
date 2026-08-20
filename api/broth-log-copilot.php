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
        '/\b(?:api[_-]?key|secret|token|password|passwd|pwd)\s*[:=]\s*[A-Za-z0-9._~+\/=-]{8,}\b/i' => '$1=[redacted]',
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
    if ($raw === false || $httpCode < 200 || $httpCode >= 300) {
        return ['sent' => false, 'reason' => 'telegram_api_error', 'http_code' => $httpCode];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || empty($decoded['ok'])) {
        return ['sent' => false, 'reason' => 'telegram_rejected', 'http_code' => $httpCode, 'error' => broth_log_copilot_sanitize_error($raw)];
    }
    return ['sent' => true, 'http_code' => $httpCode];
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

function broth_log_copilot_parse(string $text, ?array $user = null, ?DateTimeImmutable $now = null): array {
    $preferred = $user['preferred_language'] ?? null;
    $lang = broth_log_copilot_detect_language($text, $preferred);
    $n = broth_log_norm($text);
    $intent = 'help';
    if (preg_match('#^/(start|help)#i', $text) || str_contains($n, 'help') || str_contains($n, 'ayuda') || str_contains($n, 'giup')) $intent = 'help';
    elseif (preg_match('#^/(today|status)#i', $text) || str_contains($n, 'today') || str_contains($n, 'hoy') || str_contains($n, 'hom nay') || str_contains($n, 'status')) $intent = 'today_summary';
    elseif (preg_match('#^/(critical|issues)#i', $text) || str_contains($n, 'critical') || str_contains($n, 'critico') || str_contains($n, 'nghiem trong')) $intent = 'critical_issues';
    elseif (str_contains($n, 'open') || str_contains($n, 'pending') || str_contains($n, 'pendiente') || str_contains($n, 'con mo') || str_contains($n, 'chua xu ly')) $intent = 'open_issues';
    elseif (preg_match('#^/missing#i', $text) || str_contains($n, 'missing') || str_contains($n, 'incomplete') || str_contains($n, 'faltante') || str_contains($n, 'thieu')) $intent = 'missing_logs';
    elseif (preg_match('#^/ack#i', $text) || preg_match('/\back\b/i', $text) || str_contains($n, 'acknowledged') || str_contains($n, 'received') || str_contains($n, 'got it') || str_contains($n, 'recibido') || str_contains($n, 'entendido') || str_contains($n, 'da nhan') || str_contains($n, 'dang xu ly')) $intent = 'ack';
    elseif (preg_match('#^/resolve#i', $text) || str_contains($n, 'resolved') || str_contains($n, 'fixed') || str_contains($n, 'resuelto') || str_contains($n, 'corregido') || str_contains($n, 'da xu ly') || str_contains($n, 'da sua')) $intent = 'resolve';
    elseif (str_contains($n, 'temperature') || str_contains($n, 'temp') || str_contains($n, 'temperatura') || str_contains($n, 'nhiet do') || str_contains($n, 'safe') || str_contains($n, 'an toan')) $intent = 'temperature_lookup';
    elseif (str_contains($n, 'sop') || str_contains($n, 'corrective') || str_contains($n, 'accion correctiva') || str_contains($n, 'khac phuc')) $intent = 'sop_comparison';

    preg_match('/\b(B[123])\b/i', $text, $bm);
    $branch = isset($bm[1]) ? strtoupper($bm[1]) : null;
    $date = null;
    if (str_contains($n, 'yesterday') || str_contains($n, 'ayer') || str_contains($n, 'hom qua')) {
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
    run("INSERT OR IGNORE INTO broth_log_incidents
        (incident_id,fingerprint,active_key,branch,business_date,business_time,response_id,station_key,station_label,temperature_f,sop_target,severity,corrective_action,state,current_level,source_revision_hash)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)", [
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
        hash('sha256', json_encode($alert)),
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
        run("UPDATE broth_log_incidents SET state='acknowledged', owner_telegram_user_id=?, acknowledged_by=?, acknowledged_at=?, last_reminder_at=NULL, updated_at=datetime('now') WHERE incident_id=?", [
            $actor['telegram_user_id'],
            $actor['telegram_user_id'],
            $ts,
            $incidentId,
        ]);
        db()->exec('COMMIT');
    } catch (Throwable $e) {
        try { db()->exec('ROLLBACK'); } catch (Throwable $ignored) {}
        return ['ok' => false, 'reason' => 'lock_failed'];
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
        return ['ok' => false, 'reason' => 'lock_failed'];
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
        $last = $incident['last_reminder_at'] ? new DateTimeImmutable($incident['last_reminder_at'] . ' UTC') : null;
        $age = $now->getTimestamp() - $created->getTimestamp();
        $sinceLast = $last ? $now->getTimestamp() - $last->getTimestamp() : PHP_INT_MAX;
        $level = (int)$incident['current_level'];
        if ($level < 3 && $age >= 9 * 60) $due[] = ['action' => 'escalate', 'incident' => $incident, 'to_level' => $level + 1];
        elseif ($level === 3 && (int)$incident['reminder_count'] >= 10) $due[] = ['action' => 'fallback', 'incident' => $incident];
        elseif ($sinceLast >= 3 * 60) $due[] = ['action' => 'remind', 'incident' => $incident, 'level' => $level];
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
        return ['ok' => false, 'reason' => 'lock_failed', 'incident_id' => $incident['incident_id']];
    }
    if ($action['action'] === 'escalate') {
        $level = (int)$action['to_level'];
        $state = $level === 2 ? 'escalated_level_2' : 'escalated_level_3';
        run("UPDATE broth_log_incidents SET state=?, current_level=?, last_reminder_at=?, reminder_count=0, escalation_lock_expires_at=NULL, escalation_lock_token=NULL, updated_at=datetime('now') WHERE incident_id=? AND escalation_lock_token=?", [$state, $level, $ts, $incident['incident_id'], $lockToken]);
        broth_log_copilot_audit($incident['incident_id'], $state, null, []);
        return ['ok' => true, 'action' => 'escalated', 'level' => $level, 'incident_id' => $incident['incident_id']];
    }
    if ($action['action'] === 'fallback') {
        run("UPDATE broth_log_incidents SET state='unacknowledged_critical', last_reminder_at=?, escalation_lock_expires_at=NULL, escalation_lock_token=NULL, updated_at=datetime('now') WHERE incident_id=? AND escalation_lock_token=?", [$ts, $incident['incident_id'], $lockToken]);
        broth_log_copilot_audit($incident['incident_id'], 'fallback_required', null, ['fallback' => broth_log_copilot_env('TELEGRAM_LEVEL3_FALLBACK', 'operations manual fallback')]);
        return ['ok' => true, 'action' => 'fallback', 'incident_id' => $incident['incident_id']];
    }
    run("UPDATE broth_log_incidents SET state=CASE WHEN state='detected' THEN 'notified_level_1' ELSE state END, last_reminder_at=?, reminder_count=reminder_count+1, escalation_lock_expires_at=NULL, escalation_lock_token=NULL, updated_at=datetime('now') WHERE incident_id=? AND escalation_lock_token=?", [$ts, $incident['incident_id'], $lockToken]);
    broth_log_copilot_audit($incident['incident_id'], 'reminder_sent', null, ['level' => (int)$incident['current_level']]);
    return ['ok' => true, 'action' => 'reminded', 'incident_id' => $incident['incident_id']];
}

function broth_log_copilot_query_response(array $parsed, array $user): array {
    $branch = $parsed['branch'];
    if (!$branch && count($user['allowed_branch_list']) === 1) $branch = strtoupper($user['allowed_branch_list'][0]);
    if (!$branch) return ['needs_clarification' => true, 'message' => 'Which store should I check: B1, B2, or B3?'];
    if (!broth_log_copilot_user_can_branch($user, $branch)) return ['forbidden' => true, 'message' => 'I cannot access that store for this account.'];
    $date = $parsed['business_date'] ?: broth_log_business_date();
    $records = broth_log_filter_records(broth_log_fetch_branch_records($branch), ['branch' => $branch, 'businessDate' => $date]);
    $summary = broth_log_summary($records);
    return ['records' => $records, 'summary' => $summary, 'branch' => $branch, 'businessDate' => $date];
}

function broth_log_copilot_active_route_exists(string $branch, int $level): bool {
    $stage = broth_log_copilot_is_staging() ? 'staging' : 'pilot';
    $row = q1("SELECT telegram_user_ids FROM broth_log_routing_rules WHERE branch=? AND stage=? AND level=? AND active=1", [strtoupper($branch), $stage, $level]);
    if (!$row) return false;
    $ids = json_decode((string)$row['telegram_user_ids'], true);
    return is_array($ids) && count($ids) > 0;
}

function broth_log_copilot_route_chat_ids(string $branch, int $level): array {
    if (!broth_log_copilot_active_route_exists($branch, $level)) return [];
    $chatId = broth_log_copilot_env('TELEGRAM_CHAT_ID');
    return $chatId !== '' ? [$chatId] : [];
}

function broth_log_copilot_incident_message(array $incident, string $kind): string {
    $label = match ($kind) {
        'ack_confirm' => 'Incident acknowledged',
        'resolve_confirm' => 'Incident resolved',
        'escalation' => 'Incident escalated',
        'fallback' => 'Emergency fallback required',
        'reminder' => 'Incident reminder',
        default => 'Critical Broth Log incident',
    };
    $lines = [
        $label,
        'Store: ' . (string)$incident['branch'],
        'Business: ' . trim((string)$incident['business_date'] . ' ' . (string)($incident['business_time'] ?? '')),
        'Item: ' . (string)$incident['station_label'],
        'Recorded: ' . (($incident['temperature_f'] ?? null) === null ? 'missing' : rtrim(rtrim((string)$incident['temperature_f'], '0'), '.') . 'F'),
        'SOP: ' . (string)$incident['sop_target'],
        'Severity: ' . (string)$incident['severity'],
        'Action: ' . (string)$incident['corrective_action'],
        'Ref: #' . (string)$incident['incident_id'],
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

function broth_log_copilot_format_response(array $parsed, array $user): string {
    $lang = $parsed['language'] ?? ($user['preferred_language'] ?? 'en');
    if (($parsed['intent'] ?? '') === 'help') {
        return match ($lang) {
            'es' => 'Puedo revisar Today, critical issues, missing logs y open issues para tus tiendas autorizadas.',
            'vi' => 'Toi co the xem Today, critical issues, missing logs va open issues cho chi nhanh ban duoc cap quyen.',
            default => 'I can check Today, critical issues, missing logs, and open issues for your authorized stores.',
        };
    }
    if (in_array($parsed['intent'] ?? '', ['ack','resolve'], true)) {
        return 'Use the signed incident buttons or include an incident id so I can apply this safely.';
    }
    if (in_array($parsed['intent'] ?? '', ['today_summary','critical_issues','open_issues','missing_logs','temperature_lookup','sop_comparison'], true)) {
        $response = broth_log_copilot_query_response($parsed, $user);
        if (!empty($response['forbidden'])) return (string)$response['message'];
        if (!empty($response['needs_clarification'])) return (string)$response['message'];
        $summary = $response['summary'] ?? [];
        $branch = $response['branch'] ?? ($parsed['branch'] ?? 'Store');
        $date = $response['businessDate'] ?? ($parsed['business_date'] ?? broth_log_business_date());
        $total = (int)($summary['total'] ?? 0);
        $critical = (int)($summary['critical'] ?? 0);
        $missing = (int)($summary['missing'] ?? 0);
        return "$branch $date: $total log(s), $critical critical, $missing missing.";
    }
    return 'I did not understand that yet. Try: Today B1, critical B1, missing B1, or open B1.';
}

function broth_log_copilot_incident_from_result(string $incidentId): ?array {
    return q1("SELECT * FROM broth_log_incidents WHERE incident_id=?", [$incidentId]);
}

function broth_log_copilot_callback_response(string $callbackData, array $user, string $chatId, ?DateTimeImmutable $now = null): array {
    $now = $now ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $callback = broth_log_copilot_consume_callback($callbackData, $now->getTimestamp());
    if (!$callback) {
        return ['message' => 'Callback expired, already used, or invalid.', 'intent' => 'callback_rejected'];
    }
    $incident = broth_log_copilot_incident_from_result((string)$callback['incident_id']);
    if (!$incident || in_array($incident['state'], ['resolved','closed'], true)) {
        return ['message' => 'Incident is not open.', 'intent' => 'callback_stale'];
    }
    if (!broth_log_copilot_user_can_branch($user, (string)$incident['branch'])) {
        return ['message' => 'I cannot apply this incident action for your account.', 'intent' => 'callback_forbidden'];
    }
    if ($callback['action'] === 'ack') {
        $result = broth_log_copilot_ack((string)$incident['incident_id'], $user, $now);
        if (!empty($result['ok'])) {
            $fresh = broth_log_copilot_incident_from_result((string)$incident['incident_id']) ?: $incident;
            return ['message' => broth_log_copilot_incident_message($fresh, 'ack_confirm'), 'intent' => 'ack'];
        }
        return ['message' => 'ACK was rejected: ' . (string)($result['reason'] ?? 'unknown'), 'intent' => 'ack_rejected'];
    }
    if ($callback['action'] === 'resolve') {
        run("INSERT OR REPLACE INTO broth_log_conversation_context (telegram_user_id,context_json,expires_at,updated_at)
             VALUES (?,?,datetime('now', '+24 hours'),datetime('now'))", [
            $user['telegram_user_id'],
            json_encode(['pending' => 'resolve', 'incident_id' => $incident['incident_id'], 'chat_id' => $chatId]),
        ]);
        return ['message' => 'Resolve #' . $incident['incident_id'] . " by replying with safe recheck temp and corrective action note. Example: /resolve #" . $incident['incident_id'] . " 38F closed door and moved product.", 'intent' => 'resolve_prompt'];
    }
    return ['message' => 'Unsupported incident action.', 'intent' => 'callback_rejected'];
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
    $incidentId = (string)($parsed['incident_id'] ?? '');
    if ($incidentId === '') {
        $context = q1("SELECT context_json,expires_at FROM broth_log_conversation_context WHERE telegram_user_id=? AND expires_at > datetime('now')", [$user['telegram_user_id']]);
        $ctx = $context ? (json_decode((string)$context['context_json'], true) ?: []) : [];
        if (($ctx['pending'] ?? '') === $intent || (($ctx['pending'] ?? '') === 'resolve' && $intent === 'resolve')) {
            $incidentId = (string)($ctx['incident_id'] ?? '');
        }
    }
    if ($incidentId === '') return ['message' => 'Include an incident reference like #bl-...', 'intent' => $intent . '_rejected'];
    if ($intent === 'ack') {
        $result = broth_log_copilot_ack($incidentId, $user, $now);
        if (!empty($result['ok'])) {
            $incident = broth_log_copilot_incident_from_result($incidentId);
            return ['message' => broth_log_copilot_incident_message($incident ?: ['incident_id' => $incidentId], 'ack_confirm'), 'intent' => 'ack'];
        }
        return ['message' => 'ACK was rejected: ' . (string)($result['reason'] ?? 'unknown'), 'intent' => 'ack_rejected'];
    }
    $note = broth_log_copilot_resolution_note($messageText, $parsed);
    $result = broth_log_copilot_resolve($incidentId, $user, $parsed['temperature_f'] ?? null, $note, $now);
    if (!empty($result['ok'])) {
        run("DELETE FROM broth_log_conversation_context WHERE telegram_user_id=?", [$user['telegram_user_id']]);
        $incident = broth_log_copilot_incident_from_result($incidentId);
        return ['message' => broth_log_copilot_incident_message($incident ?: ['incident_id' => $incidentId], 'resolve_confirm'), 'intent' => 'resolve'];
    }
    return ['message' => 'Resolve was rejected: ' . (string)($result['reason'] ?? 'unknown') . '. Include a safe recheck temperature and corrective-action note.', 'intent' => 'resolve_rejected'];
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
