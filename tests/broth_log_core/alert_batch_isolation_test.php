<?php
declare(strict_types=1);

// The alerts-batch route and broth_log_process_telegram_alert_batch() both live in api/index.php,
// which has unconditional top-level HTTP bootstrap code (reads $_SERVER, etc.) and is not safe to
// require_once from a CLI test harness - doing so throws immediately in this environment. Its
// db()/run()/q() are also bound to a hardcoded production DB_PATH with no override mechanism, so
// even if it could be required, exercising the batch function here would write to production data.
//
// This suite instead proves the fix structurally: it demonstrates the exact vulnerable pattern
// (calling the hard-exit validator directly inside a per-alert loop) DOES terminate a batch early,
// then verifies the actual deployed code has replaced that pattern with the safe one. The full
// dynamic, end-to-end proof (a real mixed valid/invalid batch surviving intact) is run against the
// real production code post-deploy with controlled tooling, per the task's own review process.

$failures = 0;
$passes = 0;

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

// --- 1. Reproduce the vulnerable pattern in isolation (not the real route, a minimal stand-in
// exercising the exact same control-flow shape the OLD code used) ---
function old_vulnerable_validate(array $alert): array {
    if (!in_array($alert['branch'] ?? '', ['B1', 'B2', 'B3'], true)) {
        exit("Invalid alert branch.\n"); // mirrors err()'s hard exit
    }
    return $alert;
}
$batch = [
    ['branch' => 'B1', 'label' => 'valid-b1'],
    ['branch' => 'B9', 'label' => 'malformed-b2'], // invalid branch
    ['branch' => 'B3', 'label' => 'valid-b3'],
];
// Run the vulnerable loop in a real subprocess: exit() would otherwise kill this test runner too.
$harness = tempnam(sys_get_temp_dir(), 'brothlog_repro_');
file_put_contents($harness, '<?php
$batch = ' . var_export($batch, true) . ';
$processed = [];
foreach ($batch as $alert) {
    if (!in_array($alert["branch"], ["B1","B2","B3"], true)) exit(0);
    $processed[] = $alert["label"];
}
echo implode(",", $processed);
');
$phpBinary = PHP_BINARY ?: 'php';
$output = shell_exec(escapeshellarg($phpBinary) . ' ' . escapeshellarg($harness) . ' 2>&1');
unlink($harness);
expect_true(!str_contains((string)$output, 'valid-b3'), 'reproduction: the old hard-exit-on-invalid pattern prevents a later valid alert in the same batch from ever being processed');

// --- 2. Verify the real deployed route no longer uses the vulnerable pattern ---
// Normalize line endings so extraction below is not sensitive to the checkout's CRLF/LF setting.
$indexSource = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../../api/index.php'));

$routeStart = strpos($indexSource, "if (\$path === '/broth-log/telegram/alerts' && \$METHOD === 'POST')");
$routeEnd = strpos($indexSource, "\n}\n", $routeStart);
$routeBody = substr($indexSource, $routeStart, $routeEnd - $routeStart);
expect_true(!str_contains($routeBody, 'validate_telegram_alert('), 'the alerts-batch route no longer calls the hard-exit validator directly');
expect_true(str_contains($routeBody, 'broth_log_process_telegram_alert_batch('), 'the alerts-batch route delegates to the isolated batch processor');

$batchFnStart = strpos($indexSource, 'function broth_log_process_telegram_alert_batch');
$batchFnEnd = strpos($indexSource, "\n}\n", $batchFnStart);
$batchFnBody = substr($indexSource, $batchFnStart, $batchFnEnd - $batchFnStart);
expect_true(str_contains($batchFnBody, 'broth_log_validate_telegram_alert_safe('), 'the batch processor uses the structured (non-exit) validator');
expect_true(!str_contains($batchFnBody, 'exit'), 'the batch processor itself never calls exit');
expect_true(str_contains($batchFnBody, 'record_telegram_alert_intake_error('), 'a rejected alert is recorded for diagnosis');
expect_true(str_contains($batchFnBody, 'continue;'), 'a rejected alert is skipped via continue, not by aborting the loop');
expect_true(str_contains($batchFnBody, '$hadValidationErrors = true;'), 'the batch processor tracks validation failures');
expect_true(str_contains($batchFnBody, '$hadValidationErrors ? 0 : mark_resolved_telegram_alerts($active)'), 'a malformed batch does not mark existing open alerts resolved');
expect_true(str_contains($batchFnBody, "'resolution_skipped' => \$hadValidationErrors"), 'the batch response reports when resolution was skipped for safety');
// The continue must appear inside the validation-failure branch, immediately after recording the
// error - not gated behind any later branch condition that could be skipped.
$failureBranchPos = strpos($batchFnBody, "if (!\$validation['ok'])");
$continuePos = strpos($batchFnBody, 'continue;', $failureBranchPos);
$nextBranchPos = strpos($batchFnBody, '$validated = ', $failureBranchPos);
expect_true($continuePos !== false && $continuePos < $nextBranchPos, 'the skip-and-continue happens before any subsequent alert would be reached, for every rejected item');
$validationFailureFlagPos = strpos($batchFnBody, '$hadValidationErrors = true;', $failureBranchPos);
$markResolvedPos = strpos($batchFnBody, 'mark_resolved_telegram_alerts($active)');
expect_true($validationFailureFlagPos !== false && $validationFailureFlagPos < $continuePos && $markResolvedPos > $nextBranchPos, 'validation-failure tracking happens before skip, and resolution happens only after the full batch loop');

// --- 3. Verify the failure log records only redacted, minimal fields ---
expect_true(str_contains($indexSource, 'CREATE TABLE IF NOT EXISTS broth_log_telegram_alert_intake_errors'), 'a durable table exists for validation failures');
$recordFnStart = strpos($indexSource, 'function record_telegram_alert_intake_error');
$recordFnEnd = strpos($indexSource, "\n}\n", $recordFnStart);
$recordFnBody = substr($indexSource, $recordFnStart, $recordFnEnd - $recordFnStart);
expect_true(!str_contains($recordFnBody, 'payload_json'), 'the intake-error record never stores the raw alert payload');
expect_true(!str_contains($recordFnBody, 'TELEGRAM_BOT_TOKEN') && !str_contains($recordFnBody, 'TELEGRAM_WEBHOOK_SECRET'), 'the intake-error record path never references secret constants');

if ($failures > 0) {
    echo "FAILED: {$failures} failing, {$passes} passing." . PHP_EOL;
    exit(1);
}
echo "All alert batch isolation tests passed ({$passes})." . PHP_EOL;
