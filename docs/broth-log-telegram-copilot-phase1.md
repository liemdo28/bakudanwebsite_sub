# Broth Log Operations Copilot Phase 1

## Status

Phase 1 is implemented as a disabled-by-default foundation. It must not be activated in production until Operations verifies numeric Telegram user IDs, branch permissions, routing, staging chat, and rollout approval.

## Architecture

- `api/broth-log-core.php` is the single server-side canonical source for Broth Log Sheets, header aliases, station readings, SOP targets, severity rules, and Today queries in `America/Chicago`.
- `scripts/broth-log-telegram-cron.php` keeps the existing one-way critical-alert path but now calls the canonical core instead of carrying a separate SOP copy.
- `api/broth-log-copilot.php` contains the Phase 1 Copilot foundation:
  - disabled-by-default feature flag;
  - idempotent SQLite migrations;
  - Telegram inbound webhook authorization helpers;
  - durable inbox queue;
  - deny-by-default authorized-user and branch-permission model;
  - deterministic EN/ES/VI intent/entity parser;
  - signed inline callback helpers;
  - incident ACK/resolve state machine;
  - fake-clock-testable escalation selector and applier.
- `POST /api/broth-log/telegram/webhook` is only available when the Copilot feature flag is explicitly enabled and the Telegram secret header is valid.
- `scripts/broth-log-telegram-bot-worker.php` drains the inbox and applies escalation actions. It supports `--dry-run` and `--now=<iso timestamp>` for fake-clock tests.

No LLM is used in MVP. All SOP and safe/unsafe decisions are deterministic.

## Feature Flags And Secrets

- `TELEGRAM_COPILOT_ENABLED=false` by default.
- `TELEGRAM_INCOMING_SECRET` validates Telegram inbound webhook requests. It may fall back to the existing webhook secret only for compatibility, but a separate secret is preferred before activation.
- `TELEGRAM_CALLBACK_SECRET` signs inline callback payloads. It must be separate where possible.
- `TELEGRAM_LEVEL3_FALLBACK` is a configurable label for the emergency fallback path after the Level 3 reminder cap.

Do not store real secrets in source, docs, tests, screenshots, or logs.

## Database Migrations

All migrations use `CREATE TABLE IF NOT EXISTS` / `CREATE INDEX IF NOT EXISTS`.

- `broth_log_authorized_users`: numeric Telegram user IDs, roles, active flag, allowed branches, language preference, escalation metadata.
- `broth_log_routing_rules`: branch/stage/level routing placeholders. Rows are inactive until verified.
- `broth_log_bot_inbox`: durable Telegram update queue with update ID dedupe.
- `broth_log_conversation_context`: 24-hour user context.
- `broth_log_incidents`: workflow state for detected, notified, acknowledged, escalated, resolved, closed, reopened, and unacknowledged critical incidents.
- `broth_log_incident_events`: append-only audit events.
- `broth_log_bot_rate_limits`: rate-limit buckets for future send/query enforcement.
- `broth_log_callback_replays`: one-time callback consumption records.

Retention defaults:

- raw Telegram inbox messages: 30 days;
- conversation context: 24 hours;
- incident/audit records: initially 12 months, pending Operations/legal approval.

## Security Model

- Deny all Telegram users by default.
- Authorization compares numeric Telegram user ID only.
- Branch access checks run before canonical data queries or incident mutations.
- Unauthorized users receive a generic denial; store data is not revealed.
- Webhook accepts POST JSON only, rejects oversized payloads, and validates Telegram secret header.
- Telegram update IDs are deduped.
- Inline callback data is signed, expires, and is consumed once to reject replay.
- ACK/resolve/escalation mutations use SQLite transactions.
- Escalation actions claim an expiring lock and reject stale two-worker snapshots.
- Resolution of temperature incidents requires both a safe recheck temperature and a corrective-action note.
- Raw Telegram payloads are reduced to sanitized metadata before storage.
- Raw token-shaped strings are redacted from stored message text, sanitized payload metadata, and error output.

## Escalation Rules

