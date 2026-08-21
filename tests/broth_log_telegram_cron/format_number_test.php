<?php
declare(strict_types=1);

require_once __DIR__ . '/../../scripts/broth-log-telegram-cron.php';

function expect_eq($actual, $expected, string $label): void {
    if ($actual !== $expected) {
        throw new RuntimeException("FAIL $label expected=" . var_export($expected, true) . " actual=" . var_export($actual, true));
    }
    echo "PASS $label\n";
}

// Regression: rtrim(rtrim($s,'0'),'.') silently turned whole-number temperatures ending in 0
// into the wrong (smaller) number (10 -> 1, 100 -> 1, 120 -> 12, 20 -> 2, 200 -> 2, 360 -> 36).
// Found via a real B1 2026-07-19 Walk-In Freezer reading of 10F rendering as "1F".
expect_eq(format_number(10.0), '10', 'format_number does not truncate a whole number ending in one zero');
expect_eq(format_number(100.0), '100', 'format_number does not truncate a whole number ending in two zeros');
expect_eq(format_number(120.0), '120', 'format_number does not truncate a whole number with an internal zero before a trailing digit');
expect_eq(format_number(20.0), '20', 'format_number does not truncate a two-digit whole number ending in zero');
expect_eq(format_number(200.0), '200', 'format_number does not truncate a three-digit whole number ending in two zeros');
expect_eq(format_number(360.0), '360', 'format_number does not truncate a whole number ending in zero after a nonzero digit');
expect_eq(format_number(38.5), '38.5', 'format_number preserves a genuine decimal value');
expect_eq(format_number(10.50), '10.5', 'format_number strips a trailing decimal zero after an actual decimal point');
expect_eq(format_number(3.0), '3', 'format_number renders a small whole number correctly');
expect_eq(format_number(0.0), '0', 'format_number renders zero correctly');
expect_eq(format_number(40.0), '40', 'format_number does not truncate a common SOP-boundary whole number ending in zero');
expect_eq(format_number(-2.0), '-2', 'format_number renders a negative whole number correctly');

// SOP targets are built directly from integer literals in SOP (e.g. 'target' => 40) concatenated
// as $sop['operator'] . ' ' . $sop['target'] . 'F' - they never pass through format_number/rtrim,
// so there is no equivalent truncation bug there. This file has exactly one rtrim() call, inside
// format_number() itself (verified by inspection during the hotfix review).
$sourceCallCount = substr_count(file_get_contents(__DIR__ . '/../../scripts/broth-log-telegram-cron.php'), 'rtrim(');
expect_eq($sourceCallCount, 2, 'the file has exactly one rtrim(rtrim(...)) call site, confined to format_number - SOP target formatting is unaffected');

echo "\nAll broth-log-telegram-cron format_number tests passed.\n";
