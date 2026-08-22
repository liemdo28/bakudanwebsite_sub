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

$validAlert = ['branch' => 'B1', 'severity' => 'critical', 'station' => 'Walk-In Freezer', 'businessDate' => '2026-08-21'];

// Baseline: a fully valid alert is accepted, matching validate_telegram_alert()'s own rules.
$valid = broth_log_validate_telegram_alert_safe($validAlert);
expect_true($valid['ok'] ?? false, 'a fully valid alert is accepted');
expect_eq($valid['alert']['branch'] ?? null, 'B1', 'a valid alert normalizes branch to uppercase');

// C. bad branch alert
$badBranch = broth_log_validate_telegram_alert_safe(array_replace($validAlert, ['branch' => 'B9']));
expect_true(!($badBranch['ok'] ?? true), 'an unrecognized branch is rejected, not accepted');
expect_eq($badBranch['reason'] ?? null, 'invalid_branch', 'an unrecognized branch is rejected with a structured reason, never a hard exit');
expect_eq($badBranch['station'] ?? null, 'Walk-In Freezer', 'the station is still safely identifiable in the failure result even though the branch was invalid');

// D. bad station alert
$badStation = broth_log_validate_telegram_alert_safe(array_replace($validAlert, ['station' => '']));
expect_eq($badStation['reason'] ?? null, 'invalid_station', 'a blank station is rejected with a structured reason');
expect_eq($badStation['branch'] ?? null, 'B1', 'the branch is still safely identifiable in the failure result even though the station was invalid');

// E. bad date alert
$badDate = broth_log_validate_telegram_alert_safe(array_replace($validAlert, ['businessDate' => 'not-a-date']));
expect_eq($badDate['reason'] ?? null, 'invalid_business_date', 'a malformed business date is rejected with a structured reason');

// Non-critical severity is also rejected (same rule as the original validator).
$notCritical = broth_log_validate_telegram_alert_safe(array_replace($validAlert, ['severity' => 'warning']));
expect_eq($notCritical['reason'] ?? null, 'not_critical', 'a non-critical severity is rejected with a structured reason');

// Structural proof: this function never calls err()/exit - it is safe to use in a loop where one
// malformed item must not abort processing of the rest of the batch.
$source = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../../api/broth-log-core.php'));
$fnStart = strpos($source, 'function broth_log_validate_telegram_alert_safe');
$fnEnd = strpos($source, "\n}\n", $fnStart);
$fnBody = substr($source, $fnStart, $fnEnd - $fnStart);
expect_true(!str_contains($fnBody, 'exit'), 'broth_log_validate_telegram_alert_safe() never calls exit');
expect_true(!str_contains($fnBody, 'err('), 'broth_log_validate_telegram_alert_safe() never calls the hard-exit err() helper');

if ($failures > 0) {
    echo "FAILED: {$failures} failing, {$passes} passing." . PHP_EOL;
    exit(1);
}
echo "All alert validation tests passed ({$passes})." . PHP_EOL;
