"""Runs the full campaign test suite in order. Exit 0 = all clean."""
import subprocess
import sys

ROOT_RELATIVE_SCRIPTS = [
    'scripts/check_no_text_corruption.py',
    'tests/campaign/test_manifest.py',
    'tests/campaign/test_articles.py',
    'tests/campaign/test_images.py',
    'tests/campaign/test_deterministic_render.py',
    'tests/campaign/test_state_authority.py',
    'tests/campaign/test_git_ops.py',
    'tests/campaign/test_scheduler.py',
    'tests/campaign/test_sftp_preflight.py',
    'tests/campaign/test_backup_artifact_scope.py',
    'tests/campaign/test_fresh_checkout_reconciliation.py',
    'tests/campaign/test_backup_output_ordering.py',
    'tests/campaign/test_backup_deploy_matrix.py',
    'tests/campaign/test_no_secrets.py',
    'tests/broth_log_copilot/test_phase1_foundations.py',
    'tests/broth_log_dashboard/test_current_day_ux.py',
]


def main():
    failed = []
    for script in ROOT_RELATIVE_SCRIPTS:
        print(f'\n=== {script} ===')
        result = subprocess.run([sys.executable, script])
        if result.returncode != 0:
            failed.append(script)
    print('\n' + '=' * 50)
    if failed:
        print(f'{len(failed)} suite(s) failed: {failed}')
        return 1
    print(f'All {len(ROOT_RELATIVE_SCRIPTS)} test suites passed.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
