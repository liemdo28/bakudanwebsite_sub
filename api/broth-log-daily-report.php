<?php
declare(strict_types=1);

// Daily Broth Log Report (library). Pure functions plus a runner; the CLI entrypoint is
// scripts/broth-log-daily-report.php. The caller must define db()/q()/q1()/run() (same contract as
// the Copilot worker and the test harness).
//
// DATA INTEGRITY: every value comes from the production Broth Log sheets (parsed with the canonical
// functions in broth-log-core.php: business-date, shift assignment/timing, SOP severity) or from the
// production incident/delivery tables. Nothing is estimated or filled in; anything absent is printed
// as "Missing / No production record". Reporting only ever READS Broth Log data - the one table this
// module writes is its own execution record (broth_log_daily_report_runs).

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/broth-log-core.php';
require_once __DIR__ . '/broth-log-copilot.php';

const BLDR_RUN_HOUR_LOCAL = 23;          // 11:00 PM America/Chicago
const BLDR_CATCHUP_UNTIL_HOUR_LOCAL = 5; // a missed run is still completed until 05:00 local the next morning
const BLDR_MAX_EMAIL_ATTEMPTS = 5;
const BLDR_MAX_GENERATE_ATTEMPTS = 5;
const BLDR_STALE_ATTEMPT_SECONDS = 900;
const BLDR_DEFAULT_RECIPIENT = 'liem.dt0208@gmail.com';
const BLDR_DEFAULT_FROM = 'broth-log-reports@bakudanramen.com';
const BLDR_MISSING = 'Missing / No production record';

// ---------------------------------------------------------------------------------------------
// Schedule: DST-safe by construction. Cron only ticks (every 5 min, any server timezone); whether a
// report is due is decided here from the America/Chicago wall clock, never from a UTC offset.
// ---------------------------------------------------------------------------------------------

// The business date whose report is due at $now, or null if nothing is due. From 23:00 local until
// 23:59 it is today's Texas date; from 00:00 until 04:59 it is still yesterday's (catch-up for a
// missed run). Outside those hours nothing is due.
function bldr_due_business_date(DateTimeImmutable $now): ?array {
    $local = broth_log_business_now($now);
    $hour = (int)$local->format('G');
    if ($hour >= BLDR_RUN_HOUR_LOCAL) {
        $date = $local->format('Y-m-d');
    } elseif ($hour < BLDR_CATCHUP_UNTIL_HOUR_LOCAL) {
        $date = $local->modify('-1 day')->format('Y-m-d');
    } else {
        return null;
    }
    $scheduled = DateTimeImmutable::createFromFormat('Y-m-d H:i', $date . ' ' . sprintf('%02d:00', BLDR_RUN_HOUR_LOCAL), new DateTimeZone(BROTH_LOG_BUSINESS_TIMEZONE));
    return [
        'business_date' => $date,
        'scheduled_local' => $scheduled->format('Y-m-d H:i T'),
        'late_minutes' => max(0, (int)floor(($now->getTimestamp() - $scheduled->getTimestamp()) / 60)),
    ];
}

