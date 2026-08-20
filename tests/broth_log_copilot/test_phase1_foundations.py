"""Phase 1 checks for the Broth Log Telegram Copilot foundation."""
from pathlib import Path
import re


ROOT = Path(__file__).resolve().parents[2]


def text(path: str) -> str:
    return (ROOT / path).read_text(encoding='utf-8')


def test_copilot_is_disabled_by_default_and_webhook_is_gated():
    api = text('api/index.php')
    copilot = text('api/broth-log-copilot.php')
    assert "TELEGRAM_COPILOT_ENABLED') ?: 'false'" in copilot
    assert "broth_log_copilot_enabled()" in api
    assert "broth_log_copilot_reject('Not found.', 404)" in api
    assert 'X_TELEGRAM_BOT_API_SECRET_TOKEN' in copilot


def test_core_is_single_server_side_sop_source_for_cron():
    core = text('api/broth-log-core.php')
    cron = text('scripts/broth-log-telegram-cron.php')
    assert 'const BROTH_LOG_SOP' in core
    assert "require_once __DIR__ . '/../api/broth-log-core.php'" in cron
    assert 'const SOP =' not in cron
    assert 'broth_log_critical_alerts_for_branch' in cron


def test_migrations_cover_auth_inbox_incident_context_and_routing():
    copilot = text('api/broth-log-copilot.php')
    required_tables = [
        'broth_log_authorized_users',
        'broth_log_routing_rules',
        'broth_log_bot_inbox',
        'broth_log_conversation_context',
        'broth_log_incidents',
        'broth_log_incident_events',
        'broth_log_bot_rate_limits',
        'broth_log_callback_replays',
    ]
    for table in required_tables:
        assert f'CREATE TABLE IF NOT EXISTS {table}' in copilot
    assert "active INTEGER NOT NULL DEFAULT 0" in copilot
    assert 'allowed_branches TEXT NOT NULL' in copilot
    assert 'active_key TEXT UNIQUE' in copilot
    assert 'CREATE UNIQUE INDEX IF NOT EXISTS idx_broth_log_incidents_active_key' in copilot
    assert 'escalation_lock_expires_at' in copilot
    assert 'escalation_lock_token' in copilot


def test_deterministic_parser_supports_en_es_vi_without_llm():
    copilot = text('api/broth-log-copilot.php')
    assert 'LLM' not in copilot
    for phrase in ['critical', 'critico', 'nghiem trong', 'recibido', 'da nhan', 'resolved', 'resuelto', 'da xu ly']:
        assert phrase in copilot
    for station in ['walkInFreezer', 'prepAreaCooler', 'pastaBoilerRight']:
        assert station in copilot


def test_ack_resolve_and_escalation_are_transactional_and_fake_clockable():
    copilot = text('api/broth-log-copilot.php')
    assert "db()->exec('BEGIN IMMEDIATE')" in copilot
    assert 'function broth_log_copilot_ack(' in copilot
    assert 'function broth_log_copilot_resolve(' in copilot
    assert 'function broth_log_copilot_due_escalations(?DateTimeImmutable $now = null)' in copilot
    assert "9 * 60" in copilot
    assert "3 * 60" in copilot
    assert ">= 10" in copilot
    assert 'missing_resolution_evidence' in copilot
    assert 'recheck_still_unsafe' in copilot
    assert "active_key=NULL" in copilot


def test_signed_callbacks_do_not_embed_secrets_or_large_payloads():
    copilot = text('api/broth-log-copilot.php')
    assert 'hash_hmac' in copilot
    assert 'TELEGRAM_CALLBACK_SECRET' in copilot
    assert 'function broth_log_copilot_validate_callback' in copilot
    assert 'function broth_log_copilot_consume_callback' in copilot
    assert 'broth_log_callback_replays' in copilot
    assert re.search(r"substr\(hash_hmac\('sha256'.*0, 16\)", copilot, re.S)


def test_raw_message_and_context_retention_are_configured():
    copilot = text('api/broth-log-copilot.php')
    worker = text('scripts/broth-log-telegram-bot-worker.php')
    assert 'BROTH_LOG_COPILOT_RETENTION_RAW_DAYS = 30' in copilot
    assert 'BROTH_LOG_COPILOT_CONTEXT_TTL_HOURS = 24' in copilot
    assert 'BROTH_LOG_COPILOT_INCIDENT_RETENTION_MONTHS = 12' in copilot
    assert 'broth_log_copilot_process_inbox' in worker
    assert "if (!broth_log_copilot_enabled()) return [];" in copilot
    assert "reason' => 'disabled'" in copilot


def test_worker_loads_env_before_resolving_db_path_and_has_outbound():
    worker = text('scripts/broth-log-telegram-bot-worker.php')
    copilot = text('api/broth-log-copilot.php')
    assert worker.index('load_private_env_file_worker(PRIVATE_TELEGRAM_ENV_PATH);') < worker.index("define('DB_PATH'")
    assert 'BROTH_LOG_COPILOT_ENV' in copilot
    assert 'function broth_log_copilot_send_telegram_message' in copilot
    assert "outbound_status='sent'" in copilot
    assert "status='send_failed'" in copilot


def test_no_secret_values_or_production_activation_in_new_files():
    paths = [
        'api/broth-log-core.php',
        'api/broth-log-copilot.php',
        'scripts/broth-log-telegram-bot-worker.php',
    ]
    blob = '\n'.join(text(path) for path in paths)
    assert not re.search(r'\b[0-9]{8,12}:AA[A-Za-z0-9_-]{20,}\b', blob)
    assert ('TELEGRAM_COPILOT_ENABLED' + '=true') not in blob


def main() -> int:
    tests = [(name, fn) for name, fn in globals().items() if name.startswith('test_') and callable(fn)]
    for name, fn in tests:
        fn()
        print(f'PASS {name}')
    print(f'\nAll {len(tests)} broth-log-copilot tests passed.')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
