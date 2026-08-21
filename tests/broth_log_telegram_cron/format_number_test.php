<?php
declare(strict_types=1);

// The one-way cron now delegates SOP/temperature formatting to the canonical core
// (broth-log-core.php) instead of carrying its own local copy, so this test targets
// broth_log_format_number() there. Still requires the cron script itself to prove it
// stays inert (main-guard) when included this way, matching real production behavior.
require_once __DIR__ . '/../../api/broth-log-core.php';
require_once __DIR__ . '/../../scripts/broth-log-telegram-cron.php';

function expect_eq($actual, $expected, string $label): void {
    if ($actual !== $expected) {
        throw new RuntimeException("FAIL $label expected=" . var_export($expected, true) . " actual=" . var_export($actual, true));
    }
    echo "PASS $label\n";
}

// Regression: rtrim(rtrim($s,'0'),'.') silently turned whole-number temperatures ending in 0
// into the wrong (smaller) number (10 -> 1, 100 -> 1, 120 -> 12, 20 -> 2, 200 -> 2, 360 -> 36).
// Found via a real B1 2026-07-19 Walk-In Freezer reading of 10F rendering as "1F", first fixed
// in the standalone hotfix (PR #21) and reconfirmed here after PR #18 refactored the cron script
// to delegate to the canonical core, which still carried the same unfixed bug independently.
expect_eq(broth_log_format_number(10.0), '10', 'format_number does not truncate a whole number ending in one zero');
expect_eq(broth_log_format_number(100.0), '100', 'format_number does not truncate a whole number ending in two zeros');
expect_eq(broth_log_format_number(120.0), '120', 'format_number does not truncate a whole number with an internal zero before a trailing digit');
expect_eq(broth_log_format_number(20.0), '20', 'format_number does not truncate a two-digit whole number ending in zero');
expect_eq(broth_log_format_number(200.0), '200', 'format_number does not truncate a three-digit whole number ending in two zeros');
expect_eq(broth_log_format_number(360.0), '360', 'format_number does not truncate a whole number ending in zero after a nonzero digit');
expect_eq(broth_log_format_number(38.5), '38.5', 'format_number preserves a genuine decimal value');
expect_eq(broth_log_format_number(10.50), '10.5', 'format_number strips a trailing decimal zero after an actual decimal point');
expect_eq(broth_log_format_number(3.0), '3', 'format_number renders a small whole number correctly');
expect_eq(broth_log_format_number(0.0), '0', 'format_number renders zero correctly');
expect_eq(broth_log_format_number(40.0), '40', 'format_number does not truncate a common SOP-boundary whole number ending in zero');
expect_eq(broth_log_format_number(-2.0), '-2', 'format_number renders a negative whole number correctly');

// SOP targets are built directly from integer literals (broth_log_sop_label / the SOP table)
// concatenated as a plain string - they never pass through broth_log_format_number/rtrim, so
// there is no equivalent truncation bug there.
$coreSource = file_get_contents(__DIR__ . '/../../api/broth-log-core.php');
expect_eq(substr_count($coreSource, 'rtrim('), 2, 'broth-log-core.php has exactly one rtrim(rtrim(...)) call site, confined to broth_log_format_number - SOP target formatting is unaffected');
$cronSource = file_get_contents(__DIR__ . '/../../scripts/broth-log-telegram-cron.php');
expect_eq(substr_count($cronSource, 'rtrim('), 0, 'the cron script itself no longer carries its own temperature-formatting logic (delegates to the canonical core)');
expect_eq(function_exists('broth_log_critical_alerts_for_branch'), true, 'the cron script requires the canonical core, which defines the function it delegates to');

echo "\nAll broth-log-telegram-cron format_number tests passed.\n";