// Next scheduled run (for verification output): the next 23:00 America/Chicago strictly after $now,
// and the business date it will report.
function bldr_next_run(DateTimeImmutable $now): array {
    $local = broth_log_business_now($now);
    $candidate = DateTimeImmutable::createFromFormat('Y-m-d H:i', $local->format('Y-m-d') . ' 23:00', new DateTimeZone(BROTH_LOG_BUSINESS_TIMEZONE));
    if ($candidate <= $now) $candidate = DateTimeImmutable::createFromFormat('Y-m-d H:i', $local->modify('+1 day')->format('Y-m-d') . ' 23:00', new DateTimeZone(BROTH_LOG_BUSINESS_TIMEZONE));
    return ['run_local' => $candidate->format('Y-m-d H:i T'), 'run_utc' => $candidate->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') . ' UTC', 'business_date' => $candidate->format('Y-m-d')];
}

// ---------------------------------------------------------------------------------------------
// Data collection
// ---------------------------------------------------------------------------------------------

function bldr_shift_label(string $status): string {
    return ['ON_TIME' => 'On Time', 'EARLY' => 'Early', 'LATE' => 'Late', 'MISSING' => 'Missing', 'NOT_YET_DUE' => 'Not yet due'][$status] ?? $status;
}

function bldr_local_time_label(?string $submittedAt): ?string {
    $parsed = broth_log_parse_submission_datetime((string)$submittedAt);
    return $parsed ? $parsed->format('g:i A') : null;
}

// Plain-number check used only to FLAG (never to alter) a raw cell that the canonical parser still
// turned into a number, e.g. "12:30" -> 1230. A plain reading may carry a degree sign / F suffix.
function bldr_raw_is_plain_number(string $raw): bool {
    $stripped = preg_replace('/[\s\x{00B0}fF]/u', '', $raw) ?? $raw;
    return (bool)preg_match('/^-?\d+(\.\d+)?$/', $stripped);
}

// One branch's sheet table -> everything the report shows for that store and business date.
// $table is the gviz table (cols/rows) exactly as broth_log_gviz_table() returns it.
function bldr_collect_store(string $branch, array $table, string $date, DateTimeImmutable $now): array {
    $cols = $table['cols'] ?? [];
    $index = broth_log_build_index($cols);
    $records = [];
    $submissions = [];
    foreach ($table['rows'] ?? [] as $row) {
        $record = broth_log_normalize_row($row, $cols, $branch);
        if (!broth_log_filter_records([$record], ['branch' => $branch, 'businessDate' => $date])) continue;
        $cells = $row['c'] ?? [];
        $stations = [];
        $assigned = null;
        $parsed = broth_log_parse_submission_datetime((string)$record['submittedAt']);
        if ($parsed) $assigned = broth_log_shift_assignment($parsed);
        foreach ($record['readings'] as $reading) {
            $key = $reading['key'];
            $columnFound = ($index[$key] ?? -1) >= 0;
            $raw = $columnFound ? broth_log_cell_value($cells[$index[$key]] ?? null) : '';
            if (!$columnFound) $class = 'no_column';
            elseif ($raw === '') $class = 'blank';
            elseif ($reading['temperature'] === null) $class = 'non_numeric';
            elseif ($reading['severity'] === 'safe') $class = 'normal';
            elseif ($reading['severity'] === 'unknown_config') $class = 'config_gap';
            else $class = 'exception';
            $stations[] = [
                'key' => $key, 'label' => $reading['label'], 'raw' => $raw, 'temperature' => $reading['temperature'],
                'severity' => $reading['severity'], 'target' => $reading['target'], 'class' => $class,
                'raw_text_parsed' => ($reading['temperature'] !== null && !bldr_raw_is_plain_number($raw)),
            ];
        }
        $records[] = $record;
        $submissions[] = [
            'response_id' => $record['responseId'], 'employee' => $record['employeeName'], 'submitted_at_raw' => $record['submittedAt'],
            'local_time' => bldr_local_time_label($record['submittedAt']), 'assigned_shift' => $assigned, 'form_shift_field' => $record['shift'],
            'stations' => $stations,
        ];
    }
    $shifts = [];
    foreach (['AM', 'PM'] as $shift) {
        $status = broth_log_shift_daily_status($shift, $records, $date, $now);
        $count = 0;
        foreach ($submissions as $s) if ($s['assigned_shift'] === $shift) $count++;
        $shifts[$shift] = [
            'status' => $status['status'], 'label' => bldr_shift_label($status['status']),
            'employee' => $status['employee'], 'local_time' => bldr_local_time_label($status['submitted_at']), 'submitted_at_raw' => $status['submitted_at'],
            'submission_count' => $count,
        ];
    }
    $tally = ['normal' => 0, 'exception' => 0, 'non_numeric' => 0, 'blank' => 0, 'config_gap' => 0, 'no_column' => 0];
    $exceptions = [];
    $nonNumeric = [];
    $reviews = [];
    foreach ($submissions as $s) {
        foreach ($s['stations'] as $st) {
            $tally[$st['class']]++;
            $ctx = ['employee' => $s['employee'], 'local_time' => $s['local_time'], 'shift' => $s['assigned_shift'], 'station' => $st['label'], 'raw' => $st['raw'], 'target' => $st['target'], 'severity' => $st['severity'], 'temperature' => $st['temperature']];
            if ($st['class'] === 'exception' || $st['class'] === 'config_gap') $exceptions[] = $ctx;
            if ($st['class'] === 'non_numeric') $nonNumeric[] = $ctx;
            if ($st['raw_text_parsed']) $reviews[] = $ctx;
        }
    }
    $unclassified = [];
    foreach ($submissions as $s) if ($s['assigned_shift'] === null) $unclassified[] = ['employee' => $s['employee'], 'submitted_at_raw' => $s['submitted_at_raw']];
    $shiftMismatch = [];
    foreach ($submissions as $s) {
        if ($s['assigned_shift'] !== null && $s['form_shift_field'] !== '' && strtoupper(trim($s['form_shift_field'])) !== $s['assigned_shift']) {
            $shiftMismatch[] = ['employee' => $s['employee'], 'local_time' => $s['local_time'], 'assigned' => $s['assigned_shift'], 'form_field' => $s['form_shift_field']];
        }
    }
    return [
        'branch' => $branch, 'name' => BROTH_LOG_BRANCHES[$branch]['name'] ?? $branch, 'error' => null,
        'submission_count' => count($submissions), 'shifts' => $shifts, 'tally' => $tally,
        'exceptions' => $exceptions, 'non_numeric' => $nonNumeric, 'raw_text_review' => $reviews,
        'unclassified' => $unclassified, 'shift_field_mismatch' => $shiftMismatch,
    ];
}

function bldr_local_label(?string $utc): ?string {
    if ($utc === null || $utc === '') return null;
    try {
        return (new DateTimeImmutable($utc . ' UTC'))->setTimezone(new DateTimeZone(BROTH_LOG_BUSINESS_TIMEZONE))->format('g:i A');
    } catch (Throwable $e) {
        return null;
    }
}

function bldr_user_names(): array {
    $names = [];
    foreach (q("SELECT telegram_user_id, display_name FROM broth_log_authorized_users") as $u) $names[(string)$u['telegram_user_id']] = (string)$u['display_name'];
    return $names;
}

// Incident view for one branch and business date, straight from production tables (read-only).
function bldr_collect_incidents(string $branch, string $date): array {
    $names = bldr_user_names();
    $rows = q("SELECT incident_id, incident_type, state, current_level, station_label, shift, acknowledged_by, acknowledged_at, resolved_by, resolved_at, closed_at, closure_reason, resolution_note, created_at
               FROM broth_log_incidents WHERE branch=? AND business_date=? ORDER BY created_at", [$branch, $date]);
    $list = [];
    foreach ($rows as $r) {
        $open = !in_array($r['state'], ['resolved', 'closed'], true);
        $list[] = [
            'incident_id' => $r['incident_id'], 'type' => $r['incident_type'], 'state' => $r['state'], 'level' => (int)$r['current_level'],
            'what' => $r['incident_type'] === 'missing_shift' ? (($r['shift'] ?? '') . ' Broth Log') : $r['station_label'],
            'created_local' => bldr_local_label($r['created_at']),
            'acked_by' => $r['acknowledged_by'] ? ($names[(string)$r['acknowledged_by']] ?? 'Unknown user') : null,
            'acked_local' => bldr_local_label($r['acknowledged_at']),
            'resolved_local' => bldr_local_label($r['resolved_at'] ?: $r['closed_at']),
            'note' => $r['resolution_note'] ?: ($r['closure_reason'] ?: null),
            'open' => $open,
        ];
    }
    $carry = q1("SELECT COUNT(*) c, MIN(business_date) oldest FROM broth_log_incidents WHERE branch=? AND business_date<? AND state NOT IN ('resolved','closed')", [$branch, $date]);
    return [
        'incidents' => $list,
        'temperature' => count(array_filter($list, fn($i) => $i['type'] === 'temperature')),
        'missing_shift' => count(array_filter($list, fn($i) => $i['type'] === 'missing_shift')),
        'open' => count(array_filter($list, fn($i) => $i['open'])),
        'carry_over_open' => (int)($carry['c'] ?? 0),
        'carry_over_oldest' => $carry['oldest'] ?? null,
    ];
}

function bldr_day_window_utc(string $date): array {
    $tz = new DateTimeZone(BROTH_LOG_BUSINESS_TIMEZONE);
    $start = new DateTimeImmutable($date . ' 00:00:00', $tz);
    $end = $start->modify('+1 day');
    $utc = new DateTimeZone('UTC');
    return [$start->setTimezone($utc)->format('Y-m-d H:i:s'), $end->setTimezone($utc)->format('Y-m-d H:i:s')];
}

// Delivery health from the actual outbound-delivery and audit-event records created during the
// Texas business day. Expected recipients are what the routing resolver says for the incident's
// level; they are shown next to (never instead of) the recorded deliveries.
function bldr_collect_delivery_health(string $branch, string $date): array {
    [$from, $to] = bldr_day_window_utc($date);
    $chatNames = [];
    foreach (q("SELECT au.display_name, pcr.private_chat_id c FROM broth_log_authorized_users au JOIN broth_log_private_chat_registrations pcr ON pcr.telegram_user_id=au.telegram_user_id") as $u) $chatNames[(string)$u['c']] = (string)$u['display_name'];
    $opsChats = array_column(q("SELECT DISTINCT chat_id c FROM broth_log_routing_rules WHERE chat_id IS NOT NULL AND chat_id != ''"), 'c');
    foreach ($opsChats as $c) $chatNames[(string)$c] = 'Ops group';
    $mode = q1("SELECT mode, updated_at FROM broth_log_branch_alert_mode WHERE branch=?", [$branch]);
    $routed = $mode && $mode['mode'] === BROTH_LOG_COPILOT_LEVEL_ROUTING_MODE;
    $rows = q("SELECT d.incident_id, d.delivery_key, d.chat_id, d.message_kind, d.status, i.created_at inc_created
               FROM broth_log_outbound_deliveries d JOIN broth_log_incidents i ON i.incident_id = d.incident_id
               WHERE i.branch=? AND d.created_at >= ? AND d.created_at < ? AND d.message_kind NOT LIKE '%\\_status' ESCAPE '\\'
               ORDER BY d.created_at", [$branch, $from, $to]);
    $sent = 0; $failed = 0; $opsSent = 0;
    $perPerson = [];
    $seen = [];
    $duplicates = [];
    $unexpected = [];
    $byIncidentLevel = [];
    foreach ($rows as $r) {
        $name = $chatNames[(string)$r['chat_id']] ?? 'Unknown chat';
        $parts = explode(':', (string)$r['delivery_key']);
        $level = $r['message_kind'] === 'incident_notification' ? 1 : (int)($parts[4] ?? 0);
        $ok = $r['status'] === 'sent';
        $ok ? $sent++ : $failed++;
        if ($name === 'Ops group' && $ok) $opsSent++;
        $perPerson[$name]['sent'] = ($perPerson[$name]['sent'] ?? 0) + ($ok ? 1 : 0);
        $perPerson[$name]['failed'] = ($perPerson[$name]['failed'] ?? 0) + ($ok ? 0 : 1);
        $sig = implode('|', [$r['incident_id'], $r['chat_id'], $r['message_kind'], $parts[3] ?? '', $level]);
        if (isset($seen[$sig])) $duplicates[$sig] = $name; else $seen[$sig] = true;
        $byIncidentLevel[$r['incident_id'] . '|' . $level]['names'][$name] = true;
        $byIncidentLevel[$r['incident_id'] . '|' . $level]['created'] = $r['inc_created'];
    }
    $crossPath = 0;
    foreach ($byIncidentLevel as $key => $info) {
        [$incidentId, $level] = explode('|', $key);
        $names = array_keys($info['names']);
        $direct = array_values(array_filter($names, fn($n) => $n !== 'Ops group'));
        if (in_array('Ops group', $names, true) && !empty($direct)) $crossPath++;
        if ($routed && $info['created'] >= $mode['updated_at']) {
            $expected = array_column(broth_log_copilot_resolve_recipients($branch, (int)$level, null), 'display_name');
            foreach ($direct as $n) if (!in_array($n, $expected, true)) $unexpected[] = $n . ' (L' . $level . ')';
        }
    }
    $fallbacks = [];
    foreach (q("SELECT e.event_json FROM broth_log_incident_events e JOIN broth_log_incidents i ON i.incident_id = e.incident_id WHERE i.branch=? AND e.event_type='ops_group_fallback' AND e.created_at >= ? AND e.created_at < ?", [$branch, $from, $to]) as $e) {
        $fallbacks[] = (string)(json_decode((string)$e['event_json'], true)['reason'] ?? 'unspecified');
    }
    return [
        'mode' => $mode['mode'] ?? 'legacy', 'sent' => $sent, 'failed' => $failed, 'per_person' => $perPerson, 'ops_group_sent' => $opsSent,
        'ops_fallback_reasons' => $fallbacks, 'duplicates' => array_values($duplicates), 'ops_plus_direct_same_incident_level' => $crossPath,
        'unexpected_recipients' => array_values(array_unique($unexpected)),
    ];
}

function bldr_build_report(string $date, array $tablesByBranch, DateTimeImmutable $now): array {
    $stores = [];
    foreach (array_keys(BROTH_LOG_BRANCHES) as $branch) {
        $entry = $tablesByBranch[$branch] ?? ['error' => 'no data provided'];
        if (isset($entry['error'])) {
            $store = ['branch' => $branch, 'name' => BROTH_LOG_BRANCHES[$branch]['name'], 'error' => (string)$entry['error']];
        } else {
            $store = bldr_collect_store($branch, $entry['table'], $date, $now);
        }
        $store['incident_view'] = bldr_collect_incidents($branch, $date);
        $store['delivery'] = bldr_collect_delivery_health($branch, $date);
        $stores[$branch] = $store;
    }
    $sum = ['expected_shifts' => 6, 'submitted' => 0, 'missing' => 0, 'on_time' => 0, 'late' => 0, 'early' => 0, 'not_yet_due' => 0, 'unknown' => 0, 'temperature_exceptions' => 0, 'non_numeric' => 0, 'blank' => 0, 'open_incidents' => 0, 'carry_over_open' => 0, 'stores_unavailable' => 0];
    foreach ($stores as $s) {
        $sum['open_incidents'] += $s['incident_view']['open'];
        $sum['carry_over_open'] += $s['incident_view']['carry_over_open'];
        if ($s['error'] !== null) { $sum['stores_unavailable']++; continue; }
        foreach ($s['shifts'] as $sh) {
            switch ($sh['status']) {
                case 'ON_TIME': $sum['submitted']++; $sum['on_time']++; break;
                case 'LATE': $sum['submitted']++; $sum['late']++; break;
                case 'EARLY': $sum['submitted']++; $sum['early']++; break;
                case 'MISSING': $sum['missing']++; break;
                case 'NOT_YET_DUE': $sum['not_yet_due']++; break;
                default: $sum['unknown']++;
            }
        }
        $sum['temperature_exceptions'] += $s['tally']['exception'] + $s['tally']['config_gap'];
        $sum['non_numeric'] += $s['tally']['non_numeric'];
        $sum['blank'] += $s['tally']['blank'];
    }
    return [
        'business_date' => $date, 'generated_utc' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        'generated_local' => broth_log_business_now($now)->format('Y-m-d g:i A T'),
        'complete' => $sum['stores_unavailable'] === 0, 'summary' => $sum, 'stores' => $stores,
        'pending_recipients' => bldr_pending_recipients_note(),
    ];
}

function bldr_pending_recipients_note(): array {
    $out = [];
    foreach (['B1', 'B2', 'B3'] as $b) {
        foreach (broth_log_copilot_pending_recipients($b) as $p) $out[] = $p['display_name'] . ' - ' . $b . ' Level ' . $p['min_level'] . ' (pending onboarding, no Telegram ID)';
    }
    return $out;
}

// ---------------------------------------------------------------------------------------------
// Rendering (HTML for mobile + desktop, plain-text alternative)
// ---------------------------------------------------------------------------------------------

function bldr_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function bldr_date_title(string $date): string {
    return (new DateTimeImmutable($date . ' 12:00:00', new DateTimeZone(BROTH_LOG_BUSINESS_TIMEZONE)))->format('M j, Y');
}

function bldr_subject(string $date): string {
    return 'Bakudan Broth Log Daily Report - ' . $date;
}

function bldr_shift_cell_text(array $sh): string {
    if ($sh['status'] === 'MISSING') return 'MISSING - ' . BLDR_MISSING;
    if ($sh['status'] === 'NOT_YET_DUE') return 'Not yet due';
    $employee = $sh['employee'] === 'Unassigned' ? 'Unassigned (no employee name in sheet)' : (string)$sh['employee'];
    $time = $sh['local_time'] ?? (BLDR_MISSING . ' (timestamp)');
    $extra = $sh['submission_count'] > 1 ? ' (+' . ($sh['submission_count'] - 1) . ' additional submission' . ($sh['submission_count'] > 2 ? 's' : '') . ')' : '';
    return $sh['label'] . ' - ' . $employee . ' - ' . $time . $extra;
}

function bldr_render_text(array $r): string {
    $s = $r['summary'];
    $L = [];
    $L[] = 'Daily Broth Log Report - ' . bldr_date_title($r['business_date']);
    if (!$r['complete']) $L[] = '*** INCOMPLETE: data for ' . $s['stores_unavailable'] . ' store(s) could not be read - see below ***';
    $L[] = '';
    $L[] = 'Expected shifts: ' . $s['expected_shifts'];
    $L[] = 'Submitted: ' . $s['submitted'] . '   Missing: ' . $s['missing'] . '   On time: ' . $s['on_time'];
    $L[] = 'Late: ' . $s['late'] . '   Early: ' . $s['early'] . ($s['not_yet_due'] ? '   Not yet due: ' . $s['not_yet_due'] : '');
    $L[] = 'Temperature exceptions: ' . $s['temperature_exceptions'] . '   Non-numeric readings (OFF, -, ...): ' . $s['non_numeric'] . '   Blank readings: ' . $s['blank'];
    $L[] = 'Open incidents (this date): ' . $s['open_incidents'] . '   Older unresolved incidents: ' . $s['carry_over_open'];
    foreach ($r['stores'] as $st) {
        $L[] = '';
        $L[] = '== ' . $st['name'] . ' ==';
        if ($st['error'] !== null) { $L[] = 'DATA UNAVAILABLE: ' . $st['error']; }
        else {
            foreach ($st['shifts'] as $shift => $sh) $L[] = $shift . ': ' . bldr_shift_cell_text($sh);
            $t = $st['tally'];
            $L[] = 'Readings: normal ' . $t['normal'] . ', exceptions ' . $t['exception'] . ', non-numeric ' . $t['non_numeric'] . ', blank ' . $t['blank'] . ($t['config_gap'] ? ', no SOP ' . $t['config_gap'] : '') . ($t['no_column'] ? ', column missing in sheet ' . $t['no_column'] : '');
            foreach ($st['exceptions'] as $e) $L[] = '  EXCEPTION ' . $e['station'] . ': ' . $e['raw'] . ' (SOP ' . $e['target'] . ', ' . $e['severity'] . ') - ' . $e['employee'] . ' ' . ($e['local_time'] ?? '') . ' ' . ($e['shift'] ?? '');
            foreach ($st['non_numeric'] as $e) $L[] = '  NON-NUMERIC ' . $e['station'] . ': "' . $e['raw'] . '" - ' . $e['employee'] . ' ' . ($e['local_time'] ?? '');
            foreach ($st['raw_text_review'] as $e) $L[] = '  REVIEW raw text parsed as number ' . $e['station'] . ': "' . $e['raw'] . '" read as ' . broth_log_format_number((float)$e['temperature']);
            foreach ($st['unclassified'] as $e) $L[] = '  UNCLASSIFIED submission (timestamp not parseable): ' . $e['employee'] . ' "' . $e['submitted_at_raw'] . '"';
            foreach ($st['shift_field_mismatch'] as $e) $L[] = '  NOTE form Shift field "' . $e['form_field'] . '" differs from canonical ' . $e['assigned'] . ' for ' . $e['employee'] . ' ' . ($e['local_time'] ?? '');
        }
        $iv = $st['incident_view'];
        $L[] = 'Incidents: temperature ' . $iv['temperature'] . ', missing shift ' . $iv['missing_shift'] . ', still open ' . $iv['open'] . ($iv['carry_over_open'] ? ', older unresolved ' . $iv['carry_over_open'] . ' (oldest ' . $iv['carry_over_oldest'] . ')' : '');
        foreach ($iv['incidents'] as $i) $L[] = '  ' . strtoupper($i['type']) . ' ' . $i['what'] . ' - ' . $i['state'] . ' (L' . $i['level'] . ')' . ($i['acked_by'] ? ' - ACK by ' . $i['acked_by'] . ' at ' . ($i['acked_local'] ?? BLDR_MISSING) : ' - not acknowledged') . ($i['resolved_local'] ? ' - closed ' . $i['resolved_local'] : '') . ' [' . $i['incident_id'] . ']';
        $d = $st['delivery'];
        $L[] = 'Telegram delivery (' . $d['mode'] . '): sent ' . $d['sent'] . ', failed ' . $d['failed'] . ', Ops group received ' . $d['ops_group_sent'] . ', fallback events ' . count($d['ops_fallback_reasons']) . ($d['ops_fallback_reasons'] ? ' (' . implode(', ', array_unique($d['ops_fallback_reasons'])) . ')' : '') . ', duplicates ' . count($d['duplicates']) . ', Ops+direct same incident ' . $d['ops_plus_direct_same_incident_level'] . ($d['unexpected_recipients'] ? ', UNEXPECTED recipients: ' . implode(', ', $d['unexpected_recipients']) : '');
    }
    if ($r['pending_recipients']) { $L[] = ''; foreach ($r['pending_recipients'] as $p) $L[] = 'Pending: ' . $p; }
    $L[] = '';
    $L[] = 'Generated ' . $r['generated_local'] . ' from production Broth Log sheets and Telegram records. Nothing in this report is estimated.';
    return implode("\n", $L) . "\n";
}

function bldr_pill(string $text, string $color): string {
    return '<span style="display:inline-block;padding:1px 8px;border-radius:10px;background:' . $color . ';color:#fff;font-size:12px;font-weight:600;">' . bldr_h($text) . '</span>';
}

function bldr_status_color(string $status): string {
    return ['ON_TIME' => '#2e7d32', 'EARLY' => '#ef6c00', 'LATE' => '#ef6c00', 'MISSING' => '#c62828', 'NOT_YET_DUE' => '#757575'][$status] ?? '#757575';
}

function bldr_render_html(array $r): string {
    $s = $r['summary'];
    $td = 'style="padding:6px 8px;border-bottom:1px solid #e0e0e0;vertical-align:top;font-size:14px;"';
    $th = 'style="padding:6px 8px;border-bottom:2px solid #333;text-align:left;font-size:13px;background:#f5f5f5;"';
    $h = [];
    $h[] = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>';
    $h[] = '<body style="margin:0;padding:0;background:#f0f0f0;"><div style="max-width:720px;margin:0 auto;background:#fff;padding:16px;font-family:Arial,Helvetica,sans-serif;color:#222;">';
    $h[] = '<h2 style="margin:0 0 4px;">Daily Broth Log Report &mdash; ' . bldr_h(bldr_date_title($r['business_date'])) . '</h2>';
    $h[] = '<div style="color:#666;font-size:13px;margin-bottom:10px;">Texas business date ' . bldr_h($r['business_date']) . ' (America/Chicago)</div>';
    if (!$r['complete']) $h[] = '<div style="background:#ffebee;border:1px solid #c62828;padding:8px;margin:8px 0;font-weight:bold;color:#c62828;">INCOMPLETE REPORT: data for ' . (int)$s['stores_unavailable'] . ' store(s) could not be read. Those stores are not counted as compliant.</div>';
    $h[] = '<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;margin:8px 0;">';
    $rows = [
        ['Expected shifts', $s['expected_shifts']], ['Submitted', $s['submitted']], ['Missing', $s['missing']], ['On time', $s['on_time']],
        ['Late / Early', $s['late'] . ' / ' . $s['early']], ['Temperature exceptions', $s['temperature_exceptions']],
        ['Non-numeric readings (OFF, -, ...)', $s['non_numeric']], ['Blank readings', $s['blank']],
        ['Open incidents (this date)', $s['open_incidents']], ['Older unresolved incidents', $s['carry_over_open']],
    ];
    if ($s['not_yet_due']) $rows[] = ['Not yet due', $s['not_yet_due']];
    foreach ($rows as [$k, $v]) $h[] = '<tr><td ' . $td . '>' . bldr_h($k) . '</td><td ' . $td . ' align="right"><b>' . bldr_h($v) . '</b></td></tr>';
    $h[] = '</table>';
    // Store-by-store table
    $h[] = '<h3 style="margin:16px 0 4px;">Stores</h3><table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;"><tr>';
    foreach (['Store', 'AM', 'PM', 'Temp exc.', 'Non-num.', 'Open inc.'] as $c) $h[] = '<th ' . $th . '>' . $c . '</th>';
    $h[] = '</tr>';
    foreach ($r['stores'] as $st) {
        $h[] = '<tr><td ' . $td . '><b>' . bldr_h($st['name']) . '</b></td>';
        if ($st['error'] !== null) { $h[] = '<td ' . $td . ' colspan="4">' . bldr_pill('DATA UNAVAILABLE', '#c62828') . '</td>'; }
        else {
            foreach (['AM', 'PM'] as $shift) $h[] = '<td ' . $td . '>' . bldr_pill($st['shifts'][$shift]['label'], bldr_status_color($st['shifts'][$shift]['status'])) . '</td>';
            $h[] = '<td ' . $td . '>' . ($st['tally']['exception'] + $st['tally']['config_gap']) . '</td><td ' . $td . '>' . $st['tally']['non_numeric'] . '</td>';
        }
        $h[] = '<td ' . $td . '>' . $st['incident_view']['open'] . '</td></tr>';
    }
    $h[] = '</table>';
    foreach ($r['stores'] as $st) {
        $h[] = '<h3 style="margin:20px 0 4px;border-top:2px solid #333;padding-top:8px;">' . bldr_h($st['name']) . '</h3>';
        if ($st['error'] !== null) {
            $h[] = '<p style="color:#c62828;"><b>Data unavailable:</b> ' . bldr_h($st['error']) . '</p>';
        } else {
            $h[] = '<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;">';
            foreach ($st['shifts'] as $shift => $sh) $h[] = '<tr><td ' . $td . ' width="40"><b>' . $shift . '</b></td><td ' . $td . '>' . bldr_pill($sh['label'], bldr_status_color($sh['status'])) . ' ' . bldr_h($sh['status'] === 'MISSING' ? BLDR_MISSING : ($sh['status'] === 'NOT_YET_DUE' ? '' : (($sh['employee'] === 'Unassigned' ? 'Unassigned (no employee name in sheet)' : $sh['employee']) . ' &middot; ' . ($sh['local_time'] ?? BLDR_MISSING . ' (timestamp)') . ($sh['submission_count'] > 1 ? ' &middot; +' . ($sh['submission_count'] - 1) . ' additional' : '')))) . '</td></tr>';
            $h[] = '</table>';
            $t = $st['tally'];
            $h[] = '<p style="font-size:13px;margin:8px 0;">Readings audited against production SOP: <b>' . $t['normal'] . '</b> normal, <b>' . $t['exception'] . '</b> exceptions, <b>' . $t['non_numeric'] . '</b> non-numeric, <b>' . $t['blank'] . '</b> blank' . ($t['config_gap'] ? ', <b>' . $t['config_gap'] . '</b> without SOP' : '') . ($t['no_column'] ? ', <b>' . $t['no_column'] . '</b> with the sheet column missing' : '') . '.</p>';
            if ($st['exceptions']) {
                $h[] = '<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;"><tr><th ' . $th . '>Exception</th><th ' . $th . '>Reading</th><th ' . $th . '>SOP</th><th ' . $th . '>Who / when</th></tr>';
                foreach ($st['exceptions'] as $e) $h[] = '<tr><td ' . $td . '>' . bldr_h($e['station']) . '<br>' . bldr_pill($e['severity'], $e['severity'] === 'critical' ? '#c62828' : '#ef6c00') . '</td><td ' . $td . '>' . bldr_h($e['raw']) . '</td><td ' . $td . '>' . bldr_h($e['target']) . '</td><td ' . $td . '>' . bldr_h($e['employee']) . '<br>' . bldr_h(($e['shift'] ?? '') . ' ' . ($e['local_time'] ?? '')) . '</td></tr>';
                $h[] = '</table>';
            }
            if ($st['non_numeric']) {
                $h[] = '<p style="font-size:13px;margin:8px 0 2px;"><b>Non-numeric readings (kept exactly as entered, not treated as temperatures):</b></p><ul style="margin:0;padding-left:18px;font-size:13px;">';
                foreach ($st['non_numeric'] as $e) $h[] = '<li>' . bldr_h($e['station']) . ': &ldquo;' . bldr_h($e['raw']) . '&rdquo; &mdash; ' . bldr_h($e['employee']) . ' ' . bldr_h($e['local_time'] ?? '') . '</li>';
                $h[] = '</ul>';
            }
            foreach ($st['raw_text_review'] as $e) $h[] = '<p style="font-size:13px;color:#ef6c00;margin:4px 0;">Review: ' . bldr_h($e['station']) . ' entered as &ldquo;' . bldr_h($e['raw']) . '&rdquo; was read by production parsing as ' . bldr_h(broth_log_format_number((float)$e['temperature'])) . '&deg;F.</p>';
            foreach ($st['unclassified'] as $e) $h[] = '<p style="font-size:13px;color:#c62828;margin:4px 0;">Submission by ' . bldr_h($e['employee']) . ' has an unparseable timestamp (&ldquo;' . bldr_h($e['submitted_at_raw']) . '&rdquo;) and could not be assigned to a shift.</p>';
            foreach ($st['shift_field_mismatch'] as $e) $h[] = '<p style="font-size:13px;color:#666;margin:4px 0;">Note: form Shift field &ldquo;' . bldr_h($e['form_field']) . '&rdquo; differs from the canonical shift ' . bldr_h($e['assigned']) . ' (' . bldr_h($e['employee']) . ' ' . bldr_h($e['local_time'] ?? '') . '); canonical was used.</p>';
        }
        $iv = $st['incident_view'];
        $h[] = '<p style="font-size:13px;margin:10px 0 2px;"><b>Incidents</b> &mdash; temperature: ' . $iv['temperature'] . ', missing shift: ' . $iv['missing_shift'] . ', still open: ' . $iv['open'] . ($iv['carry_over_open'] ? '; <span style="color:#c62828;">older unresolved: ' . $iv['carry_over_open'] . ' (oldest ' . bldr_h($iv['carry_over_oldest']) . ')</span>' : '') . '</p>';
        if ($iv['incidents']) {
            $h[] = '<ul style="margin:0;padding-left:18px;font-size:13px;">';
            foreach ($iv['incidents'] as $i) $h[] = '<li>' . bldr_h(ucfirst(str_replace('_', ' ', $i['type']))) . ' &mdash; ' . bldr_h($i['what']) . ' &mdash; <b>' . bldr_h($i['state']) . '</b> (level ' . $i['level'] . ')' . ($i['acked_by'] ? ' &mdash; ACK by ' . bldr_h($i['acked_by']) . ' at ' . bldr_h($i['acked_local'] ?? BLDR_MISSING) : ' &mdash; not acknowledged') . ($i['resolved_local'] ? ' &mdash; closed ' . bldr_h($i['resolved_local']) : '') . ($i['open'] ? ' ' . bldr_pill('needs attention', '#c62828') : '') . '</li>';
            $h[] = '</ul>';
        }
        $d = $st['delivery'];
        $per = [];
        foreach ($d['per_person'] as $n => $c) $per[] = bldr_h($n) . ' ' . $c['sent'] . ' sent' . ($c['failed'] ? ' / ' . $c['failed'] . ' failed' : '');
        $h[] = '<p style="font-size:13px;margin:10px 0 2px;"><b>Telegram delivery</b> (mode ' . bldr_h($d['mode']) . '): sent ' . $d['sent'] . ', failed ' . $d['failed'] . ($per ? ' &mdash; ' . implode('; ', $per) : '') . '<br>Ops group received: ' . $d['ops_group_sent'] . '; fallback events: ' . count($d['ops_fallback_reasons']) . ($d['ops_fallback_reasons'] ? ' (' . bldr_h(implode(', ', array_unique($d['ops_fallback_reasons']))) . ')' : '') . '; duplicate deliveries: ' . count($d['duplicates']) . '; Ops copy alongside direct DM for same incident/level: ' . $d['ops_plus_direct_same_incident_level'] . ($d['unexpected_recipients'] ? '<br><span style="color:#c62828;">Unexpected recipients: ' . bldr_h(implode(', ', $d['unexpected_recipients'])) . '</span>' : '') . '</p>';
    }
    if ($r['pending_recipients']) {
        $h[] = '<p style="font-size:13px;margin-top:16px;"><b>Pending onboarding (no deliveries possible yet):</b><br>' . implode('<br>', array_map('bldr_h', $r['pending_recipients'])) . '</p>';
    }
    $h[] = '<p style="font-size:12px;color:#777;margin-top:18px;border-top:1px solid #ddd;padding-top:8px;">Generated ' . bldr_h($r['generated_local']) . ' from the production Broth Log sheets and Telegram incident/delivery records. Nothing in this report is estimated; values not present in production are shown as &ldquo;' . bldr_h(BLDR_MISSING) . '&rdquo;.</p>';
    $h[] = '</div></body></html>';
    return implode("\n", $h);
}

// ---------------------------------------------------------------------------------------------
// Email transport. Default: the host's local MTA via PHP mail() (no credentials needed). Tests
// replace it with $GLOBALS['BROTH_LOG_DAILY_REPORT_MAIL_TRANSPORT'].
// "accepted" means the MTA took the message - it is NOT proof of inbox delivery.
// ---------------------------------------------------------------------------------------------

function bldr_valid_address(string $a): bool {
    return $a !== '' && !preg_match('/[\r\n,;<>]/', $a) && filter_var($a, FILTER_VALIDATE_EMAIL) !== false;
}

function bldr_send_email(string $to, string $subject, string $html, string $text): array {
    $from = broth_log_copilot_env('BROTH_LOG_DAILY_REPORT_FROM', BLDR_DEFAULT_FROM);
    if (!bldr_valid_address($to) || !bldr_valid_address($from)) return ['accepted' => false, 'error' => 'invalid sender or recipient address'];
    if (preg_match('/[\r\n]/', $subject)) return ['accepted' => false, 'error' => 'invalid subject'];
    $transport = $GLOBALS['BROTH_LOG_DAILY_REPORT_MAIL_TRANSPORT'] ?? null;
    if (is_callable($transport)) return $transport($to, $subject, $html, $text, $from);
    $boundary = 'bl-' . bin2hex(random_bytes(12));
    $headers = [
        'From: Bakudan Broth Log <' . $from . '>', 'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'X-Mailer: bakudan-broth-log-daily-report',
    ];
    $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
          . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html))
          . "--$boundary--\r\n";
    $ok = @mail($to, $subject, $body, implode("\r\n", $headers), '-f' . $from);
    return $ok ? ['accepted' => true] : ['accepted' => false, 'error' => 'local mail transport rejected the message'];
}

// ---------------------------------------------------------------------------------------------
// Execution record + idempotent runner
// ---------------------------------------------------------------------------------------------

function bldr_migrate(SQLite3 $db): void {
    $db->exec("
    CREATE TABLE IF NOT EXISTS broth_log_daily_report_runs (
        business_date TEXT PRIMARY KEY,
        recipient TEXT NOT NULL,
        scheduled_for_local TEXT,
        started_at TEXT NOT NULL,
        completed_at TEXT,
        report_status TEXT NOT NULL DEFAULT 'pending',
        report_generated_at TEXT,
        generate_attempts INTEGER NOT NULL DEFAULT 0,
        report_complete INTEGER,
        report_sha256 TEXT,
        subject TEXT,
        report_html TEXT,
        report_text TEXT,
        summary_json TEXT,
        email_status TEXT NOT NULL DEFAULT 'not_attempted',
        email_attempts INTEGER NOT NULL DEFAULT 0,
        email_attempt_started_at TEXT,
        email_accepted_at TEXT,
        error_summary TEXT,
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
}

function bldr_enabled(): bool {
    return in_array(strtolower(trim(broth_log_copilot_env('BROTH_LOG_DAILY_REPORT_ENABLED', 'false'))), ['1', 'true', 'yes', 'on'], true);
}

function bldr_fetch_tables(): array {
    $provider = $GLOBALS['BROTH_LOG_DAILY_REPORT_TABLES_PROVIDER'] ?? null;
    $out = [];
    foreach (array_keys(BROTH_LOG_BRANCHES) as $branch) {
        $lastError = 'unknown error';
        $out[$branch] = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $out[$branch] = ['table' => is_callable($provider) ? $provider($branch) : broth_log_gviz_table($branch)];
                break;
            } catch (Throwable $e) {
                $lastError = broth_log_copilot_sanitize_error($e->getMessage());
                if (!is_callable($provider)) sleep(2);
            }
        }
        if ($out[$branch] === null) $out[$branch] = ['error' => $lastError];
    }
    return $out;
}

function bldr_set(string $date, array $fields): void {
    $sets = [];
    $params = [];
    foreach ($fields as $k => $v) { $sets[] = "$k=?"; $params[] = $v; }
    $params[] = $date;
    run("UPDATE broth_log_daily_report_runs SET " . implode(',', $sets) . ", updated_at=datetime('now') WHERE business_date=?", $params);
}

// One tick. Safe to call every few minutes from cron at any time of day: returns quickly unless a
// report is due, and at most one email is ever sent per business date.
function bldr_run(DateTimeImmutable $now): array {
    $due = bldr_due_business_date($now);
    if ($due === null) return ['action' => 'not_due'];
    if (!bldr_enabled()) return ['action' => 'disabled', 'business_date' => $due['business_date']];
    $date = $due['business_date'];
    $to = broth_log_copilot_env('BROTH_LOG_DAILY_REPORT_TO', BLDR_DEFAULT_RECIPIENT);
    $utcNow = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    bldr_migrate(db());
    run("INSERT OR IGNORE INTO broth_log_daily_report_runs (business_date, recipient, scheduled_for_local, started_at) VALUES (?,?,?,?)", [$date, $to, $due['scheduled_local'], $utcNow]);
    $row = q1("SELECT * FROM broth_log_daily_report_runs WHERE business_date=?", [$date]);

    if ($row['email_status'] === 'accepted') return ['action' => 'already_sent', 'business_date' => $date];
    if ($row['email_status'] === 'unknown') return ['action' => 'needs_review', 'business_date' => $date, 'error' => (string)$row['error_summary']];
    if ($row['email_status'] === 'attempting') {
        $age = $now->getTimestamp() - (new DateTimeImmutable((string)$row['email_attempt_started_at'] . ' UTC'))->getTimestamp();
        if ($age < BLDR_STALE_ATTEMPT_SECONDS) return ['action' => 'in_progress', 'business_date' => $date];
        // A previous process died after starting to send. Whether the message left is unknowable, so
        // never resend automatically (a duplicate email is worse than a flagged one a human checks).
        bldr_set($date, ['email_status' => 'unknown', 'error_summary' => 'email attempt interrupted; delivery state unknown - not resent automatically']);
        return ['action' => 'needs_review', 'business_date' => $date];
    }
    if ((int)$row['email_attempts'] >= BLDR_MAX_EMAIL_ATTEMPTS) return ['action' => 'gave_up', 'business_date' => $date, 'error' => (string)$row['error_summary']];

    // Generate once; a retry reuses the stored report and never re-reads production data.
    if (($row['report_html'] ?? '') === '') {
        if ((int)$row['generate_attempts'] >= BLDR_MAX_GENERATE_ATTEMPTS) return ['action' => 'gave_up', 'business_date' => $date, 'error' => (string)$row['error_summary']];
        bldr_set($date, ['generate_attempts' => (int)$row['generate_attempts'] + 1]);
        try {
            $report = bldr_build_report($date, bldr_fetch_tables(), $now);
            $html = bldr_render_html($report);
            $text = bldr_render_text($report);
            bldr_set($date, [
                'report_status' => $report['complete'] ? 'generated' : 'partial', 'report_generated_at' => $utcNow, 'report_complete' => $report['complete'] ? 1 : 0,
                'report_sha256' => hash('sha256', $html), 'subject' => bldr_subject($date), 'report_html' => $html, 'report_text' => $text,
                'summary_json' => json_encode($report['summary']), 'error_summary' => $report['complete'] ? null : 'one or more stores had unreadable data',
            ]);
        } catch (Throwable $e) {
            $msg = broth_log_copilot_sanitize_error($e->getMessage());
            bldr_set($date, ['report_status' => 'failed', 'error_summary' => 'report generation failed: ' . $msg]);
            return ['action' => 'report_failed', 'business_date' => $date, 'error' => $msg];
        }
        $row = q1("SELECT * FROM broth_log_daily_report_runs WHERE business_date=?", [$date]);
    }

    // Claim the send atomically so two overlapping processes cannot both send.
    db()->exec('BEGIN IMMEDIATE');
    $claim = q1("SELECT email_status FROM broth_log_daily_report_runs WHERE business_date=?", [$date]);
    if (!in_array($claim['email_status'], ['not_attempted', 'failed'], true)) {
        db()->exec('COMMIT');
        return ['action' => 'in_progress', 'business_date' => $date];
    }
    bldr_set($date, ['email_status' => 'attempting', 'email_attempts' => (int)$row['email_attempts'] + 1, 'email_attempt_started_at' => $utcNow]);
    db()->exec('COMMIT');

    try {
        $result = bldr_send_email((string)$row['recipient'], (string)$row['subject'], (string)$row['report_html'], (string)$row['report_text']);
    } catch (Throwable $e) {
        $result = ['accepted' => false, 'error' => broth_log_copilot_sanitize_error($e->getMessage())];
    }
    if (!empty($result['accepted'])) {
        bldr_set($date, ['email_status' => 'accepted', 'email_accepted_at' => $utcNow, 'completed_at' => $utcNow, 'error_summary' => $row['report_complete'] ? null : $row['error_summary']]);
        return ['action' => 'sent', 'business_date' => $date, 'recipient' => (string)$row['recipient']];
    }
    $err = broth_log_copilot_sanitize_error((string)($result['error'] ?? 'send failed'));
    bldr_set($date, ['email_status' => 'failed', 'error_summary' => 'email failed: ' . $err]);
    return ['action' => 'email_failed', 'business_date' => $date, 'error' => $err];
}
