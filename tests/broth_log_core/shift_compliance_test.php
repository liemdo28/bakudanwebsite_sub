<?php
declare(strict_types=1);

require_once __DIR__ . '/../../api/broth-log-core.php';

$failures = 0;
$passes = 0;

function expect_eq($actual, $expected, string $label): void {
    global $failures, $passes;
    if ($actual === $expected) {
        $passes++;
        echo "PASS {$label}" . PHP_EOL;
    } else {
        $failures++;
        echo "FAIL {$label} - expected " . var_export($expected, true) . ", got " . var_export($actual, true) . PHP_EOL;
    }
}

function expect_true(bool $condition, string $label): void {
    global $failures, $passes;
    if ($condition) {
        $passes++;
        echo "PASS {$label}" . PHP_EOL;
    } else {
        $failures++;
        echo "FAIL {$label}" . PHP_EOL;
    }
}

function chicago(string $time): DateTimeImmutable {
    return new DateTimeImmutable($time, new DateTimeZone('America/Chicago'));
}

// broth_log_parse_submission_datetime() expects the RAW sheet string in the sheet's own source
// timezone (Asia/Ho_Chi_Minh - see BROTH_LOG_SHEET_TIMESTAMP_TIMEZONE), not America/Chicago. This
// converts a Chicago-local intent into the equivalent raw sheet string, in the sheet's exact
// 'n/j/Y G:i:s' format, so every fixture below states its intent in Chicago time (matching the
// business rule) while PHP - not hand arithmetic - computes the correct raw value to feed in.
function raw_sheet_string_for_chicago_time(string $chicagoTime): string {
    return chicago($chicagoTime)
        ->setTimezone(new DateTimeZone(BROTH_LOG_SHEET_TIMESTAMP_TIMEZONE))
        ->format('n/j/Y G:i:s');
}

// --- Canonical window config ---
expect_eq(BROTH_LOG_SHIFT_WINDOWS['AM'], ['start' => '10:00', 'end' => '11:00'], 'AM window is 10:00-11:00');
expect_eq(BROTH_LOG_SHIFT_WINDOWS['PM'], ['start' => '16:00', 'end' => '17:00'], 'PM window is 16:00-17:00');

// --- Shift assignment (which shift a timestamp belongs to) ---
expect_eq(broth_log_shift_assignment(chicago('2026-08-25 09:59:00')), 'AM', '09:59 assigns to AM');
expect_eq(broth_log_shift_assignment(chicago('2026-08-25 13:29:00')), 'AM', '13:29 (just before the AM/PM midpoint) assigns to AM');
expect_eq(broth_log_shift_assignment(chicago('2026-08-25 13:30:00')), 'PM', '13:30 (the AM/PM midpoint) assigns to PM');
expect_eq(broth_log_shift_assignment(chicago('2026-08-25 15:59:00')), 'PM', '15:59 assigns to PM, not "AM/LATE"');

// --- AM timing status: 09:59 EARLY / 10:00 ON_TIME / 10:30 ON_TIME / 11:00 ON_TIME / 11:01 LATE ---
expect_eq(broth_log_shift_timing_status('AM', chicago('2026-08-25 09:59:00')), 'EARLY', '09:59 -> AM EARLY');
expect_eq(broth_log_shift_timing_status('AM', chicago('2026-08-25 10:00:00')), 'ON_TIME', '10:00 -> AM ON_TIME (inclusive boundary)');
expect_eq(broth_log_shift_timing_status('AM', chicago('2026-08-25 10:30:00')), 'ON_TIME', '10:30 -> AM ON_TIME');
expect_eq(broth_log_shift_timing_status('AM', chicago('2026-08-25 11:00:00')), 'ON_TIME', '11:00 -> AM ON_TIME (inclusive boundary)');
expect_eq(broth_log_shift_timing_status('AM', chicago('2026-08-25 11:01:00')), 'LATE', '11:01 -> AM LATE');

// --- PM timing status: 15:59 EARLY / 16:00 ON_TIME / 16:30 ON_TIME / 17:00 ON_TIME / 17:01 LATE ---
expect_eq(broth_log_shift_timing_status('PM', chicago('2026-08-25 15:59:00')), 'EARLY', '15:59 -> PM EARLY');
expect_eq(broth_log_shift_timing_status('PM', chicago('2026-08-25 16:00:00')), 'ON_TIME', '16:00 -> PM ON_TIME (inclusive boundary)');
expect_eq(broth_log_shift_timing_status('PM', chicago('2026-08-25 16:30:00')), 'ON_TIME', '16:30 -> PM ON_TIME');
expect_eq(broth_log_shift_timing_status('PM', chicago('2026-08-25 17:00:00')), 'ON_TIME', '17:00 -> PM ON_TIME (inclusive boundary)');
expect_eq(broth_log_shift_timing_status('PM', chicago('2026-08-25 17:01:00')), 'LATE', '17:01 -> PM LATE');

// --- Screenshot validation case: B1 Aug 23, 10:42 AM CDT, employee Yenci ---
$screenshotSubmission = broth_log_parse_submission_datetime(raw_sheet_string_for_chicago_time('2026-08-23 10:42:00'));
expect_true($screenshotSubmission !== null, 'screenshot case: submission timestamp parses');
expect_eq(broth_log_shift_assignment($screenshotSubmission), 'AM', 'screenshot case: shift = AM');
expect_eq(broth_log_shift_timing_status('AM', $screenshotSubmission), 'ON_TIME', 'screenshot case: timing_status = ON_TIME');

