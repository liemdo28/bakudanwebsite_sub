<?php
declare(strict_types=1);

// Requires the real runner script so this test exercises the exact code
// that ships to production, not a copy. Safe to require: the execution
// guard in api/run_linkhub_automations.php only runs the lock-file/HTTP
// logic when invoked directly (realpath($argv[0]) === __FILE__), so
// requiring it here only defines load_private_env_file() and the
// constants, matching the pattern already used by
// tests/broth_log_telegram_cron/format_number_test.php for the Telegram
// cron script's own private-env-file loader.
require_once __DIR__ . '/../../api/run_linkhub_automations.php';

$failures = 0;
function expect(bool $cond, string $label): void {
    if ($cond) {
        echo "PASS $label\n";
    } else {
        echo "FAIL $label\n";
        $GLOBALS['failures']++;
    }
}

// Scratch dir for fixture env files. Fixture credentials below are
// synthetic test-only values, never real production credentials.
$dir = sys_get_temp_dir() . '/linkhub_automations_env_loader_test_' . getmypid();
@mkdir($dir, 0700, true);

function reset_env(): void {
    putenv('LINKHUB_ADMIN_EMAIL');
    putenv('LINKHUB_ADMIN_PASSWORD');
    unset($_ENV['LINKHUB_ADMIN_EMAIL'], $_ENV['LINKHUB_ADMIN_PASSWORD']);
}

// 1. Credentials supplied directly through the environment work, and the
//    private file (which doesn't even exist here) is never consulted.
reset_env();
putenv('LINKHUB_ADMIN_EMAIL=env-only@example.test');
putenv('LINKHUB_ADMIN_PASSWORD=env-only-test-value');
load_private_env_file($dir . '/does-not-exist.env');
expect(getenv('LINKHUB_ADMIN_EMAIL') === 'env-only@example.test', 'env-supplied email is used as-is');
expect(getenv('LINKHUB_ADMIN_PASSWORD') === 'env-only-test-value', 'env-supplied password is used as-is');

// 2. Credentials supplied only through the private env file work.
reset_env();
$onlyFile = $dir . '/only_file.env';
file_put_contents($onlyFile, "LINKHUB_ADMIN_EMAIL=file-only@example.test\nLINKHUB_ADMIN_PASSWORD=file-only-test-value\n");
load_private_env_file($onlyFile);
expect(getenv('LINKHUB_ADMIN_EMAIL') === 'file-only@example.test', 'file-supplied email is loaded');
expect(getenv('LINKHUB_ADMIN_PASSWORD') === 'file-only-test-value', 'file-supplied password is loaded');

// 3. An existing environment variable is NOT overwritten by the file --
//    getenv()-set values always win, per the "if (getenv($key) === false)"
//    guard. A key that is *not* already set still gets filled from the
//    file in the same call.
reset_env();
putenv('LINKHUB_ADMIN_EMAIL=real-env@example.test');
$mixedFile = $dir . '/mixed.env';
file_put_contents($mixedFile, "LINKHUB_ADMIN_EMAIL=should-be-ignored@example.test\nLINKHUB_ADMIN_PASSWORD=file-fills-this-one\n");
load_private_env_file($mixedFile);
expect(getenv('LINKHUB_ADMIN_EMAIL') === 'real-env@example.test', 'pre-set env var takes precedence over file value');
expect(getenv('LINKHUB_ADMIN_PASSWORD') === 'file-fills-this-one', 'file still fills a var that was not already set');

// 4. A missing file does not expose credentials, throw, or produce any
//    output -- fails safe and silent.
reset_env();
ob_start();
load_private_env_file($dir . '/totally_missing.env');
$out = ob_get_clean();
expect($out === '', 'missing env file produces no output');
expect(getenv('LINKHUB_ADMIN_EMAIL') === false, 'missing env file leaves email unset');
expect(getenv('LINKHUB_ADMIN_PASSWORD') === false, 'missing env file leaves password unset');

// 5. A malformed/partial file fails safe: well-formed lines still load,
//    garbage lines are skipped, nothing fatals.
reset_env();
$malformedFile = $dir . '/malformed.env';
file_put_contents(
    $malformedFile,
    "# a comment line\n" .
    "this line has no equals sign\n" .
    "LINKHUB_ADMIN_EMAIL=partial@example.test\n" .
    "=novalue\n" .
    "lowercase_key=should_be_ignored\n" .
    "LINKHUB_ADMIN_PASSWORD\n"
);
load_private_env_file($malformedFile);
expect(getenv('LINKHUB_ADMIN_EMAIL') === 'partial@example.test', 'malformed file still loads its one well-formed line');
expect(getenv('LINKHUB_ADMIN_PASSWORD') === false, 'a line with no "=" does not set a value');
expect(getenv('LOWERCASE_KEY') === false, 'a lowercase key is ignored (regex requires an uppercase leading key)');

// 6. Quoted values are unquoted correctly (matches the Telegram env
//    loader's behavior for values containing spaces).
reset_env();
$quotedFile = $dir . '/quoted.env';
file_put_contents($quotedFile, "LINKHUB_ADMIN_PASSWORD=\"has spaces in it\"\n");
load_private_env_file($quotedFile);
expect(getenv('LINKHUB_ADMIN_PASSWORD') === 'has spaces in it', 'double-quoted value is unquoted');

// 7. An unreadable/unusable path (a directory, not a file) fails safe:
//    no output, no thrown error, nothing set.
reset_env();
ob_start();
load_private_env_file($dir);
$dirOut = ob_get_clean();
expect($dirOut === '', 'passing a directory instead of a file produces no output');
expect(getenv('LINKHUB_ADMIN_EMAIL') === false, 'passing a directory does not set any credential');

// 8. LINKHUB_AUTOMATIONS_ENV_FILE overrides the built-in path, same
//    override mechanism as BAKUDAN_TELEGRAM_ENV_FILE for the Telegram cron.
reset_env();
putenv('LINKHUB_AUTOMATIONS_ENV_FILE=' . $onlyFile);
load_private_env_file('/some/path/that/is/never/used.env');
expect(getenv('LINKHUB_ADMIN_EMAIL') === 'file-only@example.test', 'LINKHUB_AUTOMATIONS_ENV_FILE overrides the default path');
putenv('LINKHUB_AUTOMATIONS_ENV_FILE');

// 9. No test fixture value above resembles a real credential -- every
// assertion compares against synthetic *.example.test fixture values
// defined in this file, never a real password. (The known-compromised
// production password is checked for across this and every other
// credential-hardening file by tests/linkhub_automations/test_no_secrets.py,
// not here, since embedding that literal in this file to check for its own
// absence would itself trip that same check.)

reset_env();
foreach (glob($dir . '/*') as $f) { @unlink($f); }
@rmdir($dir);

echo "\n" . ($failures === 0 ? 'ALL PASS' : "$failures FAILURE(S)") . "\n";
exit($failures === 0 ? 0 : 1);
