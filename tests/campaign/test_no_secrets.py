"""
Confirms no plaintext credentials, Google Sheet IDs, or other secrets are
committed anywhere in the campaign infrastructure files.
"""
import glob
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

FORBIDDEN_PATTERNS = [
    (r'BAKUDAN_SFTP_PASS\s*=\s*[\'"][^\'"$]', 'hardcoded SFTP password'),
    (r'PRODUCTION_PASSWORD\s*[:=]\s*[\'"][^\'"$]', 'hardcoded production password'),
    (r'\b1-T9WLdHI1MWp0kX7U2SNPOnc7nDBnrrc0njFxBUKnqo\b', 'hardcoded Broth Log Sheet ID (Bandera)'),
    (r'\b1qk78Spg8GmyP4RCjQYwU8Nm0bXdoyl240iUDcSkK3MQ\b', 'hardcoded Broth Log Sheet ID (Stone Oak)'),
    (r'\b1odx4Xq94kz50aJBuE2Q-WcZbvXdfeVFOksOeAxn4Kxw\b', 'hardcoded Broth Log Sheet ID (La Cantera)'),
]

CAMPAIGN_PATHS = [
    'content/campaign/**/*.json',
    'scripts/campaign/*.py',
    'tests/campaign/*.py',
    '.github/workflows/seo-campaign-publish.yml',
]


def main():
    errors = []
    for pattern in CAMPAIGN_PATHS:
        for path in glob.glob(os.path.join(ROOT, pattern), recursive=True):
            try:
                with open(path, encoding='utf-8', errors='ignore') as f:
                    text = f.read()
            except OSError:
                continue
            for regex, label in FORBIDDEN_PATTERNS:
                if re.search(regex, text):
                    errors.append(f'{path}: matches forbidden pattern ({label})')

    # the workflow must read credentials from secrets.*, never inline
    workflow_path = os.path.join(ROOT, '.github', 'workflows', 'seo-campaign-publish.yml')
    with open(workflow_path, encoding='utf-8') as f:
        workflow_text = f.read()
    if 'secrets.PRODUCTION_PASSWORD' not in workflow_text:
        errors.append('seo-campaign-publish.yml does not reference secrets.PRODUCTION_PASSWORD -- check credential wiring')

    if errors:
        print(f'FAIL: {len(errors)} error(s):')
        for e in errors:
            print(' -', e)
        return 1
    print('OK: no secrets found in campaign infrastructure files.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
