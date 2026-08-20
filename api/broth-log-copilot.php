<?php
declare(strict_types=1);

require_once __DIR__ . '/broth-log-core.php';

const BROTH_LOG_COPILOT_STATES = ['detected','notified_level_1','acknowledged','escalated_level_2','escalated_level_3','resolved','closed','reopened','unacknowledged_critical'];
const BROTH_LOG_COPILOT_RETENTION_RAW_DAYS = 30;
const BROTH_LOG_COPILOT_CONTEXT_TTL_HOURS = 24;
const BROTH_LOG_COPILOT_INCIDENT_RETENTION_MONTHS = 12;

function broth_log_copilot_enabled(): bool {
    return in_array(strtolower(trim((string)(getenv('TELEGRAM_COPILOT_ENABLED') ?: 'false'))), ['1','true','yes','on'], true);
}

function broth_log_copilot_env(string $key, string $default = ''): string {
    $value = getenv($key);
    return is_string($value) && $value !== '' ? trim($value) : $default;
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
    ");
    foreach ([
        "ALTER TABLE broth_log_incidents ADD COLUMN active_key TEXT",
    ] as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) {}
    }
    foreach ([
        "CREATE INDEX IF NOT EXISTS idx_broth_log_bot_inbox_status ON broth_log_bot_inbox(status, received_at)",
        "CREATE INDEX IF NOT EXISTS idx_broth_log_incidents_open ON broth_log_incidents(state, branch, updated_at)",
        "CREATE UNIQUE INDEX IF NOT EXISTS idx_broth_log_incidents_active_key ON broth_log_incidents(active_key)",
        "CREATE INDEX IF NOT EXISTS idx_broth_log_incident_events_incident ON broth_log_incident_events(incident_id, created_at)",
        "CREATE INDEX IF NOT EXISTS idx_broth_log_routing_rules_active ON broth_log_routing_rules(branch, stage, level, active)",
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
    return [
        'update_id' => (string)($update['update_id'] ?? ''),
        'telegram_user_id' => isset($from['id']) ? (string)$from['id'] : '',
        'chat_id' => isset($chat['id']) ? (string)$chat['id'] : '',
        'message_id' => isset($message['message_id']) ? (string)$message['message_id'] : '',
        'update_type' => $callback ? 'callback_query' : 'message',
        'text' => trim((string)($callback['data'] ?? ($message['text'] ?? ''))),
    ];
}

function broth_log_copilot_enqueue_webhook(array $update): array {
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
        json_encode($update),
        broth_log_copilot_sanitize_message($meta['text']),
    ]);
    return ['queued' => db()->changes() > 0, 'update_id' => $meta['update_id']];
}

function broth_log_copilot_sanitize_message(string $message): string {
    $message = preg_replace('/[0-9]{8,12}:AA[A-Za-z0-9_-]{20,}/', '[redacted-token]', $message) ?: '';
    return substr($message, 0, 1000);
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
    preg_match('/(-?\d+(?:\.\d+)?)\s*(?:f|°f|degrees)?/i', $text, $tm);
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
        'temperature_f' => isset($tm[1]) ? (float)$tm[1] : null,
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

function broth_log_copilot_create_incident(array $alert): string {
    $stationKey = (string)($alert['stationKey'] ?? '');
    $fingerprint = hash('sha256', implode('|', [$alert['branch'] ?? '', $alert['responseId'] ?? '', $stationKey ?: ($alert['station'] ?? ''), $alert['severity'] ?? 'critical', $alert['businessDate'] ?? '']));
    $existing = q1("SELECT incident_id FROM broth_log_incidents WHERE active_key=?", [$fingerprint]);
    if ($existing) return (string)$existing['incident_id'];
    $incidentId = 'bl-' . substr($fingerprint, 0, 10) . '-' . gmdate('YmdHis');
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
    $now = $now ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $due = [];
    foreach (q("SELECT * FROM broth_log_incidents WHERE state IN ('detected','notified_level_1','escalated_level_2','escalated_level_3')") as $incident) {
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
    $incident = $action['incident'];
    $ts = ($now ?: new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    if ($action['action'] === 'escalate') {
        $level = (int)$action['to_level'];
        $state = $level === 2 ? 'escalated_level_2' : 'escalated_level_3';
        run("UPDATE broth_log_incidents SET state=?, current_level=?, last_reminder_at=?, reminder_count=0, updated_at=datetime('now') WHERE incident_id=?", [$state, $level, $ts, $incident['incident_id']]);
        broth_log_copilot_audit($incident['incident_id'], $state, null, []);
        return ['ok' => true, 'action' => 'escalated', 'level' => $level, 'incident_id' => $incident['incident_id']];
    }
    if ($action['action'] === 'fallback') {
        run("UPDATE broth_log_incidents SET state='unacknowledged_critical', last_reminder_at=?, updated_at=datetime('now') WHERE incident_id=?", [$ts, $incident['incident_id']]);
        broth_log_copilot_audit($incident['incident_id'], 'fallback_required', null, ['fallback' => broth_log_copilot_env('TELEGRAM_LEVEL3_FALLBACK', 'operations manual fallback')]);
        return ['ok' => true, 'action' => 'fallback', 'incident_id' => $incident['incident_id']];
    }
    run("UPDATE broth_log_incidents SET state=CASE WHEN state='detected' THEN 'notified_level_1' ELSE state END, last_reminder_at=?, reminder_count=reminder_count+1, updated_at=datetime('now') WHERE incident_id=?", [$ts, $incident['incident_id']]);
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

function broth_log_copilot_process_inbox(int $limit = 10, ?DateTimeImmutable $now = null): array {
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
        $parsed = broth_log_copilot_parse((string)$row['message_text'], $user, $now);
        run("INSERT OR REPLACE INTO broth_log_conversation_context (telegram_user_id,context_json,expires_at,updated_at)
             VALUES (?,?,datetime('now', '+24 hours'),datetime('now'))", [
            $telegramUserId,
            json_encode(['last_parse' => $parsed, 'chat_id' => $row['chat_id']]),
        ]);
        run("UPDATE broth_log_bot_inbox SET status='processed', processed_at=datetime('now') WHERE update_id=?", [$row['update_id']]);
        $processed[] = ['update_id' => $row['update_id'], 'status' => 'processed', 'intent' => $parsed['intent'], 'language' => $parsed['language']];
    }
    return $processed;
}