// --- Daily status: NOT_YET_DUE vs MISSING (today, various times of day) ---
$today = '2026-08-25';
expect_eq(broth_log_shift_daily_status('AM', [], $today, chicago('2026-08-25 09:00:00'))['status'], 'NOT_YET_DUE', 'at 9 AM, no AM submission yet -> AM NOT_YET_DUE');
expect_eq(broth_log_shift_daily_status('PM', [], $today, chicago('2026-08-25 09:00:00'))['status'], 'NOT_YET_DUE', 'at 9 AM, no PM submission yet -> PM NOT_YET_DUE');
expect_eq(broth_log_shift_daily_status('AM', [], $today, chicago('2026-08-25 10:30:00'))['status'], 'NOT_YET_DUE', 'at 10:30, no AM submission yet (window still open) -> AM NOT_YET_DUE');
expect_eq(broth_log_shift_daily_status('AM', [], $today, chicago('2026-08-25 11:01:00'))['status'], 'MISSING', 'at 11:01, no AM submission -> AM MISSING');
expect_eq(broth_log_shift_daily_status('AM', [], $today, chicago('2026-08-25 14:00:00'))['status'], 'MISSING', 'at 2 PM, no AM submission -> AM MISSING');
expect_eq(broth_log_shift_daily_status('PM', [], $today, chicago('2026-08-25 14:00:00'))['status'], 'NOT_YET_DUE', 'at 2 PM, no PM submission -> PM NOT_YET_DUE (window has not opened)');
expect_eq(broth_log_shift_daily_status('PM', [], $today, chicago('2026-08-25 16:30:00'))['status'], 'NOT_YET_DUE', 'at 4:30 PM, no PM submission -> PM NOT_YET_DUE (window still open)');
expect_eq(broth_log_shift_daily_status('PM', [], $today, chicago('2026-08-25 17:01:00'))['status'], 'MISSING', 'at 5:01 PM, no PM submission -> PM MISSING');

// --- Daily status: a historical (already-completed) date is always judged as closed ---
// "now" is intentionally the middle of the business day - proves the historical date is judged
// against ITS OWN completed windows, never against today's current clock.
$midDayNow = chicago('2026-08-25 12:00:00');
expect_eq(broth_log_shift_daily_status('AM', [], '2026-08-23', $midDayNow)['status'], 'MISSING', 'a past business date with no AM submission is MISSING, never NOT_YET_DUE, regardless of today\'s current time');
expect_eq(broth_log_shift_daily_status('PM', [], '2026-08-23', $midDayNow)['status'], 'MISSING', 'a past business date with no PM submission is MISSING, never NOT_YET_DUE, regardless of today\'s current time');

// --- Daily status: a real qualifying submission reports its own timing status ---
$onTimeRawTimestamp = raw_sheet_string_for_chicago_time('2026-08-25 10:42:00');
$onTimeRecords = [['submittedAt' => $onTimeRawTimestamp, 'employeeName' => 'Yenci']];
$amStatus = broth_log_shift_daily_status('AM', $onTimeRecords, $today, $midDayNow);
expect_eq($amStatus['status'], 'ON_TIME', 'a real 10:42 AM submission reports daily status ON_TIME');
expect_eq($amStatus['employee'], 'Yenci', 'daily status carries through the submitting employee name');
expect_eq($amStatus['submitted_at'], $onTimeRawTimestamp, 'daily status carries through the raw submission timestamp');

$lateRecords = [['submittedAt' => raw_sheet_string_for_chicago_time('2026-08-25 11:20:00'), 'employeeName' => 'Late Employee']];
expect_eq(broth_log_shift_daily_status('AM', $lateRecords, $today, $midDayNow)['status'], 'LATE', 'an 11:20 AM submission reports daily status LATE, not MISSING - the shift was late, not skipped');

// --- Duplicate same-shift submissions: earliest qualifying submission wins (documented default) ---
$duplicateRecords = [
    ['submittedAt' => raw_sheet_string_for_chicago_time('2026-08-25 10:50:00'), 'employeeName' => 'Second Submitter'],
    ['submittedAt' => raw_sheet_string_for_chicago_time('2026-08-25 10:15:00'), 'employeeName' => 'First Submitter'],
];
$dupStatus = broth_log_shift_daily_status('AM', $duplicateRecords, $today, $midDayNow);
expect_eq($dupStatus['employee'], 'First Submitter', 'with duplicate AM submissions, the earliest one determines daily status (documented default pending real-data audit)');

// --- AM/PM independence: a submission for one shift never affects the other shift's status ---
$amOnlyRecords = [['submittedAt' => $onTimeRawTimestamp, 'employeeName' => 'Yenci']];
expect_eq(broth_log_shift_daily_status('PM', $amOnlyRecords, $today, $midDayNow)['status'], 'NOT_YET_DUE', 'an AM submission does not satisfy the PM shift - PM independently remains NOT_YET_DUE');

// --- A submission missing/unparseable submittedAt is never misclassified into either shift ---
$unparseableRecords = [['submittedAt' => 'not a real timestamp', 'employeeName' => 'Bad Row']];
expect_eq(broth_log_shift_daily_status('AM', $unparseableRecords, $today, chicago('2026-08-25 11:01:00'))['status'], 'MISSING', 'a record with an unparseable submittedAt is ignored, not misclassified into AM');
expect_eq(broth_log_shift_daily_status('PM', $unparseableRecords, $today, chicago('2026-08-25 17:01:00'))['status'], 'MISSING', 'a record with an unparseable submittedAt is ignored, not misclassified into PM');

if ($failures > 0) {
    echo "FAILED: {$failures} failing, {$passes} passing." . PHP_EOL;
    exit(1);
}
echo "All shift-compliance tests passed ({$passes})." . PHP_EOL;
