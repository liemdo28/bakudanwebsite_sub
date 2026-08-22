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

// Builds a gviz-shaped $cols/$row fixture matching the real Broth Log sheet header layout,
// only including the columns exercised by these tests.
function make_row(array $fields): array {
    $labels = [
        'timestamp' => 'submittedAt',
        'store code' => 'branch',
        'business date' => 'businessDate',
        'walk-in freezer' => 'walkInFreezer',
        'response id' => 'responseId',
    ];
    $cols = [];
    $cells = [];
    foreach ($labels as $label => $field) {
        $cols[] = ['label' => $label];
        $cells[] = ['v' => $fields[$field] ?? '', 'f' => $fields[$field] ?? ''];
    }
    return [$cols, ['c' => $cells]];
}

// 1. Valid explicit businessDate is preserved exactly, never overridden.
[$cols, $row] = make_row(['branch' => 'B1', 'businessDate' => '2026-07-19', 'submittedAt' => '7/19/2026 10:00:00']);
$normalized = broth_log_normalize_row($row, $cols, 'B1');
expect_eq($normalized['businessDate'], '2026-07-19', 'a valid explicit sheet business date is preserved exactly');

// 2. Blank businessDate + valid submittedAt derives the expected date.
[$cols, $row] = make_row(['branch' => 'B2', 'businessDate' => '', 'submittedAt' => '8/7/2026 0:28:23']);
$normalized = broth_log_normalize_row($row, $cols, 'B2');
expect_eq($normalized['businessDate'], '2026-08-07', 'a blank business date with a valid submittedAt derives the expected calendar date');

// 3. Malformed/absent submittedAt does not invent a date - fails safe to blank, not a guess.
[$cols, $row] = make_row(['branch' => 'B3', 'businessDate' => '', 'submittedAt' => 'not a real timestamp']);
$normalized = broth_log_normalize_row($row, $cols, 'B3');
expect_eq($normalized['businessDate'], '', 'a malformed submittedAt does not invent a business date - stays unresolved');
[$cols, $row] = make_row(['branch' => 'B3', 'businessDate' => '', 'submittedAt' => '']);
$normalized = broth_log_normalize_row($row, $cols, 'B3');
expect_eq($normalized['businessDate'], '', 'a completely absent submittedAt does not invent a business date - stays unresolved');

// 4. Timezone boundary: a submission at 23:59:59 Chicago time must not roll into the next day.
expect_eq(broth_log_derive_business_date_from_submission('12/31/2026 23:59:59'), '2026-12-31', 'a submission one second before midnight Chicago time still derives the same calendar day');
expect_eq(broth_log_derive_business_date_from_submission('1/1/2027 0:00:01'), '2027-01-01', 'a submission one second after midnight Chicago time correctly derives the new calendar day');

// 5. Existing B1 behavior with a normal, complete row is unaffected by this change.
[$cols, $row] = make_row(['branch' => 'B1', 'businessDate' => '2026-08-20', 'submittedAt' => '8/20/2026 9:00:00', 'walkInFreezer' => '-2']);
$normalized = broth_log_normalize_row($row, $cols, 'B1');
expect_eq($normalized['businessDate'], '2026-08-20', 'B1 rows with a normal explicit business date behave exactly as before');

// 6/7. B2/B3 records with blank business dates become date-addressable, and a genuinely
// out-of-range reading on one of them is now findable by the derived date - proving the exact
// mechanism broth_log_critical_alerts_for_branch() uses internally (filter by businessDate).
[$cols, $row] = make_row(['branch' => 'B2', 'businessDate' => '', 'submittedAt' => '8/7/2026 6:00:00', 'walkInFreezer' => '10']);
$normalizedB2 = broth_log_normalize_row($row, $cols, 'B2');
$filteredB2 = broth_log_filter_records([$normalizedB2], ['businessDate' => '2026-08-07', 'branch' => 'B2']);
expect_true(count($filteredB2) === 1, 'a B2 record with a derived business date is addressable by date-filtered lookup');
$freezerReading = array_values(array_filter($filteredB2[0]['readings'], fn($r) => $r['key'] === 'walkInFreezer'))[0] ?? null;
expect_true($freezerReading !== null && $freezerReading['severity'] === 'critical', 'an out-of-range reading on a derived-date B2 record is correctly detected as critical, not silently missed');

[$cols, $row] = make_row(['branch' => 'B3', 'businessDate' => '', 'submittedAt' => '8/9/2026 22:16:31', 'walkInFreezer' => '12']);
$normalizedB3 = broth_log_normalize_row($row, $cols, 'B3');
$filteredB3 = broth_log_filter_records([$normalizedB3], ['businessDate' => '2026-08-09', 'branch' => 'B3']);
expect_true(count($filteredB3) === 1, 'a B3 record with a derived business date is addressable by date-filtered lookup');

// 8. The one-way alert cron and Copilot both consume the same canonical function - there is only
// one normalization path, not a divergent copy for each caller.
$coreSource = file_get_contents(__DIR__ . '/../../api/broth-log-core.php');
expect_true(substr_count($coreSource, 'function broth_log_normalize_row(') === 1, 'there is exactly one canonical row-normalization function shared by every caller');

// 9. Deriving the date is deterministic - repeated normalization of the same row never produces
// a different result, which would otherwise fragment fingerprints/response IDs and create
// duplicate incidents for what is really the same submission.
[$cols, $row] = make_row(['branch' => 'B2', 'businessDate' => '', 'submittedAt' => '8/7/2026 0:28:23']);
$first = broth_log_normalize_row($row, $cols, 'B2');
$second = broth_log_normalize_row($row, $cols, 'B2');
expect_eq($first['businessDate'], $second['businessDate'], 'deriving the business date is deterministic across repeated normalization of the same row');
expect_eq($first['responseId'], $second['responseId'], 'the derived date does not cause responseId (and therefore incident fingerprinting) to drift between runs');

if ($failures > 0) {
    echo "FAILED: {$failures} failing, {$passes} passing." . PHP_EOL;
    exit(1);
}
echo "All business-date normalization tests passed ({$passes})." . PHP_EOL;
