"""
Confirms no plaintext LinkHub automation admin credentials are committed
anywhere in the credential-hardening files (code, docs, tests), and that
the known-compromised production password never appears.
"""
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

SCANNED_PATHS = [
    'api/run_linkhub_automations.php',
    'LINK_HUB_2_FINAL_PRODUCTION_READINESS.md',
    'docs/linkhub-automations-credential-rotation.md',
    'tests/linkhub_automations/credential_env_loader_test.php',
    'tests/linkhub_automations/test_no_secrets.py',
]

# The password that was found exposed in plaintext in the production
# crontab -- must never appear anywhere in this repo, in any casing.
# Built from two pieces so this constant itself is never a contiguous
# match for its own check when this file is scanned below.
KNOWN_COMPROMISED_PASSWORD = 'admin' + '123'

# A LINKHUB_ADMIN_PASSWORD=<value> assignment is only "safe" when <value>
# is empty, a shell variable expansion ($...), a printf format specifier
# (%s, used when generating the private env file), or a doc placeholder
# like <real password> / <OLD_PASSWORD> -- never a literal value. This
# check is intentionally skipped for files under tests/: those legitimately
# contain synthetic fixture values (e.g. "file-only-test-value") to
# exercise the env-file loader, same rationale as the campaign secret scan
# excluding tests/campaign/*.py from its own forbidden-pattern check.
UNSAFE_PASSWORD_ASSIGNMENT = re.compile(
    r'LINKHUB_ADMIN_PASSWORD\s*=\s*(?!["\']?\s*$|\$|<|%)\S'
)


def main():
    errors = []
    for rel in SCANNED_PATHS:
        path = os.path.join(ROOT, rel)
        if not os.path.isfile(path):
            errors.append(f'{rel}: expected file not found')
            continue
        with open(path, encoding='utf-8', errors='ignore') as f:
            text = f.read()

        if re.search(re.escape(KNOWN_COMPROMISED_PASSWORD), text, re.IGNORECASE):
            errors.append(f'{rel}: contains the known-compromised production password')

        if rel.startswith('tests/'):
            continue
        for lineno, line in enumerate(text.splitlines(), start=1):
            if UNSAFE_PASSWORD_ASSIGNMENT.search(line):
                errors.append(f'{rel}:{lineno}: LINKHUB_ADMIN_PASSWORD appears to be assigned a literal value: {line.strip()!r}')

    # The runner script itself must read credentials only from getenv()
    # (via the private env-file loader), never a hardcoded literal.
    script_path = os.path.join(ROOT, 'api', 'run_linkhub_automations.php')
    with open(script_path, encoding='utf-8') as f:
        script_text = f.read()
    if "getenv('LINKHUB_ADMIN_EMAIL')" not in script_text or "getenv('LINKHUB_ADMIN_PASSWORD')" not in script_text:
        errors.append('run_linkhub_automations.php no longer reads credentials via getenv() -- check for a regression')
    if 'load_private_env_file(PRIVATE_ENV_PATH)' not in script_text:
        errors.append('run_linkhub_automations.php no longer loads the private env file -- credential-hardening regression')

    if errors:
        print(f'FAIL: {len(errors)} error(s):')
        for e in errors:
            print(' -', e)
        return 1
    print(f'OK: no secrets found across {len(SCANNED_PATHS)} LinkHub credential-hardening file(s).')
    return 0


if __name__ == '__main__':
    sys.exit(main())
