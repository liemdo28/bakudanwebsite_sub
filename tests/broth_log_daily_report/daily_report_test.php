<?php
declare(strict_types=1);

// Daily Broth Log Report: Texas business-date/DST scheduling, shift classification, missing shifts,
// canonical SOP evaluation (incl. OFF / - / blank / malformed), idempotency, and rendering.
// Synthetic data only - no network, no real email, no production database.

$dbPath = sys_get_temp_dir() . '/broth-log-daily-report-' . bin2hex(random_bytes(4)) . '.sqlite';
define('TEST_DB_PATH', $dbPath);

function db(): SQLite3 {
    static $db = null;
    if ($db) return $db;
    $db = new SQLite3(TEST_DB_PATH);
    $db->enableExceptions(true);
    $db->busyTimeout(3000);
    $db->exec('PRAGMA journal_mode=WAL;');
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
function expect_true(bool $c, string $label): void { if (!$c) throw new RuntimeException("FAIL $label"); echo "PASS $label\n"; }
function expect_eq($actual, $expected, string $label): void {
    if ($actual !== $expected) throw new RuntimeException("FAIL $label expected=" . var_export($expected, true) . " actual=" . var_export($actual, true));
    echo "PASS $label\n";
}

require_once __DIR__ . '/../../api/broth-log-daily-report.php';

function utc(string $s): DateTimeImmutable { return new DateTimeImmutable($s, new DateTimeZone('UTC')); }

// gviz-shaped table builder using the production header aliases.
function make_table(array $rows): array {
    $headers = ['Timestamp', 'Employee Name / Nombre del Empleado', 'Store Code', 'Business Date', 'Business Time', 'Shift', 'Walk-In Cooler (Produce)', 'Walk-In Freezer', 'Prep Area Cooler', 'Bowl Warmer', 'Line Freezer', 'Fryer Left'];
    $cols = array_map(fn($h) => ['label' => $h], $headers);
    $out = [];
    foreach ($rows as $r) {
        $cells = [];
        foreach ($headers as $i => $h) $cells[] = ['v' => (string)($r[$i] ?? '')];
        $out[] = ['c' => $cells];
    }
    return ['cols' => $cols, 'rows' => $out];
}
// [timestamp(Vietnam), employee, store, business date, business time, shift field, walkIn, walkInFreezer, prepCooler, bowlWarmer, lineFreezer, fryerLeft]
$d = '2026-10-08';

try {
    putenv('TELEGRAM_COPILOT_ENABLED=true');
    putenv('BROTH_LOG_DAILY_REPORT_ENABLED=true');
    putenv('BROTH_LOG_DAILY_REPORT_TO=');
    putenv('BROTH_LOG_DAILY_REPORT_FROM=');
    broth_log_copilot_migrate(db());
    bldr_migrate(db());

    // ------------------------------------------------------------------ 1. Texas business date + DST
    expect_eq(bldr_due_business_date(utc('2026-10-09 04:00:00'))['business_date'] ?? null, '2026-10-08', 'summer: 04:00Z Oct 9 is 11:00 PM CDT Oct 8 -> reports Oct 8 (not the UTC/Vietnam date Oct 9)');
    expect_eq(bldr_due_business_date(utc('2026-10-09 03:59:00')), null, 'summer: 03:59Z is 10:59 PM CDT -> nothing due yet');
    expect_eq(bldr_due_business_date(utc('2026-12-09 05:00:00'))['business_date'] ?? null, '2026-12-08', 'winter: 05:00Z Dec 9 is 11:00 PM CST Dec 8 -> reports Dec 8');
    expect_eq(bldr_due_business_date(utc('2026-12-09 04:00:00')), null, 'winter: 04:00Z (the SUMMER run time) is only 10:00 PM CST -> not due: schedule is not hardcoded to a UTC hour');
    expect_eq(bldr_due_business_date(utc('2026-10-09 05:00:00'))['business_date'] ?? null, '2026-10-08', 'summer: 05:00Z (midnight CDT) is still the catch-up window for Oct 8');
    expect_eq(bldr_due_business_date(utc('2026-10-09 09:59:00'))['business_date'] ?? null, '2026-10-08', 'catch-up lasts until 04:59 local');
    expect_eq(bldr_due_business_date(utc('2026-10-09 10:00:00')), null, 'catch-up window closes at 05:00 local');
    // spring forward: Mar 8 2026 (2 AM -> 3 AM). 23:00 on Mar 7 is CST, on Mar 8 is CDT.
    expect_eq(bldr_due_business_date(utc('2026-03-08 05:00:00'))['business_date'] ?? null, '2026-03-07', 'DST start: 11:00 PM CST Mar 7 = 05:00Z');
    expect_eq(bldr_due_business_date(utc('2026-03-09 04:00:00'))['business_date'] ?? null, '2026-03-08', 'DST start: 11:00 PM CDT Mar 8 = 04:00Z (one hour earlier in UTC)');
    // fall back: Nov 1 2026 (2 AM -> 1 AM). 23:00 on Oct 31 is CDT, on Nov 1 is CST.
    expect_eq(bldr_due_business_date(utc('2026-11-01 04:00:00'))['business_date'] ?? null, '2026-10-31', 'DST end: 11:00 PM CDT Oct 31 = 04:00Z');
    expect_eq(bldr_due_business_date(utc('2026-11-02 05:00:00'))['business_date'] ?? null, '2026-11-01', 'DST end: 11:00 PM CST Nov 1 = 05:00Z (one hour later in UTC)');
    expect_eq(bldr_due_business_date(utc('2026-11-02 04:00:00')), null, 'DST end: 04:00Z Nov 2 is 10:00 PM CST -> not due');
    expect_eq(bldr_due_business_date(utc('2026-11-01 07:30:00'))['business_date'] ?? null, '2026-10-31', 'DST end night: 07:30Z Nov 1 is 01:30 CST (the repeated hour) -> still the Oct 31 catch-up');
    expect_eq(bldr_due_business_date(utc('2026-10-09 04:07:00'))['late_minutes'] ?? null, 7, 'late_minutes counts from the 11:00 PM Texas moment');
    $next = bldr_next_run(utc('2026-10-08 20:00:00'));
    expect_eq([$next['run_utc'], $next['business_date']], ['2026-10-09 04:00:00 UTC', '2026-10-08'], 'next run (summer): 11:00 PM CDT Oct 8 reporting Oct 8');
    $nextW = bldr_next_run(utc('2026-12-08 10:00:00'));
    expect_eq([$nextW['run_utc'], $nextW['business_date']], ['2026-12-09 05:00:00 UTC', '2026-12-08'], 'next run (winter): 05:00Z, reporting Dec 8');
    expect_eq(bldr_next_run(utc('2026-11-01 04:00:00'))['run_utc'], '2026-11-02 05:00:00 UTC', 'next run across the fall-back change moves to 05:00Z so local time stays 11:00 PM');
    expect_eq(bldr_next_run(utc('2026-10-09 04:00:00'))['business_date'], '2026-10-09', 'next run strictly after the current run reports the following date');

    // ------------------------------------------------------------------ 2. shifts, SOP, raw values
    // Texas local -> sheet (Vietnam, UTC+7) timestamps:
    //   10:30 AM CDT Oct 8 = 15:30Z = "10/8/2026 22:30:00"      4:20 PM... 5:20 PM CDT = 22:20Z = "10/9/2026 5:20:00"
    //   1:29 PM CDT = 18:29Z = "10/9/2026 1:29:00" (AM)         1:30 PM CDT = 18:30Z = "10/9/2026 1:30:00" (PM)
    $b1 = make_table([
        ['10/8/2026 22:30:00', 'Anna', 'B1', $d, '10:30', 'PM', '38', '-', '50', 'OFF', '', '12:30'],      // AM by time, form field says PM
        ['10/9/2026 5:20:00', 'Ben', 'B1', $d, '17:20', 'PM', '40', '0', '44', '110', '-5', '355'],        // PM, 5:20 PM = Late
    ]);
    $b2 = make_table([
        ['10/8/2026 22:10:00', 'Cara', 'B2', $d, '10:10', 'AM', '35', '', '', '', '', ''],                  // AM 10:10 On Time
        ['10/8/2026 22:40:00', 'Cara', 'B2', $d, '10:40', 'AM', '36', '', '', '', '', ''],                  // duplicate AM submission
    ]);
    $b3 = make_table([]);
    $report = bldr_build_report($d, ['B1' => ['table' => $b1], 'B2' => ['table' => $b2], 'B3' => ['table' => $b3]], utc('2026-10-09 04:00:00'));
    $s1 = $report['stores']['B1'];
    expect_eq([$s1['shifts']['AM']['status'], $s1['shifts']['AM']['employee'], $s1['shifts']['AM']['local_time']], ['ON_TIME', 'Anna', '10:30 AM'], 'B1 AM: On Time, Anna, actual Texas-local time 10:30 AM');
    expect_eq([$s1['shifts']['PM']['status'], $s1['shifts']['PM']['employee'], $s1['shifts']['PM']['local_time']], ['LATE', 'Ben', '5:20 PM'], 'B1 PM: Late (5:20 PM is past the 5:00 PM window), Ben');
    expect_eq($s1['shift_field_mismatch'][0]['assigned'] ?? '', 'AM', 'a free-text Shift field of PM on a 10:30 AM submission is overridden by canonical shift assignment (AM) and flagged');
    expect_eq([$report['stores']['B2']['shifts']['AM']['status'], $report['stores']['B2']['shifts']['AM']['submission_count']], ['ON_TIME', 2], 'B2 AM: earliest qualifying submission decides, second submission counted as additional');
    expect_eq($report['stores']['B2']['shifts']['PM']['status'], 'MISSING', 'B2 PM: Missing - no PM record exists');
    expect_eq([$report['stores']['B3']['shifts']['AM']['status'], $report['stores']['B3']['shifts']['PM']['status'], $report['stores']['B3']['shifts']['AM']['employee']], ['MISSING', 'MISSING', null], 'B3 with no records: both shifts Missing and no employee/time invented');
    // boundary check through the report path: 1:29 PM -> AM, 1:30 PM -> PM
    $edge = bldr_collect_store('B1', make_table([['10/9/2026 1:29:00', 'Eve', 'B1', $d, '', '', '40'], ['10/9/2026 1:30:00', 'Fay', 'B1', $d, '', '', '40']]), $d, utc('2026-10-09 04:00:00'));
    expect_eq([$edge['shifts']['AM']['employee'], $edge['shifts']['PM']['employee']], ['Eve', 'Fay'], 'canonical 13:30 boundary: 1:29 PM is AM, 1:30 PM is PM');
    $sm = $report['summary'];
    expect_eq([$sm['expected_shifts'], $sm['submitted'], $sm['missing'], $sm['on_time'], $sm['late'], $sm['early']], [6, 3, 3, 2, 1, 0], 'summary: 6 expected, 3 submitted, 3 missing, 2 on time, 1 late, 0 early - all derived from the data');
    expect_true($report['complete'], 'report is complete when every store was read');
    // Missing-shift calculation relative to time: a not-yet-closed window is NOT_YET_DUE, never Missing
    $early = bldr_collect_store('B3', make_table([]), '2026-10-09', utc('2026-10-09 14:00:00')); // 9:00 AM CDT Oct 9
    expect_eq([$early['shifts']['AM']['status'], $early['shifts']['PM']['status']], ['NOT_YET_DUE', 'NOT_YET_DUE'], 'same-day shifts before their window closes are Not yet due, not Missing');

    // SOP / raw values
    $t = $s1['tally'];
    expect_eq([$t['normal'], $t['exception'], $t['non_numeric'], $t['blank']], [7, 2, 2, 1], 'B1 tally: normal 7, exceptions 2 (prepAreaCooler 50F, fryerLeft 1230 parsed), non-numeric 2 (OFF, -), blank 1');
    expect_eq($t['no_column'], 12 * 0 + (19 - 6) * 2, 'stations whose sheet column does not exist are counted as column-missing, never as normal');
    $exStations = array_column($s1['exceptions'], 'station');
    sort($exStations);
    expect_eq($exStations, ['Fryer Left', 'Prep Area Cooler'], 'exceptions are exactly the readings outside the production SOP');
    $prep = array_values(array_filter($s1['exceptions'], fn($e) => $e['station'] === 'Prep Area Cooler'))[0];
    expect_eq([$prep['raw'], $prep['target'], $prep['severity']], ['50', '30F - 45F', 'high'], 'exception carries raw value, the canonical SOP range label and canonical severity');
    $nn = array_column($s1['non_numeric'], 'raw'); sort($nn);
    expect_eq($nn, ['-', 'OFF'], 'OFF and - are listed as non-numeric exactly as entered, never converted to a temperature');
    expect_eq(array_column($s1['raw_text_review'], 'raw'), ['12:30'], 'a raw cell the canonical parser turned into a number via stripping (12:30 -> 1230) is flagged for review');
    expect_eq(broth_log_severity_for(BROTH_LOG_SOP['walkInFreezer'], -5.0), 'safe', 'canonical SOP is reused from broth-log-core (walk-in freezer -5F is safe)');
    $exceptionsInFreezerRange = array_filter($s1['exceptions'], fn($e) => $e['station'] === 'Walk-In Freezer');
    expect_eq(count($exceptionsInFreezerRange), 0, 'walk-in freezer 0F within the production SOP (-20..5) is not flagged');

    // ------------------------------------------------------------------ seed incidents + deliveries (real-table shapes)
    $inc = function (string $id, string $branch, string $date, string $type, string $state, ?string $ackBy = null, string $created = '2026-10-08 18:00:00', ?string $ackAt = null, string $label = 'Prep Area Cooler', ?string $shift = null): void {
        run("INSERT INTO broth_log_incidents (incident_id, fingerprint, branch, business_date, response_id, station_key, station_label, sop_target, severity, corrective_action, state, current_level, acknowledged_by, acknowledged_at, created_at, incident_type, shift)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)", [$id, 'fp-' . $id, $branch, $date, 'r-' . $id, 'k', $label, '30-45', 'critical', 'x', $state, 1, $ackBy, $ackAt, $created, $type, $shift]);
    };
    run("INSERT INTO broth_log_authorized_users (telegram_user_id,display_name,role,allowed_branches,active,created_at) VALUES ('1001','David','manager','[\"B1\",\"B2\",\"B3\"]',1,'2025-01-01 00:00:00'),('1004','Hoang Le','owner','[\"B1\",\"B2\",\"B3\"]',1,'2025-01-01 00:00:00'),('1099','Zed','manager','[\"B1\"]',1,'2025-01-01 00:00:00')");
    foreach (['1001', '1004', '1099'] as $u) run("INSERT INTO broth_log_private_chat_registrations (telegram_user_id, private_chat_id) VALUES (?,?)", [$u, 'dm-' . $u]);
    run("INSERT INTO broth_log_routing_rules (branch,stage,level,telegram_user_ids,chat_id,active) VALUES ('B1','pilot',1,'[\"x\"]','ops-chat',1)");
    run("INSERT INTO broth_log_branch_alert_mode (branch, mode, updated_at) VALUES ('B1','level_routing','2026-10-01 00:00:00')");
    run("INSERT INTO broth_log_alert_recipients (branch,kind,min_level,telegram_user_id,display_name) VALUES ('B1','manager',1,'1001',''),('B1','test_observer',1,'1004','')");
    run("INSERT INTO broth_log_alert_recipients (branch,kind,min_level,telegram_user_id,display_name) VALUES ('B2','manager',2,'','Omar')");
    $inc('inc-b1-temp', 'B1', $d, 'temperature', 'resolved', '1001', '2026-10-08 18:00:00', '2026-10-08 18:05:00');
    $inc('inc-b2-ms', 'B2', $d, 'missing_shift', 'detected', null, '2026-10-08 22:30:00', null, 'PM', 'PM');
    $inc('inc-b3-old', 'B3', '2026-08-22', 'temperature', 'escalated_level_3', null, '2026-08-22 15:00:00');
    $deliv = function (string $key, string $inc, string $chat, string $kind, string $status, string $created = '2026-10-08 18:00:30'): void {
        run("INSERT INTO broth_log_outbound_deliveries (delivery_key, incident_id, chat_id, message_kind, message_text, status, created_at) VALUES (?,?,?,?,?,?,?)", [$key, $inc, $chat, $kind, 't', $status, $created]);
    };
    $deliv('incident:inc-b1-temp:notify:dm-1001', 'inc-b1-temp', 'dm-1001', 'incident_notification', 'sent');
    $deliv('incident:inc-b1-temp:notify:dm-1004', 'inc-b1-temp', 'dm-1004', 'incident_notification', 'failed');
    $deliv('incident:inc-b1-temp:notify:dm-1099', 'inc-b1-temp', 'dm-1099', 'incident_notification', 'sent');
    $deliv('incident:inc-b1-temp:notify:ops-chat', 'inc-b1-temp', 'ops-chat', 'incident_notification', 'sent');
    $deliv('incident:inc-b1-temp:acknowledged_status:dm-1004', 'inc-b1-temp', 'dm-1004', 'acknowledged_status', 'sent');
    run("INSERT INTO broth_log_incident_events (incident_id, event_type, event_json, created_at) VALUES ('inc-b1-temp','ops_group_fallback','{\"reason\":\"all_direct_deliveries_failed\"}','2026-10-08 18:00:31')");
    $countsBefore = [q1("SELECT COUNT(*) c FROM broth_log_incidents")['c'], q1("SELECT COUNT(*) c FROM broth_log_outbound_deliveries")['c'], q1("SELECT COUNT(*) c FROM broth_log_incident_events")['c']];

    $report = bldr_build_report($d, ['B1' => ['table' => $b1], 'B2' => ['table' => $b2], 'B3' => ['table' => $b3]], utc('2026-10-09 04:00:00'));
    $iv1 = $report['stores']['B1']['incident_view'];
    expect_eq([$iv1['temperature'], $iv1['missing_shift'], $iv1['open']], [1, 0, 0], 'B1 incidents: one temperature incident, resolved -> not open');
    expect_eq([$iv1['incidents'][0]['acked_by'], $iv1['incidents'][0]['acked_local']], ['David', '1:05 PM'], 'ACK person and Texas-local ACK time come from the incident record (18:05Z = 1:05 PM CDT)');
    $iv2 = $report['stores']['B2']['incident_view'];
    expect_eq([$iv2['missing_shift'], $iv2['open'], $iv2['incidents'][0]['acked_by']], [1, 1, null], 'B2: one open Missing Shift incident, no ACK invented');
    expect_eq([$report['stores']['B3']['incident_view']['carry_over_open'], $report['stores']['B3']['incident_view']['carry_over_oldest']], [1, '2026-08-22'], 'older unresolved incident is reported as carry-over with its real date');
    $dl = $report['stores']['B1']['delivery'];
    expect_eq([$dl['sent'], $dl['failed'], $dl['ops_group_sent']], [3, 1, 1], 'delivery health counts come from delivery records: 3 sent (David, Zed, Ops), 1 failed (Hoang); status broadcasts excluded');
    expect_eq($dl['ops_fallback_reasons'], ['all_direct_deliveries_failed'], 'Ops fallback reason comes from the audit event');
    expect_eq($dl['unexpected_recipients'], ['Zed (L1)'], 'a recipient outside the resolved routing for that level is flagged as unexpected');
    expect_eq([$dl['ops_plus_direct_same_incident_level'], count($dl['duplicates'])], [1, 0], 'Ops copy alongside direct DMs for one incident/level is flagged; no duplicate delivery rows');
    expect_eq($report['pending_recipients'], ['Omar - B2 Level 2 (pending onboarding, no Telegram ID)'], 'Omar is reported as pending onboarding');
    expect_eq([q1("SELECT COUNT(*) c FROM broth_log_incidents")['c'], q1("SELECT COUNT(*) c FROM broth_log_outbound_deliveries")['c'], q1("SELECT COUNT(*) c FROM broth_log_incident_events")['c']], $countsBefore, 'building the report creates/changes no incident, delivery or event');

    // ------------------------------------------------------------------ rendering
    $html = bldr_render_html($report);
    $text = bldr_render_text($report);
    if (getenv('DAILY_REPORT_SAMPLE_OUT')) { file_put_contents(getenv('DAILY_REPORT_SAMPLE_OUT') . '.html', $html); file_put_contents(getenv('DAILY_REPORT_SAMPLE_OUT') . '.txt', $text); }
    expect_true(str_contains($html, 'viewport') && str_contains($html, 'max-width:720px'), 'HTML is mobile + desktop friendly (viewport meta, fluid container)');
    expect_true(str_contains($html, 'Oct 8, 2026') && str_contains($text, 'Daily Broth Log Report - Oct 8, 2026'), 'title carries the Texas business date');
    expect_true(str_contains($html, 'Missing / No production record'), 'missing shifts print the explicit missing-data phrase');
    expect_true(str_contains($html, '&ldquo;OFF&rdquo;') && str_contains($text, 'NON-NUMERIC Bowl Warmer: "OFF"'), 'raw OFF value preserved exactly in HTML and text');
    expect_true(str_contains($html, 'ACK by David at 1:05 PM'), 'ACK person/time rendered');
    expect_true(str_contains($text, 'Expected shifts: 6') && str_contains($text, 'Submitted: 3   Missing: 3   On time: 2'), 'plain-text summary matches the data');
    expect_true(!preg_match('/\b[0-9]{5,16}:[A-Za-z0-9_-]{20,}\b/', $html . $text), 'no token-shaped secrets in the output');
    expect_true(!str_contains($html . $text, '1001'), 'raw Telegram ids never appear in the report');
    $partial = bldr_build_report($d, ['B1' => ['table' => $b1], 'B2' => ['error' => 'B2 sheet request failed'], 'B3' => ['table' => $b3]], utc('2026-10-09 04:00:00'));
    expect_true(!$partial['complete'] && $partial['summary']['stores_unavailable'] === 1 && str_contains(bldr_render_html($partial), 'INCOMPLETE REPORT'), 'an unreadable store makes the report visibly INCOMPLETE and is never counted as compliant');
    expect_eq($partial['summary']['missing'] + $partial['summary']['submitted'], 4, 'unreadable store contributes no shift counts (no invented Missing/Submitted)');

    // ------------------------------------------------------------------ 3. runner: idempotency, retry, failure handling
    $mailLog = [];
    $mailMode = 'ok';
    $GLOBALS['BROTH_LOG_DAILY_REPORT_MAIL_TRANSPORT'] = function (string $to, string $subject, string $html, string $text, string $from) use (&$mailLog, &$mailMode): array {
        $mailLog[] = ['to' => $to, 'subject' => $subject, 'from' => $from, 'bytes' => strlen($html)];
        return $mailMode === 'ok' ? ['accepted' => true] : ['accepted' => false, 'error' => 'smtp temporary failure'];
    };
    $fetches = 0;
    $failFetch = false;
    $GLOBALS['BROTH_LOG_DAILY_REPORT_TABLES_PROVIDER'] = function (string $branch) use (&$fetches, &$failFetch, $b1, $b2, $b3): array {
        $fetches++;
        if ($failFetch) throw new RuntimeException("$branch sheet request failed");
        return ['B1' => $b1, 'B2' => $b2, 'B3' => $b3][$branch];
    };
    run("DELETE FROM broth_log_daily_report_runs");
    putenv('BROTH_LOG_DAILY_REPORT_ENABLED=false');
    expect_eq(bldr_run(utc('2026-10-09 04:00:00'))['action'], 'disabled', 'disabled flag: a due tick does nothing');
    expect_eq([count($mailLog), (int)q1("SELECT COUNT(*) c FROM broth_log_daily_report_runs")['c'], $fetches], [0, 0, 0], 'disabled: no email, no record, no data read');
    putenv('BROTH_LOG_DAILY_REPORT_ENABLED=true');
    expect_eq(bldr_run(utc('2026-10-09 03:00:00'))['action'], 'not_due', 'before 11 PM Texas: not due, nothing read');
    expect_eq($fetches, 0, 'a not-due tick reads no production data');

    $res = bldr_run(utc('2026-10-09 04:00:00'));
    expect_eq([$res['action'], $res['business_date'], $res['recipient']], ['sent', '2026-10-08', 'liem.dt0208@gmail.com'], 'first due tick sends the Oct 8 report to the required recipient');
    expect_eq([$mailLog[0]['subject'], $mailLog[0]['from']], ['Bakudan Broth Log Daily Report - 2026-10-08', 'broth-log-reports@bakudanramen.com'], 'subject format and default sender');
    $row = q1("SELECT * FROM broth_log_daily_report_runs WHERE business_date='2026-10-08'");
    expect_eq([$row['report_status'], $row['email_status'], (int)$row['email_attempts'], $row['error_summary']], ['generated', 'accepted', 1, null], 'execution record: report generated, email accepted, 1 attempt, no error');
    expect_true($row['started_at'] !== '' && $row['completed_at'] !== null && $row['email_accepted_at'] !== null && $row['report_sha256'] !== null, 'execution record stores started/completed/accepted times and report hash');
    $fetchesAfterFirst = $fetches;
    expect_eq(bldr_run(utc('2026-10-09 04:00:00'))['action'], 'already_sent', 'same tick again: already sent');
    expect_eq(bldr_run(utc('2026-10-09 04:05:00'))['action'], 'already_sent', 'next 5-minute tick: already sent');
    expect_eq(bldr_run(utc('2026-10-09 07:30:00'))['action'], 'already_sent', 'catch-up window tick later that night: still no second email');
    expect_eq([count($mailLog), $fetches], [1, $fetchesAfterFirst], 'duplicate-email prevention: exactly one email for the business date and no re-read of data');

    // failure then retry: stored report resent, data not re-read
    $mailMode = 'fail';
    $r2 = bldr_run(utc('2026-10-10 04:00:00'));
    expect_eq([$r2['action'], $r2['business_date']], ['email_failed', '2026-10-09'], 'a failed send is recorded as email_failed for the right business date');
    $row2 = q1("SELECT * FROM broth_log_daily_report_runs WHERE business_date='2026-10-09'");
    expect_eq([$row2['report_status'], $row2['email_status'], (int)$row2['email_attempts']], ['generated', 'failed', 1], 'report generated but email failed - the two states are tracked separately');
    expect_true(str_contains((string)$row2['error_summary'], 'smtp temporary failure'), 'error summary recorded');
    $fetchesBeforeRetry = $fetches;
    $mailMode = 'ok';
    $r3 = bldr_run(utc('2026-10-10 04:05:00'));
    expect_eq($r3['action'], 'sent', 'retry on the next tick succeeds');
    expect_eq($fetches, $fetchesBeforeRetry, 'retry reuses the stored report and does not re-read production data');
    expect_eq((int)q1("SELECT email_attempts a FROM broth_log_daily_report_runs WHERE business_date='2026-10-09'")['a'], 2, 'attempt count reflects the retry');
    expect_eq(count($mailLog), 3, 'one failed attempt + one successful send recorded by the transport; first date unaffected');
    // gives up after the maximum attempts
    $mailMode = 'fail';
    for ($i = 0; $i < 6; $i++) bldr_run(utc('2026-10-11 04:0' . min($i, 9) . ':00'));
    $row3 = q1("SELECT * FROM broth_log_daily_report_runs WHERE business_date='2026-10-10'");
    expect_eq([$row3['email_status'], (int)$row3['email_attempts']], ['failed', BLDR_MAX_EMAIL_ATTEMPTS], 'retries stop after the maximum attempts and the failure stays recorded');
    expect_eq(bldr_run(utc('2026-10-11 04:30:00'))['action'], 'gave_up', 'further ticks report gave_up instead of looping');
    $mailMode = 'ok';

    // interrupted attempt -> never auto-resend
    run("INSERT INTO broth_log_daily_report_runs (business_date, recipient, started_at, report_status, report_html, report_text, subject, email_status, email_attempts, email_attempt_started_at) VALUES ('2026-10-11','liem.dt0208@gmail.com','2026-10-12 04:00:00','generated','<html></html>','t','s','attempting',1,'2026-10-12 04:00:00')");
    $mailsBefore = count($mailLog);
    expect_eq(bldr_run(utc('2026-10-12 04:02:00'))['action'], 'in_progress', 'a recent in-flight attempt is left alone');
    expect_eq(bldr_run(utc('2026-10-12 04:30:00'))['action'], 'needs_review', 'a stale in-flight attempt becomes needs_review');
    expect_eq(bldr_run(utc('2026-10-12 04:35:00'))['action'], 'needs_review', 'and stays that way');
    expect_eq([count($mailLog), q1("SELECT email_status s FROM broth_log_daily_report_runs WHERE business_date='2026-10-11'")['s']], [$mailsBefore, 'unknown'], 'unknown delivery state is never resent automatically (no duplicate email) and is recorded');

    // unreadable sheets -> INCOMPLETE report is still sent and clearly recorded
    $failFetch = true;
    $r4 = bldr_run(utc('2026-10-13 04:00:00'));
    expect_eq($r4['action'], 'sent', 'sheet outage still produces and sends a clearly INCOMPLETE report instead of failing silently');
    $row4 = q1("SELECT * FROM broth_log_daily_report_runs WHERE business_date='2026-10-12'");
    expect_eq([$row4['report_status'], (int)$row4['report_complete']], ['partial', 0], 'run record marks the report partial');
    expect_true(str_contains((string)$row4['report_html'], 'INCOMPLETE REPORT') && str_contains((string)$row4['error_summary'], 'unreadable'), 'stored report is labelled incomplete and error summary says why');
    $failFetch = false;

    // generation failure (internal error) is recorded, then recovers on a later tick
    db()->exec('ALTER TABLE broth_log_incidents RENAME TO broth_log_incidents_off');
    $r5 = bldr_run(utc('2026-10-14 04:00:00'));
    expect_eq($r5['action'], 'report_failed', 'an internal generation error is reported as report_failed');
    $row5 = q1("SELECT * FROM broth_log_daily_report_runs WHERE business_date='2026-10-13'");
    expect_eq([$row5['report_status'], $row5['email_status'], (int)$row5['generate_attempts']], ['failed', 'not_attempted', 1], 'record shows generation failed and email not attempted');
    db()->exec('ALTER TABLE broth_log_incidents_off RENAME TO broth_log_incidents');
    expect_eq(bldr_run(utc('2026-10-14 04:05:00'))['action'], 'sent', 'after the fault clears the next tick generates and sends once');
    expect_eq((int)q1("SELECT generate_attempts g FROM broth_log_daily_report_runs WHERE business_date='2026-10-13'")['g'], 2, 'generation attempts recorded');

    // concurrency guard: claiming an already-attempting row never double sends
    expect_eq((int)q1("SELECT COUNT(*) c FROM broth_log_daily_report_runs WHERE email_status='accepted'")['c'], 4, 'every accepted run corresponds to exactly one accepted email');

    // transport safety
    $GLOBALS['BROTH_LOG_DAILY_REPORT_MAIL_TRANSPORT'] = function () { throw new RuntimeException('should not be reached'); };
    expect_eq(bldr_send_email("a@b.com\r\nBcc: x@y.com", 's', 'h', 't')['accepted'], false, 'header injection in the recipient is rejected');
    expect_eq(bldr_send_email('liem.dt0208@gmail.com', "subj\r\nBcc: x@y.com", 'h', 't')['accepted'], false, 'header injection in the subject is rejected');
    expect_eq(bldr_send_email('not-an-email', 's', 'h', 't')['accepted'], false, 'invalid recipient is rejected');

    echo "\nAll daily-report tests passed.\n";
} finally {
    @unlink(TEST_DB_PATH);
    @unlink(TEST_DB_PATH . '-wal');
    @unlink(TEST_DB_PATH . '-shm');
}