- Level 1 reminders: minute 0, 3, and 6.
- Escalate to Level 2 at minute 9 if not ACKed.
- Level 2 reminders: minute 0, 3, and 6.
- Escalate to Level 3 at minute 9 if not ACKed.
- Level 3 reminders every 3 minutes, maximum 10 reminders.
- After cap, mark `unacknowledged_critical` and require configured emergency fallback.

The escalation planner accepts a `DateTimeImmutable` clock so tests can simulate time without waiting real minutes.

## Supported MVP Intents

The deterministic parser recognizes the foundation set for:

- help;
- today summary;
- current status;
- critical issues;
- open issues;
- missing/incomplete logs;
- station temperature lookup;
- SOP/corrective action;
- ACK;
- resolve.

Languages:

- English;
- Spanish;
- Vietnamese, including common no-accent variants.

## Deployment Plan

1. Keep `TELEGRAM_COPILOT_ENABLED=false`.
2. Backup production files and SQLite DB before deployment.
3. Deploy code via the existing release workflow only after PR approval.
4. Configure staging-only Telegram bot/chat and separate inbound/callback secrets.
5. Enable Copilot only in staging.
6. Verify webhook unauthorized rejection, inbox queue, deny-by-default user behavior, parser, ACK/resolve, fake-clock escalation, and no regression in the existing one-way critical-alert cron.
7. Add verified numeric Telegram user IDs and inactive routing rows.
8. Activate B1 pilot for 7 days only after explicit approval.
9. Roll out B2, then B3 after pilot signoff.

## Staging Plan

- Use a separate Telegram staging bot. Telegram permits one webhook per bot, so never register the production bot to a staging endpoint.
- Use a private staging chat controlled by Operations.
- Use separate staging values for inbound webhook and callback secrets.
- Use an isolated staging database/state file.
- Prefix all staging bot messages with `TEST`.
- Do not load production manager routing or production manager data into staging.
- Keep production defaults unchanged: Copilot disabled, no active routing, no production webhook registration, no production worker cron, and no LLM.

## Manager Onboarding Template

For each manager:

| Field | Required | Notes |
|---|---:|---|
| Numeric Telegram user ID | Yes | Collect from a verified out-of-band flow; never from username alone. |
| Display name | Yes | Human-readable only; not used for authorization. |
| Role | Yes | Example: manager, area_manager, admin. |
| Allowed branches | Yes | Explicit list such as `B1`, `B2`, `B3`. |
| Preferred language | Yes | `en`, `es`, or `vi`. |
| Active | Yes | Must remain `false` until identity and branch access are verified. |
| Escalation level | Yes | Operations-approved level only. |
| Backup manager | Optional | Numeric Telegram user ID after verification. |

Activation requires two-person verification of the numeric Telegram ID and explicit Operations approval for branch access.

## Activation Checklist

- Separate staging bot exists.
- Private staging chat approved.
- Staging secrets configured outside web root.
- Staging database/state isolated.
- Webhook unauthorized requests return 401.
- Replay update IDs are suppressed.
- Callback replay is rejected.
- Unauthorized users are denied.
- Cross-branch queries/mutations are denied.
- ACK stops reminders for the correct incident.
- Resolve requires safe recheck and corrective-action note.
- Fake-clock escalation follows 0/3/6/9 minute rules.
- Two-worker stale escalation action is rejected.
- Existing one-way critical-alert cron dry-run still works.
- Production feature flag remains disabled.
- No production webhook or production worker cron is registered.

## Rollback Plan

1. Set `TELEGRAM_COPILOT_ENABLED=false`.
2. Disable the Copilot worker cron.
3. Remove or reset the Telegram webhook for the staging bot if needed.
4. Keep existing one-way critical-alert cron untouched.
5. Roll back code through the existing production release/rollback process.
6. Keep incident/audit tables unless Operations explicitly approves data deletion.

## Production Activation Blockers

- Verified numeric Telegram user IDs.
- Branch permissions for each manager.
- Active routing for B1 pilot, then B2, then B3.
- Staging Telegram chat approval.
- Production rollout window.
- Emergency fallback owner/action.
- Operations/legal approval for 12-month incident/audit retention.
